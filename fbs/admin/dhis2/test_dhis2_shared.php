<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1); // Ensure logging to file is enabled
ini_set('error_log', __DIR__ . '/php-error.log'); // Specify your error log path

// Ensure dhis2_shared.php is included. It will, in turn, include connect.php.
require_once __DIR__ . '/dhis2_shared.php';

echo "<h2>Testing DHIS2 Shared Functions</h2>";

// Test database connection (from connect.php via dhis2_shared.php)
global $pdo; // Access the global PDO object
if (isset($pdo) && $pdo instanceof PDO) {
    echo "<p style='color: green;'>PDO connection object is available.</p>";
    try {
        $stmt = $pdo->query("SELECT 1 + 1 AS result;"); // Simple query
        if ($stmt && $row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "<p style='color: green;'>Database query successful: " . $row['result'] . "</p>";
        } else {
            echo "<p style='color: red;'>Database query failed.</p>";
            error_log("test_dhis2_shared.php: Simple database query failed.");
        }
    } catch (PDOException $e) {
        echo "<p style='color: red;'>Database connection query error: " . htmlspecialchars($e->getMessage()) . "</p>";
        error_log("test_dhis2_shared.php: Database query PDOException: " . $e->getMessage());
    }
} else {
    echo "<p style='color: red;'>PDO object not available or not an instance of PDO. Check connect.php and its inclusion.</p>";
    error_log("test_dhis2_shared.php: PDO object not available.");
}

echo "<h3>Testing getDhis2Config()</h3>";

// Replace 'YOUR_ACTUAL_INSTANCE_KEY' with a key that exists in your 'dhis2_instances' table
// AND has status = 1. You should have checked this directly in your database.
$test_instance_key = 'UiO'; // Example key, use one from your DB that has status=1

if (function_exists('getDhis2Config')) {
    echo "<p><code>getDhis2Config</code> function is defined.</p>";

    $config = getDhis2Config($test_instance_key);

    if ($config) {
        echo "<p style='color: green;'>Successfully retrieved DHIS2 config for instance: <strong>" . htmlspecialchars($config['description']) . " (" . htmlspecialchars($config['key']) . ")</strong></p>";
        echo "<h4>Retrieved Config Details:</h4>";
        echo "<pre>";
        print_r($config);
        echo "</pre>";
    } else {
        echo "<p style='color: red;'>Failed to retrieve DHIS2 config for key: '<strong>" . htmlspecialchars($test_instance_key) . "</strong>'. Check your PHP error logs for more details (e.g., 'No row found...').</p>";
    }
} else {
    echo "<p style='color: red;'><code>getDhis2Config</code> function is NOT defined. `dhis2_shared.php` likely failed to load properly.</p>";
    error_log("test_dhis2_shared.php: getDhis2Config function not found.");
}

echo "<h3>Testing getAllDhis2Configs()</h3>";

if (function_exists('getAllDhis2Configs')) {
    echo "<p><code>getAllDhis2Configs</code> function is defined.</p>";
    $allConfigs = getAllDhis2Configs();

    if (!empty($allConfigs)) {
        echo "<p style='color: green;'>Successfully retrieved all active DHIS2 configs:</p>";
        echo "<pre>";
        print_r($allConfigs);
        echo "</pre>";
    } else {
        echo "<p style='color: orange;'>No active DHIS2 configurations found in the database. (Or failed to retrieve them. Check error logs).</p>";
    }
} else {
    echo "<p style='color: red;'><code>getAllDhis2Configs</code> function is NOT defined.</p>";
}

?>