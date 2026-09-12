<?php
require __DIR__ . '/inc.php';

const OAUTH_TYPES = ['qq', 'wx', 'alipay', 'sina', 'baidu', 'douyin', 'huawei', 'xiaomi', 'google', 'microsoft', 'dingtalk', 'gitee', 'github'];
const OAUTH_API_DEFAULT = 'https://open.juhedenglu.cn/connect.php';

function oauth_fail(string $msg): void {
    page_head('第三方登录');
    notice('', $msg);
    echo '<div class="card"><a class="md-btn tonal" href="login.php">返回登录</a></div>';
    page_foot();
    exit;
}

$apiBase = setting('oauth_api');
if ($apiBase === '') $apiBase = OAUTH_API_DEFAULT;
if (!oauth_configured()) oauth_fail('第三方登录未配置，请联系管理员');
$appid  = setting('oauth_appid');
$appkey = setting('oauth_appkey');

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$callback = $scheme . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');

if (!isset($_GET['code'])) {
    // Step1: 获取跳转登录地址
    $type = (string)($_GET['type'] ?? '');
    if (!in_array($type, OAUTH_TYPES, true)) oauth_fail('不支持的登录方式');
    if (!in_array($type, array_keys(oauth_enabled_channels()), true)) oauth_fail('该登录渠道未启用');
    $_SESSION['oauth_type'] = $type;
    $api = $apiBase . '?act=login&appid=' . urlencode($appid)
         . '&appkey=' . urlencode($appkey) . '&type=' . urlencode($type)
         . '&redirect_uri=' . urlencode($callback);
    $resp = json_decode(http_get($api), true);
    if (!is_array($resp) || (int)($resp['code'] ?? -1) !== 0) {
        oauth_fail('获取登录地址失败：' . (string)($resp['msg'] ?? '网络错误'));
    }
    $jump = (string)($resp['url'] ?? ($resp['qrcode'] ?? ''));
    if ($jump === '') oauth_fail('平台未返回登录地址');
    header('Location: ' . $jump);
    exit;
}

// Step3/4: 回调，用 code 换用户信息
$cbType = (string)($_GET['type'] ?? '');
$expect = (string)($_SESSION['oauth_type'] ?? '');
if ($expect === '' || $cbType !== $expect) oauth_fail('登录状态校验失败，请重新发起登录');
unset($_SESSION['oauth_type']);

$api = $apiBase . '?act=callback&appid=' . urlencode($appid)
     . '&appkey=' . urlencode($appkey) . '&type=' . urlencode($cbType)
     . '&code=' . urlencode((string)$_GET['code']);
$info = json_decode(http_get($api), true);
if (!is_array($info) || (int)($info['code'] ?? -1) !== 0) {
    $m = (string)($info['msg'] ?? '未知错误');
    if ((int)($info['code'] ?? 0) === 2) $m = '登录未完成，请重试';
    oauth_fail('第三方登录失败：' . $m);
}

$suid = (string)$info['social_uid'];
$nick = (string)($info['nickname'] ?? '');
$face = (string)($info['faceimg'] ?? '');

$st = db()->prepare('SELECT user_id FROM social_accounts WHERE type = ? AND social_uid = ?');
$st->execute([$cbType, $suid]);
$boundUid = $st->fetchColumn();
$me = current_user();

if ($boundUid) {
    $loginId = (int)$boundUid;
} elseif ($me) {
    // 已登录用户：绑定到当前账号
    db()->prepare('INSERT INTO social_accounts(user_id, type, social_uid, nickname, faceimg, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$me['id'], $cbType, $suid, $nick, $face, now()]);
    $loginId = (int)$me['id'];
} else {
    // 新用户：自动创建账号并绑定
    $base = substr(preg_replace('/[^a-z0-9_]/', '', strtolower($cbType . '_' . $suid)), 0, 16);
    if (strlen($base) < 3) $base = 'user_' . $base;
    $username = $base;
    $i = 0;
    $st = db()->prepare('SELECT id FROM users WHERE username = ?');
    while (true) {
        $st->execute([$username]);
        if (!$st->fetch()) break;
        $username = substr($base, 0, 16) . ++$i;
    }
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO users(username, password, pwd_set, created_at) VALUES (?, ?, 0, ?)')
        ->execute([$username, password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT), now()]);
    $loginId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO social_accounts(user_id, type, social_uid, nickname, faceimg, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$loginId, $cbType, $suid, $nick, $face, now()]);
    $pdo->commit();
}

session_regenerate_id(true);
$_SESSION['uid'] = $loginId;
header('Location: index.php');
exit;
