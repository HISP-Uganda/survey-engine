<?php
session_start();

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- CHANGE 1: Remove direct database connection ---
// REMOVE these lines that establish a direct mysqli connection:
// $servername = "localhost";
// $username = "root";
// $password = "root";
// $dbname = "fbtv3";

// $conn = new mysqli($servername, $username, $password, $dbname);

// if ($conn->connect_error) {
//     http_response_code(500);
//     echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $conn->connect_error]);
//     exit();
// }
// --- END CHANGE 1 ---

// Redirect to login if not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

// --- CHANGE 2: Include centralized PDO connection and check for $pdo object ---
require 'connect.php'; // This line is already present and correct.

// Add a check to ensure $pdo object is available from connect.php
if (!isset($pdo)) {
    http_response_code(500);
    echo "Database connection failed: Central PDO object not found. Please check connect.php.";
    exit();
}
// --- END CHANGE 2 ---

require 'dhis2/dhis2_shared.php'; // Ensure this provides getDhis2Config() and dhis2_get()
require 'dhis2/dhis2_get_function.php'; // Ensure this has necessary DHIS2 API call functions

$success_message = null;
$error_message = null;

// Helper function to get or create an option set
// This function is already correctly using PDO and takes $conn as an argument,
// which will be replaced by $pdo when called. No direct changes inside this function.
function getOrCreateOptionSetId(PDO $conn, string $optionSetName): int
{
    $stmt = $conn->prepare("SELECT id FROM option_set WHERE name = ?");
    $stmt->execute([$optionSetName]);
    $existingOptionSet = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingOptionSet) {
        return $existingOptionSet['id'];
    } else {
        $stmt = $conn->prepare("INSERT INTO option_set (name) VALUES (?)");
        $stmt->execute([$optionSetName]);
        return $conn->lastInsertId();
    }
}

