<?php
session_start();

// Include the database connection file
require_once '../admin/connect.php'; // Make sure the path is correct relative to this file
require_once '../admin/includes/skip_logic_helper.php';

// Check if $pdo object is available from connect.php
if (!isset($pdo)) {
    // Log error for debugging, but stop execution as DB connection is critical here
    error_log("Database connection failed in survey_page.php. Please check connect.php.");
    die("Database connection failed. Please try again later.");
}

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

// Get survey_id from the URL
$surveyId = $_GET['survey_id'] ?? null;

if (!$surveyId) {
    die("Survey ID is missing.");
}

// Check if user has already submitted this survey (prevent reaccess after submission)
if (isset($_SESSION['submitted_uid']) && isset($_SESSION['submitted_survey_id']) && $_SESSION['submitted_survey_id'] == $surveyId) {
    // User already submitted this survey, redirect to simple thank you page
    $uid = $_SESSION['submitted_uid'];
    header("Location: /thank-you/$uid");
    exit();
}

// Fetch survey details (id, type, name, is_active)
$survey = null; // Initialize to null
try {
    $surveyStmt = $pdo->prepare("SELECT id, type, name, is_active FROM survey WHERE id = ?");
    $surveyStmt->execute([$surveyId]);
    $survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching survey details in survey_page.php: " . $e->getMessage());
    die("Error fetching survey details.");
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

// Determine survey type
$surveyType = $survey['type'] ?? 'local';

// Set default survey title
$defaultSurveyTitle = htmlspecialchars($survey['name'] ?? 'Ministry of Health Client Satisfaction Feedback Tool');

// Fetch translations for the selected language
$language = isset($_GET['language']) ? $_GET['language'] : 'en'; // Default to English
$translations = [];
try {
    $query = "SELECT key_name, translations FROM default_text";
    $translations_stmt = $pdo->query($query);
    while ($row = $translations_stmt->fetch(PDO::FETCH_ASSOC)) {
        $decoded_translations = json_decode($row['translations'], true);
        $translations[$row['key_name']] = $decoded_translations[$language] ?? $row['key_name'];
    }
} catch (PDOException $e) {
    error_log("Database error fetching translations in survey_page.php: " . $e->getMessage());
    // Continue with empty translations if fetch fails
}

// Fetch survey settings from the database
$surveySettings = [];
try {
    $settingsStmt = $pdo->prepare("SELECT * FROM survey_settings WHERE survey_id = ?");
    $settingsStmt->execute([$surveyId]);
    $existingSettings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingSettings) {
        $surveySettings = $existingSettings;
        // Keep raw values - let JavaScript handle conversion for better debugging
        // This ensures we can see exactly what's in the database
        error_log("Raw database settings loaded: " . json_encode($existingSettings));
    } else {
        // Fallback to conservative defaults - most elements hidden by default
        $surveySettings = [
            'logo_path' => 'asets/asets/img/loog.jpg',
            'show_logo' => true,
            'flag_black_color' => '#000000',
            'flag_yellow_color' => '#FCD116',
            'flag_red_color' => '#D21034',
            'show_flag_bar' => true,
            'title_text' => $defaultSurveyTitle,
            'show_title' => true,
            'subheading_text' => $translations['subheading'] ?? 'This tool is used to obtain clients\' feedback about their experience with the services and promote quality improvement, accountability, and transparency within the healthcare system.',
            'show_subheading' => true,
            'show_submit_button' => true,
            'rating_instruction1_text' => $translations['rating_instruction'] ?? '1. Please rate each of the following parameters according to your experience today on a scale of 1 to 4.',
            'rating_instruction2_text' => $translations['rating_scale'] ?? 'where \'0\' means Poor, \'1\' Fair, \'2\' Good and \'3\' Excellent',
            'show_rating_instructions' => ($surveyType === 'local'), // Only show for local surveys
            'show_facility_section' => true,
            'republic_title_text' => 'THE REPUBLIC OF UGANDA',
            'show_republic_title_share' => true,
            'ministry_subtitle_text' => 'MINISTRY OF HEALTH',
            'show_ministry_subtitle_share' => true,
            'qr_instructions_text' => 'Scan this QR Code to Give Your Feedback on Services Received',
            'show_qr_instructions_share' => false, // Hidden by default
            'footer_note_text' => 'Thank you for helping us improve our services.',
            'show_footer_note_share' => false, // Hidden by default
            'selected_instance_key' => null,
            'selected_hierarchy_level' => null,
        ];
    }
} catch (PDOException $e) {
    error_log("Database error fetching survey settings in survey_page.php: " . $e->getMessage());
    // Fallback to conservative defaults on error
    $surveySettings = [
        'logo_path' => 'asets/asets/img/loog.jpg',
        'show_logo' => true,
        'flag_black_color' => '#000000',
        'flag_yellow_color' => '#FCD116',
        'flag_red_color' => '#D21034',
        'show_flag_bar' => true,
        'title_text' => $defaultSurveyTitle,
        'show_title' => true,
        'subheading_text' => $translations['subheading'] ?? 'This tool is used to obtain clients\' feedback about their experience with the services and promote quality improvement, accountability, and transparency within the healthcare system.',
        'show_subheading' => true,
        'show_submit_button' => true,
        'rating_instruction1_text' => $translations['rating_instruction'] ?? '1. Please rate each of the following parameters according to your experience today on a scale of 1 to 4.',
        'rating_instruction2_text' => $translations['rating_scale'] ?? 'where \'0\' means Poor, \'1\' Fair, \'2\' Good and \'3\' Excellent',
        'show_rating_instructions' => ($surveyType === 'local'),
        'show_facility_section' => true,
        'republic_title_text' => 'THE REPUBLIC OF UGANDA',
        'show_republic_title_share' => true,
        'ministry_subtitle_text' => 'MINISTRY OF HEALTH',
        'show_ministry_subtitle_share' => true,
        'qr_instructions_text' => 'Scan this QR Code to Give Your Feedback on Services Received',
        'show_qr_instructions_share' => false,
        'footer_note_text' => 'Thank you for helping us improve our services.',
        'show_footer_note_share' => false,
        'selected_instance_key' => null,
        'selected_hierarchy_level' => null,
    ];
}


// Extract selected instance key and hierarchy level from survey settings
$selectedInstanceKey = $surveySettings['selected_instance_key'] ?? null;
$selectedHierarchyLevel = $surveySettings['selected_hierarchy_level'] ?? null;

// Process dynamic images from survey settings
$dynamicImages = [];
$imageLayout = 'horizontal'; // default
if (!empty($surveySettings['dynamic_images_data'])) {
    try {
        $imagesData = json_decode($surveySettings['dynamic_images_data'], true);
        if (is_array($imagesData)) {
            $dynamicImages = $imagesData;
        }
        error_log("Loaded dynamic images: " . count($dynamicImages) . " images");
    } catch (Exception $e) {
        error_log("Error parsing dynamic images JSON: " . $e->getMessage());
    }
}
if (!empty($surveySettings['image_layout_type'])) {
    $imageLayout = $surveySettings['image_layout_type'];
}

// Debug: Log the survey settings being applied
error_log("Survey ID: $surveyId, Survey Type: $surveyType");
error_log("Survey Settings: " . json_encode($surveySettings));

// Debug visibility values specifically
$debugInfo = [
    'show_logo' => $surveySettings['show_logo'] ?? 'not set',
    'show_flag_bar' => $surveySettings['show_flag_bar'] ?? 'not set',
    'show_title' => $surveySettings['show_title'] ?? 'not set',
    'show_subheading' => $surveySettings['show_subheading'] ?? 'not set',
    'show_facility_section' => $surveySettings['show_facility_section'] ?? 'not set',
    'show_rating_instructions' => $surveySettings['show_rating_instructions'] ?? 'not set',
];
error_log("Visibility Debug: " . json_encode($debugInfo));

// Hierarchy Level Mapping (Fixed to Level X) - needed for display logic
$hierarchyLevels = [];
for ($i = 1; $i <= 8; $i++) {
    $hierarchyLevels[$i] = 'Level ' . $i;
}

