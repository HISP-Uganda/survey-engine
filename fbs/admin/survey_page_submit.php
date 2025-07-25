<?php
session_start();
// The path for dhis2_submit.php needs to be correct relative to this file.
// If dhis2 is a folder inside fbs, and this file is in fbs/admin, then this path is wrong.
// It should probably be something like:
// require_once '../dhis2/dhis2_submit.php'; // If dhis2 is a sibling of admin
require_once 'dhis2/dhis2_submit.php'; // Adjust this path if dhis2/dhis2_submit.php is in a different location.
                                        // For example, if dhis2 is inside fbs, and survey_page_submit.php
                                        // is in fbs/admin, then '../dhis2/' would navigate up to fbs/ then down to dhis2/


// Include the connect.php file which provides the $pdo object
// Since survey_page_submit.php is in fbs/admin, and connect.php is also in fbs/admin,
// the path is simply 'connect.php'.
require_once 'connect.php'; // This will make the $pdo variable available

// You no longer need these mysqli connection details and object creation
// $servername = "localhost";
// $username = "root";
// $password = "root";
// $dbname = "fbtv3";
// $conn = new mysqli($servername, $username, $password, $dbname);
// if ($conn->connect_error) {
//     die("Connection failed: " . $conn->connect_error);
// }

// Function to sanitize input data - Refactored for PDO
// For PDO, prepare statements handle most of the "sanitization" against SQL injection.
// For general string cleaning (like trimming whitespace), you don't need the connection object.
// If you absolutely need to escape a string *outside* of a prepared statement (rarely needed with PDO),
// you would use $pdo->quote(). But for user input in prepared statements, this is not needed.
function sanitize($data) { // Removed $conn parameter as it's not needed for basic trim
    return trim($data);
}

// Function to generate a unique identifier (UID)
function generateUID() {
    return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 10));
}

