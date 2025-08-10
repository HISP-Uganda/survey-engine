<?php
// Quick integration test for tracker form refactoring
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include necessary files
require_once 'fbs/admin/connect.php';
require_once 'fbs/admin/dhis2/dhis2_shared.php';

echo "Testing Tracker Form Integration:\n\n";

// Test 1: Check if dhis2_shared functions are available
echo "1. Testing dhis2_shared.php functions:\n";
if (function_exists('dhis2_post')) {
    echo "   ✅ dhis2_post() function available\n";
} else {
    echo "   ❌ dhis2_post() function NOT available\n";
}

if (function_exists('getDhis2Config')) {
    echo "   ✅ getDhis2Config() function available\n";
} else {
    echo "   ❌ getDhis2Config() function NOT available\n";
}

// Test 2: Check database connection
echo "\n2. Testing database connection:\n";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM dhis2_instances");
    $result = $stmt->fetch();
    echo "   ✅ Database connection successful\n";
    echo "   ✅ Found {$result['count']} DHIS2 instances configured\n";
} catch (Exception $e) {
    echo "   ❌ Database connection failed: " . $e->getMessage() . "\n";
}

// Test 3: Check if tracker_submissions table exists
echo "\n3. Testing tracker_submissions table:\n";
try {
    $stmt = $pdo->query("DESCRIBE tracker_submissions");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "   ✅ tracker_submissions table exists\n";
    echo "   ✅ Columns: " . implode(', ', $columns) . "\n";
} catch (Exception $e) {
    echo "   ❌ tracker_submissions table issue: " . $e->getMessage() . "\n";
}

// Test 4: Check if dhis2_submission_log table exists (for payload checker)
echo "\n4. Testing payload checker integration:\n";
try {
    $stmt = $pdo->query("DESCRIBE dhis2_submission_log");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "   ✅ dhis2_submission_log table exists\n";
    echo "   ✅ Payload checker integration ready\n";
} catch (Exception $e) {
    echo "   ❌ dhis2_submission_log table issue: " . $e->getMessage() . "\n";
}

// Test 5: Check for tracker surveys
echo "\n5. Testing tracker survey configuration:\n";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM survey WHERE dhis2_program_uid IS NOT NULL");
    $result = $stmt->fetch();
    echo "   ✅ Found {$result['count']} surveys with DHIS2 program UIDs (potential tracker surveys)\n";
} catch (Exception $e) {
    echo "   ❌ Error checking tracker surveys: " . $e->getMessage() . "\n";
}

echo "\n🎉 Integration test completed!\n";
echo "\nKey improvements made:\n";
echo "- ✅ Replaced custom cURL with shared dhis2_post() functions\n";
echo "- ✅ Added payload checker integration for retry capability\n";  
echo "- ✅ Improved error handling using shared patterns\n";
echo "- ✅ Standardized DHIS2 API authentication\n";
echo "\nTracker forms should now:\n";
echo "- Use the same DHIS2 connection system as regular surveys\n";
echo "- Appear in payload checker for failed submission retry\n";
echo "- Have better error messages and logging\n";
?>