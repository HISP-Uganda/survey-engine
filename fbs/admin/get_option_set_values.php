<?php
/**
 * AJAX endpoint to get values for a specific option set
 * Returns the available options for an option set (for use in skip logic option filtering)
 */

header('Content-Type: application/json');

session_start();

// For admin panel, require authentication and POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if admin is logged in for POST requests
    if (!isset($_SESSION['admin_logged_in'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not authorized']);
        exit();
    }
}

require_once 'connect.php';

try {
    // Get the input data - support both GET and POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $optionSetId = $input['option_set_id'] ?? null;
    } else {
        // GET request (for public tracker form)
        $optionSetId = $_GET['option_set_id'] ?? null;
    }
    
    if (!$optionSetId) {
        echo json_encode(['success' => false, 'message' => 'Option set ID is required']);
        exit();
    }
    
    // Debug logging
    error_log("get_option_set_values.php: Requesting options for option set ID: " . $optionSetId);
    
    // Try DHIS2 option set mapping first (for DHIS2 option sets with UID format)
    $stmt = $pdo->prepare("
        SELECT local_value as displayName, dhis2_option_code as code, dhis2_option_set_id
        FROM dhis2_option_set_mapping 
        WHERE dhis2_option_set_id = ? 
        ORDER BY local_value
    ");
    $stmt->execute([$optionSetId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no DHIS2 options found, try local option sets (for numeric IDs)
    if (empty($results) && is_numeric($optionSetId)) {
        error_log("get_option_set_values.php: No DHIS2 options found, trying local option set ID: " . $optionSetId);
        
        $stmt = $pdo->prepare("
            SELECT osv.option_value as displayName, osv.option_value as code, os.name as option_set_name
            FROM option_set_values osv
            JOIN option_set os ON osv.option_set_id = os.id
            WHERE osv.option_set_id = ?
            ORDER BY osv.id
        ");
        $stmt->execute([$optionSetId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if (empty($results)) {
        error_log("get_option_set_values.php: No options found for option set ID: " . $optionSetId);
        echo json_encode(['success' => false, 'message' => 'No options found for this option set']);
        exit();
    }
    
    error_log("get_option_set_values.php: Found " . count($results) . " options for option set ID: " . $optionSetId);
    
    $options = [];
    $optionSetName = 'Option Set';
    
    foreach ($results as $result) {
        $options[] = [
            'code' => $result['code'],
            'displayName' => $result['displayName'],
            'value' => $result['code'],
            'label' => $result['displayName']
        ];
        if (!$optionSetName) {
            $optionSetName = $result['dhis2_option_set_id'];
        }
    }
    
    // Return in the format expected by the JavaScript
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // For tracker form, return simple array
        echo json_encode($options);
    } else {
        // For admin panel, return full structure
        echo json_encode([
            'success' => true,
            'option_set_name' => $optionSetName,
            'options' => $options
        ]);
    }
    
} catch (Exception $e) {
    error_log("Error in get_option_set_values.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}
?>