<?php
require __DIR__ . '/inc.php';
$user = require_login();

$products = db()->query('SELECT * FROM products ORDER BY id DESC')->fetchAll();

page_head('订购产品');
if (!$products):
?>
<div class="card"><p class="muted">暂无可订购的产品，请等待管理员上架。</p></div>
<?php else: ?>
<div class="grid">
  <?php foreach ($products as $p): ?>
  <div class="card prod">
    <h2><?= h($p['name']) ?></h2>
    <p class="muted"><?= h($p['description']) !== '' ? h($p['description']) : '暂无描述' ?></p>
    <div class="prod-meta">
      <span class="prod-price"><?= (int)$p['price'] ?> 积分</span>
      <?php if ((int)$p['stock'] < 0): ?>
        <span class="tag">库存充足</span>
      <?php elseif ((int)$p['stock'] === 0): ?>
        <span class="tag gray">已售罄</span>
      <?php else: ?>
        <span class="tag">剩余 <?= (int)$p['stock'] ?> 件</span>
      <?php endif; ?>
    </div>
    <?php if ((int)$p['stock'] === 0): ?>
      <div><button class="md-btn filled" disabled>已售罄</button></div>
    <?php else: ?>
      <div><a class="md-btn filled" href="order.php?id=<?= (int)$p['id'] ?>">立即订购</a></div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; page_foot(); ?>
