<?php
require_once 'dhis2_shared.php';

class DHIS2SubmissionHandler {
    private $conn;
    private $instance;
    private $programUID;
    private $programType;
    private $fieldMappingCache = [];
    private $surveyId;
    private $datasetUID;

    /**
     * Constructor for DHIS2SubmissionHandler.
     * Fetches DHIS2 configuration from the database based on surveyId.
     *
     * @param mysqli $conn The database connection.
     * @param int $surveyId The ID of the survey to get configuration for.
     * @throws Exception If the survey configuration is found but invalid (e.g., empty instance/program UID).
     */
   public function __construct(mysqli $conn, int $surveyId) {
        $this->conn = $conn;
        $this->surveyId = $surveyId;
        
        $surveyConfig = $this->getSurveyConfig($surveyId);

        if (!$surveyConfig || empty($surveyConfig['dhis2_instance']) || empty($surveyConfig['program_dataset'])) {
            $this->instance = null;
            $this->programUID = null;
            $this->datasetUID = null; // Also set datasetUID to null
            error_log("No valid DHIS2 configuration found for survey ID: $surveyId. dhis2_instance or program_dataset might be empty/null.");
            return;
        }
        
        $this->instance = $surveyConfig['dhis2_instance'];
        $programDatasetUID = $surveyConfig['program_dataset']; // Temporary variable for the fetched UID
        
        error_log("Initialized DHIS2 Handler with dynamic config from survey ID: $surveyId - Instance: {$this->instance}, Program/Dataset: {$programDatasetUID}");
        
        // Determine program type and set the appropriate UID
        $this->programType = $this->determineProgramType($programDatasetUID);
        
        if ($this->programType === 'dataset') {
            $this->datasetUID = $programDatasetUID;
            $this->programUID = null; // Ensure programUID is null if it's a dataset
            error_log("Assigned program/dataset UID to datasetUID: {$this->datasetUID}");
        } else {
            $this->programUID = $programDatasetUID;
            $this->datasetUID = null; // Ensure datasetUID is null if it's a program
            error_log("Assigned program/dataset UID to programUID: {$this->programUID}");
        }
        
        $this->loadFieldMappings();
        
        error_log("Fully initialized DHIS2 Handler - Instance: {$this->instance}, Program: {$this->programUID}, Dataset: {$this->datasetUID}, Type: {$this->programType}");
    }

    /**
     * Check if the handler is ready for DHIS2 submission.
     * @return bool True if instance and programUID are set, false otherwise.
     */
     public function isReadyForSubmission(): bool {
        return $this->instance !== null && ($this->programUID !== null || $this->datasetUID !== null);
    }

