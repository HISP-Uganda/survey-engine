<?php
session_start();
require_once '../connect.php';

header('Content-Type: application/json');

// Check if file was uploaded
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error']);
    exit;
}

$file = $_FILES['image'];
$survey_id = $_POST['survey_id'] ?? null;
$image_number = $_POST['image_number'] ?? null;

if (!$survey_id || !$image_number) {
    echo json_encode(['success' => false, 'error' => 'Missing survey_id or image_number']);
    exit;
}

// Validate file type
$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($file['type'], $allowedTypes)) {
    echo json_encode(['success' => false, 'error' => 'Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed.']);
    exit;
}

// Validate file size (max 5MB)
$maxSize = 5 * 1024 * 1024; // 5MB
if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'error' => 'File too large. Maximum size is 5MB.']);
    exit;
}

// Create upload directory
$uploadDir = '../asets/img/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate unique filename
$timestamp = time();
$randomId = bin2hex(random_bytes(4));
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$baseName = pathinfo($file['name'], PATHINFO_FILENAME);
$fileName = "uploaded_{$baseName}_{$survey_id}_{$image_number}_{$timestamp}_{$randomId}.{$extension}";
$filePath = $uploadDir . $fileName;

// Move uploaded file
if (move_uploaded_file($file['tmp_name'], $filePath)) {
    $relativePath = "asets/img/{$fileName}";
    
    echo json_encode([
        'success' => true,
        'filename' => $fileName,
        'path' => $relativePath,
        'url' => "/fbs/admin/{$relativePath}"
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file']);
}
?>