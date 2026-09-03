<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('HTTP/1.1 404 Not Found');
    exit;
}

$pdo = getDBConnection();
$stmt = $pdo->prepare("SELECT * FROM budget_item_attachments WHERE id = ?");
$stmt->execute([$id]);
$att = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$att) {
    header('HTTP/1.1 404 Not Found');
    exit;
}

$path = getBudgetAttachmentPath($att);
if (!file_exists($path)) {
    header('HTTP/1.1 404 Not Found');
    exit;
}

$mime = $att['mime_type'] ?: 'application/octet-stream';
$name = $att['file_name'];

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