    private function getSurveyConfig(int $surveyId): ?array {
        // Changed query: Removed 'type = dhis2' and 'is_active = 1' to fetch all survey types.
        // We will now rely on dhis2_instance and program_dataset being present.
        $stmt = $this->conn->prepare("
            SELECT dhis2_instance, program_dataset 
            FROM survey 
            WHERE id = ? 
        ");
        
        $stmt->bind_param("i", $surveyId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc();
    }


  private function determineProgramType(string $uid): string {
        // You might consider caching these responses if this method is called frequently for the same UID
        // and network latency is a concern.
        
        // Check if it's a program (event or tracker)
        $programResponse = dhis2Curl("/api/programs/$uid.json?fields=id,programType", $this->instance);
        
        if ($programResponse && isset($programResponse['programType'])) {
            $programType = strtolower($programResponse['programType']);
            error_log("Detected program type: $programType for UID: $uid");
            return $programType === 'with_registration' ? 'tracker' : 'event';
        }
        
        // Check if it's a dataset
        $datasetResponse = dhis2Curl("/api/dataSets/$uid.json?fields=id,name", $this->instance);
        
        if ($datasetResponse && isset($datasetResponse['id'])) {
            error_log("Detected dataset for UID: $uid");
            return 'dataset';
        }
        
        // Default to event if cannot determine
        // It's good practice to log this fallback, as it might indicate a configuration issue.
        error_log("Could not determine type for UID: $uid, defaulting to event");
        return 'event';
    }
    private function loadFieldMappings(): void {
        $stmt = $this->conn->prepare("SELECT field_name, dhis2_dataelement_id, dhis2_option_set_id FROM dhis2_system_field_mapping");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $this->fieldMappingCache[$row['field_name']] = [
                'data_element' => $row['dhis2_dataelement_id'],
                'option_set' => $row['dhis2_option_set_id'],
            ];
        }
    }

    private function getMappedUID(string $fieldName, string $type = 'data_element'): ?string {
        return $this->fieldMappingCache[$fieldName][$type] ?? null;
    }

    public function processSubmission(int $submissionId): array {
        // Add a check here as well, in case someone calls processSubmission directly
        if (!$this->isReadyForSubmission()) {
            return ['success' => false, 'message' => 'DHIS2 handler not configured for submission (missing instance or program UID).'];
        }

        // Temporarily skip duplicate submission check for diagnostics
        // if ($this->isAlreadySubmitted($submissionId)) {
        //     return ['success' => true, 'message' => 'Submission was already processed successfully'];
        // }
        try {
            // Temporarily skip duplicate submission check for diagnostics
            // if ($this->isAlreadySubmitted($submissionId)) {
            //     return ['success' => true, 'message' => 'Submission was already processed successfully'];
            // }

            // Get submission data with explicit JOINs to ensure we have ownership and service unit info
            $submissionData = $this->getSubmissionData($submissionId);
            if (!$submissionData) {
                throw new Exception("Submission not found");
            }

            // Get all question responses
            $responses = $this->getSubmissionResponses($submissionId);

            // Generate a unique event/enrollment ID to prevent duplication
            $uniqueUID = $this->generateUniqueUID($submissionId, $submissionData);

            // Prepare payload based on program type
            $payload = $this->prepareDHIS2Payload($submissionData, $responses, $uniqueUID);

            // Log complete payload for debugging
            error_log("DHIS2 Payload ({$this->programType}): " . json_encode($payload, JSON_PRETTY_PRINT));
         


            $result = $this->submitToDHIS2($payload);

            // If successful, mark this submission as processed
            if ($result['success']) {
                $this->markAsSubmitted($submissionId);
            }

            return $result;

        } catch (Exception $e) {
            error_log("DHIS2 Submission Error: " . $e->getMessage());
            return ['success' => false, 'message' => "Final submission error: " . $e->getMessage()];
        }
    
    }

    private function isAlreadySubmitted(int $submissionId): bool {
        $stmt = $this->conn->prepare("
            SELECT id FROM dhis2_submission_log
            WHERE submission_id = ? AND status = 'SUCCESS'
        ");

        $stmt->bind_param("i", $submissionId);
        $stmt->execute();
        $result = $stmt->get_result();

        return $result->num_rows > 0;
    }

    private function markAsSubmitted(int $submissionId): void {
        $stmt = $this->conn->prepare("
            INSERT INTO dhis2_submission_log (submission_id, status, submitted_at)
            VALUES (?, 'SUCCESS', NOW())
            ON DUPLICATE KEY UPDATE status = 'SUCCESS', submitted_at = NOW()
        ");

        $stmt->bind_param("i", $submissionId);
        $stmt->execute();
    }

    private function generateUniqueUID(int $submissionId, array $submissionData): string {
        $uniqueFields = [
            $submissionId,
            $submissionData['location_uid'] ?? '',
            $submissionData['period'] ?? '',
            $this->programUID,
            $this->programType
        ];

        $baseString = implode('-', $uniqueFields);
        $hash = substr(md5($baseString), 0, 11);

        return $hash;
    }

    private function getSubmissionData(int $submissionId): ?array {
        $stmt = $this->conn->prepare("
            SELECT
                s.*,
                l.uid as location_uid,
                o.id as ownership_id,
                o.name as ownership_name,
                su.id as service_unit_id,
                su.name as service_unit_name
            FROM submission s
            LEFT JOIN location l ON s.location_id = l.id
            LEFT JOIN owner o ON s.ownership_id = o.id
            LEFT JOIN service_unit su ON s.service_unit_id = su.id
            WHERE s.id = ?
        ");

        $stmt->bind_param("i", $submissionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();

        if ($data) {
            error_log("Submission data: " . json_encode($data, JSON_PRETTY_PRINT));
        }

        return $data;
    }

    private function getSubmissionResponses(int $submissionId): array {
        $responses = [];
        $stmt = $this->conn->prepare("
            SELECT sr.question_id, sr.response_value
            FROM submission_response sr
            WHERE sr.submission_id = ?
        ");

        $stmt->bind_param("i", $submissionId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $responses[$row['question_id']] = [
                'value' => $row['response_value']
            ];
        }

        if (!empty($responses)) {
            error_log("Submission responses: " . json_encode($responses, JSON_PRETTY_PRINT));
        }

        return $responses;
    }

    private function prepareDHIS2Payload(array $submissionData, array $responses, string $uniqueUID): array {
        $eventDate = $submissionData['period'] ?? date('Y-m-d');
        $dataValues = $this->prepareDataValues($submissionData, $responses);

        switch ($this->programType) {
            case 'event':
                return $this->prepareEventPayload($submissionData, $dataValues, $uniqueUID, $eventDate);
            
            case 'tracker':
                return $this->prepareTrackerPayload($submissionData, $dataValues, $uniqueUID, $eventDate);
            
            case 'dataset':
                return $this->prepareDatasetPayload($submissionData, $dataValues, $eventDate);
            
            default:
                throw new Exception("Unsupported program type: {$this->programType}");
        }
    }

    private function prepareDataValues(array $submissionData, array $responses): array {
        $dataValues = [];

        // 1. Add ownership data element
        $ownershipDE = $this->getMappedUID('ownership');
        $ownershipOS = $this->getMappedUID('ownership', 'option_set');
        if (!empty($submissionData['ownership_name']) && $ownershipDE) {
            $ownershipCode = $this->getOptionCode($submissionData['ownership_name'], $ownershipOS);
            if ($ownershipCode) {
                $dataValues[] = [
                    'dataElement' => $ownershipDE,
                    'value' => $ownershipCode
                ];
                error_log("Added ownership: {$submissionData['ownership_name']} -> $ownershipCode");
            }
        }

        // 2. Add service unit data element
        $serviceUnitDE = $this->getMappedUID('service_unit');
        $serviceUnitOS = $this->getMappedUID('service_unit', 'option_set');
        if (!empty($submissionData['service_unit_name']) && $serviceUnitDE) {
            $serviceUnitCode = $this->getOptionCode($submissionData['service_unit_name'], $serviceUnitOS);
            if ($serviceUnitCode) {
                $dataValues[] = [
                    'dataElement' => $serviceUnitDE,
                    'value' => $serviceUnitCode
                ];
                error_log("Added service unit: {$submissionData['service_unit_name']} -> $serviceUnitCode");
            }
        }

        // 3. Add other standard fields
        if (!empty($submissionData['age'])) {
            if ($ageDE = $this->getMappedUID('age')) {
                $dataValues[] = [
                    'dataElement' => $ageDE,
                    'value' => (string)$submissionData['age']
                ];
            }
        }

        if (!empty($submissionData['sex'])) {
            if ($sexDE = $this->getMappedUID('sex')) {
                $sexOS = $this->getMappedUID('sex', 'option_set');
                $sexCode = $this->getOptionCode($submissionData['sex'], $sexOS);
                if ($sexCode) {
                    $dataValues[] = [
                        'dataElement' => $sexDE,
                        'value' => $sexCode
                    ];
                }
            }
        }

        if (!empty($submissionData['period'])) {
            if ($periodDE = $this->getMappedUID('period')) {
                $dataValues[] = [
                    'dataElement' => $periodDE,
                    'value' => $submissionData['period']
                ];
            }
        }

        // 4. Add question responses
        $dataValues = array_merge($dataValues, $this->processQuestionResponses($responses));

        return $dataValues;
    }

    private function prepareEventPayload(array $submissionData, array $dataValues, string $eventUID, string $eventDate): array {
        return [
            'events' => [
                [
                    'event' => $eventUID,
                    'orgUnit' => $submissionData['location_uid'],
                    'program' => $this->programUID,
                    'eventDate' => $eventDate,
                    'occurredAt' => $eventDate,
                    'status' => 'COMPLETED',
                    'dataValues' => $dataValues
                ]
            ]
        ];
    }

    private function prepareTrackerPayload(array $submissionData, array $dataValues, string $enrollmentUID, string $eventDate): array {
    return [
        'trackedEntityInstances' => [
            [
                'trackedEntityInstance' => $this->generateTrackedEntityUID($submissionData),
                'orgUnit' => $submissionData['location_uid'],
                'trackedEntityType' => 'MCPQUTHX1Ze',
                'enrollments' => [
                    [
                        'enrollment' => $enrollmentUID,
                        'orgUnit' => $submissionData['location_uid'],
                        'program' => $this->programUID,
                        'enrollmentDate' => $eventDate,
                        'incidentDate' => $eventDate,
                        'status' => 'COMPLETED',
                        'events' => [
                            [
                                'event' => $this->generateEventUID($enrollmentUID, $submissionData),
                                'orgUnit' => $submissionData['location_uid'],
                                'program' => $this->programUID,
                                'programStage' => $this->getProgramStageUID(),  // Added programStage
                                'eventDate' => $eventDate,
                                'status' => 'COMPLETED',
                                'dataValues' => $dataValues
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ];
}

private function prepareDatasetPayload(array $submissionData, array $dataValues, string $period): array {
        if ($this->datasetUID === null) {
            throw new Exception("Dataset UID not set for dataset submission.");
        }

        // Option 1: If period is empty, use today's date in YYYYMMDD format
        if (empty($period)) {
            $period = date('Y-m-d');
        }

        // Ensure period is in YYYYMMDD format for daily surveys
        $dhis2Period = $this->convertToDHIS2Period($period); // Should return YYYYMMDD
        $orgUnit = (string)$submissionData['location_uid'];
        $preparedDataValues = [];
        $attributeOptionCombo = null;
        $defaultCategoryOptionCombo = 'HllvX50cXC0';
        $defaultAttributeOptionCombo = 'HllvX50cXC0'; // Confirm this is correct for your DHIS2 instance

        // First pass: check if attributeOptionCombo is set in dataValues
        foreach ($dataValues as $dv) {
            if ($this->getMappedUID('attribute_option_combo_field') && 
                $dv['dataElement'] === $this->getMappedUID('attribute_option_combo_field')) {
                $attributeOptionCombo = (string)$dv['value'];
                continue;
            }
        }

        // If not set, try to derive it from DB
        if (is_null($attributeOptionCombo)) {
            $attributeAOC_DE = $this->getMappedUID('attribute_option_combo');
            if ($attributeAOC_DE) {
                $stmt = $this->conn->prepare("
                    SELECT sr.response_value, qm.dhis2_option_set_id
                    FROM submission_response sr
                    JOIN question_dhis2_mapping qm ON sr.question_id = qm.question_id
                    WHERE sr.submission_id = ? AND qm.dhis2_dataelement_id = ?
                    LIMIT 1
                ");
                $stmt->bind_param("is", $submissionData['id'], $attributeAOC_DE);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $derivedAttributeOptionCombo = $this->getOptionCode($row['response_value'], $row['dhis2_option_set_id']);
                    if ($derivedAttributeOptionCombo) {
                        $attributeOptionCombo = $derivedAttributeOptionCombo;
                    }
                }
            }
        }

        // Always set attributeOptionCombo, defaulting if not found
        if (is_null($attributeOptionCombo)) {
            $attributeOptionCombo = $defaultAttributeOptionCombo;
        }

        // Prepare dataValues
        foreach ($dataValues as $dv) {
            // Skip the attribute option combo field itself
            if ($this->getMappedUID('attribute_option_combo_field') && 
                $dv['dataElement'] === $this->getMappedUID('attribute_option_combo_field')) {
                continue;
            }

            $dataEntry = [
                'dataElement' => (string)$dv['dataElement'],
                'value' => (string)$dv['value'],
                'categoryOptionCombo' => isset($dv['categoryOptionCombo']) && !empty($dv['categoryOptionCombo'])
                    ? (string)$dv['categoryOptionCombo']
                    : $defaultCategoryOptionCombo,
                'attributeOptionCombo' => $attributeOptionCombo
            ];

            if (isset($dv['comment']) && !empty($dv['comment'])) {
                $dataEntry['comment'] = (string)$dv['comment'];
            }

            $preparedDataValues[] = $dataEntry;
        }

        return [
            'dataSet' => $this->datasetUID,
            'period' => $dhis2Period,
            'orgUnit' => $orgUnit,
            'dataValues' => $preparedDataValues
        ];
    }

    private function generateTrackedEntityUID(array $submissionData): string {
        $uniqueFields = [
            'TEI',
            $submissionData['location_uid'] ?? '',
            $submissionData['id'] ?? '',
            $this->programUID
        ];
        
        $baseString = implode('-', $uniqueFields);
        return substr(md5($baseString), 0, 11);
    }

    private function generateEventUID(string $baseUID, array $submissionData): string {
        return substr(md5($baseUID . '-event'), 0, 11);
    }

     private function getProgramStageUID(): string {
        // Cache this if the program stages don't change frequently for a given program
        static $programStageCache = [];
        if (isset($programStageCache[$this->programUID])) {
            return $programStageCache[$this->programUID];
        }

        $response = dhis2Curl("/api/programs/{$this->programUID}.json?fields=programStages[id]", $this->instance);
        
        if ($response && isset($response['programStages'][0]['id'])) {
            $programStageId = $response['programStages'][0]['id'];
            $programStageCache[$this->programUID] = $programStageId;
            return $programStageId;
        }
        
        throw new Exception("Could not find program stage for program: {$this->programUID}. This is required for Tracker programs.");
    }

    private function convertToDHIS2Period(string $period): string {
        // Handles various period formats for DHIS2 datasets:
        // - YYYY-MM-DD (monthly, daily, weekly, yearly)
        // - YYYY-MM (monthly)
        // - YYYY (yearly)
        // - W-prefixed (weekly)
        // Returns DHIS2 period string (e.g., 202401, 2024W05, 2024Q1, 2024)
        $period = trim($period);

        // Yearly: 2024
        if (preg_match('/^\d{4}$/', $period)) {
            return $period;
        }

        // Monthly: 2024-01 or 2024-1
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $period, $m)) {
            return sprintf('%04d%02d', $m[1], $m[2]);
        }

        // Quarterly: 2024Q1 or 2024-Q1
        if (preg_match('/^(\d{4})-?Q([1-4])$/i', $period, $m)) {
            return sprintf('%04dQ%d', $m[1], $m[2]);
        }

        // Weekly: 2024W05 or 2024-W05
        if (preg_match('/^(\d{4})-?W(\d{1,2})$/i', $period, $m)) {
            return sprintf('%04dW%02d', $m[1], $m[2]);
        }

        // Daily: 2024-01-15
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $period, $m)) {
            return sprintf('%04d%02d%02d', $m[1], $m[2], $m[3]);
        }

        // Fallback: try to parse as date and use monthly
        $date = DateTime::createFromFormat('Y-m-d', $period);
        if ($date) {
            return $date->format('Ym');
        }

        // If nothing matches, return as-is
        return $period;
    }

    private function processQuestionResponses(array $responses): array {
        $dataValues = [];
        
        if (!empty($responses)) {
            $questionIds = array_keys($responses);
            $placeholders = implode(',', array_fill(0, count($questionIds), '?'));

            $stmt = $this->conn->prepare("
                SELECT qm.question_id, qm.dhis2_dataelement_id, qm.dhis2_option_set_id
                FROM question_dhis2_mapping qm
                WHERE qm.question_id IN ($placeholders)
            ");

            $bindTypes = str_repeat('i', count($questionIds));
            $stmt->bind_param($bindTypes, ...$questionIds);
            $stmt->execute();
            $result = $stmt->get_result();

            $questionMappings = [];
            while ($row = $result->fetch_assoc()) {
                $questionMappings[$row['question_id']] = $row;
            }

            error_log("Question mappings: " . json_encode($questionMappings, JSON_PRETTY_PRINT));

            foreach ($responses as $questionId => $responseData) {
                $responseValue = $responseData['value'];

                if (isset($questionMappings[$questionId])) {
                    $mapping = $questionMappings[$questionId];
                    $value = $responseValue;

                    if (empty($value)) continue;

                    if (!empty($mapping['dhis2_option_set_id'])) {
                        $optionCode = $this->getOptionCode($value, $mapping['dhis2_option_set_id']);
                        if ($optionCode) {
                            $value = $optionCode;
                        } else {
                            error_log("WARNING: No option mapping for question $questionId value: $value");
                            continue;
                        }
                    }

                    $dataValues[] = [
                        'dataElement' => $mapping['dhis2_dataelement_id'],
                        'value' => (string)$value
                    ];

                    error_log("Added question $questionId response: $value -> data element: {$mapping['dhis2_dataelement_id']}");
                } else {
                    error_log("WARNING: No DHIS2 mapping for question ID: $questionId");
                }
            }
        }
        
        return $dataValues;
    }

    private function getOptionCode(string $localValue, ?string $optionSetId): ?string {
        if (empty($optionSetId)) {
            return $localValue;
        }
        
        $stmt = $this->conn->prepare("
            SELECT dhis2_option_code
            FROM dhis2_option_set_mapping
            WHERE local_value = ? AND dhis2_option_set_id = ?
        ");

        $stmt->bind_param("ss", $localValue, $optionSetId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if (!$row) {
            error_log("Option lookup failed: Local value '$localValue' not found in option set '$optionSetId'");

            // Try case-insensitive lookup as fallback
            $stmt = $this->conn->prepare("
                SELECT dhis2_option_code
                FROM dhis2_option_set_mapping
                WHERE LOWER(local_value) = LOWER(?) AND dhis2_option_set_id = ?
            ");
            $stmt->bind_param("ss", $localValue, $optionSetId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();

            if (!$row) {
                return null;
            }
        }

        return $row['dhis2_option_code'];
    }

    private function submitToDHIS2(array $payload): array {
        try {
            // Determine the correct API endpoint based on program type
            $endpoint = $this->getAPIEndpoint();
            
            $response = dhis2_post($endpoint, $payload, $this->instance);

            if ($response === null) {
                throw new Exception("DHIS2 API returned null response");
            }

            error_log("DHIS2 Response: " . json_encode($response, JSON_PRETTY_PRINT));

            // Check for success - different response structures for different endpoints
            if ($this->isSuccessfulResponse($response)) {
                return ['success' => true, 'message' => 'Successfully submitted to DHIS2'];
            }

            // Handle specific DHIS2 errors
            if (isset($response['response'])) {
                $status = $response['response']['status'] ?? '';
                $description = $response['response']['description'] ?? '';

                if ($status === 'ERROR') {
                    if (strpos($description, 'already exists') !== false) {
                        return ['success' => true, 'message' => 'Data was already submitted to DHIS2'];
                    }

                    if (strpos($description, 'Validation failed') !== false) {
                        throw new Exception("DHIS2 validation failed: " . $description);
                    }
                }
            }

            throw new Exception($response['message'] ?? json_encode($response));

        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            if (strpos($errorMsg, 'already exists') !== false) {
                return ['success' => true, 'message' => $errorMsg];
            }
            throw $e;
        }
    }

    private function getAPIEndpoint(): string {
    switch ($this->programType) {
        case 'event':
            return '/api/events';
        case 'tracker':
            return '/api/trackedEntityInstances';
        case 'dataset':
            return '/api/dataValueSets';  // Changed endpoint
        default:
            return '/api/events';
    }
}

    private function isSuccessfulResponse(array $response): bool {
        // Event programs
        if (isset($response['status']) && $response['status'] === 'SUCCESS') {
            return true;
        }

        
        // Tracker programs
        if (isset($response['response']['importSummaries'])) {
            foreach ($response['response']['importSummaries'] as $summary) {
                if (isset($summary['status']) && $summary['status'] === 'SUCCESS') {
                    return true;
                }
            }
        }

        // Datasets
         if (isset($response['status']) && $response['status'] === 'SUCCESS') {
        return true;
        }

        // Check for HTTP status codes
        if (isset($response['httpStatusCode']) && $response['httpStatusCode'] === 200) {
            return true;
        }

        return false;
    }
}
