<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'dhis2_shared.php';



// Test configuration loading
echo "<h3>Testing DHIS2 Configuration</h3>";
$config = getDhis2Config('UiO');
echo "Config loaded: " . ($config ? "YES" : "NO") . "<br>";
if (!$config) {
    echo "Config file path: " . realpath("dhis2/dhis2.json") . "<br>";
    
    // Try to read the file directly
    $jsonContent = file_get_contents("dhis2.json");
    echo "File content: " . ($jsonContent ? substr($jsonContent, 0, 30) . "..." : "FAILED TO READ") . "<br>";
}


// Test basic cURL functionality 
echo "<h3>Testing Basic cURL</h3>";
$ch = curl_init("https://www.google.com");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$error = curl_error($ch);
echo "Basic cURL test: " . ($response ? "SUCCESS" : "FAILED") . "<br>";
if ($error) {
    echo "cURL Error: " . $error . "<br>";
}
curl_close($ch);

// Test DHIS2 connection if config is available
if ($config) {
    echo "<h3>Testing DHIS2 Connection</h3>";
    $url = rtrim($config['url'], '/') . '/api/me';
    echo "Testing URL: $url<br>";
    
    // Get authentication string
    $authString = dhis2auth('UiO');
    echo "Auth string generated: " . ($authString ? "YES" : "NO") . "<br>";
    
    // Try the connection
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . $authString,
        'Accept: application/json',
    ]);
    
    // Disable SSL verification temporarily for testing
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    // Enable verbose output
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    $verbose = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_STDERR, $verbose);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    echo "HTTP Status Code: $httpCode<br>";
    echo "Response: " . ($response ? substr($response, 0, 100) . "..." : "EMPTY") . "<br>";
    
    if ($error) {
        echo "cURL Error: $error<br>";
    }
    
    // Output verbose information
    rewind($verbose);
    $verboseLog = stream_get_contents($verbose);
    echo "<pre>Verbose log: " . htmlspecialchars($verboseLog) . "</pre>";
    
    curl_close($ch);
}
?>