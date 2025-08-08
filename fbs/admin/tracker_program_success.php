<?php
session_start();

require_once 'connect.php';

// Get survey_id from URL
$surveyId = $_GET['survey_id'] ?? null;
if (!$surveyId) {
    die("Survey ID is missing.");
}

// Fetch survey details
$survey = null;
try {
    $surveyStmt = $pdo->prepare("SELECT * FROM survey WHERE id = ?");
    $surveyStmt->execute([$surveyId]);
    $survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error fetching survey: " . $e->getMessage());
}

if (!$survey) {
    die("Survey not found.");
}

// Get latest submission for this survey
$submission = null;
try {
    $submissionStmt = $pdo->prepare("SELECT * FROM tracker_submissions WHERE survey_id = ? ORDER BY submitted_at DESC LIMIT 1");
    $submissionStmt->execute([$surveyId]);
    $submission = $submissionStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Submission details not critical for success page
    $submission = null;
}

// Get survey settings for styling
$surveySettings = [];
try {
    $settingsStmt = $pdo->prepare("SELECT * FROM survey_settings WHERE survey_id = ?");
    $settingsStmt->execute([$surveyId]);
    $surveySettings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $surveySettings = [];
}

// Default settings
$defaultSettings = [
    'title_text' => $survey['name'] ?? 'DHIS2 Tracker Program',
    'show_logo' => true,
    'logo_path' => 'asets/asets/img/loog.jpg',
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
    <title>Submission Complete - <?= htmlspecialchars($surveySettings['title_text']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        /* Flag Bar Styles */
        .flag-bar {
            height: 8px;
            display: flex;
            width: 100%;
        }
        
        .flag-section {
            flex: 1;
            height: 100%;
        }
        
        /* Success Page Styles */
        .success-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 40px 20px;
            text-align: center;
        }
        
        .success-card {
            background: white;
            border-radius: 20px;
            padding: 50px 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .success-icon {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            animation: successPulse 2s ease-in-out infinite;
        }
        
        @keyframes successPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .success-title {
            color: #2c3e50;
            font-weight: 700;
            margin-bottom: 20px;
        }
        
        .success-message {
            color: #6c757d;
            font-size: 1.1rem;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        
        .submission-details {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 25px;
            margin: 30px 0;
            text-align: left;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            font-weight: 600;
            color: #495057;
        }
        
        .detail-value {
            color: #6c757d;
            font-family: monospace;
            font-size: 0.9rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 30px;
        }
        
        .btn-success-page {
            padding: 12px 30px;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .btn-primary-page {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
        }
        
        .btn-primary-page:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
            color: white;
        }
        
        .btn-outline-page {
            border: 2px solid #667eea;
            color: #667eea;
            background: transparent;
        }
        
        .btn-outline-page:hover {
            background: #667eea;
            color: white;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .status-success {
            background: #d4edda;
            color: #155724;
        }
        
        .dhis2-info {
            background: linear-gradient(135deg, #e3f2fd 0%, #f0f4ff 100%);
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .dhis2-icon {
            color: #1976d2;
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .success-container {
                padding: 20px 10px;
            }
            
            .success-card {
                padding: 30px 20px;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .btn-success-page {
                width: 100%;
                max-width: 250px;
            }
        }
    </style>
</head>

<body style="background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); min-height: 100vh;">
    <!-- Flag Bar -->
    <?php if ($surveySettings['show_flag_bar']): ?>
        <div class="flag-bar">
            <div class="flag-section" style="background-color: <?= $surveySettings['flag_black_color'] ?>;"></div>
            <div class="flag-section" style="background-color: <?= $surveySettings['flag_yellow_color'] ?>;"></div>
            <div class="flag-section" style="background-color: <?= $surveySettings['flag_red_color'] ?>;"></div>
        </div>
    <?php endif; ?>

    <div class="success-container">
        <!-- Header -->
        <div class="text-center mb-4">
            <?php if ($surveySettings['show_logo']): ?>
                <img src="<?= htmlspecialchars($surveySettings['logo_path']) ?>" alt="Logo" class="mb-3" style="max-height: 60px;">
            <?php endif; ?>
        </div>

        <!-- Success Card -->
        <div class="success-card">
            <div class="success-icon">
                <i class="fas fa-check fa-3x text-white"></i>
            </div>
            
            <h2 class="success-title">Tracker Program Submission Successful!</h2>
            
            <p class="success-message">
                Your tracker program data has been successfully submitted to DHIS2. 
                The tracked entity instance and all program events have been created in the system.
            </p>
            
            <!-- DHIS2 Integration Info -->
            <div class="dhis2-info">
                <i class="fas fa-database dhis2-icon"></i>
                <h5 class="mb-2">DHIS2 Integration Complete</h5>
                <p class="mb-1">✓ Tracked Entity Instance created</p>
                <p class="mb-1">✓ Program enrollment processed</p>
                <p class="mb-0">✓ All stage events submitted</p>
            </div>
            
            <!-- Submission Details -->
            <?php if ($submission): ?>
                <div class="submission-details">
                    <h5 class="mb-3"><i class="fas fa-info-circle me-2"></i>Submission Details</h5>
                    
                    <div class="detail-row">
                        <span class="detail-label">Program Name:</span>
                        <span class="detail-value"><?= htmlspecialchars($survey['name']) ?></span>
                    </div>
                    
                    <div class="detail-row">
                        <span class="detail-label">DHIS2 Program:</span>
                        <span class="detail-value"><?= htmlspecialchars($survey['dhis2_program_uid']) ?></span>
                    </div>
                    
                    <?php if ($submission['tracked_entity_instance']): ?>
                        <div class="detail-row">
                            <span class="detail-label">Participant ID:</span>
                            <span class="detail-value"><?= htmlspecialchars($submission['tracked_entity_instance']) ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="detail-row">
                        <span class="detail-label">Submitted:</span>
                        <span class="detail-value"><?= date('F j, Y g:i A', strtotime($submission['submitted_at'])) ?></span>
                    </div>
                    
                    <div class="detail-row">
                        <span class="detail-label">Status:</span>
                        <span class="detail-value">
                            <span class="status-badge status-success">
                                <i class="fas fa-check-circle"></i>
                                Successfully Submitted to DHIS2
                            </span>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Thank You Message -->
            <div class="mt-4 p-3 bg-light rounded">
                <p class="mb-0 text-muted">
                    <i class="fas fa-heart text-danger me-2"></i>
                    Thank you for your participation in this tracker program. Your data will help improve health outcomes and program effectiveness.
                </p>
            </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="action-buttons">
            <?php if (isset($_SESSION['admin_logged_in'])): ?>
                <a href="survey.php" class="btn btn-primary-page btn-success-page">
                    <i class="fas fa-list me-2"></i>Back to Surveys
                </a>
                <a href="tracker_program_form.php?survey_id=<?= $surveyId ?>" class="btn btn-outline-page btn-success-page">
                    <i class="fas fa-plus me-2"></i>Submit Another
                </a>
            <?php else: ?>
                <a href="tracker_program_form.php?survey_id=<?= $surveyId ?>" class="btn btn-outline-page btn-success-page">
                    <i class="fas fa-plus me-2"></i>Submit Another Entry
                </a>
                <a href="javascript:window.close()" class="btn btn-primary-page btn-success-page">
                    <i class="fas fa-times me-2"></i>Close
                </a>
            <?php endif; ?>
        </div>
        
        <!-- Additional Information -->
        <div class="mt-4 text-center">
            <small class="text-muted">
                Your data has been securely transmitted to DHIS2 and is now part of the health information system.
                <br>
                If you have any questions, please contact your system administrator.
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Prevent back navigation after submission
        (function() {
            // Replace current history entry to prevent going back
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
            
            // Handle back button attempts
            window.addEventListener('popstate', function(event) {
                // Prevent navigation and show message
                event.preventDefault();
                event.stopPropagation();
                
                // Push current state again to block back navigation
                window.history.pushState(null, null, window.location.href);
                
                // Show alert to user
                alert('Thank you for your submission! For security reasons, you cannot navigate back to the form. Your data has been successfully submitted to DHIS2.');
                
                return false;
            });
            
            // Push an additional state to prevent direct back navigation
            window.history.pushState(null, null, window.location.href);
        })();

        // Auto-close window after 30 seconds if opened as popup
        <?php if (!isset($_SESSION['admin_logged_in'])): ?>
        setTimeout(function() {
            if (window.opener) {
                window.close();
            }
        }, 30000);
        <?php endif; ?>
    </script>
</body>
</html>