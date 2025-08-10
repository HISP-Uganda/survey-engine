<?php
session_start();
require_once 'includes/session_timeout.php';
// Include the database connection file
require_once 'connect.php'; // Make sure the path is correct relative to this file
require_once 'includes/survey_renderer.php';

// Check if $pdo object is available from connect.php
if (!isset($pdo)) {
    die("Database connection failed. Please check connect.php.");
}

// Get survey_id from the URL
$surveyId = $_GET['survey_id'] ?? null;

if (!$surveyId) {
    die("Survey ID is missing.");
}

// Fetch survey details including survey name AND TYPE
try {
    $surveyStmt = $pdo->prepare("SELECT id, type, name FROM survey WHERE id = ?");
    $surveyStmt->execute([$surveyId]);
    $survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);

    if (!$survey) {
        die("Survey not found.");
    }
    $surveyType = $survey['type']; // Get the survey type here
} catch (PDOException $e) {
    error_log("Database error fetching survey details: " . $e->getMessage());
    die("Error fetching survey details.");
}

// Set the default survey title from the database
$defaultSurveyTitle = htmlspecialchars($survey['name'] ?? 'Ministry of Health Client Satisfaction Feedback Tool');

// Fetch translations for the selected language
$language = isset($_GET['language']) ? $_GET['language'] : 'en'; // Default to English
$translations = [];

try {
    $query = "SELECT key_name, translations FROM default_text";
    $translations_stmt = $pdo->query($query); // Use query for simple selects without parameters
    while ($row = $translations_stmt->fetch(PDO::FETCH_ASSOC)) {
        $decoded_translations = json_decode($row['translations'], true);
        $translations[$row['key_name']] = $decoded_translations[$language] ?? $row['key_name'];
    }
} catch (PDOException $e) {
    error_log("Database error fetching translations: " . $e->getMessage());
    // Continue with empty translations if fetch fails
}

// Hierarchy Level Mapping (Fixed to Level X)
$hierarchyLevels = ['' => 'All Levels']; // Add "All Levels" option with empty value
for ($i = 1; $i <= 8; $i++) {
    $hierarchyLevels[$i] = 'Level ' . $i;
}

// --- FIX: Define all default variables at a global scope ---
// This ensures they are accessible by all fallback blocks in case of DB issues.
$defaultLogoPath = 'asets/asets/img/loog.jpg';
$defaultShowLogo = 1;
$defaultFlagBlackColor = '#000000';
$defaultFlagYellowColor = '#FCD116';
$defaultFlagRedColor = '#D21034';
$defaultShowFlagBar = 1;
$defaultTitleText = $defaultSurveyTitle;
$defaultShowTitle = 1;
$defaultSubheadingText = $translations['subheading'] ?? 'This tool is used to obtain clients\' feedback about their experience with the services and promote quality improvement, accountability, and transparency within the healthcare system.';
$defaultShowSubheading = 1;
$defaultShowSubmitButton = 1;
$defaultRatingInstruction1Text = $translations['rating_instruction'] ?? '1. Please rate each of the following parameters according to your experience today on a scale of 1 to 4.';
$defaultRatingInstruction2Text = $translations['rating_scale'] ?? 'where \'0\' means Poor, \'1\' Fair, \'2\' Good and \'3\' Excellent';
$defaultShowRatingInstructions = 1;
$defaultShowFacilitySection = 1;
$defaultShowLocationRowGeneral = 1;
$defaultShowLocationRowPeriodAge = 1;
$defaultShowOwnershipSection = 1;
$defaultRepublicTitleText = 'THE REPUBLIC OF UGANDA';
$defaultShowRepublicTitleShare = 1;
$defaultMinistrySubtitleText = 'MINISTRY OF HEALTH';
$defaultShowMinistrySubtitleShare = 1; // This was the undefined variable
$defaultQrInstructionsText = 'Scan this QR Code to Give Your Feedback on Services Received';
$defaultShowQrInstructionsShare = 1;
$defaultFooterNoteText = 'Thank you for helping us improve our services.';
$defaultShowFooterNoteShare = 1;
$defaultSelectedInstanceKey = null;
$defaultSelectedHierarchyLevel = null;
// --- END FIX ---


