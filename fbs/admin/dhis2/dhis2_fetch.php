<?php
require_once 'dhis2_shared.php';

header('Content-Type: application/json');

try {
    // Validate required parameters
    if (!isset($_GET['instance']) || !isset($_GET['endpoint'])) {
        throw new Exception('Missing required parameters: instance and endpoint');
    }

    $instance = $_GET['instance'];
    $endpoint = $_GET['endpoint'];
    
    // Get the full URL from configuration
    $config = getDhis2Config($instance);
    if (!$config || !isset($config['url'])) {
        throw new Exception('Invalid DHIS2 instance configuration');
    }
    
    // Construct the full URL (ensure proper API path)
    $apiBase = rtrim($config['url'], '/') . '/api/';
    $fullUrl = $apiBase . ltrim($endpoint, '/');
    
    // Log the request for debugging
    error_log("DHIS2 API Request: $fullUrl");
    
    // Make the API call
    $response = dhis2_get($fullUrl, $instance);
    
    if ($response === null) {
        throw new Exception('Failed to fetch data from DHIS2');
    }
    
    // Return the response
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'instance' => $_GET['instance'] ?? null,
        'endpoint' => $_GET['endpoint'] ?? null
    ]);
}