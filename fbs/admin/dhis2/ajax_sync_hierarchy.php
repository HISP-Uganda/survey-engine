<?php
// dhis2/ajax_sync_hierarchy.php - Memory-optimized hierarchy sync with pagination
session_start();
require_once __DIR__ . '/dhis2_shared.php';

ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');

// Increase memory limit and execution time for large syncs
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 0); // No time limit for large syncs
set_time_limit(0);

// Enable output buffering and flush for real-time updates
ob_start();
header('Content-Type: application/json');

// Function to send progress updates
function sendProgress($data) {
    echo json_encode($data) . "\n";
    if (ob_get_level()) {
        ob_flush();
    }
    flush();
}

$instanceKey = $_POST['dhis2_instance'] ?? '';
$syncLevels = $_POST['sync_levels'] ?? []; // Array of levels to sync
$fullHierarchy = ($_POST['full_hierarchy'] ?? 'false') === 'true'; // Convert string to boolean
$syncMode = $_POST['sync_mode'] ?? 'update'; // update, skip, or fresh

if (empty($instanceKey)) {
    echo json_encode(['success' => false, 'error' => 'No instance key provided']);
    exit;
}

$config = getDhis2Config($instanceKey);
if (!$config) {
    echo json_encode(['success' => false, 'error' => 'Instance not found or not active']);
    exit;
}

