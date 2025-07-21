<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

require 'connect.php';

$id = $_GET['id'] ?? null;

if (!$id) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Submission ID is required']);
    exit();
}

try {
    // Start transaction
    $pdo->beginTransaction();
    
    // First delete related records from submission_response
    $stmt = $pdo->prepare("DELETE FROM submission_response WHERE submission_id = :id");
    $stmt->execute(['id' => $id]);
    
    // Then delete the submission
    $stmt = $pdo->prepare("DELETE FROM submission WHERE id = :id");
    $stmt->execute(['id' => $id]);
    
    // Commit transaction
    $pdo->commit();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Submission deleted successfully']);
} catch (PDOException $e) {
    // Rollback transaction on error
    $pdo->rollBack();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>