<?php
require __DIR__ . '/inc.php';
$user = require_login();

$list = db()->query('SELECT * FROM announcements ORDER BY id DESC')->fetchAll();

page_head('公告');
if (!$list):
?>
<div class="card"><p class="muted">暂无公告。</p></div>
<?php else: ?>
  <?php foreach ($list as $a): ?>
  <div class="card">
    <h2><?= h($a['title']) ?></h2>
    <p class="muted" style="margin:0 0 8px"><?= h($a['created_at']) ?></p>
    <div class="ann-content"><?= nl2br(h($a['content'])) ?></div>
  </div>
  <?php endforeach; ?>
<?php endif; page_foot(); ?>
