<?php
// sync_worker.php - ONLY imports from CSV to local DB (no DHIS2 calls)
session_start();
header('Content-Type: application/json');
set_time_limit(300); // Increased time limit for larger imports

require_once __DIR__ . '/connect.php'; // Make sure this provides $pdo

// --- STATUS CONSTANTS ---
define('STATUS_PROCESSING', 'processing');
define('STATUS_IMPORTING', 'importing');
define('STATUS_COMPLETE', 'complete');
define('STATUS_ERROR', 'error');

// --- INITIALIZE RESPONSE ---
$response = [
    'status'    => 'error',
    'message'   => 'Invalid request',
    'processed' => 0,
    'total'     => 0,
    'inserted'  => 0,
    'updated'   => 0, // In this new logic, 'updated' means "skipped due to existing exact match"
    'errors'    => 0,
    'progress'  => 0
];

// --- VALIDATE JOB ---
$jobId = $_GET['job_id'] ?? null;
if (!$jobId || !isset($_SESSION['sync_jobs'][$jobId])) {
    echo json_encode($response);
    exit;
}
$job = &$_SESSION['sync_jobs'][$jobId];
$response['total'] = $job['total'] ?? 0; // Get total from session job data

try {
    $storageDir = __DIR__ . '/temp';
    $csvFile    = $storageDir . '/' . $jobId . '_data.csv';

    if (!file_exists($csvFile)) throw new Exception("CSV file not found!");

    $job['status'] = STATUS_PROCESSING;
    importCSVToDatabase($csvFile, $job, $pdo); // Pass $pdo
    
    $job['status'] = STATUS_COMPLETE;
    $response['status'] = STATUS_COMPLETE;
    $response['message'] = 'Import completed successfully!';
    
    // Clean up temp files after successful import (or after error if desired)
    @unlink($csvFile); 

} catch (Exception $e) {
    $job['status'] = STATUS_ERROR;
    $response['status'] = STATUS_ERROR;
    $response['message'] = '[ERROR] ' . $e->getMessage();
    error_log("[SYNC WORKER ERROR] Job ID: {$jobId} - " . $e->getMessage());
}

// Update response with final job stats
$response['processed'] = $job['processed'] ?? 0;
$response['inserted']  = $job['inserted']  ?? 0;
$response['updated']   = $job['updated']   ?? 0; // Reflects skipped count
$response['errors']    = $job['errors']    ?? 0;
$response['status']    = $job['status'];
$response['progress']  = $response['total'] > 0 ? round(($response['processed'] / $response['total']) * 100) : 0;

echo json_encode($response);
exit;

