<?php
session_start();

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
    // Check if the user has already submitted the form
    if (isset($_SESSION['submitted_uid'])) {
        die("You have already submitted this form.");
    }

    // Get survey ID
    $surveyId = isset($_POST['survey_id']) ? intval($_POST['survey_id']) : null;
    if (!$surveyId) {
        die("Survey ID is missing.");
    }

    // Get the submission language
    $submissionLanguage = isset($_POST['submission_language']) ? sanitize($conn, $_POST['submission_language']) : 'en';

    // Generate a unique identifier (UID)
    $uid = generateUID();

    // Get and sanitize form data
    $age = isset($_POST['age']) ? intval($_POST['age']) : null;
    $sex = isset($_POST['sex']) ? sanitize($conn, $_POST['sex']) : null;
    $reportingPeriod = isset($_POST['reporting_period']) ? sanitize($conn, $_POST['reporting_period']) : null;
    $serviceUnitId = isset($_POST['serviceUnit']) ? intval($_POST['serviceUnit']) : null;
    $locationId = isset($_POST['facility_id']) ? intval($_POST['facility_id']) : null;
    $ownershipId = isset($_POST['ownership']) ? intval($_POST['ownership']) : null;

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

            // Handle different question types
            if ($questionType == 'checkbox' && isset($_POST[$responseKey]) && is_array($_POST[$responseKey])) {
                // For checkboxes, we might have multiple values
                foreach ($_POST[$responseKey] as $value) {
                    $sanitizedValue = sanitize($conn, $value);
                    $insertResponse->bind_param("iis", $submissionId, $questionId, $sanitizedValue);
                    $insertResponse->execute();
                }
            } elseif (isset($_POST[$responseKey])) {
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

        // Commit transaction
        $conn->commit();



// Prepare data for DHIS2 submission
$dhis2Data = [
    'survey_id' => $surveyId, // Add this line to include survey ID
    'age' => $age,
    'sex' => $sex,
    'serviceUnit' => $serviceUnitId,
    'ownership' => $ownershipId,
    'reportingPeriod' => $reportingPeriod,
    'facility_id' => $locationId,
    'responses' => []
];

// Add question responses
foreach ($_POST as $key => $value) {
    if (strpos($key, 'question_') === 0) {
        $questionId = substr($key, strlen('question_'));
        $dhis2Data['responses']["question_$questionId"] = is_array($value) ? implode(',', $value) : $value;
    }
}

// Submit to DHIS2
require_once 'dhis2/dhis2_submit.php';
$dhis2Result = submitSurveyToDHIS2($dhis2Data, $submissionId, $uid);

// You might want to log the DHIS2 submission result
if (!$dhis2Result['success']) {
    error_log("DHIS2 submission failed for submission $submissionId: " . print_r($dhis2Result, true));
}

  // Redirect to thank you page
        header("Location: thank_you.php?uid=$uid");
        exit;
    } catch (Exception $e) {
        // Roll back transaction on error
        $conn->rollback();
        echo "Error: " . $e->getMessage();
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