// Helper function to insert or update option set values and DHIS2 mappings
// This function is already correctly using PDO and takes $conn as an argument.
// No direct changes inside this function.
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
        // Check if mapping exists
        $stmt = $conn->prepare("SELECT id FROM dhis2_option_set_mapping WHERE local_value = ? AND dhis2_option_code = ? AND dhis2_option_set_id = ?");
        $stmt->execute([
            $option['name'],
            $option['code'],
            $dhis2OptionSetId
        ]);

        if (!$stmt->fetch()) {
            try {
                $stmt = $conn->prepare("INSERT INTO dhis2_option_set_mapping (local_value, dhis2_option_code, dhis2_option_set_id) VALUES (?, ?, ?)");
                $stmt->execute([
                    $option['name'],
                    $option['code'],
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


// Handle form submission for creating survey (both local and DHIS2)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // --- CHANGE 3: Remove redundant PDO connection and use global $pdo ---
        // REMOVE these lines that create a new PDO connection in the POST block:
        // $conn = new PDO("mysql:host=localhost;dbname=fbtv3;charset=utf8", "root", "root");
        // $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Set error mode for PDO

        // Replace all subsequent uses of `$conn` with `$pdo` in this entire POST block.
        // The `$pdo` object is already available globally from `connect.php`.
        $pdo->beginTransaction(); // Use $pdo for transactions

        if (isset($_POST['create_local_survey'])) {
            // Logic for Local Survey Creation
            $surveyName = trim($_POST['local_survey_name']);
            if (empty($surveyName)) {
                throw new Exception("Survey name cannot be empty.");
            }

            // Check for duplicate survey name
            $stmt = $pdo->prepare("SELECT id FROM survey WHERE name = ?"); // Use $pdo
            $stmt->execute([$surveyName]);
            if ($stmt->fetch()) {
                throw new Exception("A survey with the name '" . htmlspecialchars($surveyName) . "' already exists.");
            }

            // Insert survey
            $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
            $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-d', strtotime($startDate . ' +6 months'));
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            $stmt = $pdo->prepare("INSERT INTO survey (name, type, start_date, end_date, is_active) VALUES (?, 'local', ?, ?, ?)"); // Use $pdo
            $stmt->execute([$surveyName, $startDate, $endDate, $isActive]);
            $surveyId = $pdo->lastInsertId(); // Use $pdo
            $position = 1;

            // Attach existing questions
            if (!empty($_POST['attach_questions']) && is_array($_POST['attach_questions'])) {
                foreach ($_POST['attach_questions'] as $qid) {
                    $stmt = $pdo->prepare("INSERT INTO survey_question (survey_id, question_id, position) VALUES (?, ?, ?)"); // Use $pdo
                    $stmt->execute([$surveyId, $qid, $position++]);
                }
            }

            // Insert new questions (skip empty labels)
            if (!empty($_POST['questions']) && is_array($_POST['questions'])) {
                foreach ($_POST['questions'] as $q) {
                    $qLabel = trim($q['label']);
                    if (!empty($qLabel)) {
                        $stmt = $pdo->prepare("INSERT INTO question (label, question_type, is_required) VALUES (?, ?, 1)"); // Use $pdo
                        $stmt->execute([$qLabel, $q['type']]);
                        $questionId = $pdo->lastInsertId(); // Use $pdo
                        $stmt = $pdo->prepare("INSERT INTO survey_question (survey_id, question_id, position) VALUES (?, ?, ?)"); // Use $pdo
                        $stmt->execute([$surveyId, $questionId, $position++]);
                    }
                }
            }

            $pdo->commit(); // Use $pdo
            $success_message = "Local survey successfully created.";

        } elseif (isset($_POST['create_survey'])) {
            // Logic for DHIS2 Survey Creation with Category Combinations
            $dhis2Instance = $_POST['dhis2_instance'] ?? null;
            $programId = $_POST['program_id'] ?? null;
            $programName = $_POST['program_name'] ?? null;
            $domain = $_POST['domain'] ?? null;
            $programType = $_POST['program_type'] ?? null; // Only relevant for tracker domain

            if (empty($dhis2Instance) || empty($programId) || empty($programName) || empty($domain)) {
                throw new Exception("Missing essential DHIS2 survey parameters.");
            }

            // Check if survey already exists by program_dataset (UID) first
            $stmt = $pdo->prepare("SELECT id FROM survey WHERE program_dataset = ?"); // Use $pdo
            $stmt->execute([$programId]);
            $existingSurvey = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingSurvey) {
                throw new Exception("A survey for this program/dataset (UID) already exists.");
            }

            // If not found by UID, check by name
            $stmt = $pdo->prepare("SELECT id FROM survey WHERE name = ?"); // Use $pdo
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

            $stmt = $pdo->prepare("INSERT INTO survey (name, type, dhis2_instance, program_dataset) VALUES (?, 'dhis2', ?, ?)"); // Use $pdo
            $stmt->execute([$surveyDisplayName, $dhis2Instance, $programId]);
            $surveyId = $pdo->lastInsertId(); // Use $pdo
            $position = 1;

            // Handle Category Combination as first question if it exists
            $categoryCombo = json_decode($_POST['category_combo'] ?? 'null', true);
            if (!empty($categoryCombo)) {
                // Check if a question with this label already exists
                $stmt = $pdo->prepare("SELECT id, option_set_id FROM question WHERE label = ? AND question_type = 'select'"); // Use $pdo
                $stmt->execute([$categoryCombo['name']]);
                $existingQuestion = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existingQuestion) {
                    $categoryQuestionId = $existingQuestion['id'];
                    $categoryOptionSetId = $existingQuestion['option_set_id'];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO question (label, question_type, is_required) VALUES (?, 'select', 1)"); // Use $pdo
                    $stmt->execute([$categoryCombo['name']]);
                    $categoryQuestionId = $pdo->lastInsertId(); // Use $pdo

                    $categoryOptionSetId = getOrCreateOptionSetId($pdo, $categoryCombo['name']); // Use $pdo
                    $stmt = $pdo->prepare("UPDATE question SET option_set_id = ? WHERE id = ?"); // Use $pdo
                    $stmt->execute([$categoryOptionSetId, $categoryQuestionId]);
                }

                $stmt = $pdo->prepare("INSERT INTO survey_question (survey_id, question_id, position) VALUES (?, ?, ?)"); // Use $pdo
                $stmt->execute([$surveyId, $categoryQuestionId, $position]);
                $position++;

                if (!empty($categoryCombo['categoryOptionCombos'])) {
                    foreach ($categoryCombo['categoryOptionCombos'] as $catOptCombo) {
                        insertOptionSetValueAndMapping($pdo, $categoryOptionSetId, $catOptCombo, $categoryCombo['id']); // Use $pdo
                    }
                }

                // Check if mapping already exists to avoid duplication
                $stmt = $pdo->prepare("SELECT id FROM question_dhis2_mapping WHERE question_id = ? AND dhis2_dataelement_id = ? AND dhis2_option_set_id = ?"); // Use $pdo
                $stmt->execute([
                    $categoryQuestionId,
                    'category_combo',
                    $categoryCombo['id']
                ]);
                if (!$stmt->fetch()) {
                    $stmt = $pdo->prepare("INSERT INTO question_dhis2_mapping (question_id, dhis2_dataelement_id, dhis2_option_set_id) VALUES (?, ?, ?)"); // Use $pdo
                    $stmt->execute([
                        $categoryQuestionId,
                        'category_combo', // Special identifier for category combinations
                        $categoryCombo['id']
                    ]);
                }
            }

            // 2. Process data elements and create questions
            $dataElements = json_decode($_POST['data_elements'] ?? '[]', true);

            foreach ($dataElements as $deId => $element) {
                $questionType = !empty($element['optionSet']) ? 'select' : 'text';

                $stmt = $pdo->prepare("INSERT INTO question (label, question_type, is_required) VALUES (?, ?, 1)"); // Use $pdo
                $stmt->execute([$element['name'], $questionType]);
                $questionId = $pdo->lastInsertId(); // Use $pdo

                $stmt = $pdo->prepare("INSERT INTO survey_question (survey_id, question_id, position) VALUES (?, ?, ?)"); // Use $pdo
                $stmt->execute([$surveyId, $questionId, $position]);
                $position++;

                $stmt = $pdo->prepare("INSERT INTO question_dhis2_mapping (question_id, dhis2_dataelement_id, dhis2_option_set_id) VALUES (?, ?, ?)"); // Use $pdo
                $stmt->execute([
                    $questionId,
                    $deId,
                    $element['optionSet']['id'] ?? null
                ]);

                if (!empty($element['optionSet']) && !empty($element['options'])) {
                    $optionSetId = getOrCreateOptionSetId($pdo, $element['optionSet']['name']); // Use $pdo

                    $stmt = $pdo->prepare("UPDATE question SET option_set_id = ? WHERE id = ?"); // Use $pdo
                    $stmt->execute([$optionSetId, $questionId]);

                    foreach ($element['options'] as $option) {
                        insertOptionSetValueAndMapping($pdo, $optionSetId, $option, $element['optionSet']['id']); // Use $pdo
                    }
                }
            }

            // Process attributes if tracker program
            if ($domain === 'tracker' && $programType === 'tracker' && isset($_POST['attributes']) && !empty($_POST['attributes'])) {
                $attributes = json_decode($_POST['attributes'], true);

                foreach ($attributes as $attrId => $attr) {
                    $questionType = !empty($attr['optionSet']) ? 'select' : 'text';

                    $stmt = $pdo->prepare("INSERT INTO question (label, question_type, is_required) VALUES (?, ?, 1)"); // Use $pdo
                    $stmt->execute([$attr['name'], $questionType]);
                    $questionId = $pdo->lastInsertId(); // Use $pdo

                    $stmt = $pdo->prepare("INSERT INTO survey_question (survey_id, question_id, position) VALUES (?, ?, ?)"); // Use $pdo
                    $stmt->execute([$surveyId, $questionId, $position]);
                    $position++;

                    $stmt = $pdo->prepare("INSERT INTO question_dhis2_mapping (question_id, dhis2_dataelement_id, dhis2_option_set_id) VALUES (?, ?, ?)"); // Use $pdo
                    $stmt->execute([
                        $questionId,
                        $attrId,
                        $attr['optionSet']['id'] ?? null
                    ]);

                    if (!empty($attr['optionSet']) && !empty($attr['options'])) {
                        $optionSetId = getOrCreateOptionSetId($pdo, $attr['optionSet']['name']); // Use $pdo

                        $stmt = $pdo->prepare("UPDATE question SET option_set_id = ? WHERE id = ?"); // Use $pdo
                        $stmt->execute([$optionSetId, $questionId]);

                        foreach ($attr['options'] as $option) {
                            insertOptionSetValueAndMapping($pdo, $optionSetId, $option, $attr['optionSet']['id']); // Use $pdo
                        }
                    }
                }
            }

            $pdo->commit(); // Use $pdo
            $success_message = "Survey successfully created from DHIS2 program";
        }

    } catch (Exception $e) {
        // --- CHANGE 4: Use $pdo for transaction rollback ---
        if (isset($pdo) && $pdo->inTransaction()) { // Check if $pdo exists and a transaction is active
            $pdo->rollBack(); // Use $pdo
        }
        // --- END CHANGE 4 ---
        $error_message = "Error creating survey: " . $e->getMessage();
    }
}

