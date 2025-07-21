<?php
// sync_processor.php - Processes DHIS2 sync in smaller batches
header('Content-Type: application/json');
session_start();
set_time_limit(30); // Set a reasonable timeout for each batch

// Required files
require 'connect.php';
require 'dhis2/dhis2_shared.php';
require 'dhis2/dhis2_get_function.php';

// Process request parameters
$jobId = $_GET['job_id'] ?? null;
$offset = (int)($_GET['offset'] ?? 0);
$batchSize = 20; // Increased batch size for better performance

// Initialize response
$response = [
    'status' => 'error',
    'message' => 'Invalid request',
    'processed' => $offset,
    'total' => 0,
    'inserted' => 0,
    'updated' => 0,
    'errors' => 0
];

// Validate job
if (!$jobId || !isset($_SESSION['sync_jobs'][$jobId])) {
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Get job information
$job = &$_SESSION['sync_jobs'][$jobId];
$response['total'] = $job['total'];

// Define status constants
define('STATUS_READY', 'ready');
define('STATUS_PROCESSING', 'processing');
define('STATUS_IMPORTING', 'importing');
define('STATUS_COMPLETE', 'complete');
define('STATUS_ERROR', 'error');

try {
    // Check if this is the first batch
    if ($offset === 0 && $job['status'] === STATUS_READY) {
        $job['status'] = STATUS_PROCESSING;
    }

    // Load job data
    $storageDir = __DIR__ . '/temp';
    $unitsFile = $storageDir . '/' . $jobId . '_units.json';
    $csvFile = $storageDir . '/' . $jobId . '_data.csv';
    
    if (!file_exists($unitsFile)) {
        throw new Exception("Job data not found");
    }
    
    $jobData = json_decode(file_get_contents($unitsFile), true);
    if (!$jobData || !isset($jobData['units']) || !isset($jobData['instance'])) {
        throw new Exception("Invalid job data format");
    }
    
    // Process the current batch
    $currentBatch = array_slice($jobData['units'], $offset, $batchSize);
    $instance = $jobData['instance'];
    
    if (empty($currentBatch)) {
        // All units have been processed, time to import the CSV
        if (!isset($job['import_started'])) {
            // Mark import as started
            $job['import_started'] = true;
            $job['status'] = STATUS_IMPORTING;
            $response['status'] = STATUS_IMPORTING;
            $response['message'] = 'Starting database import';
        } else {
            // Perform the import
            importCSVToDatabase($csvFile, $job, $pdo);
            $job['status'] = STATUS_COMPLETE;
            $response['status'] = STATUS_COMPLETE;
            $response['message'] = 'Import completed successfully';
        }
    } else {
        // Process this batch of organization units
        if (!file_exists($csvFile) && $offset === 0) {
            // Create CSV file with header row for the first batch
            $fp = fopen($csvFile, 'w');
            fputcsv($fp, ['uid', 'name', 'path', 'level', 'parent_uid']);
            fclose($fp);
        }
        
        $fp = fopen($csvFile, 'a');
        
        foreach ($currentBatch as $unitId) {
            try {
                // Fetch org unit details from DHIS2
                $unitDetails = dhis2_get('/api/organisationUnits/' . $unitId . 
                                    '.json?fields=id,name,path,level,parent[id]', $instance);
                
                if (!$unitDetails) {
                    $job['errors']++;
                    continue;
                }
                
                // Write to CSV
                fputcsv($fp, [
                    $unitDetails['id'],
                    $unitDetails['name'],
                    $unitDetails['path'] ?? '',
                    $unitDetails['level'] ?? 0,
                    isset($unitDetails['parent']['id']) ? $unitDetails['parent']['id'] : ''
                ]);
                
                $job['processed']++;
                
            } catch (Exception $e) {
                $job['errors']++;
                error_log("Error processing org unit {$unitId}: " . $e->getMessage());
            }
        }
        
        fclose($fp);
        
        // Update response
        $response['status'] = STATUS_PROCESSING;
        $response['processed'] = $offset + count($currentBatch);
        $response['message'] = "Processing batch " . ceil($response['processed'] / $batchSize) . 
                             " of " . ceil($job['total'] / $batchSize);
    }
    
} catch (Exception $e) {
    $job['status'] = STATUS_ERROR;
    $response['status'] = STATUS_ERROR;
    $response['message'] = $e->getMessage();
    error_log("Sync error: " . $e->getMessage());
}

// Return current progress
$response['inserted'] = $job['inserted'] ?? 0;
$response['updated'] = $job['updated'] ?? 0;
$response['errors'] = $job['errors'] ?? 0;
$response['status'] = $job['status'];
$response['progress'] = $job['total'] > 0 ? round(($response['processed'] / $job['total']) * 100) : 0;

header('Content-Type: application/json');
echo json_encode($response);
exit;

/**
 * Import CSV data to database
 * 
 * @param string $csvFile Path to CSV file
 * @param array &$job Job reference
 * @param PDO $pdo Database connection
 * @return void
 * @throws Exception
 */
function importCSVToDatabase($csvFile, &$job, $pdo) {
    try {
        // Set execution time to 5 minutes for the import phase
        set_time_limit(300);
        
        // Read CSV file
        $data = [];
        $parentMappings = [];
        $row = 0;
        
        if (($handle = fopen($csvFile, "r")) !== FALSE) {
            while (($csvRow = fgetcsv($handle, 1000, ",")) !== FALSE) {
                // Skip header row
                if ($row == 0) {
                    $row++;
                    continue;
                }
                
                // Parse the CSV row
                $locationData = [
                    'uid' => $csvRow[0],
                    'name' => $csvRow[1],
                    'path' => $csvRow[2],
                    'hierarchylevel' => $csvRow[3],
                    'parent_uid' => isset($csvRow[4]) ? $csvRow[4] : null
                ];
                
                $data[] = $locationData;
                
                // Track parent relationships
                if (!empty($locationData['parent_uid'])) {
                    $parentMappings[$locationData['uid']] = $locationData['parent_uid'];
                }
                
                $row++;
            }
            fclose($handle);
        }
        
        // Begin transaction
        $pdo->beginTransaction();
        
        // Prepare statements for better performance
        $insertStmt = $pdo->prepare("INSERT INTO location (uid, name, path, hierarchylevel) 
                                    VALUES (?, ?, ?, ?) 
                                    ON DUPLICATE KEY UPDATE 
                                    name = VALUES(name), 
                                    path = VALUES(path), 
                                    hierarchylevel = VALUES(hierarchylevel)");
        
        // Track counts
        $insertCount = 0;
        $updateCount = 0;
        
        // Process in batches
        $batchSize = 100;
        $batchCount = ceil(count($data) / $batchSize);
        
        for ($i = 0; $i < $batchCount; $i++) {
            $batch = array_slice($data, $i * $batchSize, $batchSize);
            
            foreach ($batch as $item) {
                $insertStmt->execute([
                    $item['uid'], 
                    $item['name'], 
                    $item['path'], 
                    $item['hierarchylevel']
                ]);
                
                // Count inserts vs updates based on row count
                if ($insertStmt->rowCount() === 1) {
                    $insertCount++;
                } else {
                    $updateCount++;
                }
            }
        }
        
        // First, build a mapping of UIDs to database IDs
        $uidToIdMap = [];
        $uids = array_unique(array_merge(array_keys($parentMappings), array_values($parentMappings)));
        
        // Break this into chunks to avoid too large queries
        $chunkSize = 500;
        $uidChunks = array_chunk($uids, $chunkSize);
        
        foreach ($uidChunks as $uidChunk) {
            $placeholders = str_repeat('?,', count($uidChunk) - 1) . '?';
            $query = "SELECT id, uid FROM location WHERE uid IN ($placeholders)";
            $stmt = $pdo->prepare($query);
            $stmt->execute($uidChunk);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $uidToIdMap[$row['uid']] = $row['id'];
            }
        }
        
        // Now update parent_id values
        $updateParentStmt = $pdo->prepare("UPDATE location SET parent_id = ? WHERE uid = ?");
        
        foreach ($parentMappings as $childUid => $parentUid) {
            if (isset($uidToIdMap[$childUid]) && isset($uidToIdMap[$parentUid])) {
                $updateParentStmt->execute([$uidToIdMap[$parentUid], $childUid]);
            }
        }
        
        // Commit transaction
        $pdo->commit();
        
        // Update job statistics
        $job['inserted'] = $insertCount;
        $job['updated'] = $updateCount;
        $job['status'] = STATUS_COMPLETE;
        
        // Clean up files
        @unlink($csvFile);
        @unlink(str_replace('_data.csv', '_units.json', $csvFile));
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        $job['status'] = STATUS_ERROR;
        throw new Exception("Database import failed: " . $e->getMessage());
    }
}