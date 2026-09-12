<?php
require __DIR__ . '/inc.php';
$user = require_login();
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $today = date('Y-m-d');

    if ($action === 'checkin') {
        if ($user['last_checkin'] === $today) {
            $err = '今天已经签到过了，明天再来吧';
        } else {
            $gain = max(0, (int)setting('checkin_points', '10'));
            db()->prepare('UPDATE users SET points = points + ?, last_checkin = ? WHERE id = ?')
                ->execute([$gain, $today, $user['id']]);
            $msg = "签到成功，获得 {$gain} 积分";
        }
    } elseif ($action === 'redeem') {
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        if ($code === '') {
            $err = '请输入兑换码';
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT * FROM codes WHERE code = ?');
            $st->execute([$code]);
            $row = $st->fetch();
            if (!$row) {
                $err = '兑换码不存在';
            } elseif ($row['used_by']) {
                $err = '兑换码已被使用';
            } else {
                $pdo->prepare("UPDATE codes SET used_by = ?, used_at = ? WHERE id = ?")
                    ->execute([$user['id'], now(), $row['id']]);
                $pdo->prepare('UPDATE users SET points = points + ? WHERE id = ?')
                    ->execute([$row['points'], $user['id']]);
                $msg = '兑换成功，获得 ' . (int)$row['points'] . ' 积分';
            }
            $pdo->commit();
        }
    }
    $user = current_user(true);
}

$checked = $user['last_checkin'] === date('Y-m-d');
$gain = (int)setting('checkin_points', '10');

page_head('控制台');
notice($msg, $err);
?>
<div class="grid">
  <div class="card">
    <h2>每日签到</h2>
    <p class="muted">今日签到可获得 <b><?= $gain ?></b> 积分</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="checkin">
      <button class="md-btn filled" <?= $checked ? 'disabled' : '' ?>>
        <?= $checked ? '今日已签到' : '立即签到' ?>
      </button>
    </form>
  </div>
  <div class="card">
    <h2>积分兑换</h2>
    <p class="muted">当前积分：<b><?= (int)$user['points'] ?></b></p>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="redeem">
      <label class="md-field">
        <input name="code" placeholder=" " autocomplete="off" maxlength="19">
        <span>兑换码</span>
      </label>
      <div><button class="md-btn tonal">立即兑换</button></div>
    </form>
  </div>
</div>
<?php
$st = db()->prepare('SELECT code, points, used_at FROM codes WHERE used_by = ? ORDER BY used_at DESC LIMIT 10');
$st->execute([$user['id']]);
$logs = $st->fetchAll();
if ($logs):
?>
<div class="card">
  <h2>我的兑换记录</h2>
  <table class="md-table">
    <thead><tr><th>兑换码</th><th>积分</th><th>时间</th></tr></thead>
    <tbody>
      <?php foreach ($logs as $l): ?>
      <tr>
        <td><code><?= h($l['code']) ?></code></td>
        <td>+<?= (int)$l['points'] ?></td>
        <td><?= h($l['used_at']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php
$st = db()->prepare('SELECT product_name, price, created_at FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 10');
$st->execute([$user['id']]);
$orders = $st->fetchAll();
if ($orders):
?>
<div class="card">
  <h2>我的订单</h2>
  <table class="md-table">
    <thead><tr><th>产品</th><th>消耗积分</th><th>时间</th></tr></thead>
    <tbody>
      <?php foreach ($orders as $o): ?>
      <tr>
        <td><?= h($o['product_name']) ?></td>
        <td>-<?= (int)$o['price'] ?></td>
        <td><?= h($o['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; page_foot(); ?>
