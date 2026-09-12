<?php
require __DIR__ . '/inc.php';
if (current_user()) { header('Location: index.php'); exit; }

$smtpOn = setting('smtp_enabled', '0') === '1';
$err = '';

if (isset($_GET['reset'])) {
    unset($_SESSION['pwd_reset']);
    header('Location: forgot.php');
    exit;
}

$step = 'form';
$pending = $_SESSION['pwd_reset'] ?? null;
if ($smtpOn && $pending && $pending['expires'] > time()) $step = 'verify';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'send' && $smtpOn) {
        $account = trim((string)($_POST['account'] ?? ''));
        $st = db()->prepare("SELECT * FROM users WHERE username = ? OR (email != '' AND email = ?)");
        $st->execute([$account, $account]);
        $u = $st->fetch();
        if (!$u || empty($u['email'])) {
            $err = '该账号不存在或未绑定邮箱，无法找回密码';
        } else {
            $code = (string)random_int(100000, 999999);
            $_SESSION['pwd_reset'] = ['uid' => (int)$u['id'], 'email' => $u['email'], 'code' => $code, 'expires' => time() + 600];
            [$ok, $e] = smtp_send(
                $u['email'],
                setting('site_name', 'HiCloud IDC') . ' 重置密码验证码',
                "您正在重置账号密码。\r\n\r\n验证码：$code\r\n\r\n验证码 10 分钟内有效，如非本人操作请忽略此邮件。"
            );
            if (!$ok) {
                unset($_SESSION['pwd_reset']);
                $err = '邮件发送失败：' . $e;
            } else {
                $pending = $_SESSION['pwd_reset'];
                $step = 'verify';
            }
        }
    } elseif ($action === 'reset' && $smtpOn) {
        $p       = $_SESSION['pwd_reset'] ?? null;
        $code    = trim((string)($_POST['code'] ?? ''));
        $new     = (string)($_POST['new'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');
        if (!$p) {
            $err = '请先发送验证码';
        } elseif ($p['expires'] < time()) {
            unset($_SESSION['pwd_reset']);
            $err = '验证码已过期，请重新发送';
        } elseif (!hash_equals((string)$p['code'], $code)) {
            $err = '验证码错误';
        } elseif (strlen($new) < 6) {
            $err = '新密码至少 6 位';
        } elseif ($new !== $confirm) {
            $err = '两次输入的密码不一致';
        } else {
            db()->prepare('UPDATE users SET password = ?, pwd_set = 1 WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $p['uid']]);
            unset($_SESSION['pwd_reset']);
            $_SESSION['flash'] = '密码已重置，请使用新密码登录';
            header('Location: login.php');
            exit;
        }
        if ($err !== '') {
            $pending = $_SESSION['pwd_reset'] ?? null;
            if ($pending && $pending['expires'] > time()) $step = 'verify';
        }
    }
}

page_head('忘记密码');
notice('', $err);
?>
<div class="card auth-card">
<?php if (!$smtpOn): ?>
  <h1>忘记密码</h1>
  <p class="muted">站点未启用 SMTP 邮箱服务，暂无法通过邮箱找回密码，请联系管理员处理。</p>
  <p class="muted"><a href="login.php">返回登录</a></p>
<?php elseif ($step === 'verify' && $pending): ?>
  <h1>重置密码</h1>
  <p class="muted">验证码已发送至 <b><?= h(mask_email($pending['email'])) ?></b>，10 分钟内有效</p>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset">
    <label class="md-field">
      <input name="code" required placeholder=" " maxlength="6" inputmode="numeric" autocomplete="one-time-code">
      <span>6 位验证码</span>
    </label>
    <label class="md-field">
      <input type="password" name="new" required placeholder=" " autocomplete="new-password">
      <span>新密码</span>
    </label>
    <label class="md-field">
      <input type="password" name="confirm" required placeholder=" " autocomplete="new-password">
      <span>确认新密码</span>
    </label>
    <button class="md-btn filled">重置密码</button>
  </form>
  <p class="muted">没收到邮件？<a href="forgot.php?reset=1">重新发送</a></p>
<?php else: ?>
  <h1>忘记密码</h1>
  <p class="muted">输入用户名或绑定的邮箱，我们将发送重置验证码</p>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="send">
    <label class="md-field">
      <input name="account" required placeholder=" " autocomplete="username" value="<?= h($_POST['account'] ?? '') ?>">
      <span>用户名或邮箱</span>
    </label>
    <button class="md-btn filled">发送验证码</button>
  </form>
  <p class="muted">想起来了？<a href="login.php">返回登录</a></p>
<?php endif; ?>
</div>
<?php page_foot(); ?>
