<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__.'/../connect.php';

/**
 * Helper: Build a full DHIS2 API URL from base + endpoint, unless already absolute.
 * @param string $base Base URL from config
 * @param string $endpoint API path or absolute URL
 * @return string
 */
function buildDhis2Url(string $base, string $endpoint): string {
    if (preg_match('#^https?://#i', $endpoint)) {
        return $endpoint;
    }
    return rtrim($base, '/') . '/api/' . ltrim($endpoint, '/');
}

/**
 * Get DHIS2 config for a specific instance.
 */
/**
 * Retrieve the DHIS2 instance config by key (active only).
 * Returns an associative array (with password decoded) or null if not found.
 *
 * @param string $key The instance key
 * @return array|null The instance config, or null if not found
 */
function getDhis2Config(string $key): ?array {
    global $pdo;
    
    error_log("Attempting to get DHIS2 config for provided key: '" . $key . "'");
    if (isset($pdo)) {
        try {
            $sql = "SELECT url, username, password, instance_key, description, status 
                    FROM dhis2_instances 
                    WHERE instance_key = :key AND status = 1 
                    LIMIT 1";
            error_log("SQL Query for DHIS2 config: " . $sql);
            error_log("Binding parameter :key with value: '" . $key . "'");

            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':key', $key, PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                error_log("Successfully retrieved DHIS2 config for key: '" . $key . "'");
                return [
                    'url' => $row['url'],
                    'username' => $row['username'],
                    'password' => base64_decode($row['password']),
                    'instance_key' => $row['instance_key'],
                    'description' => $row['description'],
                    'status' => (int)$row['status']
                ];
            } else {
                error_log("No row found in dhis2_instances for instance_key: '" . $key . "' OR status is not 1.");
            }
        } catch (PDOException $e) {
            error_log("Database error fetching DHIS2 config for key '" . $key . "': " . $e->getMessage());
        }
    } else {
        error_log("PDO connection not available in getDhis2Config.");
    }
    return null;
}

/**
 * Get all DHIS2 configs (for select/list use).
 */
function getAllDhis2Configs(): array {
    global $pdo;
    $instances = [];
    if (isset($pdo)) {
        try {
            // IMPORTANT: Changed 'key' to 'instance_key' in SELECT clause
            $stmt = $pdo->prepare("SELECT url, username, password, instance_key, description, status FROM dhis2_instances WHERE status = 1");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $instances[] = [
                    'url' => $row['url'],
                    'username' => $row['username'],
                    'password' => base64_decode($row['password']),
                    'instance_key' => $row['instance_key'], // IMPORTANT: Changed array key access to 'instance_key'
                    'description' => $row['description'],
                    'status' => (int)$row['status']
                ];
            }
        } catch (PDOException $e) {
            error_log("Database error fetching all DHIS2 configs: " . $e->getMessage());
        }
    } else {
        error_log("PDO connection not available in getAllDhis2Configs.");
    }
    return $instances;
}

/**
 * Create Basic Auth string for DHIS2 API.
 */
function dhis2auth($instance) {
    $config = getDhis2Config($instance);
    if (!$config) {
        error_log("Config not found for instance: " . $instance);
        return null;
    }
    return base64_encode($config['username'] . ':' . $config['password']);
}

/**
 * Execute a cURL request to DHIS2 API.
 * @param string $fullUrl Absolute or relative URL (no formatting done here)
 * @param string $instance Instance key
 * @param string $method HTTP method
 * @param array|null $data POST/PUT/PATCH data
 * @return array|null JSON-decoded result or null on failure
 */
function dhis2Curl($fullUrl, $instance, $method = 'GET', $data = null) {
    $config = getDhis2Config($instance);
    if (!$config) {
        error_log("No DHIS2 configuration found for instance: " . $instance);
        return null;
    }

    error_log("DHIS2 API call: $method $fullUrl");
    $ch = curl_init();
   $options = [
        CURLOPT_URL => $fullUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . dhis2auth($instance),
            'Accept: application/json',
            'Content-Type: application/json'
        ],
        CURLOPT_VERBOSE => true,
        CURLOPT_STDERR => fopen('php://temp', 'w+'),
        CURLOPT_SSL_VERIFYPEER => false, // Keep for now for debugging, but remove in production!
        CURLOPT_SSL_VERIFYHOST => 0,      // Keep for now for debugging, but remove in production!
        CURLOPT_FOLLOWLOCATION => true // <<< THIS IS THE CRUCIAL LINE
    ];

    if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
        if ($data) {
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        } else {
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
    error_log("DHIS2 Response (first 500 chars): " . substr($response, 0, 500));
    return json_decode($response, true);
}

/**
 * GET helper for DHIS2 API.
 */
function dhis2_get($endpoint, $instance) {
    $config = getDhis2Config($instance);
    if (!$config) {
        error_log("No config found for instance: " . $instance);
        return null;
    }
    $fullUrl = buildDhis2Url($config['url'], $endpoint);
    return dhis2Curl($fullUrl, $instance, 'GET');
}

/**
 * POST helper for DHIS2 API.
 */
function dhis2_post($endpoint, $data, $instance) {
    $config = getDhis2Config($instance);
    if (!$config) {
        error_log("No config found for instance: " . $instance);
        return null;
    }
    $fullUrl = buildDhis2Url($config['url'], $endpoint);
    return dhis2Curl($fullUrl, $instance, 'POST', $data);
}

/**
 * PUT helper for DHIS2 API.
 */
function dhis2_put($endpoint, $data, $instance) {
    $config = getDhis2Config($instance);
    if (!$config) {
        error_log("No config found for instance: " . $instance);
        return null;
    }
    $fullUrl = buildDhis2Url($config['url'], $endpoint);
    return dhis2Curl($fullUrl, $instance, 'PUT', $data);
}

/**
 * PATCH helper for DHIS2 API.
 */
function dhis2_patch($endpoint, $data, $instance) {
    $config = getDhis2Config($instance);
    if (!$config) {
        error_log("No config found for instance: " . $instance);
        return null;
    }
    $fullUrl = buildDhis2Url($config['url'], $endpoint);
    return dhis2Curl($fullUrl, $instance, 'PATCH', $data);
}

/**
 * DELETE helper for DHIS2 API.
 */
function dhis2_delete($endpoint, $instance) {
    $config = getDhis2Config($instance);
    if (!$config) {
        error_log("No config found for instance: " . $instance);
        return null;
    }
    $fullUrl = buildDhis2Url($config['url'], $endpoint);
    return dhis2Curl($fullUrl, $instance, 'DELETE');
}

?>