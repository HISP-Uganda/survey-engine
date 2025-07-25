<?php
require_once './admin/connect.php';

$query = "SELECT * FROM owner"; // Fetch all ownership options
$stmt = $pdo->query($query);
$ownershipOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($ownershipOptions);
?>



