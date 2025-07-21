<?php
// Enable full error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

function getDhis2Config(string $key): ?array {
    $jsonFilePath = "dhis2.json";  
    if (!file_exists($jsonFilePath)) {
        error_log("DHIS2 config file not found at: " . realpath($jsonFilePath));
        return null;
    }

    $content = file_get_contents($jsonFilePath);
    if ($content === false) {
        error_log("Failed to read DHIS2 config file");
        return null;
    }

    $configs = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Invalid JSON in DHIS2 config: " . json_last_error_msg());
        return null;
    }

    return $configs[$key] ?? null;
}

function dhis2auth($instance) {
    $config = getDhis2Config($instance);
    if (!$config) {
        error_log("Config not found for instance: " . $instance);
        return null;
    }
    $authString = base64_encode($config['username'] . ':' . $config['password']);
    error_log("Generated Auth Header: Basic " . $authString);
    return $authString;
}

function dhis2_get($endpoint, $instance) {
    $config = getDhis2Config($instance);
    if (!$config) {
        error_log("No config found for instance: " . $instance);
        return null;
    }

    // Construct the full URL
    $fullUrl = rtrim($config['url'], '/') . '/' . ltrim($endpoint, '/');
    error_log("Attempting to call DHIS2 API at: " . $fullUrl);
    
    $ch = curl_init($fullUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . dhis2auth($instance),
        'Accept: application/json',
    ]);
    
    // Enable verbose output for debugging
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    $verbose = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_STDERR, $verbose);
    
    // Temporarily disable SSL verification (for testing only)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    
    if ($response === false) {
        rewind($verbose);
        $verboseLog = stream_get_contents($verbose);
        error_log("CURL Error: " . curl_error($ch) . "\nVerbose log: " . $verboseLog);
        fclose($verbose);
        curl_close($ch);
        return null;
    }
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    error_log("HTTP Status Code: " . $httpCode);
    
    fclose($verbose);
    curl_close($ch);
    
    return json_decode($response, true);
}






// Test the connection step by step
echo "<h2>DHIS2 Connection Test</h2>";

// 1. First verify we can load the config
$config = getDhis2Config("UiO");
echo "<h3>Configuration Check</h3>";
if ($config) {
    echo "<pre>Config loaded successfully:\n";
    print_r($config);
    echo "</pre>";
} else {
    echo "<p style='color:red'>Failed to load configuration</p>";
    exit;
}

// 2. Test authentication string generation
echo "<h3>Authentication Check</h3>";
$authHeader = dhis2auth("UiO");
if ($authHeader) {
    echo "<p>Auth header generated: Basic " . htmlspecialchars($authHeader) . "</p>";
} else {
    echo "<p style='color:red'>Failed to generate authentication header</p>";
    exit;
}

// 3. Test with system info endpoint (simple endpoint)
echo "<h3>System Info Test</h3>";
$systemInfo = dhis2_get("api/system/info.json", "UiO");
if ($systemInfo) {
    echo "<pre>System info (connection successful):\n";
    print_r($systemInfo);
    echo "</pre>";
    
    // 4. If system info works, try organization units
    echo "<h3>Organization Units Test</h3>";
    $orgUnits = dhis2_get("api/organisationUnits.json?fields=id,name,level&paging=false", "UiO");
    if ($orgUnits) {
        echo "<pre>Organization units:\n";
        print_r($orgUnits);
        echo "</pre>";
    } else {
        echo "<p style='color:red'>Failed to fetch organization units (but system info worked)</p>";
    }
} else {
    echo "<p style='color:red'>Failed to connect to DHIS2. Check error logs.</p>";
    
    // Additional debug info
    echo "<h3>Debug Information</h3>";
    echo "<p>Test URL would be: " . htmlspecialchars($config['url'] . '/api/system/info.json') . "</p>";
    
    // Try a simple file_get_contents test
    echo "<h4>Alternative Connection Test</h4>";
    $testUrl = $config['url'] . '/api/system/info.json';
    $context = stream_context_create([
        'http' => [
            'header' => "Authorization: Basic " . $authHeader
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    
    $altResponse = @file_get_contents($testUrl, false, $context);
    if ($altResponse !== false) {
        echo "<p>file_get_contents worked! Response:</p>";
        echo "<pre>" . htmlspecialchars($altResponse) . "</pre>";
    } else {
        echo "<p style='color:red'>file_get_contents also failed. Possible server configuration issue.</p>";
        echo "<p>Last PHP error: " . error_get_last()['message'] . "</p>";
    }
}
?>