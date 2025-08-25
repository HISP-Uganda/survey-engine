<?php
/**
 * AJAX endpoint to get options for a specific question
 * Returns the available values for a question (for use in skip logic trigger values)
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
    $questionId = $input['question_id'] ?? null;
    
    if (!$questionId) {
        echo json_encode(['success' => false, 'message' => 'Question ID is required']);
        exit();
    }
    
    // Get question details
    $stmt = $pdo->prepare("
        SELECT q.id, q.label, q.question_type, q.option_set_id
        FROM question q
        WHERE q.id = ?
    ");
    $stmt->execute([$questionId]);
    $question = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$question) {
        echo json_encode(['success' => false, 'message' => 'Question not found']);
        exit();
    }
    
    $options = [];
    
    // Check if question has an option set (select, radio, checkbox)
    if ($question['option_set_id']) {
        $stmt = $pdo->prepare("
            SELECT option_value 
            FROM option_set_values 
            WHERE option_set_id = ? 
            ORDER BY id
        ");
        $stmt->execute([$question['option_set_id']]);
        $optionValues = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($optionValues as $value) {
            $options[] = [
                'value' => $value,
                'label' => $value
            ];
        }
    }
    
    // If no predefined options, provide common options based on question type
    if (empty($options)) {
        switch ($question['question_type']) {
            case 'text':
            case 'textarea':
            case 'email':
            case 'phone':
            case 'url':
                $options = [
                    ['value' => 'is_not_empty', 'label' => 'Has any value'],
                    ['value' => 'is_empty', 'label' => 'Is empty/not answered']
                ];
                break;
                
            case 'number':
            case 'integer':
            case 'decimal':
            case 'percentage':
                $options = [
                    ['value' => 'is_not_empty', 'label' => 'Has any value'],
                    ['value' => 'is_empty', 'label' => 'Is empty/not answered'],
                    ['value' => 'greater_than_0', 'label' => 'Greater than 0'],
                    ['value' => 'equals_0', 'label' => 'Equals 0']
                ];
                break;
                
            case 'date':
            case 'datetime':
            case 'time':
                $options = [
                    ['value' => 'is_not_empty', 'label' => 'Has any date'],
                    ['value' => 'is_empty', 'label' => 'No date selected']
                ];
                break;
                
            case 'file_upload':
                $options = [
                    ['value' => 'has_file', 'label' => 'File uploaded'],
                    ['value' => 'no_file', 'label' => 'No file uploaded']
                ];
                break;
                
            case 'rating':
            case 'likert_scale':
            case 'star_rating':
                // Generate rating options (1-5 by default)
                for ($i = 1; $i <= 5; $i++) {
                    $options[] = [
                        'value' => (string)$i,
                        'label' => "Rating: $i"
                    ];
                }
                $options[] = ['value' => 'is_empty', 'label' => 'No rating given'];
                break;
                
            default:
                $options = [
                    ['value' => 'is_not_empty', 'label' => 'Has any value'],
                    ['value' => 'is_empty', 'label' => 'Is empty/not answered']
                ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'question' => [
            'id' => $question['id'],
            'label' => $question['label'],
            'type' => $question['question_type']
        ],
        'options' => $options
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_question_options.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}
?>