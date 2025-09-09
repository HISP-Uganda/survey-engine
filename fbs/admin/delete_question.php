<?php
session_start();

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

require_once 'connect.php';

// Helper function to check if current user can delete
function canUserDelete() {
    if (!isset($_SESSION['admin_role_name']) && !isset($_SESSION['admin_role_id'])) {
        return false;
    }
    
    // Super users can delete - check by role name or role ID
    $roleName = $_SESSION['admin_role_name'] ?? '';
    $roleId = $_SESSION['admin_role_id'] ?? 0;
    
    return ($roleName === 'super_user' || $roleName === 'admin' || $roleId == 1);
}

// Check if user has permission to delete
if (!canUserDelete()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to delete questions']);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['question_id'])) {
        throw new Exception('Missing question_id parameter');
    }
    
    $questionId = (int)$input['question_id'];
    
    if ($questionId <= 0) {
        throw new Exception('Invalid question ID');
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    // Check if question exists and get its details
    $stmt = $pdo->prepare("SELECT id, label FROM question WHERE id = ?");
    $stmt->execute([$questionId]);
    $question = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$question) {
        throw new Exception('Question not found');
    }
    
    // Check if question is being used in any surveys
    $stmt = $pdo->prepare("SELECT COUNT(*) as survey_count FROM survey_question WHERE question_id = ?");
    $stmt->execute([$questionId]);
    $surveyCount = $stmt->fetch(PDO::FETCH_ASSOC)['survey_count'];
    
    if ($surveyCount > 0) {
        throw new Exception('Cannot delete question that is used in ' . $surveyCount . ' survey(s)');
    }
    
    // Delete related records first (in order of dependencies)
    
    // Delete DHIS2 mappings
    $stmt = $pdo->prepare("DELETE FROM question_dhis2_mapping WHERE question_id = ?");
    $stmt->execute([$questionId]);
    
    // Delete any skip logic rules that reference this question
    $stmt = $pdo->prepare("
        UPDATE question 
        SET skip_logic = NULL 
        WHERE skip_logic IS NOT NULL 
        AND (
            JSON_SEARCH(skip_logic, 'one', ?, null, '$[*].conditions[*].question_id') IS NOT NULL
            OR JSON_SEARCH(skip_logic, 'one', CAST(? AS CHAR), null, '$[*].conditions[*].question_id') IS NOT NULL
        )
    ");
    $stmt->execute([$questionId, $questionId]);
    
    // Delete the question itself
    $stmt = $pdo->prepare("DELETE FROM question WHERE id = ?");
    $stmt->execute([$questionId]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Failed to delete question');
    }
    
    // Commit transaction
    $pdo->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Question "' . htmlspecialchars($question['label']) . '" deleted successfully'
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>