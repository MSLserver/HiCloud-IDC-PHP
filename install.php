<?php
declare(strict_types=1);
// 安装向导：独立于 inc.php，仅在未安装时可访问
session_start();

$base = __DIR__;
$cfgFile = $base . '/config.php';
$lockFile = $base . '/data/installed.lock';
// 安装完成后（锁文件）禁止再进入；历史版本无锁文件时以数据库文件判断，但安装流程进行中（有会话暂存）不拦截
$installed = file_exists($lockFile)
    || (file_exists($base . '/data.sqlite') && empty($_SESSION['install']));
if ($installed) {
    header('Location: index.php');
    exit;
}

function json_out(array $d): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = (string)($_POST['step'] ?? '');

    if ($step === 'validate') {
        // 第一步：校验输入 + 测试数据库连接，结果暂存会话
        $type      = ($_POST['db_type'] ?? 'mysql') === 'sqlite' ? 'sqlite' : 'mysql';
        $site      = trim((string)($_POST['site_name'] ?? '')) ?: 'HiCloud IDC';
        $adminUser = trim((string)($_POST['admin_user'] ?? ''));
        $adminPass = (string)($_POST['admin_pass'] ?? '');
        $cfg = ['db_type' => 'sqlite', 'db_path' => $base . '/data.sqlite'];

        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $adminUser)) {
            json_out(['ok' => false, 'msg' => '管理员用户名需为 3-20 位字母、数字或下划线']);
        }
        if (strlen($adminPass) < 6) {
            json_out(['ok' => false, 'msg' => '管理员密码至少 6 位']);
        }
        if ($type === 'mysql') {
            $host = trim((string)($_POST['db_host'] ?? ''));
            $port = (int)($_POST['db_port'] ?? 3306);
            $name = trim((string)($_POST['db_name'] ?? ''));
            $user = trim((string)($_POST['db_user'] ?? ''));
            $pass = (string)($_POST['db_pass'] ?? '');
            if ($host === '' || $name === '' || $user === '' || $port < 1) {
                json_out(['ok' => false, 'msg' => '请完整填写 MySQL 数据库信息']);
            }
            try {
                $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
                $tmp = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, $opts);
                $tmp->exec("CREATE DATABASE IF NOT EXISTS `$name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
                $tmp = null;
                new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, $opts);
                $cfg = [
                    'db_type' => 'mysql',
                    'db_host' => $host,
                    'db_port' => $port,
                    'db_name' => $name,
                    'db_user' => $user,
                    'db_pass' => $pass,
                ];
            } catch (Throwable $e) {
                json_out(['ok' => false, 'msg' => '数据库连接失败：' . $e->getMessage()]);
            }
            $msg = "MySQL 连接成功，数据库 `$name` 就绪";
        } else {
            $msg = '使用 SQLite，无需连接测试';
        }
        $_SESSION['install'] = ['cfg' => $cfg, 'site' => $site, 'user' => $adminUser, 'pass' => $adminPass];
        json_out(['ok' => true, 'msg' => $msg]);
    }

    if ($step === 'config') {
        // 第二步：写入配置文件
        $inst = $_SESSION['install'] ?? null;
        if (!$inst) json_out(['ok' => false, 'msg' => '请先完成配置校验']);
        if (!is_dir($base . '/data')) {
            mkdir($base . '/data', 0755, true);
        }
        $written = file_put_contents($cfgFile, "<?php\nreturn " . var_export($inst['cfg'], true) . ";\n");
        if ($written === false) json_out(['ok' => false, 'msg' => '无法写入 config.php，请检查目录写入权限']);
        json_out(['ok' => true, 'msg' => 'config.php 写入成功']);
    }

    if ($step === 'install') {
        // 第三步：建表 + 站点设置 + 创建管理员 + 安装锁定
        $inst = $_SESSION['install'] ?? null;
        if (!$inst) json_out(['ok' => false, 'msg' => '请先完成配置校验']);
        if (!file_exists($cfgFile)) json_out(['ok' => false, 'msg' => '配置文件不存在']);
        try {
            define('INSTALLING', true);
            require $base . '/inc.php';
            db();
            set_setting('site_name', $inst['site']);
            db()->prepare("INSERT INTO users(username, password, role, created_at) VALUES (?, ?, 'admin', ?)")
                ->execute([$inst['user'], password_hash($inst['pass'], PASSWORD_DEFAULT), now()]);
            file_put_contents($lockFile, now());
        } catch (Throwable $e) {
            json_out(['ok' => false, 'msg' => '初始化失败：' . $e->getMessage()]);
        }
        unset($_SESSION['install']);
        json_out(['ok' => true, 'msg' => '数据表初始化完成，管理员账号已创建', 'redirect' => 'login.php']);
    }

    json_out(['ok' => false, 'msg' => '未知安装步骤']);
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装向导 · HiCloud IDC</title>
<script>(function(){var m='system';try{m=localStorage.getItem('theme')||'system'}catch(e){}
var d=m==='dark'||(m==='system'&&matchMedia('(prefers-color-scheme: dark)').matches);
document.documentElement.dataset.theme=d?'dark':'light';})();</script>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="container">
<div class="card" style="max-width:560px;margin:48px auto">
  <h1>安装向导</h1>
  <p class="muted">欢迎部署 HiCloud IDC 控制台，请完成以下初始配置</p>
  <form id="installForm" class="stack" onsubmit="return false">
    <label class="md-field">
      <input name="site_name" required placeholder=" " value="HiCloud IDC">
      <span>站点名称</span>
    </label>
    <p class="muted" style="margin:0">数据库类型</p>
    <select name="db_type" id="dbType" class="md-select">
      <option value="mysql" selected>MySQL（虚拟主机推荐）</option>
      <option value="sqlite">SQLite（免配置）</option>
    </select>
    <div id="mysqlFields" class="stack" style="display:none">
      <div class="inline">
        <label class="md-field small" style="flex:2">
          <input name="db_host" placeholder=" " value="127.0.0.1">
          <span>数据库地址</span>
        </label>
        <label class="md-field small" style="flex:1">
          <input type="number" name="db_port" min="1" placeholder=" " value="3306">
          <span>端口</span>
        </label>
      </div>
      <label class="md-field">
        <input name="db_name" placeholder=" ">
        <span>数据库名（不存在将自动创建）</span>
      </label>
      <label class="md-field">
        <input name="db_user" placeholder=" " autocomplete="off">
        <span>数据库用户名</span>
      </label>
      <label class="md-field">
        <input type="password" name="db_pass" placeholder=" " autocomplete="new-password">
        <span>数据库密码</span>
      </label>
    </div>
    <p class="muted" style="margin:0">管理员账号</p>
    <label class="md-field">
      <input name="admin_user" required placeholder=" " autocomplete="off" value="admin">
      <span>管理员用户名</span>
    </label>
    <label class="md-field">
      <input type="password" name="admin_pass" required placeholder=" " autocomplete="new-password">
      <span>管理员密码（至少 6 位）</span>
    </label>
    <div><button type="submit" class="md-btn filled" id="installBtn">开始安装</button></div>
  </form>

  <div class="md-progress" id="prog"><div id="progBar"></div></div>
  <div class="install-log" id="log"></div>
  <div id="doneBox" style="display:none;margin-top:16px">
    <a class="md-btn filled" href="login.php">立即体验</a>
  </div>
