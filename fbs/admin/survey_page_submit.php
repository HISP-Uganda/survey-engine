<?php
session_start();
require_once 'dhis2/dhis2_submit.php';

// Database connection
$servername = "localhost";
$username = "root";
$password = "root";
$dbname = "fbtv3";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to sanitize input data
function sanitize($conn, $data) {
    return $conn->real_escape_string(trim($data));
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
    $submissionLanguage = isset($_POST['submission_language']) ? sanitize($conn, $_POST['submission_language']) : 'en';

    // Generate a unique identifier (UID)
    $uid = generateUID();

    // --- Fetch survey type (still useful for local logic) ---
    $surveyType = 'local'; // Default to 'local'
    $stmt = $conn->prepare("SELECT type FROM survey WHERE id = ?");
    $stmt->bind_param("i", $surveyId);
    $stmt->execute();
    $stmt->bind_result($fetchedType);
    if ($stmt->fetch()) {
        $surveyType = $fetchedType;
    }
    $stmt->close();
    // --- End fetch survey type ---

    // Initialize optional variables. They will be null if not provided in POST.
    // Ensure these columns are set to NULLABLE in your database schema.
    $age = isset($_POST['age']) && $_POST['age'] !== '' ? intval($_POST['age']) : null;
    $sex = isset($_POST['sex']) && $_POST['sex'] !== '' ? sanitize($conn, $_POST['sex']) : null;
    $reportingPeriod = isset($_POST['reporting_period']) && $_POST['reporting_period'] !== '' ? sanitize($conn, $_POST['reporting_period']) : null;
    $serviceUnitId = isset($_POST['serviceUnit']) && $_POST['serviceUnit'] !== '' ? intval($_POST['serviceUnit']) : null;
    $ownershipId = isset($_POST['ownership']) && $_POST['ownership'] !== '' ? intval($_POST['ownership']) : null;
    $locationId = isset($_POST['facility_id']) && $_POST['facility_id'] !== '' ? intval($_POST['facility_id']) : null;

    // Begin transaction
    $conn->begin_transaction();

    try {
        // Insert into submission table
        $insertSubmission = $conn->prepare("
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

        // The 'bind_param' function handles NULL values correctly for corresponding database types
        // as long as the database columns are set to NULLABLE.
        $insertSubmission->bind_param(
            "sissiiii",
            $uid,
            $age,
            $sex,
            $reportingPeriod,
            $serviceUnitId,
            $locationId,
            $ownershipId,
            $surveyId
        );

        $insertSubmission->execute();

        // Get the submission ID
        $submissionId = $conn->insert_id;

        // Get questions for this survey
        $questions = $conn->query("
            SELECT q.id, q.question_type
            FROM question q
            JOIN survey_question sq ON q.id = sq.question_id
            WHERE sq.survey_id = $surveyId
        ");

        // Prepare statement for inserting responses
        $insertResponse = $conn->prepare("
            INSERT INTO submission_response (
                submission_id,
                question_id,
                response_value
            ) VALUES (?, ?, ?)
        ");

        // Process each question response
        while ($question = $questions->fetch_assoc()) {
            $questionId = $question['id'];
            $questionType = $question['question_type'];
            $responseKey = "question_" . $questionId;

            // Only process if the response key exists in POST data and is not empty
            if (isset($_POST[$responseKey]) && $_POST[$responseKey] !== '') {
                if ($questionType == 'checkbox' && is_array($_POST[$responseKey])) {
                    // For checkboxes, we might have multiple values
                    foreach ($_POST[$responseKey] as $value) {
                        $sanitizedValue = sanitize($conn, $value);
                        $insertResponse->bind_param("iis", $submissionId, $questionId, $sanitizedValue);
                        $insertResponse->execute();
                    }
                } else {
                    // For other question types
                    $responseValue = is_array($_POST[$responseKey]) ?
                                     implode(", ", array_map(function($item) use ($conn) {
                                         return sanitize($conn, $item);
                                     }, $_POST[$responseKey])) :
                                     sanitize($conn, $_POST[$responseKey]);

                    $insertResponse->bind_param("iis", $submissionId, $questionId, $responseValue);
                    $insertResponse->execute();
                }
            }
            // If the response key is not set or empty, we simply skip inserting a response for it,
            // effectively making it optional.
        }

        // Commit transaction
        $conn->commit();

        // Store UID in session to prevent resubmission
        $_SESSION['submitted_uid'] = $uid;

        // --- DHIS2 Submission Logic (Now applies to all types, but checks for config) ---
        try {
            $dhis2Submitter = new DHIS2SubmissionHandler($conn, $surveyId); // Instantiate unconditionally

            if ($dhis2Submitter->isReadyForSubmission()) { // Check if configuration was successful
                $result = $dhis2Submitter->processSubmission($submissionId);

                if (!$result['success']) {
                    error_log("DHIS2 submission failed for submission ID $submissionId (Survey ID: $surveyId): " . $result['message']);
                    // You might want to log this to a separate table for failed DHIS2 submissions
                } else {
                    error_log("DHIS2 submission successful or already processed for submission ID $submissionId (Survey ID: $surveyId): " . $result['message']);
                }
            } else {
                error_log("Skipping DHIS2 submission for survey ID $surveyId: No valid DHIS2 configuration found in the database (dhis2_instance or program_dataset is missing/empty).");
            }
        } catch (Exception $e) {
            // Catch any exceptions specifically from the DHIS2 handler (e.g., if determineProgramType fails)
            error_log("Caught DHIS2 handler exception for survey ID $surveyId, submission ID $submissionId: " . $e->getMessage());
        }
        // --- End DHIS2 Submission Logic ---

        // Redirect to thank you page
        header("Location: thank_you.php?uid=$uid");
        exit;
    } catch (Exception $e) {
        // Roll back transaction on error
        $conn->rollback();
        echo "Error: " . $e->getMessage();
        // It's good to log the actual submission ID if available, but here it might not be.
        error_log("Caught main submission exception for survey ID $surveyId: " . $e->getMessage());
    }

    // Close prepared statements
    if (isset($insertSubmission)) $insertSubmission->close();
    if (isset($insertResponse)) $insertResponse->close();
} else {
    // If not a POST request, redirect to the survey page
    header("Location: survey_page.php");
    exit;
}

// Close database connection
$conn->close();
?>