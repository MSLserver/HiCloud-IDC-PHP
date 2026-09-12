<?php
require __DIR__ . '/inc.php';
if (current_user()) { header('Location: index.php'); exit; }

$smtpOn = setting('smtp_enabled', '0') === '1';
$err = '';

if (isset($_GET['reset'])) {
    unset($_SESSION['reg']);
    header('Location: register.php');
    exit;
}

$step = 'form';
$reg = $_SESSION['reg'] ?? null;
if ($smtpOn && $reg && $reg['expires'] > time()) $step = 'verify';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'register' && !$smtpOn) {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm  = (string)($_POST['confirm'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
            $err = '用户名需为 3-20 位字母、数字或下划线';
        } elseif (strlen($password) < 6) {
            $err = '密码至少 6 位';
        } elseif ($password !== $confirm) {
            $err = '两次输入的密码不一致';
        } else {
            try {
                db()->prepare('INSERT INTO users(username, password, created_at) VALUES (?, ?, ?)')
                    ->execute([$username, password_hash($password, PASSWORD_DEFAULT), now()]);
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)db()->lastInsertId();
                header('Location: index.php');
                exit;
            } catch (PDOException $e) {
                $err = '该用户名已被注册';
            }
        }
    } elseif ($action === 'send' && $smtpOn) {
        $username = trim((string)($_POST['username'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm  = (string)($_POST['confirm'] ?? '');
        $st = db()->prepare('SELECT id FROM users WHERE username = ? OR (email != \'\' AND email = ?)');
        $st->execute([$username, $email]);
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
            $err = '用户名需为 3-20 位字母、数字或下划线';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = '邮箱格式不正确';
        } elseif (strlen($password) < 6) {
            $err = '密码至少 6 位';
        } elseif ($password !== $confirm) {
            $err = '两次输入的密码不一致';
        } elseif ($st->fetch()) {
            $err = '用户名或邮箱已被注册';
        } else {
            $code = (string)random_int(100000, 999999);
            $_SESSION['reg'] = [
                'username' => $username,
                'email'    => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'code'     => $code,
                'expires'  => time() + 600,
            ];
            [$ok, $e] = smtp_send(
                $email,
                setting('site_name', 'HiCloud IDC') . ' 注册验证码',
                "您正在注册 " . setting('site_name', 'HiCloud IDC') . " 账号。\r\n\r\n验证码：$code\r\n\r\n验证码 10 分钟内有效，请勿泄露给他人。"
            );
            if (!$ok) {
                unset($_SESSION['reg']);
                $err = '邮件发送失败：' . $e;
            } else {
                $reg = $_SESSION['reg'];
                $step = 'verify';
            }
        }
    } elseif ($action === 'verify' && $smtpOn) {
        $reg  = $_SESSION['reg'] ?? null;
        $code = trim((string)($_POST['code'] ?? ''));
        if (!$reg) {
            $err = '请先填写注册信息';
        } elseif ($reg['expires'] < time()) {
            unset($_SESSION['reg']);
            $err = '验证码已过期，请重新注册';
        } elseif (!hash_equals((string)$reg['code'], $code)) {
            $err = '验证码错误';
            $step = 'verify';
        } else {
            try {
                db()->prepare('INSERT INTO users(username, password, email, created_at) VALUES (?, ?, ?, ?)')
                    ->execute([$reg['username'], $reg['password'], $reg['email'], now()]);
                unset($_SESSION['reg']);
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)db()->lastInsertId();
                header('Location: index.php');
                exit;
            } catch (PDOException $e) {
                unset($_SESSION['reg']);
                $err = '用户名或邮箱已被注册';
            }
        }
        $reg = $_SESSION['reg'] ?? null;
    }
}

page_head('注册');
notice('', $err);
?>
<div class="card auth-card">
<?php if ($smtpOn && $step === 'verify' && $reg): ?>
  <h1>邮箱验证</h1>
  <p class="muted">验证码已发送至 <b><?= h(mask_email($reg['email'])) ?></b>，10 分钟内有效</p>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="verify">
    <label class="md-field">
      <input name="code" required placeholder=" " autocomplete="one-time-code" maxlength="6" inputmode="numeric">
      <span>6 位验证码</span>
    </label>
    <button class="md-btn filled">完成注册</button>
  </form>
  <p class="muted">没收到邮件？<a href="register.php?reset=1">重新填写注册信息</a></p>
<?php else: ?>
  <h1>注册</h1>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $smtpOn ? 'send' : 'register' ?>">
    <label class="md-field">
      <input name="username" required placeholder=" " autocomplete="username" value="<?= h($_POST['username'] ?? '') ?>">
      <span>用户名</span>
    </label>
    <?php if ($smtpOn): ?>
    <label class="md-field">
      <input type="email" name="email" required placeholder=" " autocomplete="email" value="<?= h($_POST['email'] ?? '') ?>">
      <span>邮箱</span>
    </label>
    <?php endif; ?>
    <label class="md-field">
      <input type="password" name="password" required placeholder=" " autocomplete="new-password">
      <span>密码</span>
    </label>
    <label class="md-field">
      <input type="password" name="confirm" required placeholder=" " autocomplete="new-password">
      <span>确认密码</span>
    </label>
    <button class="md-btn filled"><?= $smtpOn ? '发送验证码' : '注册' ?></button>
  </form>
  <p class="muted">已有账号？<a href="login.php">直接登录</a></p>
<?php endif; ?>
</div>
<?php page_foot(); ?>
