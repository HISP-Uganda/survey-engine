<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require 'connect.php';

$submissionId = $_GET['submission_id'] ?? null;

if (!$submissionId) {
    die("Submission ID is required.");
}

// Get submission details with names instead of IDs
$stmt = $pdo->prepare("
    SELECT 
        s.id, 
        s.uid, 
        s.age,
        s.sex,
        s.period,
        su.name AS service_unit_name,
        l.name AS location_name,
        o.name AS ownership_name,
        surv.name AS survey_name,
        s.created
    FROM submission s
    LEFT JOIN service_unit su ON s.service_unit_id = su.id
    LEFT JOIN location l ON s.location_id = l.id
    LEFT JOIN owner o ON s.ownership_id = o.id
    LEFT JOIN survey surv ON s.survey_id = surv.id
    WHERE s.id = :submission_id
");
$stmt->execute(['submission_id' => $submissionId]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$submission) {
    die("Submission not found.");
}

// Get responses for this submission with question text
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
$groupedResponses = [];
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Submission</title>
    <!-- Argon Dashboard CSS -->
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
                            <button class="btn btn-sm btn-outline-danger mb-0" onclick="deleteSubmission(<?php echo $submission['id']; ?>)">
                                <i class="fas fa-trash me-1"></i> Delete
                            </button>
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
                        <span class="detail-label">Age:</span>
                        <p class="detail-value"><?php echo $submission['age'] ?? 'N/A'; ?></p>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Sex:</span>
                        <p class="detail-value"><?php echo htmlspecialchars($submission['sex'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Reporting Period:</span>
                        <p class="detail-value"><?php echo htmlspecialchars($submission['period'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Service Unit:</span>
                        <p class="detail-value"><?php echo htmlspecialchars($submission['service_unit_name'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Location:</span>
                        <p class="detail-value"><?php echo htmlspecialchars($submission['location_name'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Ownership:</span>
                        <p class="detail-value"><?php echo htmlspecialchars($submission['ownership_name'] ?? 'N/A'); ?></p>
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

    <?php include 'components/fixednav.php'; ?>
    
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
            fetch(`delete_submission.php?id=${submissionId}`, { method: 'DELETE' })
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