<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

/**
 * Test DHIS2 connection directly using provided credentials
 */
function testDhis2Connection($url, $username, $password) {
    // Clean the URL
    $url = rtrim($url, '/');
    
    // Build the system info endpoint URL
    if (strpos($url, '/api/') !== false) {
        $apiUrl = $url . '/system/info';
    } else {
        $apiUrl = $url . '/api/system/info';
    }
    
    // Initialize cURL
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $username . ':' . $password,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        throw new Exception("Connection failed: $curlError");
    }
    
    if ($httpCode === 401) {
        throw new Exception('Authentication failed. Please check username and password.');
    }
    
    if ($httpCode === 404) {
        throw new Exception('DHIS2 API endpoint not found. Please check the server URL.');
    }
    
    if ($httpCode !== 200) {
        throw new Exception("HTTP Error $httpCode. Please check server URL and network connectivity.");
    }
    
    $data = json_decode($response, true);
    if (!$data) {
        throw new Exception('Invalid response from DHIS2 server.');
    }
    
    return $data;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['url']) || !isset($input['username']) || !isset($input['password'])) {
        throw new Exception('URL, username, and password are required');
    }
    
    $url = trim($input['url']);
    $username = trim($input['username']);
    $password = $input['password']; // Password might be base64 encoded
    
    // If password looks like base64, decode it
    if (base64_encode(base64_decode($password, true)) === $password) {
        $password = base64_decode($password);
    }
    
    // Test the connection
    $response = testDhis2Connection($url, $username, $password);
    
    // Successful connection
    $serverInfo = '';
    if (isset($response['version'])) {
        $serverInfo = " (DHIS2 v{$response['version']})";
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Connection successful! DHIS2 server is reachable' . $serverInfo,
        'data' => [
            'version' => $response['version'] ?? 'Unknown',
            'serverDate' => $response['serverDate'] ?? null,
            'systemName' => $response['systemName'] ?? 'DHIS2'
        ]
    ]);
    
} catch (Exception $e) {
    error_log("DHIS2 connection test failed: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>