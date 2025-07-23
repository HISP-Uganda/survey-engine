<?php
// Enable full error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * Gets DHIS2 configuration for a specific instance
 * 
 * @param string $key The instance key to look up
 * @return array|null Configuration array or null if not found
 */
function getDhis2Config(string $key): ?array {
    // Try to load from database first
    $dbHost = 'localhost';
    $dbUser = 'root';
    $dbPass = 'root';
    $dbName = 'fbtv3'; 

    $mysqli = @new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if (!$mysqli->connect_errno) {
        $stmt = $mysqli->prepare("SELECT url, username, password, `key`, description, status FROM dhis2_instances WHERE `key` = ? AND status = 1 LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $stmt->close();
                $mysqli->close();
                return [
                    'url' => $row['url'],
                    'username' => $row['username'],
                    'password' => base64_decode($row['password']),
                    'key' => $row['key'],
                    'description' => $row['description'],
                    'status' => (int)$row['status']
                ];
            }
            $stmt->close();
        }
        $mysqli->close();
    }

    // Fallback to JSON file if not found in DB
    $jsonFilePath = "dhis2/dhis2.json";
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
    if (isset($configs[$key])) {
        $configs[$key]['password'] = base64_decode($configs[$key]['password']);
        return $configs[$key];
    }
    return null;
}

//load dhis2 instances from databases (table is dhis2_instances) to serve as above from dhis2.json


/**
 * Creates an authentication string for DHIS2 API
 * 
 * @param string $instance The instance key
 * @return string|null Base64 encoded auth string or null if config not found
 */
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

function dhis2Curl($url, $instance, $method = 'GET', $data = null) {
    // $config = getDhis2Config($instance);  // Uncomment when config is available
    // $fullUrl = rtrim($config['url'], '/') . '/' . ltrim($url, '/');
    
    error_log("DHIS2 API call: $method $url");

    $ch = curl_init();
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . dhis2auth($instance),
            'Accept: application/json',
            'Content-Type: application/json'
        ],
        CURLOPT_VERBOSE => true,
        CURLOPT_STDERR => fopen('php://temp', 'w+'),
        CURLOPT_SSL_VERIFYPEER => false, // Remove in production
        CURLOPT_SSL_VERIFYHOST => 0  // Remove in production
    ];

    if ($method === 'POST' && $data) {
        $options[CURLOPT_POSTFIELDS] = json_encode($data);
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        rewind($options[CURLOPT_STDERR]);
        $verboseLog = stream_get_contents($options[CURLOPT_STDERR]);
        error_log("CURL Error: " . curl_error($ch) . "\nVerbose log: " . $verboseLog);
    }

    fclose($options[CURLOPT_STDERR]);
    curl_close($ch);
    
    error_log("HTTP Status: $httpCode");
    return $response ? json_decode($response, true) : null;
}


// function dhis2Curl($url, $instance, $method = 'GET', $data = null) {
//     // $config = getDhis2Config($instance);
//     // if (!$config) {
//     //     error_log("No config found for instance: " . $instance);
//     //     return null;
//     // }
    
//     // Construct the full URL
//     // $fullUrl = rtrim($config['url'], '/') . '/' . ltrim($url, '/');
//     error_log("Attempting to call DHIS2 API at: " . $url);

//     $ch = curl_init($url);
//     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

//     if($method === 'POST') {
//         curl_setopt($ch, CURLOPT_POST, true);
//         curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
//     }

//     curl_setopt($ch, CURLOPT_HTTPHEADER, [
//         'Authorization: Basic ' . dhis2auth($instance),
//         'Accept: application/json',
//     ]);
    
//     // Enable verbose output for debugging
//     curl_setopt($ch, CURLOPT_VERBOSE, true);
//     $verbose = fopen('php://temp', 'w+');
//     curl_setopt($ch, CURLOPT_STDERR, $verbose);
    
//     // Temporarily disable SSL verification (for testing only)
//     curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
//     curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
//     $response = curl_exec($ch);

//     if ($response === false) {
//         rewind($verbose);
//         $verboseLog = stream_get_contents($verbose);
//         error_log("CURL Error: " . curl_error($ch) . "\nVerbose log: " . $verboseLog);
//         fclose($verbose);
//         curl_close($ch);
//         return null;
//     }
    
//     $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
//     error_log("HTTP Status Code: " . $httpCode);
    
//     fclose($verbose);
//     curl_close($ch);
    
//     return json_decode($response, true);
// }
?>