<?php
$host = "localhost"; // Change if needed
$dbname = "fbtv3"; // Replace with your DB name
$username = "root"; // Replace with your DB username
$password = "root"; // Replace with your DB password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
