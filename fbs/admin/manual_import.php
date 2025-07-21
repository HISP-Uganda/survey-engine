<?php
// manual_import.php - Alternative approach without LOAD DATA LOCAL INFILE
session_start();
require 'connect.php';

$storageDir = __DIR__ . '/temp';
$files = glob($storageDir . '/*_data.csv');

if (empty($files)) {
    echo "No CSV files found to import.";
    exit;
}

// Sort by modification time, newest first
usort($files, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

$csvFile = $files[0]; // Get the most recent file

try {
    // Set execution time to 10 minutes for large imports
    set_time_limit(600);
    
    echo "Starting import of file: " . basename($csvFile) . "<br>";
    echo "This may take a few minutes...<br>";
    flush();
    
    // Read CSV file
    $row = 0;
    $data = [];
    $parentMappings = [];
    
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
    
    $totalRows = count($data);
    echo "Found $totalRows records to import<br>";
    flush();
    
    // Begin transaction
    $pdo->beginTransaction();
    
    // Prepare the statements for better performance
    $insertStmt = $pdo->prepare("INSERT INTO location (uid, name, path, hierarchylevel) 
                                VALUES (?, ?, ?, ?) 
                                ON DUPLICATE KEY UPDATE 
                                name = VALUES(name), 
                                path = VALUES(path), 
                                hierarchylevel = VALUES(hierarchylevel)");
    
    // Process in batches to prevent memory issues
    $batchSize = 100;
    $batchCount = ceil(count($data) / $batchSize);
    
    $processed = 0;
    for ($i = 0; $i < $batchCount; $i++) {
        $batch = array_slice($data, $i * $batchSize, $batchSize);
        
        foreach ($batch as $item) {
            $insertStmt->execute([
                $item['uid'], 
                $item['name'], 
                $item['path'], 
                $item['hierarchylevel']
            ]);
        }
        
        $processed += count($batch);
        echo "Processed $processed of $totalRows records<br>";
        flush();
    }
    
    echo "All location records inserted/updated<br>";
    flush();
    
    // Now update parent relationships
    echo "Creating parent-child relationships...<br>";
    flush();
    
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
    $updateCount = 0;
    
    foreach ($parentMappings as $childUid => $parentUid) {
        if (isset($uidToIdMap[$childUid]) && isset($uidToIdMap[$parentUid])) {
            $updateParentStmt->execute([$uidToIdMap[$parentUid], $childUid]);
            $updateCount++;
            
            if ($updateCount % 100 == 0) {
                echo "Updated $updateCount parent relationships<br>";
                flush();
            }
        }
    }
    
    echo "All parent relationships updated ($updateCount total)<br>";
    flush();
    
    // Commit transaction
    $pdo->commit();
    echo "Transaction committed successfully!<br>";
    
    echo "<strong>Import completed successfully!</strong>";
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo "Error: " . $e->getMessage();
}
?>