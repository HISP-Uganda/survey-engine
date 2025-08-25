<?php
session_start();
require_once 'includes/session_timeout.php';
require_once 'connect.php';

// Check if $pdo object is available from connect.php
if (!isset($pdo)) {
    die("Database connection failed. Please check connect.php.");
}

// Get survey_id from the URL
$surveyId = $_GET['survey_id'] ?? null;

if (!$surveyId) {
    die("Survey ID is missing.");
}

// Fetch survey details
try {
    $surveyStmt = $pdo->prepare("SELECT id, type, name, dhis2_program_uid, dhis2_instance FROM survey WHERE id = ?");
    $surveyStmt->execute([$surveyId]);
    $survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);

    if (!$survey) {
        die("Survey not found.");
    }
    
    // Check if this is a DHIS2 tracker program
    if ($survey['type'] !== 'dhis2' || empty($survey['dhis2_program_uid'])) {
        // Redirect to regular preview form
        header("Location: preview_form.php?survey_id=" . $surveyId);
        exit();
    }
} catch (PDOException $e) {
    error_log("Database error fetching survey details: " . $e->getMessage());
    die("Error fetching survey details.");
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
    // Create a minimal tracker program structure for offline preview
    $trackerProgram = [
        'id' => $survey['dhis2_program_uid'],
        'name' => $survey['name'],
        'description' => 'DHIS2 Tracker Program (Offline Mode - Limited Preview)',
        'programType' => 'WITH_REGISTRATION',
        'programTrackedEntityAttributes' => [],
        'programStages' => [
            [
                'id' => 'offline_stage',
                'name' => 'Program Stage (Offline)',
                'description' => 'This is a simplified preview in offline mode',
                'repeatable' => false,
                'programStageDataElements' => []
            ]
        ]
    ];
    
    // Add offline mode indicator
    $offlineMode = true;
} else if ($trackerProgram['programType'] !== 'WITH_REGISTRATION') {
    // Not a tracker program, redirect to regular preview
    header("Location: preview_form.php?survey_id=" . $surveyId);
    exit();
} else {
    $offlineMode = false;
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
    'title_text' => $trackerProgram['name'] ?? 'DHIS2 Tracker Program',
    'layout_type' => 'horizontal',
    'show_flag_bar' => true,
    'flag_black_color' => '#000000',
    'flag_yellow_color' => '#FCD116', 
    'flag_red_color' => '#D21034',
    'show_logo' => false,
    'logo_path' => 'asets/asets/img/loog.jpg'
];

$surveySettings = array_merge($defaultSettings, $surveySettings);

// Fetch distinct instance_keys for the dropdown
$instanceKeys = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT instance_key FROM location ORDER BY instance_key ASC");
    $instanceKeys = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Database error fetching instance keys: " . $e->getMessage());
}

// Hierarchy Level Mapping (Fixed to Level X)
$hierarchyLevels = ['' => 'All Levels']; // Add "All Levels" option with empty value
for ($i = 1; $i <= 8; $i++) {
    $hierarchyLevels[$i] = 'Level ' . $i;
}

// Extract program components
$trackedEntityAttributes = $trackerProgram['programTrackedEntityAttributes'] ?? [];
$programStages = $trackerProgram['programStages'] ?? [];

// De-duplicate program stages to prevent issues with faulty API responses
if (!empty($programStages)) {
    $uniqueStages = [];
    $seenStageIds = [];
    foreach ($programStages as $stage) {
        if (isset($stage['id']) && !in_array($stage['id'], $seenStageIds)) {
            $uniqueStages[] = $stage;
            $seenStageIds[] = $stage['id'];
        }
    }
    $programStages = $uniqueStages;
}
//     echo "<h1>Debug Info:                  
//        Stages Before                            
//          De-duplication</h1>";                    
//    echo "<pre>";                          
//   print_r($trackerProgram[               
//         'programStages'] ?? []);                 
//  echo "</pre>";                         
                                       