/**
 * Get all active DHIS2 instances using getDhis2Config from dhis2_shared.php.
 * Returns an array of instance configs keyed by their 'key'.
 */
function getLocalDHIS2Config()
{
    global $pdo;

    $instances = [];
    if (!isset($pdo)) {
        error_log("ERROR: getLocalDHIS2Config: PDO object not available. Returning empty instances.");
        throw new Exception("Database connection not established for DHIS2 instance lookup."); // Throw exception to halt if critical
    }

    try {
        $stmt = $pdo->query("SELECT `key` FROM dhis2_instances WHERE status = 1 ORDER BY `key` ASC"); // ORDER BY added for consistent dropdown order
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $config = getDhis2Config($row['key']); // Calls the function in dhis2_shared.php
                if ($config) {
                    $instances[$row['key']] = $config;
                } else {
                    error_log("WARNING: getLocalDHIS2Config: getDhis2Config returned null for key: " . $row['key']); // Log if getDhis2Config failed for a key
                }
            }
        } else {
            error_log("ERROR: getLocalDHIS2Config: Failed to prepare or execute query for dhis2_instances table - " . json_encode($pdo->errorInfo()));
            throw new Exception("Failed to query active DHIS2 instances from database.");
        }
    } catch (PDOException $e) {
        error_log("ERROR: getLocalDHIS2Config: Database error: " . $e->getMessage());
        throw new Exception("Error fetching DHIS2 instances from database: " . $e->getMessage());
    }
    error_log("DEBUG: getLocalDHIS2Config: Successfully returning " . count($instances) . " DHIS2 instances."); // LOG THE FINAL COUNT
    return $instances;
}

