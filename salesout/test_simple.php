<?php
// Minimal test - does just header work?
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once __DIR__ . '/config.php';
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
require_once __DIR__ . '/header.php';
?>
<div class="container-xl py-4"><h1>Test OK</h1><p>If you see this, header works.</p></div>
</div></div></body></html>
