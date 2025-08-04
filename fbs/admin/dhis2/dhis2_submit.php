<?php
// session_start();
// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure the PDO connection is available
require_once __DIR__ . '/../connect.php'; // This should provide $pdo
if (!isset($pdo)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed: Central PDO object not found. Please check connect.php.']);
    exit();
}

require_once __DIR__ . '/dhis2_shared.php'; // Ensure this provides dhis2_get() and dhis2_post()

class DHIS2SubmissionHandler {
    private $pdo;
    private $instance;
    private $programUID; // Will hold program UID for events/trackers
    private $datasetUID; // Will hold dataset UID for aggregate
    private $programType; // 'event', 'tracker', 'dataset'
    private $trackedEntityTypeUID; // Specific for 'tracker' programs
    private $fieldMappingCache = []; // Caches mappings from dhis2_system_field_mapping
    private $surveyId;

    /**
     * Constructor for DHIS2SubmissionHandler.
     * Fetches DHIS2 configuration from the database based on surveyId.
     *
     * @param PDO $pdo The database connection.
     * @param int $surveyId The ID of the survey to get configuration for.
     * @throws Exception If survey config is invalid or essential UIDs are missing.
     */
    public function __construct(PDO $pdo, int $surveyId) {
        $this->pdo = $pdo;
        $this->surveyId = $surveyId;

        $surveyConfig = $this->getSurveyConfig($surveyId);

        if (!$surveyConfig || empty($surveyConfig['dhis2_instance']) || empty($surveyConfig['program_dataset'])) {
            $this->instance = null;
            $this->programUID = null;
            $this->datasetUID = null;
            error_log("No valid DHIS2 configuration found for survey ID: $surveyId. dhis2_instance or program_dataset might be empty/null.");
            return;
        }

        $this->instance = $surveyConfig['dhis2_instance'];
        $programDatasetUID = $surveyConfig['program_dataset'];
        $this->trackedEntityTypeUID = $surveyConfig['dhis2_tracked_entity_type_uid'] ?? null; // Assuming this is now stored

        error_log("Initialized DHIS2 Handler with dynamic config from survey ID: $surveyId - Instance: {$this->instance}, Program/Dataset: {$programDatasetUID}, TE Type: {$this->trackedEntityTypeUID}");

        // Determine program type and set the appropriate UID
        $this->programType = $this->determineProgramType($programDatasetUID);

        if ($this->programType === 'dataset') {
            $this->datasetUID = $programDatasetUID;
            $this->programUID = null;
        } else { // 'event' or 'tracker'
            $this->programUID = $programDatasetUID;
            $this->datasetUID = null;
        }

        $this->loadSystemFieldMappings(); // Load mappings for system fields

        error_log("Fully initialized DHIS2 Handler - Instance: {$this->instance}, Program: {$this->programUID}, Dataset: {$this->datasetUID}, Type: {$this->programType}, TE Type: {$this->trackedEntityTypeUID}");
    }

    /**
     * Check if the handler is ready for DHIS2 submission.
     * @return bool True if instance and program/dataset UID are set, false otherwise.
     */
    public function isReadyForSubmission(): bool {
        return $this->instance !== null && ($this->programUID !== null || $this->datasetUID !== null);
    }