/**
 * Fetches programs from DHIS2 based on program type.
 * 'event' will fetch event programs.
 * 'tracker' will fetch tracker programs.
 * If no type is specified, it fetches all.
 * (This function does not directly use local DB connection, relies on dhis2_get which you've already converted.)
 */
function getPrograms($instance, $programType = null)
{
    $filter = '';
    if ($programType === 'event') {
        $filter = '&filter=programType:eq:WITHOUT_REGISTRATION';
    } elseif ($programType === 'tracker') {
        $filter = '&filter=programType:eq:WITH_REGISTRATION';
    }
    $programs = dhis2_get('/api/programs?fields=id,name,programType' . $filter, $instance);
    return $programs['programs'] ?? [];
}

/**
 * Fetches datasets from DHIS2.
 * (This function does not directly use local DB connection, relies on dhis2_get.)
 */
function getDatasets($instance)
{
    // dhis2_get is assumed to be defined in dhis2_shared.php or dhis2_get_function.php
    $datasets = dhis2_get('/api/dataSets?fields=id,name', $instance);
    return $datasets['dataSets'] ?? [];
}

/**
 * Get category combination details for a program or dataset
 * (This function does not directly use local DB connection, relies on dhis2_get.)
 */
function getCategoryComboDetails($instance, $categoryComboId)
{
    if (empty($categoryComboId)) {
        return null;
    }

    $categoryCombo = dhis2_get('/api/categoryCombos/' . $categoryComboId . '?fields=id,name,categoryOptionCombos[id,name]', $instance);
    return $categoryCombo;
}

