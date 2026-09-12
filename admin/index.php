<?php
require dirname(__DIR__) . '/inc.php';
$admin = require_admin();
$msg = $err = '';
$tab = (string)($_GET['tab'] ?? 'overview');

function gen_code(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $seg = function () use ($chars) {
        $s = '';
        for ($i = 0; $i < 4; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
        return $s;
    };
    return $seg() . '-' . $seg() . '-' . $seg() . '-' . $seg();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $name = trim((string)($_POST['site_name'] ?? ''));
        $points = (int)($_POST['checkin_points'] ?? -1);
        if ($name === '' || $points < 0) {
            $err = '参数无效';
        } else {
            set_setting('site_name', $name);
            set_setting('checkin_points', (string)$points);
            set_setting('smtp_enabled', isset($_POST['smtp_enabled']) ? '1' : '0');
            set_setting('smtp_host', trim((string)($_POST['smtp_host'] ?? '')));
            set_setting('smtp_port', (string)max(1, (int)($_POST['smtp_port'] ?? 465)));
            set_setting('smtp_user', trim((string)($_POST['smtp_user'] ?? '')));
            if ((string)($_POST['smtp_pass'] ?? '') !== '') {
                set_setting('smtp_pass', (string)$_POST['smtp_pass']);
            }
            $secure = (string)($_POST['smtp_secure'] ?? 'ssl');
            set_setting('smtp_secure', in_array($secure, ['ssl', 'tls', 'none'], true) ? $secure : 'ssl');
            set_setting('smtp_from_name', trim((string)($_POST['smtp_from_name'] ?? '')));
            set_setting('oauth_appid', trim((string)($_POST['oauth_appid'] ?? '')));
            set_setting('oauth_appkey', trim((string)($_POST['oauth_appkey'] ?? '')));
            set_setting('oauth_api', trim((string)($_POST['oauth_api'] ?? '')));
            $raw = $_POST['oauth_types'] ?? [];
            if (is_string($raw)) $raw = explode(',', $raw);
            $enabled = array_intersect(array_map('trim', (array)$raw), array_keys(oauth_channels()));
            set_setting('oauth_types', implode(',', $enabled));
            $msg = '设置已保存';
        }
        $tab = 'settings';
    } elseif ($action === 'gen_codes') {
        $count  = min(100, max(1, (int)($_POST['count'] ?? 1)));
        $points = max(1, (int)($_POST['points'] ?? 100));
        $st = db()->prepare('INSERT INTO codes(code, points, created_at) VALUES (?, ?, ?)');
        for ($i = 0; $i < $count; $i++) $st->execute([gen_code(), $points, now()]);
        $msg = "已生成 {$count} 个兑换码（每个 {$points} 积分）";
        $tab = 'codes';
    } elseif ($action === 'del_code') {
        db()->prepare('DELETE FROM codes WHERE id = ? AND used_by IS NULL')->execute([(int)$_POST['id']]);
        $msg = '兑换码已删除';
        $tab = 'codes';
    } elseif ($action === 'set_points') {
        db()->prepare('UPDATE users SET points = ? WHERE id = ?')
            ->execute([max(0, (int)$_POST['points']), (int)$_POST['id']]);
        $msg = '积分已更新';
        $tab = 'users';
    } elseif ($action === 'toggle_role') {
        $id = (int)$_POST['id'];
        if ($id !== (int)$admin['id']) {
            db()->prepare("UPDATE users SET role = CASE role WHEN 'admin' THEN 'user' ELSE 'admin' END WHERE id = ?")
                ->execute([$id]);
            $msg = '角色已变更';
        }
        $tab = 'users';
    } elseif ($action === 'del_user') {
        $id = (int)$_POST['id'];
        if ($id !== (int)$admin['id']) {
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            $msg = '用户已删除';
        }
        $tab = 'users';
    } elseif ($action === 'add_product') {
        $name  = trim((string)($_POST['name'] ?? ''));
        $desc  = trim((string)($_POST['description'] ?? ''));
        $price = (int)($_POST['price'] ?? -1);
        $stock = (int)($_POST['stock'] ?? -1);
        if ($name === '' || $price < 0 || $stock < -1) {
            $err = '参数无效';
        } else {
            db()->prepare('INSERT INTO products(name, description, price, stock) VALUES (?, ?, ?, ?)')
                ->execute([$name, $desc, $price, $stock]);
            $msg = '产品已添加';
        }
        $tab = 'products';
    } elseif ($action === 'edit_product') {
        $price = (int)($_POST['price'] ?? -1);
        $stock = (int)($_POST['stock'] ?? -1);
        if ($price >= 0 && $stock >= -1) {
            db()->prepare('UPDATE products SET price = ?, stock = ? WHERE id = ?')
                ->execute([$price, $stock, (int)$_POST['id']]);
            $msg = '产品已更新';
        } else {
            $err = '参数无效';
        }
        $tab = 'products';
    } elseif ($action === 'del_product') {
        db()->prepare('DELETE FROM products WHERE id = ?')->execute([(int)$_POST['id']]);
        $msg = '产品已删除';
        $tab = 'products';
    } elseif ($action === 'add_ann') {
        $title   = trim((string)($_POST['title'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        if ($title === '' || $content === '') {
            $err = '标题和内容不能为空';
        } else {
            db()->prepare('INSERT INTO announcements(title, content, created_at) VALUES (?, ?, ?)')
                ->execute([$title, $content, now()]);
            $msg = '公告已发布';
        }
        $tab = 'ann';
    } elseif ($action === 'del_ann') {
        db()->prepare('DELETE FROM announcements WHERE id = ?')->execute([(int)$_POST['id']]);
        $msg = '公告已删除';
        $tab = 'ann';
    }
}

page_head('管理后台');
notice($msg, $err);

$tabs = ['overview' => '概览', 'ann' => '公告管理', 'settings' => '系统设置', 'products' => '产品管理', 'orders' => '订单记录', 'codes' => '兑换码', 'users' => '用户管理'];
echo '<div class="tabs">';
foreach ($tabs as $k => $label) {
    $cls = $tab === $k ? ' class="active"' : '';
    echo '<a' . $cls . ' href="?tab=' . $k . '">' . $label . '</a>';
}
echo '</div>';

if ($tab === 'overview'):
    $totalUsers  = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $totalPoints = (int)db()->query('SELECT COALESCE(SUM(points),0) FROM users')->fetchColumn();
    $codesUsed   = (int)db()->query('SELECT COUNT(*) FROM codes WHERE used_by IS NOT NULL')->fetchColumn();
    $codesFree   = (int)db()->query('SELECT COUNT(*) FROM codes WHERE used_by IS NULL')->fetchColumn();
    $st = db()->prepare('SELECT COUNT(*) FROM users WHERE last_checkin = ?');
    $st->execute([date('Y-m-d')]);
    $todayCheck  = (int)$st->fetchColumn();
    $totalOrders = (int)db()->query('SELECT COUNT(*) FROM orders')->fetchColumn();
?>
<div class="grid">
  <div class="card stat"><div class="num"><?= $totalUsers ?></div><div class="lbl">注册用户</div></div>
  <div class="card stat"><div class="num"><?= $totalPoints ?></div><div class="lbl">用户积分总量</div></div>
  <div class="card stat"><div class="num"><?= $codesUsed ?> / <?= $codesFree ?></div><div class="lbl">兑换码 已用 / 未用</div></div>
  <div class="card stat"><div class="num"><?= $todayCheck ?></div><div class="lbl">今日签到人数</div></div>
  <div class="card stat"><div class="num"><?= $totalOrders ?></div><div class="lbl">订单总数</div></div>
</div>

<?php elseif ($tab === 'settings'): ?>
<div class="card" style="max-width:560px">
  <h2>系统设置</h2>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_settings">
    <label class="md-field">
      <input name="site_name" required placeholder=" " value="<?= h(setting('site_name', 'HiCloud IDC')) ?>">
      <span>站点名称</span>
    </label>
    <label class="md-field">
      <input type="number" name="checkin_points" required min="0" placeholder=" " value="<?= h(setting('checkin_points', '10')) ?>">
      <span>每日签到积分</span>
    </label>
    <h2 style="margin-top:12px">SMTP 邮箱设置</h2>
    <p class="muted">开启后，用户注册需通过邮箱验证码验证</p>
    <label class="md-check">
      <input type="checkbox" name="smtp_enabled" value="1" <?= setting('smtp_enabled', '0') === '1' ? 'checked' : '' ?>>
      启用邮箱验证码注册
    </label>
    <div class="inline">
      <label class="md-field small" style="flex:2">
        <input name="smtp_host" placeholder=" " value="<?= h(setting('smtp_host')) ?>">
        <span>SMTP 服务器</span>
      </label>
      <label class="md-field small" style="flex:1">
        <input type="number" name="smtp_port" min="1" placeholder=" " value="<?= h(setting('smtp_port', '465')) ?>">
        <span>端口</span>
      </label>
      <select name="smtp_secure" class="md-select" title="加密方式">
        <?php $sec = setting('smtp_secure', 'ssl'); ?>
        <option value="ssl" <?= $sec === 'ssl' ? 'selected' : '' ?>>SSL</option>
        <option value="tls" <?= $sec === 'tls' ? 'selected' : '' ?>>TLS</option>
        <option value="none" <?= $sec === 'none' ? 'selected' : '' ?>>无加密</option>
      </select>
    </div>
    <label class="md-field">
      <input name="smtp_user" placeholder=" " autocomplete="off" value="<?= h(setting('smtp_user')) ?>">
      <span>SMTP 账号（发件邮箱）</span>
    </label>
    <label class="md-field">
      <input type="password" name="smtp_pass" placeholder=" " autocomplete="new-password">
      <span>SMTP 密码 / 授权码（留空不修改）</span>
    </label>
    <label class="md-field">
      <input name="smtp_from_name" placeholder=" " value="<?= h(setting('smtp_from_name')) ?>">
      <span>发件人名称（留空使用站点名称）</span>
    </label>
    <h2 style="margin-top:12px">聚合登录（第三方账号登录）</h2>
    <p class="muted">留空 AppID 则关闭第三方登录；回调地址需在聚合登录平台授权</p>
    <div class="inline">
      <label class="md-field small" style="flex:1">
        <input name="oauth_appid" placeholder=" " value="<?= h(setting('oauth_appid')) ?>">
        <span>AppID</span>
      </label>
      <label class="md-field small" style="flex:2">
        <input name="oauth_appkey" placeholder=" " autocomplete="off" value="<?= h(setting('oauth_appkey')) ?>">
        <span>AppKey</span>
      </label>
    </div>
    <label class="md-field">
      <input name="oauth_api" placeholder=" " value="<?= h(setting('oauth_api')) ?>">
      <span>接口地址（留空使用官方默认）</span>
    </label>
    <p class="muted" style="margin:0">启用的登录渠道（勾选后显示在登录页）：</p>
    <div class="check-grid">
      <?php $onTypes = array_keys(oauth_enabled_channels()); ?>
      <?php foreach (oauth_channels() as $t => $label): ?>
      <label class="md-check">
        <input type="checkbox" name="oauth_types[]" value="<?= h($t) ?>" <?= in_array($t, $onTypes, true) ? 'checked' : '' ?>>
        <?= h($label) ?>
      </label>
      <?php endforeach; ?>
    </div>
    <div><button class="md-btn filled">保存设置</button></div>
  </form>
</div>

<?php elseif ($tab === 'codes'): ?>
<div class="card" style="max-width:560px">
  <h2>生成兑换码</h2>
  <form method="post" class="inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="gen_codes">
    <label class="md-field small" style="flex:1">
      <input type="number" name="count" required min="1" max="100" placeholder=" " value="5">
      <span>数量（1-100）</span>
    </label>
    <label class="md-field small" style="flex:1">
      <input type="number" name="points" required min="1" placeholder=" " value="100">
      <span>每个积分</span>
    </label>
    <button class="md-btn filled small">生成</button>
  </form>
</div>
<div class="card">
  <h2>兑换码列表（最近 100 条）</h2>
  <table class="md-table">
    <thead><tr><th>兑换码</th><th>积分</th><th>状态</th><th>使用者</th><th>操作</th></tr></thead>
    <tbody>
    <?php
    $rows = db()->query('SELECT c.*, u.username FROM codes c LEFT JOIN users u ON u.id = c.used_by
                         ORDER BY c.id DESC LIMIT 100')->fetchAll();
    foreach ($rows as $r):
    ?>
      <tr>
        <td><code><?= h($r['code']) ?></code></td>
        <td><?= (int)$r['points'] ?></td>
        <td><?= $r['used_by'] ? '<span class="tag gray">已使用</span>' : '<span class="tag">未使用</span>' ?></td>
        <td><?= h($r['username'] ?? '-') ?></td>
        <td>
          <?php if (!$r['used_by']): ?>
          <form method="post" onsubmit="return confirm('确定删除该兑换码？')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="del_code">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="md-btn text small">删除</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php elseif ($tab === 'users'): ?>
<div class="card">
  <h2>用户管理</h2>
  <table class="md-table">
    <thead><tr><th>ID</th><th>用户名</th><th>邮箱</th><th>角色</th><th>积分</th><th>最近签到</th><th>操作</th></tr></thead>
    <tbody>
    <?php foreach (db()->query('SELECT * FROM users ORDER BY id ASC')->fetchAll() as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= h($r['username']) ?></td>
        <td class="muted"><?= h($r['email'] ?? '') !== '' ? h($r['email']) : '-' ?></td>
        <td><?= $r['role'] === 'admin' ? '<span class="tag">管理员</span>' : '<span class="tag gray">用户</span>' ?></td>
        <td>
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_points">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <label class="md-field small" style="width:110px">
              <input type="number" name="points" min="0" placeholder=" " value="<?= (int)$r['points'] ?>">
              <span>积分</span>
            </label>
            <button class="md-btn tonal small">保存</button>
          </form>
        </td>
        <td><?= h($r['last_checkin'] ?? '从未') ?></td>
        <td class="inline">
          <?php if ((int)$r['id'] !== (int)$admin['id']): ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_role">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="md-btn outlined small"><?= $r['role'] === 'admin' ? '降为用户' : '设为管理员' ?></button>
          </form>
          <form method="post" onsubmit="return confirm('确定删除该用户？')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="del_user">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="md-btn text small">删除</button>
          </form>
          <?php else: ?>
          <span class="muted">当前账号</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php elseif ($tab === 'products'): ?>
<div class="card" style="max-width:720px">
  <h2>添加产品</h2>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_product">
    <div class="inline">
      <label class="md-field small" style="flex:2">
        <input name="name" required placeholder=" ">
        <span>产品名称</span>
      </label>
      <label class="md-field small" style="flex:1">
        <input type="number" name="price" required min="0" placeholder=" " value="100">
        <span>价格（积分）</span>
      </label>
      <label class="md-field small" style="flex:1">
        <input type="number" name="stock" required min="-1" placeholder=" " value="-1">
        <span>库存（-1 不限）</span>
      </label>
    </div>
    <label class="md-field">
      <input name="description" placeholder=" ">
      <span>产品描述</span>
    </label>
    <div><button class="md-btn filled">添加产品</button></div>
  </form>
</div>
<div class="card">
  <h2>产品列表</h2>
  <table class="md-table">
    <thead><tr><th>ID</th><th>名称</th><th>描述</th><th>价格 / 库存</th><th>操作</th></tr></thead>
    <tbody>
    <?php foreach (db()->query('SELECT * FROM products ORDER BY id DESC')->fetchAll() as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= h($r['name']) ?></td>
        <td class="muted"><?= h($r['description']) !== '' ? h($r['description']) : '-' ?></td>
        <td>
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_product">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <label class="md-field small" style="width:100px">
              <input type="number" name="price" min="0" placeholder=" " value="<?= (int)$r['price'] ?>">
              <span>价格</span>
            </label>
            <label class="md-field small" style="width:100px">
              <input type="number" name="stock" min="-1" placeholder=" " value="<?= (int)$r['stock'] ?>">
              <span>库存</span>
            </label>
            <button class="md-btn tonal small">保存</button>
          </form>
        </td>
        <td>
          <form method="post" onsubmit="return confirm('确定删除该产品？')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="del_product">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="md-btn text small">删除</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php elseif ($tab === 'orders'): ?>
<div class="card">
  <h2>订单记录（最近 100 条）</h2>
  <table class="md-table">
    <thead><tr><th>ID</th><th>用户</th><th>产品</th><th>积分</th><th>时间</th></tr></thead>
    <tbody>
    <?php
    $rows = db()->query('SELECT o.*, u.username FROM orders o LEFT JOIN users u ON u.id = o.user_id
                         ORDER BY o.id DESC LIMIT 100')->fetchAll();
    foreach ($rows as $r):
    ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= h($r['username'] ?? '-') ?></td>
        <td><?= h($r['product_name']) ?></td>
        <td><?= (int)$r['price'] ?></td>
        <td><?= h($r['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php elseif ($tab === 'ann'): ?>
<div class="card" style="max-width:640px">
  <h2>发布公告</h2>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_ann">
    <label class="md-field">
      <input name="title" required placeholder=" " maxlength="255">
      <span>公告标题</span>
    </label>
    <textarea class="md-textarea" name="content" required placeholder="公告内容"></textarea>
    <div><button class="md-btn filled">发布公告</button></div>
  </form>
</div>
<div class="card">
  <h2>历史公告</h2>
  <table class="md-table">
    <thead><tr><th>ID</th><th>标题</th><th>内容</th><th>发布时间</th><th>操作</th></tr></thead>
    <tbody>
    <?php foreach (db()->query('SELECT * FROM announcements ORDER BY id DESC LIMIT 100')->fetchAll() as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= h($r['title']) ?></td>
        <td class="muted"><?= h(mb_strimwidth($r['content'], 0, 60, '…')) ?></td>
        <td><?= h($r['created_at']) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('确定删除该公告？')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="del_ann">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="md-btn text small">删除</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; page_foot(); ?>
