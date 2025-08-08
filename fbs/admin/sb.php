<?php
session_start();

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Redirect to login if not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}
require_once 'includes/session_timeout.php';
require_once 'connect.php'; // This line is already present and correct.

// Add a check to ensure $pdo object is available from connect.php
if (!isset($pdo)) {
    http_response_code(500);
    echo "Database connection failed: Central PDO object not found. Please check connect.php.";
    exit();
}

require_once 'dhis2/dhis2_shared.php'; // Ensure this provides getDhis2Config() and dhis2_get()
require_once 'includes/question_helper.php'; // Question reusability functions

$success_message = null;
$error_message = null;

// Helper function to get or create an option set
function getOrCreateOptionSetId(PDO $conn, string $optionSetName): int
{
    $stmt = $conn->prepare("SELECT id FROM option_set WHERE name = ?");
    $stmt->execute([$optionSetName]);
    $existingOptionSet = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingOptionSet) {
        return (int)$existingOptionSet['id'];
    } else {
        $stmt = $conn->prepare("INSERT INTO option_set (name) VALUES (?)");
        $stmt->execute([$optionSetName]);
        return (int)$conn->lastInsertId();
    }
}

// Helper function to insert or update option set values and DHIS2 mappings
function insertOptionSetValueAndMapping(PDO $conn, int $optionSetId, array $option, string $dhis2OptionSetId)
{
    // Check if this exact option value already exists in this option set
    $stmt = $conn->prepare("SELECT id FROM option_set_values WHERE option_set_id = ? AND option_value = ?");
    $stmt->execute([$optionSetId, $option['name']]);
    $existingOptionValue = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingOptionValue) {
        $stmt = $conn->prepare("INSERT INTO option_set_values (option_set_id, option_value) VALUES (?, ?)");
        $stmt->execute([$optionSetId, $option['name']]);
    }

    // Map option to DHIS2 if code exists
    if (!empty($option['code'])) {
        // Check if mapping exists (using trimmed values to prevent whitespace issues)
        $localValue = trim($option['name']);
        $dhis2Code = trim($option['code']);
        
        $stmt = $conn->prepare("SELECT id FROM dhis2_option_set_mapping WHERE local_value = ? AND dhis2_option_code = ? AND dhis2_option_set_id = ?");
        $stmt->execute([
            $localValue,
            $dhis2Code,
            $dhis2OptionSetId
        ]);

        if (!$stmt->fetch()) {
            try {
                $stmt = $conn->prepare("INSERT INTO dhis2_option_set_mapping (local_value, dhis2_option_code, dhis2_option_set_id) VALUES (?, ?, ?)");
                $stmt->execute([
                    $localValue, // Trimmed to prevent mapping issues
                    $dhis2Code,  // Trimmed to prevent mapping issues
                    $dhis2OptionSetId
                ]);
            } catch (PDOException $e) {
                if ($e->errorInfo[1] != 1062) { // Ignore duplicate entry errors
                    throw $e;
                }
            }
        }
    }
}

/**
 * Fetches programs from DHIS2 based on program type.
 * 'event' will fetch event programs.
 * 'tracker' will fetch tracker programs.
 * If no type is specified, it fetches all.
 */
