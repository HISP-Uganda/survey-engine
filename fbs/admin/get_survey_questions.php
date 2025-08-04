<?php
/**
 * AJAX endpoint to get questions from selected surveys
 * Returns questions that can be used as triggers in skip logic
 */

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

require_once 'connect.php';

try {
    // Get the input data
    $input = json_decode(file_get_contents('php://input'), true);
    $surveyIds = $input['survey_ids'] ?? [];
    $excludeQuestionId = $input['exclude_question_id'] ?? null;
    
    if (empty($surveyIds) || !is_array($surveyIds)) {
        echo json_encode(['success' => false, 'message' => 'Survey IDs are required']);
        exit();
    }
    
    // Sanitize survey IDs
    $surveyIds = array_map('intval', $surveyIds);
    $placeholders = str_repeat('?,', count($surveyIds) - 1) . '?';
    
    // Build the query
    $sql = "
        SELECT q.id, q.label, q.question_type, MIN(sq.position) as min_position
        FROM question q
        JOIN survey_question sq ON q.id = sq.question_id
        WHERE sq.survey_id IN ($placeholders)
    ";
    
    $params = $surveyIds;
    
    // Exclude current question if provided
    if ($excludeQuestionId) {
        $sql .= " AND q.id != ?";
        $params[] = intval($excludeQuestionId);
    }
    
    $sql .= "
        GROUP BY q.id, q.label, q.question_type
        ORDER BY min_position, q.label
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the questions for the frontend
    $formattedQuestions = [];
    foreach ($questions as $question) {
        $formattedQuestions[] = [
            'id' => (int)$question['id'],
            'label' => $question['label'],
            'question_type' => $question['question_type']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'questions' => $formattedQuestions,
        'count' => count($formattedQuestions)
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_survey_questions.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}
?>