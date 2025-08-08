<?php
session_start();

header('Content-Type: application/json');

require_once 'connect.php';

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
    
    // Get DHIS2 configuration
    $dhis2Config = null;
    if (!empty($survey['dhis2_instance'])) {
        $stmt = $pdo->prepare("SELECT id, url as base_url, username, password, instance_key, description FROM dhis2_instances WHERE instance_key = ?");
        $stmt->execute([$survey['dhis2_instance']]);
        $dhis2Config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Decode password if it's base64 encoded
        if ($dhis2Config && !empty($dhis2Config['password'])) {
            $decodedPassword = base64_decode($dhis2Config['password']);
            if ($decodedPassword !== false) {
                $dhis2Config['password'] = $decodedPassword;
            }
        }
    }
    
    if (!$dhis2Config) {
        throw new Exception('DHIS2 configuration not found');
    }
    
    // Submit to DHIS2
    $dhis2Response = submitToDHIS2Tracker($survey, $formData, $dhis2Config, $locationData);
    
    // Save to local database for backup/tracking
    $submissionId = saveLocalSubmission($surveyId, $formData, $dhis2Response, $locationData);
    
    echo json_encode([
        'success' => true,
        'message' => 'Data submitted successfully to DHIS2',
        'submission_id' => $submissionId,
        'dhis2_response' => $dhis2Response
    ]);
    
} catch (Exception $e) {
    error_log("Tracker submission error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// Function to submit to DHIS2 tracker API
function submitToDHIS2Tracker($survey, $formData, $dhis2Config, $locationData) {
    $dhis2Url = rtrim($dhis2Config['base_url'], '/') . '/api';
    
    // Use selected orgunit from location data
    $selectedOrgUnit = $locationData['orgunit_uid'] ?? ($survey['dhis2_org_unit'] ?? 'DiszpKrYNg8');
    
    // Step 1: Create/Update Tracked Entity Instance
    $teiData = [
        'trackedEntityType' => $survey['dhis2_tracked_entity_type'] ?? 'MCPQUTHX1Ze', // Default person
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
    
    // Create TEI
    $teiResponse = submitToDHIS2API($dhis2Url . '/trackedEntityInstances', $teiData, $dhis2Config);
    
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
    
    $enrollmentResponse = submitToDHIS2API($dhis2Url . '/enrollments', $enrollmentData, $dhis2Config);
    
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
        
        $eventResponse = submitToDHIS2API($dhis2Url . '/events', $event, $dhis2Config);
        $eventResponses[$eventKey] = $eventResponse;
    }
    
    return [
        'tei_response' => $teiResponse,
        'enrollment_response' => $enrollmentResponse,
        'event_responses' => $eventResponses,
        'tei_id' => $teiId
    ];
}

// Function to make DHIS2 API calls
function submitToDHIS2API($url, $data, $dhis2Config) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($dhis2Config['username'] . ':' . $dhis2Config['password'])
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception('DHIS2 API error: ' . $error);
    }
    
    $responseData = json_decode($response, true);
    
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception('DHIS2 API error: HTTP ' . $httpCode . ' - ' . $response);
    }
    
    return [
        'http_code' => $httpCode,
        'response' => $responseData
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
?>