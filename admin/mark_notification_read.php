<?php

if (session_status() == PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id'])) exit;
include 'admin_demo_guard.php';

if (!guardDemoAdmin()) {
    $_SESSION['error'] = "Action désactivée en mode démo.";
    header('Location: dashboard.php');
    exit;
}

include '../includes/db.php';
$id = (int)$_GET['id'];
$pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?")->execute([$id]);
header('Location: dashboard.php');
exit;

?>