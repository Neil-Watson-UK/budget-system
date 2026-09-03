<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

$att_id = (int)($_GET['id'] ?? 0);
$return_id = (int)($_GET['return_id'] ?? 0);
$return_edit = !empty($_GET['return_edit']);

if (!$att_id) {
    header('Location: index.php?error=Invalid attachment');
    exit;
}

$pdo = getDBConnection();
deleteBudgetItemAttachment($pdo, $att_id);

$redirect = $return_id
    ? ($return_edit ? "edit_item.php?id=$return_id" : "add_item.php?id=$return_id")
    : "index.php";
$redirect .= "&success=" . urlencode('Attachment removed');
header("Location: $redirect");
exit;