// --- CSV Import Function ---
function importCSVToDatabase($csvFile, &$job, $pdo) {
    if (!file_exists($csvFile)) {
        throw new Exception("CSV file missing: $csvFile");
    }

    $data = [];
    if (($h = fopen($csvFile, "r")) !== FALSE) {
        $header = fgetcsv($h, 1000, ","); // Read header
        $headerMap = array_flip($header); // Map column names to indices

        $expectedColumns = ['instance_key', 'uid', 'name', 'path', 'level', 'parent_uid'];
        foreach ($expectedColumns as $col) {
            if (!isset($headerMap[$col])) {
                throw new Exception("Missing expected column in CSV: '$col'");
            }
        }

        while (($r = fgetcsv($h, 1000, ",")) !== FALSE) {
            if (count($r) < count($expectedColumns)) {
                error_log("Skipping malformed CSV row: " . implode(',', $r));
                $job['errors']++;
                continue;
            }

            $data[] = [
                'instance_key' => $r[$headerMap['instance_key']],
                'uid'          => $r[$headerMap['uid']],
                'name'         => $r[$headerMap['name']],
                'path'         => $r[$headerMap['path']],
                'level'        => is_numeric($r[$headerMap['level']]) ? (int)$r[$headerMap['level']] : null,
                'parent_uid'   => $r[$headerMap['parent_uid']] ?? ''
            ];
        }
        fclose($h);
    }
    
    if (empty($data)) throw new Exception("No data to import from CSV!");

    $job['inserted'] = 0;
    $job['updated'] = 0; // Now used for "skipped due to exact duplicate"
    $job['errors'] = 0;
    $job['processed'] = 0;

    $pdo->beginTransaction();
    try {
        // Prepare statement to check for existing record by (instance_key, uid, path)
        $checkExactMatchStmt = $pdo->prepare("SELECT id FROM location WHERE instance_key = ? AND uid = ? AND path = ? LIMIT 1");

        // Prepare statement for inserting new record
        $insertStmt = $pdo->prepare("
            INSERT INTO location (instance_key, uid, name, path, hierarchylevel, parent_id)
            VALUES (?, ?, ?, ?, ?, NULL)
        ");

        foreach ($data as $loc) {
            $job['processed']++; // Increment processed count for each record from CSV

            try {
                // Rule 1: Check if (instance_key, uid, path) is already present
                $checkExactMatchStmt->execute([$loc['instance_key'], $loc['uid'], $loc['path']]);
                $exactMatchRow = $checkExactMatchStmt->fetch(PDO::FETCH_ASSOC);

                if ($exactMatchRow) {
                    // Record already exists with exact (instance_key, uid, path). Skip.
                    $job['updated']++; // Count as skipped/already existing
                    continue; // Move to the next record in the CSV
                } else {
                    // Rule 2 & 3: (instance_key, uid, path) combination does NOT exist. Perform an INSERT.
                    // This handles both completely new (instance_key, uid) combinations
                    // AND existing (instance_key, uid) combinations that have a new/different path.
                    $insertStmt->execute([
                        $loc['instance_key'],
                        $loc['uid'],
                        $loc['name'],
                        $loc['path'],
                        $loc['level']
                    ]);
                    $job['inserted']++;
                }
            } catch (PDOException $e) {
                $job['errors']++;
                error_log("[IMPORT] DB operation error for UID {$loc['uid']} in instance {$loc['instance_key']} with path {$loc['path']}: " . $e->getMessage());
            }
        }

        // --- Parent ID Resolution (Second Pass) ---
        // This part needs to remain. It resolves `parent_id` by linking children to parents.
        // Given that (instance_key, uid) might now be non-unique, the behavior for parent resolution
        // will be based on the arbitrary `id` chosen if multiple rows exist for the same (instance_key, uid).
        // If an organization unit (by uid and instance_key) has multiple paths in the database,
        // which one should be linked as a parent? The current logic will pick one arbitrarily.

        $uidMap = [];
        $uidsToMap = [];
        // Assuming all data in one CSV batch is for a single instance_key for simplicity in parent mapping
        $currentBatchInstanceKey = $data[0]['instance_key'] ?? ''; 

        foreach($data as $loc) {
            $uidsToMap[] = $loc['uid'];
            if (!empty($loc['parent_uid'])) {
                $uidsToMap[] = $loc['parent_uid'];
            }
        }
        $uidsToMap = array_unique($uidsToMap);

        if (!empty($uidsToMap) && !empty($currentBatchInstanceKey)) {
            $chunks = array_chunk($uidsToMap, 500);
            foreach ($chunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                // Fetch IDs for all relevant UIDs within this specific instance_key.
                // If (instance_key, uid) is not unique, this SELECT will return multiple rows.
                // The $uidMap will then store the `id` of the LAST row fetched for that (uid, instance_key).
                // This is an ARBITRARY choice for parent resolution.
                $q = $pdo->prepare("SELECT id, uid FROM location WHERE instance_key = ? AND uid IN ($placeholders)");
                $q->execute(array_merge([$currentBatchInstanceKey], $chunk));
                while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                    $uidMap[$row['uid'] . '|' . $currentBatchInstanceKey] = $row['id'];
                }
            }
        }

        // Update parent_id for each location record processed in this batch
        $parentUpdate = $pdo->prepare("UPDATE location SET parent_id = ? WHERE instance_key = ? AND uid = ?");
        foreach ($data as $loc) {
            if (!empty($loc['parent_uid'])) {
                $childLookupKey = $loc['uid'] . '|' . $loc['instance_key'];
                $parentLookupKey = $loc['parent_uid'] . '|' . $loc['instance_key'];

                if (isset($uidMap[$childLookupKey]) && isset($uidMap[$parentLookupKey])) {
                    $parentId = $uidMap[$parentLookupKey];
                    try {
                        // This UPDATE also relies on (instance_key, uid) to identify the child.
                        // If (instance_key, uid) is not unique, this UPDATE statement will affect
                        // ALL rows that match (instance_key, uid), setting the same parent_id for all.
                        // This might lead to unintended results if specific paths need specific parents.
                        $parentUpdate->execute([
                            $parentId,
                            $loc['instance_key'],
                            $loc['uid']
                        ]);
                    } catch (PDOException $e) {
                        $job['errors']++;
                        error_log("[IMPORT ERROR] Parent update error for child UID {$loc['uid']} in instance {$loc['instance_key']}: " . $e->getMessage());
                    }
                } else {
                    error_log("[IMPORT WARNING] Could not resolve parent for UID: {$loc['uid']} (Parent UID: {$loc['parent_uid']}) in instance {$loc['instance_key']}. Parent_id will remain NULL.");
                }
            }
        }

        $pdo->commit(); // Commit the transaction
    } catch (Exception $ex) {
        $pdo->rollBack(); // Rollback on any error
        throw $ex; // Re-throw to be caught by the outer try-catch
    }
    return true;
}