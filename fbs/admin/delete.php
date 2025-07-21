<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require 'connect.php';

if (!isset($_GET['id'])) {
    header("Location: router.php");
    exit();
}

$id = $_GET['id'];

// Delete the record
$stmt = $pdo->prepare("DELETE FROM response_form WHERE id = ?");
$stmt->execute([$id]);

header("Location: router.php");
exit();
?>