function getPrograms($instance, $programType = null)
{
    try {
        $filter = '';
        if ($programType === 'event') {
            $filter = '&filter=programType:eq:WITHOUT_REGISTRATION';
        } elseif ($programType === 'tracker') {
            $filter = '&filter=programType:eq:WITH_REGISTRATION';
        }
        
        $programs = dhis2_get('programs?fields=id,name,programType' . $filter, $instance);
        
        if ($programs === null) {
            error_log("WARNING: DHIS2 programs API call returned null for instance '$instance'. Possible timeout or connection issue.");
            return [];
        }
        
        return $programs['programs'] ?? [];
    } catch (Exception $e) {
        error_log("ERROR: Failed to get programs from DHIS2 instance '$instance': " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches datasets from DHIS2.
 */
function getDatasets($instance)
{
    try {
        $datasets = dhis2_get('dataSets?fields=id,name', $instance);
        
        if ($datasets === null) {
            error_log("WARNING: DHIS2 datasets API call returned null for instance '$instance'. Possible timeout or connection issue.");
            return [];
        }
        
        return $datasets['dataSets'] ?? [];
    } catch (Exception $e) {
        error_log("ERROR: Failed to get datasets from DHIS2 instance '$instance': " . $e->getMessage());
        return [];
    }
}

/**
 * Get category combination details for a program or dataset
 */
function getCategoryComboDetails($instance, $categoryComboId)
{
    if (empty($categoryComboId)) {
        return null;
    }
    $categoryCombo = dhis2_get('categoryCombos/' . $categoryComboId . '?fields=id,name,categoryOptionCombos[id,name]', $instance);
    return $categoryCombo;
}

/**
 * Get details for a specific DHIS2 program or dataset, including data elements, attributes, and category combinations.
 * This function now also fetches 'trackedEntityType[id]' for tracker programs.
 */
function getProgramDetails($instance, $domain, $programId, $programType = null)
{
    $result = [
        'program' => null,
        'dataElements' => [],
        'attributes' => [], // These are Tracked Entity Attributes
        'optionSets' => [],
        'categoryCombo' => null,
        'trackedEntityTypeId' => null // NEW: Stores the DHIS2 Tracked Entity Type UID
    ];

    try {
        if ($domain === 'tracker') {
            if ($programType === 'tracker') { // WITH_REGISTRATION program
              $programInfo = dhis2_get('programs/' . $programId . '?fields=id,name,programType,categoryCombo[id,name],programTrackedEntityAttributes[trackedEntityAttribute[id,name,formName,optionSet[id,name]]],programStages[id,name,programStageDataElements[dataElement[id,name,formName,optionSet[id,name]]]],trackedEntityType[id]', $instance);
              
              if ($programInfo === null) {
                  throw new Exception("Failed to fetch program details for program ID '$programId'. DHIS2 server may be unavailable or experiencing timeouts.");
              }

            $result['program'] = [
                'id' => $programInfo['id'],
                'name' => $programInfo['name'],
                'programType' => $programInfo['programType']
            ];

            // Store the Tracked Entity Type ID if available
            if (!empty($programInfo['trackedEntityType']['id'])) {
                $result['trackedEntityTypeId'] = $programInfo['trackedEntityType']['id'];
            }

            // Get category combination if exists
            if (!empty($programInfo['categoryCombo'])) {
                $result['categoryCombo'] = getCategoryComboDetails($instance, $programInfo['categoryCombo']['id']);
            }

            // Get program attributes (tracker-level data)
            if (!empty($programInfo['programTrackedEntityAttributes'])) {
                foreach ($programInfo['programTrackedEntityAttributes'] as $attr) {
                    $tea = $attr['trackedEntityAttribute'];
                    $result['attributes'][$tea['id']] = [
                        'name' => $tea['formName'] ?? $tea['name'], 
                        'optionSet' => $tea['optionSet'] ?? null
                    ];
                    if (!empty($tea['optionSet'])) {
                        $result['optionSets'][$tea['optionSet']['id']] = $tea['optionSet'];
                    }
                }
            }

            // Get data elements from program stages for tracker programs
            if (!empty($programInfo['programStages'])) {
                foreach ($programInfo['programStages'] as $stage) {
                    if (isset($stage['programStageDataElements'])) {
                        foreach ($stage['programStageDataElements'] as $psde) {
                            $de = $psde['dataElement'];
                            $result['dataElements'][$de['id']] = [
                                'name' => $de['formName'] ?? $de['name'],
                                'optionSet' => $de['optionSet'] ?? null,
                                'programStage' => $stage['id'] // Store which program stage this DE belongs to
                            ];
                            if (!empty($de['optionSet'])) {
                                $result['optionSets'][$de['optionSet']['id']] = $de['optionSet'];
                            }
                        }
                    }
                }
            }
        } elseif ($programType === 'event') { // WITHOUT_REGISTRATION program
            $programInfo = dhis2_get('programs/' . $programId . '?fields=id,name,programType,categoryCombo[id,name],programStages[id,name,programStageDataElements[dataElement[id,name,formName,optionSet[id,name]]]]', $instance); // Added formName
            
            if ($programInfo === null) {
                throw new Exception("Failed to fetch event program details for program ID '$programId'. DHIS2 server may be unavailable or experiencing timeouts.");
            }

            $result['program'] = [
                'id' => $programInfo['id'],
                'name' => $programInfo['name'],
                'programType' => $programInfo['programType']
            ];

            if (!empty($programInfo['categoryCombo'])) {
                $result['categoryCombo'] = getCategoryComboDetails($instance, $programInfo['categoryCombo']['id']);
            }

            if (!empty($programInfo['programStages'])) {
                foreach ($programInfo['programStages'] as $stage) {
                    if (isset($stage['programStageDataElements'])) {
                        foreach ($stage['programStageDataElements'] as $psde) {
                            $de = $psde['dataElement'];
                            $result['dataElements'][$de['id']] = [
                                'name' => $de['formName'] ?? $de['name'],
                                'optionSet' => $de['optionSet'] ?? null,
                                'programStage' => $stage['id'] // Store which program stage this DE belongs to
                            ];
                            if (!empty($de['optionSet'])) {
                                $result['optionSets'][$de['optionSet']['id']] = $de['optionSet'];
                            }
                        }
                    }
                }
            }
        } else {
            // This else block is where the error came from.
            // It means $programType was not 'tracker' or 'event' when $domain was 'tracker'.
            // This case should ideally not be reached if the frontend logic is correct.
            throw new Exception("Invalid program type for tracker domain. Program Type received: " . ($programType ?? 'NULL'));
        }
    } elseif ($domain === 'aggregate') {
        $datasetInfo = dhis2_get('dataSets/' . $programId . '?fields=id,name,categoryCombo[id,name,categoryOptionCombos[id,name]],dataSetElements[dataElement[id,name,categoryCombo[id,name,categoryOptionCombos[id,name]]]]', $instance);
        
        if ($datasetInfo === null) {
            throw new Exception("Failed to fetch dataset details for dataset ID '$programId'. DHIS2 server may be unavailable or experiencing timeouts.");
        }
        $result['program'] = [
            'id' => $datasetInfo['id'],
            'name' => $datasetInfo['name']
        ];

        if (!empty($datasetInfo['categoryCombo'])) {
            $result['categoryCombo'] = [
                'id' => $datasetInfo['categoryCombo']['id'],
                'name' => $datasetInfo['categoryCombo']['name'],
                'categoryOptionCombos' => $datasetInfo['categoryCombo']['categoryOptionCombos'] ?? []
            ];

            $result['optionSets'][$datasetInfo['categoryCombo']['id']] = [
                'id' => $datasetInfo['categoryCombo']['id'],
                'name' => $datasetInfo['categoryCombo']['name'],
                'options' => $datasetInfo['categoryCombo']['categoryOptionCombos'] ?? []
            ];
        }

        if (!empty($datasetInfo['dataSetElements'])) {
            foreach ($datasetInfo['dataSetElements'] as $dse) {
                $de = $dse['dataElement'];
                $optionSet = null;
                $options = [];
                if (!empty($de['categoryCombo']) && !empty($de['categoryCombo']['categoryOptionCombos'])) {
                    if (empty($de['categoryCombo']['name']) || !preg_match('/default/i', $de['categoryCombo']['name'])) {
                        $optionSet = [
                            'id' => $de['categoryCombo']['id'],
                            'name' => $de['categoryCombo']['name']
                        ];
                        $options = $de['categoryCombo']['categoryOptionCombos'];
                        $result['optionSets'][$de['categoryCombo']['id']] = [
                            'id' => $de['categoryCombo']['id'],
                            'name' => $de['categoryCombo']['name'],
                            'options' => $options
                        ];
                    }
                }
                $result['dataElements'][$de['id']] = [
                    'name' => $de['formName'] ?? $de['name'],
                    'optionSet' => $optionSet,
                    'options' => $options
                ];
            }
        }
    }

    // Fetch option values for all option sets (for tracker/event programs)
    foreach ($result['optionSets'] as $optionSetId => &$optionSet) {
        $optionSetDetails = dhis2_get('optionSets/' . $optionSetId . '?fields=id,name,options[id,name,code]', $instance);
        if (!empty($optionSetDetails['options'])) {
            $optionSet['options'] = $optionSetDetails['options'];

            // Add options to data elements and attributes
            foreach ($result['dataElements'] as $deId => &$de) {
                if (!empty($de['optionSet']) && $de['optionSet']['id'] === $optionSetId) {
                    $de['options'] = $optionSetDetails['options'];
                }
            }

            foreach ($result['attributes'] as $attrId => &$attr) {
                if (!empty($attr['optionSet']) && $attr['optionSet']['id'] === $optionSetId) {
                    $attr['options'] = $optionSetDetails['options'];
                }
            }
        }
    }

    return $result;
    
    } catch (Exception $e) {
        error_log("ERROR: Failed to get program details from DHIS2: " . $e->getMessage());
        // Return a basic error result structure
        return [
            'program' => null,
            'dataElements' => [],
            'attributes' => [],
            'optionSets' => [],
            'categoryCombo' => null,
            'trackedEntityTypeId' => null,
            'error' => $e->getMessage()
        ];
    }
}


// Handle form submission for creating survey (both local and DHIS2)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        if (isset($_POST['create_local_survey'])) {
            $surveyName = trim($_POST['local_survey_name']);
            if (empty($surveyName)) {
                throw new Exception("Survey name cannot be empty.");
            }

            $stmt = $pdo->prepare("SELECT id FROM survey WHERE name = ?");
            $stmt->execute([$surveyName]);
            if ($stmt->fetch()) {
                throw new Exception("A survey with the name '" . htmlspecialchars($surveyName) . "' already exists.");
            }

            $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
            $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-d', strtotime($startDate . ' +6 months'));
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            $stmt = $pdo->prepare("INSERT INTO survey (name, type, start_date, end_date, is_active) VALUES (?, 'local', ?, ?, ?)");
            $stmt->execute([$surveyName, $startDate, $endDate, $isActive]);
            $surveyId = $pdo->lastInsertId();
            $position = 1;

            if (!empty($_POST['attach_questions']) && is_array($_POST['attach_questions'])) {
                foreach ($_POST['attach_questions'] as $qid) {
                    $stmt = $pdo->prepare("INSERT INTO survey_question (survey_id, question_id, position) VALUES (?, ?, ?)");
                    $stmt->execute([$surveyId, $qid, $position++]);
                }
            }

            if (!empty($_POST['questions']) && is_array($_POST['questions'])) {
                foreach ($_POST['questions'] as $q) {
                    $qLabel = trim($q['label']);
                    if (!empty($qLabel)) {
                        $stmt = $pdo->prepare("INSERT INTO question (label, question_type, is_required) VALUES (?, ?, 1)");
                        $stmt->execute([$qLabel, $q['type']]);
                        $questionId = $pdo->lastInsertId();
                        $stmt = $pdo->prepare("INSERT INTO survey_question (survey_id, question_id, position) VALUES (?, ?, ?)");
                        $stmt->execute([$surveyId, $questionId, $position++]);
                    }
                }
            }

            $pdo->commit();
            $success_message = "Local survey successfully created.";

        } elseif (isset($_POST['create_survey'])) {
            $dhis2Instance = $_POST['dhis2_instance'] ?? null;
            $programId = $_POST['program_id'] ?? null;
            $programName = $_POST['program_name'] ?? null;
            $domain = $_POST['domain'] ?? null;
            $programType = $_POST['program_type'] ?? null;

            // Re-fetch program details for full data for DB insertion
            // Ensure getProgramDetails is updated to fetch 'formName' for data elements
            $programDetails = getProgramDetails(
                $dhis2Instance,
                $domain,
                $programId,
                $programType
            );

            $dhis2TrackedEntityTypeUid = ($domain === 'tracker' && $programType === 'tracker') ? ($programDetails['trackedEntityTypeId'] ?? null) : null;

            if (empty($dhis2Instance) || empty($programId) || empty($programName) || empty($domain) || ($domain === 'tracker' && empty($programType))) {
                throw new Exception("Missing essential DHIS2 survey parameters (instance, program/dataset, name, domain, or program type for tracker).");
            }

            $stmt = $pdo->prepare("SELECT id FROM survey WHERE program_dataset = ?");
            $stmt->execute([$programId]);
            $existingSurvey = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingSurvey) {
                throw new Exception("A survey for this program/dataset (UID) already exists.");
            }

            $stmt = $pdo->prepare("SELECT id FROM survey WHERE name = ?");
            $stmt->execute([$programName]);
            $existingSurvey = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingSurvey) {
                throw new Exception("A survey with name '" . htmlspecialchars($programName) . "' already exists.");
            }

            // 1. Create survey entry
            $surveyTypeLabel = '';
            if ($domain === 'tracker') {
                if ($programType === 'tracker') {
                    $surveyTypeLabel = ' (T)';
                } elseif ($programType === 'event') {
                    $surveyTypeLabel = ' (E)';
                }
            } elseif ($domain === 'aggregate') {
                $surveyTypeLabel = ' (A)';
            }
            $surveyDisplayName = $programName . $surveyTypeLabel;

            $stmt = $pdo->prepare("INSERT INTO survey (name, type, dhis2_instance, program_dataset, dhis2_tracked_entity_type_uid) VALUES (?, 'dhis2', ?, ?, ?)");
            $stmt->execute([$surveyDisplayName, $dhis2Instance, $programId, $dhis2TrackedEntityTypeUid]);
            $surveyId = $pdo->lastInsertId();
            $position = 1; // Initialize position for survey_question order

            // --- START REORDERED LOGIC ---

            // 1. Process Tracked Entity Attributes (TEAs) first if it's a tracker program
            // CHANGES: This entire block is moved up to be processed first.
            if ($domain === 'tracker' && $programType === 'tracker' && isset($_POST['attributes']) && !empty($_POST['attributes'])) {
                $attributes = json_decode($_POST['attributes'], true);

                foreach ($attributes as $attrId => $attr) {
                    $questionType = !empty($attr['optionSet']) ? 'select' : 'text';

                    $questionLabel = $attr['formName'] ?? $attr['name'];

                    // Use question reusability system
                    $questionId = getOrCreateQuestion($pdo, $questionLabel, $questionType, true, $attrId);

                    $stmt = $pdo->prepare("INSERT INTO survey_question (survey_id, question_id, position) VALUES (?, ?, ?)");
                    $stmt->execute([$surveyId, $questionId, $position]);
                    $position++; // Increment position

                    // Create or update DHIS2 mapping
                    createOrUpdateDHIS2Mapping(
                        $pdo,
                        $questionId,
                        null, // dhis2_dataelement_id
                        $attrId, // dhis2_attribute_id
                        $attr['optionSet']['id'] ?? null // dhis2_option_set_id
                    );

                    if (!empty($attr['optionSet']) && !empty($attr['options'])) {
                        $optionSetId = getOrCreateOptionSetId($pdo, $attr['optionSet']['name']);

                        $stmt = $pdo->prepare("UPDATE question SET option_set_id = ? WHERE id = ?");
                        $stmt->execute([$optionSetId, $questionId]);

                        foreach ($attr['options'] as $option) {
                            insertOptionSetValueAndMapping($pdo, $optionSetId, $option, $attr['optionSet']['id']);
                        }
                    }
                }
            }
            
            // 2. Handle Category Combination (appears after TEAs, if TEAs were present, otherwise first)
            // CHANGES: This block is moved to appear after the Tracked Entity Attributes processing.
            $categoryCombo = json_decode($_POST['category_combo'] ?? 'null', true);
            if (!empty($categoryCombo)) {
                $stmt = $pdo->prepare("SELECT id, option_set_id FROM question WHERE label = ? AND question_type = 'select'");
                $stmt->execute([$categoryCombo['name']]);
                $existingQuestion = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existingQuestion) {
                    $categoryQuestionId = $existingQuestion['id'];
                    $categoryOptionSetId = $existingQuestion['option_set_id'];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO question (label, question_type, is_required) VALUES (?, 'select', 1)");
                    $stmt->execute([$categoryCombo['name']]);
                    $categoryQuestionId = $pdo->lastInsertId();

                    $categoryOptionSetId = getOrCreateOptionSetId($pdo, $categoryCombo['name']);
                    $stmt = $pdo->prepare("UPDATE question SET option_set_id = ? WHERE id = ?");
                    $stmt->execute([$categoryOptionSetId, $categoryQuestionId]);
                }

                $stmt = $pdo->prepare("INSERT INTO survey_question (survey_id, question_id, position) VALUES (?, ?, ?)");
                $stmt->execute([$surveyId, $categoryQuestionId, $position]);
                $position++; // Increment position

                if (!empty($categoryCombo['categoryOptionCombos'])) {
                    foreach ($categoryCombo['categoryOptionCombos'] as $catOptCombo) {
                        insertOptionSetValueAndMapping($pdo, $categoryOptionSetId, $catOptCombo, $categoryCombo['id']);
                    }
                }

                $stmt = $pdo->prepare("SELECT id FROM question_dhis2_mapping WHERE question_id = ? AND dhis2_dataelement_id = ? AND dhis2_option_set_id = ?");
                $stmt->execute([
                    $categoryQuestionId,
                    'category_combo',
                    $categoryCombo['id']
                ]);
                if (!$stmt->fetch()) {
                    $stmt = $pdo->prepare("INSERT INTO question_dhis2_mapping (question_id, dhis2_dataelement_id, dhis2_option_set_id) VALUES (?, ?, ?)");
                    $stmt->execute([
                        $categoryQuestionId,
                        'category_combo',
                        $categoryCombo['id']
                    ]);
                }
            }

            // 3. Process Data Elements and create questions (the rest)
            // CHANGES: This block is moved to be processed last.
            $dataElements = json_decode($_POST['data_elements'] ?? '[]', true);

            foreach ($dataElements as $deId => $element) {
                $questionType = !empty($element['optionSet']) ? 'select' : 'text';

                // Use 'formName' if available, otherwise 'name' for the question label
                // This assumes `getProgramDetails` now fetches 'formName'.
                $questionLabel = $element['formName'] ?? $element['name']; 
                
                // Use question reusability system
                $questionId = getOrCreateQuestion($pdo, $questionLabel, $questionType, true, $deId);

                $stmt = $pdo->prepare("INSERT INTO survey_question (survey_id, question_id, position) VALUES (?, ?, ?)");
                $stmt->execute([$surveyId, $questionId, $position]);
                $position++; // Increment position

                // Create or update DHIS2 mapping
                createOrUpdateDHIS2Mapping(
                    $pdo,
                    $questionId,
                    $deId, // dhis2_dataelement_id
                    null, // dhis2_attribute_id
                    $element['optionSet']['id'] ?? null, // dhis2_option_set_id
                    $element['programStage'] ?? null // dhis2_program_stage_id
                );

                if (!empty($element['optionSet']) && !empty($element['options'])) {
                    $optionSetId = getOrCreateOptionSetId($pdo, $element['optionSet']['name']);

                    $stmt = $pdo->prepare("UPDATE question SET option_set_id = ? WHERE id = ?");
                    $stmt->execute([$optionSetId, $questionId]);

                    foreach ($element['options'] as $option) {
                        insertOptionSetValueAndMapping($pdo, $optionSetId, $option, $element['optionSet']['id']);
                    }
                }
            }

            // --- END REORDERED LOGIC ---

            $pdo->commit();
            $success_message = "Survey successfully created from DHIS2 program";

        } // End of elseif (isset($_POST['create_survey']))

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_message = "Error creating survey: " . $e->getMessage();
    }
}