// Fetch questions and options
$questionsArray = [];
try {
    $questionsStmt = $pdo->prepare("
        SELECT q.id, q.label, q.question_type, q.is_required, q.translations, q.option_set_id, q.validation_rules, q.skip_logic, q.min_selections, q.max_selections, sq.position
        FROM question q
        JOIN survey_question sq ON q.id = sq.question_id
        WHERE sq.survey_id = ?
        ORDER BY sq.position ASC
    ");
    $questionsStmt->execute([$surveyId]);

    while ($question = $questionsStmt->fetch(PDO::FETCH_ASSOC)) {
        $question['options'] = [];
        if ($question['option_set_id']) {
            $optionsStmt = $pdo->prepare("
                SELECT * FROM option_set_values
                WHERE option_set_id = ?
                ORDER BY id ASC
            ");
            $optionsStmt->execute([$question['option_set_id']]);

            while ($option = $optionsStmt->fetch(PDO::FETCH_ASSOC)) {
                $question['options'][] = $option;
            }
        }
        $questionsArray[] = $question;
    }
} catch (PDOException $e) {
    error_log("Database error fetching questions and options in survey_page.php: " . $e->getMessage());
    // $questionsArray will remain empty if fetch fails
}

// Apply translations to questions and options
foreach ($questionsArray as &$question) {
    $questionTranslations = $question['translations'] ? json_decode($question['translations'], true) : [];
    $question['label'] = $questionTranslations[$language] ?? $question['label'];
    foreach ($question['options'] as &$option) {
        $optionTranslations = $option['translations'] ? json_decode($option['translations'], true) : [];
        $option['option_value'] = $optionTranslations[$language] ?? $option['option_value'];
    }
}
unset($question);
unset($option);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($surveySettings['title_text'] ?? $defaultSurveyTitle); ?></title>
    <link rel="stylesheet" href="../styles.css">
    <style>
        /* Rich text content styling */
        .rich-text-content {
            font-family: inherit;
            line-height: 1.5;
        }
        
        .rich-text-content p {
            margin: 8px 0;
        }
        
        .rich-text-content strong {
            font-weight: bold;
        }
        
        .rich-text-content em {
            font-style: italic;
        }
        
        .rich-text-content ul, .rich-text-content ol {
            margin: 10px 0;
            padding-left: 20px;
        }
        
        .rich-text-content li {
            margin: 4px 0;
        }
        
        /* Enhanced subheading styling */
        .subheading {
            word-wrap: break-word;
            overflow-wrap: break-word;
            hyphens: auto;
            max-width: 100%;
            line-height: 1.5;
            margin: 15px 0;
            padding: 10px 0;
            box-sizing: border-box;
        }
        
        /* Dynamic Images Styling */
        .dynamic-images-container {
            margin: 20px 0;
            text-align: center;
        }
        
        .dynamic-images-container.horizontal {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .dynamic-images-container.vertical {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
        }
        
        .dynamic-image-item {
            display: inline-block;
            margin: 5px;
            background: white;
            padding: 8px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .dynamic-image-item img {
            border-radius: 8px;
            object-fit: contain;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        @media (max-width: 768px) {
            .dynamic-images-container.horizontal {
                flex-direction: column;
            }
            
            .dynamic-image-item img {
                max-width: 100%;
                height: auto;
            }
        }
        
        /* Enhanced responsive body styles */
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f8f9fa;
            margin: 0;
            padding: 0;
            line-height: 1.6;
            font-size: 16px;
        }

        /* Enhanced container styles - made bigger like preview_form */
        .container {
            max-width: 1200px !important;
            margin: 20px auto !important;
            padding: 30px !important;
            background: #fff !important;
            border-radius: 12px !important;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.08) !important;
            border: 1px solid #e9ecef !important;
            width: 95% !important;
        }
        .question-number {
            font-weight: bold;
            margin-right: 8px;
        }
        .logo-container {
            text-align: center;
            margin-bottom: 20px;
        }
        .logo-container img {
            max-width: 100%;
            height: 170px;
            object-fit: contain;
        }

        .header-section {
    text-align: center; /* Center inline/inline-block children */
    display: flex; /* Use flexbox for robust centering of children */
    flex-direction: column; /* Stack children vertically */
    align-items: center; /* Center children horizontally in a column layout */
    margin-bottom: 20px; /* Adjust as needed */
}
.header-section .title,
.header-section .subtitle {
    text-align: center; /* Ensure text itself is centered within its div */
    margin-left: auto; /* For block elements to center horizontally */
    margin-right: auto;
}
.logo-container {
    /* Your existing logo-container styles */
    text-align: center; /* Ensures content inside is centered */
    margin-left: auto; /* Center the container itself */
    margin-right: auto;
    margin-bottom: 20px;
}
        /* Default flag bar colors (these will be overridden by PHP/JS from DB) */
        .flag-bar {
            display: flex;
            height: 10px;
            margin-top: 10px;
            margin-bottom: 20px;
        }
        .flag-black, .flag-yellow, .flag-red {
            flex: 1;
            /* Default colors, overridden by PHP below */
            background-color: #000;
        }
        .flag-yellow { background-color: #FCD116; }
        .flag-red { background-color: #D21034; }

        /* Enhanced utility classes for hiding elements */
        .hidden-element {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
            height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            overflow: hidden !important;
            position: absolute !important;
            left: -9999px !important;
        }
        
        /* Force visibility when elements should be shown */
        .show-element {
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
            position: static !important;
            left: auto !important;
        }
        
        /* For flex elements that need to be shown */
        .show-flex {
            display: flex !important;
            visibility: visible !important;
            opacity: 1 !important;
            position: static !important;
            left: auto !important;
        }

        /* Top controls for language and print (if you want to keep them) */
        .top-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            background: #fff;
            border-bottom: 1px solid #ddd;
            margin-bottom: 20px; /* Space before the form content */
        }
        .language-switcher select {
            padding: 5px;
            border-radius: 4px;
            border: 1px solid #ccc;
        }
        .print-button button {
            background: none;
            border: none;
            cursor: pointer;
        }
        .print-button img {
            width: 30px; /* Adjust as needed */
            height: 30px;
        }

           /* Improved Pagination Controls Container */
.pagination-controls {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 18px;
    margin: 28px auto 18px auto;
    max-width: 340px;      /* Reduce container width */
    width: 100%;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    padding: 12px 0;
}

/* Pagination Buttons */
.pagination-controls button {
    min-width: 100px;
    padding: 10px 24px;
    font-size: 1rem;
    border: none;
    border-radius: 6px;
    background-color: #1976d2;
    color: #fff;
    cursor: pointer;
    transition: background 0.2s, box-shadow 0.2s;
    box-shadow: 0 1px 3px rgba(25, 118, 210, 0.08);
    outline: none;
    margin: 0;
    display: inline-block;
    vertical-align: middle;
}

.pagination-controls button:disabled,
.pagination-controls button[style*="display: none"] {
    background-color: #bdbdbd;
    color: #fff;
    cursor: not-allowed;
}

.pagination-controls button:hover:not(:disabled):not([style*="display: none"]) {
    background-color: #1565c0;
}

/* Remove left margin from submit button for perfect alignment */
.pagination-controls #submit-button-final {
    margin-left: 0 !important;
}
.star-rating {
    display: flex;
    flex-direction: row;
    gap: 24px; /* space between stars */
    justify-content: center;
    align-items: flex-end;
    margin: 12px 0;
}

.star-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 50px;
}

.star-number {
    font-size: 1rem;
    color: #888;
    margin-bottom: 2px;
    font-weight: 500;
}

.star {
    color: #ccc;
    font-size: 2.2rem;
    transition: color 0.2s;
    cursor: pointer;
    outline: none;
    user-select: none;
}

.star.selected,
.star.hovered {
    color: #FFD600;
}

.star:focus {
    outline: 2px solid #1976d2;
}
        /* Enhanced searchable dropdown styling */
        .searchable-dropdown {
            position: relative;
            width: 100%;
        }
        
        #facility-search {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 16px;
            line-height: 1.4;
            background: #fff;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            box-sizing: border-box;
        }
        
        #facility-search:focus {
            border-color: #1976d2;
            box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
            outline: none;
        }
        
        .dropdown-results {
            max-height: 300px;
            overflow-y: auto;
            border: 2px solid #e9ecef;
            border-top: none;
            border-radius: 0 0 8px 8px;
            background-color: #fff;
            position: absolute;
            width: 100%;
            z-index: 1000;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            font-size: 16px;
            box-sizing: border-box;
        }
        
        .dropdown-item {
            padding: 12px 16px;
            cursor: pointer;
            transition: background-color 0.2s;
            border-bottom: 1px solid #f1f3f4;
            font-size: 16px;
            line-height: 1.4;
            color: #333;
        }
        
        .dropdown-item:hover {
            background-color: #e3f2fd;
            color: #1976d2;
        }
        
        .dropdown-item:last-child {
            border-bottom: none;
        }
        .hierarchy-path .path-display {
            font-size: 0.9em;
            color: #555;
            margin-top: 5px;
            word-break: break-all;
        }

        /* Enhanced responsive design for form elements */
        .enhanced-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
            margin: 12px 0;
            padding: 0;
        }

        .enhanced-option {
            display: flex;
            align-items: flex-start;
            padding: 12px 16px;
            background: #f8f9fa;
            border: 2px solid transparent;
            border-radius: 8px;
            transition: all 0.2s ease;
            cursor: pointer;
            min-height: 44px;
        }

        .enhanced-option:hover {
            background: #e3f2fd;
            border-color: #1976d2;
            box-shadow: 0 2px 8px rgba(25, 118, 210, 0.1);
        }

        .enhanced-option input[type="radio"],
        .enhanced-option input[type="checkbox"] {
            margin: 0 12px 0 0;
            transform: scale(1.2);
            accent-color: #1976d2;
            flex-shrink: 0;
        }

        .enhanced-option label {
            margin: 0;
            font-weight: 500;
            color: #333;
            cursor: pointer;
            line-height: 1.4;
            word-break: break-word;
        }

        .enhanced-option input:checked + label {
            color: #1976d2;
            font-weight: 600;
        }

        .enhanced-option input:focus {
            outline: 2px solid #1976d2;
            outline-offset: 2px;
        }

        /* Form group enhancements */
        .form-group {
            margin-bottom: 24px;
        }

        .form-group .radio-label {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 12px;
            line-height: 1.3;
        }

        .required-indicator {
            color: #dc3545 !important;
            font-weight: bold;
        }

        /* Location row responsive improvements */
        .location-row {
            display: grid !important;
            grid-template-columns: 1fr 1fr !important;
            gap: 20px !important;
            margin-bottom: 20px !important;
        }

        /* Form control enhancements - improved dropdown styling */
        .form-control, input[type="text"], input[type="number"], select, textarea {
            padding: 12px !important;
            border: 2px solid #e9ecef !important;
            border-radius: 8px !important;
            font-size: 16px !important;
            transition: border-color 0.2s ease, box-shadow 0.2s ease !important;
            background: #fff !important;
            min-height: 44px !important;
            line-height: 1.4 !important;
        }

        /* Enhanced dropdown styling for better text visibility */
        select.form-control {
            appearance: none;
            background-image: url('data:image/svg+xml;charset=US-ASCII,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 5"><path fill="%23666" d="M2 0L0 2h4zm0 5L0 3h4z"/></svg>');
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 12px;
            padding-right: 40px !important;
        }

        /* Dropdown results container improvements */
        .dropdown-results {
            max-height: 300px !important;
            overflow-y: auto !important;
            border: 2px solid #e9ecef !important;
            border-top: none !important;
            border-radius: 0 0 8px 8px !important;
            background-color: #fff !important;
            position: absolute !important;
            width: 100% !important;
            z-index: 1000 !important;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1) !important;
            font-size: 16px !important;
        }

        .dropdown-item {
            padding: 12px 16px !important;
            cursor: pointer !important;
            transition: background-color 0.2s !important;
            border-bottom: 1px solid #f1f3f4 !important;
            font-size: 16px !important;
            line-height: 1.4 !important;
            color: #333 !important;
        }

        .dropdown-item:hover {
            background-color: #e3f2fd !important;
            color: #1976d2 !important;
        }

        .dropdown-item:last-child {
            border-bottom: none !important;
        }

        .form-control:focus, input:focus, select:focus, textarea:focus {
            border-color: #1976d2 !important;
            box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1) !important;
            outline: none !important;
        }

        /* Mobile-first responsive design */
        @media (max-width: 768px) {
            .container {
                margin: 10px !important;
                padding: 20px !important;
                border-radius: 8px !important;
                width: calc(100% - 20px) !important;
            }

            .enhanced-options {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .enhanced-option {
                padding: 14px;
                min-height: 48px;
            }

            .location-row {
                grid-template-columns: 1fr !important;
                gap: 16px !important;
            }

            .form-group .radio-label {
                font-size: 1rem;
            }

            .logo-container img {
                height: 120px;
            }

            h2 {
                font-size: 24px;
            }
        }

        @media (max-width: 480px) {
            .container {
                margin: 5px !important;
                padding: 16px !important;
                border-radius: 8px !important;
                width: calc(100% - 10px) !important;
                box-sizing: border-box !important;
            }

            .enhanced-options {
                gap: 8px;
            }

            .enhanced-option {
                padding: 12px;
                font-size: 15px;
            }

            .form-control, input, select, textarea {
                font-size: 16px !important; /* Prevents zoom on iOS */
                padding: 14px !important;
            }

            .logo-container img {
                height: 100px;
            }

            h2 {
                font-size: 20px;
            }

            .subheading {
                font-size: 14px;
            }
        }

        /* Large screen optimizations */
        @media (min-width: 1200px) {
            .enhanced-options {
                grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            }
        }

        /* ===== ENHANCED FORM FIELD STYLES ===== */
        
        /* Base form container for all field types */
        .form-field-container {
            margin-bottom: 24px;
            padding: 16px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            transition: box-shadow 0.2s ease;
        }
        
        .form-field-container:focus-within {
            box-shadow: 0 2px 8px rgba(25, 118, 210, 0.15);
        }
        
        /* Field labels */
        .field-label {
            display: block;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            font-size: 14px;
            line-height: 1.4;
        }
        
        .field-label .required-indicator {
            color: #dc3545;
            margin-left: 4px;
        }
        
        /* Base input styling for all text-based inputs */
        .enhanced-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            font-size: 16px;
            line-height: 1.5;
            background: #fff;
            transition: all 0.2s ease;
            box-sizing: border-box;
            font-family: inherit;
        }
        
        .enhanced-input:focus {
            border-color: #1976d2;
            box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
            outline: none;
        }
        
        .enhanced-input:disabled {
            background-color: #f8f9fa;
            border-color: #dee2e6;
            color: #6c757d;
            cursor: not-allowed;
        }
        
        /* Specific input type styling */
        input[type="text"].enhanced-input,
        input[type="email"].enhanced-input,
        input[type="tel"].enhanced-input,
        input[type="url"].enhanced-input,
        input[type="number"].enhanced-input,
        input[type="date"].enhanced-input,
        input[type="datetime-local"].enhanced-input,
        input[type="time"].enhanced-input,
        input[type="month"].enhanced-input,
        input[type="color"].enhanced-input,
        input[type="file"].enhanced-input {
            min-height: 48px;
        }
        
        /* Textarea styling */
        textarea.enhanced-input {
            min-height: 100px;
            resize: vertical;
            font-family: inherit;
        }
        
        /* Select dropdown styling */
        select.enhanced-input {
            min-height: 48px;
            appearance: none;
            background-image: url('data:image/svg+xml;charset=US-ASCII,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 5"><path fill="%23666" d="M2 0L0 2h4zm0 5L0 3h4z"/></svg>');
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 12px;
            padding-right: 40px;
            cursor: pointer;
        }
        
        /* Radio and checkbox group containers */
        .radio-checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 12px;
            margin-top: 8px;
        }
        
        .radio-checkbox-item {
            display: flex;
            align-items: flex-start;
            padding: 12px 16px;
            background: #f8f9fa;
            border: 2px solid transparent;
            border-radius: 6px;
            transition: all 0.2s ease;
            cursor: pointer;
            min-height: 48px;
        }
        
        .radio-checkbox-item:hover {
            background: #e3f2fd;
            border-color: #1976d2;
        }
        
        .radio-checkbox-item input[type="radio"],
        .radio-checkbox-item input[type="checkbox"] {
            margin: 0 12px 0 0;
            transform: scale(1.2);
            accent-color: #1976d2;
            flex-shrink: 0;
            margin-top: 2px;
        }
        
        .radio-checkbox-item label {
            margin: 0;
            font-weight: 500;
            color: #333;
            cursor: pointer;
            line-height: 1.4;
            word-break: break-word;
        }
        
        .radio-checkbox-item input:checked + label {
            color: #1976d2;
            font-weight: 600;
        }
        
        /* Rating scales */
        .rating-scale-container {
            padding: 16px;
            background: #f8f9fa;
            border-radius: 6px;
            margin-top: 8px;
        }
        
        .scale-labels {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 12px;
            color: #666;
            font-weight: 500;
        }
        
        .scale-options {
            display: flex;
            gap: 8px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .scale-option {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 40px;
            padding: 8px;
            cursor: pointer;
        }
        
        .scale-option input[type="radio"] {
            margin: 0 0 8px 0;
            transform: scale(1.3);
            accent-color: #1976d2;
        }
        
        .scale-option span {
            font-size: 14px;
            font-weight: 500;
            color: #333;
        }
        
        /* Star rating enhanced */
        .star-rating-enhanced {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 6px;
            margin-top: 8px;
        }
        
        .star-enhanced {
            font-size: 28px;
            color: #ddd;
            cursor: pointer;
            transition: all 0.2s ease;
            user-select: none;
            padding: 4px;
            border-radius: 4px;
        }
        
        .star-enhanced:hover,
        .star-enhanced.selected {
            color: #ffd700;
            transform: scale(1.1);
        }
        
        .star-enhanced:focus {
            outline: 2px solid #1976d2;
            outline-offset: 2px;
        }
        
        /* Input groups for currency, percentage */
        .input-group-enhanced {
            display: flex;
            align-items: stretch;
            width: 100%;
        }
        
        .input-group-enhanced .enhanced-input {
            border-radius: 6px 0 0 6px;
            border-right: none;
        }
        
        .input-group-text-enhanced {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            background: #e9ecef;
            border: 2px solid #e9ecef;
            border-left: none;
            border-radius: 0 6px 6px 0;
            font-weight: 600;
            color: #495057;
            white-space: nowrap;
        }
        
        .input-group-enhanced:focus-within .input-group-text-enhanced {
            border-color: #1976d2;
        }
        
        /* File upload styling */
        input[type="file"].enhanced-input {
            padding: 8px 12px;
            cursor: pointer;
        }
        
        input[type="file"].enhanced-input::-webkit-file-upload-button {
            background: #1976d2;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            margin-right: 12px;
            cursor: pointer;
            font-weight: 500;
        }
        
        input[type="file"].enhanced-input::-webkit-file-upload-button:hover {
            background: #1565c0;
        }
        
        /* Color input styling */
        input[type="color"].enhanced-input {
            width: 80px;
            height: 48px;
            padding: 4px;
            cursor: pointer;
        }
        
        /* Signature pad */
        .signature-container {
            border: 2px solid #e9ecef;
            border-radius: 6px;
            padding: 16px;
            background: #fff;
            margin-top: 8px;
        }
        
        .signature-canvas {
            border: 1px solid #dee2e6;
            border-radius: 4px;
            background: #fff;
            width: 100%;
            max-width: 500px;
            height: 200px;
        }
        
        .signature-controls {
            margin-top: 12px;
            display: flex;
            gap: 12px;
        }
        
        .signature-clear-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            transition: background 0.2s ease;
        }
        
        .signature-clear-btn:hover {
            background: #5a6268;
        }
        
        /* Coordinates input */
        .coordinates-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 8px;
        }
        
        .coordinates-btn {
            grid-column: 1 / -1;
            background: #1976d2;
            color: white;
            border: none;
            padding: 12px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            margin-top: 8px;
            transition: background 0.2s ease;
        }
        
        .coordinates-btn:hover {
            background: #1565c0;
        }
        
        /* Form group enhanced styling */
        .form-group.enhanced {
            background: #fff;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            transition: box-shadow 0.2s ease;
        }
        
        .form-group.enhanced:focus-within {
            box-shadow: 0 2px 8px rgba(25, 118, 210, 0.15);
        }
        
        /* Enhanced styling for existing form elements */
        .form-group .form-control {
            padding: 12px 16px !important;
            border: 2px solid #e9ecef !important;
            border-radius: 6px !important;
            transition: all 0.2s ease !important;
            min-height: 48px !important;
        }
        
        .form-group .form-control:focus {
            border-color: #1976d2 !important;
            box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1) !important;
        }
        
        /* Enhanced input group styling */
        .input-group {
            display: flex;
            align-items: stretch;
        }
        
        .input-group .form-control {
            border-radius: 6px 0 0 6px !important;
            border-right: none !important;
        }
        
        .input-group-text {
            background: #e9ecef !important;
            border: 2px solid #e9ecef !important;
            border-left: none !important;
            border-radius: 0 6px 6px 0 !important;
            font-weight: 600 !important;
            color: #495057 !important;
            padding: 12px 16px !important;
        }
        
        .input-group:focus-within .input-group-text {
            border-color: #1976d2 !important;
        }
        
        /* Enhanced likert scale styling */
        .likert-scale {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-top: 8px;
        }
        
        .likert-scale .scale-labels {
            display: flex;
            justify-content: space-between;
            margin-bottom: 16px;
            font-size: 13px;
            color: #666;
            font-weight: 500;
        }
        
        .likert-scale .scale-options {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .likert-scale .scale-option {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 44px;
            padding: 12px 8px;
            background: #fff;
            border: 2px solid transparent;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .likert-scale .scale-option:hover {
            background: #e3f2fd;
            border-color: #1976d2;
        }
        
        .likert-scale .scale-option input[type="radio"] {
            margin: 0 0 8px 0;
            transform: scale(1.3);
            accent-color: #1976d2;
        }
        
        .likert-scale .scale-option span {
            font-size: 14px;
            font-weight: 600;
            color: #333;
        }
        
        /* Enhanced NPS styling */
        .nps-scale {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-top: 8px;
        }
        
        .nps-scale .scale-labels {
            display: flex;
            justify-content: space-between;
            margin-bottom: 16px;
            font-size: 13px;
            color: #666;
            font-weight: 500;
        }
        
        .nps-scale .scale-options {
            display: flex;
            gap: 8px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .nps-scale .scale-option {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 36px;
            padding: 8px 6px;
            background: #fff;
            border: 2px solid transparent;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .nps-scale .scale-option:hover {
            background: #e3f2fd;
            border-color: #1976d2;
        }
        
        .nps-scale .scale-option input[type="radio"] {
            margin: 0 0 6px 0;
            transform: scale(1.2);
            accent-color: #1976d2;
        }
        
        .nps-scale .scale-option span {
            font-size: 12px;
            font-weight: 600;
            color: #333;
        }
        
        /* Enhanced star rating input */
        .star-rating-input {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-top: 8px;
            text-align: center;
        }
        
        .star-rating-input .stars {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-bottom: 8px;
        }
        
        .star-rating-input .star {
            font-size: 32px;
            color: #ddd;
            cursor: pointer;
            transition: all 0.2s ease;
            user-select: none;
            padding: 4px;
            border-radius: 4px;
        }
        
        .star-rating-input .star:hover,
        .star-rating-input .star.selected {
            color: #ffd700;
            transform: scale(1.1);
        }
        
        /* Responsive design for enhanced fields */
        @media (max-width: 768px) {
            .radio-checkbox-group {
                grid-template-columns: 1fr;
                gap: 8px;
            }
            
            .radio-checkbox-item {
                padding: 16px;
                min-height: 52px;
            }
            
            .likert-scale .scale-options,
            .nps-scale .scale-options {
                gap: 6px;
                justify-content: center;
            }
            
            .likert-scale .scale-option,
            .nps-scale .scale-option {
                min-width: 36px;
                padding: 8px 6px;
            }
            
            .star-rating-input .star {
                font-size: 28px;
                padding: 6px;
            }
            
            .coordinates-container {
                grid-template-columns: 1fr;
                gap: 8px;
            }
            
            .form-group.enhanced {
                padding: 16px;
                margin-bottom: 20px;
            }
        }
        
        @media (max-width: 480px) {
            .form-field-container,
            .form-group.enhanced {
                padding: 12px;
                margin-bottom: 16px;
            }
            
            .enhanced-input {
                font-size: 16px; /* Prevents zoom on iOS */
                padding: 14px 16px;
            }
            
            .radio-checkbox-item {
                padding: 14px;
                min-height: 56px;
            }
            
            .likert-scale .scale-options,
            .nps-scale .scale-options {
                gap: 4px;
            }
            
            .likert-scale .scale-option,
            .nps-scale .scale-option {
                min-width: 32px;
                padding: 6px 4px;
            }
            
            .star-rating-input .star {
                font-size: 24px;
                padding: 4px;
            }
        }
    </style>
</head>
<body>
  
    <div class="container" id="form-content">



        <div class="header-section" id="logo-section">
            <?php if (!empty($dynamicImages) && ($surveySettings['show_dynamic_images'] ?? true)): ?>
            <div class="dynamic-images-container <?= htmlspecialchars($imageLayout) ?>" style="text-align: center; margin: 20px 0;">
                <?php foreach ($dynamicImages as $image): ?>
                    <div class="dynamic-image-item" style="display: inline-block; margin: 10px;">
                        <img src="/fbs/admin/<?= htmlspecialchars($image['path']) ?>" 
                             alt="<?= htmlspecialchars($image['alt_text']) ?>"
                             style="width: <?= intval($image['width']) ?>px; height: <?= intval($image['height']) ?>px; object-fit: contain; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);"
                             onerror="this.style.display='none';"
                             title="<?= htmlspecialchars($image['alt_text']) ?>">
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="title" id="republic-title"><?php echo htmlspecialchars($surveySettings['republic_title_text'] ?? 'THE REPUBLIC OF UGANDA'); ?></div>
            <div class="subtitle" id="ministry-subtitle"><?php echo htmlspecialchars($surveySettings['ministry_subtitle_text'] ?? 'MINISTRY OF HEALTH'); ?></div>
        </div>

        <div class="flag-bar" id="flag-bar">
            <div class="flag-black" id="flag-black-color" style="background-color: <?php echo htmlspecialchars($surveySettings['flag_black_color'] ?? '#000000'); ?>;"></div>
            <div class="flag-yellow" id="flag-yellow-color" style="background-color: <?php echo htmlspecialchars($surveySettings['flag_yellow_color'] ?? '#FCD116'); ?>;"></div>
            <div class="flag-red" id="flag-red-color" style="background-color: <?php echo htmlspecialchars($surveySettings['flag_red_color'] ?? '#D21034'); ?>;"></div>
        </div>

        <h2 id="survey-title" data-translate="title"><?php echo htmlspecialchars($surveySettings['title_text'] ?? $defaultSurveyTitle); ?></h2>

        <div class="subheading rich-text-content" id="survey-subheading" data-translate="subheading">
            <?php echo $surveySettings['subheading_text'] ?? $translations['subheading'] ?? 'This tool is used to obtain clients\' feedback about their experience with the services and promote quality improvement, accountability, and transparency within the healthcare system.'; ?>
        </div>

        <form action="/fbs/admin/survey_page_submit.php" method="POST" onsubmit="return validateForm()">
            <input type="hidden" name="survey_id" value="<?php echo htmlspecialchars($surveyId); ?>">
            <input type="hidden" name="submission_language" value="<?php echo htmlspecialchars($language); ?>">

            <div class="facility-section" id="facility-section">
                <div class="form-group">
                    <label for="facility-search">Schools:</label>
                    <div class="searchable-dropdown">
                        <input type="text" id="facility-search" placeholder="Type to search for school..." autocomplete="off">
                        <div class="dropdown-results" id="facility-results"></div>
                        <input type="hidden" id="facility_id" name="facility_id">
                    </div>
                </div>

                <div class="hierarchy-path" id="hierarchy-path">
                    <div class="path-display" id="path-display"></div>
                </div>

                <input type="hidden" id="hierarchy_data" name="hierarchy_data">
            </div>

            <select id="facility" name="facility" style="display: none;">
                <option value="">None Selected</option>
            </select>

            <?php if ($surveyType === 'local'): ?>
                <!-- Removed hardcoded demographic fields - these should be survey questions instead -->
                <p id="rating-instruction-1" data-translate="rating_instruction"><?php echo htmlspecialchars($surveySettings['rating_instruction1_text'] ?? $translations['rating_instruction'] ?? '1. Please rate each of the following parameters according to your experience today on a scale of 1 to 4.'); ?></p>
                <p id="rating-instruction-2" data-translate="rating_scale" style="color: red; font-size: 12px; font-style: italic;"><?php echo htmlspecialchars($surveySettings['rating_instruction2_text'] ?? $translations['rating_scale'] ?? 'where \'0\' means Poor, \'1\' Fair, \'2\' Good and \'3\' Excellent'); ?></p>

            <?php endif; ?>
           <div id="validation-message" style="display:none; color: #fff; background: #e74c3c; padding: 10px; border-radius: 4px; margin-bottom: 15px;"></div>
           <?php foreach ($questionsArray as $index => $question): ?>
            <div class="form-group survey-question enhanced"
                 data-question-index="<?php echo $index; ?>"
                 data-question-id="<?php echo $question['id']; ?>"
                 data-question-type="<?php echo $question['question_type']; ?>"
                 style="display: none;">
                <div class="radio-label field-label">
                    <span class="question-number" data-question-original="<?php echo ($index + 1); ?>"><?php echo ($index + 1) . '.'; ?></span>
                    <?php echo htmlspecialchars($question['label']); ?>
                    <?php if ($question['is_required']): ?>
                        <span class="required-indicator">*</span>
                    <?php endif; ?>
                </div>
                <?php if ($question['question_type'] == 'radio'): ?>
                    <div class="radio-options enhanced-options">
                        <?php foreach ($question['options'] as $option): ?>
                           <div class="radio-option enhanced-option">
                                <input type="radio"
                                       id="option_<?php echo $question['id']; ?>_<?php echo $option['id']; ?>"
                                       name="question_<?php echo $question['id']; ?>"
                                       value="<?php echo htmlspecialchars($option['option_value']); ?>"
                                       <?php echo $question['is_required'] ? 'required' : ''; ?>>
                                <label for="option_<?php echo $question['id']; ?>_<?php echo $option['id']; ?>">
                                    <?php echo htmlspecialchars($option['option_value']); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($question['question_type'] == 'checkbox'): ?>
                    <div class="checkbox-options enhanced-options" 
                         data-question-id="<?php echo $question['id']; ?>"
                         data-min-selections="<?php echo $question['min_selections'] ?? 1; ?>"
                         data-max-selections="<?php echo $question['max_selections'] ?? ''; ?>"
                         data-required="<?php echo $question['is_required'] ? 'true' : 'false'; ?>">
                        <?php foreach ($question['options'] as $option): ?>
                            <div class="checkbox-option enhanced-option">
                                <input type="checkbox"
                                       id="option_<?php echo $question['id']; ?>_<?php echo $option['id']; ?>"
                                       name="question_<?php echo $question['id']; ?>[]"
                                       value="<?php echo htmlspecialchars($option['option_value']); ?>"
                                       class="checkbox-input"
                                       data-question-id="<?php echo $question['id']; ?>">
                                <label for="option_<?php echo $question['id']; ?>_<?php echo $option['id']; ?>">
                                    <?php echo htmlspecialchars($option['option_value']); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>


                <?php elseif ($question['question_type'] == 'select'): ?>
                    <div style="max-width: 400px;">
                        <select class="form-control enhanced-input" name="question_<?php echo $question['id']; ?>" <?php echo $question['is_required'] ? 'required' : ''; ?>>
                            <option value="">Select an option</option>
                            <?php foreach ($question['options'] as $option): ?>
                                <option value="<?php echo htmlspecialchars($option['option_value']); ?>">
                                    <?php echo htmlspecialchars($option['option_value']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php elseif ($question['question_type'] == 'text'): ?>
                    <input type="text"
                           class="form-control enhanced-input"
                           name="question_<?php echo $question['id']; ?>"
                           placeholder="Enter your answer..."
                           <?php echo $question['is_required'] ? 'required' : ''; ?>>
                <?php elseif ($question['question_type'] == 'textarea'): ?>
                    <textarea class="form-control enhanced-input"
                              name="question_<?php echo $question['id']; ?>"
                              rows="4"
                              placeholder="Enter your detailed response..."
                              <?php echo $question['is_required'] ? 'required' : ''; ?>></textarea>

                <?php elseif ($question['question_type'] == 'rating'): ?>
    <div class="star-rating"
         data-question-id="<?php echo $question['id']; ?>"
         data-required="<?php echo $question['is_required'] ? 'true' : 'false'; ?>">
        <?php
        $maxStars = count($question['options']);
        for ($i = 1; $i <= $maxStars; $i++): ?>
            <div class="star-container">
                <div class="star-number"><?php echo $i; ?></div>
                <span class="star"
                      data-value="<?php echo $i; ?>"
                      aria-label="<?php echo $i; ?> star"
                      tabindex="0">&#9733;</span>
            </div>
        <?php endfor; ?>
        <input type="hidden"
               name="question_<?php echo $question['id']; ?>"
               id="star-rating-input-<?php echo $question['id']; ?>"
               value=""
               <?php echo $question['is_required'] ? 'required' : ''; ?>>
    </div>

                    <!-- New Question Types -->
                    <?php elseif ($question['question_type'] == 'number'): ?>
                        <div style="max-width: 300px;">
                            <input type="number"
                                   class="form-control enhanced-input"
                                   name="question_<?php echo $question['id']; ?>"
                                   placeholder="Enter number..."
                                   <?php 
                                   $validation = $question['validation_rules'] ? json_decode($question['validation_rules'], true) : [];
                                   if (isset($validation['min'])) echo 'min="' . $validation['min'] . '"';
                                   if (isset($validation['max'])) echo 'max="' . $validation['max'] . '"';
                                   if (isset($validation['decimals'])) echo 'step="' . (1 / pow(10, $validation['decimals'])) . '"';
                                   echo $question['is_required'] ? 'required' : '';
                                   ?>>
                        </div>

                    <?php elseif ($question['question_type'] == 'integer'): ?>
                        <div style="max-width: 300px;">
                            <input type="number"
                                   class="form-control enhanced-input"
                                   name="question_<?php echo $question['id']; ?>"
                                   step="1"
                                   placeholder="Enter whole number..."
                                   <?php 
                                   $validation = $question['validation_rules'] ? json_decode($question['validation_rules'], true) : [];
                                   if (isset($validation['min'])) echo 'min="' . $validation['min'] . '"';
                                   if (isset($validation['max'])) echo 'max="' . $validation['max'] . '"';
                                   echo $question['is_required'] ? 'required' : '';
                                   ?>>
                        </div>

                    <?php elseif ($question['question_type'] == 'decimal'): ?>
                        <div style="max-width: 300px;">
                            <input type="number"
                                   class="form-control enhanced-input"
                                   name="question_<?php echo $question['id']; ?>"
                                   placeholder="Enter decimal number..."
                                   <?php 
                                   $validation = $question['validation_rules'] ? json_decode($question['validation_rules'], true) : [];
                                   $decimals = $validation['decimals'] ?? 2;
                                   echo 'step="' . (1 / pow(10, $decimals)) . '"';
                                   if (isset($validation['min'])) echo 'min="' . $validation['min'] . '"';
                                   if (isset($validation['max'])) echo 'max="' . $validation['max'] . '"';
                                   echo $question['is_required'] ? 'required' : '';
                                   ?>>
                        </div>

                    <?php elseif ($question['question_type'] == 'percentage'): ?>
                        <div class="input-group-enhanced" style="max-width: 200px;">
                            <input type="number"
                                   class="form-control enhanced-input"
                                   name="question_<?php echo $question['id']; ?>"
                                   min="0" max="100" step="0.01"
                                   placeholder="0.00"
                                   <?php echo $question['is_required'] ? 'required' : ''; ?>>
                            <span class="input-group-text-enhanced">%</span>
                        </div>

                    <?php elseif ($question['question_type'] == 'date'): ?>
                        <div style="max-width: 250px;">
                            <input type="date"
                                   class="form-control enhanced-input"
                                   name="question_<?php echo $question['id']; ?>"
                                   <?php 
                                   $validation = $question['validation_rules'] ? json_decode($question['validation_rules'], true) : [];
                                   if (isset($validation['min_date'])) echo 'min="' . $validation['min_date'] . '"';
                                   if (isset($validation['max_date'])) echo 'max="' . $validation['max_date'] . '"';
                                   echo $question['is_required'] ? 'required' : '';
                                   ?>>
                        </div>

                    <?php elseif ($question['question_type'] == 'email'): ?>
                        <div style="max-width: 400px;">
                            <input type="email"
                                   class="form-control enhanced-input"
                                   name="question_<?php echo $question['id']; ?>"
                                   placeholder="email@example.com"
                                   <?php echo $question['is_required'] ? 'required' : ''; ?>>
                        </div>

                    <?php elseif ($question['question_type'] == 'phone'): ?>
                        <div style="max-width: 300px;">
                            <input type="tel"
                                   class="form-control enhanced-input"
                                   name="question_<?php echo $question['id']; ?>"
                                   placeholder="256XXXXXXXXX"
                                   <?php echo $question['is_required'] ? 'required' : ''; ?>>
                        </div>

                    <?php elseif ($question['question_type'] == 'likert_scale'): ?>
                        <div class="likert-scale">
                            <?php 
                            $validation = $question['validation_rules'] ? json_decode($question['validation_rules'], true) : [];
                            $range = explode('-', $validation['scale_range'] ?? '1-5');
                            $lowLabel = $validation['low_label'] ?? 'Strongly Disagree';
                            $highLabel = $validation['high_label'] ?? 'Strongly Agree';
                            ?>
                            <div class="scale-labels" style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                                <span style="font-size: 12px; color: #666;"><?php echo htmlspecialchars($lowLabel); ?></span>
                                <span style="font-size: 12px; color: #666;"><?php echo htmlspecialchars($highLabel); ?></span>
                            </div>
                            <div class="scale-options" style="display: flex; gap: 15px;">
                                <?php for ($i = $range[0]; $i <= $range[1]; $i++): ?>
                                    <label class="scale-option" style="display: flex; flex-direction: column; align-items: center;">
                                        <input type="radio" name="question_<?php echo $question['id']; ?>" value="<?php echo $i; ?>" <?php echo $question['is_required'] ? 'required' : ''; ?>>
                                        <span style="margin-top: 5px; font-size: 14px;"><?php echo $i; ?></span>
                                    </label>
                                <?php endfor; ?>
                            </div>
                        </div>

                    <?php elseif ($question['question_type'] == 'datetime'): ?>
                        <div style="max-width: 300px;">
                            <input type="datetime-local"
                                   class="form-control enhanced-input"
                                   name="question_<?php echo $question['id']; ?>"
                                   <?php echo $question['is_required'] ? 'required' : ''; ?>>
                        </div>

                    <?php elseif ($question['question_type'] == 'time'): ?>
                        <div style="max-width: 200px;">
                            <input type="time"
                                   class="form-control enhanced-input"
                                   name="question_<?php echo $question['id']; ?>"
                                   <?php echo $question['is_required'] ? 'required' : ''; ?>>
                        </div>

                    <?php elseif ($question['question_type'] == 'year'): ?>
                        <div style="max-width: 150px;">
                            <input type="number"
                                   class="form-control enhanced-input"
                                   name="question_<?php echo $question['id']; ?>"
                                   min="1900" max="2030" step="1"
                                   placeholder="YYYY"
                                   <?php echo $question['is_required'] ? 'required' : ''; ?>>
                        </div>

                    <?php elseif ($question['question_type'] == 'month'): ?>
                        <div style="max-width: 200px;">
                            <input type="month"
                                   class="form-control enhanced-input"
                                   name="question_<?php echo $question['id']; ?>"
                                   <?php echo $question['is_required'] ? 'required' : ''; ?>>
                        </div>

                    <?php elseif ($question['question_type'] == 'url'): ?>
                        <div style="max-width: 400px;">
                            <input type="url"
                                   class="form-control enhanced-input"
                                   name="question_<?php echo $question['id']; ?>"
                                   placeholder="https://example.com"
                                   <?php echo $question['is_required'] ? 'required' : ''; ?>>
                        </div>

                    <?php elseif ($question['question_type'] == 'national_id'): ?>
                        <div style="max-width: 300px;">
                            <input type="text"
                                   class="form-control enhanced-input"
                                   name="question_<?php echo $question['id']; ?>"
                                   placeholder="Enter National ID"
                                   <?php echo $question['is_required'] ? 'required' : ''; ?>>
                        </div>

                    <?php elseif (in_array($question['question_type'], ['country', 'region', 'city'])): ?>
                        <div style="max-width: 400px;">
                            <select class="form-control enhanced-input" name="question_<?php echo $question['id']; ?>" <?php echo $question['is_required'] ? 'required' : ''; ?>>
                                <option value="">Select <?php echo ucfirst(str_replace('_', ' ', $question['question_type'])); ?></option>
                                <?php if (!empty($question['options'])): ?>
                                    <?php foreach ($question['options'] as $option): ?>
                                        <option value="<?php echo htmlspecialchars($option['option_value']); ?>">
                                            <?php echo htmlspecialchars($option['option_value']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                    <?php elseif ($question['question_type'] == 'postal_code'): ?>
                        <div style="max-width: 200px;">
                            <input type="text"
                                   class="form-control enhanced-input"
                                   name="question_<?php echo $question['id']; ?>"
                                   placeholder="Enter postal code"
                                   <?php echo $question['is_required'] ? 'required' : ''; ?>>
                        </div>

                    <?php elseif ($question['question_type'] == 'currency'): ?>
                        <div class="input-group-enhanced" style="max-width: 200px;">
                            <span class="input-group-text-enhanced">$</span>
                            <input type="number"
                                   class="form-control enhanced-input"
                                   name="question_<?php echo $question['id']; ?>"
                                   step="0.01" min="0"
                                   placeholder="0.00"
                                   <?php echo $question['is_required'] ? 'required' : ''; ?>>
                        </div>

                    <?php elseif ($question['question_type'] == 'file_upload'): ?>
                        <div style="max-width: 400px;">
                            <input type="file"
                                   class="form-control enhanced-input"
                                   name="question_<?php echo $question['id']; ?>"
                                   <?php 
                                   $validation = $question['validation_rules'] ? json_decode($question['validation_rules'], true) : [];
                                   if (isset($validation['file_types'])) {
                                       $acceptTypes = array_map(function($type) { return '.' . $type; }, $validation['file_types']);
                                       echo 'accept="' . implode(',', $acceptTypes) . '"';
                                   }
                                   echo $question['is_required'] ? 'required' : '';
                                   ?>>
                        </div>

                    <?php elseif ($question['question_type'] == 'net_promoter_score'): ?>
                        <div class="nps-scale">
                            <div class="scale-labels" style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                                <span style="font-size: 12px; color: #666;">Not at all likely</span>
                                <span style="font-size: 12px; color: #666;">Extremely likely</span>
                            </div>
                            <div class="scale-options" style="display: flex; gap: 10px;">
                                <?php for ($i = 0; $i <= 10; $i++): ?>
                                    <label class="scale-option" style="display: flex; flex-direction: column; align-items: center;">
                                        <input type="radio" name="question_<?php echo $question['id']; ?>" value="<?php echo $i; ?>" <?php echo $question['is_required'] ? 'required' : ''; ?>>
                                        <span style="margin-top: 5px; font-size: 12px;"><?php echo $i; ?></span>
                                    </label>
                                <?php endfor; ?>
                            </div>
                        </div>

                    <?php elseif ($question['question_type'] == 'star_rating'): ?>
                        <div class="star-rating-input">
                            <div class="stars" style="font-size: 24px;">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <span class="star" data-value="<?php echo $i; ?>" style="cursor: pointer; color: #ddd;" data-question-id="<?php echo $question['id']; ?>">★</span>
                                <?php endfor; ?>
                            </div>
                            <input type="hidden" name="question_<?php echo $question['id']; ?>" value="" <?php echo $question['is_required'] ? 'required' : ''; ?>>
                        </div>

                    <?php elseif ($question['question_type'] == 'signature'): ?>
                        <div class="signature-container">
                            <canvas class="signature-canvas" width="400" height="200"></canvas>
                            <div class="signature-controls">
                                <button type="button" class="signature-clear-btn" onclick="clearSignature(this)">Clear Signature</button>
                            </div>
                            <input type="hidden" name="question_<?php echo $question['id']; ?>" value="" <?php echo $question['is_required'] ? 'required' : ''; ?>>
                        </div>

                    <?php elseif ($question['question_type'] == 'coordinates'): ?>
                        <div class="coordinates-container">
                            <input type="number" class="form-control enhanced-input" placeholder="Latitude" step="any" id="lat_<?php echo $question['id']; ?>">
                            <input type="number" class="form-control enhanced-input" placeholder="Longitude" step="any" id="lng_<?php echo $question['id']; ?>">
                            <button type="button" class="coordinates-btn" onclick="getCurrentLocation(<?php echo $question['id']; ?>)">Get Current Location</button>
                            <input type="hidden" name="question_<?php echo $question['id']; ?>" value="" <?php echo $question['is_required'] ? 'required' : ''; ?>>
                        </div>

                    <?php elseif ($question['question_type'] == 'color'): ?>
                        <input type="color"
                               class="form-control enhanced-input"
                               name="question_<?php echo $question['id']; ?>"
                               <?php echo $question['is_required'] ? 'required' : ''; ?>>

<?php endif; ?>


            </div>
        <?php endforeach; ?>


    </div>

  <div class="pagination-controls">
    <button type="button" id="prev-page-btn" style="display: none;">Back</button>
    <button type="button" id="next-page-btn">Next</button>
    
    <button type="submit" id="submit-button-final" style="display: inline-block;">Submit</button>
</div>
        </form>
    </div>

    <script>
        // Pass selected filters from PHP to JavaScript
        const surveyId = "<?php echo $surveyId; ?>";
        const preselectedInstanceKey = "<?php echo htmlspecialchars($selectedInstanceKey ?? ''); ?>";
        const preselectedHierarchyLevel = "<?php echo htmlspecialchars($selectedHierarchyLevel ?? ''); ?>";
        const totalQuestionsFromPHP = <?php echo count($questionsArray); ?>; // Pass total questions for pagination

        document.addEventListener('DOMContentLoaded', function() {
            // --- DOM Element References ---
            const facilitySearchInput = document.getElementById('facility-search');
            const facilityResultsDiv = document.getElementById('facility-results');
            const facilityIdInput = document.getElementById('facility_id');
            const pathDisplay = document.getElementById('path-display');
            const hierarchyDataInput = document.getElementById('hierarchy_data');
            const facilitySectionElement = document.getElementById('facility-section'); // For visibility check

            let currentFilteredLocations = []; // Holds locations matching the pre-selected filters

            // --- Helper Functions for Locations ---
            async function fetchLocationsForSurveyPage() {
                if (facilitySectionElement.style.display === 'none') {
                    // If the facility section is hidden by survey settings, do nothing.
                    // Ensure the facility search input is completely irrelevant for form submission if hidden.
                    if (facilitySearchInput) facilitySearchInput.removeAttribute('required');
                    if (facilityIdInput) facilityIdInput.removeAttribute('required');
                    return;
                }

                // If filters are not set in admin panel, disable search and show prompt
                if (!preselectedInstanceKey || !preselectedHierarchyLevel) {
                    if (facilitySearchInput) {
                        facilitySearchInput.disabled = true;
                        facilitySearchInput.placeholder = "Locations not configured by admin.";
                    }
                    if (facilityResultsDiv) {
                        facilityResultsDiv.innerHTML = '<div style="padding: 8px; color: #888;">No locations available. Filters not set in admin panel.</div>';
                        facilityResultsDiv.style.display = 'block';
                    }
                    // Make location selection NOT required if not configured
                    if (facilitySearchInput) facilitySearchInput.removeAttribute('required');
                    if (facilityIdInput) facilityIdInput.removeAttribute('required');
                    return;
                }

                // Filters are set, enable search and fetch data
                if (facilitySearchInput) {
                    facilitySearchInput.disabled = false;
                    facilitySearchInput.placeholder = "Type to search locations...";
                    // Make location selection required if configured
                    facilitySearchInput.setAttribute('required', 'required');
                    facilityIdInput.setAttribute('required', 'required');
                }

                try {
                    const params = new URLSearchParams();
                    params.append('instance_key', preselectedInstanceKey);
                    params.append('hierarchylevel', preselectedHierarchyLevel);

                    const response = await fetch(`/fbs/admin/get_locations.php?${params.toString()}`);
                    if (!response.ok) {
                        const errorText = await response.text();
                        throw new Error(`Network response was not ok (${response.status}): ${errorText}`);
                    }
                    const responseData = await response.json();

                    if (responseData.error) {
                        throw new Error(`Server Error: ${responseData.error}`);
                    }

                    currentFilteredLocations = responseData; // Populate the master list for this page
                    // Display all loaded locations initially
                    filterAndDisplaySearchResults(facilitySearchInput.value);

                } catch (error) {
                    console.error('Error fetching locations on survey page:', error);
                    if (facilityResultsDiv) {
                        facilityResultsDiv.innerHTML = `<div style="padding: 8px; color: red;">${error.message || 'Error loading locations.'}</div>`;
                        facilityResultsDiv.style.display = 'block';
                    }
                    if (facilitySearchInput) {
                        facilitySearchInput.disabled = true;
                        facilitySearchInput.placeholder = "Error loading locations.";
                        // Make location selection NOT required if there was an error loading them
                        facilitySearchInput.removeAttribute('required');
                        facilityIdInput.removeAttribute('required');
                    }
                }
            }

            async function fetchLocationPath(locationId) {
                if (!locationId) {
                    return '';
                }
                try {
                    const response = await fetch(`/fbs/admin/get_location_path.php?id=${locationId}`);
                    if (!response.ok) {
                        const errorText = await response.text();
                        throw new Error(`Network response was not ok (${response.status}): ${errorText}`);
                    }
                    const data = await response.json();
                    if (data.error) {
                        throw new Error(`Server Error: ${data.error}`);
                    }
                    return data.path || '';
                } catch (error) {
                    console.error('Error fetching location path:', error);
                    return `Error: ${error.message.substring(0, 50)}...`;
                }
            }

            function filterAndDisplaySearchResults(searchTerm) {
                if (!facilityResultsDiv || !facilitySearchInput) return; // Guard against elements not existing

                facilityResultsDiv.innerHTML = '';

                if (facilitySearchInput.disabled) {
                    // Message already set by fetchLocationsForSurveyPage if disabled
                    facilityResultsDiv.style.display = 'block';
                    return;
                }

                // Only show dropdown if search term has 2 or more characters
                if (searchTerm.length < 2) {
                    facilityResultsDiv.style.display = 'none';
                    return;
                }

                facilityResultsDiv.style.display = 'block';

                const lowerCaseSearchTerm = searchTerm.toLowerCase();
                const searchResults = currentFilteredLocations.filter(location =>
                    location.name.toLowerCase().includes(lowerCaseSearchTerm)
                );

                if (searchResults.length > 0) {
                    searchResults.forEach(location => {
                        const div = document.createElement('div');
                        div.classList.add('dropdown-item');
                        div.textContent = location.name;
                        div.dataset.id = location.id;
                        div.dataset.path = location.path;
                        div.dataset.hierarchylevel = location.hierarchylevel;
                        div.dataset.instancekey = location.instance_key;
                        facilityResultsDiv.appendChild(div);
                    });
                } else {
                    facilityResultsDiv.innerHTML = '<div style="padding: 8px; color: #888;">No matching locations found for your search.</div>';
                }
            }

            // --- Event Listeners for Locations ---
            if (facilitySearchInput) { // Check if facilitySearchInput exists before adding listeners
                facilitySearchInput.addEventListener('input', function() {
                    filterAndDisplaySearchResults(this.value);
                });

                facilitySearchInput.addEventListener('focus', function() {
                    // Only show results on focus if there are already 2+ characters
                    if (this.value.length >= 2) {
                        filterAndDisplaySearchResults(this.value);
                    }
                });
            }

            document.addEventListener('click', function(event) {
                if (facilitySearchInput && facilityResultsDiv &&
                    !facilitySearchInput.contains(event.target) && !facilityResultsDiv.contains(event.target)) {
                    facilityResultsDiv.style.display = 'none';
                }
            });

            if (facilityResultsDiv) { // Check if facilityResultsDiv exists
                facilityResultsDiv.addEventListener('click', async function(event) {
                    const target = event.target;
                    if (target.classList.contains('dropdown-item')) {
                        const locationId = target.dataset.id;

                        if (facilitySearchInput) facilitySearchInput.value = target.textContent;
                        if (facilityIdInput) facilityIdInput.value = locationId;

                        const humanReadablePath = await fetchLocationPath(locationId);
                        if (pathDisplay) pathDisplay.textContent = humanReadablePath;
                        if (hierarchyDataInput) hierarchyDataInput.value = humanReadablePath;

                        facilityResultsDiv.style.display = 'none';
                    }
                });
            }

            // --- Form validation function ---
            // This function checks all required fields on the current page, INCLUDING facility if visible
            function validateForm() {
                // Initial check for facility section if visible
                const isFacilitySectionVisible = facilitySectionElement && facilitySectionElement.style.display !== 'none';
                const facilityId = facilityIdInput ? facilityIdInput.value : '';

                if (isFacilitySectionVisible && facilityIdInput && facilityIdInput.hasAttribute('required') && !facilityId) {
                    showValidationMessage('Please select a location from the dropdown.');
                    if (facilitySearchInput) facilitySearchInput.focus();
                    return false;
                }

                // Now, validate current page's questions
                if (!validateCurrentPageQuestions()) return false;

                return true;
            }

            // --- Pagination logic for survey questions ---
            const QUESTIONS_PER_PAGE = 30;
            // Select questions directly; no need for a global 'questions' variable that could be overwritten
            const allSurveyQuestions = Array.from(document.querySelectorAll('.form-group.survey-question'));
            const totalQuestions = allSurveyQuestions.length; // Use total questions from DOM
            const totalPages = Math.ceil(totalQuestions / QUESTIONS_PER_PAGE);

            let currentPage = 1;

            const prevBtn = document.getElementById('prev-page-btn');
            const nextBtn = document.getElementById('next-page-btn');
            const submitBtn = document.getElementById('submit-button-final');

            function showPage(page) {
                // Hide all questions first
                allSurveyQuestions.forEach(q => q.style.display = 'none');

                // Determine which questions to show for the current page
                const start = (page - 1) * QUESTIONS_PER_PAGE;
                const end = Math.min(start + QUESTIONS_PER_PAGE, totalQuestions);

                for (let i = start; i < end; i++) {
                    allSurveyQuestions[i].style.display = ''; // Show relevant questions
                }

                // Update pagination button visibility
                prevBtn.style.display = (page > 1) ? '' : 'none';
                // Only show next if not on the last page AND if there are questions at all
                nextBtn.style.display = (page < totalPages && totalQuestions > 0) ? '' : 'none';
                submitBtn.style.display = (page === totalPages && totalQuestions > 0) ? '' : 'none';

                // If no questions or only one page, show submit button if necessary
                if (totalQuestions === 0 || totalPages === 1) {
                    nextBtn.style.display = 'none';
                    submitBtn.style.display = 'inline-block'; // Or whatever default submit display is
                }
                
                // Reapply skip logic after showing page
                if (typeof applyAllSkipLogic === 'function') {
                    setTimeout(applyAllSkipLogic, 50);
                }
            }

            prevBtn.addEventListener('click', function() {
                if (currentPage > 1) {
                    currentPage--;
                    showPage(currentPage);
                    window.scrollTo(0, 0); // Scroll to top for new page
                }
            });

            nextBtn.addEventListener('click', function() {
                if (!validateCurrentPageQuestions()) { // Validate only questions on current page
                    return;
                }
                if (currentPage < totalPages) {
                    currentPage++;
                    showPage(currentPage);
                    window.scrollTo(0, 0); // Scroll to top for new page
                }
            });

            function showValidationMessage(msg) {
                const msgDiv = document.getElementById('validation-message');
                if (msgDiv) {
                    msgDiv.textContent = msg;
                    msgDiv.style.display = 'block';
                    setTimeout(() => { msgDiv.style.display = 'none'; }, 4000);
                }
            }

            // Function to validate only questions visible on the current page
            function validateCurrentPageQuestions() {
                document.getElementById('validation-message').style.display = 'none'; // Hide previous message

                // Filter for currently visible questions (form-group.survey-question elements that are not display:none)
                const visibleQuestions = allSurveyQuestions.filter(q => q.style.display !== 'none');

                for (const q of visibleQuestions) {
                    const requiredInputs = q.querySelectorAll('[required]');
                    for (const input of requiredInputs) {
                        // Special handling for radio/checkbox groups
                        if (input.type === 'radio' || input.type === 'checkbox') {
                            const name = input.name;
                            // Select all inputs in the same group within the current visible question's context
                            const group = q.querySelectorAll(`[name="${name}"]`);
                            if (![...group].some(i => i.checked)) {
                                showValidationMessage('Please answer all required questions on this page.');
                                input.focus();
                                return false; // Validation failed
                            }
                        }
                        // General validation for other input types (text, number, select, textarea)
                        else if (!input.value.trim()) { // Use .trim() for text inputs
                            showValidationMessage('Please answer all required questions on this page.');
                            input.focus();
                            return false; // Validation failed
                        }
                    }
                }
                return true; // All visible required questions are answered
            }


            document.querySelector('form').addEventListener('submit', function(e) {
                // Prevent default submission to handle custom validation
                e.preventDefault();

                // Disable submit button immediately and show loading state
                const submitButton = document.getElementById('submit-button-final');
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.textContent = 'Submitting...';
                }

                // 1. Validate facility section first (if visible and required)
                const isFacilitySectionVisible = facilitySectionElement && facilitySectionElement.style.display !== 'none';
                const facilityId = facilityIdInput ? facilityIdInput.value : '';

                if (isFacilitySectionVisible && facilityIdInput && facilityIdInput.hasAttribute('required') && !facilityId) {
                    showValidationMessage('Please select a location from the dropdown.');
                    if (facilitySearchInput) facilitySearchInput.focus();
                    // Re-enable button on validation failure
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.textContent = 'Submit';
                    }
                    return; // Stop submission
                }

                // 2. Validate all questions across all pages on final submit
                // This is needed because questions on other pages won't be "visible"
                // but are still part of the form submission requirements.
                for (const q of allSurveyQuestions) { // Loop through ALL questions
                    const requiredInputs = q.querySelectorAll('[required]');
                    for (const input of requiredInputs) {
                        if (input.type === 'radio' || input.type === 'checkbox') {
                            const name = input.name;
                            const group = q.querySelectorAll(`[name="${name}"]`);
                            if (![...group].some(i => i.checked)) {
                                showValidationMessage('Please answer all required questions before submitting.');
                                // Optionally, navigate to the page where this question is, and focus
                                // For simplicity, we just alert/show message here for cross-page issues.
                                // Re-enable button on validation failure
                                if (submitButton) {
                                    submitButton.disabled = false;
                                    submitButton.textContent = 'Submit';
                                }
                                return; // Stop submission
                            }
                        } else if (!input.value.trim()) {
                            showValidationMessage('Please answer all required questions before submitting.');
                            // Re-enable button on validation failure
                            if (submitButton) {
                                submitButton.disabled = false;
                                submitButton.textContent = 'Submit';
                            }
                            return; // Stop submission
                        }
                    }
                }

                // If all validations pass, manually submit the form
                // Button stays disabled during submission
                this.submit();
            });

            // Apply survey settings visibility controls
            applyVisibilitySettings();
            
            // Initial load: Show the first page and fetch locations
            showPage(currentPage);
            fetchLocationsForSurveyPage(); // Call this function on page load
            
            // Initialize skip logic after page setup
            if (typeof initializeSkipLogic === 'function') {
                setTimeout(initializeSkipLogic, 100); // Small delay to ensure DOM is ready
            }
            
            function applyVisibilitySettings() {
                // Get survey settings from PHP
                const settings = <?php echo json_encode($surveySettings); ?>;
                const surveyType = "<?php echo $surveyType; ?>";
                
                console.log('Raw settings from database:', settings);
                console.log('Survey type:', surveyType);
                
                // Convert values to proper booleans (handle string '0', '1', 0, 1, true, false)
                function toBool(value) {
                    if (value === null || value === undefined) return false;
                    if (typeof value === 'boolean') return value;
                    if (typeof value === 'string') return value === '1' || value.toLowerCase() === 'true';
                    if (typeof value === 'number') return value === 1;
                    return false;
                }
                
                // Apply visibility for each element with proper boolean conversion
                const visibilityMap = {
                    'logo-section': toBool(settings.show_logo),
                    'flag-bar': toBool(settings.show_flag_bar),
                    'survey-title': toBool(settings.show_title),
                    'survey-subheading': toBool(settings.show_subheading),
                    'republic-title': toBool(settings.show_republic_title_share),
                    'ministry-subtitle': toBool(settings.show_ministry_subtitle_share),
                    'facility-section': toBool(settings.show_facility_section),
                    'rating-instruction-1': toBool(settings.show_rating_instructions) && (surveyType === 'local'),
                    'rating-instruction-2': toBool(settings.show_rating_instructions) && (surveyType === 'local'),
                    'submit-button-final': toBool(settings.show_submit_button)
                };
                
                console.log('Processed visibility map:', visibilityMap);
                
                // Apply visibility settings
                Object.keys(visibilityMap).forEach(elementId => {
                    const element = document.getElementById(elementId);
                    if (element) {
                        const shouldShow = visibilityMap[elementId];
                        
                        // Remove any existing visibility classes first
                        element.classList.remove('hidden-element', 'show-element', 'show-flex');
                        
                        if (shouldShow) {
                            // Show the element - reset all hiding styles
                            element.style.removeProperty('height');
                            element.style.removeProperty('margin');
                            element.style.removeProperty('padding');
                            element.style.removeProperty('overflow');
                            element.style.removeProperty('position');
                            element.style.removeProperty('left');
                            element.style.visibility = 'visible';
                            element.style.opacity = '1';
                            
                            if (elementId === 'flag-bar' || elementId.includes('location-row')) {
                                element.style.display = 'flex';
                                element.classList.add('show-flex');
                            } else {
                                element.style.display = 'block';
                                element.classList.add('show-element');
                            }
                        } else {
                            // Hide the element completely
                            element.classList.add('hidden-element');
                            // Force hide with multiple methods
                            element.style.setProperty('display', 'none', 'important');
                            element.style.setProperty('visibility', 'hidden', 'important');
                            element.style.setProperty('opacity', '0', 'important');
                            element.style.setProperty('height', '0', 'important');
                            element.style.setProperty('max-height', '0', 'important');
                            element.style.setProperty('margin', '0', 'important');
                            element.style.setProperty('padding', '0', 'important');
                            element.style.setProperty('overflow', 'hidden', 'important');
                            element.style.setProperty('position', 'absolute', 'important');
                            element.style.setProperty('left', '-9999px', 'important');
                        }
                        
                        // Debug the field mapping
                        let fieldName = elementId.replace('-', '_');
                        
                        console.log(`Element ${elementId}: ${shouldShow ? 'SHOWN' : 'HIDDEN'} (field: ${fieldName}, raw value: ${settings[fieldName]}, converted: ${shouldShow})`);
                    } else {
                        console.warn(`Element with ID ${elementId} not found`);
                    }
                });
                
                // Special handling for survey type-specific elements
                if (surveyType === 'dhis2') {
                    console.log('DHIS2 survey detected - hiding local-only elements');
                    // Force hide local-only elements for DHIS2 surveys
                    const localOnlyElements = ['rating-instruction-1', 'rating-instruction-2'];
                    localOnlyElements.forEach(elementId => {
                        const element = document.getElementById(elementId);
                        if (element) {
                            element.classList.add('hidden-element');
                            element.style.display = 'none';
                            console.log(`Force hiding ${elementId} for DHIS2 survey`);
                        }
                    });
                }
                
                // Final verification - log all element states
                setTimeout(() => {
                    Object.keys(visibilityMap).forEach(elementId => {
                        const element = document.getElementById(elementId);
                        if (element) {
                            const computedStyle = window.getComputedStyle(element);
                            console.log(`Final state - ${elementId}: display=${computedStyle.display}, visibility=${computedStyle.visibility}, classes=[${element.className}]`);
                            
                            // Debug logging cleaned up - no longer referencing unused database columns
                        }
                    });
                }, 100);
            }


            // Star rating logic (existing code, ensure it's here)
            document.querySelectorAll('.star-rating').forEach(function(starRatingDiv) {
                const stars = Array.from(starRatingDiv.querySelectorAll('.star'));
                const input = starRatingDiv.querySelector('input[type="hidden"]');
                let selectedValue = 0;

                // Restore selected value if form is reloaded (e.g., due to validation error)
                if (input.value) {
                    selectedValue = parseInt(input.value, 10);
                    setStars(selectedValue);
                }

                function setStars(value) {
                    stars.forEach((star, idx) => {
                        if (idx < value) {
                            star.classList.add('selected');
                        } else {
                            star.classList.remove('selected');
                        }
                    });
                }

                stars.forEach((star, idx) => {
                    star.addEventListener('mouseenter', () => {
                        setStars(idx + 1);
                        stars.forEach((s, i) => s.classList.toggle('hovered', i <= idx));
                    });
                    star.addEventListener('mouseleave', () => {
                        setStars(selectedValue);
                        stars.forEach(s => s.classList.remove('hovered'));
                    });
                    star.addEventListener('click', () => {
                        selectedValue = idx + 1;
                        setStars(selectedValue);
                        input.value = selectedValue;
                    });
                    star.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter' || e.key === ' ') {
                            selectedValue = idx + 1;
                            setStars(selectedValue);
                            input.value = selectedValue;
                            e.preventDefault();
                            star.blur(); // Remove focus after selection
                        }
                        if (e.key === 'ArrowLeft' && selectedValue > 1) {
                            selectedValue--;
                            setStars(selectedValue);
                            input.value = selectedValue;
                            stars[selectedValue - 1].focus();
                            e.preventDefault();
                        }
                        if (e.key === 'ArrowRight' && selectedValue < stars.length) {
                            selectedValue++;
                            setStars(selectedValue);
                            input.value = selectedValue;
                            stars[selectedValue - 1].focus();
                            e.preventDefault();
                        }
                    });
                });

                starRatingDiv.addEventListener('mouseleave', () => {
                    setStars(selectedValue);
                    stars.forEach(s => s.classList.remove('hovered'));
                });
            });
        });

        // Checkbox validation for min/max selections
        function validateCheckboxGroups() {
            let allValid = true;
            const checkboxGroups = document.querySelectorAll('.checkbox-options[data-required="true"]');
            
            checkboxGroups.forEach(group => {
                const questionId = group.getAttribute('data-question-id');
                const minSelections = parseInt(group.getAttribute('data-min-selections') || 1);
                const maxSelections = group.getAttribute('data-max-selections');
                const checkboxes = group.querySelectorAll('input[type="checkbox"]');
                const checkedBoxes = group.querySelectorAll('input[type="checkbox"]:checked');
                
                // Remove previous error styling
                group.style.border = '';
                group.style.backgroundColor = '';
                
                // Check minimum selections
                if (checkedBoxes.length < minSelections) {
                    group.style.border = '2px solid #dc3545';
                    group.style.backgroundColor = '#f8d7da';
                    allValid = false;
                    
                    // Show error message
                    let errorMsg = group.querySelector('.checkbox-error-msg');
                    if (!errorMsg) {
                        errorMsg = document.createElement('div');
                        errorMsg.className = 'checkbox-error-msg';
                        errorMsg.style.color = '#dc3545';
                        errorMsg.style.fontSize = '12px';
                        errorMsg.style.marginTop = '5px';
                        group.appendChild(errorMsg);
                    }
                    errorMsg.textContent = `Please select at least ${minSelections} option${minSelections > 1 ? 's' : ''}.`;
                } else if (maxSelections && checkedBoxes.length > parseInt(maxSelections)) {
                    group.style.border = '2px solid #dc3545';
                    group.style.backgroundColor = '#f8d7da';
                    allValid = false;
                    
                    // Show error message
                    let errorMsg = group.querySelector('.checkbox-error-msg');
                    if (!errorMsg) {
                        errorMsg = document.createElement('div');
                        errorMsg.className = 'checkbox-error-msg';
                        errorMsg.style.color = '#dc3545';
                        errorMsg.style.fontSize = '12px';
                        errorMsg.style.marginTop = '5px';
                        group.appendChild(errorMsg);
                    }
                    errorMsg.textContent = `Please select no more than ${maxSelections} option${maxSelections > 1 ? 's' : ''}.`;
                } else {
                    // Remove error message if validation passes
                    const errorMsg = group.querySelector('.checkbox-error-msg');
                    if (errorMsg) {
                        errorMsg.remove();
                    }
                }
            });
            
            return allValid;
        }

        // Add event listeners to checkbox inputs
        document.addEventListener('DOMContentLoaded', function() {
            const checkboxInputs = document.querySelectorAll('.checkbox-input');
            checkboxInputs.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const questionId = this.getAttribute('data-question-id');
                    const group = document.querySelector(`.checkbox-options[data-question-id="${questionId}"]`);
                    if (group && group.getAttribute('data-required') === 'true') {
                        validateCheckboxGroups();
                    }
                });
            });

            // Star rating functionality
            const stars = document.querySelectorAll('.star-rating-input .star');
            stars.forEach(star => {
                star.addEventListener('click', function() {
                    const questionId = this.getAttribute('data-question-id');
                    const value = this.getAttribute('data-value');
                    const hiddenInput = document.querySelector(`input[name="question_${questionId}"]`);
                    const starContainer = this.parentElement;
                    
                    // Update hidden input
                    hiddenInput.value = value;
                    
                    // Update star display
                    const allStars = starContainer.querySelectorAll('.star');
                    allStars.forEach((s, index) => {
                        if (index < value) {
                            s.style.color = '#ffd700'; // Gold color for selected
                        } else {
                            s.style.color = '#ddd'; // Gray color for unselected
                        }
                    });
                });
            });

            // Coordinates functionality
            window.getCurrentLocation = function(questionId) {
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(function(position) {
                        document.getElementById(`lat_${questionId}`).value = position.coords.latitude;
                        document.getElementById(`lng_${questionId}`).value = position.coords.longitude;
                        
                        // Update hidden input
                        const hiddenInput = document.querySelector(`input[name="question_${questionId}"]`);
                        hiddenInput.value = `${position.coords.latitude},${position.coords.longitude}`;
                    });
                } else {
                    alert("Geolocation is not supported by this browser.");
                }
            };

            // Signature functionality placeholder
            window.clearSignature = function(button) {
                const canvas = button.parentElement.previousElementSibling;
                const context = canvas.getContext('2d');
                context.clearRect(0, 0, canvas.width, canvas.height);
                
                // Clear hidden input
                const hiddenInput = button.parentElement.parentElement.querySelector('input[type="hidden"]');
                hiddenInput.value = '';
            };
        });

        // Override form submission to include checkbox validation
        const originalSubmit = HTMLFormElement.prototype.submit;
        HTMLFormElement.prototype.submit = function() {
            if (validateCheckboxGroups()) {
                originalSubmit.call(this);
            } else {
                // Scroll to first error
                const firstError = document.querySelector('.checkbox-options[style*="border: 2px solid"]');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        };

        // Add submit event listener to forms
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    if (!validateCheckboxGroups()) {
                        e.preventDefault();
                        // Scroll to first error
                        const firstError = document.querySelector('.checkbox-options[style*="border: 2px solid"]');
                        if (firstError) {
                            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }
                });
            });
        });
    </script>
    
    <!-- Skip Logic JavaScript -->
    <script>
        <?php echo generateSkipLogicJS($surveyId, $pdo); ?>
    </script>
    
    <!-- Dynamic Question Numbering JavaScript -->
    <script>
        function updateQuestionNumbering() {
            const visibleQuestions = document.querySelectorAll('.survey-question[style*="block"], .survey-question:not([style*="none"])');
            let questionNumber = 1;
            
            visibleQuestions.forEach(function(questionElement) {
                const numberSpan = questionElement.querySelector('.question-number');
                if (numberSpan) {
                    // Get numbering style from settings (default to numeric)
                    const numberingStyle = '<?php echo $surveySettings['numbering_style'] ?? 'numeric'; ?>';
                    const showNumbering = <?php echo json_encode($surveySettings['show_numbering'] ?? true); ?>;
                    
                    if (showNumbering) {
                        let displayNumber = '';
                        switch (numberingStyle) {
                            case 'alphabetic_lower':
                                displayNumber = String.fromCharCode(96 + questionNumber) + '.'; // a, b, c...
                                break;
                            case 'alphabetic_upper':
                                displayNumber = String.fromCharCode(64 + questionNumber) + '.'; // A, B, C...
                                break;
                            case 'roman_lower':
                                displayNumber = toRoman(questionNumber).toLowerCase() + '.'; // i, ii, iii...
                                break;
                            case 'roman_upper':
                                displayNumber = toRoman(questionNumber) + '.'; // I, II, III...
                                break;
                            case 'none':
                                displayNumber = '';
                                break;
                            case 'numeric':
                            default:
                                displayNumber = questionNumber + '.'; // 1, 2, 3...
                                break;
                        }
                        numberSpan.textContent = displayNumber;
                        numberSpan.style.display = displayNumber ? 'inline' : 'none';
                    } else {
                        numberSpan.style.display = 'none';
                    }
                    questionNumber++;
                }
            });
        }
        
        // Roman numeral conversion function
        function toRoman(num) {
            const values = [1000, 900, 500, 400, 100, 90, 50, 40, 10, 9, 5, 4, 1];
            const symbols = ['M', 'CM', 'D', 'CD', 'C', 'XC', 'L', 'XL', 'X', 'IX', 'V', 'IV', 'I'];
            let result = '';
            for (let i = 0; i < values.length; i++) {
                while (num >= values[i]) {
                    result += symbols[i];
                    num -= values[i];
                }
            }
            return result;
        }
        
        // Update numbering when page loads
        document.addEventListener('DOMContentLoaded', function() {
            updateQuestionNumbering();
        });
        
        // Update numbering whenever skip logic shows/hides questions
        document.addEventListener('skipLogicUpdate', function() {
            updateQuestionNumbering();
        });
        
        // Also update on any question visibility change
        const observer = new MutationObserver(function(mutations) {
            let shouldUpdate = false;
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
                    if (mutation.target.classList.contains('survey-question')) {
                        shouldUpdate = true;
                    }
                }
            });
            if (shouldUpdate) {
                setTimeout(updateQuestionNumbering, 50); // Small delay to ensure all changes are processed
            }
        });
        
        // Observe all question elements for style changes
        document.querySelectorAll('.survey-question').forEach(function(question) {
            observer.observe(question, { attributes: true, attributeFilter: ['style'] });
        });
    </script>
    
    <script defer src="survey_page.js"></script>
</body>
</html>