try {
    // Step 1: Get all organisation unit levels for reference
    sendProgress(['type' => 'start', 'message' => 'Connecting to DHIS2 server...']);
    
    $levelsResp = dhis2_get('organisationUnitLevels?fields=id,level,displayName&paging=false', $instanceKey);
    
    // Check if the API call failed due to timeout or other issues
    if ($levelsResp === null) {
        throw new Exception('Failed to connect to DHIS2 server. The server may be unavailable or experiencing high load.');
    }
    
    $levelMap = [];
    if (!empty($levelsResp['organisationUnitLevels'])) {
        foreach ($levelsResp['organisationUnitLevels'] as $lvl) {
            $levelMap[$lvl['level']] = $lvl['displayName'];
        }
        sendProgress(['type' => 'start', 'message' => 'Connected successfully. Preparing sync...']);
    } else {
        throw new Exception('No organisation unit levels found in DHIS2 instance.');
    }

    // Step 2: Build filter based on sync type
    $filter = '';
    if ($fullHierarchy) {
        // Sync all levels
        $filter = '';
    } elseif (!empty($syncLevels)) {
        // Sync specific levels
        $levelFilters = array_map(function($level) {
            return "level:eq:$level";
        }, $syncLevels);
        $filter = '&filter=' . implode('&filter=', $levelFilters);
    } else {
        sendProgress(['type' => 'error', 'success' => false, 'error' => 'No sync levels specified']);
        exit;
    }

    // Step 3: Handle different sync modes
    global $pdo;
    $uidToIdMap = [];
    $skipped = 0;
    
    if ($syncMode === 'fresh') {
        // Delete all existing records for this instance
        sendProgress(['type' => 'start', 'message' => 'Clearing existing records for fresh start...']);
        $deleteStmt = $pdo->prepare("DELETE FROM location WHERE instance_key = ?");
        $deleteStmt->execute([$instanceKey]);
        sendProgress(['type' => 'start', 'message' => 'Fresh start complete. Beginning sync...']);
    } else {
        // Get existing records to build UID mapping for parent resolution
        $stmt = $pdo->prepare("SELECT id, uid FROM location WHERE instance_key = ?");
        $stmt->execute([$instanceKey]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $uidToIdMap[$row['uid']] = $row['id'];
        }
    }

    // Step 4: Paginated sync process
    $pageSize = 500; // Process 500 records at a time for efficiency
    $page = 1;
    $processed = 0;
    $inserted = 0;
    $updated = 0;
    $errors = [];
    $totalPages = 0;
    $totalRecords = 0;
    
    // Send initial progress
    sendProgress(['type' => 'start', 'message' => 'Starting DHIS2 hierarchy sync...']);
    
    // Start transaction
    $pdo->beginTransaction();

    do {
        // Clear memory from previous iteration
        if ($page > 1) {
            gc_collect_cycles();
        }

        // Fetch page of organization units
        $endpoint = "organisationUnits?fields=id,name,path,level,parent[id,name]&pageSize=$pageSize&page=$page$filter";
        $orgUnitsResp = dhis2_get($endpoint, $instanceKey);

        // Check if API call failed (timeout or other error)
        if ($orgUnitsResp === null) {
            sendProgress([
                'type' => 'error', 
                'message' => "DHIS2 server timeout on page $page. Sync stopped but processed data is saved.",
                'partial_success' => true,
                'processed' => $processed,
                'inserted' => $inserted,
                'updated' => $updated
            ]);
            break;
        }

        if (empty($orgUnitsResp['organisationUnits'])) {
            break; // No more data
        }

        $orgUnits = $orgUnitsResp['organisationUnits'];
        $totalPages = $orgUnitsResp['pager']['pageCount'] ?? 1;
        $totalRecords = $orgUnitsResp['pager']['total'] ?? count($orgUnits);
        
        // Send page progress
        sendProgress([
            'type' => 'page', 
            'page' => $page, 
            'totalPages' => $totalPages,
            'totalRecords' => $totalRecords,
            'processed' => $processed,
            'message' => "Processing page $page of $totalPages (" . count($orgUnits) . " units)"
        ]);

        // Sort current page by level to ensure parents are processed first
        usort($orgUnits, function($a, $b) {
            return $a['level'] <=> $b['level'];
        });

        // Process batch of organization units
        foreach ($orgUnits as $ou) {
            try {
                $processed++;
                
                // Extract parent UID from path or parent object
                $parentUid = null;
                if (!empty($ou['parent']['id'])) {
                    $parentUid = $ou['parent']['id'];
                } elseif (!empty($ou['path'])) {
                    // Extract parent from path (e.g., /root/parent/current -> parent)
                    $pathParts = array_filter(explode('/', $ou['path']));
                    if (count($pathParts) > 1) {
                        $parentUid = $pathParts[count($pathParts) - 2];
                    }
                }

                // Resolve parent_id from UID
                $parentId = null;
                if ($parentUid && isset($uidToIdMap[$parentUid])) {
                    $parentId = $uidToIdMap[$parentUid];
                }

                // Handle based on sync mode
                if ($syncMode === 'fresh') {
                    // Fresh mode: Always insert (since we deleted all records)
                    $insertStmt = $pdo->prepare("
                        INSERT INTO location (instance_key, uid, name, path, hierarchylevel, parent_id, created, updated)
                        VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ");
                    $insertStmt->execute([
                        $instanceKey,
                        $ou['id'],
                        $ou['name'],
                        $ou['path'],
                        $ou['level'],
                        $parentId
                    ]);
                    $inserted++;
                    
                    // Add new ID to mapping for future parent references
                    $newId = $pdo->lastInsertId();
                    $uidToIdMap[$ou['id']] = $newId;
                    
                } elseif ($syncMode === 'skip') {
                    // Skip mode: Only insert if record doesn't exist
                    if (isset($uidToIdMap[$ou['id']])) {
                        $skipped++;
                        // Still keep in mapping for parent references
                        continue;
                    } else {
                        // Insert new record
                        $insertStmt = $pdo->prepare("
                            INSERT INTO location (instance_key, uid, name, path, hierarchylevel, parent_id, created, updated)
                            VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ");
                        $insertStmt->execute([
                            $instanceKey,
                            $ou['id'],
                            $ou['name'],
                            $ou['path'],
                            $ou['level'],
                            $parentId
                        ]);
                        $inserted++;
                        
                        // Add new ID to mapping for future parent references
                        $newId = $pdo->lastInsertId();
                        $uidToIdMap[$ou['id']] = $newId;
                    }
                    
                } else {
                    // Update mode: Check if record exists and update or insert
                    $checkStmt = $pdo->prepare("SELECT id FROM location WHERE instance_key = ? AND uid = ?");
                    $checkStmt->execute([$instanceKey, $ou['id']]);
                    $existingRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);

                    if ($existingRecord) {
                        // Update existing record
                        $updateStmt = $pdo->prepare("
                            UPDATE location 
                            SET name = ?, path = ?, hierarchylevel = ?, parent_id = ?, updated = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        $updateStmt->execute([
                            $ou['name'],
                            $ou['path'],
                            $ou['level'],
                            $parentId,
                            $existingRecord['id']
                        ]);
                        $updated++;
                        
                        // Keep existing ID in mapping
                        $uidToIdMap[$ou['id']] = $existingRecord['id'];
                    } else {
                        // Insert new record
                        $insertStmt = $pdo->prepare("
                            INSERT INTO location (instance_key, uid, name, path, hierarchylevel, parent_id, created, updated)
                            VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ");
                        $insertStmt->execute([
                            $instanceKey,
                            $ou['id'],
                            $ou['name'],
                            $ou['path'],
                            $ou['level'],
                            $parentId
                        ]);
                        $inserted++;
                        
                        // Add new ID to mapping for future parent references
                        $newId = $pdo->lastInsertId();
                        $uidToIdMap[$ou['id']] = $newId;
                    }
                }

                // Send progress every 100 records and commit every 100 records
                if ($processed % 100 === 0) {
                    $pdo->commit();
                    $pdo->beginTransaction();
                    
                    // Send progress update
                    $progressPercent = ($totalRecords > 0) ? round(($processed / $totalRecords) * 100, 1) : 0;
                    sendProgress([
                        'type' => 'progress', 
                        'processed' => $processed,
                        'totalRecords' => $totalRecords,
                        'inserted' => $inserted,
                        'updated' => $updated,
                        'skipped' => $skipped,
                        'percentage' => $progressPercent,
                        'message' => "Processed $processed / $totalRecords records ($progressPercent%)"
                    ]);
                }

            } catch (Exception $e) {
                $errors[] = "Error processing unit {$ou['id']}: " . $e->getMessage();
                error_log("Sync error for unit {$ou['id']}: " . $e->getMessage());
            }
        }

        $page++;
        unset($orgUnits, $orgUnitsResp); // Free memory

    } while ($page <= $totalPages);

    // Commit any remaining transactions
    $pdo->commit();

    // Step 5: Second pass to update any parent_id that couldn't be resolved initially
    $unresolvedStmt = $pdo->prepare("
        SELECT l1.id, l1.uid, l1.path 
        FROM location l1 
        WHERE l1.instance_key = ? AND l1.parent_id IS NULL AND l1.hierarchylevel > 1
    ");
    $unresolvedStmt->execute([$instanceKey]);
    
    $resolved = 0;
    while ($unresolved = $unresolvedStmt->fetch(PDO::FETCH_ASSOC)) {
        $pathParts = array_filter(explode('/', $unresolved['path']));
        if (count($pathParts) > 1) {
            $parentUid = $pathParts[count($pathParts) - 2];
            if (isset($uidToIdMap[$parentUid])) {
                $updateParentStmt = $pdo->prepare("UPDATE location SET parent_id = ? WHERE id = ?");
                $updateParentStmt->execute([$uidToIdMap[$parentUid], $unresolved['id']]);
                $resolved++;
            }
        }
    }

    // Determine success type
    $isPartialSuccess = $processed > 0 && ($page == 1 || $totalRecords == 0); // Got some data but may have timed out
    $successType = ($processed > 0) ? 'complete' : 'warning';
    
    // Send completion message
    sendProgress([
        'type' => $successType,
        'success' => true,
        'partial_success' => $isPartialSuccess,
        'summary' => [
            'processed' => $processed,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => count($errors),
            'parent_relationships_resolved' => $resolved,
            'levels_synced' => $fullHierarchy ? 'All levels' : implode(', ', $syncLevels),
            'sync_mode' => $syncMode,
            'pages_processed' => $page - 1,
            'timeout_occurred' => $isPartialSuccess
        ],
        'errors' => $errors,
        'message' => $processed > 0 ? 
            "Sync completed! Processed $processed records" : 
            "Sync completed but no records were processed due to server timeout"
    ]);

} catch (Exception $e) {
    error_log("DHIS2 hierarchy sync error: " . $e->getMessage());
    sendProgress([
        'type' => 'error',
        'success' => false,
        'error' => 'Sync failed: ' . $e->getMessage()
    ]);
}
?>