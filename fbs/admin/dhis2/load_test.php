<?php
// load_test.php - Simplified for debugging organisationUnitLevels fetch

// 1. Ensure dhis2_shared.php is included. It already handles connect.php.
require_once __DIR__ . '/dhis2_shared.php';

// 2. Aggressive error reporting and logging for detailed output
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log'); // Make sure this path is writable

echo "<h1>DHIS2 Organisation Unit Levels Debug Test</h1>";

// --- Configuration for the test ---
// Replace 'EMIS' with the actual instance_key you are testing with
$test_instance_key = 'UiO'; // Use the key from your screenshot 'Development instance for DHIS2 (UiO)'

// 3. Get the full DHIS2 configuration for the test instance
echo "<h2>Step 1: Get DHIS2 Config</h2>";
$dhis2Config = getDhis2Config($test_instance_key);

if (!$dhis2Config) {
    echo "<p style='color: red;'><strong>ERROR:</strong> Failed to retrieve DHIS2 config for key: '{$test_instance_key}'. Check dhis2_shared.php logs for database errors.</p>";
    die("Cannot proceed without DHIS2 config.");
} else {
    echo "<p style='color: green;'>Successfully retrieved DHIS2 config for: " . htmlspecialchars($dhis2Config['description']) . "</p>";
    echo "<h3>Config Details:</h3>";
    echo "<pre>";
    var_dump($dhis2Config);
    echo "</pre>";
    // Critical: Verify the URL here has the trailing slash.
    if (substr($dhis2Config['url'], -1) !== '/') {
        echo "<p style='color: orange;'><strong>WARNING:</strong> DHIS2 URL in database for '{$test_instance_key}' does NOT have a trailing slash. This might cause redirects. Current URL: " . htmlspecialchars($dhis2Config['url']) . "</p>";
    } else {
        echo "<p style='color: green;'>DHIS2 URL has a trailing slash (GOOD).</p>";
    }
}

// 4. Test fetching Organisation Unit Levels
echo "<h2>Step 2: Fetch Organisation Unit Levels</h2>";
$levelsEndpoint = 'organisationUnitLevels?fields=id,level,displayName';
echo "<p>Attempting to call DHIS2 API endpoint: <code>" . htmlspecialchars($levelsEndpoint) . "</code> for instance <code>" . htmlspecialchars($test_instance_key) . "</code></p>";

// Call the dhis2_get function (which uses dhis2Curl internally)
$levelsResponse = dhis2_get($levelsEndpoint, $test_instance_key);

echo "<h3>Raw \$levelsResponse from dhis2_get():</h3>";
echo "<pre>";
var_dump($levelsResponse); // This will show if it's null, empty array, or actual data
echo "</pre>";

if ($levelsResponse && isset($levelsResponse['organisationUnitLevels']) && !empty($levelsResponse['organisationUnitLevels'])) {
    echo "<p style='color: green;'><strong>SUCCESS!</strong> Organisation Unit Levels retrieved:</p>";
    echo "<ul>";
    foreach ($levelsResponse['organisationUnitLevels'] as $levelData) {
        if (isset($levelData['level']) && isset($levelData['displayName'])) {
            echo "<li>Level " . htmlspecialchars($levelData['level']) . ": " . htmlspecialchars($levelData['displayName']) . "</li>";
        } else {
            echo "<li>Incomplete level data: " . json_encode($levelData) . "</li>";
        }
    }
    echo "</ul>";
} elseif ($levelsResponse && isset($levelsResponse['organisationUnitLevels']) && empty($levelsResponse['organisationUnitLevels'])) {
    echo "<p style='color: orange;'><strong>WARNING:</strong> DHIS2 API call successful, but no organisationUnitLevels found in the response (the array is empty).</p>";
} else {
    echo "<p style='color: red;'><strong>ERROR:</strong> Failed to fetch organisationUnitLevels or response was malformed.</p>";
    echo "<p style='color: red;'><strong>Check your <code>php-error.log</code> for details from <code>dhis2Curl</code> (HTTP Status, DHIS2 Response, CURL Errors).</strong></p>";
}

?>