<?php
session_start();

header('Content-Type: application/json');

require_once '../admin/connect.php';
require_once '../admin/dhis2/dhis2_shared.php';
require_once '../admin/includes/file_upload_helper.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Handle both JSON and FormData submissions
if ($_SERVER['CONTENT_TYPE'] && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    // JSON submission (legacy)
    $input = file_get_contents('php://input');
    $requestData = json_decode($input, true);
} else {
    // FormData submission (with file uploads)
    $requestData = [
        'survey_id' => $_POST['survey_id'] ?? null,
        'form_data' => isset($_POST['form_data']) ? json_decode($_POST['form_data'], true) : null,
        'location_data' => isset($_POST['location_data']) ? json_decode($_POST['location_data'], true) : []
    ];
}

if (!$requestData || !$requestData['survey_id'] || !$requestData['form_data']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request data']);
    exit;
}

try {
    $surveyId = $requestData['survey_id'];
    $formData = $requestData['form_data'];
    $locationData = $requestData['location_data'] ?? [];
    
    if (!$surveyId || !$formData) {
        throw new Exception('Missing required data: survey_id or form_data');
    }
    
    if (empty($locationData['orgunit_uid'])) {
        throw new Exception('Missing location/orgunit selection');
    }
    
    // Get survey and DHIS2 configuration
    $stmt = $pdo->prepare("SELECT * FROM survey WHERE id = ?");
    $stmt->execute([$surveyId]);
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$survey) {
        throw new Exception('Survey not found');
    }
    
    // Get DHIS2 instance key 
    $instanceKey = $survey['dhis2_instance'];
    if (empty($instanceKey)) {
        throw new Exception('No DHIS2 instance configured for this survey');
    }
    
    // Verify DHIS2 configuration exists (using shared function)
    $dhis2Config = getDhis2Config($instanceKey);
    if (!$dhis2Config) {
        throw new Exception('DHIS2 instance configuration not found or inactive: ' . $instanceKey);
    }
    
    // Process file uploads if any
    $uploadedFiles = [];
    $dhis2FileResources = [];
    
    if (!empty($_FILES['files'])) {
        error_log("Processing file uploads: " . json_encode(array_keys($_FILES['files']['name'])));
        error_log("Total file fields received: " . count($_FILES['files']['name']));
        
        // Reorganize $_FILES structure for easier processing
        $files = [];
        foreach ($_FILES['files']['name'] as $fieldName => $fileName) {
            error_log("Processing file field: $fieldName -> $fileName (Error: " . $_FILES['files']['error'][$fieldName] . ")");
            if ($_FILES['files']['error'][$fieldName] === UPLOAD_ERR_OK) {
                $files[$fieldName] = [
                    'name' => $_FILES['files']['name'][$fieldName],
                    'type' => $_FILES['files']['type'][$fieldName],
                    'tmp_name' => $_FILES['files']['tmp_name'][$fieldName],
                    'error' => $_FILES['files']['error'][$fieldName],
                    'size' => $_FILES['files']['size'][$fieldName]
                ];
                error_log("Successfully reorganized file: $fieldName");
            } else {
                error_log("File upload error for $fieldName: " . $_FILES['files']['error'][$fieldName]);
            }
        }
        
        if (!empty($files)) {
            // Generate temporary submission ID for file naming
            $tempSubmissionId = time() . '_' . mt_rand(1000, 9999);
            
            // Handle local file uploads
            $uploadResult = handleTrackerFileUploads($files, $tempSubmissionId, $formData);
            
            if ($uploadResult['success']) {
                $uploadedFiles = $uploadResult['files'];
                error_log("Files uploaded locally: " . json_encode(array_keys($uploadedFiles)));
                error_log("Total files uploaded locally: " . count($uploadedFiles));
                
                // Upload files to DHIS2 as FILE_RESOURCE entities
                $dhis2UploadCount = 0;
                foreach ($uploadedFiles as $fieldName => $fileInfo) {
                    error_log("Attempting DHIS2 upload for: $fieldName -> " . $fileInfo['original_name']);
                    $dhis2FileUID = uploadFileToDHIS2($fileInfo['file_path'], $fileInfo['original_name'], $instanceKey);
                    if ($dhis2FileUID) {
                        $dhis2FileResources[$fieldName] = $dhis2FileUID;
                        $dhis2UploadCount++;
                        error_log("SUCCESS: File uploaded to DHIS2: $fieldName -> $dhis2FileUID");
                    } else {
                        error_log("FAILED: Could not upload file to DHIS2: $fieldName -> " . $fileInfo['original_name']);
                    }
                }
                error_log("DHIS2 upload summary: $dhis2UploadCount out of " . count($uploadedFiles) . " files uploaded successfully");
            } else {
                error_log("File upload failed: " . $uploadResult['message']);
                throw new Exception('File upload failed: ' . $uploadResult['message']);
            }
        }
    }
    
    // Update form data with DHIS2 file resource UIDs
    if (!empty($dhis2FileResources)) {
        error_log("Updating form data with " . count($dhis2FileResources) . " DHIS2 file resources");
        error_log("File resources to replace: " . json_encode($dhis2FileResources));
        $formData = updateFormDataWithFileResources($formData, $dhis2FileResources);
        error_log("Form data updated with DHIS2 file UIDs");
    } else {
        error_log("No DHIS2 file resources to update in form data");
    }
    
    // Submit to DHIS2 using instance key
    $dhis2Response = submitToDHIS2Tracker($survey, $formData, $instanceKey, $locationData);
    
    // Use the TEI UID from the response as the submission UID
    $submissionUID = $dhis2Response['tei_id'] ?? generateUID();
    
    // Save to local database for backup/tracking
    $submissionId = saveTrackerSubmissionLocally($surveyId, $formData, $dhis2Response, $locationData, $submissionUID);
    
    // Log uploaded files to database
    if (!empty($uploadedFiles) && $submissionId) {
        foreach ($uploadedFiles as $fieldName => $fileInfo) {
            logTrackerFileUpload($submissionId, $fieldName, $fileInfo, $pdo);
        }
    }
    
    // Log to payload checker for retry capability
    logToPayloadChecker($submissionId, 'SUCCESS', $formData, $dhis2Response, 'Tracker submission successful');
    
    echo json_encode([
        'success' => true,
        'message' => 'Data submitted successfully to DHIS2',
        'submission_id' => $submissionId,
        'participant_uid' => $submissionUID,
        'dhis2_response' => $dhis2Response
    ]);
    
} catch (Exception $e) {
    error_log("Tracker submission error: " . $e->getMessage());
    
    // Try to save local submission even on DHIS2 failure
    try {
        $submissionUID = generateUID();
        $submissionId = saveTrackerSubmissionLocally($surveyId, $formData, ['error' => $e->getMessage()], $locationData, $submissionUID);
        // Log failure to payload checker for retry capability
        logToPayloadChecker($submissionId, 'FAILED', $formData, null, $e->getMessage());
    } catch (Exception $saveError) {
        error_log("Failed to save local submission: " . $saveError->getMessage());
    }
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// Function to submit to DHIS2 tracker API using modern tracker API
function submitToDHIS2Tracker($survey, $formData, $instanceKey, $locationData) {
    // Use selected orgunit from location data
    $selectedOrgUnit = $locationData['orgunit_uid'] ?? ($survey['dhis2_org_unit'] ?? 'DiszpKrYNg8');
    
    // Generate unique UIDs for tracker entities
    $teiUID = generateUID();
    $enrollmentUID = generateUID();
    
    // Convert form data structure to correct DHIS2 tracker API format
    $trackerPayload = convertToTrackerAPIFormat($survey, $formData, $selectedOrgUnit, $teiUID, $enrollmentUID);
    
    // Submit using modern tracker API endpoint
    $endpoint = '/api/tracker?async=false&importReportMode=FULL&validationMode=FULL&importStrategy=CREATE_AND_UPDATE';
    $response = dhis2_post($endpoint, $trackerPayload, $instanceKey);
    
    if (!$response) {
        throw new Exception('Failed to submit to DHIS2 tracker API: No response received');
    }
    
    // Check for success in modern tracker API response
    if (!isTrackerAPIResponseSuccessful($response)) {
        $errorMessage = extractTrackerAPIError($response);
        error_log("DHIS2 Tracker API submission failed with detailed errors");
        throw new Exception('DHIS2 Tracker API Error: ' . $errorMessage);
    }
    
    return [
        'tracker_response' => $response,
        'tei_id' => $teiUID,
        'enrollment_id' => $enrollmentUID,
        'status' => 'SUCCESS'
    ];
}

// Helper function to detect if a value is a file path instead of a DHIS2 file resource UID
function isFileResourcePath($value) {
    return (
        strpos($value, 'C:\\') !== false ||          // Windows path
        strpos($value, '/') !== false &&            // Unix path with file extensions
        (
            strpos($value, '.xlsx') !== false ||    // Excel file
            strpos($value, '.csv') !== false ||     // CSV file
            strpos($value, '.xls') !== false ||     // Old Excel file
            strpos($value, '.pdf') !== false ||     // PDF file
            strpos($value, '.doc') !== false        // Word file
        ) ||
        strpos($value, 'fakepath') !== false ||     // Browser security path
        (
            // More specific file path detection - must contain file extensions AND path separators
            (strpos($value, '\\') !== false || strpos($value, '/') !== false) &&
            preg_match('/\.(xlsx|csv|xls|pdf|docx?|txt|zip)$/i', $value)
        )
    );
}

// Convert form data to proper DHIS2 tracker API format
function convertToTrackerAPIFormat($survey, $formData, $selectedOrgUnit, $teiUID, $enrollmentUID) {
    // Log form data for debugging
    error_log("Converting form data to tracker format: " . json_encode($formData));
    
    // Prepare tracked entity attributes with validation
    $attributes = [];
    if (isset($formData['trackedEntityAttributes'])) {
        foreach ($formData['trackedEntityAttributes'] as $teaId => $value) {
            // Clean and validate attribute values
            $cleanValue = trim((string)$value);
            if ($cleanValue !== '') {
                $attributes[] = [
                    'attribute' => $teaId,
                    'value' => $cleanValue
                ];
                error_log("TEA: $teaId = $cleanValue");
            }
        }
    }
    
    // Add missing mandatory attribute if detected from error patterns
    // Based on error: "Attribute: `biIdwUNiNxa`, is mandatory in tracked entity type `y5jPHwWD9ZV`"
    $tetUID = $survey['dhis2_tracked_entity_type_uid'] ?? 'MCPQUTHX1Ze';
    if ($tetUID === 'y5jPHwWD9ZV') {
        // Check if mandatory attribute biIdwUNiNxa is missing
        $hasMandatoryAttr = false;
        foreach ($attributes as $attr) {
            if ($attr['attribute'] === 'biIdwUNiNxa') {
                $hasMandatoryAttr = true;
                break;
            }
        }
        
        if (!$hasMandatoryAttr) {
            // Add default value for mandatory attribute
            $attributes[] = [
                'attribute' => 'biIdwUNiNxa',
                'value' => 'SurveyEngine_' . date('Y-m-d_H:i:s') // Default identifier
            ];
            error_log("Added mandatory TEA: biIdwUNiNxa = SurveyEngine_" . date('Y-m-d_H:i:s'));
        }
    }
    
    // Prepare events array (convert from object/map to array)
    $events = [];
    if (isset($formData['events'])) {
        foreach ($formData['events'] as $eventKey => $eventData) {
            $eventUID = generateUID();
            
            // Convert dataValues from object/map to array with validation
            $dataValues = [];
            if (isset($eventData['dataValues'])) {
                foreach ($eventData['dataValues'] as $deId => $value) {
                    // Clean and validate data values
                    $cleanValue = trim((string)$value);
                    if ($cleanValue !== '') {
                        // Note: FILE_RESOURCE fields now contain DHIS2 file UIDs (processed above)
                        // No need to filter file paths as they're now proper DHIS2 references
                        
                        $dataValues[] = [
                            'dataElement' => $deId,
                            'value' => $cleanValue,
                            'providedElsewhere' => false
                        ];
                        error_log("DataElement: $deId = $cleanValue");
                    }
                }
            }
            
            $events[] = [
                'event' => $eventUID,
                'program' => $survey['dhis2_program_uid'],
                'programStage' => $eventData['programStage'],
                'orgUnit' => $selectedOrgUnit,
                'occurredAt' => $eventData['eventDate'] ?? date('Y-m-d'), // Use occurredAt instead of eventDate
                'scheduledAt' => $eventData['eventDate'] ?? date('Y-m-d'),
                'status' => 'COMPLETED',
                'completedAt' => date('Y-m-d\TH:i:s.000'),
                'storedBy' => 'SurveyEngine',
                'dataValues' => $dataValues
            ];
        }
    }
    
    // Build modern tracker API payload
    $payload = [
        'trackedEntities' => [
            [
                'trackedEntity' => $teiUID,
                'trackedEntityType' => $survey['dhis2_tracked_entity_type_uid'] ?? 'MCPQUTHX1Ze',
                'orgUnit' => $selectedOrgUnit,
                'attributes' => $attributes,
                'enrollments' => [
                    [
                        'enrollment' => $enrollmentUID,
                        'program' => $survey['dhis2_program_uid'],
                        'orgUnit' => $selectedOrgUnit,
                        'enrolledAt' => date('Y-m-d'),
                        'occurredAt' => date('Y-m-d'),
                        'status' => 'COMPLETED',
                        'events' => $events
                    ]
                ]
            ]
        ]
    ];
    
    // Log final payload for debugging
    error_log("Final tracker payload: " . json_encode($payload, JSON_PRETTY_PRINT));
    
    return $payload;
}

// Check if tracker API response indicates success
function isTrackerAPIResponseSuccessful($response) {
    // Log response for debugging
    error_log("Checking success for response structure: " . json_encode(array_keys($response)));
    
    // Check overall status first (most reliable indicator)
    if (isset($response['status']) && $response['status'] === 'OK') {
        error_log("Success detected: status = OK");
        return true;
    }
    
    // Check HTTP status code if available
    if (isset($response['httpStatusCode']) && ($response['httpStatusCode'] === 200 || $response['httpStatusCode'] === 201)) {
        error_log("Success detected: HTTP status " . $response['httpStatusCode']);
        return true;
    }
    
    // Check bundle report for detailed status
    if (isset($response['bundleReport']) && isset($response['bundleReport']['status']) && $response['bundleReport']['status'] === 'OK') {
        error_log("Success detected: bundleReport status = OK");
        return true;
    }
    
    // Check if there are stats indicating successful creation/update
    if (isset($response['bundleReport']['stats']) && 
        ($response['bundleReport']['stats']['created'] > 0 || $response['bundleReport']['stats']['updated'] > 0)) {
        error_log("Success detected: stats show created/updated entities");
        return true;
    }
    
    error_log("No success indicators found in response");
    return false;
}

// Extract error message from tracker API response
function extractTrackerAPIError($response) {
    $errors = [];
    
    // Log the full response for debugging
    error_log("DHIS2 Error Response: " . json_encode($response, JSON_PRETTY_PRINT));
    
    // Check for bundle report errors
    if (isset($response['bundleReport']['typeReportMap'])) {
        foreach ($response['bundleReport']['typeReportMap'] as $entityType => $typeReport) {
            if (isset($typeReport['objectReports'])) {
                foreach ($typeReport['objectReports'] as $objectReport) {
                    // Log each object report
                    error_log("Object Report for $entityType: " . json_encode($objectReport));
                    
                    if (isset($objectReport['errorReports'])) {
                        foreach ($objectReport['errorReports'] as $errorReport) {
                            $errorMsg = $errorReport['message'] ?? $errorReport['errorCode'] ?? 'Unknown error';
                            $errors[] = "[$entityType] $errorMsg";
                            
                            // Special handling for option set validation errors
                            if (strpos($errorMsg, 'not a valid option') !== false) {
                                error_log("Option set validation error detected: $errorMsg");
                            }
                        }
                    }
                    
                    // Check for warning reports too
                    if (isset($objectReport['warningReports'])) {
                        foreach ($objectReport['warningReports'] as $warningReport) {
                            $warningMsg = $warningReport['message'] ?? 'Unknown warning';
                            error_log("Warning: [$entityType] $warningMsg");
                        }
                    }
                }
            }
        }
    }
    
    // Check for validation report errors
    if (isset($response['validationReport']['errorReports'])) {
        foreach ($response['validationReport']['errorReports'] as $errorReport) {
            $errorMsg = $errorReport['message'] ?? $errorReport['errorCode'] ?? 'Validation error';
            $errors[] = "[Validation] $errorMsg";
            error_log("Validation error: $errorMsg");
        }
    }
    
    // Check for top-level error message
    if (isset($response['message'])) {
        $errors[] = $response['message'];
    }
    
    // Check for HTTP status error
    if (isset($response['httpStatusCode']) && $response['httpStatusCode'] >= 400) {
        $errors[] = "HTTP {$response['httpStatusCode']}: " . ($response['httpStatus'] ?? 'Error');
    }
    
    $finalError = empty($errors) ? 'Unknown tracker API error' : implode('; ', $errors);
    error_log("Final extracted error: $finalError");
    
    return $finalError;
}


// Generate DHIS2 compatible UID (11 characters) with enhanced uniqueness and validation
function generateUID() {
    // DHIS2 UID requirements:
    // - Exactly 11 characters
    // - First character must be letter (a-zA-Z)
    // - Remaining 10 characters can be alphanumeric (a-zA-Z0-9)
    $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $alphanumeric = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    
    // Start with a random letter
    $uid = $letters[mt_rand(0, strlen($letters) - 1)];
    
    // Generate remaining 10 characters using multiple entropy sources
    $timestamp = microtime(true) * 1000000; // microseconds for high precision
    $processId = getmypid();
    $randomBytes = random_bytes(10);
    $entropy = $timestamp . $processId . bin2hex($randomBytes) . uniqid('', true);
    
    // Create hash from entropy
    $hash = hash('sha256', $entropy);
    
    // Extract alphanumeric characters from hash
    $hashChars = array_filter(str_split($hash), function($char) use ($alphanumeric) {
        return strpos($alphanumeric, $char) !== false;
    });
    
    // Add characters from hash, falling back to random if needed
    for ($i = 1; $i < 11; $i++) {
        if (!empty($hashChars)) {
            $uid .= array_shift($hashChars);
        } else {
            $uid .= $alphanumeric[mt_rand(0, strlen($alphanumeric) - 1)];
        }
    }
    
    // Ensure exact length and valid format
    $uid = substr($uid, 0, 11);
    if (strlen($uid) < 11) {
        while (strlen($uid) < 11) {
            $uid .= $alphanumeric[mt_rand(0, strlen($alphanumeric) - 1)];
        }
    }
    
    return $uid;
}

// Function to save tracker submission locally
function saveTrackerSubmissionLocally($surveyId, $formData, $dhis2Response, $locationData, $uid = null) {
    global $pdo;
    
    try {
        // Generate UID if not provided
        if (!$uid) {
            $uid = generateUID();
        }
        
        // Create submissions table if it doesn't exist with location fields
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tracker_submissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uid VARCHAR(25) UNIQUE,
                survey_id INT NOT NULL,
                tracked_entity_instance VARCHAR(255),
                selected_facility_id VARCHAR(255),
                selected_facility_name VARCHAR(500),
                selected_orgunit_uid VARCHAR(255),
                form_data JSON,
                dhis2_response JSON,
                submission_status ENUM('submitted', 'failed') DEFAULT 'submitted',
                submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ip_address VARCHAR(45),
                user_session_id VARCHAR(255),
                INDEX idx_survey_id (survey_id),
                INDEX idx_tei (tracked_entity_instance),
                INDEX idx_facility (selected_facility_id),
                INDEX idx_status (submission_status),
                INDEX idx_uid (uid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        $stmt = $pdo->prepare("
            INSERT INTO tracker_submissions 
            (uid, survey_id, tracked_entity_instance, selected_facility_id, selected_facility_name, 
             selected_orgunit_uid, form_data, dhis2_response, ip_address, user_session_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        // Ensure dhis2Response is an array for safe access
        $dhis2ResponseArray = is_array($dhis2Response) ? $dhis2Response : [];
        $locationDataArray = is_array($locationData) ? $locationData : [];
        
        $stmt->execute([
            $uid ?? generateUID(),
            $surveyId ?? 0,
            $dhis2ResponseArray['tei_id'] ?? null,
            $locationDataArray['facility_id'] ?? null,
            $locationDataArray['facility_name'] ?? null,
            $locationDataArray['orgunit_uid'] ?? null,
            json_encode($formData ?? []),
            json_encode($dhis2Response ?? []),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            session_id() ?? 'no_session'
        ]);
        
        return $pdo->lastInsertId();
        
    } catch (Exception $e) {
        error_log("Error saving local submission: " . $e->getMessage());
        return null;
    }
}

// Function to log tracker submissions to payload checker (dhis2_submission_log table)
function logToPayloadChecker($submissionId, $status, $formData, $dhis2Response, $message) {
    global $pdo;
    
    try {
        $payloadJson = $formData ? json_encode($formData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $dhis2ResponseJson = $dhis2Response ? json_encode($dhis2Response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        
        $stmt = $pdo->prepare("
            INSERT INTO dhis2_submission_log (submission_id, status, payload_sent, dhis2_response, dhis2_message, submitted_at, retries)
            VALUES (?, ?, ?, ?, ?, NOW(), 0)
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                payload_sent = VALUES(payload_sent),
                dhis2_response = VALUES(dhis2_response),
                dhis2_message = VALUES(dhis2_message),
                submitted_at = NOW(),
                retries = retries + 1
        ");
        
        $stmt->execute([
            $submissionId,
            $status,
            $payloadJson,
            $dhis2ResponseJson,
            $message
        ]);
        
        error_log("Tracker submission ID $submissionId logged to payload checker with status: $status");
    } catch (PDOException $e) {
        error_log("Failed to log tracker submission to payload checker: " . $e->getMessage());
    }
}
?>