</div>
</main>
<script src="assets/app.js"></script>
<script>
(function () {
  var sel = document.getElementById('dbType');
  var box = document.getElementById('mysqlFields');
  function toggle() { box.style.display = sel.value === 'mysql' ? 'flex' : 'none'; }
  sel.addEventListener('change', toggle);
  toggle();

  var form = document.getElementById('installForm');
  var btn = document.getElementById('installBtn');
  var prog = document.getElementById('prog');
  var progBar = document.getElementById('progBar');
  var logBox = document.getElementById('log');
  var doneBox = document.getElementById('doneBox');

  function log(text, cls) {
    var line = document.createElement('div');
    if (cls) line.className = cls;
    line.textContent = text;
    logBox.appendChild(line);
    logBox.scrollTop = logBox.scrollHeight;
  }
  function setProgress(pct) {
    progBar.style.width = pct + '%';
  }

  var steps = [
    { step: 'validate', pct: 35, doing: '正在校验配置并测试数据库连接…' },
    { step: 'config',   pct: 70, doing: '正在写入配置文件 config.php…' },
    { step: 'install',  pct: 100, doing: '正在初始化数据表与管理员账号…' }
  ];

  form.addEventListener('submit', async function () {
    btn.disabled = true;
    doneBox.style.display = 'none';
    prog.style.display = 'block';
    logBox.style.display = 'block';
    logBox.innerHTML = '';
    setProgress(5);
    try {
      for (var i = 0; i < steps.length; i++) {
        var s = steps[i];
        log('[步骤 ' + (i + 1) + '/' + steps.length + '] ' + s.doing);
        var fd = new FormData(form);
        fd.append('step', s.step);
        var resp = await fetch('install.php', { method: 'POST', body: fd });
        var data = await resp.json();
        if (!data.ok) {
          log('✕ ' + data.msg, 'err');
          log('安装已中止，请修正后重试。');
          btn.disabled = false;
          return;
        }
        log('✓ ' + data.msg, 'ok');
        setProgress(s.pct);
      }
      log('部署完成！点击下方按钮进入登录页。', 'ok');
      doneBox.style.display = 'block';
    } catch (e) {
      log('✕ 网络错误：' + e.message, 'err');
      btn.disabled = false;
    }
  });
})();
</script>
</body>
</html>
