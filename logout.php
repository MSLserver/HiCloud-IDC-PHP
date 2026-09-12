<?php
require __DIR__ . '/inc.php';
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
