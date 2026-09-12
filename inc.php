<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

define('BASE_DIR', __DIR__);
define('DB_PATH', BASE_DIR . '/data.sqlite');
define('CONFIG_FILE', BASE_DIR . '/config.php');
define('LOCK_FILE', BASE_DIR . '/data/installed.lock');

function is_installed(): bool {
    return file_exists(LOCK_FILE) || file_exists(CONFIG_FILE) || file_exists(DB_PATH);
}

if (!is_installed() && basename((string)$_SERVER['SCRIPT_NAME']) !== 'install.php') {
    $target = str_contains((string)$_SERVER['SCRIPT_NAME'], '/admin/') ? '../install.php' : 'install.php';
    header('Location: ' . $target);
    exit;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $cfg = file_exists(CONFIG_FILE) ? (array)(require CONFIG_FILE) : [];
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if (($cfg['db_type'] ?? 'sqlite') === 'mysql') {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    (string)($cfg['db_host'] ?? '127.0.0.1'),
                    (int)($cfg['db_port'] ?? 3306),
                    (string)($cfg['db_name'] ?? '')),
                (string)($cfg['db_user'] ?? ''),
                (string)($cfg['db_pass'] ?? ''),
                $opts
            );
        } else {
            $pdo = new PDO('sqlite:' . (string)($cfg['db_path'] ?? DB_PATH), null, null, $opts);
        }
        init_schema($pdo);
    }
    return $pdo;
}

function db_mysql(PDO $pdo): bool {
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
}

function now(): string {
    return date('Y-m-d H:i:s');
}

