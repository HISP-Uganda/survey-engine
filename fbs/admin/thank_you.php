<?php
session_start();

// Check if the user has submitted the form
// This line needs to be aware of the survey ID to ensure correct context
if (!isset($_SESSION['submitted_uid'])) {
    // Redirect to the form page if no submission is found
    // Consider redirecting back to the original survey if possible, otherwise a generic survey list
    header("Location: survey.php"); // Assuming survey.php exists or adjust to your entry point
    exit;
}

// Get the submission UID
$uid = $_SESSION['submitted_uid'];
$surveyIdFromSession = $_SESSION['submitted_survey_id'] ?? null; // Get survey_id from session as well

// Database connection
$servername = "localhost";
$username = "root";
$password = "root";
$dbname = "fbtv3";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get basic submission data
// Using prepared statement for safety
$stmt = $conn->prepare("
    SELECT id, age, sex, period, service_unit_id, location_id, ownership_id, survey_id
    FROM submission
    WHERE uid = ?
");
$stmt->bind_param("s", $uid);
$stmt->execute();
$result = $stmt->get_result();
$submission = $result->fetch_assoc();
$stmt->close(); // Close the statement after use

// If no submission found with this UID
if (!$submission) {
    die("Submission not found.");
}

// IMPORTANT: Get survey_id directly from the submission if possible
$surveyId = $submission['survey_id'] ?? $surveyIdFromSession;

// Initialize facility name to a default that will be used for checking if it's "specified"
$facilityName = null; // Changed to null for stricter check
$facilityId = $submission['location_id']; // Get location_id from submission

// Conditionally fetch facility name ONLY if facilityId is not null
if ($facilityId !== null) {
    $stmt = $conn->prepare("SELECT name FROM location WHERE id = ?");
    $stmt->bind_param("i", $facilityId);
    $stmt->execute();
    $facilityResult = $stmt->get_result();
    $facility = $facilityResult->fetch_assoc();
    $stmt->close();

    if ($facility) {
        $facilityName = $facility['name'];
    }
}


// Check survey type before fetching service unit and ownership
$surveyType = 'local'; // Default to local

if ($surveyId) {
    $stmt = $conn->prepare("SELECT type FROM survey WHERE id = ?");
    $stmt->bind_param("i", $surveyId);
    $stmt->execute();
    $surveyTypeResult = $stmt->get_result();
    $surveyTypeRow = $surveyTypeResult->fetch_assoc();
    $stmt->close();
    if ($surveyTypeRow && isset($surveyTypeRow['type'])) {
        $surveyType = $surveyTypeRow['type'];
    }
}

$serviceUnitName = null; // Changed to null for stricter check
$ownershipName = null; // Changed to null for stricter check

$age = $submission['age'] ?? null; // Changed to null for stricter check
$sex = $submission['sex'] ?? null; // Changed to null for stricter check
$period = $submission['period'] ?? null; // Changed to null for stricter check

// Only fetch these specific details if the survey type is 'local' AND the IDs are not null
if ($surveyType === 'local') {
    // Get service unit name
    $serviceUnitId = $submission['service_unit_id'] ?? null;
    if ($serviceUnitId !== null) {
        $stmt = $conn->prepare("SELECT name FROM service_unit WHERE id = ?");
        $stmt->bind_param("i", $serviceUnitId);
        $stmt->execute();
        $serviceUnitResult = $stmt->get_result();
        $serviceUnit = $serviceUnitResult->fetch_assoc();
        $stmt->close();
        if ($serviceUnit) {
            $serviceUnitName = $serviceUnit['name'];
        }
    }

    // Get ownership name
    $ownershipId = $submission['ownership_id'] ?? null;
    if ($ownershipId !== null) {
        $stmt = $conn->prepare("SELECT name FROM owner WHERE id = ?");
        $stmt->bind_param("i", $ownershipId);
        $stmt->execute();
        $ownershipResult = $stmt->get_result();
        $ownership = $ownershipResult->fetch_assoc();
        $stmt->close();
        if ($ownership) {
            $ownershipName = $ownership['name'];
        }
    }
}

// Get responses
$submissionId = $submission['id'];
// Using prepared statement for responses as well
$stmt = $conn->prepare("
    SELECT sr.question_id, sr.response_value, q.label
    FROM submission_response sr
    JOIN question q ON sr.question_id = q.id
    WHERE sr.submission_id = ?
");
$stmt->bind_param("i", $submissionId);
$stmt->execute();
$responsesResult = $stmt->get_result();

$responses = [];
while ($row = $responsesResult->fetch_assoc()) {
    $responses[] = $row;
}
$stmt->close(); // Close the statement after use


// Clear the session variable to prevent refreshing the page and resubmitting
unset($_SESSION['submitted_uid']);
unset($_SESSION['submitted_survey_id']);


// Close database connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You for Your Feedback</title>
    <link rel="stylesheet" href="../styles.css">
    <style>
        /* Thank you container styling */
        body {
            font-family: Arial, sans-serif;
            background-color: #f8f9fa;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .thank-you-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 20px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .header-section {
            text-align: center;
            margin-bottom: 20px;
        }

        .logo-container {
            text-align: center;
            margin-bottom: 20px;
        }

        .logo-container img {
            max-width: 100%;
            height: 170px;
            object-fit: contain; /* Ensure logo fits within container */
        }

        .title {
            font-size: 20px;
            font-weight: bold;
            margin-top: 10px;
        }

        .subtitle {
            font-size: 18px;
            margin-top: 5px;
        }

        .flag-bar {
            display: flex;
            height: 10px;
            width: 100%;
            margin: 15px 0;
        }

        .flag-black {
            background-color: black;
            flex: 1;
        }

        .flag-yellow {
            background-color: #ffce00;
            flex: 1;
        }

        .flag-red {
            background-color: red;
            flex: 1;
        }

        h2 {
            color: #006400;
            text-align: center;
            margin: 20px 0;
        }

        .success-message {
            background-color: #dff0d8;
            color: #3c763d;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }

        .reference-id {
            background-color: #f7f7f9;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            border: 1px dashed #ccc;
        }

        .reference-id span {
            font-weight: bold;
            font-family: monospace;
            font-size: 16px;
        }

        .action-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin: 20px 0;
        }

        .action-button {
            padding: 10px 20px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }

        .action-button.secondary {
            background-color: #6c757d;
        }

        .action-button:hover {
            opacity: 0.9;
        }

        /* Submission details styling */
        .submission-details {
            display: none;
            margin-top: 30px;
            border-top: 1px solid #ddd;
            padding-top: 20px;
        }

        .details-section {
            margin-bottom: 20px;
        }

        .details-section h3 {
            color: #006400;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        th {
            background-color: #f2f2f2;
            font-weight: 600;
            width: 40%;
        }

        /* Utility class for hiding elements */
        .hidden-element {
            display: none !important;
        }

        @media print {
            .action-buttons, .print-hidden {
                display: none !important;
            }

            .submission-details {
                display: block !important;
            }

            body {
                font-size: 12pt;
            }

            .thank-you-container {
                box-shadow: none;
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <div class="thank-you-container" id="printableArea">
        <div class="header-section">
            <div class="logo-container">
                <img id="moh-logo" src="asets/asets/img/loog.jpg" alt="Ministry of Health Logo">
            </div>
            <div class="title hidden-element" id="republic-title-thankyou">THE REPUBLIC OF UGANDA</div>
            <div class="subtitle hidden-element" id="ministry-subtitle-thankyou">MINISTRY OF HEALTH</div>
        </div>

        <div class="flag-bar" id="flag-bar-thankyou">
            <div class="flag-black" id="flag-black-color-thankyou"></div>
            <div class="flag-yellow" id="flag-yellow-color-thankyou"></div>
            <div class="flag-red" id="flag-red-color-thankyou"></div>
        </div>

        <h2 id="survey-title-thankyou">CLIENT SATISFACTION FEEDBACK TOOL</h2>

        <div class="success-message">
            Thank you for taking the time to provide your valuable feedback! Your insights will help us improve healthcare services.
        </div>

        <div class="reference-id">
            Your Reference ID: <span><?php echo htmlspecialchars($uid); ?></span>
        </div>

        <div class="action-buttons print-hidden">
            <button class="action-button" id="viewDetailsBtn">View Your Responses</button>
            <button class="action-button" onclick="printSummary()">Download/Print Summary</button>
        </div>

        <div class="submission-details" id="submissionDetails">
            <div class="details-section">
                <h3>Respondent Information</h3>
                <table>
                    <tr>
                        <th>Reference ID</th>
                        <td><?php echo htmlspecialchars($uid); ?></td>
                    </tr>
                    <?php if ($facilityName !== null): ?>
                    <tr>
                        <th>Facility</th>
                        <td><?php echo htmlspecialchars($facilityName); ?></td>
                    </tr>
                    <?php endif; ?>

                    <?php if ($surveyType === 'local'): // Conditionally display for 'local' ?>
                        <?php if ($serviceUnitName !== null): ?>
                        <tr id="serviceUnitRow">
                            <th>Service Unit</th>
                            <td><?php echo htmlspecialchars($serviceUnitName); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($age !== null): ?>
                        <tr id="ageRow">
                            <th>Age</th>
                            <td><?php echo htmlspecialchars($age); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($sex !== null): ?>
                        <tr id="sexRow">
                            <th>Sex</th>
                            <td><?php echo htmlspecialchars($sex); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($period !== null): ?>
                        <tr id="dateRow">
                            <th>Date</th>
                            <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($period))); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($ownershipName !== null): ?>
                        <tr id="ownershipRow">
                            <th>Ownership</th>
                            <td><?php echo htmlspecialchars($ownershipName); ?></td>
                        </tr>
                        <?php endif; ?>
                    <?php endif; ?>
                </table>
            </div>

            <div class="details-section">
                <h3>Your Responses</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Question</th>
                            <th>Your Response</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($responses) > 0): ?>
                            <?php foreach ($responses as $response): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($response['label']); ?></td>
                                    <td><?php echo htmlspecialchars($response['response_value']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="2" style="text-align: center;">No responses recorded</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Pass the survey ID and type from PHP to JavaScript
        const currentSurveyId = "<?php echo $surveyId; ?>";
        const surveyType = "<?php echo $surveyType; ?>";

        document.addEventListener('DOMContentLoaded', function() {
            // --- DOM Elements for Branding ---
            const mohLogo = document.getElementById('moh-logo');
            const republicTitleElement = document.getElementById('republic-title-thankyou');
            const ministrySubtitleElement = document.getElementById('ministry-subtitle-thankyou');
            const flagBarElement = document.getElementById('flag-bar-thankyou');
            const flagBlackElement = document.getElementById('flag-black-color-thankyou');
            const flagYellowElement = document.getElementById('flag-yellow-color-thankyou');
            const flagRedElement = document.getElementById('flag-red-color-thankyou');
            const surveyTitleThankYou = document.getElementById('survey-title-thankyou');


            // --- DOM Elements for Conditional Submission Details (for JavaScript control if needed) ---
            // These are now less critical for hiding as PHP handles it, but kept for consistency
            const serviceUnitRow = document.getElementById('serviceUnitRow');
            const ageRow = document.getElementById('ageRow');
            const sexRow = document.getElementById('sexRow');
            const dateRow = document.getElementById('dateRow');
            const ownershipRow = document.getElementById('ownershipRow');


            /**
             * Loads preview settings from localStorage and applies them to the DOM.
             */
            function loadAndApplySettings() {
                if (!currentSurveyId) {
                    console.warn("Survey ID not found. Cannot load localStorage settings for branding.");
                    return;
                }
                const settingsKey = 'surveyPreviewSettings_' + currentSurveyId;
                const settings = JSON.parse(localStorage.getItem(settingsKey)) || {};

                console.log("Thank you page - Loaded settings:", settings);

                // Apply Logo
                if (settings.hasOwnProperty('showLogo') && !settings.showLogo) {
                    mohLogo.classList.add('hidden-element');
                } else {
                    mohLogo.classList.remove('hidden-element');
                    if (settings.logoSrc) { // Use logoSrc for the actual image on preview/thank you
                        mohLogo.src = settings.logoSrc;
                    }
                }

                // Apply Republic Title
                if (settings.hasOwnProperty('showRepublicTitleShare') && !settings.showRepublicTitleShare) {
                    republicTitleElement.classList.add('hidden-element');
                } else {
                    republicTitleElement.classList.remove('hidden-element');
                    republicTitleElement.textContent = settings.republicTitleText || 'THE REPUBLIC OF UGANDA';
                }

                // Apply Ministry Subtitle
                if (settings.hasOwnProperty('showMinistrySubtitleShare') && !settings.showMinistrySubtitleShare) {
                    ministrySubtitleElement.classList.add('hidden-element');
                } else {
                    ministrySubtitleElement.classList.remove('hidden-element');
                    ministrySubtitleElement.textContent = settings.ministrySubtitleText || 'MINISTRY OF HEALTH';
                }

                // Apply Flag Bar
                if (settings.hasOwnProperty('showFlagBar') && !settings.showFlagBar) {
                    flagBarElement.classList.add('hidden-element');
                } else {
                    flagBarElement.classList.remove('hidden-element');
                    flagBlackElement.style.backgroundColor = settings.flagBlackColor || 'black';
                    flagYellowElement.style.backgroundColor = settings.flagYellowColor || '#ffce00';
                    flagRedElement.style.backgroundColor = settings.flagRedColor || 'red';
                }

                // Apply Survey Title (from preview settings if available)
                if (settings.hasOwnProperty('showTitle') && !settings.showTitle) {
                    surveyTitleThankYou.classList.add('hidden-element');
                } else {
                    surveyTitleThankYou.classList.remove('hidden-element');
                    surveyTitleThankYou.textContent = settings.titleText || 'CLIENT SATISFACTION FEEDBACK TOOL';
                }

                // No need for specific JavaScript logic to hide the rows if null for surveyType 'dhis2'
                // because the PHP now completely omits the <tr> if the value is null.
                // The PHP conditional for `$surveyType === 'local'` already handles whether these fields
                // are considered at all.
            }

            // --- Initial Load ---
            loadAndApplySettings();

            // --- Toggle submission details ---
            document.getElementById('viewDetailsBtn').addEventListener('click', function() {
                var details = document.getElementById('submissionDetails');
                if (details.style.display === 'block') {
                    details.style.display = 'none';
                    this.textContent = 'View Your Responses';
                } else {
                    details.style.display = 'block';
                    this.textContent = 'Hide Your Responses';
                }
            });

            // --- Print summary function ---
            window.printSummary = function() {
                // Make sure details are visible before printing
                document.getElementById('submissionDetails').style.display = 'block';
                window.print();
            }
        });
    </script>
</body>
</html>