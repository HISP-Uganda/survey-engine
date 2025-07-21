<?php
session_start();

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
$servername = "localhost";
$username = "root";
$password = "root";
$dbname = "fbtv3";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $conn->connect_error]);
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
$deleteStmt = $conn->prepare("DELETE FROM survey_settings WHERE survey_id = ?");
if (!$deleteStmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare delete statement: ' . $conn->error]);
    exit();
}

$deleteStmt->bind_param("i", $surveyId);

if ($deleteStmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Survey settings reset to defaults.']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to reset settings: ' . $deleteStmt->error]);
}

$deleteStmt->close();
$conn->close();
?>