// Fetch survey settings from the database
$surveySettings = [];
try {
    $settingsStmt = $pdo->prepare("SELECT * FROM survey_settings WHERE survey_id = ?");
    $settingsStmt->execute([$surveyId]);
    $existingSettings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingSettings) {
        // If settings exist, use them
        $surveySettings = $existingSettings;
        // Convert boolean-like strings/integers to actual booleans for JavaScript convenience
        foreach(['show_logo', 'show_flag_bar', 'show_title', 'show_subheading', 'show_submit_button',
                 'show_rating_instructions', 'show_facility_section', 'show_republic_title_share',
                 'show_ministry_subtitle_share', 'show_qr_instructions_share', 'show_footer_note_share'] as $key) {
            if (isset($surveySettings[$key])) {
                $surveySettings[$key] = (bool)$surveySettings[$key];
            }
        }

    } else {
        // If no settings exist for this survey, insert default values
        $insertStmt = $pdo->prepare("
            INSERT INTO survey_settings (
                survey_id, logo_path, show_logo, flag_black_color, flag_yellow_color, flag_red_color, show_flag_bar,
                title_text, show_title, subheading_text, show_subheading, show_submit_button,
                rating_instruction1_text, rating_instruction2_text, show_rating_instructions,
                show_facility_section, republic_title_text, show_republic_title_share, ministry_subtitle_text, show_ministry_subtitle_share,
                qr_instructions_text, show_qr_instructions_share, footer_note_text, show_footer_note_share,
                selected_instance_key, selected_hierarchy_level
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
        ");

        $insertData = [
            $surveyId, $defaultLogoPath, $defaultShowLogo, $defaultFlagBlackColor, $defaultFlagYellowColor, $defaultFlagRedColor, $defaultShowFlagBar,
            $defaultTitleText, $defaultShowTitle, $defaultSubheadingText, $defaultShowSubheading, $defaultShowSubmitButton,
            $defaultRatingInstruction1Text, $defaultRatingInstruction2Text, $defaultShowRatingInstructions,
            $defaultShowFacilitySection, $defaultRepublicTitleText, $defaultShowRepublicTitleShare, $defaultMinistrySubtitleText, $defaultShowMinistrySubtitleShare,
            $defaultQrInstructionsText, $defaultShowQrInstructionsShare, $defaultFooterNoteText, $defaultShowFooterNoteShare,
            $defaultSelectedInstanceKey, $defaultSelectedHierarchyLevel
        ];

        if ($insertStmt->execute($insertData)) {
            $settingsStmt->execute([$surveyId]); // Re-fetch to get newly inserted defaults
            $surveySettings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
             // Convert boolean-like integers to actual booleans for JavaScript convenience
            foreach(['show_logo', 'show_flag_bar', 'show_title', 'show_subheading', 'show_submit_button',
                     'show_rating_instructions', 'show_facility_section', 'show_republic_title_share',
                     'show_ministry_subtitle_share', 'show_qr_instructions_share', 'show_footer_note_share'] as $key) {
                if (isset($surveySettings[$key])) {
                    $surveySettings[$key] = (bool)$surveySettings[$key];
                }
            }
        } else {
            error_log("Error inserting default survey settings: " . json_encode($insertStmt->errorInfo()));
            // Fallback: use hardcoded defaults if DB insert fails
            $surveySettings = [
                'logo_path' => $defaultLogoPath, 'show_logo' => (bool)$defaultShowLogo,
                'flag_black_color' => $defaultFlagBlackColor, 'flag_yellow_color' => $defaultFlagYellowColor, 'flag_red_color' => $defaultFlagRedColor, 'show_flag_bar' => (bool)$defaultShowFlagBar,
                'title_text' => $defaultTitleText, 'show_title' => (bool)$defaultShowTitle,
                'subheading_text' => $defaultSubheadingText, 'show_subheading' => (bool)$defaultShowSubheading, 'show_submit_button' => (bool)$defaultShowSubmitButton,
                'rating_instruction1_text' => $defaultRatingInstruction1Text, 'rating_instruction2_text' => $defaultRatingInstruction2Text, 'show_rating_instructions' => (bool)$defaultShowRatingInstructions,
                'show_facility_section' => (bool)$defaultShowFacilitySection,
                'republic_title_text' => $defaultRepublicTitleText, 'show_republic_title_share' => (bool)$defaultShowRepublicTitleShare,
                'ministry_subtitle_text' => $defaultMinistrySubtitleText, 'show_ministry_subtitle_share' => (bool)$defaultShowMinistrySubtitleShare,
                'qr_instructions_text' => $defaultQrInstructionsText, 'show_qr_instructions_share' => (bool)$defaultShowQrInstructionsShare,
                'footer_note_text' => $defaultFooterNoteText, 'show_footer_note_share' => (bool)$defaultShowFooterNoteShare,
                'selected_instance_key' => $defaultSelectedInstanceKey,
                'selected_hierarchy_level' => $defaultSelectedHierarchyLevel,
            ];
        }
    }
} catch (PDOException $e) {
    error_log("Database error fetching or inserting survey settings: " . $e->getMessage());
    // Fallback: use hardcoded defaults on DB error
    $surveySettings = [
        'logo_path' => $defaultLogoPath, 'show_logo' => true,
        'flag_black_color' => $defaultFlagBlackColor, 'flag_yellow_color' => $defaultFlagYellowColor, 'flag_red_color' => $defaultFlagRedColor, 'show_flag_bar' => true,
        'title_text' => $defaultTitleText, 'show_title' => true,
        'subheading_text' => $defaultSubheadingText, 'show_subheading' => true, 'show_submit_button' => true,
        'rating_instruction1_text' => $defaultRatingInstruction1Text, 'rating_instruction2_text' => $defaultRatingInstruction2Text, 'show_rating_instructions' => true,
        'show_facility_section' => true,
        'republic_title_text' => $defaultRepublicTitleText, 'show_republic_title_share' => true,
        'ministry_subtitle_text' => $defaultMinistrySubtitleText, 'show_ministry_subtitle_share' => true,
        'qr_instructions_text' => $defaultQrInstructionsText, 'show_qr_instructions_share' => true,
        'footer_note_text' => $defaultFooterNoteText, 'show_footer_note_share' => true,
        'selected_instance_key' => $defaultSelectedInstanceKey,
        'selected_hierarchy_level' => $defaultSelectedHierarchyLevel,
    ];
}

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


// Fetch questions and options for the selected survey, ordered by position
$questionsArray = [];
try {
    $questionsStmt = $pdo->prepare("
        SELECT q.id, q.label, q.question_type, q.is_required, q.translations, q.option_set_id, sq.position
        FROM question q
        JOIN survey_question sq ON q.id = sq.question_id
        WHERE sq.survey_id = ?
        ORDER BY sq.position ASC
    ");
    $questionsStmt->execute([$surveyId]);

    while ($question = $questionsStmt->fetch(PDO::FETCH_ASSOC)) {
        $question['options'] = [];

        // Fetch options for the question with original order
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
    error_log("Database error fetching questions and options: " . $e->getMessage());
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $defaultSurveyTitle; ?> - Preview</title>
    <link href="argon-dashboard-master/assets/css/nucleo-icons.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-svg.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/argon-dashboard.min.css" rel="stylesheet">
    <style>
        /* Existing CSS from your previous code */
        body {
            font-family: Arial, sans-serif;
            background-color: #f8f9fa;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
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

        /* Preview and Control Panel Layout */
        .main-content {
            display: flex;
            flex-grow: 1;
            width: 100%;
        }
        .preview-container {
            flex: 2;
            padding: 20px;
            box-sizing: border-box;
            overflow-y: auto; /* Allow preview to scroll if content is long */
        }
        .control-panel {
            flex: 1;
            background-color: #f0f0f0;
            border-left: 1px solid #ddd;
            padding: 20px;
            box-sizing: border-box;
            overflow-y: auto; /* Allow control panel to scroll */
            padding-bottom: 80px; /* Space for the fixed bottom controls */
        }
        .control-panel h3 {
            margin-top: 0;
            color: #333;
        }
        .control-panel .setting-group {
            margin-bottom: 15px; /* Reduced margin, no bottom border now */
            /* Removed padding-bottom and border-bottom as accordion-content handles separation */
        }
        .control-panel label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
        }
        .control-panel input[type="text"],
        .control-panel textarea,
        .control-panel input[type="color"],
        .control-panel input[type="file"],
        .control-panel select { /* Added select */
            width: calc(100% - 10px);
            padding: 8px;
            margin-bottom: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .control-panel .checkbox-group label {
            display: flex;
            align-items: center;
            font-weight: normal;
        }
        .control-panel .checkbox-group input[type="checkbox"] {
            margin-right: 10px;
            width: auto;
        }
        .flag-bar {
            display: flex;
            height: 10px;
            margin-top: 10px;
            margin-bottom: 20px;
        }
        .flag-black, .flag-yellow, .flag-red {
            flex: 1;
        }
        /* Default colors for flag bar (can be overridden by JS) */
        .flag-black { background-color: #000; }
        .flag-yellow { background-color: #FCD116; /* Gold */ }
        .flag-red { background-color: #D21034; /* Red */ }

        /* Hideable elements */
        .hidden-element {
            display: none !important;
        }

        /* Accordion Styles */
        .accordion-item {
            border: 1px solid #ddd;
            margin-bottom: 10px;
            border-radius: 5px;
            background-color: #fff;
        }

        .accordion-header {
            background-color: #e9e9e9;
            color: #333;
            cursor: pointer;
            padding: 12px 15px;
            width: 100%;
            text-align: left;
            border: none;
            outline: none;
            transition: background-color 0.3s ease;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: bold;
            border-radius: 5px 5px 0 0;
        }

        .accordion-header:hover {
            background-color: #dcdcdc;
        }

        .accordion-header i {
            transition: transform 0.3s ease;
        }

        .accordion-header.active i {
            transform: rotate(180deg);
        }

        .accordion-content {
            padding: 15px;
            background-color: #f9f9f9;
            border-top: 1px solid #eee;
            display: none; /* Hidden by default */
            overflow: hidden;
            border-radius: 0 0 5px 5px;
        }

        .accordion-content.active {
            display: block;
        }

        /* Bottom Controls Styling (Fixed Footer) */
        .bottom-controls {
            position: fixed; /* Make it fixed */
            bottom: 0; /* Stick to the bottom */
            left: 0;
            width: 100%; /* Take full width */
            display: flex;
            justify-content: center;
            gap: 20px;
            padding: 15px 20px;
            background: #fff;
            border-top: 1px solid #ddd;
            box-shadow: 0 -2px 5px rgba(0,0,0,0.1); /* Add a subtle shadow */
            z-index: 1000; /* Ensure it's on top */
        }
        .action-button {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.3s ease;
        }
        .action-button:hover {
            background-color: #0056b3;
        }
        .action-button i {
            font-size: 18px;
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
        /* NEW: Styles for the new filter selects */
        .filter-group {
            margin-bottom: 15px;
        }
        .filter-group label {
            font-weight: bold;
            margin-bottom: 5px;
            display: block;
        }
        .filter-group select {
            width: 100%;
            padding: 8px;
            margin-bottom: 10px; /* Added margin for consistency */
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box; /* Ensures padding is included in width */
        }
        .hierarchy-path .path-display {
            font-size: 0.9em;
            color: #555;
            margin-top: 5px;
            word-break: break-all; /* Break long paths */
        }
        /* Added for the dropdown results display */
        .dropdown-results {
            max-height: 200px; /* Limit height */
            overflow-y: auto; /* Enable scrolling */
            border: 1px solid #ddd;
            border-top: none; /* No top border if part of same input group */
            border-radius: 0 0 4px 4px;
            background-color: #fff;
            position: absolute; /* Position relative to parent .searchable-dropdown */
            width: 100%;
            z-index: 100; /* Ensure it's above other elements */
            box-sizing: border-box;
        }
        .dropdown-item {
            padding: 8px 10px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .dropdown-item:hover {
            background-color: #f0f0f0;
        }
        /* Style for the searchable-dropdown container to allow absolute positioning of results */
        .searchable-dropdown {
            position: relative;
        }
        
        /* Character counter styles */
        .char-counter {
            font-size: 12px;
            color: #666;
            text-align: right;
            margin-top: 5px;
            margin-bottom: 10px;
        }
        
        .char-counter.warning {
            color: #ff9800;
            font-weight: bold;
        }
        
        .char-counter.danger {
            color: #f44336;
            font-weight: bold;
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
        
        /* Responsive design for smaller screens */
        @media (max-width: 768px) {
            .radio-checkbox-group {
                grid-template-columns: 1fr;
                gap: 8px;
            }
            
            .radio-checkbox-item {
                padding: 16px;
                min-height: 52px;
            }
            
            .scale-options {
                gap: 4px;
            }
            
            .scale-option {
                min-width: 36px;
                padding: 6px;
            }
            
            .star-enhanced {
                font-size: 24px;
                padding: 6px;
            }
            
            .coordinates-container {
                grid-template-columns: 1fr;
                gap: 8px;
            }
        }
        
        @media (max-width: 480px) {
            .form-field-container {
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
            
            .scale-options {
                gap: 2px;
            }
            
            .scale-option {
                min-width: 32px;
                padding: 4px;
            }
            
            .star-enhanced {
                font-size: 20px;
                padding: 4px;
            }
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

        /* Improved styling for left sidebar controls */
        .card-body input[type="text"],
        .card-body textarea,
        .card-body input[type="color"],
        .card-body input[type="file"],
        .card-body select {
            width: 100% !important;
            padding: 10px 12px !important;
            font-size: 14px !important;
            font-weight: 500 !important;
            color: #495057 !important;
            background-color: #fff !important;
            border: 1px solid #ced4da !important;
            border-radius: 6px !important;
            margin-bottom: 12px !important;
            box-sizing: border-box;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .card-body input[type="text"]:focus,
        .card-body textarea:focus,
        .card-body select:focus {
            border-color: #80bdff !important;
            outline: 0 !important;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25) !important;
        }

        .card-body textarea {
            min-height: 80px !important;
            resize: vertical;
        }

        .card-body label {
            font-weight: 600 !important;
            color: #344767 !important;
            font-size: 14px !important;
            margin-bottom: 6px !important;
            display: block;
        }

        .card-body .checkbox-group label {
            font-weight: 500 !important;
            font-size: 14px !important;
            display: flex !important;
            align-items: center;
        }

        .card-body .checkbox-group input[type="checkbox"] {
            margin-right: 8px !important;
            width: auto !important;
            margin-bottom: 0 !important;
        }

        .card-body .char-counter {
            font-size: 12px !important;
            color: #6c757d !important;
            margin-top: -8px !important;
            margin-bottom: 12px !important;
        }

        .card-body .setting-group {
            margin-bottom: 20px !important;
        }

        .card-body .filter-group {
            margin-bottom: 15px !important;
        }

        .card-body .accordion-header {
            background-color: #f8f9fa !important;
            border: 1px solid #dee2e6 !important;
            color: #495057 !important;
            font-weight: 600 !important;
            padding: 12px 15px !important;
            font-size: 14px !important;
        }

        .card-body .accordion-content {
            border: 1px solid #dee2e6 !important;
            border-top: none !important;
            padding: 15px !important;
            background-color: #fff !important;
        }

        /* Prevent horizontal scrolling and ensure static layout */
        body {
            overflow-x: hidden !important;
        }
        
        .container-fluid {
            overflow-x: hidden !important;
            max-width: 100% !important;
        }
        
        .row {
            margin-left: 0 !important;
            margin-right: 0 !important;
            max-width: 100% !important;
        }
        
        .col-lg-5, .col-lg-7 {
            padding-left: 15px !important;
            padding-right: 15px !important;
            max-width: 100% !important;
        }
        
        .card {
            max-width: 100% !important;
            word-wrap: break-word !important;
        }
        
        .card-body {
            overflow-x: hidden !important;
        }
        
        /* Ensure form content doesn't overflow */
        .preview-container {
            max-width: 100% !important;
            overflow-x: hidden !important;
        }
        
        /* Handle wide content gracefully */
        * {
            box-sizing: border-box !important;
        }
        
        .form-control, textarea, input, select {
            max-width: 100% !important;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100">
    <?php include 'components/aside.php'; ?>
    
    <main class="main-content position-relative border-radius-lg">
        <?php include 'components/navbar.php'; ?>
        
        <div class="container-fluid py-4">
            <div class="row">
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h4 class="mb-0"><i class="fas fa-cog me-2"></i>Preview Settings</h4>
                        </div>
                        <div class="card-body" style="max-height: 80vh; overflow-y: auto;">
                            <div class="accordion-item">
                                <button class="accordion-header">Branding & Appearance <i class="fas fa-chevron-down"></i></button>
                                <div class="accordion-content">
                                    <div class="setting-group">
                                        <label for="logo-upload">Upload Logo:</label>
                                        <input type="file" id="logo-upload" accept="image/*">
                                        <div class="checkbox-group">
                                            <label>
                                                <input type="checkbox" id="toggle-logo" <?php echo $surveySettings['show_logo'] ? 'checked' : ''; ?>> Show Logo
                                            </label>
                                        </div>
                                    </div>

                                    <div class="setting-group">
                                        <h4>Flag Bar Colors:</h4>
                                        <label for="flag-black-color-picker">First Strip Color:</label>
                                        <input type="color" id="flag-black-color-picker" value="#000000">
                                        <label for="flag-yellow-color-picker">Second Strip Color:</label>
                                        <input type="color" id="flag-yellow-color-picker" value="#FCD116">
                                        <label for="flag-red-color-picker">Third Strip Color:</label>
                                        <input type="color" id="flag-red-color-picker" value="#D21034">
                                        <div class="checkbox-group">
                                            <label>
                                               <input type="checkbox" id="toggle-flag-bar" <?php echo $surveySettings['show_flag_bar'] ? 'checked' : ''; ?>> Show Color Bar
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="accordion-item">
                                <button class="accordion-header">Survey Content <i class="fas fa-chevron-down"></i></button>
                                <div class="accordion-content">
                                    <div class="setting-group">
                                        <label for="edit-title">Survey Title:</label>
                                        <input type="text" id="edit-title" value="<?php echo htmlspecialchars($surveySettings['title_text'] ?? ''); ?>">
                                        <div class="checkbox-group">
                                            <label>
                                                <input type="checkbox" id="toggle-title" <?php echo $surveySettings['show_title'] ? 'checked' : ''; ?>> Show Title
                                            </label>
                                        </div>
                                    </div>

                                    <div class="setting-group">
                                        <label for="edit-subheading">Survey Subheading:</label>
                                        <textarea id="edit-subheading" rows="4" maxlength="1000"><?php echo htmlspecialchars($surveySettings['subheading_text'] ?? 'This tool is used to obtain clients\' feedback about their experience with the services and promote quality improvement, accountability, and transparency within the healthcare system.'); ?></textarea>
                                        <div class="char-counter">
                                            <span id="subheading-counter">0</span>/1000 characters
                                        </div>
                                        <div class="checkbox-group">
                                            <label>
                                                <input type="checkbox" id="toggle-subheading" <?php echo $surveySettings['show_subheading'] ? 'checked' : ''; ?>> Show Subheading
                                            </label>
                                        </div>
                                    </div>

                                    <div class="setting-group" id="rating-instructions-control-group">
                                        <label for="edit-rating-instruction-1">Rating Instruction 1:</label>
                                        <textarea id="edit-rating-instruction-1" rows="2" maxlength="500"><?php echo htmlspecialchars($translations['rating_instruction'] ?? '1. Please rate each of the following parameters according to your experience today on a scale of 1 to 4.'); ?></textarea>
                                        <div class="char-counter">
                                            <span id="rating1-counter">0</span>/500 characters
                                        </div>
                                        <label for="edit-rating-instruction-2">Rating Instruction 2:</label>
                                        <textarea id="edit-rating-instruction-2" rows="2" maxlength="500"><?php echo htmlspecialchars($translations['rating_scale'] ?? 'where \'0\' means Poor, \'1\' Fair, \'2\' Good and \'3\' Excellent'); ?></textarea>
                                        <div class="char-counter">
                                            <span id="rating2-counter">0</span>/500 characters
                                        </div>
                                        <div class="checkbox-group">
                                            <label>
                                                <input type="checkbox" id="toggle-rating-instructions" <?php echo $surveySettings['show_rating_instructions'] ? 'checked' : ''; ?>> Show Rating Instructions
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="accordion-item">
                                <button class="accordion-header">Form Sections Visibility <i class="fas fa-chevron-down"></i></button>
                                <div class="accordion-content">
                                    <div class="setting-group" id="toggle-facility-section-group">
                                        <div class="checkbox-group">
                                            <label>
                                                <input type="checkbox" id="toggle-facility-section" <?php echo $surveySettings['show_facility_section'] ? 'checked' : ''; ?>> Show Facility Section
                                            </label>
                                        </div>
                                        <div class="filter-group" id="instance-key-filter-group">
                                            <label for="control-instance-key-select">Filter by Instance:</label>
                                            <select id="control-instance-key-select">
                                                <option value="">All Instances</option>
                                                <?php foreach ($instanceKeys as $key): ?>
                                                    <option value="<?php echo htmlspecialchars($key); ?>" <?php echo (isset($surveySettings['selected_instance_key']) && $surveySettings['selected_instance_key'] == $key) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($key); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="filter-group" id="hierarchy-level-filter-group">
                                            <label for="control-hierarchy-level-select">Filter by Level:</label>
                                            <select id="control-hierarchy-level-select">
                                                <option value="">All Levels</option>
                                                <?php foreach ($hierarchyLevels as $levelInt => $levelName): ?>
                                                    <option value="<?php echo htmlspecialchars($levelInt); ?>" <?php echo (isset($surveySettings['selected_hierarchy_level']) && $surveySettings['selected_hierarchy_level'] == $levelInt) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($levelName); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="setting-group" id="toggle-submit-button-group">
                                        <div class="checkbox-group">
                                            <label>
                                                <input type="checkbox" id="toggle-submit-button" <?php echo $surveySettings['show_submit_button'] ? 'checked' : ''; ?>> Show Submit Button
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="accordion-item">
                                <button class="accordion-header">Share Page Settings <i class="fas fa-chevron-down"></i></button>
                                <div class="accordion-content">
                                    <div class="setting-group">
                                        <label for="edit-logo-url">Logo URL (for Share Page):</label>
                                        <input type="text" id="edit-logo-url" value="<?php echo htmlspecialchars($surveySettings['logo_path'] ?? 'asets/asets/img/loog.jpg'); ?>" readonly>
                                        <div class="checkbox-group">
                                            <label>
                                               <input type="checkbox" id="toggle-logo-url" <?php echo $surveySettings['show_logo'] ? 'checked' : ''; ?>> Show Logo on Share Page
                                            </label>
                                        </div>
                                    </div>
                                    <div class="setting-group">
                                        <label for="edit-republic-title-share">Republic Title (Share Page):</label>
                                        <input type="text" id="edit-republic-title-share" value="<?php echo htmlspecialchars($surveySettings['republic_title_text'] ?? 'THE REPUBLIC OF UGANDA'); ?>">
                                        <div class="checkbox-group">
                                            <label>
                                                <input type="checkbox" id="toggle-republic-title-share" <?php echo $surveySettings['show_republic_title_share'] ? 'checked' : ''; ?>> Show Republic Title
                                            </label>
                                        </div>
                                    </div>
                                    <div class="setting-group">
                                        <label for="edit-ministry-subtitle-share">Ministry Subtitle (Share Page):</label>
                                        <input type="text" id="edit-ministry-subtitle-share" value="<?php echo htmlspecialchars($surveySettings['ministry_subtitle_text'] ?? 'MINISTRY OF HEALTH'); ?>">
                                        <div class="checkbox-group">
                                            <label>
                                                <input type="checkbox" id="toggle-ministry-subtitle-share" <?php echo $surveySettings['show_ministry_subtitle_share'] ? 'checked' : ''; ?>> Show Ministry Subtitle
                                            </label>
                                        </div>
                                    </div>
                                    <div class="setting-group">
                                        <label for="edit-qr-instructions-share">QR Instructions Text (Share Page):</label>
                                        <textarea id="edit-qr-instructions-share" rows="3" maxlength="500"><?php echo htmlspecialchars($surveySettings['qr_instructions_text'] ?? ''); ?></textarea>
                                        <div class="char-counter">
                                            <span id="qr-counter">0</span>/500 characters
                                        </div>
                                        <div class="checkbox-group">
                                            <label>
                                                <input type="checkbox" id="toggle-qr-instructions-share" <?php echo $surveySettings['show_qr_instructions_share'] ? 'checked' : ''; ?>> Show QR Instructions
                                            </label>
                                        </div>
                                    </div>
                                    <div class="setting-group">
                                        <label for="edit-footer-note-share">Footer Note Text (Share Page):</label>
                                        <textarea id="edit-footer-note-share" rows="2" maxlength="500"><?php echo htmlspecialchars($surveySettings['footer_note_text'] ?? 'Thank you for helping us improve our services.'); ?></textarea>
                                        <div class="char-counter">
                                            <span id="footer-counter">0</span>/500 characters
                                        </div>
                                        <div class="checkbox-group">
                                            <label>
                                                <input type="checkbox" id="toggle-footer-note-share" <?php echo $surveySettings['show_footer_note_share'] ? 'checked' : ''; ?>> Show Footer Note
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="accordion-item">
                                <button class="accordion-header">Question Numbering <i class="fas fa-chevron-down"></i></button>
                                <div class="accordion-content">
                                    <div class="setting-group">
                                        <div class="checkbox-group">
                                            <label>
                                                <input type="checkbox" id="toggle-numbering" <?php echo ($surveySettings['show_numbering'] ?? true) ? 'checked' : ''; ?>> Show Question Numbers
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="setting-group">
                                        <label for="numbering-style">Numbering Style:</label>
                                        <select id="numbering-style">
                                            <option value="numeric" <?php echo ($surveySettings['numbering_style'] ?? 'numeric') == 'numeric' ? 'selected' : ''; ?>>Numeric (1, 2, 3...)</option>
                                            <option value="alphabetic_lower" <?php echo ($surveySettings['numbering_style'] ?? 'numeric') == 'alphabetic_lower' ? 'selected' : ''; ?>>Lowercase Letters (a, b, c...)</option>
                                            <option value="alphabetic_upper" <?php echo ($surveySettings['numbering_style'] ?? 'numeric') == 'alphabetic_upper' ? 'selected' : ''; ?>>Uppercase Letters (A, B, C...)</option>
                                            <option value="roman_lower" <?php echo ($surveySettings['numbering_style'] ?? 'numeric') == 'roman_lower' ? 'selected' : ''; ?>>Lowercase Roman (i, ii, iii...)</option>
                                            <option value="roman_upper" <?php echo ($surveySettings['numbering_style'] ?? 'numeric') == 'roman_upper' ? 'selected' : ''; ?>>Uppercase Roman (I, II, III...)</option>
                                            <option value="none" <?php echo ($surveySettings['numbering_style'] ?? 'numeric') == 'none' ? 'selected' : ''; ?>>No Numbering</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-3">
                                <div class="col-6">
                                    <button onclick="window.savePreviewSettings()" class="btn btn-success w-100 btn-sm">
                                        <i class="fas fa-save me-1"></i>Save Settings
                                    </button>
                                </div>
                                <div class="col-6">
                                    <button onclick="window.resetPreviewSettings()" class="btn btn-secondary w-100 btn-sm">
                                        <i class="fas fa-undo me-1"></i>Reset
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="card mt-3">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-tools me-2"></i>Actions</h6>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="../../s/<?= $surveyId ?>" class="btn btn-primary" target="_blank">
                                    <i class="fas fa-external-link-alt me-2"></i>Open Form
                                </a>
                                <button onclick="copyShareLink()" class="btn btn-info">
                                    <i class="fas fa-link me-2"></i>Copy Share Link
                                </button>
                                <a href="../../share/s/<?= $surveyId ?>" class="btn btn-success">
                                    <i class="fas fa-share me-2"></i>Share Page
                                </a>
                                <hr class="my-2">
                                <a href="survey.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-2"></i>Back to Surveys
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="mb-0"><?php echo htmlspecialchars($defaultSurveyTitle); ?> - Preview</h4>
                        </div>
                        <div class="card-body">
                            <div class="container preview-container" id="form-content">
            <div class="header-section" id="logo-section">
                <div class="logo-container">
                  <img id="moh-logo" src="<?php echo htmlspecialchars($surveySettings['logo_path'] ?? ''); ?>" alt="Ministry of Health Logo">
                </div>
             <div class="title hidden-element" id="republic-title"><?php echo htmlspecialchars($surveySettings['republic_title_text'] ?? ''); ?></div>
            <div class="subtitle hidden-element" id="ministry-subtitle"><?php echo htmlspecialchars($surveySettings['ministry_subtitle_text'] ?? ''); ?></div>
            </div>

            <div class="flag-bar" id="flag-bar">
                <div class="flag-black" id="flag-black-color" style="background-color: <?php echo htmlspecialchars($surveySettings['flag_black_color'] ?? '#000000'); ?>;"></div>
                <div class="flag-yellow" id="flag-yellow-color" style="background-color: <?php echo htmlspecialchars($surveySettings['flag_yellow_color'] ?? '#FCD116'); ?>;"></div>
                <div class="flag-red" id="flag-red-color" style="background-color: <?php echo htmlspecialchars($surveySettings['flag_red_color'] ?? '#D21034'); ?>;"></div>
            </div>

           <h2 id="survey-title" data-translate="title"><?php echo htmlspecialchars($surveySettings['title_text'] ?? ''); ?></h2>
            <h3 id="survey-subtitle" data-translate="client_satisfaction_tool"><?php echo $translations['client_satisfaction_tool'] ?? 'CLIENT SATISFACTION FEEDBACK TOOL'; ?></h3>
               <p class="subheading" id="survey-subheading" data-translate="subheading">
                    <?php echo htmlspecialchars($surveySettings['subheading_text'] ?? ''); ?>
                </p>
            <div class="facility-section" id="facility-section">
                <div class="form-group">
                    <label for="facility-search">Locations:</label>
                    <div class="searchable-dropdown">
                        <input type="text" id="facility-search" placeholder="Type to search locations..." autocomplete="off" required>
                        <div class="dropdown-results" id="facility-results"></div>
                        <input type="hidden" id="facility_id" name="facility_id">
                    </div>
                </div>

                <div class="hierarchy-path" id="hierarchy-path">
                    <div class="path-display" id="path-display"></div>
                </div>

                <input type="hidden" id="hierarchy_data" name="hierarchy_data">
            </div>

            <?php if ($survey['type'] === 'local'): ?>
            <p id="rating-instruction-1" data-translate="rating_instruction"><?php echo htmlspecialchars($surveySettings['rating_instruction1_text'] ?? ''); ?></p>
          <p id="rating-instruction-2" data-translate="rating_scale" style="color: red; font-size: 14px; font-style: italic."><?php echo htmlspecialchars($surveySettings['rating_instruction2_text'] ?? ''); ?></p>

            <?php endif; ?>

            <?php 
            // Use the new survey renderer with skip logic support
            echo renderSurveyForm($surveyId, $pdo, [
                'form_id' => 'survey_preview_form',
                'form_class' => 'survey-form preview-form',
                'include_skip_logic' => true,
                'show_required_indicator' => true
            ]);
            ?>

            <button type="submit" id="submit-button-preview">Submit</button>
        </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
    </main>

<script>
    // --- 1. Global Functions (must be outside of DOMContentLoaded) ---
    // These functions are now available immediately for the onclick attributes in the HTML.

    const surveyType = "<?php echo htmlspecialchars($survey['type']); ?>";
    const surveyId = "<?php echo intval($surveyId); ?>";
    const hierarchyLevelMap = <?php echo json_encode($hierarchyLevels); ?>;

    // Helper function for toast notifications
    function showToast(message, type = 'success') {
        // Use the new toast notification style
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

    // Function to copy share link to clipboard
    function copyShareLink() {
        const scheme = window.location.protocol;
        const host = window.location.host;
        const shareUrl = `${scheme}//${host}/share/s/${surveyId}`;
        
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
    
    // Function to handle saving settings
    window.savePreviewSettings = async function() {
        console.log('savePreviewSettings called');

        // Check if required elements exist
        const logoImg = document.getElementById('moh-logo');
        if (!logoImg) {
            console.error('Required elements not found');
            showToast('Error: Page not fully loaded', 'error');
            return;
        }

        // Collect settings (your existing logic)
        const toggleLogo = document.getElementById('toggle-logo');
        const flagBlackColorPicker = document.getElementById('flag-black-color-picker');
        const flagYellowColorPicker = document.getElementById('flag-yellow-color-picker');
        const flagRedColorPicker = document.getElementById('flag-red-color-picker');
        const toggleFlagBar = document.getElementById('toggle-flag-bar');
        const editTitle = document.getElementById('edit-title');
        const toggleTitle = document.getElementById('toggle-title');
        const editSubheading = document.getElementById('edit-subheading');
        const toggleSubheading = document.getElementById('toggle-subheading');
        const toggleSubmitButton = document.getElementById('toggle-submit-button');
        const editRatingInstruction1 = document.getElementById('edit-rating-instruction-1');
        const editRatingInstruction2 = document.getElementById('edit-rating-instruction-2');
        const toggleRatingInstructions = document.getElementById('toggle-rating-instructions');
        const toggleFacilitySection = document.getElementById('toggle-facility-section');
        const editRepublicTitleShare = document.getElementById('edit-republic-title-share');
        const toggleRepublicTitleShare = document.getElementById('toggle-republic-title-share');
        const editMinistrySubtitleShare = document.getElementById('edit-ministry-subtitle-share');
        const toggleMinistrySubtitleShare = document.getElementById('toggle-ministry-subtitle-share');
        const editQrInstructionsShare = document.getElementById('edit-qr-instructions-share');
        const toggleQrInstructionsShare = document.getElementById('toggle-qr-instructions-share');
        const editFooterNoteShare = document.getElementById('edit-footer-note-share');
        const toggleFooterNoteShare = document.getElementById('toggle-footer-note-share');
        const controlInstanceKeySelect = document.getElementById('control-instance-key-select');
        const controlHierarchyLevelSelect = document.getElementById('control-hierarchy-level-select');
        const toggleNumbering = document.getElementById('toggle-numbering');
        const numberingStyle = document.getElementById('numbering-style');

        const settings = {
            surveyId: surveyId,
            logoSrc: logoImg.src,
            showLogo: toggleLogo ? toggleLogo.checked : false,
            flagBlackColor: flagBlackColorPicker ? flagBlackColorPicker.value : '#000000',
            flagYellowColor: flagYellowColorPicker ? flagYellowColorPicker.value : '#FCD116',
            flagRedColor: flagRedColorPicker ? flagRedColorPicker.value : '#D21034',
            showFlagBar: toggleFlagBar ? toggleFlagBar.checked : false,
            titleText: editTitle ? editTitle.value : '',
            showTitle: toggleTitle ? toggleTitle.checked : false,
            subheadingText: editSubheading ? editSubheading.value : '',
            showSubheading: toggleSubheading ? toggleSubheading.checked : false,
            showSubmitButton: toggleSubmitButton ? toggleSubmitButton.checked : false,
            ratingInstruction1Text: editRatingInstruction1 ? editRatingInstruction1.value : '',
            ratingInstruction2Text: editRatingInstruction2 ? editRatingInstruction2.value : '',
            showRatingInstructions: toggleRatingInstructions ? toggleRatingInstructions.checked : false,
            showFacilitySection: surveyType === 'dhis2' || (toggleFacilitySection ? toggleFacilitySection.checked : false),
            republicTitleText: editRepublicTitleShare ? editRepublicTitleShare.value : '',
            showRepublicTitleShare: toggleRepublicTitleShare ? toggleRepublicTitleShare.checked : false,
            ministrySubtitleText: editMinistrySubtitleShare ? editMinistrySubtitleShare.value : '',
            showMinistrySubtitleShare: toggleMinistrySubtitleShare ? toggleMinistrySubtitleShare.checked : false,
            qrInstructionsText: editQrInstructionsShare ? editQrInstructionsShare.value : '',
            showQrInstructionsShare: toggleQrInstructionsShare ? toggleQrInstructionsShare.checked : false,
            footerNoteText: editFooterNoteShare ? editFooterNoteShare.value : '',
            showFooterNoteShare: toggleFooterNoteShare ? toggleFooterNoteShare.checked : false,
            selectedInstanceKey: controlInstanceKeySelect ? controlInstanceKeySelect.value : null,
            selectedHierarchyLevel: controlHierarchyLevelSelect ? (controlHierarchyLevelSelect.value === '' ? null : parseInt(controlHierarchyLevelSelect.value, 10)) : null,
            showNumbering: toggleNumbering ? toggleNumbering.checked : true,
            numberingStyle: numberingStyle ? numberingStyle.value : 'numeric',
        };

        try {
            console.log('Attempting to save settings:', settings);
            const response = await fetch('save_survey_settings.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(settings)
            });

            console.log('Response status:', response.status);

            if (!response.ok) {
                const errorText = await response.text();
                console.error('Server response error:', errorText);
                throw new Error(`Server error (${response.status}): ${errorText}`);
            }

            const data = await response.json();
            console.log('Save response:', data);

            showToast(data.message || 'Settings saved successfully', 'success');
        } catch (error) {
            console.error('Error saving settings:', error);
            showToast(error.message || 'An unexpected error occurred while saving.', 'error');
            throw error; // Re-throw the error to be caught by the caller, e.g., the share button
        }
    };
    
    // Function to handle resetting settings
    window.resetPreviewSettings = async function() {
        if (!confirm('Are you sure you want to reset all preview settings to their default values? This cannot be undone.')) {
            return;
        }

        try {
            const response = await fetch('reset_survey_settings.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: `survey_id=${encodeURIComponent(surveyId)}`
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Failed to reset settings due to a server error.');
            }

            showToast(data.message, 'success');
            setTimeout(() => {
                location.reload();
            }, 1000);
        } catch (error) {
            console.error('Error resetting settings:', error);
            showToast(error.message || 'An unexpected error occurred while resetting.', 'error');
        }
    };
    
    // Function for testing the save process
    window.testSaveFunction = async function() {
        console.log('Test function called');
        
        const testData = {
            surveyId: surveyId,
            titleText: 'Test Title',
            showTitle: true,
            showNumbering: true,
            numberingStyle: 'numeric'
        };
        
        try {
            console.log('Sending test data:', testData);
            const response = await fetch('save_survey_settings.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(testData)
            });
            
            const responseText = await response.text();
            console.log('Test response text:', responseText);
            
            try {
                const responseData = JSON.parse(responseText);
                showToast('Test: ' + responseData.message, responseData.status === 'success' ? 'success' : 'error');
            } catch (parseError) {
                console.error('Failed to parse test response as JSON:', parseError);
                showToast('Test failed: Invalid JSON response', 'error');
            }
        } catch (error) {
            console.error('Test function error:', error);
            showToast('Test failed: ' + error.message, 'error');
        }
    };


    document.addEventListener('DOMContentLoaded', function() {
        // --- 2. DOM Element References (rest of your code) ---
        const logoImg = document.getElementById('moh-logo');
        const logoUpload = document.getElementById('logo-upload');
        const toggleLogo = document.getElementById('toggle-logo');
        const logoSection = document.getElementById('logo-section');
        const flagBlackColorPicker = document.getElementById('flag-black-color-picker');
        const flagYellowColorPicker = document.getElementById('flag-yellow-color-picker');
        const flagRedColorPicker = document.getElementById('flag-red-color-picker');
        const flagBlackElement = document.getElementById('flag-black-color');
        const flagYellowElement = document.getElementById('flag-yellow-color');
        const flagRedElement = document.getElementById('flag-red-color');
        const toggleFlagBar = document.getElementById('toggle-flag-bar');
        const flagBarElement = document.getElementById('flag-bar');
        const editTitle = document.getElementById('edit-title');
        const surveyTitle = document.getElementById('survey-title');
        const toggleTitle = document.getElementById('toggle-title');
        const editSubheading = document.getElementById('edit-subheading');
        const surveySubheading = document.getElementById('survey-subheading');
        const toggleSubheading = document.getElementById('toggle-subheading');
        const editRatingInstruction1 = document.getElementById('edit-rating-instruction-1');
        const ratingInstruction1 = document.getElementById('rating-instruction-1');
        const editRatingInstruction2 = document.getElementById('edit-rating-instruction-2');
        const ratingInstruction2 = document.getElementById('rating-instruction-2');
        const toggleRatingInstructions = document.getElementById('toggle-rating-instructions');
        const ratingInstructionsControlGroup = document.getElementById('rating-instructions-control-group');
        const formSectionsAccordionItem = document.getElementById('form-sections-accordion-item');
        const toggleFacilitySection = document.getElementById('toggle-facility-section');
        const facilitySection = document.getElementById('facility-section');
        const instanceKeyFilterGroup = document.getElementById('instance-key-filter-group');
        const hierarchyLevelFilterGroup = document.getElementById('hierarchy-level-filter-group');
        const controlInstanceKeySelect = document.getElementById('control-instance-key-select');
        const controlHierarchyLevelSelect = document.getElementById('control-hierarchy-level-select');
        const facilitySearchInput = document.getElementById('facility-search');
        const facilityResultsDiv = document.getElementById('facility-results');
        const pathDisplay = document.getElementById('path-display');
        const facilityIdInput = document.getElementById('facility_id');
        const hierarchyDataInput = document.getElementById('hierarchy_data');
        const toggleSubmitButton = document.getElementById('toggle-submit-button');
        const submitButtonPreview = document.getElementById('submit-button-preview');
        const logoUrlInput = document.getElementById('edit-logo-url');
        const toggleLogoUrl = document.getElementById('toggle-logo-url');
        const republicTitleElement = document.getElementById('republic-title');
        const editRepublicTitleShare = document.getElementById('edit-republic-title-share');
        const toggleRepublicTitleShare = document.getElementById('toggle-republic-title-share');
        const ministrySubtitleElement = document.getElementById('ministry-subtitle');
        const editMinistrySubtitleShare = document.getElementById('edit-ministry-subtitle-share');
        const toggleMinistrySubtitleShare = document.getElementById('toggle-ministry-subtitle-share');
        const editQrInstructionsShare = document.getElementById('edit-qr-instructions-share');
        const toggleQrInstructionsShare = document.getElementById('toggle-qr-instructions-share');
        const editFooterNoteShare = document.getElementById('edit-footer-note-share');
        const toggleFooterNoteShare = document.getElementById('toggle-footer-note-share');
        const toggleNumbering = document.getElementById('toggle-numbering');
        const numberingStyle = document.getElementById('numbering-style');
        
        // --- 3. Helper Functions (that rely on DOM elements) ---

        function updateCharacterCounter(textareaId, counterId, maxLength) {
            const textarea = document.getElementById(textareaId);
            const counter = document.getElementById(counterId);
            const counterDiv = counter.parentElement;
            
            if (textarea && counter) {
                const currentLength = textarea.value.length;
                counter.textContent = currentLength;
                
                counterDiv.classList.remove('warning', 'danger');
                if (currentLength > maxLength * 0.9) {
                    counterDiv.classList.add('danger');
                } else if (currentLength > maxLength * 0.8) {
                    counterDiv.classList.add('warning');
                }
            }
        }
        
        function setupCharacterCounters() {
            const counters = [
                { textarea: 'edit-subheading', counter: 'subheading-counter', maxLength: 1000 },
                { textarea: 'edit-rating-instruction-1', counter: 'rating1-counter', maxLength: 500 },
                { textarea: 'edit-rating-instruction-2', counter: 'rating2-counter', maxLength: 500 },
                { textarea: 'edit-qr-instructions-share', counter: 'qr-counter', maxLength: 500 },
                { textarea: 'edit-footer-note-share', counter: 'footer-counter', maxLength: 500 }
            ];
            
            counters.forEach(({ textarea, counter, maxLength }) => {
                const textareaElement = document.getElementById(textarea);
                if (textareaElement) {
                    updateCharacterCounter(textarea, counter, maxLength);
                    textareaElement.addEventListener('input', () => {
                        updateCharacterCounter(textarea, counter, maxLength);
                    });
                }
            });
        }
        
        function applyTypeSpecificControls() {
            if (surveyType === 'dhis2') {
                if (formSectionsAccordionItem) formSectionsAccordionItem.classList.remove('hidden-element');
                const submitButtonGroup = document.getElementById('toggle-submit-button-group');
                if (submitButtonGroup) submitButtonGroup.classList.add('hidden-element');
                if (facilitySection) facilitySection.classList.remove('hidden-element');
                if (instanceKeyFilterGroup) instanceKeyFilterGroup.style.display = 'block';
                if (hierarchyLevelFilterGroup) hierarchyLevelFilterGroup.style.display = 'block';
                if (ratingInstructionsControlGroup) ratingInstructionsControlGroup.classList.add('hidden-element');
                if (ratingInstruction1) ratingInstruction1.classList.add('hidden-element');
                if (ratingInstruction2) ratingInstruction2.classList.add('hidden-element');
            } else if (surveyType === 'local') {
                if (formSectionsAccordionItem) formSectionsAccordionItem.classList.remove('hidden-element');
                if (ratingInstructionsControlGroup) ratingInstructionsControlGroup.classList.remove('hidden-element');
                const submitButtonGroup = document.getElementById('toggle-submit-button-group');
                if (submitButtonGroup) submitButtonGroup.classList.remove('hidden-element');
                if (facilitySection) facilitySection.classList.remove('hidden-element');
                if (instanceKeyFilterGroup) instanceKeyFilterGroup.style.display = 'block';
                if (hierarchyLevelFilterGroup) hierarchyLevelFilterGroup.style.display = 'block';
            }
        }

        let currentFilteredLocations = [];

        async function updateLocationsBasedOnFilters() {
            const instanceKey = controlInstanceKeySelect.value;
            const hierarchyLevel = controlHierarchyLevelSelect.value;
            facilitySearchInput.value = '';
            facilityResultsDiv.innerHTML = '';
            facilityResultsDiv.style.display = 'none';
            facilityIdInput.value = '';
            pathDisplay.textContent = '';
            hierarchyDataInput.value = '';

            if (!instanceKey || !hierarchyLevel) {
                currentFilteredLocations = [];
                if (facilitySearchInput) facilitySearchInput.disabled = true;
                filterAndDisplaySearchResults('');
                return;
            }

            if (facilitySearchInput) facilitySearchInput.disabled = false;
            try {
                const params = new URLSearchParams();
                params.append('instance_key', instanceKey);
                params.append('hierarchylevel', hierarchyLevel);
                const response = await fetch(`get_locations.php?${params.toString()}`);
                if (!response.ok) {
                    const errorText = await response.text();
                    throw new Error(`Network response was not ok (${response.status}): ${errorText}`);
                }
                const responseData = await response.json();
                if (responseData.error) {
                    throw new Error(`Server Error: ${responseData.error}`);
                }
                currentFilteredLocations = responseData;
                filterAndDisplaySearchResults('');
            } catch (error) {
                console.error('Error fetching locations:', error);
                if (facilityResultsDiv) facilityResultsDiv.innerHTML = `<div style="padding: 8px; color: red;">${error.message || 'Error loading locations from server.'}</div>`;
                if (facilityResultsDiv) facilityResultsDiv.style.display = 'block';
                if (facilitySearchInput) facilitySearchInput.disabled = true;
            }
        }

        async function fetchLocationPath(locationId) {
            if (!locationId) return '';
            try {
                const response = await fetch(`get_location_path.php?id=${locationId}`);
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
                return '';
            }
        }

        function filterAndDisplaySearchResults(searchTerm) {
            if (facilityResultsDiv) facilityResultsDiv.innerHTML = '';
            if (facilityResultsDiv) facilityResultsDiv.style.display = 'block';

            if (facilitySearchInput && facilitySearchInput.disabled) {
                if (facilityResultsDiv) facilityResultsDiv.innerHTML = '<div style="padding: 8px; color: #888;">Select an Instance and Level to load locations.</div>';
                return;
            }

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
                    if (facilityResultsDiv) facilityResultsDiv.appendChild(div);
                });
            } else {
                if (facilityResultsDiv) {
                    if (searchTerm.length > 0) {
                        facilityResultsDiv.innerHTML = '<div style="padding: 8px; color: #888;">No matching locations found for your search.</div>';
                    } else {
                        facilityResultsDiv.innerHTML = '<div style="padding: 8px; color: #888;">No locations available for selected filters.</div>';
                    }
                }
            }
        }
        
        function updateQuestionNumberingPreview() {
            const toggleElement = document.getElementById('toggle-numbering');
            const styleElement = document.getElementById('numbering-style');
            if (!toggleElement || !styleElement) return;

            const showNumbering = toggleElement.checked;
            const numberingStyle = styleElement.value;
            const questionNumbers = document.querySelectorAll('.question-number');
            
            questionNumbers.forEach(function(numberSpan, index) {
                if (showNumbering && numberingStyle !== 'none') {
                    let displayNumber = '';
                    const questionIndex = index + 1;
                    switch (numberingStyle) {
                        case 'alphabetic_lower': displayNumber = String.fromCharCode(96 + questionIndex) + '.'; break;
                        case 'alphabetic_upper': displayNumber = String.fromCharCode(64 + questionIndex) + '.'; break;
                        case 'roman_lower': displayNumber = toRoman(questionIndex).toLowerCase() + '.'; break;
                        case 'roman_upper': displayNumber = toRoman(questionIndex) + '.'; break;
                        case 'numeric':
                        default: displayNumber = questionIndex + '.'; break;
                    }
                    numberSpan.textContent = displayNumber;
                    numberSpan.style.display = 'inline';
                } else {
                    numberSpan.style.display = 'none';
                }
            });
        }
        
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

        // --- 4. Event Listeners ---
        // All event listeners now safely inside DOMContentLoaded where the elements exist.

        if (logoUpload && logoImg && logoUrlInput) {
            logoUpload.addEventListener('change', function(event) {
                const file = event.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        logoImg.src = e.target.result;
                        logoUrlInput.value = e.target.result;
                    };
                    reader.readAsDataURL(file);
                }
            });
        }

        if (toggleLogo && logoSection && toggleLogoUrl) {
            toggleLogo.addEventListener('change', function() {
                logoSection.classList.toggle('hidden-element', !this.checked);
                toggleLogoUrl.checked = this.checked;
            });
            toggleLogoUrl.addEventListener('change', function() {
                toggleLogo.checked = this.checked;
                logoSection.classList.toggle('hidden-element', !this.checked);
            });
        }

        if (flagBlackColorPicker && flagBlackElement) flagBlackColorPicker.addEventListener('input', function() { flagBlackElement.style.backgroundColor = this.value; });
        if (flagYellowColorPicker && flagYellowElement) flagYellowColorPicker.addEventListener('input', function() { flagYellowElement.style.backgroundColor = this.value; });
        if (flagRedColorPicker && flagRedElement) flagRedColorPicker.addEventListener('input', function() { flagRedElement.style.backgroundColor = this.value; });
        if (toggleFlagBar && flagBarElement) toggleFlagBar.addEventListener('change', function() { flagBarElement.classList.toggle('hidden-element', !this.checked); });

        if (editTitle && surveyTitle && toggleTitle) {
            editTitle.addEventListener('input', function() { surveyTitle.textContent = this.value; });
            toggleTitle.addEventListener('change', function() { surveyTitle.classList.toggle('hidden-element', !this.checked); });
        }

        if (editSubheading && surveySubheading && toggleSubheading) {
            editSubheading.addEventListener('input', function() { surveySubheading.textContent = this.value; });
            toggleSubheading.addEventListener('change', function() { surveySubheading.classList.toggle('hidden-element', !this.checked); });
        }
        
        if (surveyType === 'local' && editRatingInstruction1 && ratingInstruction1 && editRatingInstruction2 && ratingInstruction2 && toggleRatingInstructions) {
            editRatingInstruction1.addEventListener('input', function() { ratingInstruction1.textContent = this.value; });
            editRatingInstruction2.addEventListener('input', function() { ratingInstruction2.textContent = this.value; });
            toggleRatingInstructions.addEventListener('change', function() {
                ratingInstruction1.classList.toggle('hidden-element', !this.checked);
                ratingInstruction2.classList.toggle('hidden-element', !this.checked);
            });
        }

        if ((surveyType === 'local' || surveyType === 'dhis2') && toggleFacilitySection && facilitySection && instanceKeyFilterGroup && hierarchyLevelFilterGroup) {
            toggleFacilitySection.addEventListener('change', function() {
                const isChecked = this.checked;
                facilitySection.classList.toggle('hidden-element', !isChecked);
                instanceKeyFilterGroup.style.display = isChecked ? 'block' : 'none';
                hierarchyLevelFilterGroup.style.display = isChecked ? 'block' : 'none';
                if (isChecked) {
                    updateLocationsBasedOnFilters();
                } else {
                    facilitySearchInput.value = '';
                    facilityResultsDiv.innerHTML = '';
                    facilityResultsDiv.style.display = 'none';
                    facilityIdInput.value = '';
                    pathDisplay.textContent = '';
                    hierarchyDataInput.value = '';
                    facilitySearchInput.disabled = true;
                }
            });

            if (controlInstanceKeySelect) controlInstanceKeySelect.addEventListener('change', updateLocationsBasedOnFilters);
            if (controlHierarchyLevelSelect) controlHierarchyLevelSelect.addEventListener('change', updateLocationsBasedOnFilters);
            if (facilitySearchInput) facilitySearchInput.addEventListener('input', function() { filterAndDisplaySearchResults(this.value); });
            
            document.addEventListener('click', function(event) {
                if (facilitySearchInput && !facilitySearchInput.contains(event.target) && facilityResultsDiv && !facilityResultsDiv.contains(event.target)) {
                    facilityResultsDiv.style.display = 'none';
                }
            });

            if (facilitySearchInput) facilitySearchInput.addEventListener('focus', function() {
                filterAndDisplaySearchResults(this.value);
            });

            if (facilityResultsDiv) facilityResultsDiv.addEventListener('click', async function(event) {
                const target = event.target;
                if (target.classList.contains('dropdown-item')) {
                    const locationId = target.dataset.id;
                    facilitySearchInput.value = target.textContent;
                    facilityIdInput.value = locationId;
                    const humanReadablePath = await fetchLocationPath(locationId);
                    pathDisplay.textContent = humanReadablePath;
                    hierarchyDataInput.value = humanReadablePath;
                    facilityResultsDiv.style.display = 'none';
                }
            });
        }

        if (toggleSubmitButton && submitButtonPreview) toggleSubmitButton.addEventListener('change', function() { submitButtonPreview.classList.toggle('hidden-element', !this.checked); });
        if (editRepublicTitleShare && republicTitleElement && toggleRepublicTitleShare) {
            editRepublicTitleShare.addEventListener('input', function() { republicTitleElement.textContent = this.value; });
            toggleRepublicTitleShare.addEventListener('change', function() { republicTitleElement.classList.toggle('hidden-element', !this.checked); });
        }
        if (editMinistrySubtitleShare && ministrySubtitleElement && toggleMinistrySubtitleShare) {
            editMinistrySubtitleShare.addEventListener('input', function() { ministrySubtitleElement.textContent = this.value; });
            toggleMinistrySubtitleShare.addEventListener('change', function() { ministrySubtitleElement.classList.toggle('hidden-element', !this.checked); });
        }
        
        const shareBtn = document.getElementById('share-btn');
        if (shareBtn) {
            shareBtn.addEventListener('click', async function() {
                try {
                    console.log('Share button clicked');
                    await window.savePreviewSettings();
                    console.log('Redirecting to share page');
                    window.location.href = `/share/s/${surveyId}`;
                } catch (error) {
                    console.error('Share button error:', error);
                    showToast('Error occurred while sharing: ' + error.message, 'error');
                }
            });
        } else {
            console.error('Share button not found in DOM');
        }

        if (toggleNumbering && numberingStyle) {
            toggleNumbering.addEventListener('change', updateQuestionNumberingPreview);
            numberingStyle.addEventListener('change', updateQuestionNumberingPreview);
        }

        // --- 5. Initial Setup Call ---
        // Moved the loadPreviewSettings call to the end of DOMContentLoaded
        window.loadPreviewSettings = function() {
            if (!logoImg) {
                console.error("Critical DOM elements not found on page load. Load Preview Settings aborted.");
                return;
            }
            
            logoImg.src = <?php echo json_encode($surveySettings['logo_path'] ?? ''); ?>;
            toggleLogo.checked = <?php echo $surveySettings['show_logo'] ? 'true' : 'false'; ?>;
            logoSection.classList.toggle('hidden-element', !toggleLogo.checked);

            flagBlackColorPicker.value = <?php echo json_encode($surveySettings['flag_black_color'] ?? '#000000'); ?>;
            flagYellowColorPicker.value = <?php echo json_encode($surveySettings['flag_yellow_color'] ?? '#FCD116'); ?>;
            flagRedColorPicker.value = <?php echo json_encode($surveySettings['flag_red_color'] ?? '#D21034'); ?>;
            flagBlackElement.style.backgroundColor = flagBlackColorPicker.value;
            flagYellowElement.style.backgroundColor = flagYellowColorPicker.value;
            flagRedElement.style.backgroundColor = flagRedColorPicker.value;
            toggleFlagBar.checked = <?php echo $surveySettings['show_flag_bar'] ? 'true' : 'false'; ?>;
            flagBarElement.classList.toggle('hidden-element', !toggleFlagBar.checked);

            editTitle.value = <?php echo json_encode($surveySettings['title_text'] ?? ''); ?>;
            surveyTitle.textContent = editTitle.value;
            toggleTitle.checked = <?php echo $surveySettings['show_title'] ? 'true' : 'false'; ?>;
            surveyTitle.classList.toggle('hidden-element', !toggleTitle.checked);

            editSubheading.value = <?php echo json_encode($surveySettings['subheading_text'] ?? ''); ?>;
            surveySubheading.textContent = editSubheading.value;
            toggleSubheading.checked = <?php echo $surveySettings['show_subheading'] ? 'true' : 'false'; ?>;
            surveySubheading.classList.toggle('hidden-element', !toggleSubheading.checked);
            
            if (surveyType === 'local') {
                if (editRatingInstruction1) editRatingInstruction1.value = <?php echo json_encode($surveySettings['rating_instruction1_text'] ?? ''); ?>;
                if (ratingInstruction1) ratingInstruction1.textContent = editRatingInstruction1.value;
                if (editRatingInstruction2) editRatingInstruction2.value = <?php echo json_encode($surveySettings['rating_instruction2_text'] ?? ''); ?>;
                if (ratingInstruction2) ratingInstruction2.textContent = editRatingInstruction2.value;
                if (toggleRatingInstructions) toggleRatingInstructions.checked = <?php echo $surveySettings['show_rating_instructions'] ? 'true' : 'false'; ?>;
                if (ratingInstruction1) ratingInstruction1.classList.toggle('hidden-element', !toggleRatingInstructions.checked);
                if (ratingInstruction2) ratingInstruction2.classList.toggle('hidden-element', !toggleRatingInstructions.checked);

                if (toggleFacilitySection) toggleFacilitySection.checked = <?php echo $surveySettings['show_facility_section'] ? 'true' : 'false'; ?>;
                if (facilitySection) facilitySection.classList.toggle('hidden-element', !toggleFacilitySection.checked);

                if (controlInstanceKeySelect) controlInstanceKeySelect.value = <?php echo json_encode($surveySettings['selected_instance_key'] ?? ''); ?>;
                if (controlHierarchyLevelSelect) controlHierarchyLevelSelect.value = <?php echo json_encode($surveySettings['selected_hierarchy_level'] ?? ''); ?>;

                if (toggleFacilitySection.checked) {
                    if (instanceKeyFilterGroup) instanceKeyFilterGroup.style.display = 'block';
                    if (hierarchyLevelFilterGroup) hierarchyLevelFilterGroup.style.display = 'block';
                    updateLocationsBasedOnFilters();
                } else {
                    if (instanceKeyFilterGroup) instanceKeyFilterGroup.style.display = 'none';
                    if (hierarchyLevelFilterGroup) hierarchyLevelFilterGroup.style.display = 'none';
                    if (facilitySearchInput) facilitySearchInput.disabled = true;
                }
            }

            if (toggleSubmitButton && submitButtonPreview) {
                toggleSubmitButton.checked = <?php echo $surveySettings['show_submit_button'] ? 'true' : 'false'; ?>;
                submitButtonPreview.classList.toggle('hidden-element', !toggleSubmitButton.checked);
            }

            if (editRepublicTitleShare && republicTitleElement && toggleRepublicTitleShare) {
                editRepublicTitleShare.value = <?php echo json_encode($surveySettings['republic_title_text'] ?? ''); ?>;
                republicTitleElement.textContent = editRepublicTitleShare.value;
                toggleRepublicTitleShare.checked = <?php echo $surveySettings['show_republic_title_share'] ? 'true' : 'false'; ?>;
                republicTitleElement.classList.toggle('hidden-element', !toggleRepublicTitleShare.checked);
            }

            if (editMinistrySubtitleShare && ministrySubtitleElement && toggleMinistrySubtitleShare) {
                editMinistrySubtitleShare.value = <?php echo json_encode($surveySettings['ministry_subtitle_text'] ?? ''); ?>;
                ministrySubtitleElement.textContent = editMinistrySubtitleShare.value;
                toggleMinistrySubtitleShare.checked = <?php echo $surveySettings['show_ministry_subtitle_share'] ? 'true' : 'false'; ?>;
                ministrySubtitleElement.classList.toggle('hidden-element', !toggleMinistrySubtitleShare.checked);
            }
            
            if (editQrInstructionsShare && toggleQrInstructionsShare) {
                editQrInstructionsShare.value = <?php echo json_encode($surveySettings['qr_instructions_text'] ?? ''); ?>;
                toggleQrInstructionsShare.checked = <?php echo $surveySettings['show_qr_instructions_share'] ? 'true' : 'false'; ?>;
            }

            if (editFooterNoteShare && toggleFooterNoteShare) {
                editFooterNoteShare.value = <?php echo json_encode($surveySettings['footer_note_text'] ?? ''); ?>;
                toggleFooterNoteShare.checked = <?php echo $surveySettings['show_footer_note_share'] ? 'true' : 'false'; ?>;
            }

            if (document.getElementById('toggle-numbering') && document.getElementById('numbering-style')) {
                updateQuestionNumberingPreview();
            }
        };

        // Call the initial load function after all elements and event listeners are set up.
        window.loadPreviewSettings();

        // This is crucial for the dropdown logic to work on page load.
        applyTypeSpecificControls(); 
        setupCharacterCounters();

        // Accordion Logic
        const accordionHeaders = document.querySelectorAll('.accordion-header');
        accordionHeaders.forEach(header => {
            header.addEventListener('click', function() {
                const content = this.nextElementSibling;
                const icon = this.querySelector('i');

                accordionHeaders.forEach(otherHeader => {
                    if (otherHeader !== this && otherHeader.classList.contains('active')) {
                        otherHeader.classList.remove('active');
                        otherHeader.nextElementSibling.style.display = 'none';
                        otherHeader.querySelector('i').classList.remove('active');
                    }
                });

                this.classList.toggle('active');
                icon.classList.toggle('active');
                if (content.style.display === 'block') {
                    content.style.display = 'none';
                } else {
                    content.style.display = 'block';
                }
            });
        });

    });
</script>

    <script src="argon-dashboard-master/assets/js/core/popper.min.js"></script>
    <script src="argon-dashboard-master/assets/js/core/bootstrap.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script defer src="survey_page.js"></script>
</body>
</html>