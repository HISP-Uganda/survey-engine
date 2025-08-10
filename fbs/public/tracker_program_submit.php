<?php
session_start();

header('Content-Type: application/json');

require_once '../admin/connect.php';
require_once '../admin/dhis2/dhis2_shared.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = file_get_contents('php://input');
$requestData = json_decode($input, true);

if (!$requestData) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
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
    
    // Submit to DHIS2 using instance key
    $dhis2Response = submitToDHIS2Tracker($survey, $formData, $instanceKey, $locationData);
    
    // Generate UID for this submission
    $submissionUID = generateUID();
    
    // Save to local database for backup/tracking
    $submissionId = saveLocalSubmission($surveyId, $formData, $dhis2Response, $locationData, $submissionUID);
    
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
        $submissionId = saveLocalSubmission($surveyId, $formData, ['error' => $e->getMessage()], $locationData, $submissionUID);
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

// Function to submit to DHIS2 tracker API using shared functions
function submitToDHIS2Tracker($survey, $formData, $instanceKey, $locationData) {
    // Use selected orgunit from location data
    $selectedOrgUnit = $locationData['orgunit_uid'] ?? ($survey['dhis2_org_unit'] ?? 'DiszpKrYNg8');
    
    // Step 1: Create/Update Tracked Entity Instance
    $teiData = [
        'trackedEntityType' => $survey['dhis2_tracked_entity_type_uid'] ?? 'MCPQUTHX1Ze', // Default person
        'orgUnit' => $selectedOrgUnit, // Use selected location
        'attributes' => []
    ];
    
    // Add TEI attributes
    foreach ($formData['trackedEntityAttributes'] as $teaId => $value) {
        $teiData['attributes'][] = [
            'attribute' => $teaId,
            'value' => $value
        ];
    }
    
    // Create TEI using shared function
    $teiResponse = dhis2_post('trackedEntityInstances', $teiData, $instanceKey);
    
    if (!$teiResponse || !isset($teiResponse['response']['importSummaries'][0]['reference'])) {
        throw new Exception('Failed to create tracked entity instance: ' . json_encode($teiResponse));
    }
    
    $teiId = $teiResponse['response']['importSummaries'][0]['reference'];
    
    // Step 2: Enroll in program
    $enrollmentData = [
        'trackedEntityInstance' => $teiId,
        'program' => $survey['dhis2_program_uid'],
        'orgUnit' => $selectedOrgUnit, // Use selected location
        'enrollmentDate' => date('Y-m-d'),
        'incidentDate' => date('Y-m-d'),
        'status' => 'ACTIVE'
    ];
    
    $enrollmentResponse = dhis2_post('enrollments', $enrollmentData, $instanceKey);
    
    if (!$enrollmentResponse) {
        throw new Exception('Failed to create enrollment: ' . json_encode($enrollmentResponse));
    }
    
    // Step 3: Create events for each stage occurrence
    $eventResponses = [];
    foreach ($formData['events'] as $eventKey => $eventData) {
        $event = [
            'program' => $survey['dhis2_program_uid'],
            'programStage' => $eventData['programStage'],
            'trackedEntityInstance' => $teiId,
            'orgUnit' => $selectedOrgUnit, // Use selected location
            'eventDate' => $eventData['eventDate'],
            'status' => 'COMPLETED',
            'dataValues' => []
        ];
        
        // Add data values
        foreach ($eventData['dataValues'] as $deId => $value) {
            $event['dataValues'][] = [
                'dataElement' => $deId,
                'value' => $value
            ];
        }
        
        $eventResponse = dhis2_post('events', $event, $instanceKey);
        $eventResponses[$eventKey] = $eventResponse;
    }
    
    return [
        'tei_response' => $teiResponse,
        'enrollment_response' => $enrollmentResponse,
        'event_responses' => $eventResponses,
        'tei_id' => $teiId
    ];
}


// Function to save submission locally
function saveLocalSubmission($surveyId, $formData, $dhis2Response, $locationData) {
    global $pdo;
    
    try {
        // Create submissions table if it doesn't exist with location fields
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tracker_submissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
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
                INDEX idx_status (submission_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        $stmt = $pdo->prepare("
            INSERT INTO tracker_submissions 
            (survey_id, tracked_entity_instance, selected_facility_id, selected_facility_name, 
             selected_orgunit_uid, form_data, dhis2_response, ip_address, user_session_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $surveyId,
            $dhis2Response['tei_id'] ?? null,
            $locationData['facility_id'] ?? null,
            $locationData['facility_name'] ?? null,
            $locationData['orgunit_uid'] ?? null,
            json_encode($formData),
            json_encode($dhis2Response),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            session_id()
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

// Function to generate a unique identifier (UID) for tracker submissions
function generateUID() {
    return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 10));
}

// Function to save tracker submission locally with UID
function saveLocalSubmission($surveyId, $formData, $dhis2Response, $locationData, $uid) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO tracker_submissions 
            (uid, survey_id, tracked_entity_instance, selected_facility_id, selected_facility_name, 
             selected_orgunit_uid, form_data, dhis2_response, ip_address, user_session_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $uid,
            $surveyId,
            $dhis2Response['tei_id'] ?? null,
            $locationData['facility_id'] ?? null,
            $locationData['facility_name'] ?? null,
            $locationData['orgunit_uid'] ?? null,
            json_encode($formData),
            json_encode($dhis2Response),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            session_id()
        ]);
        
        return $pdo->lastInsertId();
        
    } catch (Exception $e) {
        error_log("Error saving local tracker submission: " . $e->getMessage());
        return null;
    }
}
?>