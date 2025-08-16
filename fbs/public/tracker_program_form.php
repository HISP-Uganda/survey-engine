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
    'logo_path' => 'admin/argon-dashboard-master/assets/img/loog.jpg',
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
    
    <!-- CSS Dependencies -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Custom Styles -->
    <style>
        :root {
            --primary-color: #2563eb;
            --success-color: #10b981;
            --secondary-color: #6b7280;
            --border-radius: 12px;
        }
        
        body {
            background: #f8fafc;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            margin: 0;
            font-size: 14px;
        }
        
        /* Header Styles */
        .tracker-header {
            background: white;
            border-bottom: 1px solid #e5e7eb;
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .tracker-header .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 1rem;
        }
        
        .logo-section {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .flag-bar {
            display: flex;
            height: 4px;
            margin-top: 0.5rem;
        }
        
        .flag-segment {
            flex: 1;
        }
        
        /* Step Navigation */
        .step-navigation {
            background: white;
            padding: 1.5rem 0;
            margin-bottom: 1.5rem;
        }
        
        .step-navigation .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 1rem;
        }
        
        .steps-container {
            display: flex;
            justify-content: center;
            align-items: center;
            max-width: 600px;
            margin: 0 auto;
            position: relative;
        }
        
        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            flex: 1;
            text-align: center;
        }
        
        .step-number {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.4rem;
            border: 2px solid #e5e7eb;
            background: white;
            color: #6b7280;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .step.completed .step-number {
            background: var(--success-color);
            color: white;
            border-color: var(--success-color);
        }
        
        .step.active .step-number {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .step-label {
            font-weight: 500;
            color: #6b7280;
            font-size: 0.8rem;
        }
        
        .step.completed .step-label,
        .step.active .step-label {
            color: #374151;
        }
        
        .step-connector {
            position: absolute;
            top: 24px;
            left: 50%;
            width: calc(100% - 48px);
            height: 2px;
            background: #e5e7eb;
            z-index: -1;
        }
        
        .step.completed .step-connector {
            background: var(--success-color);
        }
        
        .step:last-child .step-connector {
            display: none;
        }
        
        /* Main Content */
        .main-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 1rem;
            margin-bottom: 2rem;
        }
        
        .form-section {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            overflow: hidden;
        }
        
        .section-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: between;
            align-items: center;
        }
        
        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #111827;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .section-status {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-completed {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-active {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .status-pending {
            background: #f3f4f6;
            color: #6b7280;
        }
        
        .section-content {
            padding: 1.5rem;
        }
        
        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.8rem;
            max-width: 500px;
        }
        
        .form-group {
            display: flex;
            flex-direction: row;
            align-items: flex-start;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 24px 32px;
            margin-bottom: 18px;
            gap: 80px;
            transition: all 0.3s ease;
            width: 100%;
            box-sizing: border-box;
        }
        
        .form-group:hover {
            border-color: #3b82f6;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.1);
        }
        
        /* Question label area */
        .form-group .form-label {
            width: 35%;
            max-width: 320px;
            min-width: 280px;
            flex-shrink: 0;
            margin-bottom: 0;
            padding-top: 8px;
            word-wrap: break-word;
            font-size: 15px;
            line-height: 1.4;
            font-weight: 500;
        }
        
        /* Answer area */
        .form-group .answer-area {
            flex: 1;
            width: 65%;
            min-width: 400px;
            max-width: 100%;
        }
        
        .form-group .answer-area .form-control,
        .form-group .answer-area .form-check,
        .form-group .answer-area select {
            width: 100%;
            min-height: 42px;
            font-size: 14px;
        }
        
        /* Special styling for dropdowns to ensure they're large enough */
        .form-group .answer-area select.form-control {
            min-height: 48px;
            padding: 12px 16px;
            background-color: #ffffff;
            border: 2px solid #d1d5db;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 400;
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 12px center;
            background-repeat: no-repeat;
            background-size: 16px;
            padding-right: 48px;
        }
        
        .form-group .answer-area select.form-control:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
            outline: none;
        }
        
        .form-group .answer-area select.form-control:hover {
            border-color: #9ca3af;
        }
        
        .form-group .input-hint {
            margin-top: 8px;
            font-size: 12px;
            color: #6b7280;
        }
        
        .form-label {
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.4rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 13px;
        }
        
        .required-indicator {
            color: #dc2626;
            font-size: 0.9rem;
        }
        
        .form-control {
            padding: 0.6rem 0.8rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 13px;
            transition: all 0.2s ease;
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        .form-help {
            font-size: 0.85rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }
        
        /* Sub-tabs for Data Entry */
        .sub-tabs {
            background: #f8fafc;
            padding: 1rem 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .sub-tabs-nav {
            display: flex;
            gap: 0.5rem;
            padding: 0 2rem;
            flex-wrap: wrap;
        }
        
        .sub-tab {
            padding: 0.5rem 1rem;
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            color: #6b7280;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: fit-content;
        }
        
        .sub-tab:hover {
            background: #f3f4f6;
            border-color: #9ca3af;
        }
        
        .sub-tab.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .sub-tab-status {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #e5e7eb;
        }
        
        .sub-tab.completed .sub-tab-status {
            background: var(--success-color);
        }
        
        .sub-tab.active .sub-tab-status {
            background: white;
        }
        
        /* Action Buttons */
        .action-buttons {
            padding: 1.5rem 2rem;
            border-top: 1px solid #e5e7eb;
            background: #f9fafb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.95rem;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        
        .btn-primary {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
        }
        
        .btn-success {
            background: var(--success-color);
            color: white;
        }
        
        .btn-success:hover {
            background: #059669;
            transform: translateY(-1px);
        }
        
        .btn-outline {
            background: white;
            color: #6b7280;
            border: 1px solid #d1d5db;
        }
        
        .btn-outline:hover {
            background: #f3f4f6;
            color: #374151;
        }
        
        /* Modal Improvements */
        .modal-content {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }
        
        .modal-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        
        .modal-footer {
            padding: 1.5rem 2rem;
            border-top: 1px solid #e5e7eb;
            background: #f9fafb;
        }
        
        /* Summary Report Styles */
        .summary-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: var(--border-radius);
            overflow: hidden;
            margin-bottom: 1rem;
        }
        
        .summary-header {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 600;
            color: #374151;
        }
        
        .summary-content {
            padding: 1.5rem;
        }
        
        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .summary-item:last-child {
            border-bottom: none;
        }
        
        .summary-label {
            font-weight: 500;
            color: #6b7280;
        }
        
        .summary-value {
            color: #111827;
            font-weight: 500;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .form-grid {
                max-width: 100%;
            }
            
            .steps-container {
                padding: 0 1rem;
            }
            
            .step-label {
                font-size: 0.8rem;
            }
            
            .sub-tabs-nav {
                flex-direction: column;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }
        }
        
        /* Location Table Styles */
        .location-table-container {
            max-height: 200px;
            overflow-y: auto;
            overflow: visible;
        }
        
        .location-table-container.dropdown-active {
            overflow: visible;
            max-height: none;
        }
        
        .location-table {
            margin-bottom: 0;
            font-size: 13px;
        }
        
        .location-table td {
            padding: 8px 10px;
            vertical-align: middle;
        }
        
        .location-table .field-label {
            background-color: #f8f9fa;
            font-weight: 500;
            width: 25%;
            color: #495057;
        }
        
        .location-table .field-value {
            width: 75%;
        }
        
        .facility-results {
            max-height: 200px;
            overflow-y: auto;
        }
        
        .facility-item {
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
            font-size: 13px;
        }
        
        .facility-item:hover {
            background-color: #f8f9fa;
        }
        
        .facility-item:last-child {
            border-bottom: none;
        }
        
        .facility-name {
            font-weight: 500;
            color: #333;
        }
        
        .facility-path {
            font-size: 11px;
            color: #666;
            margin-top: 2px;
        }
        
        /* Stage Cards */
        .stage-card {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-bottom: 1rem;
            overflow: hidden;
            transition: all 0.2s ease;
        }
        
        .stage-card:hover {
            border-color: #d1d5db;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .stage-card-header {
            background: #f9fafb;
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .stage-card-title {
            font-weight: 600;
            color: #374151;
            margin: 0;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .stage-card-status {
            font-size: 11px;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-weight: 500;
            text-transform: uppercase;
        }
        
        .stage-card-body {
            padding: 1rem;
        }
        
        .stage-card-actions {
            padding: 0.75rem 1rem;
            background: #f9fafb;
            border-top: 1px solid #e5e7eb;
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }
        
        .occurrence-badge {
            background: #dbeafe;
            color: #1e40af;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .occurrence-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        
        .occurrence-item {
            padding: 0.5rem;
            background: #f8fafc;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 0.5rem;
        }
        
        .occurrence-main {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .occurrence-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .occurrence-actions {
            display: flex;
            gap: 0.25rem;
        }
        
        /* Occurrence summary styling */
        .occurrence-summary {
            margin-top: 8px;
            padding: 8px;
            background: #f1f5f9;
            border-radius: 6px;
            border-left: 3px solid #3b82f6;
        }
        
        .summary-table {
            width: 100%;
            font-size: 12px;
            margin-bottom: 4px;
        }
        
        .summary-table td {
            padding: 2px 4px;
            vertical-align: top;
        }
        
        .summary-table .field-name {
            font-weight: 500;
            color: #374151;
            width: 40%;
        }
        
        .summary-table .field-value {
            color: #6b7280;
            width: 60%;
            word-break: break-word;
        }
        
        .summary-table .more-fields {
            text-align: center;
            font-style: italic;
            padding-top: 4px;
        }
        
        
        .occurrence-item.has-data {
            border-left: 4px solid #10b981;
            background: #f0fdf4;
        }
        
        .occurrence-item.empty {
            border-left: 4px solid #e5e7eb;
        }
        
        /* Review section occurrence styling */
        .occurrence-review-item {
            margin-bottom: 16px;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        
        .occurrence-review-item.has-data {
            border-left: 4px solid #10b981;
            background: #f0fdf4;
        }
        
        .occurrence-review-item.empty {
            border-left: 4px solid #e5e7eb;
            background: #f9fafb;
        }
        
        .occurrence-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .occurrence-title {
            font-weight: 600;
            color: #374151;
            font-size: 14px;
        }
        
        .occurrence-status {
            font-size: 13px;
            font-weight: 500;
        }
        
        /* Adjust summary styling for review section */
        .occurrence-review-item .occurrence-summary {
            margin-top: 0;
            background: rgba(248, 250, 252, 0.8);
            border-left: 3px solid #60a5fa;
        }
        
        /* Question Type Specific Styles */
        .coordinate-input {
            margin-bottom: 0;
        }
        
        .coordinate-input .row {
            margin: 0;
        }
        
        .file-preview {
            padding: 0.5rem;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .form-check {
            padding-left: 1.5rem;
        }
        
        .form-check-input {
            margin-left: -1.5rem;
            margin-top: 0.25rem;
        }
        
        .form-check-label {
            margin-bottom: 0;
            cursor: pointer;
        }
        
        /* Uniform checkbox styling with other questions */
        .form-group .form-check {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
            padding: 0;
        }
        
        .form-group .form-check-input {
            width: 18px;
            height: 18px;
            margin: 0;
            border: 2px solid #d1d5db;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }
        
        .form-group .form-check-input:checked {
            background-color: #3b82f6;
            border-color: #3b82f6;
        }
        
        .form-group .form-check-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-group .form-check-label {
            font-weight: 500;
            color: #374151;
            cursor: pointer;
            flex-grow: 1;
            margin: 0;
        }
        
        
        /* Horizontal checkbox layout for multiple options */
        .checkbox-options-container {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 8px;
        }
        
        .checkbox-options-container .form-check {
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
            min-width: auto;
        }
        
        /* Ensure checkbox groups flow horizontally when they have multiple items */
        .form-group:has(.form-check + .form-check) {
            display: block;
        }
        
        .form-group:has(.form-check + .form-check) .form-check {
            display: inline-flex;
            margin-right: 20px;
            margin-bottom: 8px;
        }
        
        /* Text area specific styling */
        .form-group textarea.form-control {
            width: 100%;
            max-width: 100%;
            resize: vertical;
            min-height: 80px;
        }
        
        /* Better width utilization for all form controls */
        .form-group .form-control {
            width: 100%;
            max-width: 100%;
        }
        
        /* Responsive adjustments for side-by-side layout */
        @media (max-width: 768px) {
            .form-group {
                flex-direction: column;
                gap: 16px;
                padding: 16px;
            }
            
            .form-group .form-label {
                width: 100%;
                min-width: auto;
                max-width: none;
                padding-top: 0;
            }
            
            .form-group .answer-area {
                width: 100%;
                min-width: auto;
            }
            
            /* Mobile responsive for modal */
            .modal-group-content .form-group {
                flex-direction: column;
                gap: 12px;
                padding: 16px 8px;
            }
            
            .modal-group-content .form-group .form-label {
                width: 100%;
                min-width: auto;
                max-width: none;
            }
            
            .modal-group-content .form-group .answer-area {
                width: 100%;
                min-width: auto;
                max-width: none;
            }
            
            .modal-body {
                padding: 16px 8px;
            }
        }
        
        @media (max-width: 1024px) {
            .modal-group-content .form-group .form-label {
                width: 40%;
                min-width: 150px;
                max-width: 250px;
            }
            
            .modal-group-content .form-group .answer-area {
                width: 55%;
                min-width: 200px;
                max-width: 300px;
            }
        }
        
        @media (min-width: 1200px) {
            .modal-group-content .form-group .form-label {
                width: 40%;
                min-width: 200px;
                max-width: 320px;
            }
            
            .modal-group-content .form-group.input-type-dropdown .answer-area {
                width: 55%;
                min-width: 300px;
                max-width: 450px;
            }
        }
        
        /* Input validation styles */
        .form-control:invalid {
            border-color: #dc3545;
        }
        
        .form-control:valid {
            border-color: #198754;
        }
        
        /* Textarea resize */
        textarea.form-control {
            resize: vertical;
            min-height: calc(1.5em + 0.75rem + 2px);
        }
        
        /* Number input styling */
        input[type="number"].form-control {
            text-align: right;
        }
        
        /* Select styling */
        select.form-control {
            cursor: pointer;
        }
        
        /* Date/time input styling */
        input[type="date"].form-control,
        input[type="datetime-local"].form-control,
        input[type="time"].form-control {
            cursor: pointer;
        }
        
        /* File input styling */
        input[type="file"].form-control {
            padding: 0.375rem 0.75rem;
        }
        
        input[type="file"].form-control::-webkit-file-upload-button {
            padding: 0.25rem 0.75rem;
            margin: -0.375rem -0.75rem;
            margin-right: 0.75rem;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem 0 0 0.25rem;
            cursor: pointer;
        }
        
        /* Hidden by default */
        .form-section {
            display: none;
        }
        
        .form-section.active {
            display: block;
        }
        
        /* Checkbox styling for modal questions - positioned to the right */
        .modal-question-item .form-check {
            margin-bottom: 0;
            padding: 12px 16px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            min-height: auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .modal-question-item .form-check-input {
            width: 20px;
            height: 20px;
            margin: 0;
            position: static;
            transform: none;
            flex-shrink: 0;
            border: 2px solid #6c757d;
            background-color: #fff;
            cursor: pointer;
        }

        .modal-question-item .form-check-input:checked {
            background-color: #0d6efd;
            border-color: #0d6efd;
        }

        .modal-question-item .form-check-label {
            color: #495057;
            font-size: 14px;
            margin: 0;
            font-weight: 500;
            cursor: pointer;
            flex: 1;
        }
        
        /* Number input styling */
        .number-input-container {
            max-width: 200px;
        }
        
        .number-input-container input[type="number"] {
            text-align: center;
            font-weight: 500;
        }
        
        /* Input hint styling */
        .input-hint {
            font-size: 0.75rem;
            color: #6c757d;
            margin-top: 4px;
            font-style: italic;
        }
        
        .input-hint.error {
            color: #dc3545;
        }
        
        /* Different input container sizes */
        .input-container-small {
            max-width: 150px;
        }
        
        .input-container-medium {
            max-width: 400px;
        }
        
        .input-container-large {
            max-width: 100%;
        }
        
        /* Grouping Styles */
        .modal-group-section {
            margin-bottom: 20px;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .modal-group-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #e9ecef;
        }

        .modal-group-title {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
            color: #495057;
        }

        .modal-group-content {
            padding: 16px 8px;
            background: white;
        }
        
        /* Better spacing for form grid within modal groups */
        .modal-group-content .form-grid {
            gap: 0;
            width: 100%;
        }
        
        /* Adjust modal form groups for modal-lg with proper left-right layout */
        .modal-group-content .form-group {
            display: flex;
            flex-direction: row;
            align-items: flex-start;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 18px 150px 18px 16px;
            margin-bottom: 14px;
            transition: all 0.3s ease;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            justify-content: space-between;
            margin-left: 0px;
            margin-right: 0px;
        }
        
        /* Remove gaps - using space-between layout now */
        
        .modal-group-content .form-group:hover {
            border-color: #3b82f6;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.1);
        }
        
        .modal-group-content .form-group .form-label {
            width: 40%;
            min-width: 200px;
            max-width: 300px;
            font-size: 14px;
            line-height: 1.4;
            font-weight: 500;
            color: #374151;
            margin-bottom: 0;
            padding-top: 8px;
            padding-right: 20px;
            word-wrap: break-word;
            hyphens: auto;
            flex-shrink: 0;
        }
        
        .modal-group-content .form-group .answer-area {
            width: 55%;
            min-width: 250px;
            max-width: 400px;
            flex-shrink: 0;
        }
        
        /* Dynamic answer area sizing based on input type */
        .modal-group-content .form-group.input-type-number .answer-area {
            min-width: 150px;
            max-width: 200px;
        }
        
        .modal-group-content .form-group.input-type-date .answer-area {
            min-width: 180px;
            max-width: 220px;
        }
        
        .modal-group-content .form-group.input-type-dropdown .answer-area {
            width: 60%;
            min-width: 320px;
            max-width: 480px;
        }
        
        .modal-group-content .form-group.input-type-textarea .answer-area {
            min-width: 300px;
            max-width: 420px;
        }
        
        .modal-group-content .form-group.input-type-checkbox .answer-area {
            min-width: 100px;
            max-width: 150px;
        }
        
        /* Enhanced dropdown styling for larger answer area */
        .modal-group-content .form-group .answer-area select.form-control {
            min-height: 46px;
            padding: 12px 16px;
            font-size: 15px;
            width: 100%;
        }
        
        /* Add Bootstrap mb-3 equivalent for form groups */
        .modal-group-content .form-group.mb-3 {
            margin-bottom: 1rem;
        }
        
        /* Ensure modal body uses full width and reaches edges */
        .modal-body {
            padding: 12px 0px;
        }
        
        #modalQuestionsContainer {
            width: 100%;
            max-width: 100%;
            padding: 0 8px;
        }
        
        /* TEI Attributes Container styling - separate from modal styling */
        #teiAttributesContainer {
            width: 100%;
            max-width: 100%;
            padding: 20px;
        }
        
        #teiAttributesContainer .form-group {
            display: flex;
            flex-direction: row;
            align-items: flex-start;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 20px 24px;
            margin-bottom: 16px;
            gap: 50px;
            transition: all 0.3s ease;
            width: 100%;
            box-sizing: border-box;
        }
        
        #teiAttributesContainer .form-group:hover {
            border-color: #3b82f6;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.1);
        }
        
        #teiAttributesContainer .form-group .form-label {
            width: 30%;
            max-width: 250px;
            min-width: 200px;
            flex-shrink: 0;
            margin-bottom: 0;
            padding-top: 8px;
            word-wrap: break-word;
            font-size: 14px;
            line-height: 1.4;
            font-weight: 500;
            color: #374151;
        }
        
        #teiAttributesContainer .form-group .answer-area {
            flex: 1;
            width: 70%;
            min-width: 300px;
            max-width: 100%;
        }
        
        #teiAttributesContainer .form-group .answer-area .form-control,
        #teiAttributesContainer .form-group .answer-area .form-check,
        #teiAttributesContainer .form-group .answer-area select {
            width: 100%;
            min-height: 40px;
            font-size: 14px;
        }
        
        #teiAttributesContainer .form-group .answer-area select.form-control {
            min-height: 42px;
            padding: 10px 12px;
            background-color: #ffffff;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        
        #teiAttributesContainer .form-group .input-hint {
            margin-top: 6px;
            font-size: 11px;
            color: #6b7280;
        }
        
        /* Responsive adjustments for TEI section */
        @media (max-width: 768px) {
            #teiAttributesContainer .form-group {
                flex-direction: column;
                gap: 12px;
                padding: 16px;
            }
            
            #teiAttributesContainer .form-group .form-label {
                width: 100%;
                min-width: auto;
                max-width: none;
                padding-top: 0;
            }
            
            #teiAttributesContainer .form-group .answer-area {
                width: 100%;
                min-width: auto;
            }
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
    </style>

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
    
    <!-- Core JavaScript Functions - Must be defined before HTML onclick handlers -->
    <script>
        // Global variables
        let programData;
        let formData = {
            trackedEntityInstance: null,
            trackedEntityAttributes: {},
            events: [],
            stages: {}
        };
        let stageOccurrences = {};
        let stageData = {};
        let currentStep = 'location';
        let currentStageId = null;
        let currentStageOccurrence = 1;
        let currentFilteredLocations = [];
        let selectedLocation = null;

        // Navigation Functions - declared at window level for onclick access
        window.navigateToStep = function(stepName) {
            console.log('Navigating to step:', stepName);
            
            // Update step navigation
            document.querySelectorAll('.step').forEach(step => {
                step.classList.remove('active');
                if (step.getAttribute('data-step') === stepName) {
                    step.classList.add('active');
                }
            });
            
            // Update all step completion statuses when navigating
            updateAllStepStatuses();
            
            // Update form sections
            document.querySelectorAll('.form-section').forEach(section => {
                section.classList.remove('active');
                if (section.getAttribute('data-step') === stepName) {
                    section.classList.add('active');
                }
            });
            
            currentStep = stepName;
            
            // Special handling for different steps - handle async calls properly
            switch(stepName) {
                case 'location':
                    if (typeof initializeLocationSelection === 'function') {
                        initializeLocationSelection().catch(e => console.error('Location init error:', e));
                    }
                    break;
                case 'participant':
                    if (typeof populateTEIAttributes === 'function') {
                        populateTEIAttributes().catch(e => console.error('TEI attributes error:', e));
                    }
                    break;
                case 'data-entry':
                    if (typeof populateStagesCards === 'function') {
                        populateStagesCards();
                    }
                    break;
                case 'review':
                    if (typeof generateSummaryReport === 'function') {
                        generateSummaryReport();
                    }
                    break;
            }
        };

        // openStageModal will be defined later with full implementation

        window.saveStageData = function() {
            console.log('Saving stage data...');
            // Simple implementation for now - will be enhanced after DOM ready
            const modalElement = document.getElementById('stageModal');
            if (modalElement) {
                const modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) modal.hide();
            }
        };
        
        window.selectFacility = function(facilityId, facilityName, facilityPath) {
            console.log('Selecting facility:', facilityId, facilityName);
            selectedLocation = { id: facilityId, name: facilityName, path: facilityPath };
            
            const facilitySearch = document.getElementById('facilitySearch');
            const facilityResults = document.getElementById('facilityResults');
            const locationNextBtn = document.getElementById('locationNextBtn');
            
            if (facilitySearch) facilitySearch.value = facilityName;
            if (facilityResults) facilityResults.style.display = 'none';
            if (locationNextBtn) locationNextBtn.disabled = false;
            
            // Update location status
            const locationStatus = document.getElementById('locationStatus');
            if (locationStatus) {
                locationStatus.textContent = 'COMPLETED';
                locationStatus.className = 'section-status status-completed';
            }
            
            // Update the selected location display with readable path
            const selectedLocationDisplay = document.getElementById('selectedLocationDisplay');
            if (selectedLocationDisplay) {
                selectedLocationDisplay.innerHTML = `
                    <div><strong>${facilityName}</strong></div>
                    <div class="text-muted" style="font-size: 0.9em;" id="locationPathDisplay">Loading path...</div>
                `;
                
                // Load the actual path
                loadLocationPath(facilityId, document.getElementById('locationPathDisplay'));
            }
        };
        
        // Stage management functions
        window.addStageOccurrence = function(stageId) {
            // This will be implemented after DOM ready
            console.log('Adding stage occurrence for:', stageId);
        };
        
        window.removeStageOccurrence = function(stageId, occurrence) {
            console.log('Removing stage occurrence:', stageId, occurrence);
        };
        
        // Utility functions
        window.showSuccessMessage = function(message) {
            // Create or update success toast
            let toast = document.getElementById('successToast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'successToast';
                toast.className = 'toast-message toast-success';
                toast.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    background: #10b981;
                    color: white;
                    padding: 12px 16px;
                    border-radius: 8px;
                    z-index: 9999;
                    font-size: 14px;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    transform: translateX(100%);
                    transition: transform 0.3s ease;
                `;
                document.body.appendChild(toast);
            }
            
            toast.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span><i class="fas fa-check-circle me-2"></i>${message}</span>
                    <button onclick="this.parentElement.parentElement.style.transform='translateX(100%)'" 
                            style="background: none; border: none; color: white; font-size: 16px; cursor: pointer; padding: 0; margin-left: 12px;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            toast.style.transform = 'translateX(0)';
            
            // Clear any existing timeout
            if (toast.hideTimeout) {
                clearTimeout(toast.hideTimeout);
            }
            
            // Auto-hide after 4 seconds
            toast.hideTimeout = setTimeout(() => {
                toast.style.transform = 'translateX(100%)';
                // Completely remove after animation completes
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                }, 300);
            }, 4000);
        };
    </script>
</head>
<body>
    <!-- Header -->
    <header class="tracker-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="logo-section">
                        <?php if ($surveySettings['show_logo'] && !empty($surveySettings['logo_path'])): ?>
                            <img src="/fbs/admin/<?= htmlspecialchars($surveySettings['logo_path']) ?>" alt="Logo" style="height: 40px;" onerror="this.style.display='none'">
                        <?php endif; ?>
                        <h1 class="h5 mb-0"><?= htmlspecialchars($trackerProgram['name'] ?? $surveySettings['title_text']) ?></h1>
                    </div>
                </div>
                <!-- <div class="col-md-6 text-end">
                    <span class="text-muted">Tracker Program</span>
                </div> -->
            </div>
        </div>
    </header>

    <!-- Step Navigation -->
    <div class="step-navigation">
        <div class="container">
            <div class="steps-container">
                <div class="step active" data-step="location" onclick="navigateToStep('location')">
                    <div class="step-number">1</div>
                    <div class="step-label">Location</div>
                    <div class="step-connector"></div>
                </div>
                <div class="step" data-step="participant" onclick="navigateToStep('participant')">
                    <div class="step-number">2</div>
                    <div class="step-label">Participant</div>
                    <div class="step-connector"></div>
                </div>
                <div class="step" data-step="data-entry" onclick="navigateToStep('data-entry')">
                    <div class="step-number">3</div>
                    <div class="step-label">Data Entry</div>
                    <div class="step-connector"></div>
                </div>
                <div class="step" data-step="review" onclick="navigateToStep('review')">
                    <div class="step-number">4</div>
                    <div class="step-label">Review</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-container">
        <!-- Location Section -->
        <div class="form-section active" id="locationSection" data-step="location">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-map-marker-alt"></i>
                    Select Location
                </h2>
                <span class="section-status status-pending" id="locationStatus">REQUIRED</span>
            </div>
            <div class="section-content">
                <!-- Location Information Table -->
                <div class="location-table-section" id="locationTableSection">
                    <div class="location-table-container">
                        <table class="table table-sm table-bordered location-table" id="locationTable">
                            <tbody>
                                <tr>
                                    <td class="field-label">Search Location</td>
                                    <td class="field-value">
                                        <div style="position: relative;">
                                            <input type="text" 
                                                   id="facilitySearch" 
                                                   name="facility_search" 
                                                   class="form-control" 
                                                   placeholder="Type to search locations..."
                                                   autocomplete="off"
                                                   required>
                                            <div id="facilityResults" 
                                                 class="facility-results expandable-dropdown" 
                                                 style="display: none; position: absolute; z-index: 1050; 
                                                        background: white; border: 1px solid #ccc; 
                                                        border-radius: 6px; max-height: 120px; 
                                                        overflow-y: auto; width: 100%; margin-top: 2px; 
                                                        box-shadow: 0 2px 8px rgba(0,0,0,0.15); 
                                                        transition: max-height 0.3s ease;"></div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="field-label">Selected Location</td>
                                    <td class="field-value">
                                        <div class="readonly-value" id="selectedLocationDisplay">No location selected yet</div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Hidden inputs for selected facility data -->
                    <input type="hidden" id="facilityId" name="facility_id" required>
                    <input type="hidden" id="facilityName" name="facility_name">
                    <input type="hidden" id="facilityOrgunitUid" name="facility_orgunit_uid">
                    <input type="hidden" id="hierarchyData" name="hierarchy_data">
                </div>
            </div>
            
            <div class="action-buttons">
                <div>
                    <!-- No back button for first step -->
                </div>
                <button type="button" class="btn btn-success" id="locationNextBtn" onclick="navigateToStep('participant')" disabled>
                    Continue to Participant
                    <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>

        <!-- Participant Information Section -->
        <div class="form-section" id="participantSection" data-step="participant">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-user text-primary"></i>
                    Participant Information
                </h2>
                <span class="section-status status-active">ACTIVE</span>
            </div>
            <div class="section-content">
                <form id="participantForm">
                    <!-- TEI Attributes Container -->
                    <div id="teiAttributesContainer" class="form-grid">
                        <!-- Will be populated by JavaScript -->
                    </div>
                </form>
            </div>
            <div class="action-buttons">
                <button type="button" class="btn btn-outline" onclick="navigateToStep('location')">
                    <i class="fas fa-arrow-left"></i>
                    Back
                </button>
                <button type="button" class="btn btn-success" onclick="navigateToStep('data-entry')">
                    Continue to Data Entry
                    <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>

        <!-- Data Entry Section -->
        <div class="form-section" id="dataEntrySection" data-step="data-entry">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-edit"></i>
                    Data Entry
                </h2>
                <span class="section-status status-pending">REQUIRED</span>
            </div>
            
            <div class="section-content">
                <div id="stagesContainer">
                    <!-- Stage cards will be populated by JavaScript -->
                </div>
            </div>
            
            <div class="action-buttons">
                <button type="button" class="btn btn-outline" onclick="navigateToStep('participant')">
                    <i class="fas fa-arrow-left"></i>
                    Back
                </button>
                <button type="button" class="btn btn-success" onclick="navigateToStep('review')">
                    Continue to Review
                    <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>

        <!-- Review & Submit Section -->
        <div class="form-section" id="reviewSection" data-step="review">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-clipboard-check text-warning"></i>
                    Review & Submit
                </h2>
                <span class="section-status status-pending">FINAL STEP</span>
            </div>
            <div class="section-content">
                <div id="summaryReport">
                    <!-- Summary will be populated by JavaScript -->
                </div>
            </div>
            <div class="action-buttons">
                <button type="button" class="btn btn-outline" onclick="navigateToStep('data-entry')">
                    <i class="fas fa-arrow-left"></i>
                    Back to Data Entry
                </button>
                <button type="button" class="btn btn-primary" onclick="submitAllData()" id="finalSubmitBtn">
                    <i class="fas fa-paper-plane"></i>
                    Submit Data
                </button>
            </div>
        </div>
    </div>

    <!-- Stage Modal -->
    <div class="modal fade" id="stageModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Stage Data</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="modalQuestionsContainer">
                        <!-- Stage questions will be populated here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveStageData()">
                        <i class="fas fa-save"></i>
                        Save & Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Spinner -->
    <div id="loadingSpinner" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 9999;">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Implementation functions - the stubs above will be replaced with these on DOM ready

        // Location Functions
        async function initializeLocationSelection() {
            console.log('Initializing location selection...');
            console.log('Program data available:', !!programData);
            console.log('Survey settings:', programData?.surveySettings);
            
            const facilitySearch = document.getElementById('facilitySearch');
            const facilityResults = document.getElementById('facilityResults');
            
            console.log('facilitySearch element:', facilitySearch);
            console.log('facilityResults element:', facilityResults);
            
            if (!facilitySearch || !facilityResults) {
                console.error('Required DOM elements not found for location selection');
                return;
            }
            
            // Fetch locations for this survey
            console.log('About to fetch locations...');
            await fetchLocationsForSurveyPage();
            
            // Set up search functionality
            facilitySearch.addEventListener('input', function() {
                const searchTerm = this.value.trim();
                if (searchTerm.length >= 2) {
                    searchAndDisplayFacilities(searchTerm);
                } else {
                    facilityResults.style.display = 'none';
                }
            });
            
            facilitySearch.addEventListener('focus', function() {
                const searchTerm = this.value.trim();
                if (searchTerm.length >= 2) {
                    searchAndDisplayFacilities(searchTerm);
                }
            });
            
            // Hide results when clicking outside
            document.addEventListener('click', function(e) {
                if (!facilitySearch.contains(e.target) && !facilityResults.contains(e.target)) {
                    facilityResults.style.display = 'none';
                }
            });
        }
        
        async function fetchLocationsForSurveyPage() {
            try {
                console.log('Fetching locations for survey ID:', programData.surveySettings.id);
                const url = `/fbs/admin/get_locations.php?survey_id=${programData.surveySettings.id}`;
                console.log('Location API URL:', url);
                
                const response = await fetch(url);
                console.log('Location response status:', response.status);
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                
                const locations = await response.json();
                console.log('Raw location response:', locations);
                
                currentFilteredLocations = Array.isArray(locations) ? locations : [];
                console.log(`Loaded ${currentFilteredLocations.length} locations for survey`);
                
                if (currentFilteredLocations.length === 0) {
                    console.warn('No locations found for this survey');
                }
            } catch (error) {
                console.error('Error fetching locations:', error);
                currentFilteredLocations = [];
                
                // Show user-friendly message
                const facilityResults = document.getElementById('facilityResults');
                if (facilityResults) {
                    facilityResults.innerHTML = '<div class="facility-item" style="color: red;">Error loading locations. Please refresh the page.</div>';
                    facilityResults.style.display = 'block';
                    setTimeout(() => {
                        facilityResults.style.display = 'none';
                    }, 3000);
                }
            }
        }
        
        function searchAndDisplayFacilities(searchTerm) {
            const facilityResults = document.getElementById('facilityResults');
            if (!facilityResults) return;
            
            const filteredLocations = currentFilteredLocations.filter(location => 
                location.name.toLowerCase().includes(searchTerm.toLowerCase())
            ).slice(0, 10); // Limit to 10 results
            
            facilityResults.innerHTML = '';
            
            if (filteredLocations.length === 0) {
                facilityResults.innerHTML = '<div class="facility-item">No locations found</div>';
            } else {
                filteredLocations.forEach(location => {
                    const facilityItem = document.createElement('div');
                    facilityItem.className = 'facility-item';
                    facilityItem.innerHTML = `
                        <div class="facility-name">${location.name}</div>
                        <div class="facility-path">Loading path...</div>
                    `;
                    
                    // Store location data for later use
                    facilityItem.dataset.locationId = location.id;
                    facilityItem.dataset.locationName = location.name;
                    facilityItem.dataset.orgunitUid = location.uid || '';
                    
                    facilityItem.onclick = () => {
                        const path = facilityItem.dataset.locationPath || '';
                        selectFacility(
                            location.id, 
                            location.name, 
                            location.uid || '',
                            path
                        );
                    };
                    
                    facilityResults.appendChild(facilityItem);
                    
                    // Load path asynchronously
                    loadLocationPath(location.id, facilityItem.querySelector('.facility-path'), facilityItem);
                });
            }
            
            facilityResults.style.display = 'block';
        }
        
        async function loadLocationPath(locationId, pathElement, facilityItem) {
            try {
                const response = await fetch(`/fbs/admin/get_location_path.php?id=${locationId}`);
                const data = await response.json();
                if (data.success && data.path) {
                    pathElement.textContent = data.path;
                    facilityItem.dataset.locationPath = data.path;
                } else {
                    pathElement.textContent = 'Path unavailable';
                    facilityItem.dataset.locationPath = '';
                }
            } catch (error) {
                pathElement.textContent = 'Path unavailable';
                facilityItem.dataset.locationPath = '';
            }
        }
        
        function selectFacility(facilityId, facilityName, orgunitUid, facilityPath) {
            // Update hidden inputs
            document.getElementById('facilityId').value = facilityId;
            document.getElementById('facilityName').value = facilityName;
            document.getElementById('facilityOrgunitUid').value = orgunitUid;
            document.getElementById('hierarchyData').value = facilityPath;
            
            // Update search input
            document.getElementById('facilitySearch').value = facilityName;
            
            // Hide dropdown
            document.getElementById('facilityResults').style.display = 'none';
            
            // Update location display
            updateLocationDisplay(facilityId, facilityName);
            
            selectedLocation = { id: facilityId, name: facilityName, orgunitUid, path: facilityPath };
            
            // Update step status
            updateStepStatus('location', 'completed');
            
            // Enable next button
            const nextBtn = document.getElementById('locationNextBtn');
            if (nextBtn) {
                nextBtn.disabled = false;
            }
        }
        
        async function updateLocationDisplay(facilityId, facilityName) {
            const display = document.getElementById('selectedLocationDisplay');
            if (!display) return;
            
            display.innerHTML = `<i class="fas fa-spinner fa-spin me-2"></i>Loading location details...`;
            
            try {
                const response = await fetch(`/fbs/admin/get_location_path.php?id=${facilityId}`);
                const data = await response.json();
                
                if (data.success && data.path) {
                    display.innerHTML = `
                        <div class="facility-name">${facilityName}</div>
                        <div class="facility-path">${data.path}</div>
                    `;
                } else {
                    display.innerHTML = facilityName;
                }
            } catch (error) {
                display.innerHTML = facilityName;
            }
        }
        
        function updateStepStatus(stepName, status) {
            const step = document.querySelector(`[data-step="${stepName}"]`);
            if (step) {
                step.classList.remove('completed', 'active');
                if (status === 'completed') {
                    step.classList.add('completed');
                    const stepNumber = step.querySelector('.step-number');
                    if (stepNumber) {
                        stepNumber.innerHTML = '<i class="fas fa-check"></i>';
                    }
                }
                
                // Update status in section header
                const statusElement = step.querySelector('.section-status');
                if (statusElement) {
                    if (status === 'completed') {
                        statusElement.innerHTML = '<i class="fas fa-check-circle me-1"></i>COMPLETED';
                        statusElement.className = 'section-status status-completed';
                    } else {
                        statusElement.textContent = 'REQUIRED';
                        statusElement.className = 'section-status status-pending';
                    }
                }
            }
        }
        
        // Function to check if a step is completed
        function isStepCompleted(stepName) {
            switch (stepName) {
                case 'location':
                    return !!selectedLocation;
                case 'participant':
                    // Check if we have any TEI attributes defined
                    if (!programData.program?.programTrackedEntityAttributes) {
                        console.log('❌ No TEI attributes defined');
                        return false;
                    }
                    
                    const requiredAttrs = programData.program.programTrackedEntityAttributes.filter(attr => attr.mandatory) || [];
                    console.log(`📋 Participant step check: ${requiredAttrs.length} mandatory attributes`);
                    console.log('Current formData.trackedEntityAttributes:', formData.trackedEntityAttributes);
                    
                    // If there are no mandatory attributes, check if ANY attribute has been filled
                    if (requiredAttrs.length === 0) {
                        const allAttrs = programData.program.programTrackedEntityAttributes;
                        const hasAnyData = allAttrs.some(attr => {
                            const value = formData.trackedEntityAttributes[attr.trackedEntityAttribute.id];
                            return value && value.trim() !== '';
                        });
                        console.log(`✓ No mandatory attrs, checking if any filled: ${hasAnyData}`);
                        return hasAnyData;
                    }
                    
                    // Check that all mandatory attributes are filled
                    const allFilled = requiredAttrs.every(attr => {
                        const value = formData.trackedEntityAttributes[attr.trackedEntityAttribute.id];
                        const isFilled = value && value.trim() !== '';
                        console.log(`  - ${attr.trackedEntityAttribute.name}: ${isFilled ? '✓' : '✗'} (value: "${value}")`);
                        return isFilled;
                    });
                    console.log(`✓ All mandatory attrs filled: ${allFilled}`);
                    return allFilled;
                case 'data-entry':
                    // Check if at least one stage has meaningful data
                    if (!formData.stages) return false;
                    
                    // Use the same logic as stage completion checking
                    return Object.keys(formData.stages).some(stageId => hasStageRealData(stageId));
                case 'review':
                    return false; // Review step is never "completed" until submission
                default:
                    return false;
            }
        }
        
        // Function to update all step statuses
        function updateAllStepStatuses() {
            const steps = ['location', 'participant', 'data-entry'];
            steps.forEach(step => {
                if (isStepCompleted(step)) {
                    updateStepStatus(step, 'completed');
                }
            });
        }

        // Function to generate input hints based on question type
        function getInputHint(valueType, attribute) {
            const hints = {
                'TEXT': 'Enter text',
                'LONG_TEXT': 'Enter detailed text or description',
                'NUMBER': 'Enter a number (can include decimals)',
                'INTEGER': 'Enter a whole number',
                'INTEGER_POSITIVE': 'Enter a positive whole number (1, 2, 3...)',
                'INTEGER_NEGATIVE': 'Enter a negative whole number (...-3, -2, -1)',
                'INTEGER_ZERO_OR_POSITIVE': 'Enter zero or a positive whole number (0, 1, 2...)',
                'DATE': 'Select a date from the calendar',
                'DATETIME': 'Select date and time',
                'TIME': 'Select a time',
                'EMAIL': 'Enter a valid email address (example@domain.com)',
                'PHONE_NUMBER': 'Enter a phone number',
                'URL': 'Enter a web address (https://example.com)',
                'BOOLEAN': 'Select Yes or No',
                'TRUE_ONLY': 'Check if applicable',
                'PERCENTAGE': 'Enter a percentage value (0-100)',
                'UNIT_INTERVAL': 'Enter a value between 0 and 1',
                'AGE': 'Enter age in years',
                'FILE_RESOURCE': 'Upload a file',
                'IMAGE': 'Upload an image file',
                'COORDINATE': 'Enter coordinates or use map'
            };
            
            let hint = hints[valueType] || 'Enter value';
            
            // Add specific constraints if available
            if (attribute) {
                if (valueType === 'INTEGER_POSITIVE' && attribute.min) {
                    hint += ` (minimum: ${attribute.min})`;
                } else if (valueType === 'INTEGER_ZERO_OR_POSITIVE' && attribute.min) {
                    hint += ` (minimum: ${attribute.min})`;
                } else if (attribute.min && attribute.max) {
                    hint += ` (${attribute.min} - ${attribute.max})`;
                } else if (attribute.min) {
                    hint += ` (minimum: ${attribute.min})`;
                } else if (attribute.max) {
                    hint += ` (maximum: ${attribute.max})`;
                }
            }
            
            return hint;
        }
        
        
        // Option Set Loading Function
        async function loadOptionSetOptions(selectElement, optionSet) {
            try {
                console.log('=== Loading option set options ===');
                console.log('Option set structure:', optionSet);
                console.log('Option set ID:', optionSet.id);
                console.log('Has options:', !!optionSet.options);
                console.log('Options array length:', optionSet.options?.length);
                console.log('First option sample:', optionSet.options?.[0]);
                
                // First try to use options from the optionSet if they exist
                if (optionSet.options && optionSet.options.length > 0) {
                    console.log(`✓ Using ${optionSet.options.length} options from optionSet.options`);
                    optionSet.options.forEach((option, index) => {
                        console.log(`Adding option ${index}:`, option);
                        const optionElement = document.createElement('option');
                        optionElement.value = option.code || option.value || option.option_value;
                        optionElement.textContent = option.displayName || option.name || option.label || option.option_value;
                        selectElement.appendChild(optionElement);
                    });
                    console.log('✓ Successfully loaded options from optionSet.options');
                    return;
                }
                
                // Fallback to local database
                console.log('Loading option set from local database:', optionSet.id);
                const response = await fetch(`/fbs/admin/get_option_set_values.php?option_set_id=${optionSet.id}`);
                
                if (response.ok) {
                    const options = await response.json();
                    console.log('Local database response:', options);
                    if (Array.isArray(options) && options.length > 0) {
                        console.log(`Loaded ${options.length} options from local database`);
                        options.forEach(option => {
                            const optionElement = document.createElement('option');
                            optionElement.value = option.code || option.value || option.option_value;
                            optionElement.textContent = option.displayName || option.label || option.option_value;
                            selectElement.appendChild(optionElement);
                        });
                        console.log('Successfully loaded options from local database');
                        return;
                    } else {
                        console.log('No valid options found in local database response');
                    }
                } else {
                    console.log('Local database request failed:', response.status, response.statusText);
                }
                
                // Fallback to DHIS2 API
                console.log('Loading option set from DHIS2 API:', optionSet.id);
                const dhis2Response = await fetch(`/fbs/admin/dhis2/dhis2_fetch.php?endpoint=optionSets/${optionSet.id}.json?fields=options[code,displayName]`);
                
                if (dhis2Response.ok) {
                    const dhis2Data = await dhis2Response.json();
                    if (dhis2Data.options && dhis2Data.options.length > 0) {
                        console.log(`Loaded ${dhis2Data.options.length} options from DHIS2 API`);
                        dhis2Data.options.forEach(option => {
                            const optionElement = document.createElement('option');
                            optionElement.value = option.code;
                            optionElement.textContent = option.displayName;
                            selectElement.appendChild(optionElement);
                        });
                        return;
                    }
                }
                
                // If all fails, add a placeholder
                console.warn('Could not load options for option set:', optionSet.id);
                const errorOption = document.createElement('option');
                errorOption.value = '';
                errorOption.textContent = 'Options could not be loaded';
                errorOption.disabled = true;
                selectElement.appendChild(errorOption);
                
            } catch (error) {
                console.error('Error loading option set options:', error);
                const errorOption = document.createElement('option');
                errorOption.value = '';
                errorOption.textContent = 'Error loading options';
                errorOption.disabled = true;
                selectElement.appendChild(errorOption);
            }
        }

        // TEI Attributes Population
        async function populateTEIAttributes() {
            console.log('Populating TEI attributes...');
            const container = document.getElementById('teiAttributesContainer');
            console.log('TEI container found:', !!container);
            console.log('Program data available:', !!programData);
            if (!container || !programData) return;
            
            container.innerHTML = '';
            
            const attributes = programData.program?.programTrackedEntityAttributes || [];
            
            for (const attrConfig of attributes) {
                const attribute = attrConfig.trackedEntityAttribute;
                if (!attribute) return;
                
                const formGroup = document.createElement('div');
                formGroup.className = 'form-group';
                
                // Apply uniform styling - no special container classes needed
                
                const label = document.createElement('label');
                label.className = 'form-label';
                label.setAttribute('for', `tei_${attribute.id}`);
                
                // Clean the display name by removing prefixes like PM_, TP_, etc.
                let cleanName = attribute.displayName || attribute.name;
                cleanName = cleanName.replace(/^[A-Z]{2,3}_/i, '');
                
                label.innerHTML = `
                    ${cleanName}
                    ${attrConfig.mandatory ? '<span class="required-indicator">*</span>' : ''}
                `;
                
                let inputElement;
                
                // Create appropriate input based on attribute value type
                switch (attribute.valueType) {
                    case 'TEXT':
                        inputElement = document.createElement('input');
                        inputElement.type = 'text';
                        inputElement.className = 'form-control';
                        inputElement.placeholder = 'Enter text...';
                        break;
                        
                    case 'LONG_TEXT':
                        inputElement = document.createElement('textarea');
                        inputElement.className = 'form-control';
                        inputElement.rows = 3;
                        inputElement.placeholder = 'Enter detailed text...';
                        break;
                        
                    case 'NUMBER':
                    case 'INTEGER':
                    case 'INTEGER_POSITIVE':
                    case 'INTEGER_ZERO_OR_POSITIVE':
                        inputElement = document.createElement('input');
                        inputElement.type = 'number';
                        inputElement.className = 'form-control';
                        if (attribute.valueType === 'INTEGER_POSITIVE') {
                            inputElement.min = '1';
                        } else if (attribute.valueType === 'INTEGER_ZERO_OR_POSITIVE') {
                            inputElement.min = '0';
                        }
                        break;
                        
                    case 'DATE':
                        inputElement = document.createElement('input');
                        inputElement.type = 'date';
                        inputElement.className = 'form-control';
                        break;
                        
                    case 'DATETIME':
                        inputElement = document.createElement('input');
                        inputElement.type = 'datetime-local';
                        inputElement.className = 'form-control';
                        break;
                        
                    case 'EMAIL':
                        inputElement = document.createElement('input');
                        inputElement.type = 'email';
                        inputElement.className = 'form-control';
                        inputElement.placeholder = 'example@domain.com';
                        break;
                        
                    case 'PHONE_NUMBER':
                        inputElement = document.createElement('input');
                        inputElement.type = 'tel';
                        inputElement.className = 'form-control';
                        inputElement.placeholder = '+256 xxx xxx xxx';
                        break;
                        
                    case 'BOOLEAN':
                        inputElement = document.createElement('input');
                        inputElement.type = 'checkbox';
                        inputElement.className = 'form-check-input';
                        formGroup.className = 'form-group form-check';
                        label.className = 'form-check-label';
                        break;
                        
                    default:
                        // Handle option sets or default to text
                        if (attribute.optionSet) {
                            console.log('TEI Attribute with option set:', attribute.name, 'Options count:', attribute.optionSet.options?.length);
                            console.log('Full TEI optionSet:', attribute.optionSet);
                            
                            inputElement = document.createElement('select');
                            inputElement.className = 'form-control';
                            
                            const defaultOption = document.createElement('option');
                            defaultOption.value = '';
                            defaultOption.textContent = 'Select an option...';
                            defaultOption.disabled = true;
                            defaultOption.selected = true;
                            inputElement.appendChild(defaultOption);
                            
                            // First check if options are already in the optionSet (from PHP processing)
                            if (attribute.optionSet.options && attribute.optionSet.options.length > 0) {
                                console.log(`✓ Using pre-loaded TEI options (${attribute.optionSet.options.length}) for:`, attribute.name);
                                attribute.optionSet.options.forEach((option, index) => {
                                    console.log(`Adding pre-loaded TEI option ${index}:`, option);
                                    const optionElement = document.createElement('option');
                                    optionElement.value = option.code || option.value || option.option_value;
                                    optionElement.textContent = option.displayName || option.name || option.label || option.option_value;
                                    inputElement.appendChild(optionElement);
                                });
                            } else {
                                // Fallback to API loading
                                console.log('No pre-loaded TEI options, trying API for:', attribute.name);
                                await loadOptionSetOptions(inputElement, attribute.optionSet);
                            }
                            
                            console.log(`✓ TEI attribute ${attribute.name} has ${inputElement.options.length} options`);
                            if (inputElement.options.length <= 1) {
                                console.warn(`⚠️ TEI SELECT element for ${attribute.name} only has ${inputElement.options.length} options!`);
                            }
                        } else {
                            // Default to text input
                            inputElement = document.createElement('input');
                            inputElement.type = 'text';
                            inputElement.className = 'form-control';
                        }
                        break;
                }
                
                inputElement.id = `tei_${attribute.id}`;
                inputElement.name = attribute.id;
                inputElement.setAttribute('data-attribute-id', attribute.id);
                
                if (attrConfig.mandatory) {
                    inputElement.required = true;
                }
                
                // Load saved value if exists
                if (formData.trackedEntityAttributes[attribute.id]) {
                    if (inputElement.type === 'file') {
                        // File inputs cannot have their value set programmatically
                        console.log('Skipping value setting for TEI file input:', inputElement.id);
                    } else {
                        inputElement.value = formData.trackedEntityAttributes[attribute.id];
                    }
                }
                
                // Add change listener
                inputElement.addEventListener('change', function() {
                    formData.trackedEntityAttributes[attribute.id] = this.value;
                    console.log('TEI attribute saved:', attribute.id, this.value);
                    
                    // Update step status when TEI attributes change
                    updateAllStepStatuses();
                });
                
                // Create input hint
                const inputHint = document.createElement('div');
                inputHint.className = 'input-hint';
                inputHint.textContent = getInputHint(attribute.valueType, attribute);
                
                // Create answer area wrapper for consistent side-by-side layout
                const answerArea = document.createElement('div');
                answerArea.className = 'answer-area';
                
                // Special handling for checkboxes
                if (inputElement.type === 'checkbox') {
                    const checkDiv = document.createElement('div');
                    checkDiv.className = 'form-check';
                    checkDiv.appendChild(inputElement);
                    
                    const checkLabel = document.createElement('label');
                    checkLabel.className = 'form-check-label';
                    checkLabel.setAttribute('for', inputElement.id);
                    checkLabel.textContent = 'Yes';
                    checkDiv.appendChild(checkLabel);
                    
                    answerArea.appendChild(checkDiv);
                    answerArea.appendChild(inputHint);
                    formGroup.appendChild(label);
                    formGroup.appendChild(answerArea);
                } else {
                    // Standard side-by-side structure
                    answerArea.appendChild(inputElement);
                    answerArea.appendChild(inputHint);
                    formGroup.appendChild(label);
                    formGroup.appendChild(answerArea);
                }
                container.appendChild(formGroup);
            }
        }

        // Stage Cards Population for Data Entry
        function populateStagesCards() {
            console.log('=== Populating stages cards ===');
            const container = document.getElementById('stagesContainer');
            console.log('Stages container found:', !!container);
            console.log('Program data available:', !!programData);
            
            if (!container) {
                console.error('stagesContainer element not found!');
                return;
            }
            
            if (!programData) {
                console.error('Program data not available!');
                return;
            }
            
            container.innerHTML = '';
            
            const stages = programData.program?.programStages || [];
            console.log('Number of stages:', stages.length);
            console.log('Stages data:', stages);
            
            if (stages.length === 0) {
                console.log('No stages found, showing empty state');
                container.innerHTML = `
                    <div class="text-center py-5">
                        <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No stages configured</h5>
                        <p class="text-muted">This program has no stages configured yet.</p>
                    </div>
                `;
                return;
            }
            
            stages.forEach((stage, index) => {
                console.log(`Creating stage card ${index}:`, stage.name);
                const stageCard = document.createElement('div');
                stageCard.className = 'stage-card';
                
                // Check if stage has meaningful data
                const stageDataExists = hasStageRealData(stage.id);
                const occurrences = getStageOccurrences(stage.id);
                
                stageCard.innerHTML = `
                    <div class="stage-card-header">
                        <div class="stage-card-title">
                            <i class="fas fa-clipboard-list"></i>
                            ${stage.name}
                            ${stage.repeatable ? '<span class="occurrence-badge">Repeatable</span>' : ''}
                        </div>
                        <span class="stage-card-status status-${stageDataExists ? 'completed' : 'pending'}">
                            ${stageDataExists ? 'COMPLETED' : 'PENDING'}
                        </span>
                    </div>
                    <div class="stage-card-body">
                        <div class="stage-description">
                            ${stage.description || 'No description available'}
                        </div>
                        ${stage.repeatable ? `
                            <div class="occurrence-instructions mb-3">
                                <div class="alert alert-info py-2">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Instructions:</strong> This stage can have multiple occurrences. 
                                    Click <i class="fas fa-edit"></i> to enter data, then <i class="fas fa-plus"></i> to add more occurrences.
                                </div>
                            </div>
                            <div class="occurrence-list" id="occurrences_${stage.id}">
                                <!-- Occurrences will be populated -->
                            </div>
                        ` : `
                            <div class="single-stage-instructions mb-3">
                                <div class="alert alert-light py-2">
                                    <i class="fas fa-clipboard-list me-2"></i>
                                    <strong>Single Entry Stage:</strong> Click "Enter Data" below to fill in the required information.
                                </div>
                            </div>
                        `}
                    </div>
                    <div class="stage-card-actions">
                        ${!stage.repeatable ? `
                            <button type="button" class="btn btn-primary btn-sm" 
                                    onclick="openStageModal('${stage.id}', 1)"
                                    title="${stageDataExists ? 'Edit the data for this stage' : 'Enter data for this stage'}"
                                    data-bs-toggle="tooltip">
                                <i class="fas fa-edit"></i> ${stageDataExists ? 'Edit' : 'Enter'} Data
                            </button>
                        ` : ''}
                        ${stage.repeatable ? `
                            <button type="button" class="btn btn-success btn-sm" 
                                    onclick="addStageOccurrence('${stage.id}')"
                                    title="Add a new occurrence of this stage"
                                    data-bs-toggle="tooltip">
                                <i class="fas fa-plus"></i> Add New ${stage.name}
                            </button>
                        ` : ''}
                    </div>
                `;
                
                container.appendChild(stageCard);
                console.log(`✓ Added stage card for: ${stage.name}`);
                
                // Populate occurrences for repeatable stages
                if (stage.repeatable) {
                    // Ensure at least one occurrence exists
                    if (!stageOccurrences[stage.id]) {
                        stageOccurrences[stage.id] = 1;
                    }
                    populateStageOccurrences(stage.id, stageOccurrences[stage.id]);
                }
            });
        }
        
        // Helper function to generate occurrence data summary
        function generateOccurrenceSummary(stageId, occurrenceData) {
            if (!occurrenceData || Object.keys(occurrenceData).length === 0) {
                return '<div class="text-muted small">No data entered</div>';
            }
            
            // Get the stage definition to understand field names
            const stage = programData.program?.programStages?.find(s => s.id === stageId);
            if (!stage) return '<div class="text-muted small">No data entered</div>';
            
            const dataElements = stage.programStageDataElements || [];
            const filledData = [];
            
            // Get the first 3 filled fields
            let count = 0;
            for (const dataElementWrapper of dataElements) {
                if (count >= 3) break;
                
                const dataElement = dataElementWrapper.dataElement;
                const value = occurrenceData[dataElement.id];
                
                if (value !== undefined && value !== '' && value !== null) {
                    // Clean the field name
                    let fieldName = (dataElement.displayName || dataElement.name || '').replace(/^[A-Z]{2,3}_/i, '');
                    if (fieldName.length > 25) {
                        fieldName = fieldName.substring(0, 25) + '...';
                    }
                    
                    // Format the value
                    let displayValue = value;
                    if (typeof value === 'object') {
                        if (value.fileName) {
                            displayValue = `📎 ${value.fileName}`;
                        } else if (value.latitude && value.longitude) {
                            displayValue = `📍 ${value.latitude}, ${value.longitude}`;
                        } else {
                            displayValue = JSON.stringify(value);
                        }
                    } else if (typeof value === 'boolean') {
                        displayValue = value ? '✓ Yes' : '✗ No';
                    } else if (String(displayValue).length > 30) {
                        displayValue = String(displayValue).substring(0, 30) + '...';
                    }
                    
                    filledData.push({ field: fieldName, value: displayValue });
                    count++;
                }
            }
            
            if (filledData.length === 0) {
                return '<div class="text-muted small">No data entered</div>';
            }
            
            // Calculate how many more fields are filled beyond the first 3
            const totalFilled = Object.keys(occurrenceData).filter(key => {
                const val = occurrenceData[key];
                return val !== undefined && val !== '' && val !== null;
            }).length;
            
            let summaryHTML = '<div class="occurrence-summary">';
            summaryHTML += '<table class="summary-table">';
            
            filledData.forEach(item => {
                summaryHTML += `
                    <tr>
                        <td class="field-name">${item.field}:</td>
                        <td class="field-value">${item.value}</td>
                    </tr>
                `;
            });
            
            if (totalFilled > 3) {
                summaryHTML += `
                    <tr>
                        <td colspan="2" class="more-fields">
                            <small class="text-muted">+ ${totalFilled - 3} more fields</small>
                        </td>
                    </tr>
                `;
            }
            
            summaryHTML += '</table>';
            summaryHTML += '</div>';
            
            return summaryHTML;
        }

        // Helper function to check if a stage has real data (not just empty placeholders)
        function hasStageRealData(stageId) {
            if (!formData.stages || !formData.stages[stageId]) {
                return false;
            }
            
            const stageData = formData.stages[stageId];
            
            // Check direct stage data (non-repeatable stages)
            const directDataKeys = Object.keys(stageData).filter(key => !key.startsWith('occurrence_'));
            if (directDataKeys.some(key => {
                const value = stageData[key];
                return value !== undefined && value !== '' && value !== null;
            })) {
                return true;
            }
            
            // Check occurrence data (repeatable stages)
            const occurrenceKeys = Object.keys(stageData).filter(key => key.startsWith('occurrence_'));
            return occurrenceKeys.some(occKey => {
                const occData = stageData[occKey];
                if (!occData || typeof occData !== 'object') return false;
                
                return Object.keys(occData).some(dataKey => {
                    const value = occData[dataKey];
                    return value !== undefined && value !== '' && value !== null;
                });
            });
        }

        function getStageOccurrences(stageId) {
            // Return existing occurrences or default to 1
            console.log(`getStageOccurrences called for stageId: ${stageId}`);
            if (formData.stages && formData.stages[stageId]) {
                const occurrenceKeys = Object.keys(formData.stages[stageId]).filter(key => key.startsWith('occurrence_'));
                console.log(`Found occurrence keys: ${occurrenceKeys}`);
                
                // Always ensure at least occurrence_1 exists for repeatable stages
                if (occurrenceKeys.length === 0) {
                    console.log('No occurrence keys found, creating occurrence_1');
                    formData.stages[stageId][`occurrence_1`] = {};
                    return 1;
                }
                
                const count = occurrenceKeys.length;
                console.log(`Returning count: ${count}`);
                return count;
            }
            console.log('No stage data found, returning 1');
            return 1;
        }
        
        function populateStageOccurrences(stageId, count) {
            console.log(`populateStageOccurrences called with stageId: ${stageId}, count: ${count}`);
            const container = document.getElementById(`occurrences_${stageId}`);
            if (!container) {
                console.error(`Container occurrences_${stageId} not found!`);
                return;
            }
            
            container.innerHTML = '';
            console.log('Container found and cleared');
            
            // Get actual occurrence keys instead of assuming sequential numbering
            if (formData.stages && formData.stages[stageId]) {
                const occurrenceKeys = Object.keys(formData.stages[stageId])
                    .filter(key => key.startsWith('occurrence_'))
                    .sort((a, b) => {
                        const numA = parseInt(a.split('_')[1]);
                        const numB = parseInt(b.split('_')[1]);
                        return numA - numB;
                    });
                
                console.log('Found occurrence keys:', occurrenceKeys);
                
                // If no occurrences exist but count > 0, create occurrence_1
                if (occurrenceKeys.length === 0 && count > 0) {
                    console.log('No occurrences found, creating occurrence_1');
                    formData.stages[stageId][`occurrence_1`] = {};
                    occurrenceKeys.push('occurrence_1');
                }
                
                // Display each actual occurrence
                occurrenceKeys.forEach((key, index) => {
                    const occurrenceNum = parseInt(key.split('_')[1]);
                    const displayNum = index + 1; // Display as 1, 2, 3... regardless of actual key numbers
                    const occurrenceData = formData.stages[stageId][key];
                    const hasData = occurrenceData && Object.keys(occurrenceData).some(dataKey => {
                        const value = occurrenceData[dataKey];
                        return value !== undefined && value !== '' && value !== null;
                    });
                    console.log(`Occurrence ${key} (display as ${displayNum}) hasData:`, hasData, formData.stages[stageId][key]);
                
                    const occurrenceItem = document.createElement('div');
                    occurrenceItem.className = `occurrence-item ${hasData ? 'has-data' : 'empty'}`;
                    
                    // Generate the summary for this occurrence
                    const summaryHTML = hasData ? generateOccurrenceSummary(stageId, formData.stages[stageId][key]) : '';
                    
                    occurrenceItem.innerHTML = `
                        <div class="occurrence-main">
                            <div class="occurrence-info">
                                <span class="occurrence-number">Occurrence ${displayNum}</span>
                                <span class="occurrence-status ${hasData ? 'text-success' : 'text-muted'}">
                                    <i class="fas fa-${hasData ? 'check-circle' : 'circle'}"></i>
                                    ${hasData ? 'Completed' : 'Empty'}
                                </span>
                            </div>
                            <div class="occurrence-actions">
                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                        onclick="openStageModal('${stageId}', ${occurrenceNum})"
                                        title="${hasData ? 'Edit this occurrence' : 'Enter data for this occurrence'}"
                                        data-bs-toggle="tooltip">
                                    <i class="fas fa-edit"></i>
                                </button>
                                ${occurrenceKeys.length > 1 ? `
                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                            onclick="removeStageOccurrence('${stageId}', ${occurrenceNum})"
                                            title="Delete this occurrence"
                                            data-bs-toggle="tooltip">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                ` : ''}
                            </div>
                        </div>
                        ${summaryHTML}
                    `;
                    
                    container.appendChild(occurrenceItem);
                });
            } else {
                // No stage data, create empty occurrence_1
                console.log('No stage data found, creating default occurrence_1');
                if (!formData.stages) formData.stages = {};
                if (!formData.stages[stageId]) formData.stages[stageId] = {};
                formData.stages[stageId][`occurrence_1`] = {};
                
                const occurrenceItem = document.createElement('div');
                occurrenceItem.className = 'occurrence-item empty';
                occurrenceItem.innerHTML = `
                    <div class="occurrence-main">
                        <div class="occurrence-info">
                            <span class="occurrence-number">Occurrence 1</span>
                            <span class="occurrence-status text-muted">
                                <i class="fas fa-circle"></i>
                                Empty
                            </span>
                        </div>
                        <div class="occurrence-actions">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="openStageModal('${stageId}', 1)">
                                <i class="fas fa-edit"></i>
                            </button>
                        </div>
                    </div>
                `;
                container.appendChild(occurrenceItem);
            }
        }
        
        // addStageOccurrence function is now implemented in enhanceWindowFunctions()
        
        function getStageNameById(stageId) {
            const stage = programData.program?.programStages?.find(s => s.id === stageId);
            return stage ? stage.name : 'Stage';
        }
        
        function removeStageOccurrence(stageId, occurrence) {
            if (confirm('Are you sure you want to remove this occurrence?')) {
                // Remove from formData
                if (formData.stages && formData.stages[stageId]) {
                    delete formData.stages[stageId][`occurrence_${occurrence}`];
                }
                
                // Refresh the display
                const newCount = getStageOccurrences(stageId);
                populateStageOccurrences(stageId, newCount);
                
                console.log(`Removed occurrence ${occurrence} for stage ${stageId}`);
            }
        }

        // Stage Sub-tabs Population (keeping for compatibility)
        function populateStageSubTabs() {
            const tabsContainer = document.getElementById('stageSubTabs');
            const contentContainer = document.getElementById('stageContentContainer');
            
            if (!tabsContainer || !contentContainer || !programData) return;
            
            tabsContainer.innerHTML = '';
            contentContainer.innerHTML = '';
            
            const stages = programData.program?.programStages || [];
            
            stages.forEach((stage, index) => {
                // Create sub-tab
                const subTab = document.createElement('div');
                subTab.className = `sub-tab ${index === 0 ? 'active' : ''}`;
                subTab.setAttribute('data-stage-id', stage.id);
                subTab.onclick = () => switchToStage(stage.id);
                
                const statusDot = document.createElement('div');
                statusDot.className = 'sub-tab-status';
                
                const tabText = document.createElement('span');
                tabText.textContent = stage.name;
                
                subTab.appendChild(statusDot);
                subTab.appendChild(tabText);
                tabsContainer.appendChild(subTab);
                
                // Create content area for this stage
                const stageContent = document.createElement('div');
                stageContent.id = `stageContent_${stage.id}`;
                stageContent.className = `stage-content ${index === 0 ? 'active' : ''}`;
                stageContent.style.display = index === 0 ? 'block' : 'none';
                
                // Check if stage has data
                const hasData = formData.stages && formData.stages[stage.id] && Object.keys(formData.stages[stage.id]).length > 0;
                
                if (hasData) {
                    // Show summary of saved data
                    const summaryDiv = document.createElement('div');
                    summaryDiv.className = 'alert alert-info';
                    summaryDiv.innerHTML = `
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-info-circle me-2"></i>
                                This stage has saved data
                            </div>
                            <button class="btn btn-sm btn-primary" onclick="openStageModal('${stage.id}')">
                                <i class="fas fa-edit me-1"></i>Edit
                            </button>
                        </div>
                    `;
                    stageContent.appendChild(summaryDiv);
                    
                    // Update tab status
                    subTab.classList.add('completed');
                } else {
                    // Show empty state
                    const emptyDiv = document.createElement('div');
                    emptyDiv.className = 'text-center py-5';
                    emptyDiv.innerHTML = `
                        <div class="text-muted mb-3">
                            <i class="fas fa-clipboard-list fa-3x"></i>
                        </div>
                        <h5 class="text-muted">No data entered for this stage</h5>
                        <p class="text-muted">Click the button below to start entering data</p>
                        <button class="btn btn-primary" onclick="openStageModal('${stage.id}')">
                            <i class="fas fa-plus me-1"></i>Add Data
                        </button>
                    `;
                    stageContent.appendChild(emptyDiv);
                }
                
                contentContainer.appendChild(stageContent);
            });
            
            // Set the first stage as current if none is set
            if (!currentStageId && stages.length > 0) {
                currentStageId = stages[0].id;
            }
        }

        function switchToStage(stageId) {
            console.log('Switching to stage:', stageId);
            currentStageId = stageId;
            
            // Update tab appearance
            document.querySelectorAll('.sub-tab').forEach(tab => {
                tab.classList.remove('active');
                if (tab.getAttribute('data-stage-id') === stageId) {
                    tab.classList.add('active');
                }
            });
            
            // Update content visibility
            document.querySelectorAll('.stage-content').forEach(content => {
                content.style.display = 'none';
                if (content.id === `stageContent_${stageId}`) {
                    content.style.display = 'block';
                }
            });
        }

        // Summary Report Generation
        function generateSummaryReport() {
            console.log('Generating summary report...');
            const container = document.getElementById('summaryReport');
            console.log('Summary container found:', !!container);
            console.log('Program data available:', !!programData);
            if (!container) return;
            
            container.innerHTML = '';
            
            // Location Information Summary (at the top)
            const locationCard = document.createElement('div');
            locationCard.className = 'summary-card';
            locationCard.innerHTML = `
                <div class="summary-header">
                    <i class="fas fa-map-marker-alt me-2"></i>Location Information
                </div>
                <div class="summary-content" id="locationSummary">
                    <!-- Will be populated -->
                </div>
            `;
            container.appendChild(locationCard);
            
            // Populate location summary
            const locationSummary = document.getElementById('locationSummary');
            if (selectedLocation) {
                locationSummary.innerHTML = `
                    <div class="summary-item">
                        <span class="summary-label">Selected Location</span>
                        <span class="summary-value">
                            <strong>${selectedLocation.name}</strong><br>
                            <small class="text-muted">${selectedLocation.path || 'Path not available'}</small>
                        </span>
                    </div>
                `;
            } else {
                locationSummary.innerHTML = `
                    <div class="summary-item">
                        <span class="summary-label">Selected Location</span>
                        <span class="summary-value text-warning">No location selected yet</span>
                    </div>
                `;
            }
            
            // Participant Information Summary
            const participantCard = document.createElement('div');
            participantCard.className = 'summary-card';
            participantCard.innerHTML = `
                <div class="summary-header">
                    <i class="fas fa-user me-2"></i>Participant Information
                </div>
                <div class="summary-content" id="participantSummary">
                    <!-- Will be populated -->
                </div>
            `;
            container.appendChild(participantCard);
            
            // Populate participant summary
            const participantSummary = document.getElementById('participantSummary');
            const attributes = programData.program?.programTrackedEntityAttributes || [];
            
            attributes.forEach(attrConfig => {
                const attribute = attrConfig.trackedEntityAttribute;
                const value = formData.trackedEntityAttributes[attribute.id] || 'Not provided';
                
                // Clean the display name by removing prefixes like PM_, TP_, etc.
                let cleanName = attribute.displayName || attribute.name;
                cleanName = cleanName.replace(/^[A-Z]{2,3}_/i, '');
                
                const summaryItem = document.createElement('div');
                summaryItem.className = 'summary-item';
                summaryItem.innerHTML = `
                    <span class="summary-label">${cleanName}</span>
                    <span class="summary-value">${value}</span>
                `;
                participantSummary.appendChild(summaryItem);
            });
            
            // Stage Data Summary with Repeatable Support
            const stages = programData.program?.programStages || [];
            stages.forEach(stage => {
                const stageData = formData.stages && formData.stages[stage.id] ? formData.stages[stage.id] : null;
                const hasData = stageData && Object.keys(stageData).length > 0;
                
                const stageCard = document.createElement('div');
                stageCard.className = 'summary-card';
                
                let stageContent = '';
                
                if (stage.repeatable && hasData) {
                    // Show occurrences for repeatable stages
                    const occurrenceKeys = Object.keys(stageData).filter(key => key.startsWith('occurrence_'));
                    const occurrenceCount = Math.max(occurrenceKeys.length, Object.keys(stageData).length > 0 ? 1 : 0);
                    
                    stageContent = `
                        <div class="summary-content">
                            <div class="text-success mb-2">
                                <i class="fas fa-check me-2"></i>
                                ${occurrenceCount} occurrence${occurrenceCount !== 1 ? 's' : ''} completed
                            </div>
                    `;
                    
                    // Show details for each occurrence with summary tables
                    for (let i = 1; i <= occurrenceCount; i++) {
                        const occurrenceData = stageData[`occurrence_${i}`] || stageData;
                        const hasOccurrenceData = occurrenceData && Object.keys(occurrenceData).length > 0;
                        
                        stageContent += `
                            <div class="occurrence-review-item ${hasOccurrenceData ? 'has-data' : 'empty'}">
                                <div class="occurrence-header">
                                    <span class="occurrence-title">Occurrence ${i}</span>
                                    <span class="occurrence-status ${hasOccurrenceData ? 'text-success' : 'text-muted'}">
                                        <i class="fas fa-${hasOccurrenceData ? 'check-circle' : 'circle'} me-1"></i>
                                        ${hasOccurrenceData ? 'Completed' : 'Empty'}
                                    </span>
                                </div>
                                ${hasOccurrenceData ? generateOccurrenceSummary(stage.id, occurrenceData) : '<div class="text-muted small">No data entered</div>'}
                            </div>
                        `;
                    }
                    
                    stageContent += '</div>';
                } else if (hasData) {
                    // Regular stage with data
                    stageContent = `
                        <div class="summary-content">
                            <div class="text-success">
                                <i class="fas fa-check me-2"></i>Data has been entered
                            </div>
                        </div>
                    `;
                } else {
                    // No data
                    stageContent = `
                        <div class="summary-content">
                            <div class="text-muted">No data entered</div>
                        </div>
                    `;
                }
                
                stageCard.innerHTML = `
                    <div class="summary-header">
                        <div>
                            <i class="fas fa-clipboard-list me-2"></i>${stage.name}
                            ${stage.repeatable ? '<span class="occurrence-badge ms-2">Repeatable</span>' : ''}
                        </div>
                        <span>
                            ${hasData ? '<i class="fas fa-check-circle text-success"></i>' : '<i class="fas fa-times-circle text-muted"></i>'}
                        </span>
                    </div>
                    ${stageContent}
                `;
                
                container.appendChild(stageCard);
            });
        }
        
        // Function to load saved groupings from the database/API
        async function loadSavedGroupings(stageId, dataElements) {
            try {
                console.log('Loading saved groupings for stage:', stageId);
                const surveyId = programData.surveySettings?.id;
                
                if (!surveyId) {
                    console.log('No survey ID available, using default grouping');
                    return groupQuestionsByCategory(dataElements);
                }
                
                // Use the working API path
                const apiPath = `/fbs/admin/api/groupings.php?survey_id=${surveyId}`;
                console.log('Loading groupings from:', apiPath);
                
                const response = await fetch(apiPath);
                
                if (response.ok) {
                    const result = await response.json();
                    
                    if (result.success && result.data && result.data[stageId]) {
                        console.log('Found saved groupings for stage:', stageId, result.data[stageId]);
                        
                        // Convert saved groupings to the format expected by the form
                        const savedGroups = result.data[stageId];
                        const questionGroups = {};
                        
                        // Create a lookup map of data elements by ID
                        const elementMap = {};
                        dataElements.forEach(elementConfig => {
                            elementMap[elementConfig.dataElement.id] = elementConfig;
                        });
                        
                        // Process saved groups
                        savedGroups.forEach(group => {
                            const groupTitle = group.groupTitle || 'Unnamed Group';
                            questionGroups[groupTitle] = [];
                            
                            if (group.questions && Array.isArray(group.questions)) {
                                group.questions.forEach(questionRef => {
                                    const elementConfig = elementMap[questionRef.questionId];
                                    if (elementConfig) {
                                        questionGroups[groupTitle].push(elementConfig);
                                    }
                                });
                            }
                        });
                        
                        // Add any ungrouped questions to a default group
                        const groupedQuestionIds = new Set();
                        Object.values(questionGroups).flat().forEach(element => {
                            groupedQuestionIds.add(element.dataElement.id);
                        });
                        
                        const ungroupedElements = dataElements.filter(elementConfig => 
                            !groupedQuestionIds.has(elementConfig.dataElement.id)
                        );
                        
                        if (ungroupedElements.length > 0) {
                            questionGroups['General Information'] = ungroupedElements;
                        }
                        
                        console.log('✓ Successfully loaded custom groupings:', questionGroups);
                        return questionGroups;
                    } else {
                        console.log(`No custom groupings found for stage ${stageId}`);
                    }
                } else {
                    console.log(`Groupings API returned ${response.status}: ${response.statusText}`);
                }
                
            } catch (error) {
                console.error('Error loading saved groupings:', error);
            }
            
            // Fallback to automatic grouping
            console.log('Using automatic grouping as fallback');
            return groupQuestionsByCategory(dataElements);
        }
        
        // Function to group questions by category based on naming patterns
        function groupQuestionsByCategory(dataElements) {
            const groups = {};
            
            dataElements.forEach(elementConfig => {
                const dataElement = elementConfig.dataElement;
                if (!dataElement) return;
                
                // Extract group name from data element name
                let groupName = 'General Information';
                const elementName = dataElement.displayName || dataElement.name || '';
                
                // Common grouping patterns for DHIS2 data elements
                if (elementName.match(/^(TP_|PM_|TR_)/i)) {
                    // Remove prefix and extract group from next part
                    const withoutPrefix = elementName.replace(/^[A-Z]{2,3}_/i, '');
                    const words = withoutPrefix.split(/[_\s]+/);
                    
                    if (words.length > 1) {
                        // Use first word or two words as group name
                        groupName = words.slice(0, 2).join(' ');
                        groupName = groupName.charAt(0).toUpperCase() + groupName.slice(1).toLowerCase();
                    }
                } else {
                    // Try to extract group from element name patterns
                    const words = elementName.split(/[_\s]+/);
                    if (words.length > 1) {
                        // Use first word as group, or look for common patterns
                        if (elementName.toLowerCase().includes('contact')) {
                            groupName = 'Contact Information';
                        } else if (elementName.toLowerCase().includes('address') || elementName.toLowerCase().includes('location')) {
                            groupName = 'Address & Location';
                        } else if (elementName.toLowerCase().includes('date') || elementName.toLowerCase().includes('time')) {
                            groupName = 'Date & Time Information';
                        } else if (elementName.toLowerCase().includes('health') || elementName.toLowerCase().includes('medical')) {
                            groupName = 'Health Information';
                        } else if (elementName.toLowerCase().includes('school') || elementName.toLowerCase().includes('education')) {
                            groupName = 'Education Information';
                        } else {
                            groupName = words[0].charAt(0).toUpperCase() + words[0].slice(1).toLowerCase() + ' Information';
                        }
                    }
                }
                
                // Initialize group if it doesn't exist
                if (!groups[groupName]) {
                    groups[groupName] = [];
                }
                
                groups[groupName].push(elementConfig);
            });
            
            return groups;
        }
        
        // Helper function to create question input HTML (like committed version)
        function createQuestionInput(dataElement, inputId, label) {
            console.log('Creating input for:', dataElement.name, 'Type:', dataElement.valueType, 'Option Set:', !!dataElement.optionSet);
            
            if (dataElement.optionSet && dataElement.optionSet.options) {
                console.log('✓ Creating SELECT for:', dataElement.name, 'with', dataElement.optionSet.options.length, 'options');
                let options = '<option value="">Search or select an option...</option>';
                dataElement.optionSet.options.forEach(option => {
                    options += `<option value="${option.code}">${option.displayName}</option>`;
                });
                return `<select id="${inputId}" name="${inputId}" class="form-control searchable-select" 
                        data-de-id="${dataElement.id}">
                    ${options}
                </select>`;
            }

            switch (dataElement.valueType) {
                case 'TEXT':
                    return `<input type="text" id="${inputId}" name="${inputId}" class="form-control" 
                            placeholder="Enter text..." data-de-id="${dataElement.id}">`;
                
                case 'LONG_TEXT':
                    return `<textarea id="${inputId}" name="${inputId}" class="form-control" rows="3" 
                            placeholder="Enter detailed text..." data-de-id="${dataElement.id}"></textarea>`;
                
                case 'NUMBER':
                case 'INTEGER':
                case 'INTEGER_POSITIVE':
                case 'INTEGER_NEGATIVE':
                case 'INTEGER_ZERO_OR_POSITIVE':
                    let numberAttrs = 'type="number" class="form-control"';
                    if (dataElement.valueType === 'INTEGER_POSITIVE') numberAttrs += ' min="1"';
                    else if (dataElement.valueType === 'INTEGER_ZERO_OR_POSITIVE') numberAttrs += ' min="0"';
                    else if (dataElement.valueType === 'INTEGER_NEGATIVE') numberAttrs += ' max="-1"';
                    return `<input ${numberAttrs} id="${inputId}" name="${inputId}" 
                            placeholder="Enter number..." data-de-id="${dataElement.id}">`;
                
                case 'PERCENTAGE':
                    return `<input type="number" id="${inputId}" name="${inputId}" class="form-control" 
                            min="0" max="100" step="0.01" placeholder="0-100%" data-de-id="${dataElement.id}">`;
                
                case 'DATE':
                    return `<input type="date" id="${inputId}" name="${inputId}" class="form-control" 
                            data-de-id="${dataElement.id}">`;
                
                case 'DATETIME':
                    return `<input type="datetime-local" id="${inputId}" name="${inputId}" class="form-control" 
                            data-de-id="${dataElement.id}">`;
                
                case 'TIME':
                    return `<input type="time" id="${inputId}" name="${inputId}" class="form-control" 
                            data-de-id="${dataElement.id}">`;
                
                case 'EMAIL':
                    return `<input type="email" id="${inputId}" name="${inputId}" class="form-control" 
                            placeholder="example@domain.com" data-de-id="${dataElement.id}">`;
                
                case 'PHONE_NUMBER':
                    return `<input type="tel" id="${inputId}" name="${inputId}" class="form-control" 
                            placeholder="+256 xxx xxx xxx" data-de-id="${dataElement.id}">`;
                
                case 'URL':
                    return `<input type="url" id="${inputId}" name="${inputId}" class="form-control" 
                            placeholder="https://example.com" data-de-id="${dataElement.id}">`;
                
                case 'BOOLEAN':
                case 'TRUE_ONLY':
                    return `<div class="form-check">
                        <input type="checkbox" id="${inputId}" name="${inputId}" class="form-check-input" 
                               data-de-id="${dataElement.id}">
                        <label class="form-check-label" for="${inputId}">Yes</label>
                    </div>`;
                
                case 'FILE_RESOURCE':
                    return `<input type="file" id="${inputId}" name="${inputId}" class="form-control" 
                            accept="*/*" data-de-id="${dataElement.id}">`;
                
                case 'COORDINATE':
                    return `<div class="coordinate-input">
                        <div class="row g-2">
                            <div class="col-6">
                                <input type="number" step="any" class="form-control" 
                                       placeholder="Latitude" name="${inputId}_lat" 
                                       data-de-id="${dataElement.id}">
                            </div>
                            <div class="col-6">
                                <input type="number" step="any" class="form-control" 
                                       placeholder="Longitude" name="${inputId}_lng" 
                                       data-de-id="${dataElement.id}">
                            </div>
                        </div>
                    </div>`;
                
                default:
                    console.log('✗ Defaulting to TEXT input for:', dataElement.name);
                    return `<input type="text" id="${inputId}" name="${inputId}" class="form-control" 
                            placeholder="Enter text..." data-de-id="${dataElement.id}">`;
            }
        }

        // Helper function to get question help text (contextual and specific)
        function getQuestionHelp(dataElement) {
            // First check if it has an option set (dropdown) - this takes priority
            if (dataElement.optionSet && dataElement.optionSet.options && dataElement.optionSet.options.length > 0) {
                return 'Select from the available options';
            }
            
            // Get the question text for contextual help
            const questionText = (dataElement.displayName || dataElement.name || '').toLowerCase();
            
            // Provide contextual help based on question content and value type
            switch (dataElement.valueType) {
                case 'TEXT':
                    // Analyze question text to provide better context
                    if (questionText.includes('year') || questionText.includes('age')) {
                        return 'Enter the number of years (e.g., 5, 10, 15)';
                    } else if (questionText.includes('name')) {
                        return 'Enter the full name';
                    } else if (questionText.includes('code') || questionText.includes('id')) {
                        return 'Enter the code or identifier';
                    } else if (questionText.includes('address')) {
                        return 'Enter the address details';
                    } else if (questionText.includes('description') || questionText.includes('comment')) {
                        return 'Enter a brief description';
                    } else {
                        return 'Enter text information';
                    }
                case 'LONG_TEXT':
                    return 'You can enter longer text with multiple lines';
                case 'NUMBER':
                case 'INTEGER':
                    if (questionText.includes('year') || questionText.includes('age')) {
                        return 'Enter the number of years';
                    } else if (questionText.includes('count') || questionText.includes('number')) {
                        return 'Enter a count or quantity';
                    } else if (questionText.includes('amount') || questionText.includes('cost') || questionText.includes('price')) {
                        return 'Enter the amount (numbers only)';
                    } else {
                        return 'Enter numeric values only';
                    }
                case 'INTEGER_POSITIVE':
                    if (questionText.includes('year') || questionText.includes('age')) {
                        return 'Enter number of years (must be greater than 0)';
                    } else {
                        return 'Enter a positive number (greater than 0)';
                    }
                case 'INTEGER_NEGATIVE':
                    return 'Enter a negative number (less than 0)';
                case 'INTEGER_ZERO_OR_POSITIVE':
                    if (questionText.includes('year') || questionText.includes('age')) {
                        return 'Enter number of years (0 or more)';
                    } else {
                        return 'Enter zero or a positive number';
                    }
                case 'PERCENTAGE':
                    return 'Enter a percentage value between 0 and 100';
                case 'DATE':
                    if (questionText.includes('birth')) {
                        return 'Select your date of birth';
                    } else if (questionText.includes('start')) {
                        return 'Select the start date';
                    } else if (questionText.includes('end')) {
                        return 'Select the end date';
                    } else {
                        return 'Select or enter a valid date';
                    }
                case 'DATETIME':
                    return 'Select or enter date and time';
                case 'TIME':
                    return 'Select or enter a time';
                case 'EMAIL':
                    return 'Enter a valid email address (e.g., user@example.com)';
                case 'PHONE_NUMBER':
                    return 'Enter a valid phone number (e.g., +256 xxx xxx xxx)';
                case 'URL':
                    return 'Enter a valid website URL (e.g., https://example.com)';
                case 'BOOLEAN':
                    return 'Choose Yes or No';
                case 'TRUE_ONLY':
                    return 'Check this box if applicable';
                case 'FILE_RESOURCE':
                    if (questionText.includes('photo') || questionText.includes('image')) {
                        return 'Select an image file to upload';
                    } else if (questionText.includes('document')) {
                        return 'Select a document file to upload';
                    } else {
                        return 'Select a file to upload';
                    }
                case 'COORDINATE':
                    return 'Enter latitude and longitude coordinates';
                default:
                    return 'Enter the required information';
            }
        }

        // Updated openStageModal function for new UI
        window.openStageModal = async function(stageId, occurrence = 1) {
            console.log('Opening stage modal for:', stageId, 'occurrence:', occurrence);
            
            if (!stageId) {
                // If no stage ID provided, use the current stage or first available
                stageId = currentStageId || (programData.program?.programStages?.[0]?.id);
            }
            
            if (!stageId) {
                alert('No stage available to edit');
                return;
            }
            
            const stage = programData.program.programStages.find(s => s.id === stageId);
            if (!stage) {
                console.error('Stage not found:', stageId);
                return;
            }
            
            // Set current stage and occurrence for saving
            currentStageId = stageId;
            currentStageOccurrence = occurrence;
            
            // Update modal title
            const modalTitle = document.getElementById('modalTitle');
            if (modalTitle) {
                modalTitle.textContent = stage.repeatable ? `${stage.name} - Occurrence ${occurrence}` : stage.name;
            }
            
            // Clear and populate modal content
            const modalContainer = document.getElementById('modalQuestionsContainer');
            if (!modalContainer) return;
            
            modalContainer.innerHTML = '';
            
            // Add event date field first
            const eventDateGroup = document.createElement('div');
            eventDateGroup.className = 'form-group mb-3';
            
            // Get saved event date for this occurrence
            const savedStageData = formData.stages?.[stageId];
            let savedEventDate = new Date().toISOString().split('T')[0];
            
            if (savedStageData) {
                if (stage.repeatable && occurrence > 1) {
                    // For repeatable stages, look in occurrence-specific data
                    const occurrenceData = savedStageData[`occurrence_${occurrence}`];
                    if (occurrenceData && occurrenceData['eventDate']) {
                        savedEventDate = occurrenceData['eventDate'];
                    }
                } else {
                    // For non-repeatable stages or first occurrence
                    if (savedStageData['eventDate']) {
                        savedEventDate = savedStageData['eventDate'];
                    } else if (savedStageData[`occurrence_${occurrence}`] && savedStageData[`occurrence_${occurrence}`]['eventDate']) {
                        savedEventDate = savedStageData[`occurrence_${occurrence}`]['eventDate'];
                    }
                }
            }
            
            eventDateGroup.innerHTML = `
                <label class="form-label" for="eventDate_${stageId}">
                    Event Date <span class="required-indicator">*</span>
                </label>
                <input type="date" class="form-control event-date" id="eventDate_${stageId}" 
                       value="${savedEventDate}" required>
                <div class="form-help">The date when this event occurred</div>
            `;
            modalContainer.appendChild(eventDateGroup);
            
            // Add separator
            const separator = document.createElement('hr');
            separator.className = 'my-4';
            modalContainer.appendChild(separator);
            
            // Load saved groupings or fall back to default grouping
            let questionGroups;
            try {
                questionGroups = await loadSavedGroupings(stage.id, stage.programStageDataElements || []);
            } catch (error) {
                console.error('Failed to load groupings, using default:', error);
                questionGroups = groupQuestionsByCategory(stage.programStageDataElements || []);
            }
            
            // Create questions with grouping
            for (const [groupName, elements] of Object.entries(questionGroups)) {
                if (elements.length === 0) continue;
                
                // Create group section
                const groupSection = document.createElement('div');
                groupSection.className = 'modal-group-section';
                
                // Group header
                const groupHeader = document.createElement('div');
                groupHeader.className = 'modal-group-header';
                groupHeader.innerHTML = `<h6 class="modal-group-title">${groupName}</h6>`;
                
                // Group content container
                const groupContent = document.createElement('div');
                groupContent.className = 'modal-group-content';
                
                // Questions grid for this group
                const questionsGrid = document.createElement('div');
                questionsGrid.className = 'form-grid';
                
                for (const elementConfig of elements) {
                const dataElement = elementConfig.dataElement;
                if (!dataElement) return;
                
                const formGroup = document.createElement('div');
                formGroup.className = 'form-group';
                
                // Clean the display name by removing prefixes like PM_, TP_, etc.
                let cleanName = dataElement.displayName || dataElement.name;
                cleanName = cleanName.replace(/^[A-Z]{2,3}_/i, '');
                
                // Create input using HTML string generation
                const inputId = `stage_${stageId}_${dataElement.id}`;
                const inputHTML = createQuestionInput(dataElement, inputId, cleanName);
                const helpText = getQuestionHelp(dataElement);
                
                // Add input type class for dynamic styling
                let inputTypeClass = '';
                if (dataElement.optionSet) {
                    inputTypeClass = 'input-type-dropdown';
                } else {
                    switch (dataElement.valueType) {
                        case 'NUMBER':
                        case 'INTEGER':
                        case 'INTEGER_POSITIVE':
                        case 'INTEGER_NEGATIVE':
                        case 'INTEGER_ZERO_OR_POSITIVE':
                        case 'PERCENTAGE':
                            inputTypeClass = 'input-type-number';
                            break;
                        case 'DATE':
                        case 'DATETIME':
                        case 'TIME':
                            inputTypeClass = 'input-type-date';
                            break;
                        case 'LONG_TEXT':
                            inputTypeClass = 'input-type-textarea';
                            break;
                        case 'BOOLEAN':
                        case 'TRUE_ONLY':
                            inputTypeClass = 'input-type-checkbox';
                            break;
                        default:
                            inputTypeClass = 'input-type-text';
                    }
                }
                
                // Add the input type class to form group
                formGroup.classList.add(inputTypeClass);
                
                // Universal side-by-side layout for all question types
                formGroup.innerHTML = `
                    <label class="form-label" for="${inputId}">${cleanName}</label>
                    <div class="answer-area">
                        ${inputHTML}
                        <div class="input-hint">${helpText}</div>
                    </div>
                `;
                
                // After creating the form group with innerHTML, get references to the actual input elements for value setting
                const actualInputElement = formGroup.querySelector('input, select, textarea');
                
                // Load saved value if exists
                const savedStageData = formData.stages?.[stageId];
                let savedValue = undefined;
                
                if (savedStageData) {
                    if (stage.repeatable && occurrence > 1) {
                        // For repeatable stages, look in occurrence-specific data
                        const occurrenceData = savedStageData[`occurrence_${occurrence}`];
                        if (occurrenceData && occurrenceData[dataElement.id] !== undefined) {
                            savedValue = occurrenceData[dataElement.id];
                        }
                    } else {
                        // For non-repeatable stages or first occurrence, look in stage data directly
                        if (savedStageData[dataElement.id] !== undefined) {
                            savedValue = savedStageData[dataElement.id];
                        } else if (savedStageData[`occurrence_${occurrence}`] && savedStageData[`occurrence_${occurrence}`][dataElement.id] !== undefined) {
                            savedValue = savedStageData[`occurrence_${occurrence}`][dataElement.id];
                        }
                    }
                }
                
                // Set saved values on the actual elements
                if (savedValue !== undefined && actualInputElement) {
                    if (actualInputElement.type === 'checkbox') {
                        actualInputElement.checked = savedValue === 'true' || savedValue === true;
                    } else if (actualInputElement.type === 'file') {
                        // File inputs cannot have their value set programmatically for security reasons
                        // But we can show what file was previously selected
                        console.log('File input with saved value:', savedValue);
                        if (savedValue && typeof savedValue === 'object' && savedValue.fileName) {
                            const existingFileInfo = document.createElement('div');
                            existingFileInfo.className = 'file-preview mt-2 existing-file';
                            existingFileInfo.innerHTML = `
                                <div class="alert alert-info d-flex align-items-center py-2">
                                    <i class="fas fa-file me-2"></i>
                                    <div>
                                        <strong>Previously selected:</strong> ${savedValue.fileName}<br>
                                        <small class="text-muted">Select a new file to replace this, or leave empty to keep the current file</small>
                                    </div>
                                </div>
                            `;
                            formGroup.appendChild(existingFileInfo);
                        }
                    } else {
                        actualInputElement.value = savedValue;
                    }
                }
                
                // For coordinate inputs, handle lat/lng separately
                if (dataElement.valueType === 'COORDINATE' && savedValue) {
                    const latInput = formGroup.querySelector('[name$="_lat"]');
                    const lngInput = formGroup.querySelector('[name$="_lng"]');
                    if (typeof savedValue === 'object' && savedValue.latitude !== undefined && savedValue.longitude !== undefined) {
                        if (latInput) latInput.value = savedValue.latitude;
                        if (lngInput) lngInput.value = savedValue.longitude;
                    }
                }
                
                // Add file input preview functionality if it's a file input
                if (dataElement.valueType === 'FILE_RESOURCE' && actualInputElement) {
                    actualInputElement.addEventListener('change', function(e) {
                        const file = e.target.files[0];
                        if (file) {
                            const preview = document.createElement('div');
                            preview.className = 'file-preview mt-2 new-file';
                            preview.innerHTML = `
                                <div class="alert alert-success d-flex align-items-center py-2">
                                    <i class="fas fa-file me-2"></i>
                                    <div>
                                        <strong>New file selected:</strong> ${file.name}<br>
                                        <small class="text-muted">Size: ${(file.size / 1024).toFixed(1)} KB</small>
                                    </div>
                                </div>
                            `;
                            
                            // Remove any existing previews (both old file info and previous new file previews)
                            const existingPreviews = formGroup.querySelectorAll('.file-preview');
                            existingPreviews.forEach(preview => preview.remove());
                            
                            formGroup.appendChild(preview);
                        } else {
                            // If no file selected, remove new file preview but keep existing file info
                            const newFilePreviews = formGroup.querySelectorAll('.file-preview.new-file');
                            newFilePreviews.forEach(preview => preview.remove());
                        }
                    });
                }
                
                questionsGrid.appendChild(formGroup);
                }
                
                // Append questionsGrid to groupContent
                groupContent.appendChild(questionsGrid);
                
                // Assemble group section
                groupSection.appendChild(groupHeader);
                groupSection.appendChild(groupContent);
                
                // Append group section to modal
                modalContainer.appendChild(groupSection);
            }
            
            // Show the modal
            const modal = new bootstrap.Modal(document.getElementById('stageModal'));
            modal.show();
        }
        
        // Updated saveStageData function
        window.saveStageData = function() {
            console.log('Saving stage data...');
            
            const modalContainer = document.getElementById('modalQuestionsContainer');
            if (!modalContainer) return;
            
            const formElements = modalContainer.querySelectorAll('input, select, textarea');
            const currentStage = currentStageId || (programData.program?.programStages?.[0]?.id);
            const currentOccurrence = currentStageOccurrence || 1;
            
            if (!currentStage) return;
            
            // Initialize stage data if not exists
            if (!formData.stages) {
                formData.stages = {};
            }
            if (!formData.stages[currentStage]) {
                formData.stages[currentStage] = {};
            }
            
            // For repeatable stages, store data in occurrence-specific object
            const stage = programData.program?.programStages?.find(s => s.id === currentStage);
            const isRepeatable = stage?.repeatable;
            
            let stageDataTarget;
            if (isRepeatable) {
                if (!formData.stages[currentStage][`occurrence_${currentOccurrence}`]) {
                    formData.stages[currentStage][`occurrence_${currentOccurrence}`] = {};
                }
                stageDataTarget = formData.stages[currentStage][`occurrence_${currentOccurrence}`];
            } else {
                stageDataTarget = formData.stages[currentStage];
            }
            
            // Collect form data
            formElements.forEach(element => {
                const stageId = element.getAttribute('data-stage-id');
                const elementId = element.getAttribute('data-de-id');
                
                if (elementId) {
                    if (element.type === 'checkbox') {
                        stageDataTarget[elementId] = element.checked;
                    } else if (element.type === 'file') {
                        if (element.files[0]) {
                            // New file selected
                            stageDataTarget[elementId] = {
                                isFile: true,
                                fileName: element.files[0].name,
                                fileObject: element.files[0]
                            };
                        } else {
                            // No new file selected - preserve existing file data if any
                            const existingValue = stageDataTarget[elementId];
                            if (existingValue && typeof existingValue === 'object' && existingValue.fileName) {
                                // Keep the existing file data
                                console.log('Preserving existing file:', existingValue.fileName);
                            }
                            // If no existing file data, elementId gets no value (undefined)
                        }
                    } else {
                        stageDataTarget[elementId] = element.value;
                    }
                } else if (element.classList.contains('event-date')) {
                    stageDataTarget['eventDate'] = element.value;
                }
            });
            
            console.log('Stage data saved:', formData.stages[currentStage]);
            
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('stageModal'));
            if (modal) {
                modal.hide();
            }
            
            // Refresh the data entry view and stage cards if currently active
            if (currentStep === 'data-entry') {
                populateStageSubTabs();
                populateStagesCards(); // Refresh stage cards to show updated status
                
                // Also refresh the specific stage's occurrence list to ensure proper status updates
                if (isRepeatable) {
                    const occurrenceCount = getStageOccurrences(currentStage);
                    populateStageOccurrences(currentStage, occurrenceCount);
                }
            }
            
            // Update step status indicators
            updateAllStepStatuses();
            
            // Show success message
            showSuccessMessage(`Stage data saved successfully for ${isRepeatable ? `occurrence ${currentOccurrence}` : 'stage'}`);
        }
        
        // Initialize the form
        document.addEventListener('DOMContentLoaded', async function() {
            try {
                const programDataElement = document.getElementById('programData');
                if (!programDataElement) {
                    console.error('Program data element not found');
                    return;
                }
                
                programData = JSON.parse(programDataElement.textContent);
                console.log('Program data loaded:', programData);
                
                // Debug option sets structure
                if (programData.program) {
                    console.log('=== PROGRAM DATA DEBUGGING ===');
                    if (programData.program.programTrackedEntityAttributes) {
                        console.log('TEI Attributes:', programData.program.programTrackedEntityAttributes.length);
                        programData.program.programTrackedEntityAttributes.forEach((attr, i) => {
                            if (attr.trackedEntityAttribute.optionSet) {
                                console.log(`TEI Attr ${i}:`, attr.trackedEntityAttribute.name, 'has optionSet with', attr.trackedEntityAttribute.optionSet.options?.length, 'options');
                            }
                        });
                    }
                    
                    if (programData.program.programStages) {
                        console.log('Program Stages:', programData.program.programStages.length);
                        programData.program.programStages.forEach((stage, s) => {
                            console.log(`Stage ${s}:`, stage.name);
                            if (stage.programStageDataElements) {
                                stage.programStageDataElements.forEach((de, d) => {
                                    if (de.dataElement.optionSet) {
                                        console.log(`  DE ${d}:`, de.dataElement.name, 'has optionSet with', de.dataElement.optionSet.options?.length, 'options');
                                    }
                                });
                            }
                        });
                    }
                }
                
                // Enhance window functions with full implementations now that DOM is ready
                enhanceWindowFunctions();
                
                // Initialize with location step
                await initializeLocationSelection();
                
                // Initialize step completion statuses
                updateAllStepStatuses();
                
                console.log('Tracker form initialized successfully with new UI');
                
            } catch (error) {
                console.error('Error initializing tracker form:', error);
            }
        });
        
        // Function to enhance window functions with full implementations
        function enhanceWindowFunctions() {
            // Enhanced functions will be defined here if needed
            
            // Enhanced stage occurrence functions
            window.addStageOccurrence = function(stageId) {
                console.log(`=== addStageOccurrence called for stageId: ${stageId} ===`);
                const currentCount = getStageOccurrences(stageId);
                console.log(`Current count: ${currentCount}`);
                const newCount = currentCount + 1;
                console.log(`New count will be: ${newCount}`);
                
                // Initialize stage data if not exists
                if (!formData.stages) formData.stages = {};
                if (!formData.stages[stageId]) formData.stages[stageId] = {};
                
                // Update tracking variable
                stageOccurrences[stageId] = newCount;
                console.log(`Updated stageOccurrences[${stageId}] to: ${newCount}`);
                
                // Create placeholder for new occurrence
                formData.stages[stageId][`occurrence_${newCount}`] = {};
                console.log(`Created occurrence_${newCount} for stage ${stageId}`);
                console.log('Current formData.stages:', formData.stages[stageId]);
                
                // Refresh the display
                console.log('Calling populateStagesCards...');
                populateStagesCards();
                
                // Update the specific stage's occurrences display
                console.log(`Calling populateStageOccurrences(${stageId}, ${newCount})...`);
                populateStageOccurrences(stageId, newCount);
                
                // Show success message
                showSuccessMessage(`Added occurrence ${newCount} for this stage`);
                
                // Automatically open the new occurrence modal
                setTimeout(() => {
                    openStageModal(stageId, newCount);
                }, 500);
                
                console.log(`Added occurrence ${newCount} for stage ${stageId}`);
            };
            
            window.removeStageOccurrence = function(stageId, occurrence) {
                if (confirm('Are you sure you want to remove this occurrence?')) {
                    console.log(`Removing occurrence ${occurrence} for stage ${stageId}`);
                    
                    if (formData.stages && formData.stages[stageId]) {
                        // Get all current occurrences
                        const allOccurrences = {};
                        const occurrenceKeys = Object.keys(formData.stages[stageId])
                            .filter(key => key.startsWith('occurrence_'))
                            .sort((a, b) => {
                                const numA = parseInt(a.split('_')[1]);
                                const numB = parseInt(b.split('_')[1]);
                                return numA - numB;
                            });
                        
                        console.log('Current occurrence keys:', occurrenceKeys);
                        
                        // Collect all occurrence data except the one being deleted
                        occurrenceKeys.forEach(key => {
                            const occurrenceNum = parseInt(key.split('_')[1]);
                            if (occurrenceNum !== occurrence) {
                                allOccurrences[key] = formData.stages[stageId][key];
                            }
                        });
                        
                        // Clear all existing occurrences
                        occurrenceKeys.forEach(key => {
                            delete formData.stages[stageId][key];
                        });
                        
                        // Re-add occurrences with consecutive numbering
                        let newOccurrenceNum = 1;
                        Object.keys(allOccurrences).forEach(oldKey => {
                            formData.stages[stageId][`occurrence_${newOccurrenceNum}`] = allOccurrences[oldKey];
                            newOccurrenceNum++;
                        });
                        
                        // Update tracking variable
                        stageOccurrences[stageId] = newOccurrenceNum - 1;
                        
                        console.log(`Reordered occurrences. New count: ${newOccurrenceNum - 1}`);
                    }
                    
                    // Refresh the display
                    populateStagesCards();
                    
                    // Show success message
                    showSuccessMessage('Stage occurrence removed successfully');
                    
                    console.log(`Successfully removed occurrence ${occurrence} for stage ${stageId}`);
                }
            };
        }
        
        // DHIS2 Submission Functions
        async function submitAllData() {
            // Check if we're in offline mode
            <?php if (isset($offlineMode) && $offlineMode): ?>
                alert('Data submission is disabled in offline mode. Please check your internet connection and refresh the page.');
                return;
            <?php endif; ?>
            
            const loadingSpinner = document.getElementById('loadingSpinner');
            const submitBtn = document.querySelector('[onclick="submitAllData()"]') || document.getElementById('finalSubmitBtn');
            
            // Validate location selection
            const facilityId = document.getElementById('facilityId').value;
            const facilityOrgunitUid = document.getElementById('facilityOrgunitUid').value;
            
            if (!facilityId) {
                alert('Please select a location before submitting.');
                return;
            }
            
            // Show loading state
            if (loadingSpinner) loadingSpinner.style.display = 'block';
            if (submitBtn) submitBtn.style.display = 'none';
            
            try {
                // Collect all form data for DHIS2 submission
                const submissionData = {
                    survey_id: programData.surveySettings.id,
                    form_data: {
                        trackedEntityAttributes: {},
                        events: []
                    },
                    location_data: {
                        facility_id: facilityId,
                        facility_name: document.getElementById('facilityName').textContent,
                        orgunit_uid: facilityOrgunitUid
                    }
                };
                
                // Save any currently open modal data before collecting submission data
                saveStageData();
                
                // Collect TEI attributes from the direct formData.trackedEntityAttributes object
                Object.keys(formData.trackedEntityAttributes || {}).forEach(attributeId => {
                    const value = formData.trackedEntityAttributes[attributeId];
                    if (value !== undefined && value !== null && value !== '') {
                        // Handle file uploads for TEI attributes
                        if (typeof value === 'object' && value.isFile) {
                            // For TEI file attributes, use a different format: tei_attributeId
                            submissionData.form_data.trackedEntityAttributes[attributeId] = `FILE_PLACEHOLDER:tei_${attributeId}`;
                        } else {
                            submissionData.form_data.trackedEntityAttributes[attributeId] = value;
                        }
                    }
                });
                
                // Collect events data
                Object.keys(formData.stages || {}).forEach(stageId => {
                    Object.keys(formData.stages[stageId] || {}).forEach(occurrenceKey => {
                        const occurrenceData = formData.stages[stageId][occurrenceKey];
                        
                        // Skip empty occurrences
                        if (!occurrenceData || Object.keys(occurrenceData).length === 0) return;
                        
                        const event = {
                            programStage: stageId,
                            eventDate: new Date().toISOString().split('T')[0], // Default to today
                            dataValues: {}
                        };
                        
                        // Process all data in this occurrence
                        Object.keys(occurrenceData).forEach(inputId => {
                            const value = occurrenceData[inputId];
                            if (value && value !== '' && inputId !== 'eventDate') {
                                // Find the data element to check its type
                                const stage = programData.program.programStages.find(s => s.id === stageId);
                                const dataElement = stage?.programStageDataElements?.find(psde => 
                                    psde.dataElement.id === inputId
                                )?.dataElement;
                                
                                let finalValue = value;
                                
                                // Handle different data types
                                if (dataElement?.valueType === 'BOOLEAN') {
                                    // Convert checkbox values to proper boolean strings
                                    finalValue = (value === true || value === 'true' || value === '1') ? 'true' : 'false';
                                } else if (typeof value === 'object' && value.isFile) {
                                    // Handle file uploads - send placeholder, backend will replace with resource ID
                                    // Use same format as file key: modal_inputId_occurrenceNumber
                                    const occurrenceNumber = occurrenceKey.replace('occurrence_', '');
                                    finalValue = `FILE_PLACEHOLDER:modal_${inputId}_${occurrenceNumber}`;
                                }
                                
                                event.dataValues[inputId] = finalValue;
                            }
                        });
                        
                        // Only add event if it has data values
                        if (Object.keys(event.dataValues).length > 0) {
                            submissionData.form_data.events.push(event);
                        }
                    });
                });
                
                console.log('Submitting data to DHIS2:', submissionData);
                
                // Create FormData to handle both regular data and files
                const submissionFormData = new FormData();
                submissionFormData.append('survey_id', submissionData.survey_id);
                submissionFormData.append('form_data', JSON.stringify(submissionData.form_data));
                submissionFormData.append('location_data', JSON.stringify(submissionData.location_data));
                
                // Collect files from all stages and occurrences
                const finalFiles = new Map();
                
                // Collect TEI file uploads
                Object.keys(formData.trackedEntityAttributes || {}).forEach(attributeId => {
                    const value = formData.trackedEntityAttributes[attributeId];
                    if (typeof value === 'object' && value.isFile && value.fileObject) {
                        const fileKey = `tei_${attributeId}`;
                        finalFiles.set(fileKey, value.fileObject);
                    }
                });
                
                // Collect stage event file uploads
                Object.keys(formData.stages || {}).forEach(stageId => {
                    Object.keys(formData.stages[stageId] || {}).forEach(occurrenceKey => {
                        const occurrenceData = formData.stages[stageId][occurrenceKey];
                        Object.keys(occurrenceData || {}).forEach(inputId => {
                            const value = occurrenceData[inputId];
                            if (typeof value === 'object' && value.isFile && value.fileObject) {
                                // Create unique key that includes occurrence info to avoid conflicts
                                const fileKey = `modal_${inputId}_${occurrenceKey.replace('occurrence_', '')}`;
                                finalFiles.set(fileKey, value.fileObject);
                            }
                        });
                    });
                });
                
                // Add files to FormData
                finalFiles.forEach((file, fileKey) => {
                    submissionFormData.append(`files[${fileKey}]`, file);
                });
                
                // Submit to backend
                const response = await fetch('tracker_program_submit.php', {
                    method: 'POST',
                    body: submissionFormData
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
                if (loadingSpinner) loadingSpinner.style.display = 'none';
                if (submitBtn) submitBtn.style.display = 'inline-block';
                showErrorMessage(error.message);
            }
        }

        function showSuccessMessage(message) {
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

        function showErrorMessage(message) {
            const alert = document.createElement('div');
            alert.className = 'alert alert-danger position-fixed';
            alert.style.cssText = 'top: 20px; right: 20px; z-index: 3000; max-width: 300px;';
            alert.innerHTML = `
                <i class="fas fa-exclamation-circle me-2"></i>
                ${message}
            `;
            
            document.body.appendChild(alert);
            
            setTimeout(() => {
                alert.remove();
            }, 5000);
        }
        
    </script>
</body>
</html>
