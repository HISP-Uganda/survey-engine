<?php
session_start();
require 'connect.php';

// Set content type to JSON for consistent API response
header('Content-Type: application/json');

// Check if the user is logged in as admin
if (!isset($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please log in.']);
    exit();
}

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// Get the submission ID from the POST data
$submissionId = $_POST['id'] ?? null;

if (!$submissionId) {
    echo json_encode(['success' => false, 'message' => 'Submission ID is required.']);
    exit();
}

try {
    // Start a transaction for atomicity
    $pdo->beginTransaction();

    // First, delete related records from 'submission_response' table
    // Assuming 'submission_response' has a foreign key constraint referencing 'submission'
    $stmtResponses = $pdo->prepare("DELETE FROM submission_response WHERE submission_id = :submission_id");
    $stmtResponses->execute(['submission_id' => $submissionId]);

    // Then, delete the submission from the 'submission' table
    $stmtSubmission = $pdo->prepare("DELETE FROM submission WHERE id = :submission_id");
    $stmtSubmission->execute(['submission_id' => $submissionId]);

    // Commit the transaction
    $pdo->commit();

    if ($stmtSubmission->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Submission and related responses deleted successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Submission not found or already deleted.']);
    }

} catch (PDOException $e) {
    // Rollback the transaction on error
    $pdo->rollBack();
    error_log("Database error deleting submission: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>