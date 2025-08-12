<?php
/**
 * Helper functions for file upload management in tracker forms
 */

/**
 * Handle FILE_RESOURCE file uploads (CSV/Excel)
 * @param array $files $_FILES array
 * @param int $submissionId Tracker submission ID
 * @param array $formData Form data containing file fields
 * @return array Result with success status and file paths
 */
function handleTrackerFileUploads($files, $submissionId, $formData) {
    $uploadDir = __DIR__ . "/../uploads/tracker_files/";
    $allowedTypes = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
        'application/vnd.ms-excel', // .xls
        'text/csv', // .csv
        'application/csv'
    ];
    $maxSize = 10 * 1024 * 1024; // 10MB
    $uploadedFiles = [];
    
    // Create upload directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Check if any files were uploaded via the form
    if (empty($files)) {
        return ['success' => true, 'files' => [], 'message' => 'No files to upload'];
    }
    
    foreach ($files as $fieldName => $file) {
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            continue; // Skip if no file uploaded for this field
        }
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => "Upload error for field $fieldName"];
        }
        
        if ($file['size'] > $maxSize) {
            return ['success' => false, 'message' => "File too large for field $fieldName. Maximum 10MB allowed."];
        }
        
        if (!in_array($file['type'], $allowedTypes)) {
            return ['success' => false, 'message' => "Invalid file type for field $fieldName. Only CSV and Excel files allowed."];
        }
        
        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'tracker_' . $submissionId . '_' . $fieldName . '_' . time() . '.' . $extension;
        $filepath = $uploadDir . $filename;
        
        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            $uploadedFiles[$fieldName] = [
                'original_name' => $file['name'],
                'saved_filename' => $filename,
                'file_path' => $filepath,
                'file_size' => $file['size'],
                'mime_type' => $file['type']
            ];
        } else {
            return ['success' => false, 'message' => "Failed to save file for field $fieldName"];
        }
    }
    
    return ['success' => true, 'files' => $uploadedFiles, 'message' => 'Files uploaded successfully'];
}

/**
 * Get uploaded files for a tracker submission
 * @param int $submissionId Tracker submission ID
 * @param PDO $pdo Database connection
 * @return array List of uploaded files
 */
