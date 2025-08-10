<?php
session_start();

require_once '../admin/connect.php';
require_once '../admin/includes/skip_logic_helper.php';

// Function to show survey status messages with good design
function showSurveyMessage($title, $message, $type = 'info') {
    $iconClass = '';
    $bgClass = '';
    $textClass = '';
    
    switch ($type) {
        case 'warning':
            $iconClass = 'fa-exclamation-triangle text-warning';
            $bgClass = 'bg-warning-subtle';
            $textClass = 'text-warning-emphasis';
            break;
        case 'info':
            $iconClass = 'fa-info-circle text-info';
            $bgClass = 'bg-info-subtle';
            $textClass = 'text-info-emphasis';
            break;
        case 'error':
            $iconClass = 'fa-times-circle text-danger';
            $bgClass = 'bg-danger-subtle';
            $textClass = 'text-danger-emphasis';
            break;
        default:
            $iconClass = 'fa-info-circle text-primary';
            $bgClass = 'bg-primary-subtle';
            $textClass = 'text-primary-emphasis';
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            body {
                background: white;
                min-height: 100vh;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }
            .message-container {
                box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
                border-radius: 20px;
                border: 1px solid rgba(0, 0, 0, 0.1);
            }
            .message-icon {
                font-size: 4rem;
                opacity: 0.8;
            }
        </style>
    </head>
    <body>
        <div class="container d-flex align-items-center justify-content-center min-vh-100">
            <div class="row w-100 justify-content-center">
                <div class="col-md-8 col-lg-6">
                    <div class="card message-container <?= $bgClass ?> border-0">
                        <div class="card-body text-center p-5">
                            <div class="message-icon mb-4">
                                <i class="fas <?= $iconClass ?>"></i>
                            </div>
                            <h2 class="card-title mb-3 <?= $textClass ?>"><?= htmlspecialchars($title) ?></h2>
                            <p class="card-text lead <?= $textClass ?>"><?= htmlspecialchars($message) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Get survey_id from URL
$surveyId = $_GET['survey_id'] ?? null;
if (!$surveyId) {
    die("Survey ID is missing.");
}

// Fetch survey details
$survey = null;
try {
    $surveyStmt = $pdo->prepare("SELECT id, type, name, is_active, dhis2_program_uid, dhis2_instance FROM survey WHERE id = ?");
    $surveyStmt->execute([$surveyId]);
    $survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error fetching survey: " . $e->getMessage());
}

if (!$survey) {
    // Survey not found - show unavailable message
    showSurveyMessage("Survey Currently Unavailable", 
        "This survey is currently unavailable. Please contact the administrator if you believe this is an error.", 
        "warning");
}

// Check if survey is inactive
if ($survey && $survey['is_active'] == 0) {
    showSurveyMessage("Survey Deadline Reached", 
        "The deadline for this survey has reached. Thank you for your time.", 
        "info");
}

// Check if this is a DHIS2 tracker program
if (empty($survey['dhis2_program_uid'])) {
    // Redirect to regular survey form
    header("Location: survey_page.php?survey_id=" . $surveyId);
    exit();
}

// Get DHIS2 configuration
$dhis2Config = null;
try {
    if (!empty($survey['dhis2_instance'])) {
        $stmt = $pdo->prepare("SELECT id, url as base_url, username, password, instance_key, description FROM dhis2_instances WHERE instance_key = ?");
        $stmt->execute([$survey['dhis2_instance']]);
        $dhis2Config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Decode password if it's base64 encoded
        if ($dhis2Config && !empty($dhis2Config['password'])) {
            $decodedPassword = base64_decode($dhis2Config['password']);
            if ($decodedPassword !== false) {
                $dhis2Config['password'] = $decodedPassword;
            }
        }
    }
} catch (Exception $e) {
    error_log("Error fetching DHIS2 config: " . $e->getMessage());
}

if (!$dhis2Config) {
    die("DHIS2 configuration not found for this survey.");
}

// Function to fetch data from DHIS2 API
function fetchFromDHIS2($endpoint, $dhis2Config) {
    $url = rtrim($dhis2Config['base_url'], '/') . '/api/' . ltrim($endpoint, '/');
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode($dhis2Config['username'] . ':' . $dhis2Config['password']),
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("DHIS2 API error: HTTP $httpCode - $response");
        return null;
    }
    
    return json_decode($response, true);
}

// Fetch tracker program structure from DHIS2
$trackerProgram = fetchFromDHIS2("programs/{$survey['dhis2_program_uid']}.json?fields=id,name,description,programType,trackedEntityType,programStages[id,name,description,repeatable,minDaysFromStart,programStageDataElements[dataElement[id,name,displayName,valueType,optionSet[options[code,displayName]]]]],programTrackedEntityAttributes[trackedEntityAttribute[id,name,displayName,valueType,unique,optionSet[options[code,displayName]]],mandatory,displayInList]", $dhis2Config);

// Check if we failed to fetch DHIS2 data (offline scenario)
if (!$trackerProgram) {
    // Create a minimal tracker program structure for offline form
    $trackerProgram = [
        'id' => $survey['dhis2_program_uid'],
        'name' => $survey['name'],
        'description' => 'DHIS2 Tracker Program (Offline Mode)',
        'programType' => 'WITH_REGISTRATION',
        'programTrackedEntityAttributes' => [
            [
                'trackedEntityAttribute' => [
                    'id' => 'offline_attr',
                    'name' => 'Basic Information',
                    'displayName' => 'Basic Information (Offline)',
                    'valueType' => 'TEXT'
                ],
                'mandatory' => false,
                'displayInList' => true
            ]
        ],
        'programStages' => [
            [
                'id' => 'offline_stage',
                'name' => 'Program Stage (Offline)',
                'description' => 'Form is in offline mode - limited functionality',
                'repeatable' => false,
                'programStageDataElements' => []
            ]
        ]
    ];
    
    // Add offline mode indicator
    $offlineMode = true;
} else if ($trackerProgram['programType'] !== 'WITH_REGISTRATION') {
    // Not a tracker program, redirect to regular form
    header("Location: survey_page.php?survey_id=" . $surveyId);
    exit();
} else {
    $offlineMode = false;
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
    'title_text' => $trackerProgram['name'] ?? 'DHIS2 Tracker Program',
    'show_logo' => true,
    'logo_path' => 'asets/asets/img/loog.jpg',
    'show_flag_bar' => true,
    'flag_black_color' => '#000000',
    'flag_yellow_color' => '#FCD116', 
    'flag_red_color' => '#D21034'
];

$surveySettings = array_merge($defaultSettings, $surveySettings);

// Extract program components
$trackedEntityAttributes = $trackerProgram['programTrackedEntityAttributes'] ?? [];
$programStages = $trackerProgram['programStages'] ?? [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($surveySettings['title_text']) ?></title>
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
        
        /* Tracker Form Styles */
        .tracker-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .tei-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .stage-section {
            background: white;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .stage-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 30px;
        }
        
        .stage-body {
            padding: 30px;
        }
        
        /* Centered Stage Navigation */
        .stage-navigation {
            position: static;
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .stage-nav-toggle {
            display: none; /* Hide toggle button since we don't need it anymore */
        }

        /* Remove main content margins */
        .main-content {
            margin-right: 0;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 60vh;
        }

        .stage-nav-header {
            padding: 15px 20px;
            border-bottom: 1px solid #dee2e6;
            background: #f8f9fa;
            border-radius: 15px 0 0 0;
        }

        .stage-nav-item {
            padding: 12px 15px;
            border-bottom: 1px solid #f1f3f4;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
        }

        .stage-nav-item:hover {
            background: #f8f9fa;
            transform: translateX(-2px);
        }

        .stage-nav-item.active {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(0,123,255,0.3);
        }

        .stage-nav-item.active:hover {
            transform: translateX(-2px);
            background: linear-gradient(135deg, #0056b3 0%, #004085 100%);
        }

        .stage-nav-item.completed {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }

        .stage-nav-item.completed:hover {
            background: linear-gradient(135deg, #20c997 0%, #17a2b8 100%);
        }

        .stage-progress {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #dee2e6;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: bold;
            color: #6c757d;
            flex-shrink: 0;
        }

        .stage-nav-item.active .stage-progress {
            background: rgba(255,255,255,0.2);
            color: white;
        }

        .stage-nav-item.completed .stage-progress {
            background: rgba(255,255,255,0.2);
            color: white;
        }

        .stage-nav-item.completed .stage-progress::before {
            content: '✓';
            font-size: 14px;
        }

        .stage-nav-content {
            flex: 1;
        }

        .stage-nav-title {
            font-weight: 600;
            margin-bottom: 2px;
        }

        .stage-nav-subtitle {
            font-size: 12px;
            opacity: 0.8;
        }

        .stage-nav-item.active .stage-nav-subtitle,
        .stage-nav-item.completed .stage-nav-subtitle {
            opacity: 0.9;
        }

        /* Updated Occurrence Tabs with repositioned Add Another and Remove */
        .stage-header {
            position: relative;
        }

        .occurrence-controls {
            position: absolute;
            top: 15px;
            right: 20px;
            display: flex;
            gap: 10px;
        }

        .add-occurrence-btn-fixed, .remove-occurrence-btn-fixed {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        .remove-occurrence-btn-fixed {
            background: #dc3545;
        }

        .add-occurrence-btn-fixed:hover {
            background: #20c997;
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(0,0,0,0.25);
        }

        .remove-occurrence-btn-fixed:hover {
            background: #c82333;
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(0,0,0,0.25);
        }

        .remove-occurrence-btn-fixed:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        .occurrence-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .occurrence-tab {
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid #e9ecef;
            background: #f8f9fa;
        }
        
        .occurrence-tab.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        /* Hide all form sections since we're using modals only */
        .stage-section, .tei-section {
            display: none;
        }
        
        /* Modal Picker Styles */
        .modal-picker {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            padding: 40px;
            max-width: 1200px;
            width: 98%;
            max-height: 95vh;
            overflow-y: auto;
            box-shadow: 0 15px 40px rgba(0,0,0,0.3);
            margin: 10px;
        }

        .btn-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            color: #6c757d;
            cursor: pointer;
        }

        .btn-close:hover {
            color: #dc3545;
        }

        .modal-question-item {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .modal-question-item:hover {
            box-shadow: 0 4px 15px rgba(0,123,255,0.15);
            border-color: #007bff;
            transform: translateY(-1px);
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 18px;
            gap: 15px;
        }

        .question-label {
            font-weight: 600;
            color: #2c3e50;
            margin: 0;
            flex: 1;
            font-size: 18px;
            line-height: 1.4;
        }


        .question-input-container {
            position: relative;
        }

        .question-input-container input,
        .question-input-container select,
        .question-input-container textarea {
            background: #fafbfc;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 15px 18px;
            font-size: 16px;
            transition: all 0.3s ease;
            width: 100%;
            min-height: 50px;
        }

        .question-input-container input:focus,
        .question-input-container select:focus,
        .question-input-container textarea:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,0.25);
            outline: none;
        }

        .question-input-container .form-placeholder {
            position: absolute;
            top: 12px;
            left: 15px;
            color: #6c757d;
            font-size: 14px;
            transition: all 0.3s ease;
            pointer-events: none;
            background: white;
            padding: 0 5px;
        }

        .question-input-container input:focus + .form-placeholder,
        .question-input-container input:not(:placeholder-shown) + .form-placeholder,
        .question-input-container select:focus + .form-placeholder,
        .question-input-container textarea:focus + .form-placeholder {
            top: -8px;
            font-size: 12px;
            color: #007bff;
            font-weight: 600;
        }

        .question-required {
            color: #dc3545;
            font-weight: bold;
            margin-left: 3px;
        }

        .question-help {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
            font-style: italic;
        }

        .modal-questions-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 25px;
        }

        @media (min-width: 992px) {
            .modal-questions-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 30px;
            }
        }

        @media (min-width: 1200px) {
            .modal-questions-grid.single-column {
                grid-template-columns: 1fr;
                max-width: 800px;
                margin: 0 auto;
            }
        }

        .question-options-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }

        .question-option {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .question-option:hover {
            border-color: #007bff;
            background: #f8f9fa;
        }

        .question-option input[type="radio"],
        .question-option input[type="checkbox"] {
            margin-right: 8px;
        }

        /* Modal Grouped Questions Styles */
        .modal-group-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 15px;
            margin-bottom: 30px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border: 1px solid #dee2e6;
        }

        .modal-group-header {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            padding: 20px 25px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .modal-group-title {
            margin: 0;
            font-weight: 700;
            font-size: 20px;
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        .modal-group-content {
            padding: 25px;
            background: white;
        }

        .grouped-questions-container {
            width: 100%;
        }

        .group-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        @media (min-width: 992px) {
            .group-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 25px;
            }
        }

        @media (min-width: 1400px) {
            .group-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 30px;
            }
        }

        /* Legacy grouped questions styles for backward compatibility */
        .form-group-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            border-left: 4px solid #17a2b8;
        }

        .form-group-section .group-title {
            color: #17a2b8;
            font-weight: 600;
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 10px;
        }

        .group-fields {
            margin-top: 15px;
        }

        /* Responsive adjustments */        
        @media (max-width: 768px) {
            .stage-navigation {
                max-width: 100%;
                margin: 0 15px;
            }
            
            .tracker-container {
                padding: 10px;
            }
            
            .modal-content {
                padding: 25px 20px;
                width: 98%;
                margin: 5px;
                max-height: 98vh;
            }
            
            .modal-question-item {
                padding: 20px 15px;
            }
            
            .question-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            
            .question-label {
                font-size: 16px;
            }
            
            .modal-group-header {
                padding: 15px 20px;
            }
            
            .modal-group-title {
                font-size: 18px;
            }
            
            .modal-group-content {
                padding: 20px;
            }
        }
        
        @media (max-width: 576px) {
            .modal-content {
                padding: 20px 15px;
                border-radius: 10px;
            }
            
            .modal-question-item {
                padding: 15px;
                margin-bottom: 20px;
            }
            
            .question-input-container input,
            .question-input-container select,
            .question-input-container textarea {
                font-size: 16px;
                padding: 12px 15px;
            }
            
            .modal-group-header {
                padding: 12px 15px;
            }
            
            .modal-group-title {
                font-size: 16px;
            }
            
            .modal-group-content {
                padding: 15px;
            }
        }

        /* Large screen optimizations */
        @media (min-width: 1400px) {
            .modal-content {
                max-width: 1400px;
            }
            
            .modal-questions-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 35px;
            }
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
            display: block;
        }
        
        .required-asterisk {
            color: #dc3545;
            margin-left: 3px;
        }
        
        .form-control {
            border-radius: 8px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .submit-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-top: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .btn-submit {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            color: white;
            padding: 15px 40px;
            border-radius: 25px;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.3);
            color: white;
        }
        
        .loading-spinner {
            display: none;
            text-align: center;
            padding: 20px;
        }
        
        .spinner-border {
            color: #667eea;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .tracker-container {
                padding: 10px;
            }
            
            .tei-section, .stage-body, .submit-section {
                padding: 20px;
            }
            
            .stage-header {
                padding: 15px 20px;
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

    <div class="tracker-container">
        <!-- Header -->
        <div class="text-center mb-4">
            <?php if ($surveySettings['show_logo']): ?>
                <img src="<?= htmlspecialchars($surveySettings['logo_path']) ?>" alt="Logo" class="mb-3" style="max-height: 80px;">
            <?php endif; ?>
            <h2 class="mb-2" style="color: #2c3e50; font-weight: 700;">
                <?= htmlspecialchars($surveySettings['title_text']) ?>
            </h2>
            <?php if (!empty($trackerProgram['description'])): ?>
                <p class="text-muted"><?= htmlspecialchars($trackerProgram['description']) ?></p>
            <?php endif; ?>
        </div>

        <!-- Offline Mode Warning -->
        <?php if (isset($offlineMode) && $offlineMode): ?>
        <div class="alert alert-warning" style="max-width: 1000px; margin-left: auto; margin-right: auto; margin-bottom: 20px; border-radius: 15px; border-left: 5px solid #ffc107;">
            <div class="d-flex align-items-center">
                <i class="fas fa-wifi-slash fa-2x me-3" style="color: #856404;"></i>
                <div>
                    <h6 class="alert-heading mb-2">Offline Mode - Limited Functionality</h6>
                    <p class="mb-1">This form is running in offline mode because the DHIS2 server is not accessible.</p>
                    <small class="text-muted">
                        • Data submission is disabled • Location loading may be limited • Please check your internet connection
                    </small>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Location Selection Section - Moved to Top -->
        <div class="location-section" id="locationSection" style="background: white; border-radius: 15px; padding: 30px; margin-bottom: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); max-width: 1000px; margin-left: auto; margin-right: auto;">
            <h5 class="mb-3" style="color: #2c3e50; font-weight: 700;">
                <i class="fas fa-map-marker-alt text-primary me-2"></i>
                Select Location
            </h5>
            <div class="row">
                <div class="col-md-8">
                    <div class="form-group" style="position: relative;">
                        <label for="facilitySearch" class="form-label">Search for facility/location <span class="text-danger">*</span></label>
                        <input type="text" 
                               id="facilitySearch" 
                               name="facility_search" 
                               class="form-control" 
                               placeholder="Type to search locations..."
                               autocomplete="off"
                               required>
                        <div id="facilityResults" class="facility-results" style="display: none; position: absolute; z-index: 1000; background: white; border: 1px solid #ddd; border-radius: 8px; max-height: 250px; overflow-y: auto; width: 100%; margin-top: 2px; box-shadow: 0 4px 15px rgba(0,0,0,0.15); border-top: 3px solid #007bff;"></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="form-label">Selected Location Path</label>
                        <div id="selectedLocationPath" class="alert alert-info" style="min-height: 60px; display: flex; align-items: center; font-size: 14px; border-radius: 8px; border-left: 4px solid #0dcaf0;">
                            <i class="fas fa-info-circle me-2" style="font-size: 16px; color: #0dcaf0;"></i>
                            <div>
                                <div style="font-weight: 500; margin-bottom: 2px;">No location selected yet</div>
                                <div style="font-size: 12px; color: #6c757d;">Please search and select a facility above</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Hidden inputs for selected facility data -->
            <input type="hidden" id="facilityId" name="facility_id" required>
            <input type="hidden" id="facilityName" name="facility_name">
            <input type="hidden" id="facilityOrgunitUid" name="facility_orgunit_uid">
            <input type="hidden" id="hierarchyData" name="hierarchy_data">
        </div>

        <!-- Enhanced Stage Navigation - DHIS2 Tracker Style -->
        <div class="stage-navigation" id="stageNavigation" style="max-width: 1000px; margin-left: auto; margin-right: auto;">
            <button class="stage-nav-toggle" onclick="toggleStageNavigation()">
                <i class="fas fa-chevron-right"></i>
            </button>
            <div class="stage-nav-header">
                <h6 class="mb-1 text-center" style="color: #007bff;">
                    <i class="fas fa-list me-2"></i>
                    DHIS2 Tracker Program Stages
                </h6>
                <p class="text-center text-muted mb-0" style="font-size: 14px;">Click on any stage to fill the form</p>
            </div>
            
            <?php if (!empty($trackedEntityAttributes)): ?>
                <div class="stage-nav-item" onclick="navigateToStage('tei-section')" data-stage="tei-section">
                    <div class="stage-progress">1</div>
                    <div class="stage-nav-content">
                        <div class="stage-nav-title">Participant Information</div>
                        <div class="stage-nav-subtitle">Basic registration details</div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php foreach ($programStages as $index => $stage): ?>
                <div class="stage-nav-item" onclick="navigateToStage('<?= $stage['id'] ?>')" data-stage="<?= $stage['id'] ?>">
                    <div class="stage-progress"><?= $index + (empty($trackedEntityAttributes) ? 1 : 2) ?></div>
                    <div class="stage-nav-content">
                        <div class="stage-nav-title">
                            <?= htmlspecialchars($stage['name']) ?>
                            <?php if ($stage['repeatable']): ?>
                                <span class="badge bg-success ms-2" style="font-size: 10px;">
                                    <i class="fas fa-repeat me-1"></i>Repeatable
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="stage-nav-subtitle">
                            <?= count($stage['programStageDataElements']) ?> fields
                            <?php if ($stage['repeatable']): ?>
                                • <span id="occurrenceCount_<?= $stage['id'] ?>">1</span> occurrence(s)
                                <button type="button" class="btn btn-sm btn-outline-success ms-2" onclick="event.stopPropagation(); addStageOccurrence('<?= $stage['id'] ?>')" style="font-size: 10px; padding: 2px 6px;">
                                    <i class="fas fa-plus"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger ms-1" onclick="event.stopPropagation(); removeStageOccurrence('<?= $stage['id'] ?>')" id="removeBtn_<?= $stage['id'] ?>" disabled style="font-size: 10px; padding: 2px 6px;">
                                    <i class="fas fa-minus"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="main-content" id="mainContent">
            <!-- Submit Section -->
            <div class="text-center p-4" style="background: white; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); max-width: 1000px; margin: 30px auto;">
                <?php if (isset($offlineMode) && $offlineMode): ?>
                    <button type="button" class="btn btn-secondary btn-lg" disabled title="Submission is disabled in offline mode">
                        <i class="fas fa-wifi-slash me-2"></i>
                        Submit Disabled (Offline)
                    </button>
                    <div class="mt-2">
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Please check your internet connection to enable data submission
                        </small>
                    </div>
                <?php else: ?>
                    <button type="button" class="btn btn-success btn-lg" onclick="submitAllData()">
                        <i class="fas fa-paper-plane me-2"></i>
                        Submit
                    </button>
                <?php endif; ?>
                <div class="loading-spinner mt-3" id="loadingSpinner" style="display: none;">
                    <div class="spinner-border text-success" role="status">
                        <span class="visually-hidden">Submitting...</span>
                    </div>
                    <p class="mt-2">Submitting data to DHIS2...</p>
                </div>
            </div>
            
            <!-- Hidden form for data collection -->
            <form id="trackerForm" style="display: none;">
                <input type="hidden" id="surveyId" value="<?= $surveyId ?>">
                <input type="hidden" id="programId" value="<?= htmlspecialchars($trackerProgram['id']) ?>">
            </form>
        </div>
    </div>

    <!-- Stage Questions Modal -->
    <div class="modal-picker" id="stageQuestionsModal">
        <div class="modal-content">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="mb-0" id="modalStageTitle">
                    <i class="fas fa-clipboard-list text-primary me-2"></i>
                    Stage Questions
                </h5>
                <button type="button" class="btn-close" onclick="closeStageModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <!-- Stage Description -->
            <div class="alert alert-info" id="modalStageDescription" style="display: none;">
                <i class="fas fa-info-circle me-2"></i>
                <span id="stageDescriptionText"></span>
            </div>

            <!-- Event Date -->
            <div class="mb-4">
                <label class="form-label">
                    <i class="fas fa-calendar-alt text-primary me-2"></i>
                    Visit/Event Date
                    <span class="text-danger">*</span>
                </label>
                <input type="date" id="modalEventDate" class="form-control" required 
                       placeholder="Select the date for this visit/event">
            </div>

            <!-- Questions Form -->
            <div id="modalQuestionsContainer" style="max-height: 500px; overflow-y: auto;">
                <div class="text-center py-4" id="modalQuestionsLoading">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div class="mt-2">Loading questions...</div>
                </div>
            </div>

            <!-- Modal Actions -->
            <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
               
                <div>
                    <button type="button" class="btn btn-outline-secondary me-2" onclick="closeStageModal()">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-success" onclick="saveStageData()">
                        <i class="fas fa-save me-1"></i> Save & Continue
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden data for JavaScript -->
    <script type="application/json" id="programData">
        <?= json_encode([
            'program' => $trackerProgram,
            'surveySettings' => $surveySettings
        ]) ?>
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global variables
        let programData;
        let formData = {
            trackedEntityInstance: null,
            trackedEntityAttributes: {},
            events: {}
        };
        let stageOccurrences = {};
        let stageData = {}; // Store independent form data for each stage occurrence
        
        // Initialize the form
        document.addEventListener('DOMContentLoaded', function() {
            programData = JSON.parse(document.getElementById('programData').textContent);
            console.log('Program data loaded:', programData);
            
            // Initialize stage occurrences and data storage
            programData.program.programStages.forEach(stage => {
                stageOccurrences[stage.id] = 1;
                stageData[stage.id] = {}; // Initialize empty data for each stage
            });
            
            // Initialize location selection
            initializeLocationSelection();
        });

        // Location selection functionality
        let currentFilteredLocations = [];

        async function initializeLocationSelection() {
            const facilitySearchInput = document.getElementById('facilitySearch');
            const facilityResultsDiv = document.getElementById('facilityResults');
            const facilityIdInput = document.getElementById('facilityId');
            
            if (!facilitySearchInput) return;
            
            // Load locations for this survey
            await fetchLocationsForSurveyPage();
            
            // Add search functionality
            facilitySearchInput.addEventListener('input', function() {
                const searchTerm = this.value.trim();
                searchAndDisplayFacilities(searchTerm);
            });
            
            // Hide results when clicking outside
            document.addEventListener('click', function(event) {
                if (!facilitySearchInput.contains(event.target) && !facilityResultsDiv.contains(event.target)) {
                    facilityResultsDiv.style.display = 'none';
                }
            });
        }

        async function fetchLocationsForSurveyPage() {
            const surveyId = document.getElementById('surveyId').value;
            const facilitySearchInput = document.getElementById('facilitySearch');
            const facilityResultsDiv = document.getElementById('facilityResults');
            const facilityIdInput = document.getElementById('facilityId');

            try {
                const response = await fetch(`/fbs/admin/get_locations.php?survey_id=${surveyId}`);
                const data = await response.json();

                if (data.error) {
                    throw new Error(`Server Error: ${data.error}`);
                }
                if (Array.isArray(data) && data.length > 0) {
                    currentFilteredLocations = data;
                    
                    if (facilitySearchInput) {
                        facilitySearchInput.disabled = false;
                        facilitySearchInput.placeholder = "Type to search locations...";
                        facilitySearchInput.setAttribute('required', 'required');
                        facilityIdInput.setAttribute('required', 'required');
                    }
                } else {
                    currentFilteredLocations = [];
                    if (facilityResultsDiv) {
                        facilityResultsDiv.innerHTML = '<div style="padding: 15px; color: #666; text-align: center; background: #f8f9fa; border-radius: 8px; margin: 10px 0; border-left: 4px solid #ffc107;"><i class="fas fa-info-circle me-2"></i>No locations available. Please configure location filters in the admin panel.</div>';
                        facilityResultsDiv.style.display = 'block';
                    }
                    if (facilitySearchInput) facilitySearchInput.removeAttribute('required');
                    if (facilityIdInput) facilityIdInput.removeAttribute('required');
                    return;
                }
            } catch (error) {
                console.error('Error loading locations:', error);
                currentFilteredLocations = [];
                
                if (facilityResultsDiv) {
                    facilityResultsDiv.innerHTML = '<div style="padding: 15px; color: #d63384; text-align: center; background: #f8d7da; border-radius: 8px; margin: 10px 0; border-left: 4px solid #dc3545;"><i class="fas fa-exclamation-triangle me-2"></i>Error loading locations. Please try refreshing the page.</div>';
                    facilityResultsDiv.style.display = 'block';
                }
                
                if (facilitySearchInput) {
                    facilitySearchInput.disabled = true;
                    facilitySearchInput.placeholder = "Error loading locations.";
                    facilitySearchInput.removeAttribute('required');
                    facilityIdInput.removeAttribute('required');
                }
            }
        }

        function searchAndDisplayFacilities(searchTerm) {
            const facilityResultsDiv = document.getElementById('facilityResults');
            
            if (!searchTerm || searchTerm.length < 2) {
                facilityResultsDiv.style.display = 'none';
                return;
            }

            const matchingFacilities = currentFilteredLocations.filter(facility => 
                facility.name.toLowerCase().includes(searchTerm.toLowerCase())
            ).slice(0, 10); // Limit to 10 results

            if (matchingFacilities.length > 0) {
                let resultsHtml = '';
                matchingFacilities.forEach(facility => {
                    resultsHtml += `
                        <div class="facility-item" 
                             style="padding: 12px 15px; cursor: pointer; border-bottom: 1px solid #eee; transition: background-color 0.2s; word-wrap: break-word; overflow-wrap: break-word;"
                             onmouseover="this.style.backgroundColor='#f8f9fa'"
                             onmouseout="this.style.backgroundColor='transparent'"
                             onclick="selectFacility('${facility.id}', '${facility.name.replace(/'/g, "\\'")}', '${facility.uid || ''}', '${facility.path || ''}')">
                            <div style="font-weight: 600; color: #2c3e50; margin-bottom: 4px; word-wrap: break-word;">${facility.name}</div>
                            <div id="facility-path-${facility.id}" style="font-size: 12px; color: #666; line-height: 1.3; word-wrap: break-word;">Loading path...</div>
                        </div>
                    `;
                });
                
                facilityResultsDiv.innerHTML = resultsHtml;
                
                // Load human-readable paths for each facility
                matchingFacilities.forEach(async (facility) => {
                    try {
                        const response = await fetch(`/fbs/admin/get_location_path.php?id=${facility.id}`);
                        const data = await response.json();
                        const pathElement = document.getElementById(`facility-path-${facility.id}`);
                        if (pathElement) {
                            if (data.path && data.path.trim()) {
                                pathElement.textContent = data.path;
                            } else {
                                pathElement.textContent = 'No path available';
                                pathElement.style.fontStyle = 'italic';
                            }
                        }
                    } catch (error) {
                        console.error(`Error loading path for facility ${facility.id}:`, error);
                        const pathElement = document.getElementById(`facility-path-${facility.id}`);
                        if (pathElement) {
                            pathElement.textContent = 'Path unavailable';
                            pathElement.style.fontStyle = 'italic';
                        }
                    }
                });
                
                facilityResultsDiv.style.display = 'block';
            } else {
                if (searchTerm.length > 0) {
                    facilityResultsDiv.innerHTML = '<div style="padding: 8px; color: #888;">No matching locations found for your search.</div>';
                } else {
                    facilityResultsDiv.innerHTML = '<div style="padding: 8px; color: #888;">No locations available for selected filters.</div>';
                }
                facilityResultsDiv.style.display = 'block';
            }
        }

        async function selectFacility(facilityId, facilityName, orgunitUid, facilityPath) {
            document.getElementById('facilitySearch').value = facilityName;
            document.getElementById('facilityId').value = facilityId;
            document.getElementById('facilityName').value = facilityName;
            document.getElementById('facilityOrgunitUid').value = orgunitUid;
            document.getElementById('hierarchyData').value = facilityPath;
            
            // Update selected location path display with loading state
            const pathDisplay = document.getElementById('selectedLocationPath');
            pathDisplay.innerHTML = `
                <i class="fas fa-check-circle text-success me-2"></i>
                <div>
                    <div style="font-weight: 600; color: #155724; margin-bottom: 4px;">${facilityName}</div>
                    <div style="font-size: 13px; color: #6c757d;">
                        <i class="fas fa-spinner fa-spin me-1"></i>Loading location path...
                    </div>
                </div>
            `;
            pathDisplay.className = 'alert alert-success';
            
            // Load human-readable path
            try {
                const response = await fetch(`/fbs/admin/get_location_path.php?id=${facilityId}`);
                const data = await response.json();
                
                pathDisplay.innerHTML = `
                    <i class="fas fa-map-marker-alt text-success me-2"></i>
                    <div>
                        <div style="font-weight: 600; color: #155724; margin-bottom: 6px;">${facilityName}</div>
                        <div style="font-size: 13px; color: #6c757d; line-height: 1.4;">
                            <i class="fas fa-route me-1"></i>${data.path || 'No hierarchy path available'}
                        </div>
                    </div>
                `;
                pathDisplay.className = 'alert alert-success';
            } catch (error) {
                console.error('Error loading selected location path:', error);
                pathDisplay.innerHTML = `
                    <i class="fas fa-map-marker-alt text-success me-2"></i>
                    <div>
                        <div style="font-weight: 600; color: #155724; margin-bottom: 6px;">${facilityName}</div>
                        <div style="font-size: 13px; color: #dc3545; font-style: italic;">
                            <i class="fas fa-exclamation-triangle me-1"></i>Hierarchy path could not be loaded
                        </div>
                    </div>
                `;
                pathDisplay.className = 'alert alert-success';
            }
            
            // Hide results
            document.getElementById('facilityResults').style.display = 'none';
            
            console.log('Selected facility:', { facilityId, facilityName, orgunitUid, facilityPath });
        }

        // Add new stage occurrence
        function addStageOccurrence(stageId) {
            const currentCount = stageOccurrences[stageId];
            const newCount = currentCount + 1;
            stageOccurrences[stageId] = newCount;
            
            // Update occurrence count display
            const countElement = document.getElementById(`occurrenceCount_${stageId}`);
            if (countElement) {
                countElement.textContent = newCount;
            }
            
            // Enable remove button
            const removeBtn = document.getElementById(`removeBtn_${stageId}`);
            if (removeBtn) {
                removeBtn.disabled = false;
            }
            
            console.log(`Added occurrence for stage ${stageId}. New count: ${newCount}`);
        }

        // Remove stage occurrence
        function removeStageOccurrence(stageId) {
            const currentCount = stageOccurrences[stageId];
            
            if (currentCount <= 1) {
                console.log('Cannot remove the last occurrence');
                return;
            }
            
            const newCount = currentCount - 1;
            stageOccurrences[stageId] = newCount;
            
            // Remove data for the last occurrence
            const lastOccurrenceKey = `${stageId}_${currentCount}`;
            if (stageData[stageId] && stageData[stageId][lastOccurrenceKey]) {
                delete stageData[stageId][lastOccurrenceKey];
            }
            
            // Update occurrence count display
            const countElement = document.getElementById(`occurrenceCount_${stageId}`);
            if (countElement) {
                countElement.textContent = newCount;
            }
            
            // Disable remove button if only one occurrence left
            if (newCount <= 1) {
                const removeBtn = document.getElementById(`removeBtn_${stageId}`);
                if (removeBtn) {
                    removeBtn.disabled = true;
                }
            }
            
            console.log(`Removed occurrence for stage ${stageId}. New count: ${newCount}`);
        }
        
        function addOccurrence(stageId) {
            const stage = programData.program.programStages.find(s => s.id === stageId);
            if (!stage.repeatable) return;
            
            const currentCount = stageOccurrences[stageId];
            const newOccurrence = currentCount + 1;
            stageOccurrences[stageId] = newOccurrence;
            
            // Add new tab
            const tabsContainer = document.getElementById(`occurrenceTabs_${stageId}`);
            const newTab = document.createElement('div');
            newTab.className = 'occurrence-tab';
            newTab.onclick = () => switchOccurrence(stageId, newOccurrence);
            newTab.textContent = `${stage.name} ${newOccurrence}`;
            tabsContainer.insertBefore(newTab, tabsContainer.lastElementChild);
            
            // Clone data elements section
            const originalSection = document.getElementById(`dataElements_${stageId}_1`);
            const newSection = originalSection.cloneNode(true);
            newSection.id = `dataElements_${stageId}_${newOccurrence}`;
            newSection.style.display = 'none';
            
            // Update IDs and clear values
            newSection.querySelectorAll('.stage-data-element').forEach(element => {
                const oldId = element.id;
                const newId = oldId.replace(/_1$/, `_${newOccurrence}`);
                element.id = newId;
                element.setAttribute('data-occurrence', newOccurrence);
                element.value = '';
                
                // Update associated label
                const label = newSection.querySelector(`label[for="${oldId}"]`);
                if (label) {
                    label.setAttribute('for', newId);
                }
            });
            
            // Add event date for new occurrence
            const eventDate = document.createElement('input');
            eventDate.type = 'date';
            eventDate.className = 'form-control event-date';
            eventDate.setAttribute('data-stage-id', stageId);
            eventDate.setAttribute('data-occurrence', newOccurrence);
            eventDate.value = new Date().toISOString().split('T')[0];
            eventDate.required = true;
            eventDate.style.display = 'none';
            
            originalSection.parentNode.insertBefore(newSection, originalSection.nextSibling);
            originalSection.parentNode.insertBefore(eventDate, newSection);
            
            // Switch to new occurrence
            switchOccurrence(stageId, newOccurrence);
        }
        
        function switchOccurrence(stageId, occurrence) {
            // Update tabs
            document.querySelectorAll(`[id^="occurrenceTabs_${stageId}"] .occurrence-tab`).forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Show/hide data elements sections
            const stageSection = document.querySelector(`[data-stage-id="${stageId}"]`);
            stageSection.querySelectorAll('.stage-data-elements').forEach(section => {
                section.style.display = 'none';
            });
            
            const targetSection = document.getElementById(`dataElements_${stageId}_${occurrence}`);
            if (targetSection) {
                targetSection.style.display = 'block';
            }
            
            // Show/hide event dates
            stageSection.querySelectorAll('.event-date').forEach(input => {
                input.style.display = input.getAttribute('data-occurrence') == occurrence ? 'block' : 'none';
            });
        }
        
        async function handleSubmit(event) {
            event.preventDefault();
            
            document.getElementById('loadingSpinner').style.display = 'block';
            document.querySelector('.btn-submit').style.display = 'none';
            
            try {
                // Collect TEI attributes
                const teaInputs = document.querySelectorAll('[name^="tea_"]');
                teaInputs.forEach(input => {
                    const teaId = input.name.replace('tea_', '');
                    if (input.value) {
                        formData.trackedEntityAttributes[teaId] = input.value;
                    }
                });
                
                // Collect events data
                programData.program.programStages.forEach(stage => {
                    const occurrenceCount = stageOccurrences[stage.id];
                    
                    for (let i = 1; i <= occurrenceCount; i++) {
                        const eventKey = `${stage.id}_${i}`;
                        const eventDate = document.querySelector(`[data-stage-id="${stage.id}"][data-occurrence="${i}"].event-date`).value;
                        
                        formData.events[eventKey] = {
                            programStage: stage.id,
                            eventDate: eventDate,
                            dataValues: {}
                        };
                        
                        // Collect data elements for this occurrence
                        const dataElements = document.querySelectorAll(`[data-stage-id="${stage.id}"][data-occurrence="${i}"].stage-data-element`);
                        dataElements.forEach(element => {
                            if (element.value) {
                                const deId = element.getAttribute('data-de-id');
                                formData.events[eventKey].dataValues[deId] = element.value;
                            }
                        });
                    }
                });
                
                console.log('Form data collected:', formData);
                
                // Submit to backend
                const response = await fetch('tracker_program_submit.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        survey_id: document.getElementById('surveyId').value,
                        form_data: formData
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Data submitted successfully to DHIS2!');
                    window.location.href = '/tracker-success/' + document.getElementById('surveyId').value;
                } else {
                    alert('Error submitting data: ' + result.message);
                    document.getElementById('loadingSpinner').style.display = 'none';
                    document.querySelector('.btn-submit').style.display = 'inline-block';
                }
                
            } catch (error) {
                console.error('Submission error:', error);
                alert('Error submitting form. Please try again.');
                document.getElementById('loadingSpinner').style.display = 'none';
                document.querySelector('.btn-submit').style.display = 'inline-block';
            }
        }

        // Enhanced Stage Navigation Functionality
        function toggleStageNavigation() {
            const nav = document.getElementById('stageNavigation');
            const mainContent = document.getElementById('mainContent');
            const toggleIcon = nav.querySelector('.stage-nav-toggle i');
            
            nav.classList.toggle('collapsed');
            mainContent.classList.toggle('nav-collapsed');
            
            // Update toggle icon
            if (nav.classList.contains('collapsed')) {
                toggleIcon.className = 'fas fa-chevron-left';
            } else {
                toggleIcon.className = 'fas fa-chevron-right';
            }
        }

        function navigateToStage(stageId) {
            // Open the stage modal instead of navigating directly
            if (stageId === 'tei-section') {
                openTEIModal();
            } else {
                openStageModal(stageId);
            }

            // Update navigation active state
            document.querySelectorAll('.stage-nav-item').forEach(item => {
                item.classList.remove('active');
            });

            const navItem = document.querySelector(`[data-stage="${stageId}"]`);
            if (navItem) {
                navItem.classList.add('active');
            }

            // Auto-collapse navigation on mobile
            if (window.innerWidth < 992) {
                const nav = document.getElementById('stageNavigation');
                if (!nav.classList.contains('collapsed')) {
                    toggleStageNavigation();
                }
            }
        }

        // Global variable to track current modal stage
        let currentModalStage = null;

        function openStageModal(stageId) {
            currentModalStage = stageId;
            const stage = programData.program.programStages.find(s => s.id === stageId);
            if (!stage) return;

            const modal = document.getElementById('stageQuestionsModal');
            
            // For repeatable stages, let user choose which occurrence to fill
            if (stage.repeatable && stageOccurrences[stageId] > 1) {
                showOccurrenceSelector(stageId, stage);
            } else {
                showStageForm(stageId, stage, 1);
            }

            // Show modal
            modal.style.display = 'flex';
        }

        function showOccurrenceSelector(stageId, stage) {
            const modal = document.getElementById('stageQuestionsModal');
            const modalContent = modal.querySelector('.modal-content');
            
            modalContent.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="mb-0">
                        <i class="fas fa-clipboard-list text-primary me-2"></i>
                        ${stage.name} - Select Occurrence
                    </h5>
                    <button type="button" class="btn-close" onclick="closeStageModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    This stage is repeatable and has ${stageOccurrences[stageId]} occurrence(s). Select which one you want to fill or review:
                </div>
                
                <div class="row g-3">
                    ${Array.from({length: stageOccurrences[stageId]}, (_, i) => {
                        const occurrenceNum = i + 1;
                        const hasData = stageData[stageId] && stageData[stageId][`${stageId}_${occurrenceNum}`];
                        return `
                            <div class="col-md-6">
                                <div class="card h-100 occurrence-selector ${hasData ? 'border-success' : ''}" 
                                     onclick="showStageForm('${stageId}', null, ${occurrenceNum})" 
                                     style="cursor: pointer; transition: all 0.3s;">
                                    <div class="card-body text-center">
                                        <i class="fas fa-calendar-alt text-primary mb-2" style="font-size: 2rem;"></i>
                                        <h6>${stage.name} ${occurrenceNum}</h6>
                                        <p class="text-muted mb-0">
                                            ${hasData ? 
                                                '<i class="fas fa-check-circle text-success me-1"></i>Data saved' : 
                                                '<i class="fas fa-circle text-muted me-1"></i>Not filled'
                                            }
                                        </p>
                                    </div>
                                </div>
                            </div>
                        `;
                    }).join('')}
                </div>
                
                <div class="text-center mt-4">
                    <button type="button" class="btn btn-secondary" onclick="closeStageModal()">
                        <i class="fas fa-times me-1"></i> Close
                    </button>
                </div>
            `;
        }

        async function showStageForm(stageId, stage, occurrenceNum) {
            if (!stage) {
                stage = programData.program.programStages.find(s => s.id === stageId);
            }
            
            const modal = document.getElementById('stageQuestionsModal');
            const modalContent = modal.querySelector('.modal-content');
            
            modalContent.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-5">
                    <h4 class="mb-0" id="modalStageTitle" style="color: #2c3e50; font-weight: 700;">
                        <i class="fas fa-clipboard-list text-primary me-3"></i>
                        ${stage.name} ${stage.repeatable ? `- Occurrence ${occurrenceNum}` : ''}
                        ${stage.repeatable ? '<span class="badge bg-success ms-3"><i class="fas fa-repeat me-1"></i>Repeatable</span>' : ''}
                    </h4>
                    <button type="button" class="btn-close btn-lg" onclick="closeStageModal()" style="font-size: 1.5rem; padding: 10px;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                ${stage.description ? `
                    <div class="alert alert-info" id="modalStageDescription">
                        <i class="fas fa-info-circle me-2"></i>
                        <span id="stageDescriptionText">${stage.description}</span>
                    </div>
                ` : ''}
                
                <div class="mb-5">
                    <label class="form-label" style="font-size: 18px; font-weight: 600; color: #2c3e50;">
                        <i class="fas fa-calendar-alt text-primary me-3"></i>
                        Visit/Event Date
                        <span class="text-danger">*</span>
                    </label>
                    <input type="date" id="modalEventDate" class="form-control" required 
                           style="font-size: 16px; padding: 15px 18px; border-radius: 10px; border: 2px solid #e9ecef; min-height: 50px;">
                </div>
                
                <div id="modalQuestionsContainer" style="max-height: 600px; overflow-y: auto; padding-right: 10px;">
                    <div class="modal-questions-grid"></div>
                </div>
                
                <div class="d-flex justify-content-between align-items-center mt-5 pt-4 border-top">
                    <div class="flex-grow-1">
                        <p class="text-muted mb-0">
                            <i class="fas fa-lightbulb me-2 text-warning"></i>
                            <strong>Tip:</strong> You can change question types by clicking on the field type selector
                        </p>
                    </div>
                    <div class="d-flex gap-3">
                        ${stage.repeatable && stageOccurrences[stageId] > 1 ? `
                            <button type="button" class="btn btn-outline-info btn-lg px-4" onclick="openStageModal('${stageId}')">
                                <i class="fas fa-arrow-left me-2"></i> Back to List
                            </button>
                        ` : ''}
                        <button type="button" class="btn btn-outline-secondary btn-lg px-4" onclick="closeStageModal()">
                            <i class="fas fa-times me-2"></i> Cancel
                        </button>
                        <button type="button" class="btn btn-success btn-lg px-5" onclick="saveStageData('${stageId}', ${occurrenceNum})">
                            <i class="fas fa-save me-2"></i> Save & Continue
                        </button>
                    </div>
                </div>
            `;
            
            // Set today's date as default
            const eventDateInput = modal.querySelector('#modalEventDate');
            eventDateInput.value = new Date().toISOString().split('T')[0];

            // Load stage questions
            const container = modal.querySelector('#modalQuestionsContainer');
            await loadStageQuestions(stageId, container);
            
            // Load existing data if available
            loadExistingData(stageId, occurrenceNum);
        }

        async function openTEIModal() {
            const modal = document.getElementById('stageQuestionsModal');
            const modalContent = modal.querySelector('.modal-content');
            
            modalContent.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-5">
                    <h4 class="mb-0" style="color: #2c3e50; font-weight: 700;">
                        <i class="fas fa-user-circle text-primary me-3"></i>
                        Participant Information
                    </h4>
                    <button type="button" class="btn-close btn-lg" onclick="closeStageModal()" style="font-size: 1.5rem; padding: 10px;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Please provide the following information about the participant. This information will remain constant throughout the program.
                </div>
                
                <div id="modalQuestionsContainer" style="max-height: 600px; overflow-y: auto; padding-right: 10px;">
                    <div class="modal-questions-grid"></div>
                </div>
                
                <div class="d-flex justify-content-between align-items-center mt-5 pt-4 border-top">
                    <div class="flex-grow-1">
                        <p class="text-muted mb-0">
                            <i class="fas fa-lightbulb me-2 text-warning"></i>
                            <strong>Tip:</strong> You can change question types by clicking on the field type selector
                        </p>
                    </div>
                    <div class="d-flex gap-3">
                        <button type="button" class="btn btn-outline-secondary btn-lg px-4" onclick="closeStageModal()">
                            <i class="fas fa-times me-2"></i> Cancel
                        </button>
                        <button type="button" class="btn btn-success btn-lg px-5" onclick="saveTEIData()">
                            <i class="fas fa-save me-2"></i> Save & Continue
                        </button>
                    </div>
                </div>
            `;
            
            // Load TEI attributes
            const container = modal.querySelector('#modalQuestionsContainer');
            await loadTEIAttributes(container);
            
            // Load existing TEI data if available
            loadExistingTEIData();
            
            // Show modal
            modal.style.display = 'flex';
        }

        async function loadTEIAttributes(container) {
            if (!programData.program.programTrackedEntityAttributes || programData.program.programTrackedEntityAttributes.length === 0) {
                const grid = container.querySelector('.modal-questions-grid');
                grid.innerHTML = '<p class="text-center text-muted">No participant information fields configured.</p>';
                return;
            }

            // Load groupings from database for TEI section
            const groupingData = await loadGroupingsFromDatabase('tei-section');

            // If we have groupings for TEI, apply them
            if (groupingData && groupingData.length > 0) {
                loadGroupedTEIAttributes(container, groupingData);
            } else {
                loadUngroupedTEIAttributes(container);
            }
        }

        function loadUngroupedTEIAttributes(container) {
            const grid = container.querySelector('.modal-questions-grid');
            
            programData.program.programTrackedEntityAttributes.forEach((teaConfig, index) => {
                const tea = teaConfig.trackedEntityAttribute;
                const questionItem = createTEIQuestionItem(tea, teaConfig, index);
                grid.appendChild(questionItem);
            });
        }

        function loadGroupedTEIAttributes(container, groupingData) {
            container.innerHTML = '<div class="grouped-questions-container"></div>';
            const mainContainer = container.querySelector('.grouped-questions-container');

            // Create groups first
            groupingData.forEach(group => {
                if (group.questions && group.questions.length > 0) {
                    const groupDiv = document.createElement('div');
                    groupDiv.className = 'modal-group-section';
                    groupDiv.innerHTML = `
                        <div class="modal-group-header">
                            <h5 class="modal-group-title">
                                <i class="fas fa-folder-open text-info me-3"></i>
                                ${group.groupTitle}
                            </h5>
                        </div>
                        <div class="modal-group-content">
                            <div class="modal-questions-grid group-grid"></div>
                        </div>
                    `;

                    const groupGrid = groupDiv.querySelector('.group-grid');
                    
                    // Add attributes to this group
                    group.questions.forEach(questionRef => {
                        const teaConfig = programData.program.programTrackedEntityAttributes.find(tea => 
                            tea.trackedEntityAttribute.id === questionRef.questionId
                        );
                        
                        if (teaConfig) {
                            const index = programData.program.programTrackedEntityAttributes.indexOf(teaConfig);
                            const questionItem = createTEIQuestionItem(teaConfig.trackedEntityAttribute, teaConfig, index);
                            groupGrid.appendChild(questionItem);
                        }
                    });

                    if (groupGrid.children.length > 0) {
                        mainContainer.appendChild(groupDiv);
                    }
                }
            });

            // Add ungrouped attributes if any
            const groupedAttributeIds = new Set();
            groupingData.forEach(group => {
                if (group.questions) {
                    group.questions.forEach(q => groupedAttributeIds.add(q.questionId));
                }
            });

            const ungroupedAttributes = programData.program.programTrackedEntityAttributes.filter(teaConfig => 
                !groupedAttributeIds.has(teaConfig.trackedEntityAttribute.id)
            );

            if (ungroupedAttributes.length > 0) {
                const ungroupedDiv = document.createElement('div');
                ungroupedDiv.className = 'modal-group-section';
                ungroupedDiv.innerHTML = `
                    <div class="modal-group-header">
                        <h5 class="modal-group-title">
                            <i class="fas fa-list text-secondary me-3"></i>
                            Other Information
                        </h5>
                    </div>
                    <div class="modal-group-content">
                        <div class="modal-questions-grid group-grid"></div>
                    </div>
                `;

                const ungroupedGrid = ungroupedDiv.querySelector('.group-grid');
                
                ungroupedAttributes.forEach((teaConfig, index) => {
                    const questionItem = createTEIQuestionItem(teaConfig.trackedEntityAttribute, teaConfig, index);
                    ungroupedGrid.appendChild(questionItem);
                });

                mainContainer.appendChild(ungroupedDiv);
            }
        }

        function createTEIQuestionItem(attribute, config, index) {
            const div = document.createElement('div');
            div.className = 'modal-question-item';
            
            // Clean the label by removing prefixes
            let cleanLabel = attribute.name;
            cleanLabel = cleanLabel.replace(/^[A-Z]+_/, '');

            const inputId = `tei_${attribute.id}_${index}`;
            
            div.innerHTML = `
                <div class="question-header">
                    <h6 class="question-label">
                        ${cleanLabel}
                        ${config.mandatory ? '<span class="question-required">*</span>' : ''}
                    </h6>
                </div>
                <div class="question-input-container" id="input_container_${inputId}">
                    ${createTEIQuestionInput(attribute, inputId, cleanLabel)}
                </div>
                <div class="question-help">
                    ${getTEIQuestionHelp(attribute.valueType)}
                </div>
            `;

            return div;
        }

        function createTEIQuestionInput(attribute, inputId, label) {
            const placeholder = getTEIPlaceholderText(attribute.valueType, label);
            const isRequired = attribute.mandatory || false;
            
            if (attribute.valueType === 'TEXT') {
                return `<input type="text" id="${inputId}" name="${inputId}" class="form-control" placeholder="${placeholder}" ${isRequired ? 'required' : ''}>`;
            } else if (['NUMBER', 'INTEGER'].includes(attribute.valueType)) {
                return `<input type="number" id="${inputId}" name="${inputId}" class="form-control" placeholder="${placeholder}" ${isRequired ? 'required' : ''}>`;
            } else if (attribute.valueType === 'DATE') {
                return `<input type="date" id="${inputId}" name="${inputId}" class="form-control" ${isRequired ? 'required' : ''}>`;
            } else if (attribute.valueType === 'LONG_TEXT') {
                return `<textarea id="${inputId}" name="${inputId}" class="form-control" rows="3" placeholder="${placeholder}" ${isRequired ? 'required' : ''}></textarea>`;
            } else if (attribute.valueType === 'BOOLEAN') {
                return `
                    <select id="${inputId}" name="${inputId}" class="form-control" ${isRequired ? 'required' : ''}>
                        <option value="">Select...</option>
                        <option value="true">Yes</option>
                        <option value="false">No</option>
                    </select>
                `;
            } else if (attribute.valueType === 'EMAIL') {
                return `<input type="email" id="${inputId}" name="${inputId}" class="form-control" placeholder="${placeholder}" ${isRequired ? 'required' : ''}>`;
            } else if (attribute.valueType === 'PHONE_NUMBER') {
                return `<input type="tel" id="${inputId}" name="${inputId}" class="form-control" placeholder="${placeholder}" ${isRequired ? 'required' : ''}>`;
            } else if (attribute.optionSet && attribute.optionSet.options) {
                let options = '<option value="">Select an option...</option>';
                attribute.optionSet.options.forEach(option => {
                    options += `<option value="${option.code}">${option.displayName}</option>`;
                });
                return `<select id="${inputId}" name="${inputId}" class="form-control" ${isRequired ? 'required' : ''}>${options}</select>`;
            } else {
                return `<input type="text" id="${inputId}" name="${inputId}" class="form-control" placeholder="${placeholder}" ${isRequired ? 'required' : ''}>`;
            }
        }

        function getTEIPlaceholderText(valueType, label) {
            switch(valueType) {
                case 'TEXT': return `Enter ${label.toLowerCase()}...`;
                case 'NUMBER':
                case 'INTEGER': return `Enter a number for ${label.toLowerCase()}...`;
                case 'LONG_TEXT': return `Enter detailed information about ${label.toLowerCase()}...`;
                case 'DATE': return 'Select date';
                case 'EMAIL': return 'Enter email address...';
                case 'PHONE_NUMBER': return 'Enter phone number...';
                default: return `Enter ${label.toLowerCase()}...`;
            }
        }

        function getTEIQuestionHelp(valueType) {
            switch(valueType) {
                case 'TEXT': return 'Enter text information';
                case 'NUMBER':
                case 'INTEGER': return 'Enter numeric values only';
                case 'DATE': return 'Select or enter a valid date';
                case 'LONG_TEXT': return 'You can enter longer text with multiple lines';
                case 'BOOLEAN': return 'Choose Yes or No';
                case 'EMAIL': return 'Enter a valid email address';
                case 'PHONE_NUMBER': return 'Enter a valid phone number';
                default: return 'Enter the required information';
            }
        }

        function loadExistingTEIData() {
            // Load existing TEI data if available
            if (formData.trackedEntityAttributes && Object.keys(formData.trackedEntityAttributes).length > 0) {
                Object.keys(formData.trackedEntityAttributes).forEach(attributeId => {
                    const inputs = document.querySelectorAll(`[id^="tei_${attributeId}"]`);
                    inputs.forEach(input => {
                        if (input) {
                            input.value = formData.trackedEntityAttributes[attributeId];
                        }
                    });
                });
            }
        }

        function saveTEIData() {
            const container = document.getElementById('modalQuestionsContainer');
            
            // Collect all TEI attribute data
            const teiData = {};
            const inputs = container.querySelectorAll('input, select, textarea');
            let hasRequiredErrors = false;
            
            inputs.forEach(input => {
                if (input.hasAttribute('required') && !input.value.trim()) {
                    hasRequiredErrors = true;
                    input.classList.add('is-invalid');
                } else {
                    input.classList.remove('is-invalid');
                    if (input.value) {
                        // Extract attribute ID from input ID (format: tei_ATTRIBUTEID_index)
                        const match = input.id.match(/^tei_([^_]+)_\d+$/);
                        if (match) {
                            const attributeId = match[1];
                            teiData[attributeId] = input.value;
                        }
                    }
                }
            });
            
            if (hasRequiredErrors) {
                alert('Please fill in all required fields marked with *');
                return;
            }
            
            // Store TEI data
            formData.trackedEntityAttributes = teiData;
            
            console.log('Saving TEI data:', teiData);
            
            // Update UI to show TEI data has been saved
            updateTEIProgress();
            
            // Close modal
            closeStageModal();
            
            // Show success message
            showSuccessMessage('Participant information saved successfully!');
        }

        function updateTEIProgress() {
            const navItem = document.querySelector('[data-stage="tei-section"]');
            if (navItem) {
                const hasData = formData.trackedEntityAttributes && Object.keys(formData.trackedEntityAttributes).length > 0;
                if (hasData) {
                    navItem.classList.add('completed');
                } else {
                    navItem.classList.remove('completed');
                }
            }
        }

        async function loadStageQuestions(stageId, container) {
            const stage = programData.program.programStages.find(s => s.id === stageId);
            if (!stage) return;

            // Load groupings from database
            const groupingData = await loadGroupingsFromDatabase(stageId);

            // If we have groupings for this stage, apply them
            if (groupingData && groupingData.length > 0) {
                loadGroupedQuestions(container, stage, groupingData);
            } else {
                loadUngroupedQuestions(container, stage);
            }
        }

        async function loadGroupingsFromDatabase(stageId) {
            try {
                const surveyId = document.getElementById('surveyId').value;
                const response = await fetch(`api/groupings.php?survey_id=${surveyId}`);
                
                if (!response.ok) {
                    console.error('Failed to load groupings from database');
                    return null;
                }
                
                const result = await response.json();
                
                if (result.success && result.data) {
                    return result.data[stageId] || null;
                }
                
                return null;
            } catch (error) {
                console.error('Error loading groupings from database:', error);
                return null;
            }
        }

        function loadUngroupedQuestions(container, stage) {
            container.innerHTML = '<div class="modal-questions-grid"></div>';
            const grid = container.querySelector('.modal-questions-grid');

            stage.programStageDataElements.forEach((deConfig, index) => {
                const de = deConfig.dataElement;
                const questionItem = createQuestionItem(de, deConfig, index);
                grid.appendChild(questionItem);
            });
        }

        function loadGroupedQuestions(container, stage, groupingData) {
            container.innerHTML = '<div class="grouped-questions-container"></div>';
            const mainContainer = container.querySelector('.grouped-questions-container');

            // Create groups first
            groupingData.forEach(group => {
                if (group.questions && group.questions.length > 0) {
                    const groupDiv = document.createElement('div');
                    groupDiv.className = 'modal-group-section';
                    groupDiv.innerHTML = `
                        <div class="modal-group-header">
                            <h5 class="modal-group-title">
                                <i class="fas fa-folder-open text-info me-3"></i>
                                ${group.groupTitle}
                            </h5>
                        </div>
                        <div class="modal-group-content">
                            <div class="modal-questions-grid group-grid"></div>
                        </div>
                    `;

                    const groupGrid = groupDiv.querySelector('.group-grid');
                    
                    // Add questions to this group
                    group.questions.forEach(questionRef => {
                        const deConfig = stage.programStageDataElements.find(de => 
                            de.dataElement.id === questionRef.questionId
                        );
                        
                        if (deConfig) {
                            const index = stage.programStageDataElements.indexOf(deConfig);
                            const questionItem = createQuestionItem(deConfig.dataElement, deConfig, index);
                            groupGrid.appendChild(questionItem);
                        }
                    });

                    if (groupGrid.children.length > 0) {
                        mainContainer.appendChild(groupDiv);
                    }
                }
            });

            // Add ungrouped questions if any
            const groupedQuestionIds = new Set();
            groupingData.forEach(group => {
                if (group.questions) {
                    group.questions.forEach(q => groupedQuestionIds.add(q.questionId));
                }
            });

            const ungroupedElements = stage.programStageDataElements.filter(deConfig => 
                !groupedQuestionIds.has(deConfig.dataElement.id)
            );

            if (ungroupedElements.length > 0) {
                const ungroupedDiv = document.createElement('div');
                ungroupedDiv.className = 'modal-group-section';
                ungroupedDiv.innerHTML = `
                    <div class="modal-group-header">
                        <h5 class="modal-group-title">
                            <i class="fas fa-list text-secondary me-3"></i>
                            Other Questions
                        </h5>
                    </div>
                    <div class="modal-group-content">
                        <div class="modal-questions-grid group-grid"></div>
                    </div>
                `;

                const ungroupedGrid = ungroupedDiv.querySelector('.group-grid');
                
                ungroupedElements.forEach((deConfig, index) => {
                    const questionItem = createQuestionItem(deConfig.dataElement, deConfig, index);
                    ungroupedGrid.appendChild(questionItem);
                });

                mainContainer.appendChild(ungroupedDiv);
            }
        }

        function createQuestionItem(dataElement, config, index) {
            const div = document.createElement('div');
            div.className = 'modal-question-item';
            
            // Clean the label by removing prefixes
            let cleanLabel = dataElement.name;
            cleanLabel = cleanLabel.replace(/^[A-Z]+_/, '');

            const inputId = `modal_${dataElement.id}_${index}`;
            
            div.innerHTML = `
                <div class="question-header">
                    <h6 class="question-label">
                        ${cleanLabel}
                        ${config.compulsory ? '<span class="question-required">*</span>' : ''}
                    </h6>
                </div>
                <div class="question-input-container" id="input_container_${inputId}">
                    ${createQuestionInput(dataElement, inputId, cleanLabel)}
                </div>
                <div class="question-help">
                    ${getQuestionHelp(dataElement.valueType)}
                </div>
            `;

            return div;
        }

        function createQuestionInput(dataElement, inputId, label) {
            const placeholder = getPlaceholderText(dataElement.valueType, label);
            
            if (dataElement.valueType === 'TEXT') {
                return `<input type="text" id="${inputId}" name="${inputId}" class="form-control" placeholder="${placeholder}" ${dataElement.compulsory ? 'required' : ''}>`;
            } else if (['NUMBER', 'INTEGER'].includes(dataElement.valueType)) {
                return `<input type="number" id="${inputId}" name="${inputId}" class="form-control" placeholder="${placeholder}" ${dataElement.compulsory ? 'required' : ''}>`;
            } else if (dataElement.valueType === 'DATE') {
                return `<input type="date" id="${inputId}" name="${inputId}" class="form-control" ${dataElement.compulsory ? 'required' : ''}>`;
            } else if (dataElement.valueType === 'LONG_TEXT') {
                return `<textarea id="${inputId}" name="${inputId}" class="form-control" rows="3" placeholder="${placeholder}" ${dataElement.compulsory ? 'required' : ''}></textarea>`;
            } else if (dataElement.valueType === 'BOOLEAN') {
                return `
                    <select id="${inputId}" name="${inputId}" class="form-control" ${dataElement.compulsory ? 'required' : ''}>
                        <option value="">Select...</option>
                        <option value="true">Yes</option>
                        <option value="false">No</option>
                    </select>
                `;
            } else if (dataElement.optionSet && dataElement.optionSet.options) {
                let options = '<option value="">Select an option...</option>';
                dataElement.optionSet.options.forEach(option => {
                    options += `<option value="${option.code}">${option.displayName}</option>`;
                });
                return `<select id="${inputId}" name="${inputId}" class="form-control" ${dataElement.compulsory ? 'required' : ''}>${options}</select>`;
            } else {
                return `<input type="text" id="${inputId}" name="${inputId}" class="form-control" placeholder="${placeholder}" ${dataElement.compulsory ? 'required' : ''}>`;
            }
        }

        function getPlaceholderText(valueType, label) {
            switch(valueType) {
                case 'TEXT': return `Enter ${label.toLowerCase()}...`;
                case 'NUMBER':
                case 'INTEGER': return `Enter a number for ${label.toLowerCase()}...`;
                case 'LONG_TEXT': return `Enter detailed information about ${label.toLowerCase()}...`;
                case 'DATE': return 'Select date';
                default: return `Enter ${label.toLowerCase()}...`;
            }
        }

        function getQuestionHelp(valueType) {
            switch(valueType) {
                case 'TEXT': return 'Enter text information';
                case 'NUMBER':
                case 'INTEGER': return 'Enter numeric values only';
                case 'DATE': return 'Select or enter a valid date';
                case 'LONG_TEXT': return 'You can enter longer text with multiple lines';
                case 'BOOLEAN': return 'Choose Yes or No';
                default: return 'Enter the required information';
            }
        }


        function closeStageModal() {
            const modal = document.getElementById('stageQuestionsModal');
            modal.style.display = 'none';
            currentModalStage = null;
        }

        function saveStageData(stageId, occurrenceNum) {
            const eventDate = document.getElementById('modalEventDate').value;
            const container = document.getElementById('modalQuestionsContainer');
            
            if (!eventDate) {
                alert('Please select an event date');
                return;
            }
            
            // Collect all form data
            const occurrenceData = { eventDate: eventDate, dataElements: {} };
            const inputs = container.querySelectorAll('input, select, textarea');
            
            inputs.forEach(input => {
                if (input.value) {
                    occurrenceData.dataElements[input.id] = input.value;
                }
            });
            
            // Store the data independently for this stage occurrence
            if (!stageData[stageId]) {
                stageData[stageId] = {};
            }
            
            const occurrenceKey = `${stageId}_${occurrenceNum}`;
            stageData[stageId][occurrenceKey] = occurrenceData;
            
            console.log('Saving stage data:', { stageId, occurrenceNum, data: occurrenceData });
            
            // Update UI to show data has been saved
            updateStageProgress(stageId);
            
            // Close modal
            closeStageModal();
            
            // Show success message
            const stageName = programData.program.programStages.find(s => s.id === stageId)?.name || 'Stage';
            showSuccessMessage(`${stageName} ${occurrenceNum > 1 ? `occurrence ${occurrenceNum}` : ''} saved successfully!`);
        }

        function loadExistingData(stageId, occurrenceNum) {
            const occurrenceKey = `${stageId}_${occurrenceNum}`;
            const existingData = stageData[stageId] && stageData[stageId][occurrenceKey];
            
            if (!existingData) return;
            
            // Load event date
            const eventDateInput = document.getElementById('modalEventDate');
            if (eventDateInput && existingData.eventDate) {
                eventDateInput.value = existingData.eventDate;
            }
            
            // Load data element values
            if (existingData.dataElements) {
                Object.keys(existingData.dataElements).forEach(inputId => {
                    const input = document.getElementById(inputId);
                    if (input) {
                        input.value = existingData.dataElements[inputId];
                    }
                });
            }
        }

        function updateStageProgress(stageId) {
            // Update the visual progress indicator for this stage
            const navItem = document.querySelector(`[data-stage="${stageId}"]`);
            if (navItem) {
                // Check if any occurrence has data
                let hasData = false;
                if (stageData[stageId]) {
                    hasData = Object.keys(stageData[stageId]).length > 0;
                }
                
                if (hasData) {
                    navItem.classList.add('completed');
                } else {
                    navItem.classList.remove('completed');
                }
            }
        }

        async function submitAllData() {
            // Check if we're in offline mode
            <?php if (isset($offlineMode) && $offlineMode): ?>
                alert('Data submission is disabled in offline mode. Please check your internet connection and refresh the page.');
                return;
            <?php endif; ?>
            
            const loadingSpinner = document.getElementById('loadingSpinner');
            const submitBtn = document.querySelector('[onclick="submitAllData()"]');
            
            // Validate location selection
            const facilityId = document.getElementById('facilityId').value;
            const facilityOrgunitUid = document.getElementById('facilityOrgunitUid').value;
            
            if (!facilityId) {
                alert('Please select a facility/location before submitting.');
                return;
            }
            
            // Show loading state
            loadingSpinner.style.display = 'block';
            submitBtn.style.display = 'none';
            
            try {
                // Collect all form data for DHIS2 submission
                const submissionData = {
                    survey_id: document.getElementById('surveyId').value,
                    location_data: {
                        facility_id: document.getElementById('facilityId').value,
                        facility_name: document.getElementById('facilityName').value,
                        orgunit_uid: document.getElementById('facilityOrgunitUid').value,
                        hierarchy_path: document.getElementById('hierarchyData').value
                    },
                    form_data: {
                        trackedEntityAttributes: formData.trackedEntityAttributes,
                        events: {}
                    }
                };
                
                // Convert stage data to DHIS2 events format
                Object.keys(stageData).forEach(stageId => {
                    const stageOccurrences = stageData[stageId];
                    Object.keys(stageOccurrences).forEach(occurrenceKey => {
                        const occurrenceData = stageOccurrences[occurrenceKey];
                        
                        // Create event for DHIS2
                        submissionData.form_data.events[occurrenceKey] = {
                            programStage: stageId,
                            eventDate: occurrenceData.eventDate,
                            dataValues: {}
                        };
                        
                        // Convert modal input data to DHIS2 data values
                        Object.keys(occurrenceData.dataElements || {}).forEach(inputId => {
                            // Extract data element ID from input ID (format: modal_DEID_index)
                            const match = inputId.match(/^modal_([^_]+)_\d+$/);
                            if (match) {
                                const deId = match[1];
                                submissionData.form_data.events[occurrenceKey].dataValues[deId] = occurrenceData.dataElements[inputId];
                            }
                        });
                    });
                });
                
                console.log('Submitting data to DHIS2:', submissionData);
                
                // Submit to backend
                const response = await fetch('tracker_program_submit.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(submissionData)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showSuccessMessage('Data submitted successfully to DHIS2!');
                    
                    // Redirect to success page after short delay
                    setTimeout(() => {
                        window.location.href = `/tracker-success/${submissionData.survey_id}/${result.submission_id}`;
                    }, 1500);
                } else {
                    throw new Error(result.message || 'Submission failed');
                }
                
            } catch (error) {
                console.error('Submission error:', error);
                loadingSpinner.style.display = 'none';
                submitBtn.style.display = 'inline-block';
                alert('Error submitting data: ' + error.message);
            }
        }

        function showSuccessMessage(message) {
            // Simple success notification
            const alert = document.createElement('div');
            alert.className = 'alert alert-success position-fixed';
            alert.style.cssText = 'top: 20px; right: 20px; z-index: 3000; max-width: 300px;';
            alert.innerHTML = `
                <i class="fas fa-check-circle me-2"></i>
                ${message}
            `;
            
            document.body.appendChild(alert);
            
            setTimeout(() => {
                alert.remove();
            }, 3000);
        }

        // Function to clear all forms in a stage when navigating to it
        function clearStageForm(stageId) {
            const stageSection = document.querySelector(`[data-stage-id="${stageId}"]`);
            if (!stageSection) return;

            // Clear all occurrence containers for this stage
            const occurrenceContainers = stageSection.querySelectorAll('.occurrence-container');
            occurrenceContainers.forEach(container => {
                clearFormContainer(container);
            });

            // Reset to first occurrence only and make sure it's visible
            const occurrenceTabs = stageSection.querySelector(`#occurrenceTabs_${stageId}`);
            if (occurrenceTabs) {
                // Remove all tabs except the first one
                const tabs = occurrenceTabs.querySelectorAll('.occurrence-tab');
                tabs.forEach((tab, index) => {
                    if (index > 0) {
                        tab.remove();
                    } else {
                        // Make first tab active
                        tab.classList.add('active');
                    }
                });

                // Remove all occurrence containers except the first one
                const allContainers = stageSection.querySelectorAll('.occurrence-container');
                allContainers.forEach((container, index) => {
                    if (index > 0) {
                        container.remove();
                    } else {
                        // Show first container and clear it
                        container.style.display = 'block';
                        clearFormContainer(container);
                    }
                });
            }

            // Update remove button state
            updateRemoveButton(stageId);
        }

        // Function to clear all form elements in a container
        function clearFormContainer(container) {
            const allFormElements = container.querySelectorAll('input, select, textarea');
            allFormElements.forEach(element => {
                // Clear all values
                if (element.type === 'checkbox' || element.type === 'radio') {
                    element.checked = false;
                } else if (element.tagName === 'SELECT') {
                    element.selectedIndex = 0;
                    element.value = '';
                } else if (element.type === 'date') {
                    // For event date, set to today, for other dates leave blank
                    if (element.classList.contains('event-date')) {
                        element.value = new Date().toISOString().split('T')[0];
                    } else {
                        element.value = '';
                    }
                } else {
                    element.value = '';
                }
                
                // Remove validation states
                element.classList.remove('is-valid', 'is-invalid');
            });
        }

        // Function to clear TEI section forms
        function clearFormSection(section) {
            const allFormElements = section.querySelectorAll('input, select, textarea');
            allFormElements.forEach(element => {
                if (element.type === 'checkbox' || element.type === 'radio') {
                    element.checked = false;
                } else if (element.tagName === 'SELECT') {
                    element.selectedIndex = 0;
                    element.value = '';
                } else {
                    element.value = '';
                }
                element.classList.remove('is-valid', 'is-invalid');
            });
        }

        // Load saved groupings and apply them - prioritize database over localStorage
        async function loadSavedGroupings() {
            const surveyId = document.getElementById('surveyId').value;
            
            try {
                // First, try to load from database
                const response = await fetch(`api/groupings.php?survey_id=${surveyId}`);
                
                if (response.ok) {
                    const result = await response.json();
                    
                    if (result.success && result.data && Object.keys(result.data).length > 0) {
                        console.log('Loading groupings from database:', result.data);
                        
                        // Apply groupings to form
                        Object.keys(result.data).forEach(stageId => {
                            const groups = result.data[stageId];
                            if (groups && groups.length > 0) {
                                applyGroupingToStage(stageId, groups);
                            }
                        });
                        return; // Success - don't fall back to localStorage
                    }
                }
            } catch (error) {
                console.error('Error loading groupings from database:', error);
            }
            
            // Fallback to localStorage if database loading failed
            const savedGrouping = localStorage.getItem(`tracker_grouping_${surveyId}`);
            if (savedGrouping) {
                try {
                    const groupingData = JSON.parse(savedGrouping);
                    console.log('Loading saved groupings from localStorage (fallback):', groupingData);
                    
                    // Apply groupings to form
                    Object.keys(groupingData).forEach(stageId => {
                        const groups = groupingData[stageId];
                        if (groups && groups.length > 0) {
                            applyGroupingToStage(stageId, groups);
                        }
                    });
                    
                } catch (e) {
                    console.error('Error loading saved groupings from localStorage:', e);
                }
            }
        }

        function applyGroupingToStage(stageId, groups) {
            const stageSection = document.querySelector(`[data-stage-id="${stageId}"]`);
            if (!stageSection) return;

            const stageBody = stageSection.querySelector('.stage-body');
            const occurrenceContainer = stageBody.querySelector('.occurrence-container');
            
            if (!occurrenceContainer) return;

            // Create grouped layout
            const groupedContainer = document.createElement('div');
            groupedContainer.className = 'grouped-questions-container';

            groups.forEach(group => {
                if (group.questions && group.questions.length > 0) {
                    const groupDiv = document.createElement('div');
                    groupDiv.className = 'form-group-section';
                    groupDiv.innerHTML = `
                        <h6 class="group-title mb-3">
                            <i class="fas fa-folder-open text-info me-2"></i>
                            ${group.groupTitle}
                        </h6>
                        <div class="group-fields"></div>
                    `;

                    const groupFields = groupDiv.querySelector('.group-fields');
                    
                    // Move questions to their groups
                    group.questions.forEach(questionRef => {
                        const questionElement = occurrenceContainer.querySelector(`[data-de-id="${questionRef.questionId}"]`);
                        if (questionElement) {
                            const formGroup = questionElement.closest('.form-group');
                            if (formGroup) {
                                groupFields.appendChild(formGroup);
                            }
                        }
                    });

                    if (groupFields.children.length > 0) {
                        groupedContainer.appendChild(groupDiv);
                    }
                }
            });

            // Replace the original container with grouped container
            if (groupedContainer.children.length > 0) {
                occurrenceContainer.appendChild(groupedContainer);
            }
        }

        // Enhanced Add Another function - creates completely blank forms
        function addOccurrence(stageId) {
            console.log(`Adding occurrence for stage: ${stageId}`);
            
            const occurrenceTabs = document.getElementById(`occurrenceTabs_${stageId}`);
            if (!occurrenceTabs) {
                console.error(`Occurrence tabs not found for stage: ${stageId}`);
                return;
            }
            
            const currentOccurrences = occurrenceTabs.querySelectorAll('.occurrence-tab').length;
            const newOccurrence = currentOccurrences + 1;

            // Add new tab
            const newTab = document.createElement('div');
            newTab.className = 'occurrence-tab';
            newTab.setAttribute('onclick', `switchOccurrence('${stageId}', ${newOccurrence})`);
            
            // Get the stage name from the first tab
            const firstTab = occurrenceTabs.querySelector('.occurrence-tab');
            const stageName = firstTab ? firstTab.textContent.split(' ')[0] : 'Visit';
            newTab.textContent = `${stageName} ${newOccurrence}`;
            occurrenceTabs.appendChild(newTab);

            // Clone the original container structure to create blank form
            const stageSection = document.querySelector(`[data-stage-id="${stageId}"]`);
            const originalContainer = stageSection.querySelector('.occurrence-container[data-occurrence="1"]');
            
            if (originalContainer) {
                const newContainer = originalContainer.cloneNode(true);
                newContainer.setAttribute('data-occurrence', newOccurrence);
                newContainer.style.display = 'none';

                // Clear ALL form values and update IDs
                const allFormElements = newContainer.querySelectorAll('input, select, textarea');
                allFormElements.forEach(element => {
                    // Update IDs and data attributes
                    if (element.id) {
                        element.id = element.id.replace(/_\d+$/, `_${newOccurrence}`);
                    }
                    element.setAttribute('data-occurrence', newOccurrence);
                    
                    // Completely clear all values
                    if (element.type === 'checkbox' || element.type === 'radio') {
                        element.checked = false;
                    } else if (element.tagName === 'SELECT') {
                        element.selectedIndex = 0; // Reset to first option (usually empty)
                        element.value = ''; // Explicitly clear value
                    } else if (element.type === 'date') {
                        // For event date, set to today, for other dates leave blank
                        if (element.classList.contains('event-date')) {
                            element.value = new Date().toISOString().split('T')[0];
                        } else {
                            element.value = '';
                        }
                    } else {
                        element.value = ''; // Clear all other input types
                    }
                    
                    // Remove any validation messages or states
                    element.classList.remove('is-valid', 'is-invalid');
                });

                // Update all labels to reference new occurrence
                const labels = newContainer.querySelectorAll('label[for]');
                labels.forEach(label => {
                    const oldFor = label.getAttribute('for');
                    if (oldFor) {
                        const newFor = oldFor.replace(/_\d+$/, `_${newOccurrence}`);
                        label.setAttribute('for', newFor);
                    }
                });

                // Insert the new container after the last occurrence container
                const stageBody = originalContainer.parentNode;
                stageBody.appendChild(newContainer);
            }

            // Update remove button state
            updateRemoveButton(stageId);

            // Switch to new occurrence
            switchOccurrence(stageId, newOccurrence);
            
            console.log(`Successfully added occurrence ${newOccurrence} for stage ${stageId}`);
        }

        // Add remove occurrence function
        function removeOccurrence(stageId) {
            console.log(`Removing occurrence for stage: ${stageId}`);
            
            const occurrenceTabs = document.getElementById(`occurrenceTabs_${stageId}`);
            if (!occurrenceTabs) {
                console.error(`Occurrence tabs not found for stage: ${stageId}`);
                return;
            }
            
            const tabs = occurrenceTabs.querySelectorAll('.occurrence-tab');
            const currentOccurrences = tabs.length;
            console.log(`Current occurrences: ${currentOccurrences}`);

            if (currentOccurrences <= 1) {
                console.log('Cannot remove the last occurrence');
                return; // Cannot remove the last occurrence
            }

            // Find the active tab
            const activeTab = occurrenceTabs.querySelector('.occurrence-tab.active');
            const activeOccurrence = parseInt(activeTab.textContent.split(' ')[1]);

            // Remove the container
            const stageSection = document.querySelector(`[data-stage-id="${stageId}"]`);
            const containerToRemove = stageSection.querySelector(`.occurrence-container[data-occurrence="${activeOccurrence}"]`);
            if (containerToRemove) {
                containerToRemove.remove();
            }

            // Remove the tab
            activeTab.remove();

            // Renumber remaining tabs
            const remainingTabs = occurrenceTabs.querySelectorAll('.occurrence-tab');
            remainingTabs.forEach((tab, index) => {
                const newNumber = index + 1;
                const stageName = tab.textContent.split(' ')[0];
                tab.textContent = `${stageName} ${newNumber}`;
                tab.setAttribute('onclick', `switchOccurrence('${stageId}', ${newNumber})`);
            });

            // Renumber remaining containers
            const remainingContainers = stageSection.querySelectorAll('.occurrence-container');
            remainingContainers.forEach((container, index) => {
                const newNumber = index + 1;
                container.setAttribute('data-occurrence', newNumber);
                
                // Update field IDs and attributes
                const fields = container.querySelectorAll('input, select, textarea');
                fields.forEach(field => {
                    const oldId = field.id;
                    const newId = oldId.replace(/_\d+$/, `_${newNumber}`);
                    field.id = newId;
                    field.setAttribute('data-occurrence', newNumber);
                });

                // Update labels
                const labels = container.querySelectorAll('label[for]');
                labels.forEach(label => {
                    const oldFor = label.getAttribute('for');
                    const newFor = oldFor.replace(/_\d+$/, `_${newNumber}`);
                    label.setAttribute('for', newFor);
                });
            });

            // Switch to first occurrence
            if (remainingTabs.length > 0) {
                switchOccurrence(stageId, 1);
            }

            // Update remove button state
            updateRemoveButton(stageId);
        }

        // Update remove button state
        function updateRemoveButton(stageId) {
            const occurrenceTabs = document.getElementById(`occurrenceTabs_${stageId}`);
            if (!occurrenceTabs) return;
            
            const tabs = occurrenceTabs.querySelectorAll('.occurrence-tab');
            const stageSection = document.querySelector(`[data-stage-id="${stageId}"]`);
            const removeButton = stageSection ? stageSection.querySelector('.remove-occurrence-btn-fixed') : null;
            
            if (removeButton) {
                removeButton.disabled = tabs.length <= 1;
                console.log(`Updated remove button for stage ${stageId}: disabled = ${removeButton.disabled}, tabs = ${tabs.length}`);
            } else {
                console.log(`Remove button not found for stage ${stageId}`);
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', async function() {
            console.log('Tracker form JavaScript loaded successfully');
            
            await loadSavedGroupings();
            
            // Set first section as active for navigation
            const firstSection = document.querySelector('.tei-section') || document.querySelector('.stage-section');
            if (firstSection) {
                firstSection.classList.add('active');
            }
            
            // Debug: Check if elements exist
            const stageNavigation = document.getElementById('stageNavigation');
            const stageSections = document.querySelectorAll('.stage-section');
            console.log('Stage navigation found:', !!stageNavigation);
            console.log('Stage sections found:', stageSections.length);
            
            // Initialize remove button states
            document.querySelectorAll('.stage-section').forEach(section => {
                const stageId = section.dataset.stageId;
                updateRemoveButton(stageId);
            });
        });
    </script>
</body>
</html>