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

// Fetch tracker program structure from DHIS2 with option set IDs
$trackerProgram = fetchFromDHIS2("programs/{$survey['dhis2_program_uid']}.json?fields=id,name,description,programType,trackedEntityType,programStages[id,name,description,repeatable,minDaysFromStart,programStageDataElements[dataElement[id,name,displayName,valueType,optionSet[id,options[code,displayName]]]]],programTrackedEntityAttributes[trackedEntityAttribute[id,name,displayName,valueType,unique,optionSet[id,options[code,displayName]]],mandatory,displayInList]", $dhis2Config);

// Function to get option set values from local database
function getLocalOptionSetValues($optionSetId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT local_value as displayName, dhis2_option_code as code 
            FROM dhis2_option_set_mapping 
            WHERE dhis2_option_set_id = ? 
            ORDER BY local_value
        ");
        $stmt->execute([$optionSetId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching option set values: " . $e->getMessage());
        return [];
    }
}

// Function to get option set values from DHIS2 API as fallback
function getOptionSetValuesFromAPI($optionSetId, $dhis2Config) {
    try {
        $response = fetchFromDHIS2("optionSets/{$optionSetId}.json?fields=options[code,displayName]", $dhis2Config);
        if ($response && isset($response['options'])) {
            error_log("Fetched " . count($response['options']) . " options from DHIS2 API for option set: " . $optionSetId);
            return $response['options'];
        }
    } catch (Exception $e) {
        error_log("Error fetching option set from DHIS2 API: " . $e->getMessage());
    }
    return [];
}

// Function to get schools - now handled dynamically by API endpoint
// Schools are loaded based on selected location via get_schools_by_location.php

// Enhance tracker program with local option sets
if ($trackerProgram && is_array($trackerProgram)) {
    try {
        // Enhance TEAs
        if (isset($trackerProgram['programTrackedEntityAttributes'])) {
            $trackerProgram['programTrackedEntityAttributes'] = array_map(function($attr) use ($dhis2Config) {
                if (isset($attr['trackedEntityAttribute']['optionSet'])) {
                    $optionSetId = null;
                    if (isset($attr['trackedEntityAttribute']['optionSet']['id'])) {
                        $optionSetId = $attr['trackedEntityAttribute']['optionSet']['id'];
                    }
                    
                    if ($optionSetId) {
                        // First try local database
                        $options = getLocalOptionSetValues($optionSetId);
                        if (!empty($options)) {
                            $attr['trackedEntityAttribute']['optionSet']['options'] = $options;
                            error_log("Replaced with " . count($options) . " local options for TEA: " . $attr['trackedEntityAttribute']['id']);
                        } else {
                            // Check if we already have options from DHIS2 API
                            if (isset($attr['trackedEntityAttribute']['optionSet']['options']) && !empty($attr['trackedEntityAttribute']['optionSet']['options'])) {
                                error_log("Using existing DHIS2 options for TEA: " . $attr['trackedEntityAttribute']['id']);
                            } else {
                                // Fallback: fetch from DHIS2 API
                                $apiOptions = getOptionSetValuesFromAPI($optionSetId, $dhis2Config);
                                if (!empty($apiOptions)) {
                                    $attr['trackedEntityAttribute']['optionSet']['options'] = $apiOptions;
                                    error_log("Fetched " . count($apiOptions) . " options from DHIS2 API for TEA: " . $attr['trackedEntityAttribute']['id']);
                                }
                            }
                        }
                    }
                }
                return $attr;
            }, $trackerProgram['programTrackedEntityAttributes']);
        }

        // Enhance Program Stages
        if (isset($trackerProgram['programStages'])) {
            $trackerProgram['programStages'] = array_map(function($stage) {
                if (isset($stage['programStageDataElements'])) {
                    $stage['programStageDataElements'] = array_map(function($psde) {
                        if (isset($psde['dataElement']['optionSet'])) {
                            error_log("Processing DE " . $psde['dataElement']['id'] . " (" . $psde['dataElement']['name'] . ") with option set structure");
                            
                            // Try to get option set ID - it might be in different places
                            $optionSetId = null;
                            if (isset($psde['dataElement']['optionSet']['id'])) {
                                $optionSetId = $psde['dataElement']['optionSet']['id'];
                                error_log("Found option set ID: " . $optionSetId);
                            } else {
                                error_log("No option set ID found in optionSet structure for DE: " . $psde['dataElement']['id']);
                            }
                            
                            if ($optionSetId) {
                                // First try local database
                                $options = getLocalOptionSetValues($optionSetId);
                                if (!empty($options)) {
                                    $psde['dataElement']['optionSet']['options'] = $options;
                                    error_log("Replaced with " . count($options) . " local options for DE: " . $psde['dataElement']['id']);
                                } else {
                                    error_log("No local options found for option set: " . $optionSetId);
                                    
                                    // Check if we already have options from DHIS2 API
                                    if (isset($psde['dataElement']['optionSet']['options']) && !empty($psde['dataElement']['optionSet']['options'])) {
                                        error_log("Using existing DHIS2 options (" . count($psde['dataElement']['optionSet']['options']) . ") for DE: " . $psde['dataElement']['id']);
                                    } else {
                                        // Fallback: fetch from DHIS2 API
                                        error_log("Attempting to fetch option set from DHIS2 API for: " . $optionSetId);
                                        $apiOptions = getOptionSetValuesFromAPI($optionSetId, $dhis2Config);
                                        if (!empty($apiOptions)) {
                                            $psde['dataElement']['optionSet']['options'] = $apiOptions;
                                            error_log("Fetched " . count($apiOptions) . " options from DHIS2 API for DE: " . $psde['dataElement']['id']);
                                        } else {
                                            error_log("No options found anywhere for option set: " . $optionSetId);
                                        }
                                    }
                                }
                            }
                        }
                        return $psde;
                    }, $stage['programStageDataElements']);
                }
                return $stage;
            }, $trackerProgram['programStages']);
        }
        error_log("Successfully enhanced tracker program with local option sets");
    } catch (Exception $e) {
        error_log("Error enhancing tracker program with option sets: " . $e->getMessage());
    }
} else {
    error_log("Warning: trackerProgram is null or invalid, using fallback structure");
}

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
    'logo_path' => 'admin/asets/asets/img/loog.jpg',
    'show_flag_bar' => true,
    'flag_black_color' => '#000000',
    'flag_yellow_color' => '#FCD116', 
    'flag_red_color' => '#D21034'
];