function init_schema(PDO $pdo): void {
    if (db_mysql($pdo)) {
        $tables = [
            "CREATE TABLE IF NOT EXISTS users (
              id INT AUTO_INCREMENT PRIMARY KEY,
              username VARCHAR(64) NOT NULL UNIQUE,
              password VARCHAR(255) NOT NULL,
              role VARCHAR(16) NOT NULL DEFAULT 'user',
              points INT NOT NULL DEFAULT 0,
              email VARCHAR(255) NULL,
              pwd_set TINYINT NOT NULL DEFAULT 1,
              last_checkin VARCHAR(10) NULL,
              created_at VARCHAR(19) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS codes (
              id INT AUTO_INCREMENT PRIMARY KEY,
              code VARCHAR(32) NOT NULL UNIQUE,
              points INT NOT NULL,
              used_by INT NULL,
              used_at VARCHAR(19) NULL,
              created_at VARCHAR(19) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS products (
              id INT AUTO_INCREMENT PRIMARY KEY,
              name VARCHAR(255) NOT NULL,
              description TEXT,
              price INT NOT NULL DEFAULT 0,
              stock INT NOT NULL DEFAULT -1,
              created_at VARCHAR(19) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS orders (
              id INT AUTO_INCREMENT PRIMARY KEY,
              user_id INT NOT NULL,
              product_id INT NOT NULL,
              product_name VARCHAR(255) NOT NULL,
              price INT NOT NULL,
              created_at VARCHAR(19) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS social_accounts (
              id INT AUTO_INCREMENT PRIMARY KEY,
              user_id INT NOT NULL,
              type VARCHAR(32) NOT NULL,
              social_uid VARCHAR(128) NOT NULL,
              nickname VARCHAR(255) NOT NULL DEFAULT '',
              faceimg VARCHAR(512) NOT NULL DEFAULT '',
              created_at VARCHAR(19) NOT NULL,
              UNIQUE KEY type_uid (type, social_uid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS settings (
              `key` VARCHAR(64) PRIMARY KEY,
              value TEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS announcements (
              id INT AUTO_INCREMENT PRIMARY KEY,
              title VARCHAR(255) NOT NULL,
              content TEXT NOT NULL,
              created_at VARCHAR(19) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        $ignore = 'INSERT IGNORE';
    } else {
        $tables = [
            "CREATE TABLE IF NOT EXISTS users (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              username TEXT NOT NULL UNIQUE,
              password TEXT NOT NULL,
              role TEXT NOT NULL DEFAULT 'user',
              points INTEGER NOT NULL DEFAULT 0,
              email TEXT,
              pwd_set INTEGER NOT NULL DEFAULT 1,
              last_checkin TEXT,
              created_at TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS codes (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              code TEXT NOT NULL UNIQUE,
              points INTEGER NOT NULL,
              used_by INTEGER,
              used_at TEXT,
              created_at TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS products (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              name TEXT NOT NULL,
              description TEXT NOT NULL DEFAULT '',
              price INTEGER NOT NULL DEFAULT 0,
              stock INTEGER NOT NULL DEFAULT -1,
              created_at TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS orders (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id INTEGER NOT NULL,
              product_id INTEGER NOT NULL,
              product_name TEXT NOT NULL,
              price INTEGER NOT NULL,
              created_at TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS social_accounts (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id INTEGER NOT NULL,
              type TEXT NOT NULL,
              social_uid TEXT NOT NULL,
              nickname TEXT NOT NULL DEFAULT '',
              faceimg TEXT NOT NULL DEFAULT '',
              created_at TEXT NOT NULL,
              UNIQUE(type, social_uid)
            )",
            "CREATE TABLE IF NOT EXISTS settings (
              `key` TEXT PRIMARY KEY,
              value TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS announcements (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              title TEXT NOT NULL,
              content TEXT NOT NULL,
              created_at TEXT NOT NULL
            )",
        ];
        $ignore = 'INSERT OR IGNORE';
    }
    foreach ($tables as $sql) $pdo->exec($sql);
    $st = $pdo->prepare("$ignore INTO settings(`key`, value) VALUES (?, ?)");
    foreach ([
        'site_name' => 'HiCloud IDC',
        'checkin_points' => '10',
        'smtp_enabled' => '0',
        'smtp_host' => '',
        'smtp_port' => '465',
        'smtp_user' => '',
        'smtp_pass' => '',
        'smtp_secure' => 'ssl',
        'smtp_from_name' => '',
        'oauth_appid' => 'YOUR_APPID',
        'oauth_appkey' => 'YOUR_APPKEY',
        'oauth_api' => '',
        'oauth_types' => 'qq,wx,alipay,sina,github',
    ] as $k => $v) {
        $st->execute([$k, $v]);
    }
    if (!db_mysql($pdo)) {
        // 旧版本 SQLite 数据库字段迁移
        $cols = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('email', $cols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN email TEXT');
        }
        if (!in_array('pwd_set', $cols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN pwd_set INTEGER NOT NULL DEFAULT 1');
        }
    }
    if (!defined('INSTALLING')) {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
        if ($cnt === 0) {
            $pdo->prepare("INSERT INTO users(username, password, role, created_at) VALUES ('admin', ?, 'admin', ?)")
                ->execute([password_hash('admin123', PASSWORD_DEFAULT), now()]);
        }
    }
}

function setting(string $key, string $default = ''): string {
    $st = db()->prepare('SELECT value FROM settings WHERE `key` = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function set_setting(string $key, string $value): void {
    $pdo = db();
    if (db_mysql($pdo)) {
        $pdo->prepare('INSERT INTO settings(`key`, value) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$key, $value]);
    } else {
        $pdo->prepare('INSERT INTO settings(`key`, value) VALUES (?, ?)
            ON CONFLICT(`key`) DO UPDATE SET value = excluded.value')->execute([$key, $value]);
    }
}

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function url(string $path): string {
    $inAdmin = str_contains($_SERVER['SCRIPT_NAME'], '/admin/');
    return ($inAdmin ? '../' : '') . $path;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('CSRF 校验失败');
    }
}

function current_user(bool $refresh = false): ?array {
    static $cache = null;
    if ($refresh) $cache = null;
    if ($cache !== null) return $cache;
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    $cache = $st->fetch() ?: null;
    return $cache;
}

function require_login(): array {
    $u = current_user();
    if (!$u) { header('Location: ' . url('login.php')); exit; }
    return $u;
}

function require_admin(): array {
    $u = require_login();
    if ($u['role'] !== 'admin') { http_response_code(403); exit('无权限访问'); }
    return $u;
}

function notice(string $msg, string $err): void {
    if ($msg !== '') echo '<div class="snackbar ok">' . h($msg) . '</div>';
    if ($err !== '') echo '<div class="snackbar err">' . h($err) . '</div>';
}

function smtp_send(string $to, string $subject, string $body): array {
    $host   = setting('smtp_host');
    $port   = (int)setting('smtp_port', '465');
    $user   = setting('smtp_user');
    $pass   = setting('smtp_pass');
    $secure = setting('smtp_secure', 'ssl');
    $fromName = setting('smtp_from_name') !== '' ? setting('smtp_from_name') : setting('site_name', 'HiCloud IDC');
    if ($host === '' || $user === '') return [false, 'SMTP 未正确配置'];

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host;
    $fp = @fsockopen($remote, $port, $errno, $errstr, 15);
    if (!$fp) return [false, "无法连接 SMTP 服务器 ($errstr)"];
    stream_set_timeout($fp, 15);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function (string $c) use ($fp, $read) {
        fwrite($fp, $c . "\r\n");
        return $read();
    };

    $read();
    $cmd('EHLO localhost');
    if ($secure === 'tls') {
        if (!str_starts_with($cmd('STARTTLS'), '220')) { fclose($fp); return [false, 'STARTTLS 协商失败']; }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return [false, 'TLS 加密失败'];
        }
        $cmd('EHLO localhost');
    }
    $cmd('AUTH LOGIN');
    $cmd(base64_encode($user));
    if (!str_starts_with($cmd(base64_encode($pass)), '235')) { fclose($fp); return [false, 'SMTP 账号认证失败']; }
    $cmd("MAIL FROM:<$user>");
    if (!str_starts_with($cmd("RCPT TO:<$to>"), '250')) { fclose($fp); return [false, '收件地址被拒绝']; }
    if (!str_starts_with($cmd('DATA'), '354')) { fclose($fp); return [false, 'DATA 命令被拒绝']; }
    $headers = 'From: =?UTF-8?B?' . base64_encode($fromName) . "?= <$user>\r\n"
             . "To: <$to>\r\n"
             . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n";
    fwrite($fp, $headers . "\r\n" . chunk_split(base64_encode($body)) . ".\r\n");
    $r = $read();
    $cmd('QUIT');
    fclose($fp);
    if (!str_starts_with($r, '250')) return [false, '发送被拒绝'];
    return [true, ''];
}

function mask_email(string $email): string {
    return preg_replace('/(^.).*(@.*$)/', '$1***$2', $email);
}

function oauth_channels(): array {
    return [
        'qq' => 'QQ', 'wx' => '微信', 'alipay' => '支付宝', 'sina' => '微博',
        'baidu' => '百度', 'douyin' => '抖音', 'huawei' => '华为', 'xiaomi' => '小米',
        'google' => '谷歌', 'microsoft' => '微软', 'dingtalk' => '钉钉',
        'gitee' => 'Gitee', 'github' => 'GitHub',
    ];
}

function oauth_enabled_channels(): array {
    $enabled = array_filter(array_map('trim', explode(',', setting('oauth_types', 'qq'))));
    return array_intersect_key(oauth_channels(), array_flip($enabled));
}

function oauth_configured(): bool {
    $id  = setting('oauth_appid');
    $key = setting('oauth_appkey');
    return $id !== '' && $key !== '' && $id !== 'YOUR_APPID' && $key !== 'YOUR_APPKEY';
}

function http_get(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'HiCloud-IDC/1.0',
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    return $r === false ? '' : (string)$r;
}

function icon(string $name): string {
    $paths = [
        'dashboard' => 'M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z',
        'cart' => 'M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.13.12-.27.12-.42 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z',
        'admin' => 'M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z',
        'menu' => 'M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z',
        'cloud' => 'M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z',
        'person' => 'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z',
        'bell' => 'M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z',
    ];
    return '<svg viewBox="0 0 24 24"><path d="' . $paths[$name] . '"/></svg>';
}

function sidebar(): void {
    $u = current_user();
    if (!$u) return;
    $script = (string)$_SERVER['SCRIPT_NAME'];
    $inAdmin = str_contains($script, '/admin/');
    $cur = basename($script);
    $items = [
        ['href' => 'index.php', 'label' => '控制台概述', 'icon' => 'dashboard', 'active' => !$inAdmin && $cur === 'index.php'],
        ['href' => 'products.php', 'label' => '订购产品', 'icon' => 'cart', 'active' => !$inAdmin && in_array($cur, ['products.php', 'order.php'], true)],
        ['href' => 'announcements.php', 'label' => '公告', 'icon' => 'bell', 'active' => !$inAdmin && $cur === 'announcements.php'],
        ['href' => 'my_products.php', 'label' => '我的产品', 'icon' => 'cloud', 'active' => !$inAdmin && $cur === 'my_products.php'],
        ['href' => 'profile.php', 'label' => '个人中心', 'icon' => 'person', 'active' => !$inAdmin && $cur === 'profile.php'],
    ];
    if ($u['role'] === 'admin') {
        $items[] = ['href' => 'admin/index.php', 'label' => '管理后台', 'icon' => 'admin', 'active' => $inAdmin];
    }
    echo '<aside class="sidebar"><nav class="menu">';
    foreach ($items as $it) {
        $cls = 'menu-item' . ($it['active'] ? ' active' : '');
        echo '<a class="' . $cls . '" href="' . url($it['href']) . '" title="' . h($it['label']) . '">'
            . icon($it['icon']) . '<span class="menu-label">' . h($it['label']) . '</span></a>';
    }
    echo '</nav></aside>';
}

function page_head(string $title): void {
    $site = setting('site_name', 'HiCloud IDC');
    $u = current_user();
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · <?= h($site) ?></title>
<script>(function(){var m='system';try{m=localStorage.getItem('theme')||'system'}catch(e){}
var d=m==='dark'||(m==='system'&&matchMedia('(prefers-color-scheme: dark)').matches);
document.documentElement.dataset.theme=d?'dark':'light';
try{if(localStorage.getItem('sidebar')==='1')document.documentElement.classList.add('sidebar-collapsed')}catch(e){}})();</script>
<link rel="stylesheet" href="<?= url('assets/style.css') ?>">
</head>
<body>
<header class="topbar">
  <div class="topbar-left">
    <?php if ($u): ?>
    <button id="sidebarToggle" class="md-btn icon" title="收起 / 展开菜单" aria-label="切换菜单"><?= icon('menu') ?></button>
    <?php endif; ?>
    <a class="brand" href="<?= url('index.php') ?>"><?= h($site) ?></a>
  </div>
  <nav class="topnav">
    <?php if ($u): ?>
      <span class="points-chip">积分 <?= (int)$u['points'] ?></span>
      <?php if ($u['role'] === 'admin'): ?>
        <a class="md-btn text" href="<?= url('admin/index.php') ?>">管理后台</a>
      <?php endif; ?>
      <a class="md-btn text" href="<?= url('logout.php') ?>">退出</a>
    <?php else: ?>
      <a class="md-btn text" href="<?= url('login.php') ?>">登录</a>
      <a class="md-btn filled small" href="<?= url('register.php') ?>">注册</a>
    <?php endif; ?>
    <select id="themeSelect" class="md-select" title="主题">
      <option value="system">跟随系统</option>
      <option value="light">极简白</option>
      <option value="dark">极简黑</option>
    </select>
  </nav>
</header>
<?php if ($u): ?>
<div class="layout">
<?php sidebar(); ?>
<main class="container">
<?php else: ?>
<main class="container">
<?php endif;
}

function page_foot(): void {
    $u = current_user();
    ?>
</main>
<?php if ($u) echo '</div>'; ?>
<?php
    if ($u) {
        $ann = db()->query('SELECT * FROM announcements ORDER BY id DESC LIMIT 1')->fetch();
        if ($ann):
?>
<div class="ann-scrim" id="annScrim" style="display:none">
  <div class="ann-dialog" role="dialog" aria-modal="true" aria-label="公告">
    <h2><?= h($ann['title']) ?></h2>
    <div class="ann-body"><?= nl2br(h($ann['content'])) ?></div>
    <p class="muted ann-time"><?= h($ann['created_at']) ?></p>
    <div class="ann-actions">
      <button class="md-btn text" id="annHide3d" data-id="<?= (int)$ann['id'] ?>">三天内不再弹出</button>
      <button class="md-btn filled" id="annClose">知道了</button>
    </div>
  </div>
</div>
<?php
        endif;
    }
?>
<script src="<?= url('assets/app.js') ?>"></script>
</body>
</html>
    <?php
}
