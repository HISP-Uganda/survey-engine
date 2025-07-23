<?php
session_start();

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include the centralized database connection file
// Since it's in the same directory, a direct filename is sufficient.
require_once 'connect.php';

// Check if the PDO object is available from connect.php
if (!isset($pdo)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed: Central PDO object not found.']);
    exit();
}

// Ensure it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST is allowed.']);
    exit();
}

// Get survey_id from the POST data
$surveyId = $_POST['survey_id'] ?? null;

if (!$surveyId || !is_numeric($surveyId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid Survey ID.']);
    exit();
}

// Delete the existing settings for this survey
try {
    $deleteStmt = $pdo->prepare("DELETE FROM survey_settings WHERE survey_id = ?");
    if (!$deleteStmt) {
        // This indicates a problem with the SQL query itself or PDO setup
        error_log("Failed to prepare delete statement for survey_settings: " . json_encode($pdo->errorInfo()));
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Internal server error: Could not prepare database operation.']);
        exit();
    }

    if ($deleteStmt->execute([(int)$surveyId])) { // Cast to int explicitly for clarity, though PDO often handles it
        echo json_encode(['success' => true, 'message' => 'Survey settings reset to defaults.']);
    } else {
        // This indicates an issue during execution (e.g., foreign key constraint)
        error_log("Failed to execute delete statement for survey_settings: " . json_encode($deleteStmt->errorInfo()));
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to reset settings due to a database error.']);
    }
} catch (PDOException $e) {
    // Catch any PDO-specific exceptions (e.g., connection issues during prepare/execute)
    error_log("PDOException during survey settings reset: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error during settings reset.']);
}

// PDO connections automatically close when the script finishes.
// No explicit $deleteStmt->close() or $pdo->close() needed here.

exit(); // Ensure no further code is executed after JSON response
?>