    /**
     * Retrieves survey configuration from the database.
     * @param int $surveyId The ID of the survey.
     * @return array|null Survey configuration including DHIS2 instance, program/dataset UID, and tracked entity type UID.
     */
    private function getSurveyConfig(int $surveyId): ?array {
        // ASSUMPTION: 'dhis2_tracked_entity_type_uid' is now stored in the 'survey' table
        // This is crucial for tracker program submissions.
        $stmt = $this->pdo->prepare("
            SELECT dhis2_instance, program_dataset, dhis2_tracked_entity_type_uid
            FROM survey
            WHERE id = ?
        ");
        $stmt->execute([$surveyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Determines if a given UID belongs to a DHIS2 program (tracker/event) or a dataset.
     * Caches the result to avoid redundant API calls within the same request.
     *
     * @param string $uid The DHIS2 UID to check.
     * @return string 'tracker', 'event', or 'dataset'.
     * @throws Exception If the type cannot be determined after trying both.
     */
    private function determineProgramType(string $uid): string {
        static $typeCache = [];
        if (isset($typeCache[$uid])) {
            return $typeCache[$uid];
        }

        // Try to fetch as program first
        $programResponse = dhis2_get("programs/$uid?fields=id,programType", $this->instance);
        if ($programResponse && isset($programResponse['programType'])) {
            $programType = strtolower($programResponse['programType']);
            error_log("Detected program type from /programs: $programType for UID: $uid");
            $type = ($programType === 'with_registration') ? 'tracker' : 'event';
            $typeCache[$uid] = $type;
            return $type;
        }

        // If not a program, try to fetch as dataset
        $datasetResponse = dhis2_get("dataSets/$uid?fields=id,name", $this->instance);
        if ($datasetResponse && isset($datasetResponse['id'])) {
            error_log("Detected dataset from /dataSets for UID: $uid");
            $typeCache[$uid] = 'dataset';
            return 'dataset';
        }

        error_log("ERROR: Could not determine type for UID: $uid after trying programs and datasets.");
        // Fallback or throw an error if determination is critical
        throw new Exception("Could not determine DHIS2 type (program or dataset) for UID: $uid.");
    }

    /**
     * Loads system field mappings for available system fields.
     * ASSUMPTION: dhis2_system_field_mapping table has columns for dhis2_dataelement_id, dhis2_attribute_id, dhis2_option_set_id.
     */
    private function loadSystemFieldMappings(): void {
        $stmt = $this->pdo->prepare("
            SELECT field_name, dhis2_dataelement_id, dhis2_attribute_id, dhis2_option_set_id
            FROM dhis2_system_field_mapping
        ");
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->fieldMappingCache[$row['field_name']] = $row;
        }
        error_log("Loaded system field mappings: " . json_encode($this->fieldMappingCache));
    }

    /**
     * Retrieves the DHIS2 UID for a given local field name and type (data_element or attribute).
     * @param string $fieldName The local field name.
     * @param string $dhis2FieldType 'data_element' or 'attribute'.
     * @return string|null The DHIS2 UID or null if not found.
     */
    private function getMappedUID(string $fieldName, string $dhis2FieldType): ?string {
        if (!isset($this->fieldMappingCache[$fieldName])) {
            return null;
        }
        if ($dhis2FieldType === 'data_element') {
            return $this->fieldMappingCache[$fieldName]['dhis2_dataelement_id'];
        } elseif ($dhis2FieldType === 'attribute') {
            return $this->fieldMappingCache[$fieldName]['dhis2_attribute_id'];
        }
        return null;
    }

    /**
     * Retrieves the DHIS2 Option Set UID for a given local field name.
     * @param string $fieldName The local field name.
     * @return string|null The DHIS2 Option Set UID or null if not found.
     */
    private function getMappedOptionSetId(string $fieldName): ?string {
        return $this->fieldMappingCache[$fieldName]['dhis2_option_set_id'] ?? null;
    }

    /**
     * Processes a submission, preparing and sending the payload to DHIS2.
     * @param int $submissionId The ID of the local submission.
     * @return array Success status and message.
     */
    public function processSubmission(int $submissionId): array {
    if (!$this->isReadyForSubmission()) {
        $message = 'DHIS2 handler not configured for submission (missing instance or program UID).';
        $this->markAsSubmitted($submissionId, 'SKIPPED', null, null, $message); // Log skipped attempts
        return ['success' => false, 'message' => $message];
    }

    // Fetch existing log entry to handle 'retries' correctly if markAsSubmitted does a simple increment
    // Or simply let ON DUPLICATE KEY UPDATE handle the `retries = retries + 1`
    // Given the ON DUPLICATE KEY UPDATE logic in markAsSubmitted, we just need to call it.

    try {
        $submissionData = $this->getSubmissionData($submissionId);
        if (!$submissionData) {
            $message = "Submission not found for ID: $submissionId";
            $this->markAsSubmitted($submissionId, 'FAILED', null, null, $message); // Log not found submissions
            throw new Exception($message);
        }

        $responses = $this->getSubmissionResponses($submissionId);
        $uniqueTrackerEventOrEnrollmentUID = $this->generateUniqueTrackerEventOrEnrollmentUID($submissionId);
        $payload = $this->prepareDHIS2Payload($submissionData, $responses, $uniqueTrackerEventOrEnrollmentUID);

        error_log("DHIS2 Payload ({$this->programType}): " . json_encode($payload, JSON_PRETTY_PRINT));

        $result = $this->submitToDHIS2($payload); // This returns ['success' => bool, 'message' => string, 'dhis2_raw_response' => array]

        // Mark as submitted regardless of success, capturing the DHIS2 response and message
        $status = $result['success'] ? 'SUCCESS' : 'FAILED';
        $this->markAsSubmitted($submissionId, $status, $payload, $result['dhis2_raw_response'] ?? null, $result['message']);

        return $result;

    } catch (Exception $e) {
        $errorMsg = "Final submission error: " . $e->getMessage();
        error_log("DHIS2 Submission Error for submission ID $submissionId (Survey ID: {$this->surveyId}): " . $errorMsg);
        $this->markAsSubmitted($submissionId, 'FAILED', $payload ?? null, null, $errorMsg); // Log if an exception occurred before submitToDHIS2 returned
        return ['success' => false, 'message' => $errorMsg];
    }
}


    /**
     * Checks if a submission has already been successfully sent to DHIS2.
     * @param int $submissionId
     * @return bool
     */
    private function isAlreadySubmitted(int $submissionId): bool {
        $stmt = $this->pdo->prepare("
            SELECT id FROM dhis2_submission_log
            WHERE submission_id = ? AND status = 'SUCCESS'
        ");
        $stmt->execute([$submissionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    /**
     * Marks a local submission as successfully sent to DHIS2.
     * @param int $submissionId
     */
     private function markAsSubmitted(int $submissionId, string $status, ?array $payload = null, ?array $dhis2Response = null, ?string $dhis2Message = null): void {
            // Prepare JSON data for storage
            $payloadJson = $payload ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
            $dhis2ResponseJson = $dhis2Response ? json_encode($dhis2Response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

            $stmt = $this->pdo->prepare("
                INSERT INTO dhis2_submission_log (submission_id, status, payload_sent, dhis2_response, dhis2_message, submitted_at, retries)
                VALUES (?, ?, ?, ?, ?, NOW(), 0)
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    payload_sent = VALUES(payload_sent),
                    dhis2_response = VALUES(dhis2_response),
                    dhis2_message = VALUES(dhis2_message),
                    submitted_at = NOW(),
                    retries = retries + 1 -- Increment retries on update
            ");

            $stmt->execute([
                $submissionId,
                $status,
                $payloadJson,
                $dhis2ResponseJson,
                $dhis2Message
            ]);
            error_log("Submission ID $submissionId marked as $status in dhis2_submission_log. Message: " . ($dhis2Message ?? 'N/A'));
        }

    /**
     * Generates a consistent unique UID for tracker entities/events/enrollments
     * based on local submission ID. Uses DHIS2 standard 11-character UID format.
     * @param mixed $identifier The identifier to generate UID from
     * @return string 11-character DHIS2-compliant UID
     */
    private function generateUniqueTrackerEventOrEnrollmentUID($identifier): string {
        // Create a deterministic UID that follows DHIS2 conventions
        // DHIS2 UIDs: 11 characters, alphanumeric, first character must be a letter
        $baseString = $identifier . '-' . $this->surveyId . '-' . ($this->programUID ?? $this->datasetUID) . '-' . $this->programType;
        $hash = md5($baseString);
        
        // Ensure first character is a letter (DHIS2 requirement)
        $firstChar = chr(ord('A') + (hexdec($hash[0]) % 26));
        
        // Get alphanumeric characters from hash
        $alphanumeric = '';
        for ($i = 1; $i < strlen($hash) && strlen($alphanumeric) < 10; $i++) {
            $char = $hash[$i];
            if (ctype_alnum($char) && $char !== '0' && $char !== 'O') { // Avoid confusing characters
                $alphanumeric .= strtoupper($char);
            }
        }
        
        // If we don't have enough characters, pad with numbers
        $remaining = str_pad($alphanumeric, 10, '123456789', STR_PAD_RIGHT);
        $remaining = substr($remaining, 0, 10);
        
        $uid = $firstChar . $remaining;
        
        // Ensure exactly 11 characters and valid format
        $uid = substr($uid, 0, 11);
        if (strlen($uid) < 11) {
            $uid = str_pad($uid, 11, '1');
        }
        
        error_log("Generated UID for identifier '$identifier': $uid");
        return $uid;
    }

    /**
     * Fetches submission main data (location and survey info).
     * @param int $submissionId
     * @return array|null
     */
    private function getSubmissionData(int $submissionId): ?array {
        $stmt = $this->pdo->prepare("
            SELECT
                s.id, s.uid, s.location_id, s.survey_id, s.created,
                l.uid as location_uid
            FROM submission s
            LEFT JOIN location l ON s.location_id = l.id
            WHERE s.id = ?
        ");
        $stmt->execute([$submissionId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($data) {
            // Add a default period based on created date since period column doesn't exist
            $data['period'] = date('Y-m-d', strtotime($data['created']));
            error_log("Fetched submission data: " . json_encode($data, JSON_PRETTY_PRINT));
        } else {
            error_log("No submission data found for ID: $submissionId");
        }
        return $data;
    }

    /**
     * Fetches all question responses for a given submission.
     * @param int $submissionId
     * @return array Assoc array [question_id => ['value' => 'response']]
     */
    private function getSubmissionResponses(int $submissionId): array {
        $responses = [];
        $stmt = $this->pdo->prepare("
            SELECT sr.question_id, sr.response_value
            FROM submission_response sr
            WHERE sr.submission_id = ?
        ");
        $stmt->execute([$submissionId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $responses[$row['question_id']] = [
                'value' => $row['response_value']
            ];
        }
        if (!empty($responses)) {
            error_log("Fetched submission responses: " . json_encode($responses, JSON_PRETTY_PRINT));
        } else {
            error_log("No responses found for submission ID: $submissionId");
        }
        return $responses;
    }

    /**
     * Prepares the main DHIS2 payload based on program type.
     * @param array $submissionData
     * @param array $responses
     * @param string $uniqueUID A unique identifier for the event/enrollment/TEI.
     * @return array The prepared DHIS2 payload.
     * @throws Exception If an unsupported program type is encountered.
     */
    private function prepareDHIS2Payload(array $submissionData, array $responses, string $uniqueUID): array {
        $eventDate = $submissionData['period'] ?? date('Y-m-d'); // Use 'period' or current date

        switch ($this->programType) {
            case 'event':
                $dataValuesByStage = $this->prepareProgramStageDataElements($submissionData, $responses, 'event');
                return $this->prepareEventPayload($submissionData, $dataValuesByStage, $uniqueUID, $eventDate);

            case 'tracker':
                $trackedEntityAttributes = $this->prepareTrackedEntityAttributes($submissionData, $responses);
                $programStageDataValues = $this->prepareProgramStageDataElements($submissionData, $responses, 'tracker');
                return $this->prepareTrackerPayload($submissionData, $trackedEntityAttributes, $programStageDataValues, $uniqueUID, $eventDate);

            case 'dataset':
                $dataValuesByStage = $this->prepareProgramStageDataElements($submissionData, $responses, 'dataset'); // Using this for general data elements
                // For datasets, flatten all data values into a single array
                $allDataValues = [];
                foreach ($dataValuesByStage as $stageId => $dataValues) {
                    $allDataValues = array_merge($allDataValues, $dataValues);
                }
                return $this->prepareDatasetPayload($submissionData, $allDataValues, $eventDate); // 'eventDate' is used for 'period' here

            default:
                throw new Exception("Unsupported program type encountered: {$this->programType}");
        }
    }

    /**
     * Prepares data elements for an Event Program event or a Tracker Program stage event.
     * Also handles internal system fields that map to Data Elements.
     * @param array $submissionData
     * @param array $responses All local submission responses.
     * @param string $context 'event', 'tracker', or 'dataset' to guide system field mapping.
     * @return array DHIS2 Data Values array grouped by program stage.
     */
    private function prepareProgramStageDataElements(array $submissionData, array $responses, string $context): array {
        $dataValuesByStage = [];

        // 1. Add system fields mapped as Data Elements
        // Note: Previously used demographic fields (ownership, service_unit, age, sex) have been removed
        // from the database schema and are no longer processed here.

        // Period (if needed as a data element, though often part of event metadata)
        $periodDE = $this->getMappedUID('period', 'data_element');
        if (!empty($submissionData['period']) && $periodDE) {
            // For system fields, use the first program stage as default
            $defaultStage = $this->getFirstProgramStageUID();
            $dataValuesByStage[$defaultStage][] = ['dataElement' => $periodDE, 'value' => $submissionData['period']];
            error_log("Added system field (DE) 'period': {$submissionData['period']} (DE: $periodDE)");
        }

        // 2. Add local question responses mapped to DHIS2 Data Elements
        // ASSUMPTION: question_dhis2_mapping has `dhis2_dataelement_id` and `dhis2_option_set_id` for DEs
        $questionIds = array_keys($responses);
        if (!empty($questionIds)) {
            $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
            $stmt = $this->pdo->prepare("
                SELECT qm.question_id, qm.dhis2_dataelement_id, qm.dhis2_option_set_id, qm.dhis2_program_stage_id
                FROM question_dhis2_mapping qm
                WHERE qm.question_id IN ($placeholders)
                  AND qm.dhis2_dataelement_id IS NOT NULL
                  AND qm.dhis2_dataelement_id != ''
                  AND qm.dhis2_dataelement_id != 'category_combo' -- category_combo is handled separately for aggregate/tracker events
            ");
            $stmt->execute($questionIds);
            $questionMappings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($questionMappings as $mapping) {
                $questionId = $mapping['question_id'];
                $responseValue = $responses[$questionId]['value'] ?? null;

                if (empty($responseValue)) {
                    error_log("DEBUG: Skipping empty response for question ID: $questionId (DE)");
                    continue;
                }

                $valueToSubmit = $responseValue;
                if (!empty($mapping['dhis2_option_set_id'])) {
                    $optionCode = $this->getOptionCode($responseValue, $mapping['dhis2_option_set_id']);
                    if ($optionCode) {
                        $valueToSubmit = $optionCode;
                    } else {
                        error_log("WARNING: No option mapping found for question ID $questionId value '$responseValue' in option set '{$mapping['dhis2_option_set_id']}'. Skipping.");
                        continue;
                    }
                }

                $programStage = $mapping['dhis2_program_stage_id'] ?? $this->getFirstProgramStageUID();
                $dataValuesByStage[$programStage][] = [
                    'dataElement' => $mapping['dhis2_dataelement_id'],
                    'value' => (string)$valueToSubmit
                ];
                error_log("Mapped question (DE) $questionId: '$responseValue' -> '$valueToSubmit' (DE: {$mapping['dhis2_dataelement_id']}, Stage: $programStage)");
            }
        }
        return $dataValuesByStage;
    }

    /**
     * Prepares tracked entity attributes for a Tracker Program TEI.
     * Also handles internal system fields that map to Tracked Entity Attributes.
     * @param array $submissionData
     * @param array $responses All local submission responses.
     * @return array DHIS2 Attributes array.
     */
    private function prepareTrackedEntityAttributes(array $submissionData, array $responses): array {
        $attributes = [];

        // 1. Add system fields mapped as Tracked Entity Attributes
        // Note: Previously used demographic fields (ownership, service_unit, age, sex) have been removed
        // from the database schema and are no longer processed here.

        // 2. Add local question responses mapped to DHIS2 Tracked Entity Attributes
        // ASSUMPTION: question_dhis2_mapping has `dhis2_attribute_id` and `dhis2_option_set_id` for TEAs
        $questionIds = array_keys($responses);
        if (!empty($questionIds)) {
            $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
            $stmt = $this->pdo->prepare("
                SELECT qm.question_id, qm.dhis2_attribute_id, qm.dhis2_option_set_id
                FROM question_dhis2_mapping qm
                WHERE qm.question_id IN ($placeholders)
                  AND qm.dhis2_attribute_id IS NOT NULL
                  AND qm.dhis2_attribute_id != ''
            ");
            $stmt->execute($questionIds);
            $questionMappings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($questionMappings as $mapping) {
                $questionId = $mapping['question_id'];
                $responseValue = $responses[$questionId]['value'] ?? null;

                if (empty($responseValue)) {
                    error_log("DEBUG: Skipping empty response for question ID: $questionId (TEA)");
                    continue;
                }

                $valueToSubmit = $responseValue;
                if (!empty($mapping['dhis2_option_set_id'])) {
                    $optionCode = $this->getOptionCode($responseValue, $mapping['dhis2_option_set_id']);
                    if ($optionCode) {
                        $valueToSubmit = $optionCode;
                    } else {
                        error_log("WARNING: No option mapping found for question ID $questionId value '$responseValue' in option set '{$mapping['dhis2_option_set_id']}'. Skipping.");
                        continue;
                    }
                }

                $attributes[] = [
                    'attribute' => $mapping['dhis2_attribute_id'],
                    'value' => (string)$valueToSubmit
                ];
                error_log("Mapped question (TEA) $questionId: '$responseValue' -> '$valueToSubmit' (TEA: {$mapping['dhis2_attribute_id']})");
            }
        }
        return $attributes;
    }

    /**
     * Prepares the payload for an DHIS2 Event Program using modern tracker API.
     * @param array $submissionData
     * @param array $dataValuesByStage Data elements grouped by program stage.
     * @param string $baseEventUID Base UID for events.
     * @param string $eventDate Date of the event.
     * @return array Modern DHIS2 tracker API payload
     */
    private function prepareEventPayload(array $submissionData, array $dataValuesByStage, string $baseEventUID, string $eventDate): array {
        $events = [];
        $eventCounter = 1;
        
        foreach ($dataValuesByStage as $programStageId => $dataValues) {
            if (empty($dataValues)) continue;
            
            $eventUID = $eventCounter === 1 ? $baseEventUID : $this->generateUniqueTrackerEventOrEnrollmentUID($submissionData['id'] . '_Event_' . $eventCounter);
            
            $events[] = [
                'event' => $eventUID,
                'orgUnit' => $submissionData['location_uid'],
                'program' => $this->programUID,
                'programStage' => $programStageId,
                'occurredAt' => $eventDate,
                'scheduledAt' => $eventDate,
                'status' => 'COMPLETED',
                'completedAt' => date('Y-m-d\TH:i:s.000'),
                'storedBy' => 'FBS_System',
                'dataValues' => array_map(function($dv) {
                    return [
                        'dataElement' => $dv['dataElement'],
                        'value' => (string)$dv['value'],
                        'providedElsewhere' => false
                    ];
                }, $dataValues)
            ];
            
            error_log("Created event for program stage: $programStageId with " . count($dataValues) . " data values");
            $eventCounter++;
        }
        
        return [
            'events' => $events
        ];
    }

    /**
     * Prepares the payload for a DHIS2 Tracker Program using modern tracker API.
     * @param array $submissionData
     * @param array $trackedEntityAttributes Attributes for the TEI.
     * @param array $programStageDataValues Data elements for the program stage event.
     * @param string $enrollmentUID Unique UID for the enrollment.
     * @param string $eventDate Date of the event/enrollment.
     * @return array Modern DHIS2 tracker API payload
     * @throws Exception If tracked entity type UID is missing.
     */
    private function prepareTrackerPayload(array $submissionData, array $trackedEntityAttributes, array $programStageDataValuesByStage, string $enrollmentUID, string $eventDate): array {
        if (empty($this->trackedEntityTypeUID)) {
            error_log("ERROR: Tracked Entity Type UID is not set for tracker program {$this->programUID}. Cannot create payload.");
            throw new Exception("Tracked Entity Type UID missing for tracker program.");
        }

        // Generate consistent UIDs for TEI
        $trackedEntityUID = $this->generateUniqueTrackerEventOrEnrollmentUID($submissionData['id'] . '_TEI');

        // Create events for each program stage that has data
        $events = [];
        $eventCounter = 1;
        
        foreach ($programStageDataValuesByStage as $programStageId => $dataValues) {
            if (empty($dataValues)) continue;
            
            $eventUID = $this->generateUniqueTrackerEventOrEnrollmentUID($submissionData['id'] . '_Event_' . $eventCounter);
            
            $events[] = [
                'event' => $eventUID,
                'program' => $this->programUID,
                'programStage' => $programStageId,
                'orgUnit' => $submissionData['location_uid'],
                'occurredAt' => $eventDate,
                'scheduledAt' => $eventDate,
                'status' => 'COMPLETED',
                'completedAt' => date('Y-m-d\TH:i:s.000'),
                'storedBy' => 'FBS_System',
                'dataValues' => array_map(function($dv) {
                    return [
                        'dataElement' => $dv['dataElement'],
                        'value' => (string)$dv['value'],
                        'providedElsewhere' => false
                    ];
                }, $dataValues)
            ];
            
            error_log("Created event for program stage: $programStageId with " . count($dataValues) . " data values");
            $eventCounter++;
        }

        // Modern tracker API payload structure
        return [
            'trackedEntities' => [
                [
                    'trackedEntity' => $trackedEntityUID,
                    'trackedEntityType' => $this->trackedEntityTypeUID,
                    'orgUnit' => $submissionData['location_uid'],
                    'attributes' => array_map(function($attr) {
                        return [
                            'attribute' => $attr['attribute'],
                            'value' => (string)$attr['value']
                        ];
                    }, $trackedEntityAttributes),
                    'enrollments' => [
                        [
                            'enrollment' => $enrollmentUID,
                            'program' => $this->programUID,
                            'orgUnit' => $submissionData['location_uid'],
                            'enrolledAt' => $eventDate,
                            'occurredAt' => $eventDate,
                            'status' => 'COMPLETED',
                            'events' => $events
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Retrieves the UID of the first program stage for the current program.
     * Caches the result.
     * @return string The program stage UID.
     * @throws Exception If no program stage is found.
     */
    private function getProgramStageUID(): string {
        return $this->getFirstProgramStageUID();
    }

    /**
     * Retrieves the UID of the first program stage for the current program.
     * Caches the result.
     * @return string The program stage UID.
     * @throws Exception If no program stage is found.
     */
    private function getFirstProgramStageUID(): string {
        static $programStageCache = [];
        if (isset($programStageCache[$this->programUID])) {
            return $programStageCache[$this->programUID];
        }

        // Fetch program stages for the current program UID
        $response = dhis2_get("programs/{$this->programUID}?fields=programStages[id]", $this->instance);

        if ($response && isset($response['programStages'][0]['id'])) {
            $programStageId = $response['programStages'][0]['id'];
            $programStageCache[$this->programUID] = $programStageId;
            error_log("Found program stage: $programStageId for program: {$this->programUID}");
            return $programStageId;
        }

        error_log("ERROR: Could not find any program stage for program: {$this->programUID}. This is required for Tracker Programs to submit events.");
        throw new Exception("Could not find program stage for program: {$this->programUID}.");
    }

    /**
     * Prepares the payload for a DHIS2 Aggregate Dataset.
     * @param array $submissionData
     * @param array $dataValues Data elements for the dataset (these are often 'categoryOptionCombos').
     * @param string $period The period string (e.g., '2024-01-15').
     * @return array
     * @throws Exception If dataset UID is not set.
     */
    private function prepareDatasetPayload(array $submissionData, array $dataValues, string $period): array {
        if ($this->datasetUID === null) {
            throw new Exception("Dataset UID not set for dataset submission.");
        }

        $dhis2Period = $this->convertToDHIS2Period($period);
        $orgUnit = (string)$submissionData['location_uid'];
        $preparedDataValues = [];

        // DHIS2 default COC/AOC. Confirm these are correct for your DHIS2 instance.
        $defaultCategoryOptionCombo = 'HllvX50cXC0';
        $defaultAttributeOptionCombo = 'HllvX50cXC0';

        // Check for specific category combo / attribute combo from survey responses if mapped
        $submittedCategoryOptionCombo = $this->getCategoryComboFromResponses($submissionData['id'], 'category_combo');
        $submittedAttributeOptionCombo = $this->getCategoryComboFromResponses($submissionData['id'], 'attribute_option_combo'); // Assuming 'attribute_option_combo' can be a mapped field in your DB

        foreach ($dataValues as $dv) {
            // Data elements in datasets often have their own categoryOptionCombo.
            // If not specified or if it's the 'default' DHIS2 one, use the dataset's overall category combo
            // or the default 'HllvX50cXC0'.
            // The `prepareProgramStageDataElements` method currently just returns `dataElement` and `value`.
            // For datasets, DHIS2 expects `categoryOptionCombo` and `attributeOptionCombo` per data value.

            // Get the specific COC for this data element if it has one (from its definition)
            $deSpecificCOC = $this->getDataElementCategoryCombo($dv['dataElement']);
            $finalCategoryOptionCombo = $deSpecificCOC ?: $submittedCategoryOptionCombo ?: $defaultCategoryOptionCombo;
            $finalAttributeOptionCombo = $submittedAttributeOptionCombo ?: $defaultAttributeOptionCombo;

            $dataEntry = [
                'dataElement' => (string)$dv['dataElement'],
                'value' => (string)$dv['value'],
                'categoryOptionCombo' => $finalCategoryOptionCombo,
                'attributeOptionCombo' => $finalAttributeOptionCombo
            ];

            // Add comment if available (assuming 'comment' might be in $dv if you expand prepareProgramStageDataElements)
            if (isset($dv['comment']) && !empty($dv['comment'])) {
                $dataEntry['comment'] = (string)$dv['comment'];
            }

            $preparedDataValues[] = $dataEntry;
            error_log("Prepared Dataset DV: DE: {$dv['dataElement']}, Value: {$dv['value']}, COC: {$finalCategoryOptionCombo}, AOC: {$finalAttributeOptionCombo}");
        }

        return [
            'dataSet' => $this->datasetUID,
            'period' => $dhis2Period,
            'orgUnit' => $orgUnit,
            'dataValues' => $preparedDataValues,
            'completedDate' => date('Y-m-d') // Required for some datasets
        ];
    }

    /**
     * Attempts to get a CategoryOptionCombo (or AttributeOptionCombo) from submission responses.
     * This relies on a question being explicitly mapped to 'category_combo' or 'attribute_option_combo'
     * in your `question_dhis2_mapping` table.
     * @param int $submissionId
     * @param string $mappingIdentifier 'category_combo' or 'attribute_option_combo'
     * @return string|null
     */
    private function getCategoryComboFromResponses(int $submissionId, string $mappingIdentifier): ?string {
        // ASSUMPTION: 'category_combo' or 'attribute_option_combo' are stored in
        // question_dhis2_mapping.dhis2_dataelement_id for special handling.
        $stmt = $this->pdo->prepare("
            SELECT sr.response_value, qm.dhis2_option_set_id
            FROM submission_response sr
            JOIN question_dhis2_mapping qm ON sr.question_id = qm.question_id
            WHERE sr.submission_id = ? AND qm.dhis2_dataelement_id = ?
            LIMIT 1
        ");
        $stmt->execute([$submissionId, $mappingIdentifier]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['response_value'])) {
            $code = $this->getOptionCode($row['response_value'], $row['dhis2_option_set_id']);
            error_log("Derived $mappingIdentifier: {$row['response_value']} -> $code (Option Set: {$row['dhis2_option_set_id']})");
            return $code;
        }
        error_log("DEBUG: No $mappingIdentifier found for submission $submissionId.");
        return null;
    }

    /**
     * Gets the specific CategoryOptionCombo for a Data Element (for aggregate submissions).
     * Caches results.
     * @param string $dataElementUid
     * @return string|null
     */
    private function getDataElementCategoryCombo(string $dataElementUid): ?string {
        static $deCategoryComboCache = [];
        if (isset($deCategoryComboCache[$dataElementUid])) {
            return $deCategoryComboCache[$dataElementUid];
        }

        // Fetch DE details to get its specific categoryCombo if it's not the default
        $response = dhis2_get("dataElements/$dataElementUid?fields=categoryCombo[id,name]", $this->instance);

        if ($response && isset($response['categoryCombo']['id']) &&
            (!isset($response['categoryCombo']['name']) || !preg_match('/default/i', $response['categoryCombo']['name']))) {
            $deCategoryComboCache[$dataElementUid] = $response['categoryCombo']['id'];
            error_log("Found specific COC for DE $dataElementUid: {$response['categoryCombo']['id']}");
            return $response['categoryCombo']['id'];
        }

        $deCategoryComboCache[$dataElementUid] = null;
        return null;
    }


    /**
     * Converts a period string into a DHIS2-compliant period format.
     * Supports YYYY-MM-DD, YYYY-MM, YYYY, YYYY-QX, YYYY-WX.
     * @param string $period The input period string.
     * @return string The DHIS2 formatted period.
     */
    private function convertToDHIS2Period(string $period): string {
        $period = trim($period);

        // Daily: YYYY-MM-DD -> YYYYMMDD
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $period, $m)) {
            return sprintf('%04d%02d%02d', $m[1], $m[2], $m[3]);
        }
        // Monthly: YYYY-MM -> YYYYMM
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $period, $m)) {
            return sprintf('%04d%02d', $m[1], $m[2]);
        }
        // Quarterly: YYYY-QX or YYYYQX -> YYYYQX
        if (preg_match('/^(\d{4})-?Q([1-4])$/i', $period, $m)) {
            return sprintf('%04dQ%d', $m[1], $m[2]);
        }
        // Weekly: YYYY-WX or YYYYWX -> YYYYWXX (e.g., 2024W05)
        if (preg_match('/^(\d{4})-?W(\d{1,2})$/i', $period, $m)) {
            return sprintf('%04dW%02d', $m[1], $m[2]);
        }
        // Yearly: YYYY -> YYYY
        if (preg_match('/^\d{4}$/', $period)) {
            return $period;
        }

        // Fallback: Try to parse as a date and format as monthly.
        // This is a last resort and may not be accurate for all DHIS2 period types.
        try {
            $date = new DateTime($period);
            error_log("WARNING: Could not determine DHIS2 period format for '$period'. Falling back to YYYYMM.");
            return $date->format('Ym');
        } catch (Exception $e) {
            error_log("ERROR: Invalid period format '$period'. Returning as-is which may cause DHIS2 error.");
            return $period; // Return as-is, DHIS2 will likely reject it
        }
    }


    /**
     * Processes local value to find corresponding DHIS2 option code.
     * @param string $localValue The value from local survey.
     * @param string|null $optionSetId DHIS2 Option Set UID.
     * @return string|null The DHIS2 option code or null if not found.
     */
    private function getOptionCode(string $localValue, ?string $optionSetId): ?string {
        if (empty($optionSetId)) {
            error_log("DEBUG: No optionSetId provided for local value '$localValue'. Returning local value.");
            return $localValue; // No option set, return value as is
        }

        // Cache option lookups to reduce DB queries
        static $optionCodeCache = [];
        if (isset($optionCodeCache[$optionSetId][$localValue])) {
            return $optionCodeCache[$optionSetId][$localValue];
        }

        // Try direct lookup first
        $stmt = $this->pdo->prepare("
            SELECT dhis2_option_code
            FROM dhis2_option_set_mapping
            WHERE local_value = ? AND dhis2_option_set_id = ?
        ");
        $stmt->execute([$localValue, $optionSetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $optionCodeCache[$optionSetId][$localValue] = $row['dhis2_option_code'];
            return $row['dhis2_option_code'];
        }

        // Try case-insensitive lookup as fallback
        $stmt = $this->pdo->prepare("
            SELECT dhis2_option_code
            FROM dhis2_option_set_mapping
            WHERE LOWER(local_value) = LOWER(?) AND dhis2_option_set_id = ?
        ");
        $stmt->execute([$localValue, $optionSetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            error_log("WARNING: Case-insensitive match for '$localValue' found in option set '$optionSetId'. Consider standardizing casing.");
            $optionCodeCache[$optionSetId][$localValue] = $row['dhis2_option_code'];
            return $row['dhis2_option_code'];
        }

        // Try trimmed lookup as additional fallback for whitespace issues
        $trimmedValue = trim($localValue);
        if ($trimmedValue !== $localValue) {
            $stmt = $this->pdo->prepare("
                SELECT dhis2_option_code
                FROM dhis2_option_set_mapping
                WHERE TRIM(local_value) = ? AND dhis2_option_set_id = ?
            ");
            $stmt->execute([$trimmedValue, $optionSetId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                error_log("WARNING: Whitespace-trimmed match for '$localValue' -> '$trimmedValue' found in option set '$optionSetId'. Consider cleaning data.");
                $optionCodeCache[$optionSetId][$localValue] = $row['dhis2_option_code'];
                return $row['dhis2_option_code'];
            }
        }

        error_log("WARNING: No DHIS2 option code found for local value '$localValue' in option set '$optionSetId'.");
        $optionCodeCache[$optionSetId][$localValue] = null; // Cache null to avoid repeated lookups
        return null;
    }

    /**
     * Sends the prepared payload to DHIS2.
     * @param array $payload The DHIS2 API payload.
     * @return array Success status and message.
     * @throws Exception On DHIS2 API errors.
     */
   private function submitToDHIS2(array $payload): array {
    try {
        $endpoint = $this->getAPIEndpoint();
        error_log("Submitting to DHIS2 endpoint: {$endpoint} for instance: {$this->instance}");

        $response = dhis2_post($endpoint, $payload, $this->instance); // This is where dhis2Curl is called

        if ($response === null) {
            return ['success' => false, 'message' => "DHIS2 API returned null response. Check network or DHIS2 server.", 'dhis2_raw_response' => null];
        }

        error_log("Raw DHIS2 Response for {$endpoint}: " . json_encode($response, JSON_PRETTY_PRINT));

        if ($this->isSuccessfulResponse($response)) {
            // Check if this is an async job response
            if (isset($response['response']['id']) && isset($response['message']) && strpos($response['message'], 'job added') !== false) {
                $jobId = $response['response']['id'];
                $message = "Successfully submitted to DHIS2. Job ID: $jobId (processing asynchronously)";
                return ['success' => true, 'message' => $message, 'dhis2_raw_response' => $response, 'job_id' => $jobId];
            }
            
            return ['success' => true, 'message' => 'Successfully submitted to DHIS2.', 'dhis2_raw_response' => $response];
        }

        // Enhanced error message extraction for modern tracker API
        $errorMessage = $this->extractDHIS2ErrorMessage($response);


        // Check for "already exists" specifically
        if (strpos($errorMessage, 'already exists') !== false || strpos($errorMessage, 'value_not_unique') !== false || strpos($errorMessage, 'Conflict') !== false) {
             return ['success' => true, 'message' => 'Data was already submitted to DHIS2 (detected as existing or duplicate).', 'dhis2_raw_response' => $response];
        }

        // If it reaches here, it's a definite failure with a specific message
        return ['success' => false, 'message' => $errorMessage, 'dhis2_raw_response' => $response];

    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
        if (strpos($errorMsg, 'already exists') !== false || strpos($errorMsg, 'value_not_unique') !== false || strpos($errorMsg, 'Conflict') !== false) {
            return ['success' => true, 'message' => "An error occurred, but data might already exist on DHIS2: " . $errorMsg, 'dhis2_raw_response' => null]; // Cannot provide raw response for CURL exception
        }
        throw $e; // Re-throw other exceptions
    }
}

    /**
     * Determines the correct DHIS2 API endpoint based on the program type.
     * Uses modern DHIS2 API endpoints (v2.37+).
     * @return string The API endpoint.
     */
    private function getAPIEndpoint(): string {
        switch ($this->programType) {
            case 'event':
                // Use synchronous mode with detailed validation reporting
                return '/api/tracker?async=false&importReportMode=FULL&validationMode=FULL&importStrategy=CREATE_AND_UPDATE';
            case 'tracker':
                // Use synchronous mode with detailed validation reporting  
                return '/api/tracker?async=false&importReportMode=FULL&validationMode=FULL&importStrategy=CREATE_AND_UPDATE';
            case 'dataset':
                return '/api/dataValueSets'; // Dataset endpoint remains the same
            default:
                throw new Exception("Cannot determine API endpoint for unknown program type: {$this->programType}");
        }
    }

    /**
     * Checks if the DHIS2 API response indicates success.
     * Updated for modern tracker API (v2.37+) response format.
     * @param array $response The decoded JSON response from DHIS2.
     * @return bool True if successful, false otherwise.
     */
    private function isSuccessfulResponse(array $response): bool {
        // Check HTTP status code first
        if (isset($response['httpStatusCode']) && ($response['httpStatusCode'] === 200 || $response['httpStatusCode'] === 201)) {
            
            // Modern tracker API response format
            if ($this->programType === 'event' || $this->programType === 'tracker') {
                // Check for modern tracker API success indicators
                if (isset($response['status']) && $response['status'] === 'OK') {
                    return true;
                }
                
                // Check bundle report for detailed status
                if (isset($response['bundleReport'])) {
                    $bundleReport = $response['bundleReport'];
                    
                    // Check overall status
                    if (isset($bundleReport['status']) && $bundleReport['status'] === 'OK') {
                        return true;
                    }
                    
                    // Check type reports for specific entity types
                    if (isset($bundleReport['typeReportMap'])) {
                        foreach ($bundleReport['typeReportMap'] as $entityType => $typeReport) {
                            if (isset($typeReport['stats'])) {
                                $stats = $typeReport['stats'];
                                $successful = ($stats['created'] ?? 0) + ($stats['updated'] ?? 0) + ($stats['ignored'] ?? 0);
                                if ($successful > 0 && ($stats['invalid'] ?? 0) === 0) {
                                    return true;
                                }
                            }
                        }
                    }
                }
            } elseif ($this->programType === 'dataset') {
                // Dataset API response format (unchanged)
                return (isset($response['status']) && $response['status'] === 'SUCCESS');
            }
        }
        
        // Modern async job response (tracker API)
        if (isset($response['status']) && $response['status'] === 'OK' && 
            isset($response['message']) && strpos($response['message'], 'job added') !== false) {
            return true; // Async job submitted successfully
        }
        
        // Legacy response format fallback
        if (isset($response['status']) && ($response['status'] === 'SUCCESS' || $response['status'] === 'OK')) {
            return true;
        }

        // Legacy tracker response format
        if (isset($response['response']['importSummaries'])) {
            foreach ($response['response']['importSummaries'] as $summary) {
                if (isset($summary['status']) && $summary['status'] === 'SUCCESS') {
                    return true;
                }
            }
        }

        // Legacy import count format
        if (isset($response['response']['importCount'])) {
            $summary = $response['response']['importCount'];
            if (($summary['imported'] ?? 0) > 0 || ($summary['updated'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extracts error messages from DHIS2 API responses.
     * Handles both modern tracker API and legacy response formats.
     * @param array $response The DHIS2 API response
     * @return string The extracted error message
     */
    private function extractDHIS2ErrorMessage(array $response): string {
        // Modern tracker API error format
        if (isset($response['bundleReport'])) {
            $bundleReport = $response['bundleReport'];
            
            // Check for validation errors
            if (isset($bundleReport['typeReportMap'])) {
                $errorDetails = [];
                foreach ($bundleReport['typeReportMap'] as $entityType => $typeReport) {
                    if (isset($typeReport['objectReports'])) {
                        foreach ($typeReport['objectReports'] as $objectReport) {
                            if (isset($objectReport['errorReports']) && !empty($objectReport['errorReports'])) {
                                foreach ($objectReport['errorReports'] as $errorReport) {
                                    $errorDetails[] = "{$entityType}: {$errorReport['message']} (Code: {$errorReport['errorCode']})";
                                }
                            }
                        }
                    }
                }
                if (!empty($errorDetails)) {
                    return "DHIS2 Validation Errors: " . implode('; ', $errorDetails);
                }
            }
            
            // Check bundle status
            if (isset($bundleReport['status']) && $bundleReport['status'] === 'ERROR') {
                return "DHIS2 Bundle Error: " . ($response['message'] ?? 'Unknown bundle error');
            }
        }
        
        // Legacy error format handling
        if (isset($response['response'])) {
            $status = $response['response']['status'] ?? '';
            $description = $response['response']['description'] ?? '';
            $conflicts = $response['response']['conflicts'] ?? [];
            $message = $response['message'] ?? '';

            if ($status === 'ERROR') {
                if (!empty($description)) {
                    $errorMessage = "DHIS2 Error: " . $description;
                } else {
                    $errorMessage = "DHIS2 API Error";
                }
                
                if (!empty($conflicts)) {
                    $conflictDetails = [];
                    foreach ($conflicts as $conflict) {
                        $conflictDetails[] = "{$conflict['object']} '{$conflict['value']}' - {$conflict['property']}";
                    }
                    $errorMessage .= " Conflicts: " . implode('; ', $conflictDetails);
                }
                return $errorMessage;
            } elseif (!empty($message)) {
                return "DHIS2 API Message: " . $message;
            }
        }
        
        // Direct message check
        if (isset($response['message'])) {
            return "DHIS2 API Message: " . $response['message'];
        }
        
        // Check for HTTP error information
        if (isset($response['httpStatus']) && $response['httpStatus'] !== 200) {
            return "DHIS2 HTTP Error {$response['httpStatus']}: " . ($response['httpStatusCode'] ?? 'Unknown HTTP error');
        }
        
        return "Unknown DHIS2 submission error. Check logs for raw response.";
    }
}
?>