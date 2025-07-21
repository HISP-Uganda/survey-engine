<?php
require 'db.php';

$query = "SELECT * FROM service_unit"; // Fetch service units
$stmt = $pdo->query($query);
$serviceUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($serviceUnits);
?>