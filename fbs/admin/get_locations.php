<?php
// get_locations.php
require_once 'connect.php'; // Include your database connection

header('Content-Type: application/json');

if (!isset($pdo)) {
    // Return a JSON error message if PDO object is not available
    echo json_encode(['error' => 'Database connection failed. Please check connect.php.']);
    exit;
}

$instanceKey = $_GET['instance_key'] ?? '';
$hierarchyLevel = $_GET['hierarchylevel'] ?? ''; // This will be an integer or empty

try {
    $sql = "SELECT id, name, path, hierarchylevel, instance_key FROM location WHERE 1=1";
    $params = [];

    if (!empty($instanceKey)) {
        $sql .= " AND instance_key = ?";
        $params[] = $instanceKey;
    }

    // Ensure hierarchyLevel is a non-empty, numeric value before adding to query
    if (!empty($hierarchyLevel) && is_numeric($hierarchyLevel)) {
        $sql .= " AND hierarchylevel = ?";
        $params[] = (int)$hierarchyLevel; // Cast to int for safety
    }

    $sql .= " ORDER BY name ASC"; // Order by name for consistent display

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($locations);

} catch (PDOException $e) {
    error_log("Error fetching locations: " . $e->getMessage());
    echo json_encode(['error' => 'Error fetching locations from database.']);
} catch (Exception $e) { // Catch any other unexpected errors
    error_log("Unexpected error in get_locations.php: " . $e->getMessage());
    echo json_encode(['error' => 'An unexpected error occurred.']);
}
?>