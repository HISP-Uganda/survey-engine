<?php
// Include shared functions
require_once 'dhis2_shared.php';

/**
 * Performs a GET request to DHIS2 API
 * 
 * @param string $endpoint The API endpoint to call (without base URL)
 * @param string $instance The instance key for configuration
 * @return array|null Response data as array or null on failure
 */
function dhis2_get($endpoint, $instance) {
    $config = getDhis2Config($instance);
    if (!$config) {
        error_log("No config found for instance: " . $instance);
        return null;
    }
    
    // // Construct the full URL
    $fullUrl = rtrim($config['url'], '/') . '/' . ltrim($endpoint, '/');
    error_log("Attempting to call DHIS2 API at: " . $fullUrl);

    return dhis2Curl($fullUrl, $instance);
}
?>