$surveySettings = array_merge($defaultSettings, $surveySettings);

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
    $programStages = $uniqueStages; // Update the programStages array with deduplicated stages
    error_log("Deduplicated program stages. Original count: " . count($trackerProgram['programStages'] ?? []) . ", Final count: " . count($programStages));
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
    <title><?= htmlspecialchars($surveySettings['title_text']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        /* Enhanced Select2 searchable dropdown styles */
        .select2-container--default .select2-selection--single {
            background-color: #fff;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            height: 48px;
            transition: all 0.3s ease;
        }
        
        .select2-container--default .select2-selection--single:focus-within,
        .select2-container--default.select2-container--open .select2-selection--single {
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: #495057;
            line-height: 44px;
            padding-left: 12px;
            font-size: 16px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__placeholder {
            color: #6c757d;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 44px;
            top: 2px;
            right: 8px;
        }
        
        .select2-dropdown {
            border: 2px solid #007bff;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        .select2-results__option {
            padding: 12px 16px;
            font-size: 15px;
            transition: background-color 0.2s;
        }
        
        .select2-results__option--highlighted {
            background-color: #007bff !important;
            color: white;
        }
        
        .select2-search__field {
            padding: 10px 12px;
            font-size: 16px;
            border-radius: 6px;
        }
        
        /* Option set field styling */
        .option-set-field {
            position: relative;
        }
        
        .option-set-field::before {
            content: '\f002';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 40px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            pointer-events: none;
            z-index: 1;
        }
        
        .question-input-container .form-control.select2-hidden-accessible + .select2-container {
            width: 100% !important;
        }
        
        /* File Upload Styles */
        .file-upload-field {
            width: 100%;
        }
        
        .file-upload-container {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
         .file-upload-area {
            padding: 30px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #fafbfc;
        }
        
        .file-upload-area:hover {
            border-color: #007bff;
            background: #f8f9ff;
        }
        
        .file-upload-icon {
            font-size: 3rem;
            color: #6c757d;
            margin-bottom: 15px;
        }
        
        .file-upload-area:hover .file-upload-icon {
            color: #007bff;
        }
        
        .file-upload-text strong {
            font-size: 18px;
            color: #2c3e50;
        }
        
        .file-upload-text small {
            color: #6c757d;
            font-size: 14px;
        }
        
        .file-upload-info {
            padding: 15px;
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
        }
        
        .uploaded-file-info {
            display: flex;
            align-items: center;
            padding: 10px;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 6px;
        }
        
        .file-preview {
            max-height: 200px;
            overflow-y: auto;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 10px;
        }
        
        .file-preview table {
            width: 100%;
            font-size: 12px;
        }
        
        .file-preview th {
            background: #f8f9fa;
            padding: 5px 8px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .file-preview td {
            padding: 3px 8px;
            border-bottom: 1px solid #f1f3f4;
        }
    </style>
    
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

        /* Compact styling for TRUE_ONLY checkbox containers */
        .modal-question-item.checkbox-item {
            padding: 15px 20px;
            margin-bottom: 15px;
            min-height: auto;
            border-width: 1px;
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }

        .modal-question-item.checkbox-item .question-header {
            margin-bottom: 0;
            flex: 1;
        }

        .modal-question-item.checkbox-item .question-input-container {
            margin-top: 0;
            flex-shrink: 0;
            width: 60px;
            display: flex;
            justify-content: center;
        }

        .modal-question-item.checkbox-item .form-check {
            margin-bottom: 0;
            padding-left: 0;
            min-height: auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .modal-question-item.checkbox-item .form-check-input {
            margin: 0 auto 5px auto;
            position: static;
            transform: none;
        }

        .modal-question-item.checkbox-item .form-check-label {
            color: #6c757d;
            font-size: 12px;
            text-align: center;
            margin: 0;
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
        .question-input-container select:not(.searchable-select),
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
        .question-input-container select:not(.searchable-select):focus,
        .question-input-container textarea:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,0.25);
            outline: none;
        }
        
        /* Validation styles for Select2 */
        .select2-container.is-invalid .select2-selection--single {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
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
                        <label for="facilitySearch" class="form-label">Search for location <span class="text-danger">*</span></label>
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
                <div class="stage-nav-item" onclick="navigateToStage('tei-section', this)" data-stage="tei-section">
                    <div class="stage-progress">1</div>
                    <div class="stage-nav-content">
                        <div class="stage-nav-title">Participant Information</div>
                        <div class="stage-nav-subtitle">Basic registration details</div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php foreach ($programStages as $index => $stage): ?>
                <div class="stage-nav-item" onclick="navigateToStage('<?= $stage['id'] ?>', this)" data-stage="<?= $stage['id'] ?>">
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
                        <i class="fas fa-save me-1"></i> Save 
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden data for JavaScript -->
    <script type="application/json" id="programData">
        <?= json_encode([
            'program' => $trackerProgram,
            'surveySettings' => array_merge($surveySettings, [
                'dhis2_program_uid' => $survey['dhis2_program_uid'] ?? null,
                'id' => $survey['id']
            ])
        ]) ?>
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global variables
        let programData;
        let formData = {
            trackedEntityInstance: null,
            trackedEntityAttributes: {},
            events: []
        };
        let stageOccurrences = {};
        let stageData = {}; // Store independent form data for each stage occurrence
        
        // Initialize the form
        document.addEventListener('DOMContentLoaded', function() {
            try {
                const programDataElement = document.getElementById('programData');
                if (!programDataElement) {
                    console.error('Program data element not found');
                    return;
                }
                
                programData = JSON.parse(programDataElement.textContent);
                console.log('Program data loaded:', programData);
                
                // Debug survey settings
                console.log('Survey settings:', programData.surveySettings);
                if (programData.surveySettings && programData.surveySettings.dhis2_program_uid) {
                    console.log('DHIS2 program UID found:', programData.surveySettings.dhis2_program_uid);
                } else {
                    console.log('No DHIS2 program UID found in survey settings');
                }
                
                // Debug option set data specifically
                if (programData.program && programData.program.programStages) {
                    programData.program.programStages.forEach(stage => {
                        console.log('Stage:', stage.name);
                        if (stage.programStageDataElements) {
                            stage.programStageDataElements.forEach(psde => {
                                if (psde.dataElement.optionSet) {
                                    console.log('DE with option set:', psde.dataElement.name, 'Options:', psde.dataElement.optionSet.options?.length);
                                }
                            });
                        }
                    });
                }
                
                if (!programData || !programData.program) {
                    console.error('Invalid program data structure');
                    return;
                }
            } catch (error) {
                console.error('Error loading program data:', error);
                alert('Error loading form data. Please refresh the page and try again.');
                return;
            }
            
            // Initialize stage occurrences and data storage
            console.log('Available program stages:', programData.program.programStages.map(s => ({id: s.id, name: s.name})));
            programData.program.programStages.forEach(stage => {
                stageOccurrences[stage.id] = 1;
                stageData[stage.id] = {}; // Initialize empty data for each stage
            });
            
            // Debug navigation items
            const allNavItems = document.querySelectorAll('.stage-nav-item[data-stage]');
            console.log('Navigation items found:', allNavItems.length);
            allNavItems.forEach((item, index) => {
                const stageId = item.getAttribute('data-stage');
                const stageTitle = item.querySelector('.stage-nav-title')?.textContent?.trim();
                console.log(`Nav item ${index + 1}: stageId="${stageId}", title="${stageTitle}"`);
            });
            
            // Initialize navigation - set first stage as active only on initial load
            const firstNavItem = document.querySelector('.stage-nav-item[data-stage]');
            if (firstNavItem) {
                firstNavItem.classList.add('active');
            }
            
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
                        const eventDate = document.querySelector(`[data-stage-id="${stage.id}"][data-occurrence="${i}"].event-date`).value;
                        
                        const event = {
                            programStage: stage.id,
                            eventDate: eventDate,
                            dataValues: {}
                        };
                        
                        // Collect data elements for this occurrence
                        const dataElements = document.querySelectorAll(`[data-stage-id="${stage.id}"][data-occurrence="${i}"].stage-data-element`);
                        dataElements.forEach(element => {
                            if (element.value) {
                                const deId = element.getAttribute('data-de-id');
                                event.dataValues[deId] = element.value;
                            }
                        });
                        
                        formData.events.push(event);
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

        function navigateToStage(stageId, navElement) {
            console.log('navigateToStage called with stageId:', stageId);
            
            // Debug: Check what navigation items exist right now
            const allNavItems = document.querySelectorAll('.stage-nav-item[data-stage]');
            console.log('Current navigation items:');
            allNavItems.forEach((item, index) => {
                const itemStageId = item.getAttribute('data-stage');
                const stageTitle = item.querySelector('.stage-nav-title')?.textContent?.trim();
                console.log(`  ${index + 1}: stageId="${itemStageId}", title="${stageTitle}"`);
            });
            
            // Open the stage modal instead of navigating directly
            if (stageId === 'tei-section') {
                openTEIModal();
            } else {
                openStageModal(stageId);
            }

            // Update navigation active state
            console.log('Setting active stage to:', stageId);
            document.querySelectorAll('.stage-nav-item').forEach(item => {
                item.classList.remove('active');
            });

            if (navElement) {
                navElement.classList.add('active');
                console.log('Successfully set active stage to:', stageId);
            } else {
                const navItem = document.querySelector(`[data-stage="${stageId}"]`);
                if (navItem) {
                    navItem.classList.add('active');
                    console.log('Successfully set active stage to (fallback):', stageId);
                } else {
                    console.error('Could not find nav item for stage:', stageId);
                }
            }

            // Auto-collapse navigation on mobile
            if (window.innerWidth < 992) {
                const nav = document.getElementById('stageNavigation');
                if (!nav.classList.contains('collapsed')) {
                    toggleStageNavigation();
                }
            }
        }

        // Global variables to track current modal stage and occurrence
        let currentModalStage = null;
        let currentModalOccurrence = null;

        function openStageModal(stageId) {
            if (!programData || !programData.program || !programData.program.programStages) {
                console.error('Program data not available');
                alert('Form data not loaded properly. Please refresh the page.');
                return;
            }
            
            currentModalStage = stageId;
            const stage = programData.program.programStages.find(s => s.id === stageId);
            if (!stage) {
                console.error('Stage not found:', stageId);
                return;
            }

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
            currentModalOccurrence = occurrenceNum;
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
                            <i class="fas fa-info-circle me-2 text-info"></i>
                            <strong>Note:</strong> Fill in all required fields to proceed with the tracker submission
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
                            <i class="fas fa-save me-2"></i> Save 
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
            
            // Load existing files for this stage
            setTimeout(async () => {
                await loadExistingFiles();
            }, 200);
            
            // Initialize Select2 for searchable dropdowns
            initializeSelect2InModal();
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
                            <i class="fas fa-info-circle me-2 text-info"></i>
                            <strong>Note:</strong> Fill in all required fields to proceed with the tracker submission
                        </p>
                    </div>
                    <div class="d-flex gap-3">
                        <button type="button" class="btn btn-outline-secondary btn-lg px-4" onclick="closeStageModal()">
                            <i class="fas fa-times me-2"></i> Cancel
                        </button>
                        <button type="button" class="btn btn-success btn-lg px-5" onclick="saveTEIData()">
                            <i class="fas fa-save me-2"></i> Save 
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

            // Initialize Select2 for searchable dropdowns
            initializeSelect2InModal();
        }

        async function loadTEIAttributes(container) {
            if (!programData.program.programTrackedEntityAttributes || programData.program.programTrackedEntityAttributes.length === 0) {
                const grid = container.querySelector('.modal-questions-grid');
                grid.innerHTML = '<p class="text-center text-muted">No participant information fields configured.</p>';
                return;
            }

            // Load groupings from database for TEI section  
            // Use a special identifier for TEI attributes
            const groupingData = await loadGroupingsFromDatabase('tei_attributes');

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
            
            // Add compact class for TRUE_ONLY checkboxes
            if (attribute.valueType === 'TRUE_ONLY') {
                div.className += ' checkbox-item';
            }
            
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
                // Check if this TEXT attribute has option set - force dropdown if it does
                if (attribute.optionSet && attribute.optionSet.options) {
                    console.log('Converting TEI TEXT attribute with options to dropdown:', attribute.name);
                    let options = '<option value="">Search or select an option...</option>';
                    attribute.optionSet.options.forEach(option => {
                        options += `<option value="${option.code}">${option.displayName}</option>`;
                    });
                    return `<div class="option-set-field"><select id="${inputId}" name="${inputId}" class="form-control searchable-select" ${isRequired ? 'required' : ''} data-placeholder="Search for ${label}...">${options}</select></div>`;
                } else {
                    return `<input type="text" id="${inputId}" name="${inputId}" class="form-control" placeholder="${placeholder}" ${isRequired ? 'required' : ''}>`;
                }
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
            } else if (attribute.valueType === 'TRUE_ONLY') {
                return `
                    <div class="form-check">
                        <input type="checkbox" id="${inputId}" name="${inputId}" class="form-check-input" value="true" ${isRequired ? 'required' : ''}>
                        <label class="form-check-label" for="${inputId}">Yes</label>
                    </div>
                `;
            } else if (attribute.valueType === 'URL') {
                return `<input type="url" id="${inputId}" name="${inputId}" class="form-control" placeholder="${placeholder}" ${isRequired ? 'required' : ''}>`;
            } else if (attribute.valueType === 'PERCENTAGE') {
                return `<input type="number" id="${inputId}" name="${inputId}" class="form-control" min="0" max="100" step="0.01" placeholder="${placeholder}" ${isRequired ? 'required' : ''}>`;
            } else if (attribute.valueType === 'TIME') {
                return `<input type="time" id="${inputId}" name="${inputId}" class="form-control" ${isRequired ? 'required' : ''}>`;
            } else if (attribute.valueType === 'DATETIME') {
                return `<input type="datetime-local" id="${inputId}" name="${inputId}" class="form-control" ${isRequired ? 'required' : ''}>`;
            } else if (attribute.valueType === 'FILE_RESOURCE') {
                return `<div class="alert alert-info">
                    <i class="fas fa-file-upload me-2"></i>
                    <strong>File Upload:</strong> ${label}<br>
                    <small class="text-muted">File upload functionality is not available in this form. This field will be skipped.</small>
                </div>`;
            } else if (attribute.optionSet && attribute.optionSet.options) {
                let options = '<option value="">Search or select an option...</option>';
                attribute.optionSet.options.forEach(option => {
                    options += `<option value="${option.code}">${option.displayName}</option>`;
                });
                return `<div class="option-set-field"><select id="${inputId}" name="${inputId}" class="form-control searchable-select" ${isRequired ? 'required' : ''} data-placeholder="Search for ${label}...">${options}</select></div>`;
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
                case 'URL': return 'Enter website URL...';
                case 'PERCENTAGE': return 'Enter percentage (0-100)...';
                case 'TIME': return 'Select time';
                case 'DATETIME': return 'Select date and time';
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
                case 'TRUE_ONLY': return 'Check this box if applicable';
                case 'EMAIL': return 'Enter a valid email address';
                case 'PHONE_NUMBER': return 'Enter a valid phone number';
                case 'URL': return 'Enter a valid website URL';
                case 'PERCENTAGE': return 'Enter a percentage value between 0 and 100';
                case 'TIME': return 'Select or enter a time';
                case 'DATETIME': return 'Select or enter date and time';
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
            if (!stage) {
                console.log('❌ Stage not found:', stageId);
                return;
            }

            console.log('🚀 Loading stage questions for stage:', stageId, stage.name);

            // Load groupings from database
            const groupingData = await loadGroupingsFromDatabase(stageId);

            // If we have groupings for this stage, apply them
            if (groupingData && groupingData.length > 0) {
                console.log('📋 Using grouped layout for stage:', stageId);
                loadGroupedQuestions(container, stage, groupingData);
            } else {
                console.log('📝 Using ungrouped layout for stage:', stageId);
                loadUngroupedQuestions(container, stage);
            }
        }

        async function loadGroupingsFromDatabase(stageId) {
            try {
                const surveyId = document.getElementById('surveyId').value;
                console.log('🔍 Attempting to load groupings for survey:', surveyId, 'stage:', stageId);
                
                const response = await fetch(`/fbs/public/api/groupings.php?survey_id=${surveyId}`);
                console.log('📡 API Response status:', response.status);
                
                if (response.ok) {
                    const result = await response.json();
                    console.log('📊 Full groupings data from API:', result);
                    
                    if (result.success && result.data && result.data[stageId]) {
                        console.log('✅ Loading groupings from database for stage:', stageId, result.data[stageId]);
                        return result.data[stageId];
                    } else {
                        console.log('❌ No groupings found for stage:', stageId, 'Available stages:', Object.keys(result.data || {}));
                    }
                } else {
                    console.error('❌ API request failed with status:', response.status);
                }
                
                return null;
            } catch (error) {
                console.error('❌ Error loading groupings from database:', error);
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
            
            // Add compact class for TRUE_ONLY checkboxes
            if (dataElement.valueType === 'TRUE_ONLY') {
                div.className += ' checkbox-item';
            }
            
            // Clean the label by removing prefixes
            let cleanLabel = dataElement.name;
            cleanLabel = cleanLabel.replace(/^[A-Z]+_/, '');

            // Use occurrence number instead of index to ensure unique IDs across events
            const occurrenceNum = currentModalOccurrence || 1;
            const inputId = `modal_${dataElement.id}_${occurrenceNum}`;
            
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
            console.log('Creating input for:', dataElement.name, 'ID:', dataElement.id, 'optionSet:', dataElement.optionSet);
            
            // Special debugging for the problematic field
            if (dataElement.id === 'ebmdvu4hMqa') {
                console.log('SPECIAL DEBUG for ebmdvu4hMqa:', {
                    valueType: dataElement.valueType,
                    hasOptionSet: !!dataElement.optionSet,
                    hasOptions: !!(dataElement.optionSet && dataElement.optionSet.options),
                    optionCount: dataElement.optionSet?.options?.length
                });
            }
            
            const placeholder = getPlaceholderText(dataElement.valueType, label);
            
            if (dataElement.valueType === 'TEXT') {
                // Check if this TEXT field has option set - force dropdown if it does
                if (dataElement.optionSet && dataElement.optionSet.options) {
                    console.log('Converting TEXT field with options to dropdown:', dataElement.name);
                    let options = '<option value="">Search or select an option...</option>';
                    dataElement.optionSet.options.forEach(option => {
                        options += `<option value="${option.code}">${option.displayName}</option>`;
                    });
                    return `<div class="option-set-field"><select id="${inputId}" name="${inputId}" class="form-control searchable-select" ${dataElement.compulsory ? 'required' : ''} data-placeholder="Search for ${label}...">${options}</select></div>`;
                } else {
                    return `<input type="text" id="${inputId}" name="${inputId}" class="form-control" placeholder="${placeholder}" ${dataElement.compulsory ? 'required' : ''}>`;
                }
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
            } else if (dataElement.valueType === 'TRUE_ONLY') {
                return `
                    <div class="form-check">
                        <input type="checkbox" id="${inputId}" name="${inputId}" class="form-check-input" value="true" ${dataElement.compulsory ? 'required' : ''}>
                        <label class="form-check-label" for="${inputId}">Yes</label>
                    </div>
                `;
            } else if (dataElement.valueType === 'EMAIL') {
                return `<input type="email" id="${inputId}" name="${inputId}" class="form-control" placeholder="${placeholder}" ${dataElement.compulsory ? 'required' : ''}>`;
            } else if (dataElement.valueType === 'PHONE_NUMBER') {
                return `<input type="tel" id="${inputId}" name="${inputId}" class="form-control" placeholder="${placeholder}" ${dataElement.compulsory ? 'required' : ''}>`;
            } else if (dataElement.valueType === 'URL') {
                return `<input type="url" id="${inputId}" name="${inputId}" class="form-control" placeholder="${placeholder}" ${dataElement.compulsory ? 'required' : ''}>`;
            } else if (dataElement.valueType === 'PERCENTAGE') {
                return `<input type="number" id="${inputId}" name="${inputId}" class="form-control" min="0" max="100" step="0.01" placeholder="${placeholder}" ${dataElement.compulsory ? 'required' : ''}>`;
            } else if (dataElement.valueType === 'TIME') {
                return `<input type="time" id="${inputId}" name="${inputId}" class="form-control" ${dataElement.compulsory ? 'required' : ''}>`;
            } else if (dataElement.valueType === 'DATETIME') {
                return `<input type="datetime-local" id="${inputId}" name="${inputId}" class="form-control" ${dataElement.compulsory ? 'required' : ''}>`;
            } else if (dataElement.valueType === 'FILE_RESOURCE') {
                console.log('FILE_RESOURCE field detected:', {
                    name: dataElement.name,
                    id: dataElement.id,
                    hasSchoolKeyword: dataElement.name ? dataElement.name.toLowerCase().includes('school') : false,
                    hasInstitutionKeyword: dataElement.name ? dataElement.name.toLowerCase().includes('institution') : false,
                    isSpecificId: dataElement.id === 'fkipjGtgOHg',
                    hasSurveySettings: !!programData.surveySettings,
                    hasDhis2Program: !!(programData.surveySettings && programData.surveySettings.dhis2_program_uid)
                });
                
                // Check if this is a school-related field for this specific survey
                if (dataElement.name && (
                    dataElement.name.toLowerCase().includes('school') || 
                    dataElement.name.toLowerCase().includes('institution') ||
                    dataElement.id === 'fkipjGtgOHg' // Specific ID for "Names of schools supported by the organization"
                ) && programData.surveySettings && programData.surveySettings.dhis2_program_uid) {
                    console.log('Converting FILE_RESOURCE field to CSV/XLSX upload:', dataElement.name);
                    return `<div class="file-upload-container" onclick="document.getElementById('${inputId}').click();" style="cursor: pointer;">
                                <div class="file-upload-area">
                                    <div class="file-upload-icon">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                    </div>
                                    <div class="file-upload-text">
                                        <strong>Click to upload a file</strong>
                                    </div>
                                    <small class="text-muted mt-2 d-block">
                                        Upload a CSV or Excel file containing the list of schools. The file should have columns: School Name, School Code/UID
                                    </small>
                                </div>
                            </div>
                            <input type="file" 
                                   id="${inputId}" 
                                   name="${inputId}" 
                                   accept=".csv,.xlsx,.xls"
                                   style="display: none;"
                                   ${dataElement.compulsory ? 'required' : ''}
                                   onchange="handleSchoolFileUpload(this, '${inputId}')">
                            <div id="${inputId}_info" class="file-upload-info" style="display: none;">
                                <div class="uploaded-file-info">
                                    <i class="fas fa-file-excel text-success me-2"></i>
                                    <span class="file-name"></span>
                                    <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="removeSchoolFile('${inputId}')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                                <div class="file-preview mt-2" id="${inputId}_preview"></div>
                            </div>`;
                } else {
                    return `<div class="alert alert-info">
                        <i class="fas fa-file-upload me-2"></i>
                        <strong>File Upload:</strong> ${label}<br>
                        <small class="text-muted">File upload functionality is not available in this form. This field will be skipped.</small>
                    </div>`;
                }
            } else if (dataElement.optionSet && dataElement.optionSet.options) {
                let options = '<option value="">Search or select an option...</option>';
                dataElement.optionSet.options.forEach(option => {
                    options += `<option value="${option.code}">${option.displayName}</option>`;
                });
                return `<div class="option-set-field"><select id="${inputId}" name="${inputId}" class="form-control searchable-select" ${dataElement.compulsory ? 'required' : ''} data-placeholder="Search for ${label}...">${options}</select></div>`;
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
                case 'EMAIL': return 'Enter email address...';
                case 'PHONE_NUMBER': return 'Enter phone number...';
                case 'URL': return 'Enter website URL...';
                case 'PERCENTAGE': return 'Enter percentage (0-100)...';
                case 'TIME': return 'Select time';
                case 'DATETIME': return 'Select date and time';
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
                case 'TRUE_ONLY': return 'Check this box if applicable';
                case 'EMAIL': return 'Enter a valid email address';
                case 'PHONE_NUMBER': return 'Enter a valid phone number';
                case 'URL': return 'Enter a valid website URL';
                case 'PERCENTAGE': return 'Enter a percentage value between 0 and 100';
                case 'TIME': return 'Select or enter a time';
                case 'DATETIME': return 'Select or enter date and time';
                default: return 'Enter the required information';
            }
        }


        function initializeSelect2InModal() {
            // Destroy existing Select2 instances to avoid conflicts
            $('#stageQuestionsModal .searchable-select').each(function() {
                if ($(this).hasClass('select2-hidden-accessible')) {
                    $(this).select2('destroy');
                }
            });
            
            // Initialize Select2 with enhanced configuration
            $('#stageQuestionsModal .searchable-select').select2({
                dropdownParent: $('#stageQuestionsModal .modal-content'),
                theme: "default",
                placeholder: function() {
                    return $(this).data('placeholder') || 'Search and select an option...';
                },
                allowClear: true,
                width: '100%',
                minimumResultsForSearch: 5, // Show search when 5+ options
                language: {
                    noResults: function () {
                        return "No options found";
                    },
                    searching: function () {
                        return "Searching...";
                    }
                }
            });
            
            // Add change event handler for validation
            $('#stageQuestionsModal .searchable-select').on('change', function() {
                const $this = $(this);
                if ($this.prop('required') && !$this.val()) {
                    $this.next('.select2-container').addClass('is-invalid');
                } else {
                    $this.next('.select2-container').removeClass('is-invalid');
                }
            });
            
            // File upload initialization is handled inline
            console.log('Select2 initialization complete');
        }
        
        // File upload handling functions
        async function handleSchoolFileUpload(input, inputId) {
            const file = input.files[0];
            if (!file) return;
            
            console.log('School file uploaded:', file.name, file.type);
            
            // Show loading state
            const infoDiv = document.getElementById(inputId + '_info');
            const fileNameSpan = infoDiv.querySelector('.file-name');
            const previewDiv = document.getElementById(inputId + '_preview');
            
            fileNameSpan.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Uploading...';
            infoDiv.style.display = 'block';
            
            try {
                // Upload file to server
                const surveyId = document.getElementById('surveyId').value;
                const questionId = inputId.replace(/^modal_/, '').replace(/_\d+$/, ''); // Extract question ID
                
                const formData = new FormData();
                formData.append('file', file);
                formData.append('survey_id', surveyId);
                formData.append('question_id', questionId);
                
                const response = await fetch('/fbs/public/api/file_uploads.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Store upload info for later use
                    const uploadInfo = {
                        uploadId: result.upload_id,
                        filename: result.filename,
                        storedFilename: result.stored_filename,
                        size: result.size,
                        uploaded: true
                    };
                    
                    // Store in a data attribute for persistence
                    input.setAttribute('data-upload-info', JSON.stringify(uploadInfo));
                    
                    // Update UI
                    fileNameSpan.innerHTML = `<i class="fas fa-file-excel text-success me-2"></i>${result.filename}`;
                    
                    // Add file size info
                    const sizeInfo = document.createElement('small');
                    sizeInfo.className = 'text-muted d-block';
                    sizeInfo.textContent = `Size: ${(result.size / 1024).toFixed(1)} KB`;
                    fileNameSpan.appendChild(sizeInfo);
                    
                    // Show preview if available
                    if (result.preview) {
                        displayFilePreview(result.preview, previewDiv);
                    }
                    
                    console.log('✅ File uploaded successfully:', uploadInfo);
                } else {
                    throw new Error(result.error || 'Upload failed');
                }
                
            } catch (error) {
                console.error('❌ File upload error:', error);
                fileNameSpan.innerHTML = `<i class="fas fa-exclamation-triangle text-danger me-2"></i>Upload failed: ${error.message}`;
                
                // Reset file input
                input.value = '';
                setTimeout(() => {
                    infoDiv.style.display = 'none';
                }, 3000);
            }
        }
        
        function displayFilePreview(preview, previewDiv) {
            if (!preview || !Array.isArray(preview)) return;
            
            let html = '<p class="mb-2"><strong>File Preview (first 5 rows):</strong></p>';
            html += '<table class="table table-sm table-bordered">';
            
            preview.forEach((line, index) => {
                if (line.trim()) {
                    const cells = line.split(',');
                    html += '<tr>';
                    if (index === 0) {
                        // Header row
                        cells.forEach(cell => {
                            html += `<th class="small">${cell.trim()}</th>`;
                        });
                    } else {
                        // Data rows
                        cells.forEach(cell => {
                            html += `<td class="small">${cell.trim()}</td>`;
                        });
                    }
                    html += '</tr>';
                }
            });
            
            html += '</table>';
            previewDiv.innerHTML = html;
        }
        
        async function removeSchoolFile(inputId) {
            const input = document.getElementById(inputId);
            const infoDiv = document.getElementById(inputId + '_info');
            
            // Check if there's an uploaded file to delete from server
            const uploadInfo = input.getAttribute('data-upload-info');
            if (uploadInfo) {
                try {
                    const uploadData = JSON.parse(uploadInfo);
                    
                    // Delete from server
                    const response = await fetch(`/fbs/public/api/file_uploads.php?upload_id=${uploadData.uploadId}`, {
                        method: 'DELETE'
                    });
                    
                    const result = await response.json();
                    if (result.success) {
                        console.log('✅ File deleted from server:', uploadData.filename);
                    } else {
                        console.warn('⚠️ Failed to delete from server:', result.error);
                    }
                } catch (error) {
                    console.error('❌ Error deleting file from server:', error);
                }
            }
            
            // Clear UI and input
            input.value = '';
            input.removeAttribute('data-upload-info');
            infoDiv.style.display = 'none';
            
            console.log('File removed for:', inputId);
        }
        
        // Function to load existing files when form opens
        async function loadExistingFiles() {
            const surveyId = document.getElementById('surveyId').value;
            const fileInputs = document.querySelectorAll('input[type="file"]');
            
            console.log('🔍 Loading existing files for survey:', surveyId);
            
            for (const input of fileInputs) {
                const inputId = input.id;
                const questionId = inputId.replace(/^modal_/, '').replace(/_\d+$/, '');
                
                try {
                    const response = await fetch(`/fbs/public/api/file_uploads.php?survey_id=${surveyId}&question_id=${questionId}`);
                    const result = await response.json();
                    
                    if (result.success && result.file) {
                        const file = result.file;
                        
                        // Store upload info
                        const uploadInfo = {
                            uploadId: file.id,
                            filename: file.original_filename,
                            storedFilename: file.stored_filename,
                            size: file.file_size,
                            uploaded: true
                        };
                        
                        input.setAttribute('data-upload-info', JSON.stringify(uploadInfo));
                        
                        // Update UI to show existing file
                        const infoDiv = document.getElementById(inputId + '_info');
                        const fileNameSpan = infoDiv.querySelector('.file-name');
                        
                        if (infoDiv && fileNameSpan) {
                            fileNameSpan.innerHTML = `<i class="fas fa-file-excel text-success me-2"></i>${file.original_filename}`;
                            
                            // Add file size info
                            const sizeInfo = document.createElement('small');
                            sizeInfo.className = 'text-muted d-block';
                            sizeInfo.textContent = `Size: ${(file.file_size / 1024).toFixed(1)} KB • Uploaded: ${new Date(file.created_at).toLocaleDateString()}`;
                            fileNameSpan.appendChild(sizeInfo);
                            
                            infoDiv.style.display = 'block';
                            
                            console.log('✅ Loaded existing file:', file.original_filename);
                        }
                    }
                } catch (error) {
                    console.log('No existing file for question:', questionId);
                }
            }
        }
        
        function previewCSVFile(file, previewDiv) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const csv = e.target.result;
                const lines = csv.split('\\n').slice(0, 6); // Show first 5 rows + header
                
                let html = '<p class="mb-2"><strong>File Preview (first 5 rows):</strong></p>';
                html += '<table class="table table-sm">';
                
                lines.forEach((line, index) => {
                    if (line.trim()) {
                        const cells = line.split(',');
                        html += '<tr>';
                        cells.forEach(cell => {
                            const tag = index === 0 ? 'th' : 'td';
                            html += `<${tag}>${cell.trim()}</${tag}>`;
                        });
                        html += '</tr>';
                    }
                });
                
                html += '</table>';
                previewDiv.innerHTML = html;
            };
            reader.readAsText(file);
        }
        
        function previewExcelFile(file, previewDiv) {
            // For Excel files, show basic info since we can't parse without a library
            previewDiv.innerHTML = `
                <p class="mb-2"><strong>Excel File Information:</strong></p>
                <ul class="list-unstyled">
                    <li><i class="fas fa-file-excel text-success me-2"></i>File: ${file.name}</li>
                    <li><i class="fas fa-weight text-info me-2"></i>Size: ${(file.size / 1024).toFixed(1)} KB</li>
                    <li><i class="fas fa-calendar text-warning me-2"></i>Modified: ${new Date(file.lastModified).toLocaleDateString()}</li>
                </ul>
                <p class="text-muted small">Preview not available for Excel files. File will be processed upon submission.</p>
            `;
        }

        // End of file upload functions
        


        function closeStageModal() {
            console.log('Closing modal for stage:', currentModalStage);
            
            // Destroy Select2 instances before closing modal
            $('#stageQuestionsModal .searchable-select').each(function() {
                if ($(this).hasClass('select2-hidden-accessible')) {
                    $(this).select2('destroy');
                }
            });
            
            const modal = document.getElementById('stageQuestionsModal');
            modal.style.display = 'none';
            
            // Check navigation state before clearing currentModalStage
            const activeNavItem = document.querySelector('.stage-nav-item.active');
            console.log('Active nav item before modal close:', activeNavItem?.getAttribute('data-stage'));
            
            currentModalStage = null;
            
            // Check navigation state after clearing currentModalStage
            const activeNavItemAfter = document.querySelector('.stage-nav-item.active');
            console.log('Active nav item after modal close:', activeNavItemAfter?.getAttribute('data-stage'));
        }

        function saveStageData(stageId, occurrenceNum) {
            const eventDate = document.getElementById('modalEventDate').value;
            const container = document.getElementById('modalQuestionsContainer');
            
            if (!eventDate) {
                alert('Please select an event date');
                return;
            }
            
            // Validate required fields
            const requiredInputs = container.querySelectorAll('input[required], select[required], textarea[required]');
            let hasRequiredErrors = false;
            
            requiredInputs.forEach(input => {
                if (!input.value || (input.type === 'checkbox' && !input.checked)) {
                    hasRequiredErrors = true;
                    input.classList.add('is-invalid');
                } else {
                    input.classList.remove('is-invalid');
                }
            });
            
            if (hasRequiredErrors) {
                alert('Please fill in all required fields marked with *');
                return;
            }
            
            // Collect all form data
            const occurrenceData = { eventDate: eventDate, dataElements: {} };
            const inputs = container.querySelectorAll('input, select, textarea');
            
            inputs.forEach(input => {
                // Handle different input types appropriately
                if (input.type === 'file') {
                    // Handle file inputs specially
                    if (input.files && input.files[0]) {
                        occurrenceData.dataElements[input.id] = {
                            fileName: input.files[0].name,
                            fileSize: input.files[0].size,
                            fileType: input.files[0].type,
                            fileObject: input.files[0], // Store the actual file
                            isFile: true
                        };
                        console.log('Saved file data for field:', input.id, occurrenceData.dataElements[input.id].fileName);
                    }
                } else if (input.type === 'checkbox') {
                    // Handle checkboxes - store checked state
                    occurrenceData.dataElements[input.id] = {
                        value: input.checked ? input.value : '',
                        checked: input.checked,
                        isCheckbox: true
                    };
                    console.log('Saved checkbox data for field:', input.id, 'checked:', input.checked);
                } else if (input.type === 'radio') {
                    // Handle radio buttons - only store if checked
                    if (input.checked) {
                        occurrenceData.dataElements[input.name] = {
                            value: input.value,
                            isRadio: true
                        };
                        console.log('Saved radio data for field:', input.name, 'value:', input.value);
                    }
                } else if (input.tagName.toLowerCase() === 'select') {
                    // Handle select elements (including Select2)
                    if (input.value) {
                        occurrenceData.dataElements[input.id] = {
                            value: input.value,
                            selectedText: input.options[input.selectedIndex]?.text || input.value,
                            isSelect: true
                        };
                        console.log('Saved select data for field:', input.id, 'value:', input.value);
                    }
                } else if (input.value !== undefined && input.value !== '') {
                    // Handle all other input types (text, number, date, email, etc.)
                    occurrenceData.dataElements[input.id] = {
                        value: input.value,
                        type: input.type
                    };
                    console.log('Saved input data for field:', input.id, 'type:', input.type, 'value:', input.value);
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
                        const savedData = existingData.dataElements[inputId];
                        
                        // Handle different field types appropriately
                        if (savedData && savedData.isFile) {
                            // Handle file inputs - show comprehensive indicator and maintain data
                            
                            // Create enhanced file status indicator
                            const fileIndicator = document.createElement('div');
                            fileIndicator.className = 'alert alert-warning py-2 px-3 mt-2 mb-0';
                            fileIndicator.style.fontSize = '0.875rem';
                            fileIndicator.innerHTML = `
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <i class="fas fa-exclamation-triangle me-2 text-warning"></i>
                                        <strong>File Previously Selected:</strong> ${savedData.fileName}
                                        <span class="text-muted ms-2">(${(savedData.fileSize / 1024).toFixed(1)} KB)</span>
                                    </div>
                                    <div>
                                        <span class="badge bg-warning text-dark">⚠ Needs Re-selection</span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="clearSavedFile('${inputId}')">
                                            <i class="fas fa-times"></i> Clear
                                        </button>
                                    </div>
                                </div>
                                <small class="text-warning d-block mt-1">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <strong>Action Required:</strong> Please re-select this file to ensure it's uploaded to DHIS2. 
                                    File inputs lose their selection when modals are closed.
                                </small>
                            `;
                            fileIndicator.id = `file-indicator-${inputId}`;
                            
                            // Remove existing indicator if any
                            const existingIndicator = document.getElementById(`file-indicator-${inputId}`);
                            if (existingIndicator) {
                                existingIndicator.remove();
                            }
                            
                            // Add the indicator after the file input
                            input.parentNode.insertBefore(fileIndicator, input.nextSibling);
                            
                            // Also add a data attribute to the input to track the saved file
                            input.setAttribute('data-saved-file', JSON.stringify({
                                fileName: savedData.fileName,
                                fileSize: savedData.fileSize,
                                hasSavedFile: true
                            }));
                            
                            // Make the file input more prominent to encourage re-selection
                            input.style.border = '2px solid #ffc107';
                            input.style.backgroundColor = '#fff3cd';
                            input.setAttribute('title', 'Please re-select this file to ensure it gets uploaded to DHIS2');
                            
                            // Add event listener to clear warning styling when file is selected
                            input.addEventListener('change', function() {
                                if (this.files && this.files[0]) {
                                    // User has selected a new file - clear warning styling
                                    this.style.border = '';
                                    this.style.backgroundColor = '';
                                    this.removeAttribute('title');
                                    
                                    // Update the indicator to show success
                                    const indicator = document.getElementById(`file-indicator-${inputId}`);
                                    if (indicator) {
                                        indicator.className = 'alert alert-success py-2 px-3 mt-2 mb-0';
                                        indicator.innerHTML = `
                                            <div class="d-flex align-items-center justify-content-between">
                                                <div>
                                                    <i class="fas fa-file-excel me-2 text-success"></i>
                                                    <strong>File Selected:</strong> ${this.files[0].name}
                                                    <span class="text-muted ms-2">(${(this.files[0].size / 1024).toFixed(1)} KB)</span>
                                                </div>
                                                <div>
                                                    <span class="badge bg-success">✓ Ready for Upload</span>
                                                </div>
                                            </div>
                                        `;
                                    }
                                }
                            });
                            
                            console.log('Restored enhanced file indicator for:', inputId, savedData.fileName);
                        } else if (savedData && savedData.isCheckbox) {
                            // Handle checkboxes - restore checked state
                            input.checked = savedData.checked;
                            console.log('Restored checkbox for:', inputId, 'checked:', savedData.checked);
                        } else if (savedData && savedData.isRadio) {
                            // Handle radio buttons - find by name and value
                            const radioInputs = document.querySelectorAll(`input[name="${inputId}"]`);
                            radioInputs.forEach(radio => {
                                if (radio.value === savedData.value) {
                                    radio.checked = true;
                                    console.log('Restored radio for:', inputId, 'value:', savedData.value);
                                }
                            });
                        } else if (savedData && savedData.isSelect) {
                            // Handle select elements (including Select2)
                            input.value = savedData.value;
                            
                            // Trigger change event for Select2 or other plugins
                            const changeEvent = new Event('change', { bubbles: true });
                            input.dispatchEvent(changeEvent);
                            console.log('Restored select for:', inputId, 'value:', savedData.value);
                        } else if (savedData && savedData.value !== undefined) {
                            // Handle regular inputs with stored value
                            input.value = savedData.value;
                            console.log('Restored input for:', inputId, 'type:', savedData.type || 'unknown', 'value:', savedData.value);
                        } else if (typeof savedData === 'string' || typeof savedData === 'number') {
                            // Handle legacy simple values (backward compatibility)
                            input.value = savedData;
                            console.log('Restored legacy value for:', inputId, 'value:', savedData);
                        }
                    } else {
                        console.warn('Input element not found for saved data:', inputId);
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

        // Debug function to test data saving and loading
        function debugStageData() {
            console.log('=== STAGE DATA DEBUG ===');
            console.log('Current stageData:', JSON.stringify(stageData, (key, value) => {
                // Don't stringify file objects
                if (key === 'fileObject') return '[File Object]';
                return value;
            }, 2));
            
            // Test data collection from current modal if open
            const modal = document.getElementById('stageQuestionsModal');
            if (modal && modal.style.display !== 'none') {
                const container = document.getElementById('modalQuestionsContainer');
                const inputs = container.querySelectorAll('input, select, textarea');
                console.log('=== CURRENT MODAL INPUTS ===');
                inputs.forEach(input => {
                    if (input.type === 'file') {
                        console.log(`${input.id} (file): ${input.files?.[0]?.name || 'No file selected'}`);
                    } else if (input.type === 'checkbox') {
                        console.log(`${input.id} (checkbox): checked=${input.checked}, value=${input.value}`);
                    } else if (input.type === 'radio') {
                        console.log(`${input.name} (radio): checked=${input.checked}, value=${input.value}`);
                    } else {
                        console.log(`${input.id} (${input.type || input.tagName}): ${input.value}`);
                    }
                });
            }
            console.log('=== END DEBUG ===');
        }

        // Make debug function available globally for testing
        window.debugStageData = debugStageData;
        
        // Function to clear saved file data
        function clearSavedFile(inputId) {
            const input = document.getElementById(inputId);
            if (input) {
                // Remove data attribute
                input.removeAttribute('data-saved-file');
                
                // Clear the file input
                input.value = '';
                
                // Remove the indicator
                const indicator = document.getElementById(`file-indicator-${inputId}`);
                if (indicator) {
                    indicator.remove();
                }
                
                console.log('Cleared saved file for:', inputId);
            }
        }
        
        // Make clearSavedFile available globally
        window.clearSavedFile = clearSavedFile;
        
        // Debug function to show complete submission data
        function debugSubmissionData() {
            console.log('=== COMPLETE SUBMISSION DEBUG ===');
            
            // Show stageData
            console.log('Current stageData:', JSON.stringify(stageData, (key, value) => {
                if (key === 'fileObject') return '[File Object]';
                return value;
            }, 2));
            
            // Show formData
            console.log('Current formData:', JSON.stringify(formData, null, 2));
            
            // Show all stages in DOM
            const stageCards = document.querySelectorAll('.stage-card, .stage-nav-item');
            console.log('=== DOM STAGES ===');
            stageCards.forEach(stage => {
                const stageId = stage.getAttribute('data-stage-id') || stage.getAttribute('data-stage');
                const stageName = stage.textContent.trim();
                console.log(`Stage: ${stageId} - ${stageName}`);
            });
            
            // Show current modal state
            const modal = document.getElementById('stageQuestionsModal');
            console.log('Modal open:', modal?.style.display !== 'none');
            console.log('Current modal stage:', currentModalStage);
            console.log('Current modal occurrence:', currentModalOccurrence);
            
            // Show all file inputs
            const allFileInputs = document.querySelectorAll('input[type="file"]');
            console.log('=== ALL FILE INPUTS ===');
            allFileInputs.forEach(input => {
                console.log(`${input.id}: ${input.files?.[0]?.name || 'No file'}`);
            });
            
            console.log('=== END SUBMISSION DEBUG ===');
        }
        
        window.debugSubmissionData = debugSubmissionData;

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
                alert('Please select a location before submitting.');
                return;
            }
            
            // Show loading state
            loadingSpinner.style.display = 'block';
            submitBtn.style.display = 'none';
            
            try {
                // Collect all form data for DHIS2 submission
                // Ensure TEI data is collected from any open modal
                const teiModal = document.getElementById('stageQuestionsModal');
                if (teiModal && teiModal.style.display !== 'none') {
                    const modalTitle = teiModal.querySelector('h4');
                    if (modalTitle && modalTitle.textContent.includes('Participant Information')) {
                        console.log('Collecting TEI data from open modal...');
                        const container = document.getElementById('modalQuestionsContainer');
                        const teiInputs = container.querySelectorAll('input, select, textarea');
                        
                        teiInputs.forEach(input => {
                            if (input.value) {
                                const match = input.id.match(/^tei_([^_]+)_\d+$/);
                                if (match) {
                                    const attributeId = match[1];
                                    if (!formData.trackedEntityAttributes) {
                                        formData.trackedEntityAttributes = {};
                                    }
                                    formData.trackedEntityAttributes[attributeId] = input.value;
                                    console.log('Added TEI attribute:', attributeId, input.value);
                                }
                            }
                        });
                    }
                }
                
                const submissionData = {
                    survey_id: document.getElementById('surveyId').value,
                    location_data: {
                        facility_id: document.getElementById('facilityId').value,
                        facility_name: document.getElementById('facilityName').value,
                        orgunit_uid: document.getElementById('facilityOrgunitUid').value,
                        hierarchy_path: document.getElementById('hierarchyData').value
                    },
                    form_data: {
                        trackedEntityAttributes: formData.trackedEntityAttributes || {},
                        events: []
                    }
                };
                
                // IMPORTANT: Save current modal data if a stage is currently open
                const modal = document.getElementById('stageQuestionsModal');
                if (modal && modal.style.display !== 'none' && currentModalStage) {
                    console.log('Saving current open modal data before submission...');
                    const eventDate = document.getElementById('modalEventDate').value;
                    const container = document.getElementById('modalQuestionsContainer');
                    
                    if (eventDate) {
                        const tempOccurrenceData = { eventDate: eventDate, dataElements: {} };
                        const inputs = container.querySelectorAll('input, select, textarea');
                        
                        inputs.forEach(input => {
                            // Use the same logic as saveStageData for consistency
                            if (input.type === 'file') {
                                if (input.files && input.files[0]) {
                                    tempOccurrenceData.dataElements[input.id] = {
                                        fileName: input.files[0].name,
                                        fileSize: input.files[0].size,
                                        fileType: input.files[0].type,
                                        fileObject: input.files[0],
                                        isFile: true
                                    };
                                }
                            } else if (input.type === 'checkbox') {
                                tempOccurrenceData.dataElements[input.id] = {
                                    value: input.checked ? input.value : '',
                                    checked: input.checked,
                                    isCheckbox: true
                                };
                            } else if (input.type === 'radio') {
                                if (input.checked) {
                                    tempOccurrenceData.dataElements[input.name] = {
                                        value: input.value,
                                        isRadio: true
                                    };
                                }
                            } else if (input.tagName.toLowerCase() === 'select') {
                                if (input.value) {
                                    tempOccurrenceData.dataElements[input.id] = {
                                        value: input.value,
                                        selectedText: input.options[input.selectedIndex]?.text || input.value,
                                        isSelect: true
                                    };
                                }
                            } else if (input.value !== undefined && input.value !== '') {
                                tempOccurrenceData.dataElements[input.id] = {
                                    value: input.value,
                                    type: input.type
                                };
                            }
                        });
                        
                        // Add current modal data to stageData temporarily for submission
                        if (!stageData[currentModalStage]) {
                            stageData[currentModalStage] = {};
                        }
                        const tempOccurrenceKey = `${currentModalStage}_${currentModalOccurrence || 1}`;
                        stageData[currentModalStage][tempOccurrenceKey] = tempOccurrenceData;
                        console.log('Added current modal data:', tempOccurrenceKey, tempOccurrenceData);
                    }
                }
                
                // Also collect any data from visible stage sections (non-modal data)
                const stageCards = document.querySelectorAll('.stage-card');
                stageCards.forEach(stageCard => {
                    const stageId = stageCard.getAttribute('data-stage-id');
                    if (stageId) {
                        // Look for any unsaved data in visible stage inputs
                        const stageInputs = stageCard.querySelectorAll('input, select, textarea');
                        stageInputs.forEach(input => {
                            if (input.value && input.getAttribute('data-de-id')) {
                                const deId = input.getAttribute('data-de-id');
                                const occurrence = input.getAttribute('data-occurrence') || '1';
                                const occurrenceKey = `${stageId}_${occurrence}`;
                                
                                // Initialize if not exists
                                if (!stageData[stageId]) {
                                    stageData[stageId] = {};
                                }
                                if (!stageData[stageId][occurrenceKey]) {
                                    stageData[stageId][occurrenceKey] = {
                                        eventDate: new Date().toISOString().split('T')[0], // Default to today
                                        dataElements: {}
                                    };
                                }
                                
                                // Add data element
                                stageData[stageId][occurrenceKey].dataElements[input.id] = {
                                    value: input.value,
                                    type: input.type
                                };
                                console.log('Added visible stage data:', occurrenceKey, deId, input.value);
                            }
                        });
                    }
                });
                
                console.log('Complete stageData before submission:', stageData);
                
                // Debug: Check for file field conflicts
                const fileFieldAnalysis = {};
                Object.keys(stageData).forEach(stageId => {
                    Object.keys(stageData[stageId]).forEach(occurrenceKey => {
                        const elements = stageData[stageId][occurrenceKey].dataElements || {};
                        Object.keys(elements).forEach(inputId => {
                            if (elements[inputId].isFile) {
                                const deId = inputId.match(/^modal_([^_]+)_\d+$/)?.[1];
                                if (deId) {
                                    if (!fileFieldAnalysis[deId]) {
                                        fileFieldAnalysis[deId] = [];
                                    }
                                    fileFieldAnalysis[deId].push({
                                        inputId,
                                        occurrenceKey,
                                        fileName: elements[inputId].fileName
                                    });
                                }
                            }
                        });
                    });
                });
                
                console.log('=== FILE FIELD ANALYSIS ===');
                Object.keys(fileFieldAnalysis).forEach(deId => {
                    const files = fileFieldAnalysis[deId];
                    console.log(`Data Element ${deId}: ${files.length} files`);
                    files.forEach((file, index) => {
                        console.log(`  ${index + 1}. ${file.fileName} (${file.occurrenceKey})`);
                    });
                    if (files.length > 1) {
                        console.warn(`⚠️  Multiple files assigned to same data element: ${deId}`);
                        
                        // Check if files are in same occurrence (file splitting needed) or different occurrences (legitimate)
                        const occurrences = [...new Set(files.map(f => f.occurrenceKey))];
                        if (occurrences.length === 1) {
                            console.warn(`   → Files are in SAME occurrence (${occurrences[0]}) - will split into separate events`);
                        } else {
                            console.log(`   → Files are in DIFFERENT occurrences - this is normal`);
                        }
                    }
                });
                console.log('=== END FILE ANALYSIS ===');
                
                // Convert stage data to DHIS2 events format (simplified - one file per field)
                Object.keys(stageData).forEach(stageId => {
                    const stageOccurrences = stageData[stageId];
                    Object.keys(stageOccurrences).forEach(occurrenceKey => {
                        const occurrenceData = stageOccurrences[occurrenceKey];
                        
                        // Create event for DHIS2
                        const event = {
                            programStage: stageId,
                            eventDate: occurrenceData.eventDate,
                            dataValues: {}
                        };
                        
                        // Process all data elements (files and non-files)
                        Object.keys(occurrenceData.dataElements || {}).forEach(inputId => {
                            const match = inputId.match(/^modal_([^_]+)_\d+$/);
                            if (match) {
                                const deId = match[1];
                                const savedData = occurrenceData.dataElements[inputId];
                                
                                let finalValue = '';
                                if (savedData && savedData.isFile) {
                                    console.log(`Checking file field ${inputId}:`, {
                                        fileName: savedData.fileName,
                                        hasFileObject: !!savedData.fileObject,
                                        fileObjectType: typeof savedData.fileObject
                                    });
                                    
                                    // Check if this file is actually available for upload
                                    if (savedData.fileObject && savedData.fileObject instanceof File) {
                                        finalValue = `FILE_PLACEHOLDER:${inputId}`;
                                        console.log(`✓ Creating placeholder for ${inputId} - file object available`);
                                    } else {
                                        console.warn(`✗ Skipping file field ${inputId} - file object not available (was: ${savedData.fileName})`);
                                        finalValue = ''; // Skip this field entirely - will not be sent to DHIS2
                                    }
                                } else if (savedData && savedData.isCheckbox) {
                                    finalValue = savedData.checked ? savedData.value : '';
                                } else if (savedData && savedData.isRadio) {
                                    finalValue = savedData.value;
                                } else if (savedData && savedData.isSelect) {
                                    finalValue = savedData.value;
                                } else if (savedData && savedData.value !== undefined) {
                                    finalValue = savedData.value;
                                } else if (typeof savedData === 'string' || typeof savedData === 'number') {
                                    finalValue = savedData;
                                }
                                
                                if (finalValue !== '') {
                                    event.dataValues[deId] = finalValue;
                                }
                            }
                        });
                        
                        submissionData.form_data.events.push(event);
                    });
                });
                
                console.log(`Final events to submit: ${submissionData.form_data.events.length} events`);
                submissionData.form_data.events.forEach((event, index) => {
                    console.log(`  Event ${index + 1} (${event.programStage}): ${Object.keys(event.dataValues).length} data values`);
                });
                
                console.log('Submitting data to DHIS2:', submissionData);
                
                // Create FormData to handle both regular data and files
                const submissionFormData = new FormData();
                submissionFormData.append('survey_id', submissionData.survey_id);
                submissionFormData.append('form_data', JSON.stringify(submissionData.form_data));
                submissionFormData.append('location_data', JSON.stringify(submissionData.location_data));
                
                // Collect ALL files from all sources (no overriding - each input field can have its own file)
                const finalFiles = new Map(); // Map of input field ID to file
                console.log('=== FILE COLLECTION DEBUG ===');
                console.log('Stage data keys:', Object.keys(stageData));
                
                // First, collect files from saved stage data
                Object.keys(stageData).forEach(stageId => {
                    console.log(`Processing stage: ${stageId}`);
                    const stageOccurrences = stageData[stageId];
                    Object.keys(stageOccurrences).forEach(occurrenceKey => {
                        console.log(`  Processing occurrence: ${occurrenceKey}`);
                        const occurrenceData = stageOccurrences[occurrenceKey];
                        if (occurrenceData.dataElements) {
                            console.log(`    Found ${Object.keys(occurrenceData.dataElements).length} data elements`);
                            Object.keys(occurrenceData.dataElements).forEach(inputId => {
                                const value = occurrenceData.dataElements[inputId];
                                console.log(`    Checking element ${inputId}:`, value);
                                if (value && value.isFile && value.fileObject) {
                                    finalFiles.set(inputId, {
                                        file: value.fileObject,
                                        fileName: value.fileName,
                                        inputId: inputId,
                                        source: 'saved_stage'
                                    });
                                    console.log(`✓ File collected from saved data: ${inputId} -> ${value.fileName}`);
                                } else if (value && value.isFile) {
                                    console.log(`✗ File data missing fileObject for ${inputId}:`, {
                                        isFile: value.isFile,
                                        fileName: value.fileName,
                                        hasFileObject: !!value.fileObject
                                    });
                                }
                            });
                        } else {
                            console.log(`    No data elements found for occurrence ${occurrenceKey}`);
                        }
                    });
                });
                
                // Then, add files from ALL file inputs (including saved file states)
                const fileFields = document.querySelectorAll('input[type="file"]');
                console.log(`Found ${fileFields.length} visible file inputs`);
                fileFields.forEach((fileField, index) => {
                    console.log(`  Visible file input ${index}: ${fileField.id} (has files: ${!!(fileField.files && fileField.files[0])})`);
                    
                    // Check if this input has a saved file (from data attribute or saved state indicator)
                    const savedFileData = fileField.getAttribute('data-saved-file');
                    let hasSavedFile = false;
                    if (savedFileData) {
                        try {
                            const savedInfo = JSON.parse(savedFileData);
                            hasSavedFile = savedInfo.hasSavedFile;
                            console.log(`    Input ${fileField.id} has saved file: ${savedInfo.fileName}`);
                        } catch (e) {
                            console.log(`    Could not parse saved file data for ${fileField.id}`);
                        }
                    }
                    
                    // Collect file if it exists OR if there's a saved file
                    if (fileField.files && fileField.files[0]) {
                        console.log(`    Current file: ${fileField.files[0].name}`);
                        finalFiles.set(fileField.id, {
                            file: fileField.files[0],
                            fileName: fileField.files[0].name,
                            inputId: fileField.id,
                            source: 'current_visible'
                        });
                        console.log(`✓ File collected from visible input: ${fileField.id} -> ${fileField.files[0].name}`);
                    } else if (hasSavedFile) {
                        console.log(`✗ File input ${fileField.id} shows saved file but no actual file object available`);
                        console.log(`    This is expected - file inputs lose their files after modal close/reopen`);
                        
                        // For saved files without current file objects, we'll skip sending this to DHIS2
                        // The placeholder will remain, causing the error we're seeing
                        // TODO: Need to prompt user to re-select files or implement file persistence
                        console.warn(`WARNING: File for ${fileField.id} was saved but is no longer available for upload`);
                    }
                });
                
                // Add ALL files to FormData
                console.log(`=== FILE COLLECTION SUMMARY ===`);
                console.log(`Total files to upload: ${finalFiles.size}`);
                if (finalFiles.size === 0) {
                    console.warn('WARNING: No files collected! This means no files will be uploaded to DHIS2.');
                }
                finalFiles.forEach((fileInfo, inputId) => {
                    submissionFormData.append('files[' + fileInfo.inputId + ']', fileInfo.file);
                    console.log(`✓ Added to FormData: files[${fileInfo.inputId}] = ${fileInfo.fileName} (${fileInfo.source})`);
                });
                console.log('=== END FILE COLLECTION ===');
                
                // Submit to backend
                const response = await fetch('tracker_program_submit.php', {
                    method: 'POST',
                    body: submissionFormData // No Content-Type header needed for FormData
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
            console.log('Groupings disabled - using default form layout');
            // Groupings functionality disabled for now to prevent API errors
            return;
            
            const surveyId = document.getElementById('surveyId').value;
            
            try {
                // First, try to load from database
                const response = await fetch(`/fbs/public/api/groupings.php?survey_id=${surveyId}`);
                
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
            
            // Load existing files after a short delay to ensure DOM is ready
            setTimeout(async () => {
                await loadExistingFiles();
            }, 500);
            
            // Navigation active state is managed by navigateToStage function
            // Don't automatically set first section as active here
            
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