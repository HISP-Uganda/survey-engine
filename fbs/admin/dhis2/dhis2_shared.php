<?php
// Enable full error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include the database connection file from the parent directory
require_once __DIR__ . '/../connect.php'; // Adjust path based on your exact file structure

/**
 * Gets DHIS2 configuration for a specific instance
 *
 * @param string $key The instance key to look up
 * @return array|null Configuration array or null if not found
 */
function getDhis2Config(string $key): ?array {
    global $pdo; // Access the global PDO object from connect.php

    // Try to load from database first
    if (isset($pdo)) {
        try {
            // Using PDO prepared statements
            $stmt = $pdo->prepare("SELECT url, username, password, `key`, description, status FROM dhis2_instances WHERE `key` = ? AND status = 1 LIMIT 1");
            if ($stmt) {
                $stmt->execute([$key]); // Pass parameters as an array
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return [
                        'url' => $row['url'],
                        'username' => $row['username'],
                        'password' => base64_decode($row['password']),
                        'key' => $row['key'],
                        'description' => $row['description'],
                        'status' => (int)$row['status']
                    ];
                }
            }
        } catch (PDOException $e) {
            error_log("Database error fetching DHIS2 config: " . $e->getMessage());
            // Continue to try JSON file if DB fails
        }
    } else {
        error_log("PDO connection not available in getDhis2Config. Trying JSON fallback.");
    }


    // Fallback to JSON file if not found in DB or DB connection failed
    // Assuming dhis2.json is in the same directory as this script (admin/dhis2/)
    $jsonFilePath = __DIR__ . "/dhis2.json";
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
        // Decode password from JSON config as well
        $configs[$key]['password'] = base64_decode($configs[$key]['password']);
        return $configs[$key];
    }
    return null;
}

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
    // error_log("Generated Auth Header: Basic " . $authString); // Keep this commented unless deep debugging
    return $authString;
}

/**
 * Executes a cURL request to the DHIS2 API
 *
 * @param string $url The relative DHIS2 API path (e.g., 'api/dataElements')
 * @param string $instance The instance key for DHIS2 configuration
 * @param string $method The HTTP method (GET, POST, PUT, DELETE)
 * @param array|null $data Data for POST/PUT requests
 * @return array|null Decoded JSON response or null on failure
 */
function dhis2Curl($url, $instance, $method = 'GET', $data = null) {
    $config = getDhis2Config($instance);
    if (!$config) {
        error_log("No DHIS2 configuration found for instance: " . $instance);
        return null;
    }

    $fullUrl = rtrim($config['url'], '/') . '/' . ltrim($url, '/');
    error_log("DHIS2 API call: $method $fullUrl"); // Log the full URL

    $ch = curl_init();
    $options = [
        CURLOPT_URL => $fullUrl, // Use the constructed full URL
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . dhis2auth($instance),
            'Accept: application/json',
            'Content-Type: application/json'
        ],
        CURLOPT_VERBOSE => true, // Enable verbose logging
        CURLOPT_STDERR => fopen('php://temp', 'w+'), // Capture verbose log
        // Temporarily disable SSL verification - FOR DEVELOPMENT ONLY!
        // In production, configure proper SSL certificate validation.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0
    ];

    if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') { // Add PATCH if used
        if ($data) {
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        } else {
            // For POST/PUT with no data, ensure content-length is 0
            $options[CURLOPT_HTTPHEADER][] = 'Content-Length: 0';
        }
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        rewind($options[CURLOPT_STDERR]);
        $verboseLog = stream_get_contents($options[CURLOPT_STDERR]);
        error_log("CURL Error: " . curl_error($ch) . "\nHTTP Code: $httpCode\nVerbose log: " . $verboseLog);
        fclose($options[CURLOPT_STDERR]);
        curl_close($ch);
        return null;
    }

    rewind($options[CURLOPT_STDERR]);
    $verboseLog = stream_get_contents($options[CURLOPT_STDERR]);
    fclose($options[CURLOPT_STDERR]);
    curl_close($ch);

    error_log("HTTP Status: $httpCode");
    error_log("DHIS2 Response (first 500 chars): " . substr($response, 0, 500)); // Log part of response
    return json_decode($response, true);
}

// You can test the functions here, for example:
// $instanceKey = 'your_dhis2_instance_key'; // Replace with a key from your dhis2_instances table or dhis2.json
// $config = getDhis2Config($instanceKey);
// if ($config) {
//     echo "Config loaded for " . $config['key'] . ": " . $config['url'] . "<br>";
//     // Example usage of dhis2Curl (uncomment to test, adjust URL/data as needed)
//     // $orgUnits = dhis2Curl('api/organisationUnits.json?fields=id,name&paging=false', $instanceKey);
//     // if ($orgUnits) {
//     //     echo "<pre>";
//     //     print_r($orgUnits);
//     //     echo "</pre>";
//     // } else {
//     //     echo "Failed to fetch organization units.<br>";
//     // }
// } else {
//     echo "Failed to load config for " . $instanceKey . "<br>";
// }

?>