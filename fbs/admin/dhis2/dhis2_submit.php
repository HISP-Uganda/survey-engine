<?php
// session_start();
// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure the PDO connection is available
require_once 'connect.php'; // This should provide $pdo
if (!isset($pdo)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed: Central PDO object not found. Please check connect.php.']);
    exit();
}

require_once 'dhis2/dhis2_shared.php'; // Ensure this provides dhis2_get() and dhis2_post()

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

        $this->loadSystemFieldMappings(); // Load mappings for fields like age, sex, etc.

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
     * Loads system field mappings (e.g., for ownership, service_unit, age, sex).
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
     * @param string $fieldName The local field name (e.g., 'age', 'ownership').
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
     * based on local submission ID.
     * @param int $submissionId
     * @return string 11-character UID
     */
    private function generateUniqueTrackerEventOrEnrollmentUID(int $submissionId): string {
        // Use a consistent string for hashing to ensure idempotency for the same submission
        $baseString = $submissionId . '-' . $this->surveyId . '-' . $this->programUID . '-' . $this->programType;
        return substr(md5($baseString), 0, 11);
    }

    /**
     * Fetches submission main data (location, ownership, service unit, period, age, sex).
     * @param int $submissionId
     * @return array|null
     */
    private function getSubmissionData(int $submissionId): ?array {
        $stmt = $this->pdo->prepare("
            SELECT
                s.id, s.location_id, s.ownership_id, s.service_unit_id,
                s.period, s.age, s.sex, s.created,
                l.uid as location_uid,
                o.name as ownership_name,
                su.name as service_unit_name
            FROM submission s
            LEFT JOIN location l ON s.location_id = l.id
            LEFT JOIN owner o ON s.ownership_id = o.id
            LEFT JOIN service_unit su ON s.service_unit_id = su.id
            WHERE s.id = ?
        ");
        $stmt->execute([$submissionId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($data) {
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
                $dataValues = $this->prepareProgramStageDataElements($submissionData, $responses, 'event');
                return $this->prepareEventPayload($submissionData, $dataValues, $uniqueUID, $eventDate);

            case 'tracker':
                $trackedEntityAttributes = $this->prepareTrackedEntityAttributes($submissionData, $responses);
                $programStageDataValues = $this->prepareProgramStageDataElements($submissionData, $responses, 'tracker');
                return $this->prepareTrackerPayload($submissionData, $trackedEntityAttributes, $programStageDataValues, $uniqueUID, $eventDate);

            case 'dataset':
                $dataValues = $this->prepareProgramStageDataElements($submissionData, $responses, 'dataset'); // Using this for general data elements
                return $this->prepareDatasetPayload($submissionData, $dataValues, $eventDate); // 'eventDate' is used for 'period' here

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
     * @return array DHIS2 Data Values array.
     */
    private function prepareProgramStageDataElements(array $submissionData, array $responses, string $context): array {
        $dataValues = [];

        // 1. Add system fields mapped as Data Elements
        // Adjust these to reflect how your 'dhis2_system_field_mapping' distinguishes DEs.
        // For simplicity, I'm assuming 'ownership', 'service_unit', 'age', 'sex', 'period'
        // can all be data elements if mapped appropriately.

        // Ownership
        $ownershipDE = $this->getMappedUID('ownership', 'data_element');
        if (!empty($submissionData['ownership_name']) && $ownershipDE) {
            $ownershipCode = $this->getOptionCode($submissionData['ownership_name'], $this->getMappedOptionSetId('ownership'));
            if ($ownershipCode) {
                $dataValues[] = ['dataElement' => $ownershipDE, 'value' => $ownershipCode];
                error_log("Added system field (DE) 'ownership': {$submissionData['ownership_name']} -> $ownershipCode (DE: $ownershipDE)");
            } else { error_log("WARNING: Option code not found for system field 'ownership' value '{$submissionData['ownership_name']}'."); }
        }

        // Service Unit
        $serviceUnitDE = $this->getMappedUID('service_unit', 'data_element');
        if (!empty($submissionData['service_unit_name']) && $serviceUnitDE) {
            $serviceUnitCode = $this->getOptionCode($submissionData['service_unit_name'], $this->getMappedOptionSetId('service_unit'));
            if ($serviceUnitCode) {
                $dataValues[] = ['dataElement' => $serviceUnitDE, 'value' => $serviceUnitCode];
                error_log("Added system field (DE) 'service_unit': {$submissionData['service_unit_name']} -> $serviceUnitCode (DE: $serviceUnitDE)");
            } else { error_log("WARNING: Option code not found for system field 'service_unit' value '{$submissionData['service_unit_name']}'."); }
        }

        // Age
        $ageDE = $this->getMappedUID('age', 'data_element');
        if (!empty($submissionData['age']) && $ageDE) {
            $dataValues[] = ['dataElement' => $ageDE, 'value' => (string)$submissionData['age']];
            error_log("Added system field (DE) 'age': {$submissionData['age']} (DE: $ageDE)");
        }

        // Sex
        $sexDE = $this->getMappedUID('sex', 'data_element');
        if (!empty($submissionData['sex']) && $sexDE) {
            $sexCode = $this->getOptionCode($submissionData['sex'], $this->getMappedOptionSetId('sex'));
            if ($sexCode) {
                $dataValues[] = ['dataElement' => $sexDE, 'value' => $sexCode];
                error_log("Added system field (DE) 'sex': {$submissionData['sex']} -> $sexCode (DE: $sexDE)");
            } else { error_log("WARNING: Option code not found for system field 'sex' value '{$submissionData['sex']}'."); }
        }

        // Period (if needed as a data element, though often part of event metadata)
        $periodDE = $this->getMappedUID('period', 'data_element');
        if (!empty($submissionData['period']) && $periodDE) {
            $dataValues[] = ['dataElement' => $periodDE, 'value' => $submissionData['period']];
            error_log("Added system field (DE) 'period': {$submissionData['period']} (DE: $periodDE)");
        }

        // 2. Add local question responses mapped to DHIS2 Data Elements
        // ASSUMPTION: question_dhis2_mapping has `dhis2_dataelement_id` and `dhis2_option_set_id` for DEs
        $questionIds = array_keys($responses);
        if (!empty($questionIds)) {
            $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
            $stmt = $this->pdo->prepare("
                SELECT qm.question_id, qm.dhis2_dataelement_id, qm.dhis2_option_set_id
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

                $dataValues[] = [
                    'dataElement' => $mapping['dhis2_dataelement_id'],
                    'value' => (string)$valueToSubmit
                ];
                error_log("Mapped question (DE) $questionId: '$responseValue' -> '$valueToSubmit' (DE: {$mapping['dhis2_dataelement_id']})");
            }
        }
        return $dataValues;
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
        // Assuming your 'dhis2_system_field_mapping' table can map fields to dhis2_attribute_id.
        // For example, if 'age' and 'sex' are TEAs.

        // Ownership (as TEA, if applicable)
        $ownershipTEA = $this->getMappedUID('ownership', 'attribute');
        if (!empty($submissionData['ownership_name']) && $ownershipTEA) {
            $ownershipCode = $this->getOptionCode($submissionData['ownership_name'], $this->getMappedOptionSetId('ownership'));
            if ($ownershipCode) {
                $attributes[] = ['attribute' => $ownershipTEA, 'value' => $ownershipCode];
                error_log("Added system field (TEA) 'ownership': {$submissionData['ownership_name']} -> $ownershipCode (TEA: $ownershipTEA)");
            }
        }

        // Service Unit (as TEA, if applicable)
        $serviceUnitTEA = $this->getMappedUID('service_unit', 'attribute');
        if (!empty($submissionData['service_unit_name']) && $serviceUnitTEA) {
            $serviceUnitCode = $this->getOptionCode($submissionData['service_unit_name'], $this->getMappedOptionSetId('service_unit'));
            if ($serviceUnitCode) {
                $attributes[] = ['attribute' => $serviceUnitTEA, 'value' => $serviceUnitCode];
                error_log("Added system field (TEA) 'service_unit': {$submissionData['service_unit_name']} -> $serviceUnitCode (TEA: $serviceUnitTEA)");
            }
        }

        // Age (as TEA, if applicable)
        $ageTEA = $this->getMappedUID('age', 'attribute');
        if (!empty($submissionData['age']) && $ageTEA) {
            $attributes[] = ['attribute' => $ageTEA, 'value' => (string)$submissionData['age']];
            error_log("Added system field (TEA) 'age': {$submissionData['age']} (TEA: $ageTEA)");
        }

        // Sex (as TEA, if applicable)
        $sexTEA = $this->getMappedUID('sex', 'attribute');
        if (!empty($submissionData['sex']) && $sexTEA) {
            $sexCode = $this->getOptionCode($submissionData['sex'], $this->getMappedOptionSetId('sex'));
            if ($sexCode) {
                $attributes[] = ['attribute' => $sexTEA, 'value' => $sexCode];
                error_log("Added system field (TEA) 'sex': {$submissionData['sex']} -> $sexCode (TEA: $sexTEA)");
            }
        }

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
     * Prepares the payload for an DHIS2 Event Program.
     * @param array $submissionData
     * @param array $dataValues Data elements for the event.
     * @param string $eventUID Unique UID for the event.
     * @param string $eventDate Date of the event.
     * @return array
     */
    private function prepareEventPayload(array $submissionData, array $dataValues, string $eventUID, string $eventDate): array {
        return [
            'events' => [
                [
                    'event' => $eventUID, // This is event ID
                    'orgUnit' => $submissionData['location_uid'],
                    'program' => $this->programUID,
                    'eventDate' => $eventDate,
                    'occurredAt' => $eventDate, // DHIS2 prefers occurredAt for events
                    'status' => 'COMPLETED',
                    'dataValues' => $dataValues,
                    'storedBy' => 'LocalSystem' // Optional: Indicate who stored it
                ]
            ]
        ];
    }

    /**
     * Prepares the payload for a DHIS2 Tracker Program.
     * @param array $submissionData
     * @param array $trackedEntityAttributes Attributes for the TEI.
     * @param array $programStageDataValues Data elements for the program stage event.
     * @param string $enrollmentUID Unique UID for the enrollment.
     * @param string $eventDate Date of the event/enrollment.
     * @return array
     * @throws Exception If tracked entity type UID is missing.
     */
    private function prepareTrackerPayload(array $submissionData, array $trackedEntityAttributes, array $programStageDataValues, string $enrollmentUID, string $eventDate): array {
        if (empty($this->trackedEntityTypeUID)) {
            error_log("ERROR: Tracked Entity Type UID is not set for tracker program {$this->programUID}. Cannot create payload.");
            throw new Exception("Tracked Entity Type UID missing for tracker program.");
        }

        // Generate consistent UIDs for TEI and event within the enrollment
        $trackedEntityInstanceUID = $this->generateUniqueTrackerEventOrEnrollmentUID($submissionData['id'] . '_TEI'); // Separate UID for TEI
        $eventUID = $this->generateUniqueTrackerEventOrEnrollmentUID($submissionData['id'] . '_Event'); // Separate UID for event

        return [
            'trackedEntityInstances' => [
                [
                    'trackedEntityInstance' => $trackedEntityInstanceUID,
                    'trackedEntityType' => $this->trackedEntityTypeUID, // Dynamic TE type UID
                    'orgUnit' => $submissionData['location_uid'],
                    'attributes' => $trackedEntityAttributes, // TRACKED ENTITY ATTRIBUTES GO HERE

                    'enrollments' => [
                        [
                            'enrollment' => $enrollmentUID, // This is the enrollment ID
                            'program' => $this->programUID,
                            'orgUnit' => $submissionData['location_uid'],
                            'enrollmentDate' => $eventDate,
                            'incidentDate' => $eventDate,
                            'status' => 'COMPLETED',
                            'events' => [
                                [
                                    'event' => $eventUID, // This is the event ID for the program stage
                                    'program' => $this->programUID,
                                    'programStage' => $this->getProgramStageUID(), // Gets the default/first program stage
                                    'orgUnit' => $submissionData['location_uid'],
                                    'eventDate' => $eventDate,
                                    'status' => 'COMPLETED',
                                    'dataValues' => $programStageDataValues, // PROGRAM STAGE DATA ELEMENTS GO HERE
                                    'storedBy' => 'LocalSystem'
                                ]
                            ]
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
            return ['success' => true, 'message' => 'Successfully submitted to DHIS2.', 'dhis2_raw_response' => $response];
        }

        // Attempt to extract specific error messages from DHIS2 response
        $errorMessage = "Unknown DHIS2 submission error.";
        // ... (existing error extraction logic remains) ...
        if (isset($response['response'])) {
             $status = $response['response']['status'] ?? '';
             $description = $response['response']['description'] ?? '';
             $conflicts = $response['response']['conflicts'] ?? [];
             $message = $response['message'] ?? '';

             if ($status === 'ERROR') {
                 if (!empty($description)) $errorMessage = "DHIS2 Error: " . $description;
                 if (!empty($conflicts)) {
                     $conflictDetails = [];
                     foreach ($conflicts as $conflict) {
                         $conflictDetails[] = "{$conflict['object']} '{$conflict['value']}' - {$conflict['property']}";
                     }
                     $errorMessage .= " Conflicts: " . implode('; ', $conflictDetails);
                 }
             } elseif (!empty($message)) {
                 $errorMessage = "DHIS2 API Message: " . $message;
             }
         } else if (isset($response['message'])) {
              $errorMessage = "DHIS2 API Message: " . $response['message'];
         }


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
     * @return string The API endpoint.
     */
    private function getAPIEndpoint(): string {
        switch ($this->programType) {
            case 'event':
                return '/api/events';
            case 'tracker':
                return '/api/trackedEntityInstances';
            case 'dataset':
                return '/api/dataValueSets';
            default:
                throw new Exception("Cannot determine API endpoint for unknown program type: {$this->programType}");
        }
    }

    /**
     * Checks if the DHIS2 API response indicates success.
     * @param array $response The decoded JSON response from DHIS2.
     * @return bool True if successful, false otherwise.
     */
    private function isSuccessfulResponse(array $response): bool {
        // HTTP status code check
        if (isset($response['httpStatusCode']) && ($response['httpStatusCode'] === 200 || $response['httpStatusCode'] === 201)) {
            // Further checks based on content for 200/201
            if ($this->programType === 'event' || $this->programType === 'dataset') {
                return (isset($response['status']) && $response['status'] === 'SUCCESS');
            } elseif ($this->programType === 'tracker') {
                // For tracker, check importSummaries for success
                if (isset($response['response']['importSummaries'])) {
                    foreach ($response['response']['importSummaries'] as $summary) {
                        if (isset($summary['status']) && $summary['status'] === 'SUCCESS') {
                            return true; // At least one successful import summary
                        }
                    }
                }
            }
        }
        // Fallback for cases where DHIS2 might return 200 but an internal error status
        if (isset($response['status']) && $response['status'] === 'SUCCESS') return true;

        // For tracker/dataValueSets, sometimes 'response' and 'importCount' are present
        if (isset($response['response']['importCount'])) {
            $summary = $response['response']['importCount'];
            if (($summary['imported'] ?? 0) > 0 || ($summary['updated'] ?? 0) > 0 || ($summary['ignored'] ?? 0) > 0) {
                 // If ignored > 0 might mean duplicates, which is a success for idempotency
                 // Be careful here, "ignored" can also mean validation failure
                if (isset($response['response']['status']) && $response['response']['status'] === 'SUCCESS') {
                    return true; // Overall success
                }
            }
        }

        return false;
    }
}
?>