/**
 * Get details for a specific DHIS2 program or dataset, including data elements, attributes, and category combinations.
 * (This function does not directly use local DB connection, relies on dhis2_get.)
 */
function getProgramDetails($instance, $domain, $programId, $programType = null)
{
    $result = [
        'program' => null,
        'dataElements' => [],
        'attributes' => [],
        'optionSets' => [],
        'categoryCombo' => null
    ];

    if ($domain === 'tracker') {
        if ($programType === 'tracker') { // This is a WITH_REGISTRATION program
            // Fetch program details including tracked entity attributes and category combination
            $programInfo = dhis2_get('/api/programs/' . $programId . '?fields=id,name,programType,categoryCombo[id,name],programTrackedEntityAttributes[trackedEntityAttribute[id,name,optionSet[id,name]]]', $instance);

            $result['program'] = [
                'id' => $programInfo['id'],
                'name' => $programInfo['name'],
                'programType' => $programInfo['programType'] // Store the program type
            ];

            // Get category combination if exists
            if (!empty($programInfo['categoryCombo'])) {
                $result['categoryCombo'] = getCategoryComboDetails($instance, $programInfo['categoryCombo']['id']);
            }

            // Get program attributes (tracker-level data)
            if (!empty($programInfo['programTrackedEntityAttributes'])) {
                foreach ($programInfo['programTrackedEntityAttributes'] as $attr) {
                    $tea = $attr['trackedEntityAttribute'];
                    $result['attributes'][$tea['id']] = [
                        'name' => $tea['name'],
                        'optionSet' => $tea['optionSet'] ?? null
                    ];
                    if (!empty($tea['optionSet'])) {
                        $result['optionSets'][$tea['optionSet']['id']] = $tea['optionSet'];
                    }
                }
            }

        } elseif ($programType === 'event') { // This is a WITHOUT_REGISTRATION program
            // Fetch program details for event program including category combination
            $programInfo = dhis2_get('/api/programs/' . $programId . '?fields=id,name,programType,categoryCombo[id,name],programStages[id,name,programStageDataElements[dataElement[id,name,optionSet[id,name]]]]', $instance);

            $result['program'] = [
                'id' => $programInfo['id'],
                'name' => $programInfo['name'],
                'programType' => $programInfo['programType'] // Store the program type
            ];

            // Get category combination if exists
            if (!empty($programInfo['categoryCombo'])) {
                $result['categoryCombo'] = getCategoryComboDetails($instance, $programInfo['categoryCombo']['id']);
            }

            // Get data elements from program stages
            if (!empty($programInfo['programStages'])) {
                foreach ($programInfo['programStages'] as $stage) {
                    if (isset($stage['programStageDataElements'])) {
                        foreach ($stage['programStageDataElements'] as $psde) {
                            $de = $psde['dataElement'];
                            $result['dataElements'][$de['id']] = [
                                'name' => $de['name'],
                                'optionSet' => $de['optionSet'] ?? null
                            ];
                            if (!empty($de['optionSet'])) {
                                $result['optionSets'][$de['optionSet']['id']] = $de['optionSet'];
                            }
                        }
                    }
                }
            }
        } else {
            throw new Exception("Invalid program type for tracker domain.");
        }
    } elseif ($domain === 'aggregate') {
      // Get dataset details with category combination
      $datasetInfo = dhis2_get('/api/dataSets/' . $programId . '?fields=id,name,categoryCombo[id,name,categoryOptionCombos[id,name]],dataSetElements[dataElement[id,name,categoryCombo[id,name,categoryOptionCombos[id,name]]]]', $instance);
      $result['program'] = [
        'id' => $datasetInfo['id'],
        'name' => $datasetInfo['name']
      ];

      // Get category combination for aggregate dataset
      if (!empty($datasetInfo['categoryCombo'])) {
        $result['categoryCombo'] = [
          'id' => $datasetInfo['categoryCombo']['id'],
          'name' => $datasetInfo['categoryCombo']['name'],
          'categoryOptionCombos' => $datasetInfo['categoryCombo']['categoryOptionCombos'] ?? []
        ];

        // Treat the dataset's categoryCombo as an option set
        $result['optionSets'][$datasetInfo['categoryCombo']['id']] = [
          'id' => $datasetInfo['categoryCombo']['id'],
          'name' => $datasetInfo['categoryCombo']['name'],
          'options' => $datasetInfo['categoryCombo']['categoryOptionCombos'] ?? []
        ];
      }

      // Get data elements - for aggregate, use the data element's own categoryCombo as option set if not default
      if (!empty($datasetInfo['dataSetElements'])) {
        foreach ($datasetInfo['dataSetElements'] as $dse) {
          $de = $dse['dataElement'];
          $optionSet = null;
          $options = [];
          if (!empty($de['categoryCombo']) && !empty($de['categoryCombo']['categoryOptionCombos'])) {
            // Only use if not the DHIS2 default
            if (empty($de['categoryCombo']['name']) || !preg_match('/default/i', $de['categoryCombo']['name'])) {
              $optionSet = [
                'id' => $de['categoryCombo']['id'],
                'name' => $de['categoryCombo']['name']
              ];
              $options = $de['categoryCombo']['categoryOptionCombos'];
              // Add to optionSets for later processing
              $result['optionSets'][$de['categoryCombo']['id']] = [
                'id' => $de['categoryCombo']['id'],
                'name' => $de['categoryCombo']['name'],
                'options' => $options
              ];
            }
          }
          $result['dataElements'][$de['id']] = [
            'name' => $de['name'],
            'optionSet' => $optionSet,
            'options' => $options
          ];
        }
      }
    }

    // Fetch option values for all option sets (for tracker/event programs)
    foreach ($result['optionSets'] as $optionSetId => &$optionSet) {
        $optionSetDetails = dhis2_get('/api/optionSets/' . $optionSetId . '?fields=id,name,options[id,name,code]', $instance);
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
}

// Check if this is an AJAX request for DHIS2 form content
if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && $_GET['survey_source'] == 'dhis2') {
    // Clear any previous output buffer to ensure only the desired HTML is sent
    ob_clean();
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
                    $availableDhis2Instances = getLocalDHIS2Config(); // This calls the function defined above
                    // Add a log here to see what's actually passed to the HTML loop
                    error_log("DEBUG: create_survey.php dropdown loop: " . json_encode(array_keys($availableDhis2Instances)));
                    foreach ($availableDhis2Instances as $key => $config) : ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= (isset($_GET['dhis2_instance']) && $_GET['dhis2_instance'] == $key) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($config['description'] . ' (' . $key . ')') ?> 
                        <?php // USE $config['description'] for display if available, fallback to $key ?>
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
                    echo '<option value="">Error: ' . htmlspecialchars($e->getMessage()) . '</option>';
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
                                            // For aggregate, fetch and show the category combo for each data element (not the dataset's)
                                            $deCategoryCombo = null;
                                            try {
                                                $deDetails = dhis2_get('/api/dataElements/' . $deId . '?fields=categoryCombo[id,name,categoryOptionCombos[id,name]]', $_GET['dhis2_instance']);
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
                        <?php if ($_GET['domain'] == 'tracker' && $_GET['program_type'] == 'tracker'): ?>
                            <input type="hidden" name="all_attributes" value="<?= htmlspecialchars(json_encode($programDetails['attributes'])) ?>">
                            <input type="hidden" id="selected_attributes_input" name="attributes" value="">
                            <input type="hidden" name="program_type" value="tracker">
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
            echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
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
  </style>
</head>
<body>

  <?php include 'components/aside.php'; ?>

  <main class="main-content position-relative border-radius-lg">
    <?php include 'components/navbar.php'; ?>

      <?php
      // Set the page title variable for use in breadcrumb and header
      $pageTitle = "Create New Survey";
      ?>
      <div class="d-flex align-items-center flex-grow-1" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);  padding: 1rem 1.5rem; margin-bottom: 1.5rem;">
        <nav aria-label="breadcrumb" class="flex-grow-1">
          <ol class="breadcrumb mb-0 navbar-breadcrumb" style="background: transparent;">
            <li class="breadcrumb-item">
              <a href="main" class="breadcrumb-link" style="color: #ffd700; font-weight: 600;">
                <i class="fas fa-home me-1" style="color: #ffd700;"></i>Home
              </a>
            </li>
            <li class="breadcrumb-item active navbar-breadcrumb-active" aria-current="page" style="color: #fff; font-weight: 700;">
              <?= htmlspecialchars($pageTitle) ?>
            </li>
          </ol>
          <h5 class="navbar-title mb-0" style="color: #fff; text-shadow: 0 1px 8px #1e3c72, 0 0 2px #ffd700;">
            <?= htmlspecialchars($pageTitle) ?>
          </h5>
        </nav>
      </div>

    <div class="container-fluid py-4">
      <div class="row">
        <div class="col-12">
          <div class="card shadow-lg mb-5">
            <div class="card-header pb-0 text-center bg-gradient-primary text-white rounded-top">
              <h1 class="mb-1">
                <span class="text-white">
                  <i class="fas fa-exclamation-circle me-2" style="color: #000; 8px #fff;"></i>
                  Create New Survey
                </span>
              </h1>
              <p class="mb-0">Choose how you want to create your survey</p>
            </div>
            <div class="card-body px-4">

              <?php if ($success_message): ?>
                <div class="alert alert-success" role="alert" id="success-alert">
                  <?= htmlspecialchars($success_message) ?>
                </div>
                <script>
                  setTimeout(function() {
                    window.location.href = 'survey.php';
                  }, 2000);
                </script>
              <?php endif; ?>

              <?php if ($error_message): ?>
                <div class="alert alert-danger" role="alert">
                  <?= htmlspecialchars($error_message) ?>
                </div>
              <?php endif; ?>

              <?php if (!isset($_GET['survey_source'])): ?>
                <div class="row justify-content-center mb-4">
                  <div class="col-md-8 mb-4">
                    <form method="get" action="" id="basic-survey-details-form">
                      <div class="card p-4 shadow-sm mb-3">
                        <h4 class="mb-3 text-primary">Survey Details</h4>
                        <div class="mb-3">
                          <label class="form-label">Survey Type <span class="text-danger">*</span></label>
                          <select name="survey_source" class="form-control" id="survey-type-select" required>
                            <option value="">-- Select Survey Type --</option>
                            <option value="local">Local Survey</option>
                            <option value="dhis2">DHIS2 Program/Dataset</option>
                          </select>
                        </div>
                        <div class="mb-3">
                          <label class="form-label">Description</label>
                          <textarea name="survey_description" class="form-control" rows="2"></textarea>
                        </div>
                        </div>
                      <div class="row" id="survey-type-buttons" style="display:none;">
                        <div class="col-md-6 mb-3">
                          <button type="submit" name="survey_source" value="local" class="w-100 btn btn-outline-primary py-4 shadow-sm" style="border-radius: 16px; border-width: 2px;">
                            <div class="mb-2" style="font-size: 2.5rem;">
                              <i class="fa-solid fa-pen-to-square"></i>
                            </div>
                            <div class="fw-bold" style="font-size: 1.3rem;">Local Survey</div>
                            <div class="text-secondary mt-2">Create a custom survey with your own questions</div>
                          </button>
                        </div>
                        <div class="col-md-6 mb-3">
                          <button type="submit" name="survey_source" value="dhis2" class="w-100 btn btn-outline-primary py-4 shadow-sm" style="border-radius: 16px; border-width: 2px;">
                            <div class="mb-2" style="font-size: 2.5rem;">
                              <i class="fa-solid fa-database"></i>
                            </div>
                            <div class="fw-bold" style="font-size: 1.3rem;">DHIS2 Program/Dataset</div>
                            <div class="text-secondary mt-2">Import from DHIS2 program or dataset</div>
                          </button>
                        </div>
                      </div>
                    </form>
                  </div>
                </div>
                <script>
                  // Hide buttons, show only after survey type is selected (if you want to use buttons instead of dropdown submit)
                  document.addEventListener('DOMContentLoaded', function() {
                    var surveyTypeSelect = document.getElementById('survey-type-select');
                    var buttonsRow = document.getElementById('survey-type-buttons');
                    if (surveyTypeSelect && buttonsRow) {
                      surveyTypeSelect.onchange = function() {
                        // If you want to show buttons after selection, uncomment below:
                        // buttonsRow.style.display = this.value ? '' : 'none';
                        // If you want to submit on select, submit the form:
                        if (this.value) {
                          document.getElementById('basic-survey-details-form').submit();
                        }
                      };
                      // Initially hidden
                      buttonsRow.style.display = 'none';
                    }
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
                  <form method="POST" action="" class="p-3 rounded bg-light shadow-sm">
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
                          // --- CHANGE 6: Use global $pdo for fetching existing questions ---
                          // REMOVE these lines that create a new PDO connection:
                          // $conn = new PDO("mysql:host=localhost;dbname=fbtv3;charset=utf8", "root", "root");
                          $stmt = $pdo->query("SELECT q.id, q.label, q.question_type, q.is_required, q.option_set_id, os.name AS option_set_name
                                                FROM question q
                                                LEFT JOIN option_set os ON q.option_set_id = os.id
                                                ORDER BY q.label ASC");
                          $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                          // --- END CHANGE 6 ---
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
                            // --- CHANGE 7: Use global $pdo for fetching option values ---
                            $optStmt = $pdo->prepare("SELECT option_value FROM option_set_values WHERE option_set_id = ?"); // Use $pdo
                            $optStmt->execute([$q['option_set_id']]);
                            $opts = $optStmt->fetchAll(PDO::FETCH_COLUMN);
                            // --- END CHANGE 7 ---
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
                      <button type="submit" name="create_local_survey" class="btn btn-primary action-btn shadow">
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
                  // AJAX loader for DHIS2 survey creation
                  function loadDHIS2SurveyForm(params = {}) {
                    let url = '<?= basename($_SERVER['PHP_SELF']) ?>?survey_source=dhis2';
                    if (params.dhis2_instance) url += '&dhis2_instance=' + encodeURIComponent(params.dhis2_instance);
                    if (params.domain) url += '&domain=' + encodeURIComponent(params.domain);
                    // Add program_type to the URL parameters
                    if (params.program_type) url += '&program_type=' + encodeURIComponent(params.program_type);
                    if (params.program_id) url += '&program_id=' + encodeURIComponent(params.program_id);


                    document.getElementById('dhis2-survey-container').innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-3">Loading DHIS2 details...</p></div>';
                    fetch(url + '&ajax=1')
                    .then(res => res.text())
                    .then(html => {
                      document.getElementById('dhis2-survey-container').innerHTML = html;
                      // Re-attach event listeners for selects within the newly loaded content
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
                      // New: Event listener for Program Type Select
                      let programTypeSel = document.getElementById('program-type-select');
                      if (programTypeSel) programTypeSel.onchange = function() {
                        loadDHIS2SurveyForm({
                          dhis2_instance: document.getElementById('dhis2-instance-select').value,
                          domain: document.getElementById('domain-select').value,
                          program_type: this.value // Pass the selected program type
                        });
                      };
                      let progSel = document.getElementById('program-select');
                      if (progSel) progSel.onchange = function() {
                        loadDHIS2SurveyForm({
                          dhis2_instance: document.getElementById('dhis2-instance-select').value,
                          domain: document.getElementById('domain-select').value,
                          program_type: document.getElementById('program-type-select') ? document.getElementById('program-type-select').value : null, // Ensure program_type is passed
                          program_id: this.value
                        });
                      };


                      // Initialize selection controls after content is loaded
                      initializeSelectionControls();
                    })
                    .catch(error => {
                        console.error('Error loading DHIS2 survey form:', error);
                        document.getElementById('dhis2-survey-container').innerHTML = '<div class="alert alert-danger">Failed to load DHIS2 form. Please try again.</div>';
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

</body>
</html>