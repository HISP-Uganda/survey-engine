<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../../admin/connect.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'POST') {
        // Handle file upload
        $surveyId = $_POST['survey_id'] ?? null;
        $questionId = $_POST['question_id'] ?? null;
        $submissionId = $_POST['submission_id'] ?? 0; // Default to 0 for new uploads
        
        if (!$surveyId || !$questionId) {
            throw new Exception('Survey ID and Question ID are required');
        }
        
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No file uploaded or upload error');
        }
        
        $file = $_FILES['file'];
        $allowedTypes = ['text/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        
        if (!in_array($file['type'], $allowedTypes)) {
            throw new Exception('Only CSV and Excel files are allowed');
        }
        
        // Create upload directory if it doesn't exist
        $uploadDir = '../../admin/uploads/tracker_files/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Generate unique filename
        $timestamp = time();
        $randomId = rand(1000, 9999);
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = "tracker_{$timestamp}_{$randomId}_{$questionId}_{$surveyId}.{$extension}";
        $filePath = $uploadDir . $fileName;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            throw new Exception('Failed to save file');
        }
        
        // Save file info to database (using existing table structure)
        $stmt = $pdo->prepare("
            INSERT INTO tracker_file_uploads (submission_id, field_name, original_filename, saved_filename, file_path, file_size, mime_type, uploaded_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
            original_filename = VALUES(original_filename),
            saved_filename = VALUES(saved_filename),
            file_path = VALUES(file_path),
            file_size = VALUES(file_size),
            mime_type = VALUES(mime_type),
            uploaded_at = NOW()
        ");
        
        $stmt->execute([
            $submissionId,
            $questionId,
            $file['name'],
            $fileName,
            $filePath,
            $file['size'],
            $file['type']
        ]);
        
        $uploadId = $submissionId ? $pdo->lastInsertId() : $pdo->lastInsertId();
        
        // Read and preview file content
        $preview = null;
        if (in_array($file['type'], ['text/csv'])) {
            $content = file_get_contents($filePath);
            $lines = explode("\n", $content);
            $preview = array_slice($lines, 0, 6); // First 6 lines including header
        }
        
        echo json_encode([
            'success' => true,
            'upload_id' => $uploadId,
            'filename' => $file['name'],
            'saved_filename' => $fileName,
            'size' => $file['size'],
            'preview' => $preview
        ]);
        
    } elseif ($method === 'GET') {
        // Retrieve file info
        $surveyId = $_GET['survey_id'] ?? null;
        $questionId = $_GET['question_id'] ?? null;
        $submissionId = $_GET['submission_id'] ?? 0;
        
        if (!$surveyId || !$questionId) {
            throw new Exception('Survey ID and Question ID are required');
        }
        
        $stmt = $pdo->prepare("
            SELECT * FROM tracker_file_uploads 
            WHERE field_name = ? AND (submission_id = ? OR submission_id = 0)
            ORDER BY uploaded_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$questionId, $submissionId]);
        $fileInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($fileInfo) {
            // Check if file still exists
            if (file_exists($fileInfo['file_path'])) {
                echo json_encode([
                    'success' => true,
                    'file' => $fileInfo
                ]);
            } else {
                // File deleted, remove from database
                $deleteStmt = $pdo->prepare("DELETE FROM tracker_file_uploads WHERE id = ?");
                $deleteStmt->execute([$fileInfo['id']]);
                
                echo json_encode([
                    'success' => false,
                    'error' => 'File no longer exists'
                ]);
            }
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'No file found'
            ]);
        }
        
    } elseif ($method === 'DELETE') {
        // Delete file
        $uploadId = $_GET['upload_id'] ?? null;
        
        if (!$uploadId) {
            throw new Exception('Upload ID is required');
        }
        
        $stmt = $pdo->prepare("SELECT * FROM tracker_file_uploads WHERE id = ?");
        $stmt->execute([$uploadId]);
        $fileInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($fileInfo) {
            // Delete physical file
            if (file_exists($fileInfo['file_path'])) {
                unlink($fileInfo['file_path']);
            }
            
            // Delete database record
            $deleteStmt = $pdo->prepare("DELETE FROM tracker_file_uploads WHERE id = ?");
            $deleteStmt->execute([$uploadId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'File deleted successfully'
            ]);
        } else {
            throw new Exception('File not found');
        }
        
    } else {
        throw new Exception('Method not allowed');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>