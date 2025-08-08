<?php
// Include the database connection file
require_once 'connect.php';

// Check if the PDO object is available from connect.php
if (!isset($pdo)) {
    error_log("Database connection failed in thank_you_simple.php. Please check connect.php.");
    die("Database connection failed. Please try again later.");
}

// Get the submission UID from URL
$uid = $_GET['uid'] ?? null;

if (!$uid) {
    die("Invalid access. No submission reference found.");
}

// Get basic submission data
$submission = null;
try {
    $stmt = $pdo->prepare("
        SELECT id, location_id, survey_id
        FROM submission
        WHERE uid = ?
    ");
    $stmt->execute([$uid]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching submission details: " . $e->getMessage());
    die("Error retrieving submission details.");
}

// If no submission found with this UID
if (!$submission) {
    die("Submission not found.");
}

$surveyId = $submission['survey_id'];
$facilityId = $submission['location_id'];

// Initialize variables
$facilityName = null;
$surveyName = null;
$surveyType = 'local';

// Fetch facility name if available
if ($facilityId !== null) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM location WHERE id = ?");
        $stmt->execute([$facilityId]);
        $facility = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($facility) {
            $facilityName = $facility['name'];
        }
    } catch (PDOException $e) {
        error_log("Database error fetching facility name: " . $e->getMessage());
    }
}

// Fetch survey information
if ($surveyId) {
    try {
        $stmt = $pdo->prepare("SELECT name, type FROM survey WHERE id = ?");
        $stmt->execute([$surveyId]);
        $surveyInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($surveyInfo) {
            $surveyName = $surveyInfo['name'];
            $surveyType = $surveyInfo['type'] ?? 'local';
        }
    } catch (PDOException $e) {
        error_log("Database error fetching survey name and type: " . $e->getMessage());
    }
}

// Get responses
$responses = [];
$submissionId = $submission['id'];
try {
    $stmt = $pdo->prepare("
        SELECT sr.question_id, sr.response_value, q.label
        FROM submission_response sr
        JOIN question q ON sr.question_id = q.id
        WHERE sr.submission_id = ?
        ORDER BY q.id
    ");
    $stmt->execute([$submissionId]);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching submission responses: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You - Survey Submitted</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f8f9fa;
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }
        
        .thank-you-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 40px;
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .header-section {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .success-icon {
            font-size: 64px;
            color: #28a745;
            margin-bottom: 20px;
        }
        
        h1 {
            color: #28a745;
            font-size: 32px;
            margin-bottom: 20px;
        }
        
        .message {
            font-size: 18px;
            color: #6c757d;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        
        .reference-id {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #28a745;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .reference-id strong {
            font-family: monospace;
            font-size: 20px;
            color: #495057;
        }
        
        .action-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin: 30px 0;
        }
        
        .action-button {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 12px 24px;
            font-size: 16px;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .action-button:hover {
            background-color: #218838;
        }
        
        .action-button.secondary {
            background-color: #6c757d;
        }
        
        .action-button.secondary:hover {
            background-color: #5a6268;
        }
        
        .submission-details {
            display: none;
            margin-top: 30px;
            border-top: 2px solid #dee2e6;
            padding-top: 30px;
        }
        
        .details-section {
            margin-bottom: 30px;
        }
        
        .details-section h3 {
            color: #28a745;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 20px;
            font-size: 18px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        th {
            background-color: #f8f9fa;
            font-weight: 600;
            width: 40%;
            color: #495057;
        }
        
        td {
            color: #6c757d;
        }
        
        .footer-text {
            margin-top: 30px;
            font-size: 14px;
            color: #6c757d;
            text-align: center;
        }
        
        @media print {
            .action-buttons, .footer-text {
                display: none !important;
            }
            
            .submission-details {
                display: block !important;
            }
            
            body {
                background-color: white;
                font-size: 12pt;
            }
            
            .thank-you-container {
                box-shadow: none;
                margin: 0;
                padding: 20px;
            }
            
            .success-icon {
                font-size: 32px;
            }
            
            h1 {
                font-size: 24px;
            }
        }
        
        @media (max-width: 768px) {
            .action-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .thank-you-container {
                padding: 20px;
                margin: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="thank-you-container" id="printableArea">
        <div class="header-section">
            <div class="success-icon">✓</div>
            <h1>Thank You!</h1>
            <div class="message">
                Your survey response has been successfully submitted.<br>
                We appreciate you taking the time to provide your valuable feedback.
            </div>
        </div>
        
        <div class="reference-id">
            <div>Your Reference ID:</div>
            <strong><?php echo htmlspecialchars($uid); ?></strong>
        </div>
        
        <div class="action-buttons">
            <button class="action-button" id="viewDetailsBtn">View Your Responses</button>
            <button class="action-button secondary" onclick="printSummary()">Print Summary</button>
            <button class="action-button secondary" onclick="closeWindow()">Close</button>
        </div>
        
        <div class="submission-details" id="submissionDetails">
            <div class="details-section">
                <h3>Submission Information</h3>
                <table>
                    <tr>
                        <th>Reference ID</th>
                        <td><?php echo htmlspecialchars($uid); ?></td>
                    </tr>
                    <?php if ($surveyName): ?>
                    <tr>
                        <th>Survey Name</th>
                        <td><?php echo htmlspecialchars($surveyName); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($facilityName): ?>
                    <tr>
                        <th>Facility</th>
                        <td><?php echo htmlspecialchars($facilityName); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Submission Date</th>
                        <td><?php echo date('F j, Y g:i A'); ?></td>
                    </tr>
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
                                <td colspan="2" style="text-align: center; color: #6c757d;">No responses recorded</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="footer-text">
            Your responses help us improve our services. Thank you for your participation!
        </div>
    </div>

    <script>
        // Prevent navigation back to survey
        history.pushState(null, null, location.href);
        window.onpopstate = function() {
            history.go(1);
        };
        
        // Disable right-click context menu
        document.addEventListener('contextmenu', function(e) {
            e.preventDefault();
        });
        
        // Disable common keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Disable F5, Ctrl+R (refresh)
            if (e.key === 'F5' || (e.ctrlKey && e.key === 'r')) {
                e.preventDefault();
            }
            // Disable Ctrl+W (close tab)
            if (e.ctrlKey && e.key === 'w') {
                e.preventDefault();
            }
            // Disable Alt+Left (back)
            if (e.altKey && e.key === 'ArrowLeft') {
                e.preventDefault();
            }
            // Disable Backspace (back)
            if (e.key === 'Backspace' && e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') {
                e.preventDefault();
            }
        });
        
        // Toggle submission details
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
        
        // Print summary function
        function printSummary() {
            // Make sure details are visible before printing
            document.getElementById('submissionDetails').style.display = 'block';
            window.print();
        }
        
        function closeWindow() {
            // Try to close the window/tab
            if (window.opener) {
                window.close();
            } else {
                // If can't close, show message
                alert('Please close this tab manually.');
            }
        }
        
        // Remove auto-close functionality for better user control
    </script>
</body>
</html>