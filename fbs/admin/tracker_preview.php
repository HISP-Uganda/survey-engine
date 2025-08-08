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

            <!-- Location Filter Settings -->
            <div class="info-group">
                <h5><i class="fas fa-map-marker-alt me-2"></i>Location Settings</h5>
                <p class="text-muted small mb-3">Configure which locations appear in the form's location selector.</p>
                
                <div class="filter-group mb-3">
                    <label for="control-instance-key-select" class="form-label">Filter by Instance:</label>
                    <select id="control-instance-key-select" class="form-control">
                        <option value="">All Instances</option>
                        <?php foreach ($instanceKeys as $key): ?>
                            <option value="<?php echo htmlspecialchars($key); ?>" <?php echo (isset($surveySettings['selected_instance_key']) && $surveySettings['selected_instance_key'] == $key) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($key); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group mb-3">
                    <label for="control-hierarchy-level-select" class="form-label">Filter by Level:</label>
                    <select id="control-hierarchy-level-select" class="form-control">
                        <option value="">All Levels</option>
                        <?php foreach ($hierarchyLevels as $levelInt => $levelName): ?>
                            <option value="<?php echo htmlspecialchars($levelInt); ?>" <?php echo (isset($surveySettings['selected_hierarchy_level']) && $surveySettings['selected_hierarchy_level'] == $levelInt) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($levelName); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <button onclick="saveLocationSettings()" class="btn btn-primary btn-sm">
                    <i class="fas fa-save me-1"></i>Save Location Settings
                </button>
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
                        <a href="tracker_program_form.php?survey_id=<?= $surveyId ?>" class="btn btn-primary btn-sm" target="_blank">
                            <i class="fas fa-external-link-alt me-2"></i>Open Form
                        </a>
                    <?php endif; ?>
                    <button onclick="copyShareLink()" class="btn btn-info btn-sm">
                        <i class="fas fa-link me-2"></i>Copy Share Link
                    </button>
                    <a href="tracker_share.php?survey_id=<?= $surveyId ?>" class="btn btn-success btn-sm">
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
                            <!-- Flag Bar -->
                            <?php if ($surveySettings['show_flag_bar']): ?>
                                <div class="flag-bar" id="flag-bar">
                                    <div class="flag-section" style="background-color: <?= $surveySettings['flag_black_color'] ?>;"></div>
                                    <div class="flag-section" style="background-color: <?= $surveySettings['flag_yellow_color'] ?>;"></div>
                                    <div class="flag-section" style="background-color: <?= $surveySettings['flag_red_color'] ?>;"></div>
                                </div>
                            <?php endif; ?>

                            <div class="tracker-preview-container">
                <!-- Header -->
                <div class="text-center mb-4">
                    <?php if ($surveySettings['show_logo']): ?>
                        <div class="logo-container">
                            <img src="<?= htmlspecialchars($surveySettings['logo_path']) ?>" alt="Logo">
                        </div>
                    <?php endif; ?>
                    <h2 class="mb-2" style="color: #2c3e50; font-weight: 700;">
                        <?= htmlspecialchars($surveySettings['title_text']) ?>
                    </h2>
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
                                                    <span class="group-title">Ungrouped Questions</span>
                                                    <small class="text-muted ms-2">(Default group)</small>
                                                </h6>
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
    <script>
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
            const basePath = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
            const shareUrl = scheme + '//' + host + basePath + 'tracker_share.php?survey_id=' + surveyId;
            
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
                                <button class="edit-group-btn" onclick="editGroupName('${groupId}')" title="Edit name">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="delete-group-btn" onclick="deleteGroup('${groupId}')" title="Delete group">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="group-questions" data-group="${groupId}">
                        <p class="text-muted text-center py-3 mb-0">
                            <i class="fas fa-plus me-2"></i>Drag questions here
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
            const groupingData = {};

            stages.forEach(stage => {
                const stageId = stage.dataset.stageId;
                groupingData[stageId] = [];

                const groups = stage.querySelectorAll('.question-group');
                groups.forEach(group => {
                    const groupId = group.dataset.groupId;
                    const groupTitle = group.querySelector('.group-title').textContent.trim();
                    const questions = Array.from(group.querySelectorAll('.question-item')).map(item => ({
                        questionId: item.dataset.questionId,
                        questionIndex: item.dataset.questionIndex
                    }));

                    if (questions.length > 0) {
                        groupingData[stageId].push({
                            groupId: groupId,
                            groupTitle: groupTitle,
                            questions: questions
                        });
                    }
                });
            });

            // Save to database via API
            const surveyId = <?= json_encode($surveyId) ?>;
            
            try {
                const response = await fetch('api/groupings.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        survey_id: surveyId,
                        groupings: groupingData
                    })
                });

                const result = await response.json();
                
                if (result.success) {
                    // Also save to localStorage as backup for immediate use
                    localStorage.setItem(`tracker_grouping_${surveyId}`, JSON.stringify(groupingData));
                    
                    alert('Grouping saved successfully! The form will now display questions in these groups for all users.');
                    console.log('Saved grouping to database:', groupingData);
                } else {
                    alert('Error saving grouping: ' + result.error);
                    console.error('Database save error:', result.error);
                }
            } catch (error) {
                console.error('Error saving grouping to database:', error);
                
                // Fallback to localStorage if database save fails
                localStorage.setItem(`tracker_grouping_${surveyId}`, JSON.stringify(groupingData));
                alert('Grouping saved locally. Please check your connection and try again to save for all users.');
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

                // Clear saved grouping from both localStorage and database
                const surveyId = <?= json_encode($surveyId) ?>;
                localStorage.removeItem(`tracker_grouping_${surveyId}`);
                
                // Clear from database
                fetch(`api/groupings.php?survey_id=${surveyId}`, {
                    method: 'DELETE'
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

        // Load saved grouping on page load
        document.addEventListener('DOMContentLoaded', async function() {
            console.log('Tracker preview JavaScript loaded successfully');
            const surveyId = <?= json_encode($surveyId) ?>;
            
            // Try to load groupings from database first
            try {
                const response = await fetch(`api/groupings.php?survey_id=${surveyId}`);
                const result = await response.json();
                
                if (result.success && result.data && Object.keys(result.data).length > 0) {
                    console.log('Loaded grouping from database:', result.data);
                    // Also save to localStorage for immediate use
                    localStorage.setItem(`tracker_grouping_${surveyId}`, JSON.stringify(result.data));
                } else {
                    // Fallback to localStorage if no database groupings
                    const savedGrouping = localStorage.getItem(`tracker_grouping_${surveyId}`);
                    if (savedGrouping) {
                        try {
                            const groupingData = JSON.parse(savedGrouping);
                            console.log('Loaded grouping from localStorage:', groupingData);
                        } catch (e) {
                            console.error('Error loading saved grouping from localStorage:', e);
                        }
                    }
                }
            } catch (error) {
                console.error('Error loading groupings from database:', error);
                
                // Fallback to localStorage
                const savedGrouping = localStorage.getItem(`tracker_grouping_${surveyId}`);
                if (savedGrouping) {
                    try {
                        const groupingData = JSON.parse(savedGrouping);
                        console.log('Loaded grouping from localStorage (fallback):', groupingData);
                    } catch (e) {
                        console.error('Error loading saved grouping from localStorage:', e);
                    }
                }
            }
            
            // Test if the grouping elements exist
            const groupingButton = document.getElementById('groupingModeText');
            const groupingInterface = document.getElementById('groupingInterface');
            console.log('Grouping button found:', !!groupingButton);
            console.log('Grouping interface found:', !!groupingInterface);
        });
    </script>
</body>
</html>