<?php
// dhis2/ajax_get_orgunit_levels.php

ini_set('display_errors', 0); // Hide errors from direct output for AJAX
error_reporting(E_ALL);
ini_set('log_errors', 1); // Log errors to php-error.log
ini_set('error_log', __DIR__ . '/php-error.log'); // Make sure this path is writable

require_once __DIR__ . '/dhis2_shared.php'; // Path to dhis2_shared.php
header('Content-Type: application/json');

$instance = $_GET['instance'] ?? $_POST['instance'] ?? ''; // Support both GET and POST for flexibility
if (!$instance) {
    echo json_encode([
        'success' => false,
        'error' => 'No instance key provided.',
        'levels' => []
    ]);
    exit;
}

$config = getDhis2Config($instance);
if (!$config) {
    echo json_encode([
        'success' => false,
        'error' => 'Instance not found or not active for key: ' . $instance,
        'levels' => []
    ]);
    exit;
}

$levelsResp = dhis2_get('organisationUnitLevels?fields=id,level,displayName', $instance);

$out = [];
if (!empty($levelsResp['organisationUnitLevels'])) {
    foreach ($levelsResp['organisationUnitLevels'] as $lvl) {
        if (isset($lvl['level'], $lvl['displayName'])) {
            $out[] = [
                'level' => $lvl['level'],
                'displayName' => $lvl['displayName']
            ];
        }
    }
}

if (!empty($out)) {
    // Sort levels numerically for better presentation in the dropdown
    usort($out, function($a, $b) {
        return $a['level'] <=> $b['level'];
    });

    echo json_encode([
        'success' => true,
        'levels' => $out
    ]);
    exit;
} else {
    echo json_encode([
        'success' => false,
        'error' => 'No org unit levels found or API call failed.',
        'raw_response' => $levelsResp // Include raw response for debugging in console if needed
    ]);
    exit;
}