<?php
// get_location_path.php
require_once 'connect.php'; // Include your database connection

header('Content-Type: application/json');

if (!isset($pdo)) {
    echo json_encode(['error' => 'Database connection failed. Please check connect.php.']);
    exit;
}

$locationId = $_GET['id'] ?? null;

if (!$locationId) {
    echo json_encode(['error' => 'Location ID is missing.']);
    exit;
}

try {
    // Function to recursively get parent names and build the full path
    function getFullPathNames($pdo, $currentId) {
        $pathNames = [];
        $idsToProcess = [$currentId]; // Start with the requested ID

        // Map to store ID => name and ID => parent_id for efficient lookup
        $locationsMap = [];

        // Fetch all relevant locations in one go if possible, or fetch parents iteratively
        // For simplicity and to avoid too many small queries, let's fetch parents iteratively
        // and build up the path
        while (!empty($idsToProcess)) {
            $id = array_shift($idsToProcess); // Get the current ID to process

            // Check if we already fetched this location to avoid infinite loops if data is bad
            if (isset($locationsMap[$id])) {
                continue;
            }

            $stmt = $pdo->prepare("SELECT id, name, parent_id FROM location WHERE id = ?");
            $stmt->execute([$id]);
            $location = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($location) {
                $locationsMap[$location['id']] = $location; // Store location details

                // Prepend the name to the pathNames array
                array_unshift($pathNames, $location['name']);

                // If it has a parent, add the parent to the front of idsToProcess to process next
                if ($location['parent_id'] !== null && $location['parent_id'] != 0 && !isset($locationsMap[$location['parent_id']])) {
                    array_unshift($idsToProcess, $location['parent_id']);
                }
            } else {
                // If a location in the path is not found, stop building the path
                break;
            }
        }
        return implode(' → ', $pathNames);
    }

    $fullPath = getFullPathNames($pdo, $locationId);

    echo json_encode(['success' => true, 'path' => $fullPath]);

} catch (PDOException $e) {
    error_log("Error fetching location full path: " . $e->getMessage());
    echo json_encode(['error' => 'Error fetching location path from database.']);
} catch (Exception $e) {
    error_log("Unexpected error in get_location_path.php: " . $e->getMessage());
    echo json_encode(['error' => 'An unexpected error occurred while generating path.']);
}
?>