// Check if this is an AJAX request for DHIS2 form content
if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && $_GET['survey_source'] == 'dhis2') {
    ob_clean(); // Clear any previous output buffer to ensure only the desired HTML is sent
    ?>
    <form method="GET" action="" class="p-3 rounded bg-light shadow-sm">
      <input type="hidden" name="survey_source" value="dhis2">
      <div class="row mb-4">
        <div class="col-md-4">
          <div class="form-group mb-3">
            <label class="form-control-label">Select DHIS2 Instance</label>
            <select name="dhis2_instance" class="form-control" id="dhis2-instance-select">
              <option value="">-- Select Instance --</option>
                <?php
                try {
                    $availableDhis2Instances = getAllDhis2Configs();
                    // print_r($availableDhis2Instances); // Debugging line to see the structure
                    error_log("DEBUG: create_survey.php dropdown loop: " . json_encode(array_keys($availableDhis2Instances)));
                    foreach ($availableDhis2Instances as $config) : ?>
                    <option value="<?= htmlspecialchars($config['instance_key']) ?>" <?= (isset($_GET['dhis2_instance']) && $_GET['dhis2_instance'] == $config['instance_key']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($config['description'] . ' (' . $config['instance_key'] . ')') ?>
                    </option>
                    <?php endforeach;
                } catch (Exception $e) {
                    error_log('ERROR: create_survey.php dropdown: Error populating DHIS2 instance select: ' . $e->getMessage());
                    echo '<option value="">Error: ' . htmlspecialchars($e->getMessage()) . '</option>';
                }
                ?>
            </select>
          </div>
        </div>

        <?php if (isset($_GET['dhis2_instance']) && !empty($_GET['dhis2_instance'])): ?>
        <div class="col-md-4">
          <div class="form-group mb-3">
            <label class="form-control-label">Select Domain Type</label>
            <select name="domain" class="form-control" id="domain-select">
              <option value="">-- Select Domain --</option>
              <option value="tracker" <?= (isset($_GET['domain']) && $_GET['domain'] == 'tracker') ? 'selected' : '' ?>>Tracker</option>
              <option value="aggregate" <?= (isset($_GET['domain']) && $_GET['domain'] == 'aggregate') ? 'selected' : '' ?>>Aggregate</option>
            </select>
          </div>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['dhis2_instance']) && !empty($_GET['dhis2_instance']) &&
                     isset($_GET['domain']) && $_GET['domain'] == 'tracker'): // Only show program type if domain is tracker ?>
        <div class="col-md-4">
          <div class="form-group mb-3">
            <label class="form-control-label">Select Program Type</label>
            <select name="program_type" class="form-control" id="program-type-select">
              <option value="">-- Select Program Type --</option>
              <option value="event" <?= (isset($_GET['program_type']) && $_GET['program_type'] == 'event') ? 'selected' : '' ?>>Event Program</option>
              <option value="tracker" <?= (isset($_GET['program_type']) && $_GET['program_type'] == 'tracker') ? 'selected' : '' ?>>Tracker Program</option>
            </select>
          </div>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['dhis2_instance']) && !empty($_GET['dhis2_instance']) &&
                     isset($_GET['domain']) && !empty($_GET['domain']) &&
                     (($_GET['domain'] == 'tracker' && isset($_GET['program_type']) && !empty($_GET['program_type'])) || $_GET['domain'] == 'aggregate')): ?>
        <div class="col-md-4">
          <div class="form-group mb-3">
            <label class="form-control-label">
              <?php
                if ($_GET['domain'] == 'tracker') {
                    echo ($_GET['program_type'] == 'tracker' ? 'Select Tracker Program' : 'Select Event Program');
                } else {
                    echo 'Select Dataset';
                }
                ?>
            </label>
            <select name="program_id" class="form-control" id="program-select">
              <option value="">-- Select
                <?php
                if ($_GET['domain'] == 'tracker') {
                    echo ($_GET['program_type'] == 'tracker' ? 'Tracker Program' : 'Event Program');
                } else {
                    echo 'Dataset';
                }
                ?>
                --</option>
              <?php
                try {
                    $programs = [];
                    if ($_GET['domain'] == 'tracker' && isset($_GET['program_type'])) {
                        $programs = getPrograms($_GET['dhis2_instance'], $_GET['program_type']);
                    } elseif ($_GET['domain'] == 'aggregate') {
                        $programs = getDatasets($_GET['dhis2_instance']);
                    }

                    foreach ($programs as $program) : ?>
                    <option value="<?= htmlspecialchars($program['id']) ?>"
                      <?= (isset($_GET['program_id']) && $_GET['program_id'] == $program['id']) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($program['name']) ?>
                    </option>
                    <?php endforeach;
                } catch (Exception $e) {
                    $errorMsg = $e->getMessage();
                    // Provide user-friendly error messages for common issues
                    if (strpos($errorMsg, 'timeout') !== false || strpos($errorMsg, 'unavailable') !== false) {
                        echo '<option value="">DHIS2 server timeout - please try again</option>';
                    } else {
                        echo '<option value="">Error loading programs: ' . htmlspecialchars($errorMsg) . '</option>';
                    }
                }
                ?>
            </select>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </form>
    <?php
    // Display program preview if all selections are made
    if (isset($_GET['dhis2_instance']) && !empty($_GET['dhis2_instance']) &&
        isset($_GET['domain']) && !empty($_GET['domain']) &&
        isset($_GET['program_id']) && !empty($_GET['program_id']) &&
        // This condition is crucial: ensures program_type is set for tracker domain
        (($_GET['domain'] == 'tracker' && isset($_GET['program_type']) && !empty($_GET['program_type'])) || $_GET['domain'] == 'aggregate')) {

        try {
            $programDetails = getProgramDetails(
                $_GET['dhis2_instance'],
                $_GET['domain'],
                $_GET['program_id'],
                ($_GET['domain'] == 'tracker' ? $_GET['program_type'] : null)
            );

            if ($programDetails['program']) {
                ?>
                <div class="program-preview shadow-sm mb-4">
                    <h3 class="mb-3 text-primary">Program Preview: <?= htmlspecialchars($programDetails['program']['name']) ?></h3>
                    <p><strong>Domain:</strong> <?= htmlspecialchars(ucfirst($_GET['domain'])) ?></p>
                    <?php if ($_GET['domain'] == 'tracker'): ?>
                    <p><strong>Program Type:</strong> <?= htmlspecialchars(ucfirst($_GET['program_type'])) ?></p>
                    <?php endif; ?>

                    <?php if (!empty($programDetails['attributes']) && $_GET['domain'] == 'tracker' && $_GET['program_type'] == 'tracker'): ?>
                    <div class="preview-section">
                        <h4>Tracked Entity Attributes</h4>
                        <div class="mb-2">
                            <button type="button" class="btn btn-sm btn-outline-primary select-all-attr" data-target="attributes">Select All</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary deselect-all-attr" data-target="attributes">Deselect All</button>
                        </div>
                        <div id="attributes">
                            <?php foreach ($programDetails['attributes'] as $attrId => $attr): ?>
                                <div class="preview-item d-flex align-items-center">
                                    <div class="form-check me-3">
                                        <input class="form-check-input attribute-checkbox" type="checkbox" value="<?= htmlspecialchars($attrId) ?>" id="attr-<?= htmlspecialchars($attrId) ?>" name="selected_attributes[]" checked>
                                        <label class="form-check-label" for="attr-<?= htmlspecialchars($attrId) ?>"></label>
                                    </div>
                                    <div class="flex-grow-1">
                                        <strong><?= htmlspecialchars($attr['name']) ?></strong>
                                        <?php if (!empty($attr['optionSet'])): ?>
                                            <div>
                                                <small>Option Set: <?= htmlspecialchars($attr['optionSet']['name']) ?></small>
                                                <?php if (!empty($attr['options'])): ?>
                                                    <div class="mt-2">
                                                        <?php foreach ($attr['options'] as $option): ?>
                                                            <span class="option-item"><?= htmlspecialchars($option['name']) ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php
                    // Show category combination only if it exists and is not the DHIS2 default
                    if (
                        !empty($programDetails['categoryCombo']) &&
                        (
                            empty($programDetails['categoryCombo']['name']) ||
                            !preg_match('/default/i', $programDetails['categoryCombo']['name'])
                        )
                    ): ?>
                        <div class="preview-section">
                        <h4>Category Combination</h4>
                        <div class="preview-item">
                            <strong><?= htmlspecialchars($programDetails['categoryCombo']['name']) ?></strong>
                            <?php if (!empty($programDetails['categoryCombo']['categoryOptionCombos'])): ?>
                            <div class="mt-2">
                                <small>Category Option Combinations:</small>
                                <div class="mt-2">
                                <?php foreach ($programDetails['categoryCombo']['categoryOptionCombos'] as $catOptCombo): ?>
                                    <span class="option-item"><?= htmlspecialchars($catOptCombo['name']) ?></span>
                                <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($programDetails['dataElements'])): ?>
                    <div class="preview-section">
                        <h4><?= $_GET['domain'] == 'aggregate' ? 'Data Set Elements' : 'Data Elements' ?></h4>
                        <div class="mb-2">
                            <button type="button" class="btn btn-sm btn-outline-primary select-all-de" data-target="data-elements">Select All</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary deselect-all-de" data-target="data-elements">Deselect All</button>
                        </div>
                        <div id="data-elements">
                            <?php foreach ($programDetails['dataElements'] as $deId => $element): ?>
                                <div class="preview-item d-flex align-items-center">
                                    <div class="form-check me-3">
                                        <input class="form-check-input data-element-checkbox" type="checkbox" value="<?= htmlspecialchars($deId) ?>" id="de-<?= htmlspecialchars($deId) ?>" name="selected_data_elements[]" checked>
                                        <label class="form-check-label" for="de-<?= htmlspecialchars($deId) ?>"></label>
                                    </div>
                                    <div class="flex-grow-1">
                                        <strong><?= htmlspecialchars($element['name']) ?></strong>
                                        <?php if ($_GET['domain'] != 'aggregate' && !empty($element['optionSet'])): ?>
                                            <div>
                                                <small>Option Set: <?= htmlspecialchars($element['optionSet']['name']) ?></small>
                                                <?php if (!empty($element['options'])): ?>
                                                    <div class="mt-2">
                                                        <?php foreach ($element['options'] as $option): ?>
                                                            <span class="option-item"><?= htmlspecialchars($option['name']) ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php elseif ($_GET['domain'] == 'aggregate'): ?>
                                            <?php
                                            $deCategoryCombo = null;
                                            try {
                                                $deDetails = dhis2_get('dataElements/' . $deId . '?fields=categoryCombo[id,name,categoryOptionCombos[id,name]]', $_GET['dhis2_instance']);
                                                if (!empty($deDetails['categoryCombo'])) {
                                                    $deCategoryCombo = $deDetails['categoryCombo'];
                                                }
                                            } catch (Exception $e) {
                                                $deCategoryCombo = null;
                                            }
                                            ?>
                                            <?php if (!empty($deCategoryCombo) && (empty($deCategoryCombo['name']) || !preg_match('/default/i', $deCategoryCombo['name']))): ?>
                                                <div>
                                                    <small>Category Combination: <?= htmlspecialchars($deCategoryCombo['name']) ?></small>
                                                    <?php if (!empty($deCategoryCombo['categoryOptionCombos'])): ?>
                                                        <div class="mt-2">
                                                            <small>Category Option Combos:</small>
                                                            <div class="mt-2">
                                                                <?php foreach ($deCategoryCombo['categoryOptionCombos'] as $catOptCombo): ?>
                                                                    <span class="option-item"><?= htmlspecialchars($catOptCombo['name']) ?></span>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div>
                                                    <small>No specific category combination for this data element.</small>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <form method="POST" action="" class="mt-4">
                        <input type="hidden" name="dhis2_instance" value="<?= htmlspecialchars($_GET['dhis2_instance']) ?>">
                        <input type="hidden" name="domain" value="<?= htmlspecialchars($_GET['domain']) ?>">
                        <input type="hidden" name="program_id" value="<?= htmlspecialchars($_GET['program_id']) ?>">
                        <input type="hidden" name="program_name" value="<?= htmlspecialchars($programDetails['program']['name']) ?>">
                        <input type="hidden" name="all_data_elements" value="<?= htmlspecialchars(json_encode($programDetails['dataElements'])) ?>">
                        <input type="hidden" id="selected_data_elements_input" name="data_elements" value="">
                        <?php if (!empty($programDetails['categoryCombo'])): ?>
                            <input type="hidden" name="category_combo" value="<?= htmlspecialchars(json_encode($programDetails['categoryCombo'])) ?>">
                        <?php endif; ?>

                        <?php
                        // --- IMPORTANT FIX HERE: Ensure program_type is always passed in the POST form if domain is tracker ---
                        if ($_GET['domain'] == 'tracker' && isset($_GET['program_type'])): ?>
                            <input type="hidden" name="program_type" value="<?= htmlspecialchars($_GET['program_type']) ?>">
                        <?php endif; ?>
                        <?php
                        // --- END FIX ---
                        ?>

                        <?php if ($_GET['domain'] == 'tracker' && $_GET['program_type'] == 'tracker'): ?>
                            <input type="hidden" name="all_attributes" value="<?= htmlspecialchars(json_encode($programDetails['attributes'])) ?>">
                            <input type="hidden" id="selected_attributes_input" name="attributes" value="">
                        <?php endif; ?>

                        <div class="text-center mt-4">
                            <button type="submit" name="create_survey" class="btn btn-primary action-btn shadow">
                                <i class="fas fa-sync-alt me-2"></i> Create Survey from DHIS2
                            </button>
                        </div>
                    </form>
                </div>
                <?php
            }
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            // Provide user-friendly error messages for common issues
            if (strpos($errorMsg, 'timeout') !== false || strpos($errorMsg, 'unavailable') !== false) {
                echo '<div class="alert alert-warning">
                    <h5><i class="fas fa-exclamation-triangle me-2"></i>DHIS2 Server Timeout</h5>
                    <p>The DHIS2 server is taking longer than expected to respond. This may be due to:</p>
                    <ul>
                        <li>High server load</li>
                        <li>Network connectivity issues</li>
                        <li>Large program/dataset with many data elements</li>
                    </ul>
                    <p><strong>Please try refreshing the page or selecting a different program.</strong></p>
                </div>';
            } else {
                echo '<div class="alert alert-danger">
                    <h5><i class="fas fa-exclamation-circle me-2"></i>Error Loading Program Details</h5>
                    <p>' . htmlspecialchars($errorMsg) . '</p>
                </div>';
            }
        }
    }
    ?>
    <div class="text-center mt-3">
      <a href="sb.php" class="btn btn-secondary action-btn shadow">
        <i class="fas fa-arrow-left me-2"></i> Back
      </a>
    </div>
    <?php
    // Stop further processing for AJAX request
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create New Survey</title>
  <link href="argon-dashboard-master/assets/css/nucleo-icons.css" rel="stylesheet">
  <link href="argon-dashboard-master/assets/css/nucleo-svg.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
  <link href="argon-dashboard-master/assets/css/argon-dashboard.min.css" rel="stylesheet">
  <style>
    /* Custom styles to apply #1e3c72 and ensure coordination */
    :root {
        --primary-color: #1e3c72; /* Your desired primary color */
        --primary-hover-color: #162c57; /* A slightly darker shade for hover */
        --primary-light-color: #3b5a9a; /* A lighter shade for text/borders */
    }

    body {
      font-family: Arial, sans-serif;
      background-color: #f9f9f9;
      margin: 0;
      padding: 0;
    }
    h1, h2 {
      color: var(--primary-color);
      margin-bottom: 30px;
    }
    .card-header.bg-gradient-primary {
      background-image: linear-gradient(310deg, var(--primary-color) 0%, var(--primary-light-color) 100%) !important;
    }
    .btn-outline-primary {
      color: var(--primary-color);
      border-color: var(--primary-color);
    }
    .btn-outline-primary:hover,
    .btn-outline-primary:focus {
      background-color: var(--primary-color);
      color: #fff;
      border-color: var(--primary-color);
    }
    .btn-primary {
      background-color: var(--primary-color);
      border-color: var(--primary-color);
    }
    .btn-primary:hover,
    .btn-primary:focus {
      background-color: var(--primary-hover-color);
      border-color: var(--primary-hover-color);
    }
    .text-primary {
      color: var(--primary-color) !important;
    }
    .preview-section h4 {
      border-bottom: 1px solid #eee;
      padding-bottom: 10px;
      margin-bottom: 15px;
      color: var(--primary-color); /* Apply primary color to section headers */
    }
    /* Rest of your existing styles */
    .program-preview {
      background-color: #fff;
      border-radius: 10px;
      padding: 20px;
      box-shadow: 0 0 15px rgba(0,0,0,0.1);
      margin-bottom: 30px;
    }
    .preview-section {
      margin-bottom: 25px;
    }
    .preview-item {
      padding: 10px;
      border-radius: 5px;
      background-color: #f8f9fe;
      margin-bottom: 10px;
    }
    .preview-item:hover {
      background-color: #e9ecef;
    }
    .option-item {
      display: inline-block;
      background-color: #e9ecef;
      padding: 3px 8px;
      border-radius: 4px;
      margin-right: 5px;
      margin-bottom: 5px;
      font-size: 12px;
    }
    .action-btn {
      font-size: 18px;
      padding: 15px 25px;
      width: 100%;
      max-width: 400px;
      display: block;
      margin: 0 auto;
    }
    .alert {
      margin-bottom: 20px;
    }
    
    /* Enhanced Survey Type Selection Cards */
    .survey-type-card {
      background: white;
      border: 2px solid #e2e8f0;
      border-radius: 15px;
      padding: 2rem;
      text-align: center;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      cursor: pointer;
      height: 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      position: relative;
      overflow: hidden;
    }

    .survey-type-card:hover {
      border-color: var(--primary-color);
      background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(255, 255, 255, 1) 100%);
      transform: translateY(-5px);
      box-shadow: 0 15px 35px rgba(102, 126, 234, 0.15);
    }

    .survey-type-card.selected {
      border-color: var(--primary-color) !important;
      background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(255, 255, 255, 1) 100%) !important;
      box-shadow: 0 10px 30px rgba(102, 126, 234, 0.2);
    }

    .survey-type-card .icon {
      font-size: 3rem;
      color: var(--primary-color);
      margin-bottom: 1rem;
      transition: all 0.3s ease;
    }

    .survey-type-card .badge {
      font-size: 0.75rem;
      padding: 0.375rem 0.75rem;
      border-radius: 20px;
      font-weight: 600;
    }

    /* Animation Classes */
    .fade-in {
      animation: fadeIn 0.6s ease-in;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .slide-in {
      animation: slideIn 0.4s ease-out;
    }

    @keyframes slideIn {
      from { transform: translateX(-20px); opacity: 0; }
      to { transform: translateX(0); opacity: 1; }
    }

    /* Card Ripple Effect */
    .ripple-effect {
      position: absolute;
      border-radius: 50%;
      background: rgba(102, 126, 234, 0.3);
      transform: scale(0);
      animation: ripple 0.6s linear;
      pointer-events: none;
      width: 20px;
      height: 20px;
      margin-left: -10px;
      margin-top: -10px;
    }

    @keyframes ripple {
      to {
        transform: scale(4);
        opacity: 0;
      }
    }
  </style>
</head>
<body>

  <?php include 'components/aside.php'; ?>

  <main class="main-content position-relative border-radius-lg">
    <?php include 'components/navbar.php'; ?>

  
    <div class="container-fluid py-4">
       <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item">
                            <a class="breadcrumb-link-light" href="main.php">Dashboard</a>     
                        </li>
                        <li class="breadcrumb-item active breadcrumb-item-active-light" aria-current="page">
                            <a class="breadcrumb-link-light" href="survey.php">Surveys</a>  

                        </li>
                        <li class="breadcrumb-item active breadcrumb-item-active-light" aria-current="page">
                            Create New Survey
                        </li>
                    </ol>
                </nav>
      <div class="row">
        <div class="col-12">
          <div class="card shadow-lg mb-5 fade-in">
            
            <div class="card-body px-4">

              <?php if ($success_message): ?>
                <div class="alert alert-success" role="alert" id="success-alert">
                  <?= htmlspecialchars($success_message) ?>
                </div>
                <script>
                  // Enhanced success redirect with loading indicator
                  const successAlert = document.getElementById('success-alert');
                  if (successAlert) {
                    let countdown = 3;
                    const updateText = () => {
                      successAlert.innerHTML = `<?= htmlspecialchars($success_message) ?> <br><small class="mt-2 d-block">Redirecting in ${countdown} seconds...</small>`;
                      countdown--;
                      if (countdown >= 0) {
                        setTimeout(updateText, 1000);
                      } else {
                        showLoadingOverlay('Redirecting to surveys...');
                        setTimeout(() => {
                          window.location.href = 'survey.php';
                        }, 500);
                      }
                    };
                    updateText();
                  }
                </script>
              <?php endif; ?>

              <?php if ($error_message): ?>
                <div class="alert alert-danger" role="alert">
                  <?= htmlspecialchars($error_message) ?>
                </div>
              <?php endif; ?>

              <?php if (!isset($_GET['survey_source'])): ?>
                <div class="row justify-content-center mb-4">
                  <div class="col-lg-10 mb-4">
                    <div class="preview-section fade-in">
                      <h4 class="mb-4"><i class="fas fa-info-circle me-2"></i>Survey Creation Options</h4>
                      <div class="row g-4">
                        <div class="col-md-6">
                          <a href="?survey_source=local" class="text-decoration-none">
                            <div class="survey-type-card slide-in">
                              <div class="icon">
                                <i class="fas fa-edit"></i>
                              </div>
                              <h5 class="fw-bold mb-2">Local Survey</h5>
                              <p class="text-muted mb-3">Create a custom survey with your own questions and design</p>
                              <div class="badge bg-success">Quick Setup</div>
                            </div>
                          </a>
                        </div>
                        <div class="col-md-6">
                          <a href="?survey_source=dhis2" class="text-decoration-none">
                            <div class="survey-type-card slide-in" style="animation-delay: 0.2s;">
                              <div class="icon">
                                <i class="fas fa-database"></i>
                              </div>
                              <h5 class="fw-bold mb-2">DHIS2 Integration</h5>
                              <p class="text-muted mb-3">Import from existing DHIS2 programs or datasets</p>
                              <div class="badge bg-info">Advanced</div>
                            </div>
                          </a>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <script>
                  // Simple hover effects for survey type cards
                  document.addEventListener('DOMContentLoaded', function() {
                    document.querySelectorAll('.survey-type-card').forEach(card => {
                      const parentLink = card.closest('a');
                      
                      card.addEventListener('mouseenter', function() {
                        this.style.borderColor = 'var(--primary-color)';
                        this.style.transform = 'translateY(-3px)';
                      });
                      
                      card.addEventListener('mouseleave', function() {
                        this.style.borderColor = '#e2e8f0';
                        this.style.transform = 'translateY(0)';
                      });
                      
                      // Add loading state on click
                      if (parentLink) {
                        parentLink.addEventListener('click', function(e) {
                          showLoadingOverlay('Loading survey setup...');
                        });
                      }
                    });
                  });
                </script>
                <div class="text-center mt-4">
                  <a href="survey.php" class="btn btn-secondary action-btn shadow">
                    <i class="fas fa-arrow-left me-2"></i> Back
                  </a>
                </div>
              <?php else: ?>

                <?php
                // LOCAL SURVEY CREATION
                if ($_GET['survey_source'] == 'local') :
                ?>
                  <div class="text-center mb-4">
                    <h2 class="mb-1">Local Survey Details</h2>
                    <div class="text-secondary mb-3">Enter the details for your local survey</div>
                  </div>
                  <form method="POST" action="sb.php" class="p-3 rounded bg-light shadow-sm">
                    <input type="hidden" name="survey_source" value="local">
                    <input type="hidden" name="create_local_survey" value="1">
                    <div class="row mb-4">
                      <div class="col-md-6">
                        <div class="form-group mb-3">
                          <label class="form-control-label">Survey Name <span class="text-danger">*</span></label>
                          <input type="text" name="local_survey_name" class="form-control" required>
                        </div>
                      </div>
                      <div class="col-md-6"></div>
                    </div>
                    <div class="row mb-4">
                      <div class="col-md-6">
                        <div class="form-group mb-3">
                          <label class="form-control-label">Start Date</label>
                          <input type="date" name="start_date" class="form-control">
                          <small class="text-muted">Defaults to today if not specified</small>
                        </div>
                      </div>
                      <div class="col-md-6">
                        <div class="form-group mb-3">
                          <label class="form-control-label">End Date</label>
                          <input type="date" name="end_date" class="form-control">
                          <small class="text-muted">Defaults to 6 months from start date</small>
                        </div>
                      </div>
                    </div>
                    <div class="form-check mb-4">
                      <input class="form-check-input" type="checkbox" name="is_active" id="activeSurvey" checked>
                      <label class="form-check-label" for="activeSurvey">
                        Active Survey
                      </label>
                    </div>

                    <div class="mb-3">
                      <button type="button" class="btn btn-outline-info shadow-sm" id="toggle-existing-questions">
                      <i class="fas fa-link"></i> Attach Existing Questions (optional)
                      </button>
                    </div>
                    <div id="existing-questions-section" style="display:none;">
                      <div class="mb-4">
                      <h5>Attach Existing Questions</h5>
                      <input type="text" id="search-existing-questions" class="form-control mb-2" placeholder="Search questions...">
                      <div id="existing-questions-list" style="max-height: 250px; overflow-y: auto; border: 1px solid #eee; border-radius: 8px; padding: 10px; background: #f8f9fa;">
                        <?php
                        // Fetch existing questions with their option sets
                        try {
                          $stmt = $pdo->query("SELECT q.id, q.label, q.question_type, q.is_required, q.option_set_id, os.name AS option_set_name
                                                FROM question q
                                                LEFT JOIN option_set os ON q.option_set_id = os.id
                                                ORDER BY q.label ASC");
                          $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        } catch (Exception $e) {
                          $questions = [];
                          echo '<div class="alert alert-danger">Could not load questions: ' . htmlspecialchars($e->getMessage()) . '</div>';
                        }
                        foreach ($questions as $q):
                        ?>
                        <div class="form-check mb-2 existing-question-item">
                          <input class="form-check-input" type="checkbox" name="attach_questions[]" value="<?= $q['id'] ?>" id="q<?= $q['id'] ?>">
                          <label class="form-check-label" for="q<?= $q['id'] ?>">
                          <strong><?= htmlspecialchars($q['label']) ?></strong>
                          <span class="badge bg-secondary ms-2"><?= htmlspecialchars($q['question_type']) ?></span>
                          <?php if ($q['option_set_name']): ?>
                            <span class="badge bg-info ms-2"><?= htmlspecialchars($q['option_set_name']) ?></span>
                          <?php endif; ?>
                          </label>
                          <?php
                          // Show option set values if any
                          if ($q['option_set_id']) {
                            $optStmt = $pdo->prepare("SELECT option_value FROM option_set_values WHERE option_set_id = ?");
                            $optStmt->execute([$q['option_set_id']]);
                            $opts = $optStmt->fetchAll(PDO::FETCH_COLUMN);
                            if ($opts) {
                            echo '<div class="mt-1" style="font-size:12px;">';
                            foreach ($opts as $opt) {
                              echo '<span class="option-item">'.htmlspecialchars($opt).'</span> ';
                            }
                            echo '</div>';
                            }
                          }
                          ?>
                        </div>
                        <?php endforeach; ?>
                      </div>
                      <small class="text-muted">Scroll and search to select questions to attach to this survey.</small>
                      </div>
                    </div>
                    <script>
                      // Search/filter for existing questions
                      document.addEventListener('DOMContentLoaded', function() {
                      var searchInput = document.getElementById('search-existing-questions');
                      var items = document.querySelectorAll('.existing-question-item');
                      if (searchInput) {
                        searchInput.addEventListener('input', function() {
                        var val = this.value.trim().toLowerCase();
                        items.forEach(function(item) {
                          var text = item.textContent.toLowerCase();
                          item.style.display = text.indexOf(val) !== -1 ? '' : 'none';
                        });
                        });
                      }
                      });
                    </script>

                    <div class="mb-3">
                      <button type="button" class="btn btn-outline-info shadow-sm" id="toggle-new-questions">
                        <i class="fas fa-plus"></i> Add New Questions (optional)
                      </button>
                    </div>
                    <div id="questions-section" style="display:none;">
                      <div id="questions-container">
                        <div class="row mb-2 question-row">
                          <div class="col-md-6">
                            <input type="text" name="questions[0][label]" class="form-control" placeholder="Question label">
                          </div>
                          <div class="col-md-4">
                            <select name="questions[0][type]" class="form-control">
                              <option value="text">Text</option>
                              <option value="select">Select</option>
                              <option value="number">Number</option>
                              <option value="date">Date</option>
                              <option value="boolean">Yes/No</option>
                            </select>
                          </div>
                          <div class="col-md-2">
                            <button type="button" class="btn btn-danger btn-sm remove-question" style="display:none;">Remove</button>
                          </div>
                        </div>
                      </div>
                      <div class="mb-3">
                        <button type="button" class="btn btn-secondary shadow-sm" id="add-question-btn">
                          <i class="fas fa-plus"></i> Add Question
                        </button>
                      </div>
                    </div>

                    <div class="text-center mt-4">
                      <button type="submit" class="btn btn-primary action-btn shadow">
                        <i class="fas fa-check me-2"></i> Create Survey
                      </button>
                    </div>
                  </form>
                  <div class="text-center mt-3">
                    <a href="sb.php" class="btn btn-secondary action-btn shadow">
                      <i class="fas fa-arrow-left me-2"></i> Back
                    </a>
                  </div>
                  <script>
                    // Toggle sections
                    document.getElementById('toggle-existing-questions').onclick = function() {
                      const sec = document.getElementById('existing-questions-section');
                      sec.style.display = sec.style.display === 'none' ? '' : 'none';
                    };
                    document.getElementById('toggle-new-questions').onclick = function() {
                      const sec = document.getElementById('questions-section');
                      sec.style.display = sec.style.display === 'none' ? '' : 'none';
                    };

                    // Add/remove new questions
                    let qIndex = 1;
                    document.getElementById('add-question-btn').onclick = function() {
                      const container = document.getElementById('questions-container');
                      const row = document.createElement('div');
                      row.className = 'row mb-2 question-row';
                      row.innerHTML = `
                        <div class="col-md-6">
                          <input type="text" name="questions[${qIndex}][label]" class="form-control" placeholder="Question label">
                        </div>
                        <div class="col-md-4">
                          <select name="questions[${qIndex}][type]" class="form-control">
                            <option value="text">Text</option>
                            <option value="select">Select</option>
                            <option value="number">Number</option>
                            <option value="date">Date</option>
                            <option value="boolean">Yes/No</option>
                          </select>
                        </div>
                        <div class="col-md-2">
                          <button type="button" class="btn btn-danger btn-sm remove-question">Remove</button>
                        </div>
                      `;
                      container.appendChild(row);
                      qIndex++;
                      updateRemoveButtons();
                    };
                    function updateRemoveButtons() {
                      document.querySelectorAll('.remove-question').forEach(btn => {
                        btn.style.display = document.querySelectorAll('.question-row').length > 1 ? '' : 'none';
                        btn.onclick = function() {
                          btn.closest('.question-row').remove();
                          updateRemoveButtons();
                        };
                      });
                    }
                    updateRemoveButtons();
                  </script>
                <?php
                // DHIS2 SURVEY CREATION
                elseif ($_GET['survey_source'] == 'dhis2') :
                ?>
                  <div class="text-center mb-4">
                    <h2 class="mb-1">DHIS2 Program/Dataset</h2>
                    <div class="text-secondary mb-3">Select your DHIS2 instance, domain, and program/dataset</div>
                  </div>
                  <div id="dhis2-survey-container">
                    </div>
                  <script>
                  // Simple working DHIS2 form loader
                  function loadDHIS2SurveyForm(params = {}) {
                    let url = 'sb.php?survey_source=dhis2';
                    if (params.dhis2_instance) url += '&dhis2_instance=' + encodeURIComponent(params.dhis2_instance);
                    if (params.domain) url += '&domain=' + encodeURIComponent(params.domain);
                    if (params.program_type) url += '&program_type=' + encodeURIComponent(params.program_type);
                    if (params.program_id) url += '&program_id=' + encodeURIComponent(params.program_id);

                    document.getElementById('dhis2-survey-container').innerHTML = '<div class=\"text-center py-5\"><div class=\"spinner-border text-primary\" role=\"status\"></div><p class=\"mt-3\">Loading DHIS2 details...</p></div>';
                    
                    // Add timeout and retry functionality
                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => controller.abort(), 90000); // 90 second timeout
                    
                    fetch(url + '&ajax=1', { signal: controller.signal })
                    .then(res => {
                        clearTimeout(timeoutId);
                        if (!res.ok) throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                        return res.text();
                    })
                    .then(html => {
                      document.getElementById('dhis2-survey-container').innerHTML = html;
                      
                      // Reattach event listeners
                      let instanceSel = document.getElementById('dhis2-instance-select');
                      if (instanceSel) instanceSel.onchange = function() {
                        loadDHIS2SurveyForm({dhis2_instance: this.value});
                      };
                      let domainSel = document.getElementById('domain-select');
                      if (domainSel) domainSel.onchange = function() {
                        loadDHIS2SurveyForm({
                          dhis2_instance: document.getElementById('dhis2-instance-select').value,
                          domain: this.value
                        });
                      };
                      let programTypeSel = document.getElementById('program-type-select');
                      if (programTypeSel) programTypeSel.onchange = function() {
                        loadDHIS2SurveyForm({
                          dhis2_instance: document.getElementById('dhis2-instance-select').value,
                          domain: document.getElementById('domain-select').value,
                          program_type: this.value
                        });
                      };
                      let progSel = document.getElementById('program-select');
                      if (progSel) progSel.onchange = function() {
                        loadDHIS2SurveyForm({
                          dhis2_instance: document.getElementById('dhis2-instance-select').value,
                          domain: document.getElementById('domain-select').value,
                          program_type: document.getElementById('program-type-select') ? document.getElementById('program-type-select').value : null,
                          program_id: this.value
                        });
                      };
                      
                      // Initialize selection controls
                      initializeSelectionControls();
                    })
                    .catch(error => {
                      clearTimeout(timeoutId);
                      console.error('Error loading DHIS2 survey form:', error);
                      
                      let errorMessage = 'Failed to load DHIS2 form.';
                      let isTimeout = false;
                      
                      if (error.name === 'AbortError') {
                        errorMessage = 'Request timed out - DHIS2 server is taking too long to respond.';
                        isTimeout = true;
                      } else if (error.message.includes('500')) {
                        errorMessage = 'DHIS2 server error - the server may be experiencing issues.';
                      } else if (error.message.includes('HTTP')) {
                        errorMessage = `Connection error: ${error.message}`;
                      }
                      
                      const retryButton = isTimeout || error.message.includes('500') ? 
                        '<button class="btn btn-outline-primary btn-sm mt-2" onclick="loadDHIS2SurveyForm(' + JSON.stringify(params) + ')"><i class="fas fa-redo me-1"></i> Retry</button>' : '';
                      
                      document.getElementById('dhis2-survey-container').innerHTML = `
                        <div class="alert alert-warning">
                          <h5><i class="fas fa-exclamation-triangle me-2"></i>Loading Issue</h5>
                          <p>${errorMessage}</p>
                          ${isTimeout ? '<p><small>Large programs may take longer to load. Try again or select a different program.</small></p>' : ''}
                          ${retryButton}
                        </div>
                      `;
                    });
                  }

                  function initializeSelectionControls() {
                    // Select All and Deselect All for Data Elements
                    document.querySelectorAll('.select-all-de').forEach(btn => {
                      btn.addEventListener('click', function() {
                        const target = document.getElementById('data-elements');
                        if (target) {
                          target.querySelectorAll('.data-element-checkbox').forEach(checkbox => {
                            checkbox.checked = true;
                          });
                          updateSelectedDataElements();
                        }
                      });
                    });

                    document.querySelectorAll('.deselect-all-de').forEach(btn => {
                      btn.addEventListener('click', function() {
                        const target = document.getElementById('data-elements');
                        if (target) {
                          target.querySelectorAll('.data-element-checkbox').forEach(checkbox => {
                            checkbox.checked = false;
                          });
                          updateSelectedDataElements();
                        }
                      });
                    });

                    // Select All and Deselect All for Attributes
                    document.querySelectorAll('.select-all-attr').forEach(btn => {
                      btn.addEventListener('click', function() {
                        const target = document.getElementById('attributes');
                        if (target) {
                          target.querySelectorAll('.attribute-checkbox').forEach(checkbox => {
                            checkbox.checked = true;
                          });
                          updateSelectedAttributes();
                        }
                      });
                    });

                    document.querySelectorAll('.deselect-all-attr').forEach(btn => {
                      btn.addEventListener('click', function() {
                        const target = document.getElementById('attributes');
                        if (target) {
                          target.querySelectorAll('.attribute-checkbox').forEach(checkbox => {
                            checkbox.checked = false;
                          });
                          updateSelectedAttributes();
                        }
                      });
                    });

                    // Update hidden inputs when checkboxes change
                    document.querySelectorAll('.data-element-checkbox').forEach(checkbox => {
                      checkbox.addEventListener('change', updateSelectedDataElements);
                    });

                    document.querySelectorAll('.attribute-checkbox').forEach(checkbox => {
                      checkbox.addEventListener('change', updateSelectedAttributes);
                    });

                    // Initial update of hidden fields
                    updateSelectedDataElements();
                    updateSelectedAttributes();
                  }

                  function updateSelectedDataElements() {
                    const selectedDEs = [];
                    document.querySelectorAll('.data-element-checkbox:checked').forEach(checkbox => {
                      selectedDEs.push(checkbox.value);
                    });
                    const allDEsInput = document.querySelector('input[name="all_data_elements"]');
                    if (allDEsInput) {
                      const allDEs = JSON.parse(allDEsInput.value);
                      const selectedData = Object.fromEntries(
                        Object.entries(allDEs).filter(([key, _]) => selectedDEs.includes(key))
                      );
                      const selectedInput = document.getElementById('selected_data_elements_input');
                      if (selectedInput) {
                        selectedInput.value = JSON.stringify(selectedData);
                      }
                    }
                  }

                  function updateSelectedAttributes() {
                    const selectedAttrs = [];
                    document.querySelectorAll('.attribute-checkbox:checked').forEach(checkbox => {
                      selectedAttrs.push(checkbox.value);
                    });
                    const allAttrsInput = document.querySelector('input[name="all_attributes"]');
                    const allAttrs = allAttrsInput ? JSON.parse(allAttrsInput.value || '{}') : {};
                    const selectedData = Object.fromEntries(
                      Object.entries(allAttrs).filter(([key, _]) => selectedAttrs.includes(key))
                    );
                    const selectedInput = document.getElementById('selected_attributes_input');
                    if (selectedInput) {
                      selectedInput.value = JSON.stringify(selectedData);
                    }
                  }

                  // Initial load of the DHIS2 form when the page loads
                  loadDHIS2SurveyForm({
                    <?php if (isset($_GET['dhis2_instance'])): ?>dhis2_instance: "<?= htmlspecialchars($_GET['dhis2_instance']) ?>",<?php endif; ?>
                    <?php if (isset($_GET['domain'])): ?>domain: "<?= htmlspecialchars($_GET['domain']) ?>",<?php endif; ?>
                    <?php if (isset($_GET['program_type'])): ?>program_type: "<?= htmlspecialchars($_GET['program_type']) ?>",<?php endif; ?>
                    <?php if (isset($_GET['program_id'])): ?>program_id: "<?= htmlspecialchars($_GET['program_id']) ?>",<?php endif; ?>
                  });
                  </script>
                <?php endif; ?>

              <?php endif; ?>

            </div>
          </div>
        </div>
      </div>
    </div>

    <?php include 'components/fixednav.php'; ?>
  </main>

  <script src="argon-dashboard-master/assets/js/core/popper.min.js"></script>
  <script src="argon-dashboard-master/assets/js/core/bootstrap.min.js"></script>
  <script src="argon-dashboard-master/assets/js/plugins/perfect-scrollbar.min.js"></script>
  <script src="argon-dashboard-master/assets/js/plugins/smooth-scrollbar.min.js"></script>
  <script src="argon-dashboard-master/assets/js/argon-dashboard.min.js"></script>

  <!-- Global Loading Overlay -->
  <div id="global-loading-overlay" class="loading-overlay" style="display: none;">
    <div class="loading-spinner">
      <div class="spinner-border" role="status"></div>
      <div class="loading-text" id="loading-text">Processing...</div>
    </div>
  </div>

  <script>
    // Global loading overlay functions
    function showLoadingOverlay(message = 'Loading...') {
      const overlay = document.getElementById('global-loading-overlay');
      const loadingText = document.getElementById('loading-text');
      if (overlay && loadingText) {
        loadingText.textContent = message;
        overlay.style.display = 'flex';
      }
    }
    
    function hideLoadingOverlay() {
      const overlay = document.getElementById('global-loading-overlay');
      if (overlay) {
        overlay.style.display = 'none';
      }
    }
    
    // Enhanced form submission with loading states
    document.addEventListener('DOMContentLoaded', function() {
      // Add loading states to all form submissions
      const forms = document.querySelectorAll('form');
      forms.forEach(form => {
        form.addEventListener('submit', function(e) {
          const submitBtn = form.querySelector('button[type="submit"]');
          if (submitBtn) {
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
            submitBtn.disabled = true;
            
            // Show global loading after a short delay
            setTimeout(() => {
              if (form.querySelector('[name="create_survey"]') || form.querySelector('[name="create_local_survey"]')) {
                showLoadingOverlay('Creating survey, please wait...');
              } else {
                showLoadingOverlay('Loading next step...');
              }
            }, 100);
            
            // Restore button state if form submission fails
            setTimeout(() => {
              if (submitBtn.disabled) {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
                hideLoadingOverlay();
              }
            }, 30000);
          }
        });
      });
      
      // Handle browser back/forward navigation
      window.addEventListener('pageshow', function(event) {
        if (event.persisted) {
          hideLoadingOverlay();
          // Re-enable any disabled buttons
          document.querySelectorAll('button[disabled]').forEach(btn => {
            btn.disabled = false;
          });
        }
      });
      
      // Add connection status monitoring
      let isOnline = navigator.onLine;
      
      window.addEventListener('online', function() {
        if (!isOnline) {
          isOnline = true;
          const offlineAlert = document.getElementById('offline-alert');
          if (offlineAlert) {
            offlineAlert.remove();
          }
        }
      });
      
      window.addEventListener('offline', function() {
        isOnline = false;
        const alertDiv = document.createElement('div');
        alertDiv.id = 'offline-alert';
        alertDiv.className = 'alert alert-warning alert-dismissible fade show position-fixed';
        alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 10000; max-width: 300px;';
        alertDiv.innerHTML = `
          <i class="fas fa-wifi-slash me-2"></i>
          <strong>Connection Lost</strong><br>
          <small>Please check your internet connection</small>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(alertDiv);
        
        // Auto-remove after 10 seconds
        setTimeout(() => {
          if (alertDiv.parentNode) {
            alertDiv.remove();
          }
        }, 10000);
      });
    });
  </script>

</body>
</html>