// Check if form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get survey ID - This remains compulsory
    $surveyId = isset($_POST['survey_id']) ? intval($_POST['survey_id']) : null;
    if (!$surveyId) {
        die("Survey ID is missing.");
    }

    // Get the submission language
    // Use the refactored sanitize function without $conn (or $pdo) parameter
    $submissionLanguage = isset($_POST['submission_language']) ? sanitize($_POST['submission_language']) : 'en';

    // Generate a unique identifier (UID)
    $uid = generateUID();

    // --- Fetch survey type (still useful for local logic) ---
    $surveyType = 'local'; // Default to 'local'
    // Use $pdo for prepare and execute
    $stmt = $pdo->prepare("SELECT type FROM survey WHERE id = ?");
    $stmt->execute([$surveyId]);
    $fetchedType = $stmt->fetchColumn(); // Fetches single column value
    if ($fetchedType) {
        $surveyType = $fetchedType;
    }
    $stmt = null; // Close statement by setting to null
    // --- End fetch survey type ---

    // Initialize optional variables. They will be null if not provided in POST.
    // Ensure these columns are set to NULLABLE in your database schema.
    $age = isset($_POST['age']) && $_POST['age'] !== '' ? intval($_POST['age']) : null;
    $sex = isset($_POST['sex']) && $_POST['sex'] !== '' ? sanitize($_POST['sex']) : null; // Use refactored sanitize
    $reportingPeriod = isset($_POST['reporting_period']) && $_POST['reporting_period'] !== '' ? sanitize($_POST['reporting_period']) : null; // Use refactored sanitize
    $serviceUnitId = isset($_POST['serviceUnit']) && $_POST['serviceUnit'] !== '' ? intval($_POST['serviceUnit']) : null;
    $ownershipId = isset($_POST['ownership']) && $_POST['ownership'] !== '' ? intval($_POST['ownership']) : null;
    $locationId = isset($_POST['facility_id']) && $_POST['facility_id'] !== '' ? intval($_POST['facility_id']) : null;

    // Begin transaction using $pdo
    $pdo->beginTransaction();

    try {
        // Insert into submission table using $pdo
        $insertSubmission = $pdo->prepare("
            INSERT INTO submission (
                uid,
                age,
                sex,
                period,
                service_unit_id,
                location_id,
                ownership_id,
                survey_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        // Execute with an array of parameters. PDO handles types automatically.
        $insertSubmission->execute([
            $uid,
            $age,
            $sex,
            $reportingPeriod,
            $serviceUnitId,
            $locationId,
            $ownershipId,
            $surveyId
        ]);

        // Get the submission ID using $pdo
        $submissionId = $pdo->lastInsertId();

        // Get questions for this survey using $pdo
        $questionsStmt = $pdo->prepare("
            SELECT q.id, q.question_type
            FROM question q
            JOIN survey_question sq ON q.id = sq.question_id
            WHERE sq.survey_id = ?
        ");
        $questionsStmt->execute([$surveyId]);
        $questions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC); // Fetch all results

        // Prepare statement for inserting responses using $pdo
        $insertResponse = $pdo->prepare("
            INSERT INTO submission_response (
                submission_id,
                question_id,
                response_value
            ) VALUES (?, ?, ?)
        ");

        // Process each question response
        foreach ($questions as $question) { // Iterate over fetched array
            $questionId = $question['id'];
            $questionType = $question['question_type'];
            $responseKey = "question_" . $questionId;

            // Only process if the response key exists in POST data and is not empty
            if (isset($_POST[$responseKey]) && $_POST[$responseKey] !== '') {
                if ($questionType == 'checkbox' && is_array($_POST[$responseKey])) {
                    // For checkboxes, we might have multiple values
                    foreach ($_POST[$responseKey] as $value) {
                        $sanitizedValue = sanitize($value); // Use refactored sanitize
                        $insertResponse->execute([$submissionId, $questionId, $sanitizedValue]);
                    }
                } else {
                    // For other question types
                    $responseValue = is_array($_POST[$responseKey]) ?
                                     implode(", ", array_map(function($item) { // No $conn or $pdo needed in closure
                                         return sanitize($item);
                                     }, $_POST[$responseKey])) :
                                     sanitize($_POST[$responseKey]); // Use refactored sanitize

                    $insertResponse->execute([$submissionId, $questionId, $responseValue]);
                }
            }
        }

        // Commit transaction using $pdo
        $pdo->commit();

        // Store UID in session to prevent resubmission
        $_SESSION['submitted_uid'] = $uid;

        // --- DHIS2 Submission Logic (Now applies to all types, but checks for config) ---
        try {
            // Ensure DHIS2SubmissionHandler can accept $pdo
            // You might need to adjust the DHIS2SubmissionHandler constructor if it was expecting a mysqli object.
            $dhis2Submitter = new DHIS2SubmissionHandler($pdo, $surveyId); // Pass $pdo

            if ($dhis2Submitter->isReadyForSubmission()) {
                $result = $dhis2Submitter->processSubmission($submissionId);

                if (!$result['success']) {
                    error_log("DHIS2 submission failed for submission ID $submissionId (Survey ID: $surveyId): " . $result['message']);
                } else {
                    error_log("DHIS2 submission successful or already processed for submission ID $submissionId (Survey ID: $surveyId): " . $result['message']);
                }
            } else {
                error_log("Skipping DHIS2 submission for survey ID $surveyId: No valid DHIS2 configuration found in the database (dhis2_instance or program_dataset is missing/empty).");
            }
        } catch (Exception $e) {
            error_log("Caught DHIS2 handler exception for survey ID $surveyId, submission ID $submissionId: " . $e->getMessage());
        }
        // --- End DHIS2 Submission Logic ---

        // Redirect to thank you page
        header("Location: thank_you.php?uid=$uid");
        exit;
    } catch (Exception $e) {
        // Roll back transaction on error using $pdo
        $pdo->rollBack();
        echo "Error: " . $e->getMessage();
        error_log("Caught main submission exception for survey ID $surveyId: " . $e->getMessage());
    }

    // PDO statements are closed when they go out of scope or explicitly set to null.
    // No explicit close calls needed like mysqli.
} else {
    // If not a POST request, redirect to the survey page
    header("Location: survey_page.php");
    exit;
}

// PDO connection doesn't need explicit close. It closes automatically when script ends.
// $conn->close(); // No longer needed
?>