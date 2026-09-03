<?php
// Set value mode (disti | trade | msrp) and redirect back
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$mode = isset($_GET['mode']) ? trim($_GET['mode']) : '';
if (in_array($mode, SALESOUT_VALUE_MODES, true)) {
    $_SESSION['salesout_value_mode'] = $mode;
}

$back = isset($_GET['back']) ? $_GET['back'] : 'index.php';
// Restrict redirect to same path (no protocol/host)
if ($back === '' || strpos($back, '//') !== false) {
    $back = 'index.php';
}
$path = (strpos($back, '?') !== false) ? preg_replace('/\?.*/', '', $back) : $back;
$path = preg_replace('#^.*/#', '', $path);
if (!preg_match('/^[a-z0-9_\-\.]+\.php$/i', $path)) {
    $back = 'index.php';
}
header('Location: ' . $back);
exit;