//  echo "<h1>Debug Info:                  
//    Stages After                             
//       De-duplication</h1>";                    
//  echo "<pre>";                          
//  print_r($programStages);               
// echo "</pre>";                                                                
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($surveySettings['title_text']) ?> - Preview</title>
    <link href="argon-dashboard-master/assets/css/nucleo-icons.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-svg.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/argon-dashboard.min.css" rel="stylesheet">
    
    <style>
        /* Preview container styles */
        body {
            font-family: Arial, sans-serif;
            background-color: #f8f9fa;
            margin: 0;
            padding: 0;
        }

        .preview-container {
            padding: 0;
        }

        /* Flag Bar Styles */
        .flag-bar {
            height: 8px;
            display: flex;
            width: 100%;
            margin-bottom: 20px;
        }
        
        .flag-section {
            flex: 1;
            height: 100%;
        }
        
        .hidden-element {
            display: none !important;
        }

        /* Logo and header styles */
        .logo-container {
            text-align: center;
            margin-bottom: 20px;
        }

        .logo-container img {
            max-width: 100%;
            height: 80px;
            object-fit: contain;
        }

        /* Tracker preview styles */
        .tracker-preview-container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .program-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }

        .section-title {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .attribute-list, .stage-list {
            list-style: none;
            padding: 0;
        }

        .attribute-item, .stage-item {
            background: white;
            padding: 12px 15px;
            margin-bottom: 8px;
            border-radius: 6px;
            border: 1px solid #e9ecef;
            display: flex;
            justify-content: between;
            align-items: center;
        }

        .attribute-name, .stage-name {
            font-weight: 500;
            color: #333;
        }

        .attribute-type, .stage-info {
            font-size: 12px;
            color: #666;
            background: #e9ecef;
            padding: 2px 8px;
            border-radius: 12px;
            margin-left: auto;
        }

        .required-badge {
            background: #dc3545;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 8px;
        }

        .repeatable-badge {
            background: #28a745;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 8px;
        }

        /* Custom styling for better integration */
        .gap-3 {
            gap: 1rem !important;
        }

        /* Toast notification styles */
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #28a745;
            color: white;
            padding: 15px 25px;
            border-radius: 8px;
            font-weight: 600;
            z-index: 10000;
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
            opacity: 0;
            transform: translateX(300px);
            transition: all 0.3s ease;
            font-size: 14px;
            min-width: 250px;
        }

        .toast-notification.show {
            opacity: 1;
            transform: translateX(0);
        }

        .toast-notification.error {
            background: #dc3545;
        }

        /* Control panel styles */
        .control-panel h3 {
            margin-top: 0;
            color: #333;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }

        .info-group {
            background: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border: 1px solid #e9ecef;
        }

        .info-group h5 {
            color: #667eea;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 5px 0;
            border-bottom: 1px solid #f1f1f1;
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 500;
            color: #333;
        }

        .info-value {
            color: #666;
            font-size: 14px;
        }

        .filter-group {
            margin-bottom: 15px;
        }

        .filter-group label {
            font-weight: 600;
            color: #333;
            font-size: 14px;
            margin-bottom: 5px;
        }

        .form-control {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 8px 12px;
            font-size: 14px;
            width: 100%;
        }

        .form-control:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.2);
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5a6fd8;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .tracker-preview-container {
                padding: 15px;
            }
        }

        /* Question Grouping Styles */
        .question-group {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 15px;
            min-height: 60px;
            background: #f8f9fa;
            transition: all 0.3s ease;
        }

        .question-group:hover {
            border-color: #007bff;
            background: #e3f2fd;
        }

        .question-group.drag-over {
            border-color: #28a745;
            background: #d4edda;
            border-style: solid;
        }

        .group-header {
            margin-bottom: 10px;
        }

        .group-header h6 {
            margin: 0;
            color: #495057;
        }

        .group-title {
            font-weight: 600;
        }

        .question-item {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 8px;
            cursor: move;
            transition: all 0.2s ease;
            user-select: none;
        }

        .question-item:hover {
            border-color: #007bff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .question-item:active {
            transform: rotate(2deg);
            opacity: 0.8;
        }

        .question-content {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .question-name {
            flex: 1;
            font-weight: 500;
            color: #333;
        }

        .question-type {
            font-size: 11px;
            background: #e9ecef;
            color: #6c757d;
            padding: 2px 6px;
            border-radius: 10px;
        }

        .custom-group {
            border-color: #17a2b8;
            background: #e1f7fa;
        }

        .custom-group .group-header h6 {
            color: #17a2b8;
        }

        .group-controls {
            display: flex;
            gap: 5px;
            align-items: center;
        }

        .group-controls button {
            padding: 2px 6px;
            font-size: 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }

        .edit-group-btn {
            background: #ffc107;
            color: #212529;
        }

        .delete-group-btn {
            background: #dc3545;
            color: white;
        }

        .stage-grouping-view .stage-header {
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        /* Available Questions Panel Styles */
        .available-questions-panel .card-header {
            padding: 10px 15px;
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }

        .available-question-item {
            background: white;
            border: 1px solid #e3e6f0;
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .available-question-item:hover {
            background: #e3f2fd;
            border-color: #2196f3;
            transform: translateX(3px);
        }

        .available-question-item.hidden {
            display: none;
        }

        .available-question-text {
            font-size: 13px;
            color: #495057;
            margin-bottom: 4px;
            font-weight: 500;
        }

        .available-question-meta {
            font-size: 11px;
            color: #6c757d;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .available-question-type {
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .add-to-group-btn {
            opacity: 0;
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: #28a745;
            color: white;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            font-size: 12px;
            transition: opacity 0.3s ease;
            cursor: pointer;
        }

        .available-question-item:hover .add-to-group-btn {
            opacity: 1;
        }

        .add-to-group-btn:hover {
            background: #218838;
        }

        .no-available-questions {
            text-align: center;
            padding: 20px;
            color: #6c757d;
            font-style: italic;
        }

        /* Preview Images Styles */
        .preview-dynamic-images {
            margin: 15px 0;
        }

        .preview-images-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .preview-images-container.horizontal {
            flex-direction: row;
        }

        .preview-images-container.vertical {
            flex-direction: column;
        }

        .preview-images-container.center {
            justify-content: center;
        }

        .preview-images-container.left-right {
            justify-content: space-between;
        }

        .preview-image-item {
            display: flex;
            align-items: center;
            transition: transform 0.3s ease;
        }

        .preview-image-item:hover {
            transform: scale(1.05);
        }

        .no-images-message {
            padding: 20px;
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            background-color: #f8f9fa;
        }
    </style>
</head>

<body class="g-sidenav-show bg-gray-100">
    <?php include 'components/aside.php'; ?>
    
    <main class="main-content position-relative border-radius-lg">
        <?php include 'components/navbar.php'; ?>
        
        <div class="container-fluid py-4">
            <div class="row">
                <div class="col-lg-3">
                    <div class="card">
                        <div class="card-header">
                            <h4 class="mb-0"><i class="fas fa-cog me-2"></i>Program Information</h4>
                        </div>
                        <div class="card-body">
            
            <div class="info-group">
                <h5><i class="fas fa-database me-2"></i>DHIS2 Details</h5>
                <div class="info-item">
                    <span class="info-label">Program UID:</span>
                    <span class="info-value"><?= htmlspecialchars($survey['dhis2_program_uid']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Instance:</span>
                    <span class="info-value"><?= htmlspecialchars($survey['dhis2_instance']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Type:</span>
                    <span class="info-value">Tracker Program</span>
                </div>
            </div>
            
            <div class="info-group">
                <h5><i class="fas fa-chart-bar me-2"></i>Program Statistics</h5>
                <div class="info-item">
                    <span class="info-label">Participant Fields:</span>
                    <span class="info-value"><?= count($trackedEntityAttributes) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Program Stages:</span>
                    <span class="info-value"><?= count($programStages) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Total Data Elements:</span>
                    <span class="info-value">
                        <?php 
                        $totalElements = 0;
                        foreach ($programStages as $stage) {
                            $totalElements += count($stage['programStageDataElements']);
                        }
                        echo $totalElements;
                        ?>
                    </span>
                </div>
                <div class="info-item">
                    <span class="info-label">Repeatable Stages:</span>
                    <span class="info-value">
                        <?php 
                        $repeatableCount = 0;
                        foreach ($programStages as $stage) {
                            if ($stage['repeatable']) $repeatableCount++;
                        }
                        echo $repeatableCount;
                        ?>
                    </span>
                </div>
            </div>
            
          



  
            <!-- Survey Settings -->
            <div class="info-group">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5><i class="fas fa-cog me-2"></i>Survey Settings</h5>
                    <button class="btn btn-outline-primary btn-sm" onclick="toggleSettingsMode()">
                        <i class="fas fa-edit me-1"></i>
                        <span id="settingsModeText">Configure Settings</span>
                    </button>
                </div>
                <p class="text-muted small mb-3">Configure the appearance and branding of your tracker form including images, flags, and colors.</p>
                
                <div id="settingsInterface" style="display: none;">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Settings Mode:</strong> Configure logos, flag colors, and other visual elements for your tracker form.
                    </div>
                    <div class="mb-3">
                        <button class="btn btn-success btn-sm me-2" onclick="saveSettings()">
                            <i class="fas fa-save me-1"></i>
                            Save Settings
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" onclick="resetSettings()">
                            <i class="fas fa-undo me-1"></i>
                            Reset to Defaults
                        </button>
                    </div>
                    
                    <!-- Settings Form -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h6><i class="fas fa-cog me-1"></i> Layout Settings</h6>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="layoutType" class="form-label">Layout Type</label>
                                        <select class="form-control" id="layoutType">
                                            <option value="horizontal">Horizontal</option>
                                            <option value="vertical">Vertical</option>
                                            <option value="center">Center</option>
                                            <option value="left-right">Left-Right</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="card">
                                <div class="card-header">
                                    <h6><i class="fas fa-images me-1"></i> Dynamic Image Settings</h6>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="imageCount" class="form-label">Number of Images</label>
                                        <select class="form-control" id="imageCount" onchange="updateImageFields()">
                                            <option value="0" selected>No Images</option>
                                            <option value="1">1 Image</option>
                                            <option value="2">2 Images</option>
                                            <option value="3">3 Images</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="imageLayout" class="form-label">Image Layout</label>
                                        <select class="form-control" id="imageLayout">
                                            <option value="horizontal">Horizontal (side by side)</option>
                                            <option value="vertical">Vertical (stacked)</option>
                                            <option value="center">Center aligned</option>
                                            <option value="left-right">Left and Right aligned</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Dynamic Image Fields -->
                                    <div id="imageFieldsContainer">
                                        <!-- Will be populated by JavaScript -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- National Flag Settings - Compact Layout for Sidebar -->
                    <div class="mt-3">
                        <div class="card">
                            <div class="card-header">
                                <h6><i class="fas fa-flag me-1"></i> National Flag Settings</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="showFlagBar" <?= $surveySettings['show_flag_bar'] ? 'checked' : '' ?> onchange="updateFlagPreview()">
                                        <label class="form-check-label" for="showFlagBar">
                                            <strong>Display Flag Bar</strong>
                                            <small class="d-block text-muted">Show national colors bar</small>
                                        </label>
                                    </div>
                                </div>
                                
                                <!-- Flag Preview -->
                                <div class="flag-bar mb-3" id="flagPreview" style="height: 20px; border-radius: 4px; <?= $surveySettings['show_flag_bar'] ? 'opacity: 1;' : 'opacity: 0.3;' ?>">
                                    <div class="flag-section" id="flagBlackPreview" style="background-color: <?= htmlspecialchars($surveySettings['flag_black_color']) ?>; flex: 1; height: 100%;"></div>
                                    <div class="flag-section" id="flagYellowPreview" style="background-color: <?= htmlspecialchars($surveySettings['flag_yellow_color']) ?>; flex: 1; height: 100%;"></div>
                                    <div class="flag-section" id="flagRedPreview" style="background-color: <?= htmlspecialchars($surveySettings['flag_red_color']) ?>; flex: 1; height: 100%;"></div>
                                </div>
                                
                                <div id="flagColorControls" style="<?= $surveySettings['show_flag_bar'] ? 'opacity: 1;' : 'opacity: 0.5;' ?>">
                                    <!-- Left Section Color -->
                                    <div class="mb-3">
                                        <label for="flagBlackColor" class="form-label">
                                            <small><strong>Left Section</strong></small>
                                        </label>
                                        <div class="d-flex gap-2">
                                            <input type="color" class="form-control form-control-color flex-shrink-0" 
                                                   id="flagBlackColor" 
                                                   value="<?= htmlspecialchars($surveySettings['flag_black_color'] ?? '#000000') ?>"
                                                   onchange="updateFlagPreview()" 
                                                   title="Choose left section color"
                                                   style="width: 50px;">
                                            <input type="text" class="form-control form-control-sm" 
                                                   id="flagBlackColorText" 
                                                   value="<?= htmlspecialchars($surveySettings['flag_black_color'] ?? '#000000') ?>"
                                                   onchange="updateColorFromText('flagBlackColor', this.value)"
                                                   placeholder="#000000">
                                        </div>
                                    </div>
                                    
                                    <!-- Center Section Color -->
                                    <div class="mb-3">
                                        <label for="flagYellowColor" class="form-label">
                                            <small><strong>Center Section</strong></small>
                                        </label>
                                        <div class="d-flex gap-2">
                                            <input type="color" class="form-control form-control-color flex-shrink-0" 
                                                   id="flagYellowColor" 
                                                   value="<?= htmlspecialchars($surveySettings['flag_yellow_color'] ?? '#FCD116') ?>"
                                                   onchange="updateFlagPreview()" 
                                                   title="Choose center section color"
                                                   style="width: 50px;">
                                            <input type="text" class="form-control form-control-sm" 
                                                   id="flagYellowColorText" 
                                                   value="<?= htmlspecialchars($surveySettings['flag_yellow_color'] ?? '#FCD116') ?>"
                                                   onchange="updateColorFromText('flagYellowColor', this.value)"
                                                   placeholder="#FCD116">
                                        </div>
                                    </div>
                                    
                                    <!-- Right Section Color -->
                                    <div class="mb-3">
                                        <label for="flagRedColor" class="form-label">
                                            <small><strong>Right Section</strong></small>
                                        </label>
                                        <div class="d-flex gap-2">
                                            <input type="color" class="form-control form-control-color flex-shrink-0" 
                                                   id="flagRedColor" 
                                                   value="<?= htmlspecialchars($surveySettings['flag_red_color'] ?? '#D21034') ?>"
                                                   onchange="updateFlagPreview()" 
                                                   title="Choose right section color"
                                                   style="width: 50px;">
                                            <input type="text" class="form-control form-control-sm" 
                                                   id="flagRedColorText" 
                                                   value="<?= htmlspecialchars($surveySettings['flag_red_color'] ?? '#D21034') ?>"
                                                   onchange="updateColorFromText('flagRedColor', this.value)"
                                                   placeholder="#D21034">
                                        </div>
                                    </div>
                                    
                                    <div class="text-center">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetToUgandaFlag()">
                                            <i class="fas fa-undo me-1"></i> Reset to Uganda Flag
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="info-group">
                <h5><i class="fas fa-exclamation-triangle me-2"></i>Important Notes</h5>
                <div style="padding: 10px 0;">
                    <small class="text-muted">
                        • This form connects directly to your DHIS2 instance<br>
                        • All data will be synchronized with DHIS2<br>
                        • Repeatable stages can be filled multiple times<br>
                        • Required fields must be completed before submission<br>
                        • Location settings control which facilities users can select
                    </small>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="info-group">
                <h5><i class="fas fa-tools me-2"></i>Actions</h5>
                <div class="d-grid gap-2">
                    <a href="survey.php" class="btn btn-secondary btn-sm">
                        <i class="fas fa-arrow-left me-2"></i>Back to Surveys
                    </a>
                    <?php if (isset($offlineMode) && $offlineMode): ?>
                        <button class="btn btn-warning btn-sm" onclick="alert('Form cannot be opened in offline mode. Please check your internet connection and try again.')" title="Requires internet connection">
                            <i class="fas fa-wifi-slash me-2"></i>Form Offline
                        </button>
                    <?php else: ?>
                        <a href="../../t/<?= $surveyId ?>" class="btn btn-primary btn-sm" target="_blank">
                            <i class="fas fa-external-link-alt me-2"></i>Open Form
                        </a>
                    <?php endif; ?>
                    <button onclick="copyShareLink()" class="btn btn-info btn-sm">
                        <i class="fas fa-link me-2"></i>Copy Share Link
                    </button>
                    <a href="../../share/t/<?= $surveyId ?>" class="btn btn-success btn-sm">
                        <i class="fas fa-share me-2"></i>Share Page
                    </a>
                </div>
            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="mb-0"><?= htmlspecialchars($surveySettings['title_text']) ?> - Preview</h4>
                            <?php if (isset($offlineMode) && $offlineMode): ?>
                                <span class="badge bg-warning">Offline Mode</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="card-body">
                            <!-- Flag Bar Preview -->
                            <div class="flag-bar mb-3" id="previewFlagBar" style="display: <?= $surveySettings['show_flag_bar'] ? 'flex' : 'none' ?>;">
                                <div class="flag-section" id="previewFlagBlack" style="background-color: <?= $surveySettings['flag_black_color'] ?>;"></div>
                                <div class="flag-section" id="previewFlagYellow" style="background-color: <?= $surveySettings['flag_yellow_color'] ?>;"></div>
                                <div class="flag-section" id="previewFlagRed" style="background-color: <?= $surveySettings['flag_red_color'] ?>;"></div>
                            </div>

                            <div class="tracker-preview-container">
                <!-- Header -->
                <div class="text-center mb-4">
                    <h2 class="mb-2" style="color: #2c3e50; font-weight: 700;">
                        <?= htmlspecialchars($surveySettings['title_text']) ?>
                    </h2>
                    
                    <!-- Dynamic Images Preview -->
                    <div class="preview-dynamic-images mb-3" id="previewDynamicImages">
                        <?php if (!empty($dynamicImages)): ?>
                            <div class="preview-images-container <?= htmlspecialchars($surveySettings['layout_type']) ?>" id="previewImagesContainer">
                                <?php foreach ($dynamicImages as $image): ?>
                                    <div class="preview-image-item position-<?= htmlspecialchars($image['position_type']) ?>">
                                        <img src="<?= htmlspecialchars($image['image_path']) ?>?v=<?= time() ?>&id=<?= $image['image_order'] ?>&rnd=<?= mt_rand(1000,9999) ?>" 
                                             alt="<?= htmlspecialchars($image['image_alt_text']) ?>"
                                             style="width: 50px; height: 35px; border-radius: 4px; object-fit: contain; border: 1px solid #ddd;"
                                             onerror="console.error('Failed to load image:', this.src); this.style.border='1px solid red'; this.alt='❌';" 
                                             onload="console.log('Loaded image:', this.src);"
                                             title="<?= htmlspecialchars($image['image_alt_text']) ?>">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-images-message text-muted" style="font-size: 14px; margin: 10px 0;">
                                <i class="fas fa-info-circle me-2"></i>No dynamic images configured - use settings to add images
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($trackerProgram['description'])): ?>
                        <p class="text-muted"><?= htmlspecialchars($trackerProgram['description']) ?></p>
                    <?php endif; ?>
                    <?php if (isset($offlineMode) && $offlineMode): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-wifi-slash me-2"></i>
                            <strong>Offline Mode:</strong> This is a limited preview as the DHIS2 server is not accessible. Some program details may not be available.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            This is a preview of your DHIS2 Tracker Program form. Use the buttons below to open the actual form or share it with others.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Tracked Entity Attributes Section -->
                <?php if (!empty($trackedEntityAttributes)): ?>
                    <div class="program-section">
                        <h4 class="section-title">
                            <i class="fas fa-user-circle text-primary"></i>
                            Participant Information
                        </h4>
                        <p class="text-muted mb-3">These fields will be filled once per participant and remain constant throughout the program.</p>
                        <ul class="attribute-list">
                            <?php foreach ($trackedEntityAttributes as $teaConfig): ?>
                                <?php $tea = $teaConfig['trackedEntityAttribute']; ?>
                                <li class="attribute-item">
                                    <span class="attribute-name">
                                        <?php 
                                        $cleanName = $tea['name'];
                                        // Remove common prefixes like PM_, HEI_, etc.
                                        $cleanName = preg_replace('/^[A-Z]+_/', '', $cleanName);
                                        echo htmlspecialchars($cleanName);
                                        ?>
                                        <?php if ($teaConfig['mandatory']): ?>
                                            <span class="required-badge">Required</span>
                                        <?php endif; ?>
                                        <?php if ($tea['unique']): ?>
                                            <span class="required-badge" style="background: #ffc107;">Unique</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="attribute-type"><?= htmlspecialchars($tea['valueType']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Program Stages Section with Grouping -->
                <?php if (!empty($programStages)): ?>
                    <div class="program-section">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="section-title mb-0">
                                <i class="fas fa-list-check text-success"></i>
                                Program Stages
                            </h4>
                            <button class="btn btn-outline-primary btn-sm" onclick="toggleGroupingMode()">
                                <i class="fas fa-layer-group me-1"></i>
                                <span id="groupingModeText">Enable Grouping</span>
                            </button>
                        </div>
                        <p class="text-muted mb-3">These are the different stages/visits in this program. Click "Enable Grouping" to create custom question groups and drag questions between them.</p>
                        
                        <div id="groupingInterface" style="display: none;">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Grouping Mode:</strong> Create custom groups and drag questions into them. Groups will appear as separate sections in the form.
                            </div>
                            <div class="mb-3">
                                <button class="btn btn-success btn-sm" onclick="createNewGroup()">
                                    <i class="fas fa-plus me-1"></i>Create New Group
                                </button>
                                <button class="btn btn-primary btn-sm ms-2" onclick="saveGrouping()">
                                    <i class="fas fa-save me-1"></i>Save Grouping
                                </button>
                                <button class="btn btn-secondary btn-sm ms-2" onclick="resetGrouping()">
                                    <i class="fas fa-undo me-1"></i>Reset
                                </button>
                            </div>
                        </div>

                        <?php foreach ($programStages as $stageIndex => $stage): ?>
                            <div class="stage-container mb-4" data-stage-id="<?= $stage['id'] ?>">
                                <div class="stage-header">
                                    <div style="width: 100%; display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                        <span class="stage-name">
                                            <?= htmlspecialchars($stage['name']) ?>
                                            <?php if ($stage['repeatable']): ?>
                                                <span class="repeatable-badge">Repeatable</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="stage-info"><?= count($stage['programStageDataElements']) ?> fields</span>
                                    </div>
                                    <?php if (!empty($stage['description'])): ?>
                                        <p class="text-muted mb-3" style="font-size: 14px;"><?= htmlspecialchars($stage['description']) ?></p>
                                    <?php endif; ?>
                                </div>

                                <!-- Normal View -->
                                <div class="stage-normal-view">
                                    <div class="row g-2">
                                        <?php foreach ($stage['programStageDataElements'] as $deIndex => $deConfig): ?>
                                            <?php $de = $deConfig['dataElement']; ?>
                                            <div class="col-md-6">
                                                <small class="text-muted d-block">
                                                    <i class="fas fa-arrow-right me-1"></i>
                                                    <?php 
                                                    $cleanName = $de['name'];
                                                    // Remove common prefixes like PM_, HEI_, etc.
                                                    $cleanName = preg_replace('/^[A-Z]+_/', '', $cleanName);
                                                    echo htmlspecialchars($cleanName);
                                                    ?>
                                                    <span style="color: #999;">(<?= htmlspecialchars($de['valueType']) ?>)</span>
                                                </small>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Grouping View -->
                                <div class="stage-grouping-view" style="display: none;">
                                    <!-- Default Group -->
                                    <div class="question-group mb-3" data-group-id="default_<?= $stage['id'] ?>">
                                        <div class="group-header">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <h6 class="mb-0">
                                                    <i class="fas fa-folder text-warning me-2"></i>
                                                    <span class="group-title" onclick="editGroupName('default_<?= $stage['id'] ?>')" style="cursor: pointer;" title="Click to edit">Ungrouped Questions</span>
                                                    <small class="text-muted ms-2">(Default group)</small>
                                                </h6>
                                                <div class="group-controls">
                                                    <button class="btn btn-outline-primary btn-sm me-2" onclick="toggleGroupSearch('default_<?= $stage['id'] ?>', '<?= $stage['id'] ?>')" title="Search and add questions">
                                                        <i class="fas fa-search-plus"></i>
                                                    </button>
                                                    <button class="edit-group-btn" onclick="editGroupName('default_<?= $stage['id'] ?>')" title="Edit name">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Group Search Panel (Initially Hidden) -->
                                        <div class="group-search-panel" id="searchPanel_default_<?= $stage['id'] ?>" style="display: none;">
                                            <div class="card bg-light mt-2 mb-3">
                                                <div class="card-body p-3">
                                                    <div class="mb-2">
                                                        <input type="text" class="form-control form-control-sm" 
                                                               placeholder="Search questions to add to this group..." 
                                                               id="groupSearch_default_<?= $stage['id'] ?>" 
                                                               onkeyup="filterGroupAvailableQuestions('default_<?= $stage['id'] ?>', '<?= $stage['id'] ?>')">
                                                    </div>
                                                    <div class="group-available-questions" 
                                                         id="groupAvailable_default_<?= $stage['id'] ?>" 
                                                         style="max-height: 150px; overflow-y: auto;">
                                                        <!-- Available questions for this group -->
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="group-questions" data-group="default_<?= $stage['id'] ?>">
                                            <?php foreach ($stage['programStageDataElements'] as $deIndex => $deConfig): ?>
                                                <?php $de = $deConfig['dataElement']; ?>
                                                <div class="question-item" draggable="true" 
                                                     data-question-id="<?= $de['id'] ?>" 
                                                     data-stage-id="<?= $stage['id'] ?>"
                                                     data-question-index="<?= $deIndex ?>">
                                                    <div class="question-content">
                                                        <i class="fas fa-grip-lines me-2 text-muted"></i>
                                                        <span class="question-name">
                                                            <?php 
                                                            $cleanName = $de['name'];
                                                            $cleanName = preg_replace('/^[A-Z]+_/', '', $cleanName);
                                                            echo htmlspecialchars($cleanName);
                                                            ?>
                                                        </span>
                                                        <span class="question-type"><?= htmlspecialchars($de['valueType']) ?></span>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                </div> <!-- End tracker-preview-container -->
                        </div>
                    </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="argon-dashboard-master/assets/js/core/popper.min.js"></script>
    <script src="argon-dashboard-master/assets/js/core/bootstrap.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/smooth-scrollbar.min.js"></script>
    
    <!-- Program Data for JavaScript -->
    <script type="application/json" id="programData">
        <?= json_encode([
            'program' => $trackerProgram,
            'surveySettings' => array_merge($surveySettings, [
                'survey_id' => $surveyId
            ])
        ]) ?>
    </script>
    
    <script>
        // Load program data from JSON script tag
        let programData = null;
        try {
            const programDataScript = document.getElementById('programData');
            if (programDataScript) {
                programData = JSON.parse(programDataScript.textContent);
                console.log('Program data loaded successfully:', programData);
            } else {
                console.error('Program data script tag not found');
            }
        } catch (error) {
            console.error('Error parsing program data:', error);
        }
        
        // Function to save location settings
        async function saveLocationSettings() {
            const surveyId = <?= json_encode($surveyId) ?>;
            const instanceKey = document.getElementById('control-instance-key-select').value;
            const hierarchyLevel = document.getElementById('control-hierarchy-level-select').value;
            
            try {
                const response = await fetch('save_location_settings.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `survey_id=${surveyId}&selected_instance_key=${encodeURIComponent(instanceKey)}&selected_hierarchy_level=${encodeURIComponent(hierarchyLevel)}`
                });

                const result = await response.json();
                
                if (result.success) {
                    showToast('Location settings saved successfully!', 'success');
                    console.log('Location settings saved:', result.data);
                    
                    // Don't reload page - settings will persist automatically
                } else {
                    throw new Error(result.error || 'Failed to save settings');
                }
            } catch (error) {
                console.error('Error saving location settings:', error);
                showToast('Error saving location settings: ' + error.message, 'error');
            }
        }

        // Function to show toast notifications
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast-notification ${type}`;
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? '#28a745' : '#dc3545'};
                color: white;
                padding: 15px 20px;
                border-radius: 5px;
                font-size: 14px;
                z-index: 10000;
                transform: translateX(100%);
                transition: transform 0.3s ease;
            `;
            toast.textContent = message;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.transform = 'translateX(0)';
            }, 100);
            
            setTimeout(() => {
                toast.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                }, 300);
            }, 3000);
        }

        // Function to copy share link to clipboard
        function copyShareLink() {
            const surveyId = <?= json_encode($surveyId) ?>;
            const scheme = window.location.protocol;
            const host = window.location.host;
            const shareUrl = `${scheme}//${host}/share/t/${surveyId}`;
            
            console.log('Attempting to copy URL:', shareUrl); // Debug log
            
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(shareUrl).then(function() {
                    console.log('Successfully copied to clipboard'); // Debug log
                    showToast('✅ Share link copied to clipboard!', 'success');
                }, function(err) {
                    console.error('Clipboard API failed:', err);
                    fallbackCopyTextToClipboard(shareUrl);
                });
            } else {
                console.log('Clipboard API not available, using fallback'); // Debug log
                fallbackCopyTextToClipboard(shareUrl);
            }
        }

        function fallbackCopyTextToClipboard(text) {
            const textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.style.top = "0";
            textArea.style.left = "0";
            textArea.style.position = "fixed";

            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();

            try {
                const successful = document.execCommand('copy');
                console.log('Fallback copy result:', successful); // Debug log
                if (successful) {
                    showToast('✅ Share link copied to clipboard!', 'success');
                } else {
                    showToast('❌ Failed to copy share link', 'error');
                }
            } catch (err) {
                console.error('Fallback: Oops, unable to copy', err);
                showToast('❌ Failed to copy share link', 'error');
            }

            document.body.removeChild(textArea);
        }

        function showToast(message, type = 'success') {
            // Remove any existing toasts
            const existingToasts = document.querySelectorAll('.toast-notification');
            existingToasts.forEach(toast => toast.remove());
            
            const toast = document.createElement('div');
            toast.className = 'toast-notification' + (type === 'error' ? ' error' : '');
            toast.textContent = message;

            document.body.appendChild(toast);
            
            // Force reflow and add show class
            setTimeout(() => {
                toast.classList.add('show');
            }, 10);
            
            // Hide and remove after 3 seconds
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => {
                    if (toast.parentNode) {
                        document.body.removeChild(toast);
                    }
                }, 300);
            }, 3000);
        }

        // Question Grouping Functionality
        let groupingMode = false;
        let groupCounter = 0;
        let draggedItem = null;
        let questionGroupings = {}; // Store groupings per stage

        async function loadGroupingsFromDatabase() {
            const surveyId = <?= json_encode($surveyId) ?>;
            
            try {
                const response = await fetch(`api/question_groupings.php?survey_id=${surveyId}`);
                const result = await response.json();
                
                if (result.success && result.groupings) {
                    console.log('✅ Loading existing groupings from database');
                    
                    // Apply groupings to the UI
                    Object.keys(result.groupings).forEach(stageId => {
                        const stageGroups = result.groupings[stageId];
                        const stageContainer = document.querySelector(`[data-stage-id="${stageId}"]`);
                        
                        if (!stageContainer) return;
                        
                        const groupingView = stageContainer.querySelector('.stage-grouping-view');
                        
                        // Clear existing custom groups (keep only default)
                        const customGroups = groupingView.querySelectorAll('.custom-group');
                        customGroups.forEach(group => group.remove());
                        
                        // Process each group from database
                        stageGroups.forEach(groupData => {
                            if (!groupData.isDefault && groupData.groupName !== 'Ungrouped Questions') {
                                // Create custom group with questions
                                addGroupToStageFromDB(stageContainer, groupData.groupName, groupData.questions);
                            }
                        });
                    });
                    
                    // Reinitialize drag and drop
                    setTimeout(() => {
                        initializeDragAndDrop();
                    }, 200);
                    
                } else if (result.success) {
                    console.log('No existing groupings found in database');
                }
            } catch (error) {
                console.error('Error loading groupings from database:', error);
            }
        }

        function addGroupToStageFromDB(stageContainer, groupName, questions) {
            // Use existing addGroupToStage function then move questions
            addGroupToStage(stageContainer, groupName);
            
            // Find the newly created group
            const newGroups = stageContainer.querySelectorAll('.custom-group');
            const newGroup = newGroups[newGroups.length - 1]; // Get the last added group
            
            if (newGroup && questions && questions.length > 0) {
                const groupQuestions = newGroup.querySelector('.group-questions');
                
                // Remove placeholder message
                const placeholder = groupQuestions.querySelector('p');
                if (placeholder) placeholder.remove();
                
                // Move questions to this group
                questions.forEach(questionData => {
                    const questionId = questionData.questionId;
                    const questionItem = document.querySelector(`[data-question-id="${questionId}"]`);
                    
                    if (questionItem) {
                        // Clone the question for grouping view
                        const questionClone = questionItem.cloneNode(true);
                        groupQuestions.appendChild(questionClone);
                    }
                });
            }
        }

        function toggleGroupingMode() {
            groupingMode = !groupingMode;
            const groupingModeText = document.getElementById('groupingModeText');
            const groupingInterface = document.getElementById('groupingInterface');
            const normalViews = document.querySelectorAll('.stage-normal-view');
            const groupingViews = document.querySelectorAll('.stage-grouping-view');

            if (groupingMode) {
                groupingModeText.textContent = 'Disable Grouping';
                groupingInterface.style.display = 'block';
                normalViews.forEach(view => view.style.display = 'none');
                groupingViews.forEach(view => view.style.display = 'block');
                initializeDragAndDrop();
                
                // Load existing groupings from database
                loadGroupingsFromDatabase();
                
                // Populate available questions for all stages
                setTimeout(() => {
                    const stageContainers = document.querySelectorAll('.stage-container');
                    stageContainers.forEach(container => {
                        const stageId = container.getAttribute('data-stage-id');
                        populateAvailableQuestions(stageId);
                    });
                }, 500);
            } else {
                groupingModeText.textContent = 'Enable Grouping';
                groupingInterface.style.display = 'none';
                normalViews.forEach(view => view.style.display = 'block');
                groupingViews.forEach(view => view.style.display = 'none');
            }
        }

        function createNewGroup() {
            const groupName = prompt('Enter group heading/name:');
            if (!groupName || groupName.trim() === '') {
                alert('Please enter a valid group name.');
                return;
            }

            // For simplicity, add group to all stages (or let user choose per stage)
            const stages = document.querySelectorAll('.stage-container');
            if (stages.length === 1) {
                // Only one stage, add to it directly
                addGroupToStage(stages[0], groupName.trim());
            } else {
                // Multiple stages, let user choose
                const stageOptions = Array.from(stages).map((stage, index) => {
                    const stageName = stage.querySelector('.stage-name').textContent.trim();
                    return `${index + 1}. ${stageName}`;
                }).join('\n');

                const stageChoice = prompt(`Select stage to add group:\n${stageOptions}\n\nEnter stage number (or 0 for all stages):`);
                
                if (stageChoice === '0') {
                    // Add to all stages
                    stages.forEach(stage => addGroupToStage(stage, groupName.trim()));
                } else {
                    const stageIndex = parseInt(stageChoice) - 1;
                    if (stageIndex >= 0 && stageIndex < stages.length) {
                        addGroupToStage(stages[stageIndex], groupName.trim());
                    } else {
                        alert('Invalid stage selection.');
                    }
                }
            }
        }

        function addGroupToStage(stageContainer, groupName) {
            const stageId = stageContainer.dataset.stageId;
            const groupingView = stageContainer.querySelector('.stage-grouping-view');
            groupCounter++;
            const groupId = `custom_${stageId}_${groupCounter}`;

            const groupHtml = `
                <div class="question-group custom-group mb-3" data-group-id="${groupId}">
                    <div class="group-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">
                                <i class="fas fa-folder-open text-info me-2"></i>
                                <span class="group-title" onclick="editGroupName('${groupId}')" style="cursor: pointer;" title="Click to edit">${groupName}</span>
                            </h6>
                            <div class="group-controls">
                                <button class="btn btn-outline-primary btn-sm me-2" onclick="toggleGroupSearch('${groupId}', '${stageId}')" title="Search and add questions">
                                    <i class="fas fa-search-plus"></i>
                                </button>
                                <button class="edit-group-btn" onclick="editGroupName('${groupId}')" title="Edit name">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="delete-group-btn" onclick="deleteGroup('${groupId}')" title="Delete group">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Group Search Panel (Initially Hidden) -->
                    <div class="group-search-panel" id="searchPanel_${groupId}" style="display: none;">
                        <div class="card bg-light mt-2 mb-3">
                            <div class="card-body p-3">
                                <div class="mb-2">
                                    <input type="text" class="form-control form-control-sm" 
                                           placeholder="Search questions to add to this group..." 
                                           id="groupSearch_${groupId}" 
                                           onkeyup="filterGroupAvailableQuestions('${groupId}', '${stageId}')">
                                </div>
                                <div class="group-available-questions" 
                                     id="groupAvailable_${groupId}" 
                                     style="max-height: 150px; overflow-y: auto;">
                                    <!-- Available questions for this group -->
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="group-questions" data-group="${groupId}">
                        <p class="text-muted text-center py-3 mb-0">
                            <i class="fas fa-search-plus me-2"></i>Drag questions here or click the search button above to add questions
                        </p>
                    </div>
                </div>
            `;

            groupingView.insertAdjacentHTML('beforeend', groupHtml);
            initializeDragAndDrop();
        }

        function editGroupName(groupId) {
            const groupElement = document.querySelector(`[data-group-id="${groupId}"]`);
            const titleElement = groupElement.querySelector('.group-title');
            const currentName = titleElement.textContent.trim();
            
            const newName = prompt('Enter new group name:', currentName);
            if (newName && newName.trim() !== '' && newName.trim() !== currentName) {
                titleElement.textContent = newName.trim();
            }
        }

        function deleteGroup(groupId) {
            if (confirm('Are you sure you want to delete this group? Questions will be moved back to ungrouped.')) {
                const groupElement = document.querySelector(`[data-group-id="${groupId}"]`);
                const questions = groupElement.querySelectorAll('.question-item');
                
                // Move questions back to default group
                const stageId = groupId.split('_')[1];
                const defaultGroup = document.querySelector(`[data-group="default_${stageId}"]`);
                const emptyMessage = defaultGroup.querySelector('p');
                if (emptyMessage) emptyMessage.remove();
                
                questions.forEach(question => {
                    defaultGroup.appendChild(question);
                });
                
                groupElement.remove();
            }
        }

        function initializeDragAndDrop() {
            const questionItems = document.querySelectorAll('.question-item');
            const dropZones = document.querySelectorAll('.group-questions');

            questionItems.forEach(item => {
                item.addEventListener('dragstart', handleDragStart);
                item.addEventListener('dragend', handleDragEnd);
            });

            dropZones.forEach(zone => {
                zone.addEventListener('dragover', handleDragOver);
                zone.addEventListener('drop', handleDrop);
                zone.addEventListener('dragenter', handleDragEnter);
                zone.addEventListener('dragleave', handleDragLeave);
            });
        }

        function handleDragStart(e) {
            draggedItem = this;
            this.style.opacity = '0.5';
            e.dataTransfer.effectAllowed = 'move';
        }

        function handleDragEnd(e) {
            this.style.opacity = '1';
            draggedItem = null;
            // Remove drag-over class from all groups
            document.querySelectorAll('.question-group').forEach(group => {
                group.classList.remove('drag-over');
            });
        }

        function handleDragOver(e) {
            if (e.preventDefault) {
                e.preventDefault(); // Allows us to drop
            }
            e.dataTransfer.dropEffect = 'move';
            return false;
        }

        function handleDragEnter(e) {
            this.closest('.question-group').classList.add('drag-over');
        }

        function handleDragLeave(e) {
            this.closest('.question-group').classList.remove('drag-over');
        }

        function handleDrop(e) {
            if (e.stopPropagation) {
                e.stopPropagation(); // Stops some browsers from redirecting
            }

            if (draggedItem !== null) {
                // Remove empty message if it exists
                const emptyMessage = this.querySelector('p');
                if (emptyMessage && emptyMessage.textContent.includes('Drag questions here')) {
                    emptyMessage.remove();
                }

                this.appendChild(draggedItem);
                this.closest('.question-group').classList.remove('drag-over');
            }

            return false;
        }

        async function saveGrouping() {
            const stages = document.querySelectorAll('.stage-container');
            // Convert grouping data to the format expected by the API
            const formattedGroupings = {};
            
            stages.forEach(stage => {
                const stageId = stage.dataset.stageId;
                formattedGroupings[stageId] = [];
                
                const groups = stage.querySelectorAll('.question-group');
                groups.forEach((group, groupIndex) => {
                    const groupTitle = group.querySelector('.group-title').textContent.trim();
                    const isDefault = group.dataset.groupId && group.dataset.groupId.startsWith('default_');
                    const questions = Array.from(group.querySelectorAll('.question-item')).map((item, questionIndex) => ({
                        questionId: item.dataset.questionId,
                        questionOrder: questionIndex
                    }));
                    
                    // Always include groups, even if empty
                    formattedGroupings[stageId].push({
                        groupName: groupTitle,
                        isDefault: isDefault,
                        questions: questions
                    });
                });
            });

            // Save to database via API
            const surveyId = <?= json_encode($surveyId) ?>;
            
            try {
                const response = await fetch('api/question_groupings.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        survey_id: surveyId,
                        groupings: formattedGroupings
                    })
                });

                const result = await response.json();
                
                if (result.success) {
                    alert('✅ Grouping saved successfully to database! The form will now display questions in these groups for all users.');
                    console.log('Saved grouping to database:', formattedGroupings);
                    
                    // Database save successful
                } else {
                    alert('❌ Error saving grouping: ' + result.error);
                    console.error('Database save error:', result.error);
                }
            } catch (error) {
                console.error('Error saving grouping to database:', error);
                
                alert('⚠️ Failed to save to database. Please check your connection and try again.');
            }
        }

        function resetGrouping() {
            if (confirm('Are you sure you want to reset all groupings? This will remove all custom groups.')) {
                // Move all questions back to default groups
                const stages = document.querySelectorAll('.stage-container');
                stages.forEach(stage => {
                    const stageId = stage.dataset.stageId;
                    const defaultGroup = stage.querySelector(`[data-group="default_${stageId}"]`);
                    const customGroups = stage.querySelectorAll('.custom-group');
                    
                    // Remove empty message from default group
                    const emptyMessage = defaultGroup.querySelector('p');
                    if (emptyMessage) emptyMessage.remove();
                    
                    // Move questions from custom groups to default
                    customGroups.forEach(customGroup => {
                        const questions = customGroup.querySelectorAll('.question-item');
                        questions.forEach(question => {
                            defaultGroup.appendChild(question);
                        });
                        customGroup.remove();
                    });
                });

                // Clear saved grouping from database
                const surveyId = <?= json_encode($surveyId) ?>;
                
                // Clear from database
                fetch(`api/question_groupings.php`, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        survey_id: surveyId
                    })
                }).then(response => response.json())
                  .then(result => {
                      if (result.success) {
                          alert('Grouping reset successfully for all users!');
                      } else {
                          alert('Grouping reset locally. Database error: ' + result.error);
                      }
                  })
                  .catch(error => {
                      console.error('Error clearing grouping from database:', error);
                      alert('Grouping reset locally. Please check your connection.');
                  });
            }
        }

        // Check if existing groupings exist and automatically enable grouping mode
        async function checkAndLoadExistingGroupings() {
            const surveyId = <?= json_encode($surveyId) ?>;
            
            try {
                const response = await fetch(`api/question_groupings.php?survey_id=${surveyId}`);
                const result = await response.json();
                
                if (result.success && result.groupings && Object.keys(result.groupings).length > 0) {
                    // Check if any stage has actual groups (not just empty)
                    const hasActualGroups = Object.values(result.groupings).some(stageGroups => 
                        stageGroups && stageGroups.length > 0
                    );
                    
                    if (hasActualGroups) {
                        console.log('✅ Found existing groupings, automatically enabling grouping mode');
                        // Automatically enable grouping mode
                        groupingMode = true;
                        const groupingModeText = document.getElementById('groupingModeText');
                        const groupingInterface = document.getElementById('groupingInterface');
                        const normalViews = document.querySelectorAll('.normal-view');
                        const groupingViews = document.querySelectorAll('.grouping-view');
                        
                        if (groupingModeText) groupingModeText.textContent = 'Disable Grouping';
                        if (groupingInterface) groupingInterface.style.display = 'block';
                        normalViews.forEach(view => view.style.display = 'none');
                        groupingViews.forEach(view => view.style.display = 'block');
                        
                        // Initialize drag and drop and load the groupings
                        initializeDragAndDrop();
                        await loadGroupingsFromDatabase();
                        
                        // Populate available questions
                        setTimeout(() => {
                            const stageContainers = document.querySelectorAll('.stage-container');
                            stageContainers.forEach(container => {
                                const stageId = container.getAttribute('data-stage-id');
                                populateAvailableQuestions(stageId);
                            });
                        }, 500);
                    }
                }
            } catch (error) {
                console.error('Error checking for existing groupings:', error);
            }
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', async function() {
            console.log('Tracker preview JavaScript loaded successfully');
            
            // Test if the grouping elements exist
            const groupingButton = document.getElementById('groupingModeText');
            const groupingInterface = document.getElementById('groupingInterface');
            console.log('Grouping button found:', !!groupingButton);
            console.log('Grouping interface found:', !!groupingInterface);
            
            // Check if there are existing groupings and show them automatically
            await checkAndLoadExistingGroupings();
        });

        // Survey Settings Management Functions
        let settingsMode = false;

        function toggleSettingsMode() {
            settingsMode = !settingsMode;
            const settingsModeText = document.getElementById('settingsModeText');
            const settingsInterface = document.getElementById('settingsInterface');

            if (settingsMode) {
                settingsModeText.textContent = 'Hide Settings';
                settingsInterface.style.display = 'block';
                loadTrackerSettings(); // Load existing settings
            } else {
                settingsModeText.textContent = 'Configure Settings';
                settingsInterface.style.display = 'none';
            }
        }

        // Dynamic Image Management
        function updateImageFields() {
            const count = parseInt(document.getElementById('imageCount').value);
            const container = document.getElementById('imageFieldsContainer');
            
            // Store existing values before clearing
            const existingValues = {};
            for (let i = 1; i <= 10; i++) { // Check up to 10 images
                const pathEl = document.getElementById(`imagePath${i}`);
                const altEl = document.getElementById(`imageAlt${i}`);
                const widthEl = document.getElementById(`imageWidth${i}`);
                const heightEl = document.getElementById(`imageHeight${i}`);
                const positionEl = document.getElementById(`imagePosition${i}`);
                
                if (pathEl) {
                    existingValues[i] = {
                        path: pathEl.value,
                        alt: altEl ? altEl.value : '',
                        width: widthEl ? widthEl.value : '100',
                        height: heightEl ? heightEl.value : '60',
                        position: positionEl ? positionEl.value : 'center'
                    };
                }
            }
            
            container.innerHTML = '';
            
            for (let i = 1; i <= count; i++) {
                const imageField = document.createElement('div');
                imageField.className = 'card mb-3';
                imageField.innerHTML = `
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-image me-1"></i> Image ${i}</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="imagePath${i}" class="form-label">Image Path</label>
                                    <input type="text" class="form-control" id="imagePath${i}" placeholder="asets/img/image${i}.jpg">
                                    <div class="form-text">Path relative to admin folder</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="imageWidth${i}" class="form-label">Width (px)</label>
                                    <input type="number" class="form-control" id="imageWidth${i}" value="100" min="50" max="500">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="imageHeight${i}" class="form-label">Height (px)</label>
                                    <input type="number" class="form-control" id="imageHeight${i}" value="60" min="30" max="300">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="imageAlt${i}" class="form-label">Alt Text</label>
                                    <input type="text" class="form-control" id="imageAlt${i}" placeholder="Image ${i} description">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="imagePosition${i}" class="form-label">Position</label>
                                    <select class="form-control" id="imagePosition${i}">
                                        <option value="left">Left</option>
                                        <option value="center" ${i === 1 ? 'selected' : ''}>Center</option>
                                        <option value="right">Right</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center">
                            <input type="file" class="form-control me-2" id="imageUpload${i}" accept="image/*" onchange="handleImageUpload(${i})">
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="previewImage(${i})">
                                <i class="fas fa-eye"></i> Preview
                            </button>
                        </div>
                        <div id="imagePreview${i}" class="mt-2" style="display: none;">
                            <img id="previewImg${i}" src="" alt="Preview" style="max-width: 200px; max-height: 100px; border: 1px solid #ddd; border-radius: 4px;">
                        </div>
                    </div>
                `;
                
                container.appendChild(imageField);
                
                // Restore existing values if they exist
                if (existingValues[i]) {
                    const values = existingValues[i];
                    document.getElementById(`imagePath${i}`).value = values.path;
                    document.getElementById(`imageAlt${i}`).value = values.alt;
                    document.getElementById(`imageWidth${i}`).value = values.width;
                    document.getElementById(`imageHeight${i}`).value = values.height;
                    document.getElementById(`imagePosition${i}`).value = values.position;
                }
            }
        }

        async function handleImageUpload(imageNumber) {
            const fileInput = document.getElementById(`imageUpload${imageNumber}`);
            const file = fileInput.files[0];
            
            if (file) {
                // Create FormData for actual file upload
                const formData = new FormData();
                formData.append('image', file);
                formData.append('survey_id', <?= json_encode($surveyId) ?>);
                formData.append('image_number', imageNumber);
                
                try {
                    // Show loading state
                    const pathInput = document.getElementById(`imagePath${imageNumber}`);
                    pathInput.value = 'Uploading...';
                    pathInput.disabled = true;
                    
                    // Upload file to server
                    const response = await fetch('api/upload_image.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        // Set the actual uploaded file path
                        pathInput.value = result.path;
                        pathInput.disabled = false;
                        
                        // Show preview using the uploaded file
                        const previewImg = document.getElementById(`previewImg${imageNumber}`);
                        previewImg.src = result.url + '?v=' + Date.now(); // Cache busting
                        document.getElementById(`imagePreview${imageNumber}`).style.display = 'block';
                        
                        // Set default alt text from filename
                        if (!document.getElementById(`imageAlt${imageNumber}`).value) {
                            document.getElementById(`imageAlt${imageNumber}`).value = file.name.replace(/\.[^/.]+$/, "");
                        }
                        
                        showToast('Image uploaded successfully!', 'success');
                    } else {
                        pathInput.value = '';
                        pathInput.disabled = false;
                        showToast('Upload failed: ' + result.error, 'error');
                    }
                } catch (error) {
                    console.error('Upload error:', error);
                    document.getElementById(`imagePath${imageNumber}`).value = '';
                    document.getElementById(`imagePath${imageNumber}`).disabled = false;
                    showToast('Upload failed: ' + error.message, 'error');
                }
            }
        }

        function previewImage(imageNumber) {
            const imagePath = document.getElementById(`imagePath${imageNumber}`).value;
            if (imagePath) {
                const previewImg = document.getElementById(`previewImg${imageNumber}`);
                previewImg.src = `/fbs/admin/${imagePath}`;
                document.getElementById(`imagePreview${imageNumber}`).style.display = 'block';
                
                previewImg.onerror = function() {
                    alert(`Could not load image: ${imagePath}`);
                    document.getElementById(`imagePreview${imageNumber}`).style.display = 'none';
                };
            } else {
                alert('Please enter an image path first');
            }
        }

        async function saveSettings() {
            const surveyId = <?= json_encode($surveyId) ?>;
            
            // Collect image data
            const images = [];
            const imageCount = parseInt(document.getElementById('imageCount').value);
            
            for (let i = 1; i <= imageCount; i++) {
                const imagePath = document.getElementById(`imagePath${i}`).value;
                if (imagePath) {
                    images.push({
                        order: i,
                        image_path: imagePath,
                        alt_text: document.getElementById(`imageAlt${i}`).value || '',
                        width: parseInt(document.getElementById(`imageWidth${i}`).value) || 100,
                        height: parseInt(document.getElementById(`imageHeight${i}`).value) || 60,
                        position: document.getElementById(`imagePosition${i}`).value || 'center'
                    });
                }
            }
            
            const settingsData = {
                survey_id: surveyId,
                layout_type: document.getElementById('layoutType') ? document.getElementById('layoutType').value : 'horizontal',
                show_flag_bar: document.getElementById('showFlagBar').checked,
                flag_black_color: document.getElementById('flagBlackColor').value,
                flag_yellow_color: document.getElementById('flagYellowColor').value,
                flag_red_color: document.getElementById('flagRedColor').value,
                images: images
            };

            try {
                const response = await fetch('api/settings.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(settingsData)
                });

                const result = await response.json();
                
                if (result.success) {
                    alert('Settings saved successfully! The tracker form will now use these settings.');
                    console.log('Saved settings:', settingsData);
                    
                    // Update the preview with the new settings
                    updatePreviewAfterSave(settingsData);
                } else {
                    alert('Error saving settings: ' + result.error);
                    console.error('Settings save error:', result.error);
                }
            } catch (error) {
                console.error('Error saving settings:', error);
                alert('Error saving settings. Please check your connection and try again.');
            }
        }
        
        // Load settings from API
        async function loadTrackerSettings() {
            const surveyId = <?= json_encode($surveyId) ?>;
            
            try {
                const response = await fetch(`api/settings.php?survey_id=${surveyId}`);
                const result = await response.json();
                
                if (result.success && result.data) {
                    const data = result.data;
                    
                    // Set layout settings
                    if (document.getElementById('layoutType')) {
                        document.getElementById('layoutType').value = data.layout_type || 'horizontal';
                    }
                    
                    // Set flag bar settings
                    document.getElementById('showFlagBar').checked = data.show_flag_bar !== false;
                    document.getElementById('flagBlackColor').value = data.flag_black_color || '#000000';
                    document.getElementById('flagYellowColor').value = data.flag_yellow_color || '#FCD116';
                    document.getElementById('flagRedColor').value = data.flag_red_color || '#D21034';
                    
                    // Set image settings
                    if (data.images && data.images.length > 0) {
                        document.getElementById('imageCount').value = data.images.length;
                        updateImageFields();
                        
                        // Populate image data
                        data.images.forEach((image, index) => {
                            const i = index + 1;
                            if (document.getElementById(`imagePath${i}`)) {
                                document.getElementById(`imagePath${i}`).value = image.image_path || '';
                                document.getElementById(`imageAlt${i}`).value = image.alt_text || '';
                                document.getElementById(`imageWidth${i}`).value = image.width || 100;
                                document.getElementById(`imageHeight${i}`).value = image.height || 60;
                                document.getElementById(`imagePosition${i}`).value = image.position || 'center';
                            }
                        });
                    } else {
                        document.getElementById('imageCount').value = '0';
                        updateImageFields();
                    }
                }
            } catch (error) {
                console.error('Error loading settings:', error);
                // Use default values if loading fails
            }
        }

        // Group-Specific Search and Add Functionality
        function toggleGroupSearch(groupId, stageId) {
            const searchPanel = document.getElementById(`searchPanel_${groupId}`);
            const isVisible = searchPanel.style.display !== 'none';
            
            if (isVisible) {
                searchPanel.style.display = 'none';
            } else {
                searchPanel.style.display = 'block';
                populateGroupAvailableQuestions(groupId, stageId);
            }
        }
        
        function populateGroupAvailableQuestions(groupId, stageId) {
            const container = document.getElementById(`groupAvailable_${groupId}`);
            
            // Check if programData is available
            if (typeof programData === 'undefined' || !programData || !programData.program) {
                container.innerHTML = '<div class="no-available-questions">Program data not loaded yet. Please refresh the page.</div>';
                console.warn('programData not available for stage:', stageId);
                return;
            }
            
            // Get all data elements for this stage from the program data
            const stages = programData.program.programStages;
            const currentStage = stages.find(stage => stage.id === stageId);
            
            if (!currentStage || !currentStage.programStageDataElements) {
                container.innerHTML = '<div class="no-available-questions">No questions available</div>';
                return;
            }

            const dataElements = currentStage.programStageDataElements;
            let availableHtml = '';

            dataElements.forEach((element, index) => {
                const dataElement = element.dataElement;
                const questionText = dataElement.displayName || dataElement.name;
                const valueType = dataElement.valueType;
                const elementId = dataElement.id;

                // Check if this question is already assigned to ANY group
                const isAssigned = document.querySelector(`[data-question-id="${elementId}"]`) !== null;

                if (!isAssigned) {
                    availableHtml += `
                        <div class="available-question-item" 
                             data-question-id="${elementId}" 
                             data-question-text="${questionText}"
                             data-value-type="${valueType}">
                            <div class="available-question-text">${questionText}</div>
                            <div class="available-question-meta">
                                <span class="available-question-type">${valueType}</span>
                                <span>ID: ${elementId.substring(0, 8)}...</span>
                            </div>
                            <button class="add-to-group-btn" 
                                    onclick="addQuestionToSpecificGroup('${groupId}', '${stageId}', '${elementId}', '${questionText}', ${index})"
                                    title="Add to this group">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    `;
                }
            });

            if (availableHtml === '') {
                container.innerHTML = '<div class="no-available-questions">All questions have been assigned to groups</div>';
            } else {
                container.innerHTML = availableHtml;
            }
        }

        function filterGroupAvailableQuestions(groupId, stageId) {
            const searchTerm = document.getElementById(`groupSearch_${groupId}`).value.toLowerCase();
            const questionItems = document.querySelectorAll(`#groupAvailable_${groupId} .available-question-item`);

            questionItems.forEach(item => {
                const questionText = item.getAttribute('data-question-text').toLowerCase();
                const valueType = item.getAttribute('data-value-type').toLowerCase();
                const questionId = item.getAttribute('data-question-id').toLowerCase();

                if (questionText.includes(searchTerm) || 
                    valueType.includes(searchTerm) || 
                    questionId.includes(searchTerm)) {
                    item.classList.remove('hidden');
                } else {
                    item.classList.add('hidden');
                }
            });
        }

        function addQuestionToSpecificGroup(groupId, stageId, questionId, questionText, questionIndex) {
            const group = document.querySelector(`[data-group-id="${groupId}"]`);
            
            if (!group) {
                alert('Group not found!');
                return;
            }

            // Create the question element
            const questionElement = document.createElement('div');
            questionElement.className = 'question-item';
            questionElement.draggable = true;
            questionElement.setAttribute('data-question-id', questionId);
            questionElement.setAttribute('data-question-index', questionIndex);
            
            questionElement.innerHTML = `
                <div class="d-flex justify-content-between align-items-center">
                    <span class="question-text">${questionText}</span>
                    <button class="btn btn-sm btn-outline-danger remove-question-btn" 
                            onclick="removeQuestionFromGroup(this)" 
                            title="Remove from group">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;

            // Add drag and drop event listeners
            questionElement.addEventListener('dragstart', handleDragStart);
            questionElement.addEventListener('dragend', handleDragEnd);

            // Remove any empty message
            const groupQuestions = group.querySelector('.group-questions');
            const emptyMessage = groupQuestions.querySelector('p');
            if (emptyMessage) {
                emptyMessage.remove();
            }

            // Add to group
            groupQuestions.appendChild(questionElement);

            // Remove from available questions in this group's search panel
            const availableItem = document.querySelector(`#groupAvailable_${groupId} [data-question-id="${questionId}"]`);
            if (availableItem) {
                availableItem.remove();
            }
            
            // Refresh all other group search panels to remove this question
            document.querySelectorAll('.group-search-panel').forEach(panel => {
                const otherGroupId = panel.id.replace('searchPanel_', '');
                if (otherGroupId !== groupId && panel.style.display !== 'none') {
                    const container = panel.querySelector('.group-available-questions');
                    const itemToRemove = container.querySelector(`[data-question-id="${questionId}"]`);
                    if (itemToRemove) {
                        itemToRemove.remove();
                    }
                }
            });

            // Show success message
            showToast(`Question "${questionText}" added to group successfully!`, 'success');
        }

        function addQuestionToGroup(stageId, groupId, questionId, questionText, questionIndex) {
            const group = document.querySelector(`[data-group-id="${groupId}"]`);
            
            if (!group) {
                alert('Group not found!');
                return;
            }

            // Create the question element
            const questionElement = document.createElement('div');
            questionElement.className = 'question-item';
            questionElement.draggable = true;
            questionElement.setAttribute('data-question-id', questionId);
            questionElement.setAttribute('data-question-index', questionIndex);
            
            questionElement.innerHTML = `
                <div class="d-flex justify-content-between align-items-center">
                    <span class="question-text">${questionText}</span>
                    <button class="btn btn-sm btn-outline-danger remove-question-btn" 
                            onclick="removeQuestionFromGroup(this)" 
                            title="Remove from group">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;

            // Add drag and drop event listeners
            questionElement.addEventListener('dragstart', handleDragStart);
            questionElement.addEventListener('dragend', handleDragEnd);

            // Remove any empty message
            const emptyMessage = group.querySelector('p');
            if (emptyMessage && emptyMessage.textContent.includes('Drag questions here')) {
                emptyMessage.remove();
            }

            // Add to group
            group.appendChild(questionElement);

            // Remove from available questions
            const availableItem = document.querySelector(`#availableQuestions_${stageId} [data-question-id="${questionId}"]`);
            if (availableItem) {
                availableItem.remove();
            }

            // Show success message
            showToast(`Question "${questionText}" added to group successfully!`, 'success');
        }

        function removeQuestionFromGroup(button) {
            const questionItem = button.closest('.question-item');
            const questionId = questionItem.getAttribute('data-question-id');
            const questionText = questionItem.querySelector('.question-text').textContent;
            const group = questionItem.closest('.question-group');
            const stageContainer = group.closest('.stage-container');
            const stageId = stageContainer.getAttribute('data-stage-id');

            // Remove the question item
            questionItem.remove();

            // If group is now empty, show empty message
            const remainingQuestions = group.querySelectorAll('.question-item');
            if (remainingQuestions.length === 0) {
                const emptyMessage = document.createElement('p');
                emptyMessage.className = 'text-muted mb-0';
                emptyMessage.innerHTML = '<i class="fas fa-mouse-pointer me-2"></i>Drag questions here or use the search panel above to add questions';
                group.appendChild(emptyMessage);
            }

            // Refresh available questions to show this question again
            populateAvailableQuestions(stageId);

            showToast(`Question "${questionText}" removed from group`, 'info');
        }

        // This function is defined elsewhere, so we need to modify it properly

        // Flag Settings Functions
        function updateFlagPreview() {
            const showFlagBar = document.getElementById('showFlagBar').checked;
            const flagPreview = document.getElementById('flagPreview');
            const flagControls = document.getElementById('flagColorControls');
            
            if (showFlagBar) {
                flagPreview.style.opacity = '1';
                flagControls.style.opacity = '1';
            } else {
                flagPreview.style.opacity = '0.3';
                flagControls.style.opacity = '0.5';
            }
            
            // Update preview colors
            const blackColor = document.getElementById('flagBlackColor').value;
            const yellowColor = document.getElementById('flagYellowColor').value;
            const redColor = document.getElementById('flagRedColor').value;
            
            document.getElementById('flagBlackPreview').style.backgroundColor = blackColor;
            document.getElementById('flagYellowPreview').style.backgroundColor = yellowColor;
            document.getElementById('flagRedPreview').style.backgroundColor = redColor;
            
            // Update text inputs
            document.getElementById('flagBlackColorText').value = blackColor;
            document.getElementById('flagYellowColorText').value = yellowColor;
            document.getElementById('flagRedColorText').value = redColor;
        }
        
        // Function to update preview after settings are saved
        function updatePreviewAfterSave(settingsData) {
            // Update flag bar in preview
            updateFlagPreview();
            
            // Update dynamic images in preview
            if (settingsData.images && settingsData.images.length > 0) {
                updatePreviewImages(settingsData.images, settingsData.layout_type);
            } else {
                // If no images, show no images message
                const previewContainer = document.getElementById('previewDynamicImages');
                if (previewContainer) {
                    previewContainer.innerHTML = `
                        <div class="no-images-message text-muted" style="font-size: 14px; margin: 10px 0;">
                            <i class="fas fa-info-circle me-2"></i>No dynamic images configured - use settings to add images
                        </div>
                    `;
                }
            }
        }
        
        // Function to update preview images
        function updatePreviewImages(images, layoutType) {
            const previewContainer = document.getElementById('previewDynamicImages');
            if (!previewContainer) return;
            
            if (images && images.length > 0) {
                let imagesHtml = `<div class="preview-images-container ${layoutType}" id="previewImagesContainer">`;
                
                images.forEach(image => {
                    imagesHtml += `
                        <div class="preview-image-item position-${image.position_type}">
                            <img src="${image.image_path}?v=${Date.now()}" 
                                 alt="${image.image_alt_text}"
                                 style="width: 50px; height: 35px; border-radius: 4px; object-fit: contain; border: 1px solid #ddd;"
                                 onerror="this.style.border='1px solid red'; this.alt='❌';" 
                                 title="${image.image_alt_text}">
                        </div>
                    `;
                });
                
                imagesHtml += '</div>';
                previewContainer.innerHTML = imagesHtml;
            } else {
                previewContainer.innerHTML = `
                    <div class="no-images-message text-muted" style="font-size: 14px; margin: 10px 0;">
                        <i class="fas fa-info-circle me-2"></i>No dynamic images configured - use settings to add images
                    </div>
                `;
            }
        }
        
        function updateColorFromText(colorInputId, hexValue) {
            if (/^#[0-9A-F]{6}$/i.test(hexValue)) {
                document.getElementById(colorInputId).value = hexValue;
                updateFlagPreview();
            }
        }
        
        function resetToUgandaFlag() {
            document.getElementById('flagBlackColor').value = '#000000';
            document.getElementById('flagYellowColor').value = '#FCD116';
            document.getElementById('flagRedColor').value = '#D21034';
            document.getElementById('showFlagBar').checked = true;
            updateFlagPreview();
            showToast('Flag colors reset to Uganda national colors', 'info');
        }

        // Utility function to show toast messages
        function showToast(message, type = 'info') {
            // Create toast element
            const toast = document.createElement('div');
            toast.className = `alert alert-${type === 'success' ? 'success' : type === 'error' ? 'danger' : 'info'} alert-dismissible fade show position-fixed`;
            toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            toast.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;

            document.body.appendChild(toast);

            // Auto-remove after 3 seconds
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.remove();
                }
            }, 3000);
        }

        function resetSettings() {
            if (confirm('Are you sure you want to reset all settings to defaults?')) {
                // Reset flag settings
                document.getElementById('showFlagBar').checked = true;
                document.getElementById('flagBlackColor').value = '#000000';
                document.getElementById('flagYellowColor').value = '#FCD116';
                document.getElementById('flagRedColor').value = '#D21034';
                
                // Reset layout settings
                if (document.getElementById('layoutType')) {
                    document.getElementById('layoutType').value = 'horizontal';
                }
                
                // Reset image count
                document.getElementById('imageCount').value = '0';
                updateImageFields();
                
                // Update flag preview
                updateFlagPreview();
                
                alert('Settings reset to defaults. Click "Save Settings" to apply.');
            }
        }
    </script>
</body>
</html>