<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require 'connect.php';

// Helper function to check if current user can delete
function canUserDelete() {
    if (!isset($_SESSION['admin_role_name']) && !isset($_SESSION['admin_role_id'])) {
        return false;
    }
    
    // Super users can delete - check by role name or role ID
    $roleName = $_SESSION['admin_role_name'] ?? '';
    $roleId = $_SESSION['admin_role_id'] ?? 0;
    
    return ($roleName === 'super_user' || $roleName === 'admin' || $roleId == 1);
}

$submissionId = $_GET['submission_id'] ?? null;

if (!$submissionId) {
    die("Submission ID is required.");
}

// First, determine if this is a regular or tracker submission
$stmt = $pdo->prepare("
    SELECT 'regular' as type, id FROM submission WHERE id = :submission_id
    UNION
    SELECT 'tracker' as type, id FROM tracker_submissions WHERE id = :submission_id
");
$stmt->execute(['submission_id' => $submissionId]);
$submissionType = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$submissionType) {
    die("Submission not found.");
}

// Get submission details based on type
if ($submissionType['type'] === 'regular') {
    $stmt = $pdo->prepare("
        SELECT 
            s.id, 
            s.uid, 
            l.name AS location_name,
            surv.name AS survey_name,
            s.created,
            'regular' as submission_type
        FROM submission s
        LEFT JOIN location l ON s.location_id = l.id
        LEFT JOIN survey surv ON s.survey_id = surv.id
        WHERE s.id = :submission_id
    ");
    $stmt->execute(['submission_id' => $submissionId]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    // Tracker submission
    $stmt = $pdo->prepare("
        SELECT 
            ts.id, 
            ts.uid, 
            ts.selected_facility_name AS location_name,
            surv.name AS survey_name,
            ts.submitted_at as created,
            'tracker' as submission_type,
            ts.tracked_entity_instance,
            ts.form_data,
            ts.dhis2_response
        FROM tracker_submissions ts
        LEFT JOIN survey surv ON ts.survey_id = surv.id
        WHERE ts.id = :submission_id
    ");
    $stmt->execute(['submission_id' => $submissionId]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$submission) {
    die("Submission not found.");
}

// Get responses based on submission type
$groupedResponses = [];

if ($submission['submission_type'] === 'regular') {
    // Regular submission - get responses from submission_response table
    $stmt = $pdo->prepare("
        SELECT 
            q.id AS question_id,
            q.label AS question_text,
            q.question_type,
            sr.response_value
        FROM submission_response sr
        JOIN question q ON sr.question_id = q.id
        WHERE sr.submission_id = :submission_id
        ORDER BY q.id
    ");
    $stmt->execute(['submission_id' => $submissionId]);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group responses by question (for checkbox questions with multiple responses)
    foreach ($responses as $response) {
        $questionId = $response['question_id'];
        if (!isset($groupedResponses[$questionId])) {
            $groupedResponses[$questionId] = [
                'question_text' => $response['question_text'],
                'question_type' => $response['question_type'],
                'responses' => []
            ];
        }
        $groupedResponses[$questionId]['responses'][] = $response['response_value'];
    }
} else {
    // Tracker submission - parse JSON form_data and get dynamic labels
    $formData = json_decode($submission['form_data'], true);
    
    // Get survey ID to fetch tracker form configuration
    $surveyId = null;
    $stmt = $pdo->prepare("SELECT survey_id FROM tracker_submissions WHERE id = ?");
    $stmt->execute([$submissionId]);
    $surveyId = $stmt->fetchColumn();
    
    // Fetch tracker form field labels from question table
    $fieldLabels = [];
    $stageLabels = [];
    if ($surveyId) {
        // Get question labels mapped to DHIS2 fields
        $stmt = $pdo->prepare("
            SELECT q.label, q.question_type, qm.dhis2_dataelement_id, qm.dhis2_attribute_id, qm.dhis2_program_stage_id
            FROM question q
            JOIN survey_question sq ON q.id = sq.question_id
            LEFT JOIN question_dhis2_mapping qm ON q.id = qm.question_id
            WHERE sq.survey_id = ? AND (qm.dhis2_dataelement_id IS NOT NULL OR qm.dhis2_attribute_id IS NOT NULL)
        ");
        $stmt->execute([$surveyId]);
        $questionMappings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($questionMappings as $mapping) {
            if ($mapping['dhis2_dataelement_id']) {
                $fieldLabels[$mapping['dhis2_dataelement_id']] = $mapping['label'];
            }
            if ($mapping['dhis2_attribute_id']) {
                $fieldLabels[$mapping['dhis2_attribute_id']] = $mapping['label'];
            }
            if ($mapping['dhis2_program_stage_id']) {
                $stageLabels[$mapping['dhis2_program_stage_id']] = $mapping['label'];
            }
        }
    }
    
    if ($formData) {
        // Handle Tracked Entity Attributes with dynamic labels
        if (isset($formData['trackedEntityAttributes'])) {
            foreach ($formData['trackedEntityAttributes'] as $attributeId => $value) {
                $questionLabel = $fieldLabels[$attributeId] ?? "Attribute ($attributeId)";
                $groupedResponses["tea_$attributeId"] = [
                    'question_text' => $questionLabel,
                    'question_type' => 'text',
                    'responses' => [$value],
                    'is_attribute' => true
                ];
            }
        }
        
        // Handle Program Stage Events (repeatable stages) with dynamic labels
        if (isset($formData['events'])) {
            $stageCounter = 1;
            foreach ($formData['events'] as $eventKey => $eventData) {
                $programStageId = $eventData['programStage'] ?? '';
                $stageTitle = $stageLabels[$programStageId] ?? "Program Stage $stageCounter";
                
                // Add entry number for repeatable stages
                if ($stageCounter > 1) {
                    $stageTitle .= " (Entry $stageCounter)";
                }
                
                // Add stage header with dynamic naming
                $groupedResponses["stage_header_$eventKey"] = [
                    'question_text' => $stageTitle,
                    'question_type' => 'stage_header',
                    'responses' => [],
                    'is_stage_header' => true,
                    'stage_number' => $stageCounter
                ];
                
                // Add data values for this stage with dynamic labels
                if (isset($eventData['dataValues'])) {
                    foreach ($eventData['dataValues'] as $dataElementId => $value) {
                        $questionLabel = $fieldLabels[$dataElementId] ?? "Field ($dataElementId)";
                        
                        // Format boolean values for better display
                        if (is_bool($value)) {
                            $value = $value ? 'Yes' : 'No';
                        } elseif ($value === 'true') {
                            $value = 'Yes';
                        } elseif ($value === 'false') {
                            $value = 'No';
                        }
                        
                        $groupedResponses["de_{$eventKey}_$dataElementId"] = [
                            'question_text' => $questionLabel,
                            'question_type' => 'text',
                            'responses' => [$value],
                            'is_data_element' => true,
                            'stage_key' => $eventKey,
                            'stage_number' => $stageCounter
                        ];
                    }
                }
                $stageCounter++;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Submission</title>
    <!-- Argon Dashboard CSS -->
      <link rel="icon" type="image/png" href="argon-dashboard-master/assets/img/webhook-icon.png">
    <link href="argon-dashboard-master/assets/css/nucleo-icons.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-svg.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/argon-dashboard.min.css" rel="stylesheet">
    <style>
        .response-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        .response-table th {
            background-color: rgba(248, 249, 250, 0.8);
            font-weight: 600;
            color: #525f7f;
            position: sticky;
            top: 0;
        }
        .response-table th, 
        .response-table td {
            padding: 1rem;
            vertical-align: top;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }
        .response-table tr:last-child td {
            border-bottom: none;
        }
        .response-table tr:hover td {
            background-color: rgba(248, 249, 250, 0.6);
        }
        .question-cell {
            width: 40%;
            font-weight: 600;
            color: #252f40;
        }
        .response-cell {
            width: 60%;
        }
        .multi-response {
            padding-left: 0;
            list-style: none;
        }
        .multi-response li {
            padding: 0.5rem 0;
            border-bottom: 1px dashed rgba(0, 0, 0, 0.1);
        }
        .multi-response li:last-child {
            border-bottom: none;
        }
        .detail-card .card-body {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }
        .detail-item {
            margin-bottom: 0.5rem;
        }
        .detail-label {
            font-weight: 600;
            color: #525f7f;
            font-size: 0.875rem;
        }
        .detail-value {
            color: #252f40;
            font-size: 0.95rem;
        }
        .print-only {
            display: none;
        }
        @media print {
            .no-print {
                display: none;
            }
            .print-only {
                display: block;
            }
            body {
                background: white;
                color: black;
            }
            .card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100">
    <?php include 'components/aside.php'; ?>
    
    <main class="main-content position-relative border-radius-lg">
        <?php include 'components/navbar.php'; ?>

        <div class="container-fluid py-4">
            <!-- Header with print button -->
            <div class="row mb-4 no-print">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0">Submission Details</h6>
                            <p class="text-sm text-secondary mb-0">ID: <?php echo $submission['id']; ?></p>
                        </div>
                        <div class="d-flex gap-2">
                            <!-- <button class="btn btn-sm btn-outline-primary mb-0" onclick="window.print()">
                                <i class="fas fa-print me-1"></i> Print
                            </button> -->
                            <button class="btn btn-sm btn-outline-dark mb-0" onclick="goBack()">
                                <i class="fas fa-arrow-left me-1"></i> Back to List
                            </button>
                            <?php if (canUserDelete()): ?>
                            <button class="btn btn-sm btn-outline-danger mb-0" onclick="deleteSubmission(<?php echo $submission['id']; ?>)">
                                <i class="fas fa-trash me-1"></i> Delete
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Print header (only shows when printing) -->
            <!-- <div class="print-only mb-4">
                <h4>Submission Details</h4>
                <p class="text-sm">ID: <?php echo $submission['id']; ?> | Survey: <?php echo htmlspecialchars($submission['survey_name']); ?></p>
                <p class="text-sm">Date: <?php echo date('M j, Y g:i A', strtotime($submission['created'])); ?></p>
                <hr>
            </div> -->

            <!-- Submission Details Card -->
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <h6>General Information</h6>
                </div>
                <div class="card-body">
                    <div class="detail-item">
                        <span class="detail-label">UID:</span>
                        <p class="detail-value"><?php echo htmlspecialchars($submission['uid']); ?></p>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Survey:</span>
                        <p class="detail-value"><?php echo htmlspecialchars($submission['survey_name']); ?></p>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Location:</span>
                        <p class="detail-value"><?php echo htmlspecialchars($submission['location_name'] ?? 'N/A'); ?></p>
                    </div>
                   
                    <div class="detail-item">
                        <span class="detail-label">Date Submitted:</span>
                        <p class="detail-value"><?php echo date('M j, Y g:i A', strtotime($submission['created'])); ?></p>
                    </div>
                </div>
            </div>

            <!-- Responses Card -->
            <div class="card">
                <div class="card-header pb-0">
                    <h6>Question Responses</h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($groupedResponses)): ?>
                        <div class="text-center p-4">
                            <i class="fas fa-inbox fa-3x text-gray-300 mb-3"></i>
                            <h6 class="text-gray-500">No responses found</h6>
                            <p class="text-sm text-gray-400">This submission doesn't contain any responses</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="response-table">
                                <thead>
                                    <tr>
                                        <th class="question-cell">Question</th>
                                        <th class="response-cell">Response</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($groupedResponses as $questionId => $data): ?>
                                        <tr>
                                            <td class="question-cell"><?php echo htmlspecialchars($data['question_text']); ?></td>
                                            <td class="response-cell">
                                                <?php if (count($data['responses']) > 1): ?>
                                                    <ul class="multi-response">
                                                        <?php foreach ($data['responses'] as $response): ?>
                                                            <li><?php echo htmlspecialchars($response); ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php else: ?>
                                                    <?php echo htmlspecialchars($data['responses'][0] ?? 'No response'); ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    
    <!-- Argon Dashboard JS -->
    <script src="argon-dashboard-master/assets/js/core/popper.min.js"></script>
    <script src="argon-dashboard-master/assets/js/core/bootstrap.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/argon-dashboard.min.js"></script>
    
    <script>
    function goBack() {
        // Get the survey_id from the submission to return to the correct survey list
        const surveyId = "<?php echo $submission['survey_id'] ?? ''; ?>";
        
        if (surveyId) {
            window.location.href = `record.php?survey_id=${surveyId}`;
        } else {
            window.history.back();
        }
    }

    function deleteSubmission(submissionId) {
        if (confirm("Are you sure you want to delete this submission? This action cannot be undone.")) {
            fetch(`delete_submission.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `id=${submissionId}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert("Submission deleted successfully.");
                        goBack();
                    } else {
                        alert("Error: " + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert("An error occurred while deleting the submission.");
                });
        }
    }

    // Initialize perfect scrollbar for the response table
    if (document.querySelector('.table-responsive')) {
        new PerfectScrollbar('.table-responsive');
    }
    </script>
</body>
</html>