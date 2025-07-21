<?php
// create_sync_job.php - Creates a sync job from the form submission
session_start();
require 'connect.php';

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

// Get instance
$instance = $_POST['dhis2_instance'];

// Determine selection type
$selectionType = $_POST['selection_type'] ?? 'none';

// Get selected organization units
$selectedUnits = $_POST['selected_orgunits'] ?? [];

// Check if any units are selected
if (empty($selectedUnits)) {
    $response['message'] = 'No organization units selected';
    outputResponse($response);
}

// Create unique job ID
$jobId = uniqid('sync_');

// Create temp directory if it doesn't exist
$storageDir = __DIR__ . '/temp';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0755, true);
}

// Store job data
$_SESSION['sync_jobs'][$jobId] = [
    'id' => $jobId,
    'instance' => $instance,
    'total' => count($selectedUnits),
    'processed' => 0,
    'inserted' => 0,
    'updated' => 0,
    'errors' => 0,
    'status' => 'ready',
    'selection_type' => $selectionType,
    'created_at' => date('Y-m-d H:i:s')
];

// Store units list in a file for processing
$jobData = [
    'instance' => $instance,
    'units' => $selectedUnits,
    'selection_type' => $selectionType
];

$unitsFile = $storageDir . '/' . $jobId . '_units.json';
file_put_contents($unitsFile, json_encode($jobData));

// Create empty CSV file for data
$csvFile = $storageDir . '/' . $jobId . '_data.csv';
$fp = fopen($csvFile, 'w');
fputcsv($fp, ['uid', 'name', 'path', 'level', 'parent_uid']);
fclose($fp);

// Return success response
$response['status'] = 'success';
$response['message'] = 'Sync job created successfully';
$response['job_id'] = $jobId;
outputResponse($response);

/**
 * Output JSON response and exit
 * 
 * @param array $response Response data
 * @return void
 */
function outputResponse($response) {
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}