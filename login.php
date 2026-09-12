<?php
require __DIR__ . '/inc.php';
if (current_user()) { header('Location: index.php'); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([trim((string)($_POST['username'] ?? ''))]);
    $row = $st->fetch();
    if ($row && password_verify((string)($_POST['password'] ?? ''), $row['password'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = $row['id'];
        header('Location: index.php');
        exit;
    }
    $err = '用户名或密码错误';
}

page_head('登录');
$msg = (string)($_SESSION['flash'] ?? '');
unset($_SESSION['flash']);
notice($msg, $err);
?>
<div class="card auth-card">
  <h1>登录</h1>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <label class="md-field">
      <input name="username" required placeholder=" " autocomplete="username">
      <span>用户名</span>
    </label>
    <label class="md-field">
      <input type="password" name="password" required placeholder=" " autocomplete="current-password">
      <span>密码</span>
    </label>
    <button class="md-btn filled">登录</button>
  </form>
  <p class="muted">还没有账号？<a href="register.php">立即注册</a> · <a href="forgot.php">忘记密码？</a></p>
  <?php $channels = oauth_configured() ? oauth_enabled_channels() : []; ?>
  <?php if ($channels): ?>
  <div class="oauth-row">
    <p class="muted">或使用第三方账号登录</p>
    <div class="inline" style="flex-wrap:wrap">
      <?php foreach ($channels as $t => $label): ?>
      <a class="md-btn outlined small" href="oauth.php?type=<?= h($t) ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php page_foot(); ?>
