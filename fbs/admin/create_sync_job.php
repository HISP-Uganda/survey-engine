<?php
// create_sync_job.php - Creates a sync job from the form submission
session_start();
require 'connect.php'; // Ensure this connects to your database

header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Initialize response
$response = [
    'status' => 'error',
    'message' => 'Invalid request',
    'job_id' => null
];

// Validate input
if (!isset($_POST['dhis2_instance']) || empty($_POST['dhis2_instance'])) {
    $response['message'] = 'DHIS2 instance is required';
    outputResponse($response);
}

// Get instance and org level
$instanceKey = $_POST['dhis2_instance']; // Renamed to instanceKey for clarity
$org_level = $_POST['org_level'] ?? null;

// Determine selection type
$selectionType = $_POST['selection_type'] ?? 'manual';

// Get selected organization units as JSON-encoded objects
$selectedUnitsRaw = $_POST['selected_orgunits'] ?? [];

if (empty($selectedUnitsRaw) || !is_array($selectedUnitsRaw)) {
    $response['message'] = 'No organization units selected';
    outputResponse($response);
}

// Parse and validate orgunit objects
$selectedUnits = [];
foreach ($selectedUnitsRaw as $jsonStr) {
    $unit = json_decode($jsonStr, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($unit['uid'], $unit['name'])) {
        // Accept only if at least uid and name are present
        $selectedUnits[] = [
            'uid'        => $unit['uid'],
            'name'       => $unit['name'],
            'path'       => $unit['path'] ?? '',
            'level'      => $unit['level'] ?? '',
            'parent_uid' => $unit['parent_uid'] ?? ''
        ];
    }
}

if (empty($selectedUnits)) {
    $response['message'] = 'No valid organisation units found in selection';
    outputResponse($response);
}

// Create unique job ID
$jobId = uniqid('sync_');

// Create temp directory if it doesn't exist
$storageDir = __DIR__ . '/temp';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0755, true);
}

// Store job data (for processor and monitor)
$_SESSION['sync_jobs'][$jobId] = [
    'id'           => $jobId,
    'instance_key' => $instanceKey, // Store instance_key in session job data
    'org_level'    => $org_level,
    'total'        => count($selectedUnits),
    'processed'    => 0,
    'inserted'     => 0,
    'updated'      => 0,
    'errors'       => 0,
    'status'       => 'ready',
    'selection_type' => $selectionType,
    'created_at'   => date('Y-m-d H:i:s'),
    'start_time' => date('Y-m-d H:i:s') 

];

// Store units list in a file for processing (save as JSON for sync_processor)
// We no longer need this as `sync_processor.php` will read from CSV directly
// $jobData = [
//     'instance' => $instanceKey,
//     'units' => $selectedUnits,
//     'selection_type' => $selectionType,
//     'org_level' => $org_level
// ];
// $unitsFile = $storageDir . '/' . $jobId . '_units.json';
// file_put_contents($unitsFile, json_encode($jobData));

// Write to CSV for processing, INCLUDING the instance_key
$csvFile = $storageDir . '/' . $jobId . '_data.csv';
$fp = fopen($csvFile, 'w');
fputcsv($fp, ['instance_key', 'uid', 'name', 'path', 'level', 'parent_uid']); // Add instance_key to header
foreach ($selectedUnits as $unit) {
    fputcsv($fp, [
        $instanceKey, // Add the instance_key here
        $unit['uid'],
        $unit['name'],
        $unit['path'],
        $unit['level'],
        $unit['parent_uid']
    ]);
}
fclose($fp);

// Return success response
$response['status'] = 'success';
$response['message'] = 'Sync job created successfully';
$response['job_id'] = $jobId;
outputResponse($response);

/**
 * Output JSON response and exit
 * * @param array $response Response data
 * @return void
 */
function outputResponse($response) {
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}