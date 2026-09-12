<?php
require __DIR__ . '/inc.php';
$user = require_login();
$err = '';

$pid = (int)($_REQUEST['id'] ?? 0);
$st = db()->prepare('SELECT * FROM products WHERE id = ?');
$st->execute([$pid]);
$p = $st->fetch();

if (!$p) {
    page_head('确认订单');
    notice('', '产品不存在或已下架');
    echo '<div class="card"><a class="md-btn tonal" href="products.php">返回产品列表</a></div>';
    page_foot();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pdo = db();
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $st->execute([$pid]);
    $p = $st->fetch();
    if (!$p) {
        $err = '产品不存在或已下架';
    } elseif ((int)$p['stock'] === 0) {
        $err = '该产品已售罄';
    } elseif ((int)$user['points'] < (int)$p['price']) {
        $err = '积分不足，请先签到或使用兑换码';
    } else {
        $pdo->prepare('UPDATE users SET points = points - ? WHERE id = ?')->execute([$p['price'], $user['id']]);
        if ((int)$p['stock'] > 0) {
            $pdo->prepare('UPDATE products SET stock = stock - 1 WHERE id = ?')->execute([$pid]);
        }
        $pdo->prepare('INSERT INTO orders(user_id, product_id, product_name, price, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$user['id'], $pid, $p['name'], $p['price'], now()]);
        $pdo->commit();
        $_SESSION['flash'] = '订购成功：' . $p['name'] . '（消耗 ' . (int)$p['price'] . ' 积分）';
        header('Location: my_products.php');
        exit;
    }
    $pdo->commit();
}

page_head('确认订单');
notice('', $err);

$price = (int)$p['price'];
$balance = (int)$user['points'];
$after = $balance - $price;
$enough = $after >= 0;
?>
<div class="card confirm-card">
  <h1>确认订单</h1>
  <p class="muted">请确认以下产品信息，确认无误后点击购买</p>
  <div class="kv"><span>产品名称</span><b><?= h($p['name']) ?></b></div>
  <div class="kv"><span>产品描述</span><b><?= h($p['description']) !== '' ? h($p['description']) : '暂无描述' ?></b></div>
  <div class="kv"><span>库存状态</span><b>
    <?php if ((int)$p['stock'] < 0): ?>充足
    <?php elseif ((int)$p['stock'] === 0): ?>已售罄
    <?php else: ?>剩余 <?= (int)$p['stock'] ?> 件<?php endif; ?>
  </b></div>
  <div class="kv"><span>所需积分</span><b class="prod-price"><?= $price ?> 积分</b></div>
  <div class="kv"><span>当前积分</span><b><?= $balance ?></b></div>
  <div class="kv"><span>订购后余额</span><b class="<?= $enough ? '' : 'text-err' ?>"><?= $enough ? $after : '积分不足' ?></b></div>
  <form method="post" class="btn-row">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
    <button class="md-btn filled" <?= (!$enough || (int)$p['stock'] === 0) ? 'disabled' : '' ?>>确认购买</button>
    <a class="md-btn outlined" href="products.php">取消</a>
  </form>
  <?php if (!$enough): ?>
  <p class="muted">积分不足，可前往 <a href="index.php">控制台</a> 签到或使用兑换码获取积分。</p>
  <?php endif; ?>
</div>
<?php page_foot(); ?>
