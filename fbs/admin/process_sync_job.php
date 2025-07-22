<?php
// process_sync_job.php - Processes a sync job and inserts/updates locations with the correct instance_key

session_start();
require_once __DIR__ . '/connect.php'; // Adjust path as needed

// Set error reporting for development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- CONFIGURATION ---
$storageDir = __DIR__ . '/temp';

// --- HELPER FUNCTION ---
/**
 * Output JSON response and exit
 *
 * @param array $response
 * @return void
 */
function outputResponse($response) {
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// --- INPUT VALIDATION ---
if (!isset($_GET['job_id']) || empty($_GET['job_id'])) {
    outputResponse(['status' => 'error', 'message' => 'Missing job_id']);
}
$jobId = $_GET['job_id'];
error_log("Processing job_id: " . $jobId); // Debug log

// --- LOAD JOB DATA ---
$unitsFile = $storageDir . '/' . $jobId . '_units.json';
if (!file_exists($unitsFile)) {
    outputResponse(['status' => 'error', 'message' => 'Job file not found: ' . $unitsFile]);
}
$jobData = json_decode(file_get_contents($unitsFile), true);
if (!$jobData || !isset($jobData['instance']) || !isset($jobData['units'])) {
    outputResponse(['status' => 'error', 'message' => 'Invalid job data in ' . $unitsFile]);
}

$instanceKey = $jobData['instance'];
$orgUnits = $jobData['units'];
$selectionType = $jobData['selection_type'] ?? 'none';

error_log("Loaded job data. Instance Key: " . $instanceKey . ", Units count from JSON: " . count($orgUnits)); // Debug log

// --- FETCH ORG UNIT DATA FROM LOCAL DB ---
$orgUnitsData = [];
if (!empty($orgUnits)) {
    // Prepare placeholders for the IN clause
    $placeholders = implode(',', array_fill(0, count($orgUnits), '?'));
    $sql = "SELECT id, uid, name, path, hierarchylevel AS level, parent_id FROM location WHERE uid IN ($placeholders)";
    $stmt = $mysqli->prepare($sql);

    if (!$stmt) {
        error_log("SQL Prepare Error (fetch initial units): " . $mysqli->error);
        outputResponse(['status' => 'error', 'message' => 'Database error preparing initial fetch.']);
    }

    // Dynamically bind parameters
    $types = str_repeat('s', count($orgUnits));
    $stmt->bind_param($types, ...$orgUnits);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        // Fetch parent UID if parent_id is set
        $parent_uid = null;
        if (!empty($row['parent_id'])) {
            $parent_stmt = $mysqli->prepare("SELECT uid FROM location WHERE id = ?");
            if (!$parent_stmt) {
                error_log("SQL Prepare Error (fetch parent uid): " . $mysqli->error);
                // Continue, but parent_uid will remain null
            } else {
                $parent_stmt->bind_param("i", $row['parent_id']);
                $parent_stmt->execute();
                $parent_stmt->bind_result($fetched_parent_uid);
                if ($parent_stmt->fetch()) {
                    $parent_uid = $fetched_parent_uid;
                }
                $parent_stmt->close();
            }
        }
        $orgUnitsData[] = [
            'uid' => $row['uid'],
            'name' => $row['name'],
            'path' => $row['path'],
            'level' => (int)$row['level'], // Ensure level is int
            'parent_uid' => $parent_uid
        ];
    }
    $stmt->close();

    error_log("Fetched " . count($orgUnitsData) . " org units from local DB matching UIDs in JSON."); // Debug log
    if (count($orgUnitsData) !== count($orgUnits)) {
        error_log("WARNING: Mismatch in count! JSON had " . count($orgUnits) . " UIDs, but only " . count($orgUnitsData) . " found in local DB.");
    }

    // Sort $orgUnitsData by level to ensure parents are processed first
    // This is crucial if parents might not yet exist for the new instance_key
    usort($orgUnitsData, function($a, $b) {
        if ($a['level'] == $b['level']) {
            // If levels are the same, try to sort by path for better hierarchy processing
            return strcmp($a['path'], $b['path']);
        }
        return ($a['level'] < $b['level']) ? -1 : 1;
    });

} else {
    error_log("No org units specified in JSON. Nothing to process.");
    outputResponse(['status' => 'success', 'message' => 'No units to process.']);
}

// --- INSERT/UPDATE LOCATIONS WITH INSTANCE_KEY ---
$inserted = 0;
$updated = 0;
$errors = 0;

