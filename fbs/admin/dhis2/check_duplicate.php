<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

require_once '../connect.php';
require_once 'dhis2_shared.php';

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || !isset($data['attribute_id']) || !isset($data['value']) || !isset($data['survey_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameters']);
        exit();
    }
    
    $attributeId = trim($data['attribute_id']);
    $value = trim($data['value']);
    $surveyId = (int)$data['survey_id'];
    
    if (empty($value)) {
        echo json_encode(['duplicate' => false]);
        exit();
    }
    
    // Get survey configuration to determine DHIS2 instance
    $stmt = $pdo->prepare("SELECT dhis2_instance FROM survey WHERE id = ?");
    $stmt->execute([$surveyId]);
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$survey) {
        http_response_code(404);
        echo json_encode(['error' => 'Survey not found']);
        exit();
    }
    
    $instance = $survey['dhis2_instance'];
    
    if (empty($instance)) {
        // If no DHIS2 instance configured, skip duplicate check
        echo json_encode(['duplicate' => false]);
        exit();
    }
    
    // Search for existing tracked entities with this attribute value
    $query = urlencode("attribute:{$attributeId}:EQ:{$value}");
    $endpoint = "trackedEntityInstances.json?query={$query}&fields=trackedEntityInstance,attributes[attribute,value]&pageSize=1";
    
    error_log("Checking for duplicates: {$endpoint}");
    
    $response = dhis2_get($endpoint, $instance);
    
    if ($response === null) {
        // API call failed, but don't block the user
        error_log("Failed to check duplicates - DHIS2 API call returned null");
        echo json_encode(['duplicate' => false]);
        exit();
    }
    
    // Check if any tracked entities were found
    if (isset($response['trackedEntityInstances']) && !empty($response['trackedEntityInstances'])) {
        $count = count($response['trackedEntityInstances']);
        error_log("Found {$count} existing tracked entities with attribute {$attributeId} = {$value}");
        
        // Generate user-friendly message based on attribute type
        $message = generateDuplicateMessage($attributeId, $value);
        
        echo json_encode([
            'duplicate' => true,
            'count' => $count,
            'message' => $message
        ]);
    } else {
        error_log("No duplicates found for attribute {$attributeId} = {$value}");
        echo json_encode(['duplicate' => false]);
    }
    
} catch (Exception $e) {
    error_log("Error checking for duplicates: " . $e->getMessage());
    // Don't block the user if there's an error
    echo json_encode(['duplicate' => false, 'error' => 'Unable to check for duplicates']);
}

/**
 * Generate a user-friendly duplicate message based on attribute type
 */
function generateDuplicateMessage($attributeId, $value) {
    // Common attribute patterns
    $phonePatterns = ['phone', 'mobile', 'tel', 'contact'];
    $emailPatterns = ['email', 'mail'];
    $idPatterns = ['id', 'number', 'code'];
    
    $lowerAttr = strtolower($attributeId);
    
    foreach ($phonePatterns as $pattern) {
        if (strpos($lowerAttr, $pattern) !== false || 
            preg_match('/^\+?[\d\s\-\(\)]+$/', $value)) {
            return "This phone number ({$value}) is already registered in the system.";
        }
    }
    
    foreach ($emailPatterns as $pattern) {
        if (strpos($lowerAttr, $pattern) !== false || 
            filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return "This email address ({$value}) is already registered in the system.";
        }
    }
    
    foreach ($idPatterns as $pattern) {
        if (strpos($lowerAttr, $pattern) !== false) {
            return "This ID number ({$value}) is already registered in the system.";
        }
    }
    
    // Generic message
    return "This value ({$value}) is already registered in the system.";
}
?>