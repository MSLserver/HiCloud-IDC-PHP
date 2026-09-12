<?php
require __DIR__ . '/inc.php';
$user = require_login();
$msg = $err = '';
$smtpOn = setting('smtp_enabled', '0') === '1';

if (isset($_GET['reset'])) {
    unset($_SESSION['email_change']);
    header('Location: profile.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'password') {
        $current = (string)($_POST['current'] ?? '');
        $new     = (string)($_POST['new'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');
        if ((int)$user['pwd_set'] === 1 && !password_verify($current, $user['password'])) {
            $err = '当前密码错误';
        } elseif (strlen($new) < 6) {
            $err = '新密码至少 6 位';
        } elseif ($new !== $confirm) {
            $err = '两次输入的新密码不一致';
        } else {
            db()->prepare('UPDATE users SET password = ?, pwd_set = 1 WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            $msg = '密码已更新';
        }
    } elseif ($action === 'send_email_code') {
        if (!$smtpOn) {
            $err = 'SMTP 未配置，请联系管理员';
        } else {
            $email = trim((string)($_POST['email'] ?? ''));
            $st = db()->prepare("SELECT id FROM users WHERE email != '' AND email = ? AND id != ?");
            $st->execute([$email, $user['id']]);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $err = '邮箱格式不正确';
            } elseif ($st->fetch()) {
                $err = '该邮箱已被其他账号绑定';
            } else {
                $code = (string)random_int(100000, 999999);
                $_SESSION['email_change'] = ['email' => $email, 'code' => $code, 'expires' => time() + 600];
                [$ok, $e] = smtp_send(
                    $email,
                    setting('site_name', 'HiCloud IDC') . ' 邮箱验证码',
                    "您正在绑定 / 更换账号邮箱。\r\n\r\n验证码：$code\r\n\r\n验证码 10 分钟内有效，请勿泄露给他人。"
                );
                if (!$ok) {
                    unset($_SESSION['email_change']);
                    $err = '邮件发送失败：' . $e;
                } else {
                    $msg = '验证码已发送，请查收邮件';
                }
            }
        }
    } elseif ($action === 'verify_email') {
        $p    = $_SESSION['email_change'] ?? null;
        $code = trim((string)($_POST['code'] ?? ''));
        if (!$p) {
            $err = '请先发送验证码';
        } elseif ($p['expires'] < time()) {
            unset($_SESSION['email_change']);
            $err = '验证码已过期，请重新发送';
        } elseif (!hash_equals((string)$p['code'], $code)) {
            $err = '验证码错误';
        } else {
            db()->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$p['email'], $user['id']]);
            unset($_SESSION['email_change']);
            $msg = '邮箱绑定成功';
        }
    } elseif ($action === 'unbind_email') {
        db()->prepare("UPDATE users SET email = '' WHERE id = ?")->execute([$user['id']]);
        $msg = '邮箱已解绑';
    }
    $user = current_user(true);
}

$pending = $_SESSION['email_change'] ?? null;
if ($pending && $pending['expires'] <= time()) {
    unset($_SESSION['email_change']);
    $pending = null;
}

$email = (string)($user['email'] ?? '');
$st = db()->prepare('SELECT type, nickname FROM social_accounts WHERE user_id = ? ORDER BY id ASC');
$st->execute([$user['id']]);
$socials = $st->fetchAll();
$labels = oauth_channels();

page_head('个人中心');
notice($msg, $err);
?>
<div class="card">
  <h2>账号信息</h2>
  <div class="kv"><span>用户名</span><b><?= h($user['username']) ?></b></div>
  <div class="kv"><span>角色</span><b><?= $user['role'] === 'admin' ? '管理员' : '用户' ?></b></div>
  <div class="kv"><span>积分</span><b><?= (int)$user['points'] ?></b></div>
  <div class="kv"><span>邮箱</span><b><?= $email !== '' ? h(mask_email($email)) : '未绑定' ?></b></div>
  <div class="kv"><span>第三方绑定</span><b><?= $socials
      ? implode('，', array_map(fn($s) => h($labels[$s['type']] ?? $s['type']), $socials))
      : '未绑定' ?></b></div>
  <div class="kv"><span>注册时间</span><b><?= h($user['created_at']) ?></b></div>
</div>

<div class="card" style="max-width:520px">
  <h2>绑定 / 更换邮箱</h2>
  <?php if (!$smtpOn): ?>
  <p class="muted">站点未启用 SMTP 邮箱服务，暂无法绑定邮箱，请联系管理员。</p>
  <?php elseif ($pending): ?>
  <p class="muted">验证码已发送至 <b><?= h(mask_email($pending['email'])) ?></b>，10 分钟内有效</p>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="verify_email">
    <label class="md-field">
      <input name="code" required placeholder=" " maxlength="6" inputmode="numeric" autocomplete="one-time-code">
      <span>6 位验证码</span>
    </label>
    <div class="btn-row">
      <button class="md-btn filled">确认绑定</button>
      <a class="md-btn outlined" href="profile.php?reset=1">重新发送</a>
    </div>
  </form>
  <?php else: ?>
  <?php if ($email !== ''): ?><p class="muted">当前邮箱：<b><?= h(mask_email($email)) ?></b>，输入新邮箱即可换绑</p><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="send_email_code">
    <label class="md-field">
      <input type="email" name="email" required placeholder=" " autocomplete="email">
      <span>新邮箱地址</span>
    </label>
    <div><button class="md-btn filled">发送验证码</button></div>
  </form>
  <?php if ($email !== ''): ?>
  <form method="post" onsubmit="return confirm('确定解绑当前邮箱？')" style="margin-top:8px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="unbind_email">
    <button class="md-btn text">解绑当前邮箱</button>
  </form>
  <?php endif; ?>
  <?php endif; ?>
</div>

<div class="card" style="max-width:520px">
  <h2>更改密码</h2>
  <?php if (!(int)$user['pwd_set']): ?>
  <p class="muted">你的账号通过第三方登录创建，可直接设置新密码</p>
  <?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="password">
    <?php if ((int)$user['pwd_set'] === 1): ?>
    <label class="md-field">
      <input type="password" name="current" required placeholder=" " autocomplete="current-password">
      <span>当前密码</span>
    </label>
    <?php endif; ?>
    <label class="md-field">
      <input type="password" name="new" required placeholder=" " autocomplete="new-password">
      <span>新密码</span>
    </label>
    <label class="md-field">
      <input type="password" name="confirm" required placeholder=" " autocomplete="new-password">
      <span>确认新密码</span>
    </label>
    <div><button class="md-btn filled">更改密码</button></div>
  </form>
</div>
<?php page_foot(); ?>