foreach ($orgUnitsData as $orgUnit) {
    error_log("Processing insert/update for UID: " . $orgUnit['uid'] . ", Level: " . $orgUnit['level'] . ", Parent UID: " . ($orgUnit['parent_uid'] ?? 'N/A'));

    // Find parent_id for this instance_key if parent_uid is set
    $parentId = null;
    if (!empty($orgUnit['parent_uid'])) {
        $parentStmt = $mysqli->prepare("SELECT id FROM location WHERE uid = ? AND instance_key = ?");
        if (!$parentStmt) {
            error_log("SQL Prepare Error (find parent_id for instance): " . $mysqli->error);
            $errors++;
            continue; // Skip this unit if we can't prepare the parent lookup
        }
        $parentStmt->bind_param("ss", $orgUnit['parent_uid'], $instanceKey);
        $parentStmt->execute();
        $parentStmt->bind_result($parentIdResult);
        if ($parentStmt->fetch()) {
            $parentId = $parentIdResult;
            error_log("Found parent_id " . $parentId . " for " . $orgUnit['parent_uid'] . " under instance_key " . $instanceKey);
        } else {
            error_log("Parent UID " . $orgUnit['parent_uid'] . " not found for instance_key " . $instanceKey . " for child " . $orgUnit['uid'] . ". Setting parent_id to NULL.");
            // This is critical if the parent should exist.
            // It could mean the parent wasn't synced yet, or there's a data mismatch.
        }
        $parentStmt->close();
    }

    // Insert or update location for this instance_key
    $stmt = $mysqli->prepare("
        INSERT INTO location (instance_key, uid, name, path, hierarchylevel, parent_id)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            path = VALUES(path),
            hierarchylevel = VALUES(hierarchylevel),
            parent_id = VALUES(parent_id),
            updated = CURRENT_TIMESTAMP
    ");
    if (!$stmt) {
        error_log("SQL Prepare Error (insert/update location): " . $mysqli->error . " for UID " . $orgUnit['uid']);
        $errors++;
        continue;
    }

    // Ensure parentId is an integer or NULL
    $bindParentId = is_null($parentId) ? null : (int)$parentId;
    $bindLevel = (int)$orgUnit['level'];

    // For binding nulls: "i" expects an integer. If parentId is truly null, it's safer
    // to pass it as null and rely on PDO or mysqli's internal handling, but mysqli_stmt_bind_param
    // has specific requirements. If your column is NULLABLE INT, passing NULL usually works.
    $stmt->bind_param(
        "ssssii", // Assuming hierarchylevel and parent_id are integers
        $instanceKey,
        $orgUnit['uid'],
        $orgUnit['name'],
        $orgUnit['path'],
        $bindLevel,
        $bindParentId // Will be NULL if $parentId was null, assuming column is nullable INT
    );

    if ($stmt->execute()) {
        if ($mysqli->affected_rows > 0) {
            // affected_rows is 1 for INSERT, 2 for UPDATE (if row actually changed) or 0 (if no change)
            // For ON DUPLICATE KEY UPDATE, 1 means new row inserted, 2 means existing row updated.
            if ($mysqli->affected_rows == 1) {
                $inserted++;
            } else { // 2, or potentially 0 if no actual changes were made to values
                $updated++;
            }
        }
        error_log("Successfully processed UID " . $orgUnit['uid'] . ". Inserted: " . $inserted . ", Updated: " . $updated);
    } else {
        error_log("SQL Execute Error for UID " . $orgUnit['uid'] . ": " . $stmt->error);
        $errors++;
    }
    $stmt->close();
}

// --- UPDATE SESSION JOB STATUS (optional) ---
if (isset($_SESSION['sync_jobs'][$jobId])) {
    $_SESSION['sync_jobs'][$jobId]['processed'] = count($orgUnitsData);
    $_SESSION['sync_jobs'][$jobId]['inserted'] = $inserted;
    $_SESSION['sync_jobs'][$jobId]['updated'] = $updated;
    $_SESSION['sync_jobs'][$jobId]['errors'] = $errors;
    $_SESSION['sync_jobs'][$jobId]['status'] = 'done';
    error_log("Session job status updated for job_id " . $jobId);
}

// --- OUTPUT RESPONSE ---
outputResponse([
    'status' => 'success',
    'message' => 'Sync job processed',
    'job_id' => $jobId,
    'total_processed' => count($orgUnitsData),
    'inserted' => $inserted,
    'updated' => $updated,
    'errors' => $errors
]);

?>