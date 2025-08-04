<?php
/**
 * AJAX endpoint to get values for a specific option set
 * Returns the available options for an option set (for use in skip logic option filtering)
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
    $optionSetId = $input['option_set_id'] ?? null;
    
    if (!$optionSetId) {
        echo json_encode(['success' => false, 'message' => 'Option set ID is required']);
        exit();
    }
    
    // Get option set values
    $stmt = $pdo->prepare("
        SELECT osv.option_value, os.name as option_set_name
        FROM option_set_values osv
        JOIN option_set os ON osv.option_set_id = os.id
        WHERE osv.option_set_id = ?
        ORDER BY osv.id
    ");
    $stmt->execute([$optionSetId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($results)) {
        echo json_encode(['success' => false, 'message' => 'No options found for this option set']);
        exit();
    }
    
    $options = [];
    $optionSetName = '';
    
    foreach ($results as $result) {
        $options[] = [
            'value' => $result['option_value'],
            'label' => $result['option_value']
        ];
        if (!$optionSetName) {
            $optionSetName = $result['option_set_name'];
        }
    }
    
    echo json_encode([
        'success' => true,
        'option_set_name' => $optionSetName,
        'options' => $options
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_option_set_values.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}
?>