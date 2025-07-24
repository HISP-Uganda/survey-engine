<?php
// dhis2/ajax_get_programs_datasets.php
session_start();
require_once __DIR__ . '/dhis2_shared.php'; // Path to dhis2_shared.php

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0); // Hide errors from direct output for AJAX
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');

$response = ['success' => false, 'data' => [], 'message' => ''];

$instanceKey = $_GET['instance'] ?? '';
$domain = $_GET['domain'] ?? '';

if (empty($instanceKey) || empty($domain)) {
    $response['message'] = 'Missing instance or domain.';
    echo json_encode($response);
    exit;
}

$dhis2ConfigDetails = getDhis2Config($instanceKey);
if (!$dhis2ConfigDetails) {
    $response['message'] = 'DHIS2 instance configuration not found.';
    error_log("ajax_get_programs_datasets.php: Config not found for instance: " . $instanceKey);
    echo json_encode($response);
    exit;
}

try {
    if ($domain === 'tracker') {
        $programs = dhis2_get('/programs?fields=id,name', $instanceKey);
        $response['data'] = $programs['programs'] ?? [];
        $response['success'] = true;
    } elseif ($domain === 'aggregate') {
        $datasets = dhis2_get('/dataSets?fields=id,name', $instanceKey);
        $response['data'] = $datasets['dataSets'] ?? [];
        $response['success'] = true;
    } else {
        $response['message'] = 'Invalid domain.';
    }
} catch (Exception $e) {
    $response['message'] = 'Error fetching data: ' . $e->getMessage();
    error_log("ajax_get_programs_datasets.php: " . $e->getMessage());
}

echo json_encode($response);
exit;