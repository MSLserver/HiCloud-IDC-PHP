<?php
require __DIR__ . '/inc.php';
$user = require_login();

$msg = (string)($_SESSION['flash'] ?? '');
unset($_SESSION['flash']);

$st = db()->prepare('SELECT o.*, p.description FROM orders o
                     LEFT JOIN products p ON p.id = o.product_id
                     WHERE o.user_id = ? ORDER BY o.id DESC');
$st->execute([$user['id']]);
$orders = $st->fetchAll();

page_head('我的产品');
notice($msg, '');
if (!$orders):
?>
<div class="card">
  <p class="muted">您还没有订购任何产品。</p>
  <a class="md-btn filled" href="products.php">去订购产品</a>
</div>
<?php else: ?>
<div class="card">
  <h2>我的产品（共 <?= count($orders) ?> 个）</h2>
  <table class="md-table">
    <thead><tr><th>产品</th><th>描述</th><th>消耗积分</th><th>订购时间</th></tr></thead>
    <tbody>
      <?php foreach ($orders as $o): ?>
      <tr>
        <td><?= h($o['product_name']) ?></td>
        <td class="muted"><?= h($o['description'] ?? '') !== '' ? h($o['description']) : '-' ?></td>
        <td><?= (int)$o['price'] ?></td>
        <td><?= h($o['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; page_foot(); ?>
