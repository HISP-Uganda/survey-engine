<?php
// Start output buffering to catch any stray output
ob_start();

// Suppress errors that might interfere with JSON response
error_reporting(E_ERROR | E_PARSE);

// Set content type to JSON for consistent API response
header('Content-Type: application/json');

session_start();
require 'connect.php';
require_once 'includes/session_timeout.php';

// Clear any output that might have been generated
ob_clean();

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

    // If deletion was successful, reset auto-increment values
    if ($stmtSubmission->rowCount() > 0) {
        // Reset auto-increment for submission table
        $maxSubmissionIdStmt = $pdo->query("SELECT MAX(id) as max_id FROM submission");
        $maxSubmissionId = $maxSubmissionIdStmt->fetchColumn();
        $nextSubmissionAutoIncrement = $maxSubmissionId ? $maxSubmissionId + 1 : 1;
        $pdo->exec("ALTER TABLE submission AUTO_INCREMENT = $nextSubmissionAutoIncrement");
        
        // Reset auto-increment for submission_response table
        $maxResponseIdStmt = $pdo->query("SELECT MAX(id) as max_id FROM submission_response");
        $maxResponseId = $maxResponseIdStmt->fetchColumn();
        $nextResponseAutoIncrement = $maxResponseId ? $maxResponseId + 1 : 1;
        $pdo->exec("ALTER TABLE submission_response AUTO_INCREMENT = $nextResponseAutoIncrement");
        
        // Commit the transaction
        $pdo->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Submission deleted and auto-increment values optimized!',
            'next_submission_id' => $nextSubmissionAutoIncrement,
            'next_response_id' => $nextResponseAutoIncrement
        ]);
    } else {
        $pdo->commit();
        echo json_encode(['success' => false, 'message' => 'Submission not found or already deleted.']);
    }

} catch (PDOException $e) {
    // Rollback the transaction on error
    $pdo->rollBack();
    error_log("Database error deleting submission: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>