<?php
// Include the database connection file
require_once '../admin/connect.php';

// Check if the PDO object is available from connect.php
if (!isset($pdo)) {
    error_log("Database connection failed in tracker_program_success.php. Please check connect.php.");
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
        SELECT id, survey_id, submitted_at, form_data, dhis2_response, tracked_entity_instance
        FROM tracker_submissions
        WHERE uid = ?
    ");
    $stmt->execute([$uid]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching tracker submission details: " . $e->getMessage());
    die("Error retrieving submission details.");
}

// If no submission found with this UID
if (!$submission) {
    die("Submission not found.");
}

$surveyId = $submission['survey_id'];

// Initialize variables
$surveyName = null;
$participantData = null;

// Decode form data if available
if ($submission['form_data']) {
    try {
        $participantData = json_decode($submission['form_data'], true);
    } catch (Exception $e) {
        error_log("Error decoding participant data: " . $e->getMessage());
    }
}

// Fetch survey information
if ($surveyId) {
    try {
        $stmt = $pdo->prepare("SELECT name, dhis2_program_uid FROM survey WHERE id = ?");
        $stmt->execute([$surveyId]);
        $surveyInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($surveyInfo) {
            $surveyName = $surveyInfo['name'];
        }
    } catch (PDOException $e) {
        error_log("Database error fetching survey name: " . $e->getMessage());
    }
}

// Get tracker settings from dedicated tables
$surveySettings = [];
$dynamicImages = [];

try {
    // Load layout settings
    $layoutStmt = $pdo->prepare("
        SELECT layout_type, show_flag_bar, flag_black_color, flag_yellow_color, flag_red_color
        FROM tracker_layout_settings 
        WHERE survey_id = ?
    ");
    $layoutStmt->execute([$surveyId]);
    $layoutSettings = $layoutStmt->fetch(PDO::FETCH_ASSOC);
    
    // Load active images
    $imageStmt = $pdo->prepare("
        SELECT image_order, image_path, image_alt_text, width_px, height_px, position_type
        FROM tracker_images 
        WHERE survey_id = ? AND is_active = 1
        ORDER BY image_order ASC
    ");
    $imageStmt->execute([$surveyId]);
    $dynamicImages = $imageStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Merge layout settings
    if ($layoutSettings) {
        $surveySettings = [
            'layout_type' => $layoutSettings['layout_type'],
            'show_flag_bar' => (bool)$layoutSettings['show_flag_bar'],
            'flag_black_color' => $layoutSettings['flag_black_color'],
            'flag_yellow_color' => $layoutSettings['flag_yellow_color'],
            'flag_red_color' => $layoutSettings['flag_red_color']
        ];
    }
    
} catch (PDOException $e) {
    error_log("Database error fetching tracker settings: " . $e->getMessage());
    $surveySettings = [];
    $dynamicImages = [];
}

// Default settings
$defaultSettings = [
    'title_text' => $surveyName ?? 'DHIS2 Tracker Program',
    'layout_type' => 'horizontal',
    'show_flag_bar' => true,
    'flag_black_color' => '#000000',
    'flag_yellow_color' => '#FCD116', 
    'flag_red_color' => '#D21034'
];

$surveySettings = array_merge($defaultSettings, $surveySettings);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You - Tracker Data Submitted</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f8f9fa;
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }
        
        /* Flag Bar */
        .flag-bar {
            height: 12px;
            display: flex;
            width: 100%;
            margin-bottom: 30px;
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .flag-section {
            flex: 1;
            height: 100%;
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
        
        .title-section {
            margin-bottom: 20px;
        }
        
        .title-section h2 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        
        /* Success Page Dynamic Images Styles */
        .success-dynamic-images-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .success-dynamic-images-container.horizontal {
            flex-direction: row;
        }
        
        .success-dynamic-images-container.vertical {
            flex-direction: column;
        }
        
        .success-dynamic-images-container.center {
            justify-content: center;
        }
        
        .success-dynamic-images-container.left-right {
            justify-content: space-between;
        }
        
        .success-dynamic-image-item {
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            padding: 8px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin: 0 5px;
        }
        
        .success-dynamic-image-item.position-left {
            justify-content: flex-start;
        }
        
        .success-dynamic-image-item.position-center {
            justify-content: center;
        }
        
        .success-dynamic-image-item.position-right {
            justify-content: flex-end;
        }
        
        .success-dynamic-image-item img {
            border-radius: 8px;
            object-fit: contain;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .logo-section {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
        }
        
        .logo-section img {
            height: 60px;
            margin-right: 15px;
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
            text-decoration: none;
        }
        
        .action-button:hover {
            background-color: #218838;
            color: white;
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
            
            .logo-section {
                flex-direction: column;
            }
            
            .logo-section img {
                margin-right: 0;
                margin-bottom: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="thank-you-container" id="printableArea">
        
        
        <div class="header-section">
            <!-- Images above title, centered -->
            <?php if (!empty($dynamicImages)): ?>
                <div class="success-dynamic-images-container center" style="margin-bottom: 20px; justify-content: center;">
                    <?php foreach ($dynamicImages as $image): ?>
                        <div class="success-dynamic-image-item">
                            <img src="/fbs/admin/<?= htmlspecialchars($image['image_path']) ?>" 
                                 alt="<?= htmlspecialchars($image['image_alt_text']) ?>"
                                 style="width: <?= intval($image['width_px']) ?>px; height: <?= intval($image['height_px']) ?>px;"
                                 onerror="this.style.display='none';" 
                                 title="<?= htmlspecialchars($image['image_alt_text']) ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- Title centered -->
            <div class="title-section" style="text-align: center;">
                <h2 style="color: #2c3e50; margin-bottom: 20px;"><?= htmlspecialchars($surveySettings['title_text']) ?></h2>
            </div>
            <!-- Flag Bar inside container -->
        <?php if ($surveySettings['show_flag_bar']): ?>
            <div class="flag-bar">
                <div class="flag-section" style="background-color: <?= htmlspecialchars($surveySettings['flag_black_color']) ?>;"></div>
                <div class="flag-section" style="background-color: <?= htmlspecialchars($surveySettings['flag_yellow_color']) ?>;"></div>
                <div class="flag-section" style="background-color: <?= htmlspecialchars($surveySettings['flag_red_color']) ?>;"></div>
            </div>
        <?php endif; ?>
            
            <div class="success-icon">✓</div>
            <h1>Thank You!</h1>
            <div class="message">
               
                We appreciate you taking the time to provide this important information.
            </div>
        </div>
        
        <div class="reference-id">
            <div>Your Reference ID:</div>
            <strong><?php echo htmlspecialchars($uid); ?></strong>
        </div>
        
        <!-- <div class="action-buttons">
            <button class="action-button" id="viewDetailsBtn">View Submission Details</button>
            <button class="action-button secondary" id="printSummaryBtn">Print Summary</button>
        </div> -->
        
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
                        <th>Program Name</th>
                        <td><?php echo htmlspecialchars($surveyName); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Submission Date</th>
                        <td><?php echo date('F j, Y g:i A', strtotime($submission['submitted_at'])); ?></td>
                    </tr>
                    <tr>
                        <th>Status</th>
                        <td>Successfully submitted to DHIS2</td>
                    </tr>
                </table>
            </div>

            <?php if ($participantData): ?>
            <div class="details-section">
                <h3>Participant Information</h3>
                <table>
                    <?php foreach ($participantData as $key => $value): ?>
                        <?php if (!empty($value)): ?>
                        <tr>
                            <th><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $key))); ?></th>
                            <td><?php echo htmlspecialchars($value); ?></td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="footer-text">
            Your data helps improve healthcare services. Thank you for your participation!
        </div>
    </div>

    <script>
        // Wait for DOM to be fully loaded
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, setting up event listeners...');
            
            // Toggle submission details
            var viewDetailsBtn = document.getElementById('viewDetailsBtn');
            var submissionDetails = document.getElementById('submissionDetails');
            
            console.log('viewDetailsBtn:', viewDetailsBtn);
            console.log('submissionDetails:', submissionDetails);
            
            if (viewDetailsBtn && submissionDetails) {
                viewDetailsBtn.addEventListener('click', function() {
                    console.log('View details button clicked');
                    var computedStyle = window.getComputedStyle(submissionDetails);
                    var isVisible = computedStyle.display !== 'none';
                    
                    console.log('Current display:', computedStyle.display, 'isVisible:', isVisible);
                    
                    if (isVisible) {
                        submissionDetails.style.display = 'none';
                        this.textContent = 'View Submission Details';
                    } else {
                        submissionDetails.style.display = 'block';
                        this.textContent = 'Hide Submission Details';
                    }
                });
            }
            
            // Add event listener for print button
            var printBtn = document.getElementById('printSummaryBtn');
            if (printBtn) {
                printBtn.addEventListener('click', printSummary);
            }
        });
        
        // Prevent navigation back to form
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
        
        // Print summary function
        function printSummary() {
            // Make sure details are visible before printing
            document.getElementById('submissionDetails').style.display = 'block';
            window.print();
        }
    </script>
</body>
</html>