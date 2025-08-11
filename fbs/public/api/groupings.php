<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../../admin/connect.php';

$surveyId = $_GET['survey_id'] ?? null;

if (!$surveyId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Survey ID is required']);
    exit();
}

try {
    // For now, return empty groupings to allow default layout
    // This can be enhanced later to actually fetch groupings
    echo json_encode([
        'success' => true,
        'data' => []
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
?>