function getTrackerUploadedFiles($submissionId, $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM tracker_file_uploads WHERE submission_id = ? ORDER BY uploaded_at DESC");
        $stmt->execute([$submissionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Log uploaded file to database
 * @param int $submissionId Tracker submission ID
 * @param string $fieldName Form field name
 * @param array $fileInfo File information
 * @param PDO $pdo Database connection
 */
function logTrackerFileUpload($submissionId, $fieldName, $fileInfo, $pdo) {
    try {
        // Create table if it doesn't exist
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tracker_file_uploads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                submission_id INT NOT NULL,
                field_name VARCHAR(255) NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                saved_filename VARCHAR(255) NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                file_size INT NOT NULL,
                mime_type VARCHAR(100),
                uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_submission_id (submission_id),
                INDEX idx_field_name (field_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        $stmt = $pdo->prepare("
            INSERT INTO tracker_file_uploads 
            (submission_id, field_name, original_filename, saved_filename, file_path, file_size, mime_type) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $submissionId,
            $fieldName,
            $fileInfo['original_name'],
            $fileInfo['saved_filename'],
            $fileInfo['file_path'],
            $fileInfo['file_size'],
            $fileInfo['mime_type']
        ]);
        
    } catch (Exception $e) {
        error_log("Failed to log tracker file upload: " . $e->getMessage());
    }
}

/**
 * Upload file to DHIS2 as FILE_RESOURCE
 * @param string $filePath Local file path
 * @param string $originalName Original filename
 * @param string $instanceKey DHIS2 instance key
 * @return string|null DHIS2 file resource UID or null on failure
 */
function uploadFileToDHIS2($filePath, $originalName, $instanceKey) {
    try {
        if (!file_exists($filePath)) {
            error_log("File not found for DHIS2 upload: $filePath");
            return null;
        }
        
        // Use cURL to upload file to DHIS2 fileResources endpoint
        $dhis2Config = getDhis2Config($instanceKey);
        if (!$dhis2Config) {
            error_log("DHIS2 config not found for instance: $instanceKey");
            return null;
        }
        
        $url = rtrim($dhis2Config['url'], '/') . '/api/fileResources';
        
        $postFields = [
            'file' => new CURLFile($filePath, mime_content_type($filePath), $originalName)
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode($dhis2Config['username'] . ':' . $dhis2Config['password'])
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("DHIS2 file upload cURL error: $error");
            return null;
        }
        
        if ($httpCode !== 202) { // DHIS2 returns 202 for file uploads
            error_log("DHIS2 file upload failed. HTTP Code: $httpCode, Response: $response");
            return null;
        }
        
        $responseData = json_decode($response, true);
        if (isset($responseData['response']['fileResource']['id'])) {
            $fileResourceUID = $responseData['response']['fileResource']['id'];
            error_log("File uploaded to DHIS2 successfully: $originalName -> $fileResourceUID");
            return $fileResourceUID;
        } else {
            error_log("Unexpected DHIS2 file upload response: $response");
            return null;
        }
        
    } catch (Exception $e) {
        error_log("Exception during DHIS2 file upload: " . $e->getMessage());
        return null;
    }
}

/**
 * Update form data with DHIS2 file resource UIDs
 * @param array $formData Original form data
 * @param array $dhis2FileResources Array of field_name => file_resource_uid
 * @return array Updated form data
 */
function updateFormDataWithFileResources($formData, $dhis2FileResources) {
    // Update events with file resource UIDs
    if (isset($formData['events'])) {
        foreach ($formData['events'] as $eventKey => &$event) {
            if (isset($event['dataValues'])) {
                foreach ($dhis2FileResources as $fieldName => $fileResourceUID) {
                    // Extract data element ID from field name (format: modal_DEID_index)
                    if (preg_match('/^modal_([^_]+)_\d+$/', $fieldName, $matches)) {
                        $deId = $matches[1];
                        if (isset($event['dataValues'][$deId])) {
                            error_log("Updating field $fieldName (DE: $deId) with DHIS2 file UID: $fileResourceUID");
                            $event['dataValues'][$deId] = $fileResourceUID;
                        }
                    } else {
                        // Handle direct field name match (backward compatibility)
                        if (isset($event['dataValues'][$fieldName])) {
                            error_log("Updating field $fieldName with DHIS2 file UID: $fileResourceUID");
                            $event['dataValues'][$fieldName] = $fileResourceUID;
                        }
                    }
                }
            }
        }
    }
    
    // Update tracked entity attributes with file resource UIDs
    if (isset($formData['trackedEntityAttributes'])) {
        foreach ($dhis2FileResources as $fieldName => $fileResourceUID) {
            // Extract attribute ID from field name (format: tei_ATTRIBUTEID_index or modal_ATTRIBUTEID_index)
            if (preg_match('/^(?:tei|modal)_([^_]+)_\d+$/', $fieldName, $matches)) {
                $attributeId = $matches[1];
                if (isset($formData['trackedEntityAttributes'][$attributeId])) {
                    error_log("Updating TEA $fieldName (Attr: $attributeId) with DHIS2 file UID: $fileResourceUID");
                    $formData['trackedEntityAttributes'][$attributeId] = $fileResourceUID;
                }
            } else {
                // Handle direct field name match (backward compatibility)
                if (isset($formData['trackedEntityAttributes'][$fieldName])) {
                    error_log("Updating TEA $fieldName with DHIS2 file UID: $fileResourceUID");
                    $formData['trackedEntityAttributes'][$fieldName] = $fileResourceUID;
                }
            }
        }
    }
    
    return $formData;
}
?>