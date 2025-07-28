<?php
session_start();

// Include the database connection file
require_once 'connect.php'; // Make sure the path is correct relative to this file

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
                 'show_rating_instructions', 'show_facility_section', 'show_location_row_general',
                 'show_location_row_period_age', 'show_ownership_section', 'show_republic_title_share',
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
                show_facility_section, show_location_row_general, show_location_row_period_age, show_ownership_section,
                republic_title_text, show_republic_title_share, ministry_subtitle_text, show_ministry_subtitle_share,
                qr_instructions_text, show_qr_instructions_share, footer_note_text, show_footer_note_share,
                selected_instance_key, selected_hierarchy_level
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
        ");

        $insertData = [
            $surveyId, $defaultLogoPath, $defaultShowLogo, $defaultFlagBlackColor, $defaultFlagYellowColor, $defaultFlagRedColor, $defaultShowFlagBar,
            $defaultTitleText, $defaultShowTitle, $defaultSubheadingText, $defaultShowSubheading, $defaultShowSubmitButton,
            $defaultRatingInstruction1Text, $defaultRatingInstruction2Text, $defaultShowRatingInstructions,
            $defaultShowFacilitySection, $defaultShowLocationRowGeneral, $defaultShowLocationRowPeriodAge, $defaultShowOwnershipSection,
            $defaultRepublicTitleText, $defaultShowRepublicTitleShare, $defaultMinistrySubtitleText, $defaultShowMinistrySubtitleShare,
            $defaultQrInstructionsText, $defaultShowQrInstructionsShare, $defaultFooterNoteText, $defaultShowFooterNoteShare,
            $defaultSelectedInstanceKey, $defaultSelectedHierarchyLevel
        ];

        if ($insertStmt->execute($insertData)) {
            $settingsStmt->execute([$surveyId]); // Re-fetch to get newly inserted defaults
            $surveySettings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
             // Convert boolean-like integers to actual booleans for JavaScript convenience
            foreach(['show_logo', 'show_flag_bar', 'show_title', 'show_subheading', 'show_submit_button',
                     'show_rating_instructions', 'show_facility_section', 'show_location_row_general',
                     'show_location_row_period_age', 'show_ownership_section', 'show_republic_title_share',
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
                'show_facility_section' => (bool)$defaultShowFacilitySection, 'show_location_row_general' => (bool)$defaultShowLocationRowGeneral, 'show_location_row_period_age' => (bool)$defaultShowLocationRowPeriodAge, 'show_ownership_section' => (bool)$defaultShowOwnershipSection,
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
        'show_facility_section' => true, 'show_location_row_general' => true, 'show_location_row_period_age' => true, 'show_ownership_section' => true,
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
    <title><?php echo $defaultSurveyTitle; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles.css">
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
    </style>
</head>
<body>
    <div class="main-content">
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
                <div class="location-row" id="location-row-general">
                    <div class="form-group">
                        <label for="serviceUnit" data-translate="service_unit"><?php echo $translations['service_unit'] ?? 'Service Unit'; ?>:</label>
                        <select id="serviceUnit" name="serviceUnit" required>
                            <option value="">none selected</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="sex" data-translate="sex"><?php echo $translations['sex'] ?? 'Sex'; ?>:</label>
                        <select id="sex" name="sex">
                            <option value="" disabled selected>none selected</option>
                            <option value="Male" data-translate="male"><?php echo $translations['male'] ?? 'Male'; ?></option>
                            <option value="Female" data-translate="female"><?php echo $translations['female'] ?? 'Female'; ?></option>
                        </select>
                    </div>
                </div>

                <div class="location-row" id="location-row-period-age">
                    <div class="reporting-period-container">
                        <label for="reporting_period" data-translate="reporting_period"><?php echo $translations['reporting_period'] ?? 'Reporting Period'; ?></label>
                        <input
                            type="date"
                            id="reporting_period"
                            name="reporting_period"
                            placeholder="Select Reporting Period"
                            required
                            min="2010-01-01"
                            max="2030-12-31"
                        >
                        <span class="placeholder-text">Click to select reporting period</span>
                    </div>

                    <div class="form-group">
                        <label for="age" data-translate="age"><?php echo $translations['age'] ?? 'Age'; ?>:</label>
                        <input type="number" id="age" name="age" min="10" max="99">
                    </div>
                </div>

                <div class="radio-group" id="ownership-section">
                   <label class="radio-label" data-translate="ownership"><?php echo $translations['ownership'] ?? 'Ownership'; ?></label>
                    <div class="radio-options" id="ownership-options">
                        </div>
                </div>

            <p id="rating-instruction-1" data-translate="rating_instruction"><?php echo htmlspecialchars($surveySettings['rating_instruction1_text'] ?? ''); ?></p>
          <p id="rating-instruction-2" data-translate="rating_scale" style="color: red; font-size: 14px; font-style: italic;"><?php echo htmlspecialchars($surveySettings['rating_instruction2_text'] ?? ''); ?></p>

            <?php endif; ?>

            <?php foreach ($questionsArray as $index => $question): ?>
                <div class="form-group">
                    <div class="radio-label">
                        <span class="question-number"><?php echo ($index + 1) . '.'; ?></span>
                        <?php echo htmlspecialchars($question['label']); ?>
                    </div>

                    <?php if ($question['question_type'] == 'radio'): ?>
                        <div class="radio-options" style="display: flex; flex-wrap: wrap; gap: 12px;">
                            <?php foreach ($question['options'] as $option): ?>
                                <div class="radio-option" style="flex: 1 1 220px; min-width: 180px;">
                                    <input type="radio"
                                           id="option_<?php echo $question['id']; ?>_<?php echo $option['id']; ?>"
                                           name="question_<?php echo $question['id']; ?>"
                                           value="<?php echo htmlspecialchars($option['option_value']); ?>">
                                    <label for="option_<?php echo $question['id']; ?>_<?php echo $option['id']; ?>">
                                        <?php echo htmlspecialchars($option['option_value']); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($question['question_type'] == 'checkbox'): ?>
                        <div class="checkbox-options" style="display: flex; flex-wrap: wrap; gap: 12px;">
                            <?php foreach ($question['options'] as $option): ?>
                                <div class="checkbox-option" style="flex: 1 1 220px; min-width: 180px;">
                                    <input type="checkbox"
                                           id="option_<?php echo $question['id']; ?>_<?php echo $option['id']; ?>"
                                           name="question_<?php echo $question['id']; ?>[]"
                                           value="<?php echo htmlspecialchars($option['option_value']); ?>">
                                    <label for="option_<?php echo $question['id']; ?>_<?php echo $option['id']; ?>">
                                        <?php echo htmlspecialchars($option['option_value']); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($question['question_type'] == 'select'): ?>
                        <select class="form-control" name="question_<?php echo $question['id']; ?>">
                            <option value="">Select an option</option>
                            <?php foreach ($question['options'] as $option): ?>
                                <option value="<?php echo htmlspecialchars($option['option_value']); ?>">
                                    <?php echo htmlspecialchars($option['option_value']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($question['question_type'] == 'text'): ?>
                        <input type="text"
                               class="form-control"
                               name="question_<?php echo $question['id']; ?>">
                    <?php elseif ($question['question_type'] == 'textarea'): ?>
                        <textarea class="form-control"
                                  name="question_<?php echo $question['id']; ?>"
                                  rows="3"></textarea>


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
<?php endif; ?>

                </div>
            <?php endforeach; ?>

            <button type="submit" id="submit-button-preview">Submit</button>
        </div>

        <div class="control-panel">
            <h3>Preview Settings</h3>

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
                            <label for="edit-title">Survey Title:</label><input type="text" id="edit-title" value="<?php echo htmlspecialchars($surveySettings['title_text'] ?? ''); ?>">
                            <div class="checkbox-group">
                                <label>
                                    <input type="checkbox" id="toggle-title" <?php echo $surveySettings['show_title'] ? 'checked' : ''; ?>> Show Title
                                </label>
                            </div>
                        </div>

                    <div class="setting-group">
                        <label for="edit-subheading">Survey Subheading:</label>
                        <textarea id="edit-subheading" rows="4"><?php echo htmlspecialchars($surveySettings['subheading_text'] ?? 'This tool is used to obtain clients\' feedback about their experience with the services and promote quality improvement, accountability, and transparency within the healthcare system.'); ?></textarea>

                        <div class="checkbox-group">
                            <label>
                                <input type="checkbox" id="toggle-subheading" <?php echo $surveySettings['show_subheading'] ? 'checked' : ''; ?>> Show Subheading
                            </label>
                        </div>
                    </div>

                    <div class="setting-group" id="rating-instructions-control-group">
                        <label for="edit-rating-instruction-1">Rating Instruction 1:</label>
                        <textarea id="edit-rating-instruction-1" rows="2"><?php echo htmlspecialchars($translations['rating_instruction'] ?? '1. Please rate each of the following parameters according to your experience today on a scale of 1 to 4.'); ?></textarea>
                        <label for="edit-rating-instruction-2">Rating Instruction 2:</label>
                        <textarea id="edit-rating-instruction-2" rows="2"><?php echo htmlspecialchars($translations['rating_scale'] ?? 'where \'0\' means Poor, \'1\' Fair, \'2\' Good and \'3\' Excellent'); ?></textarea>
                        <div class="checkbox-group">
                            <label>
                                <input type="checkbox" id="toggle-rating-instructions" <?php echo $surveySettings['show_rating_instructions'] ? 'checked' : ''; ?>> Show Rating Instructions
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="accordion-item" id="form-sections-accordion-item">
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

                    <div class="setting-group" id="toggle-location-row-general-group">
                        <div class="checkbox-group">
                            <label>
                               <input type="checkbox" id="toggle-location-row-general" <?php echo $surveySettings['show_location_row_general'] ? 'checked' : ''; ?>> Show Service Unit/Sex
                            </label>
                        </div>
                    </div>

                    <div class="setting-group" id="toggle-location-row-period-age-group">
                        <div class="checkbox-group">
                            <label>
                                <input type="checkbox" id="toggle-location-row-period-age" <?php echo $surveySettings['show_location_row_period_age'] ? 'checked' : ''; ?>> Show Reporting Period/Age
                            </label>
                        </div>
                    </div>

                    <div class="setting-group" id="toggle-ownership-section-group">
                        <div class="checkbox-group">
                            <label>
                               <input type="checkbox" id="toggle-ownership-section" <?php echo $surveySettings['show_ownership_section'] ? 'checked' : ''; ?>> Show Ownership
                            </label>
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
                        <textarea id="edit-qr-instructions-share" rows="3"><?php echo htmlspecialchars($surveySettings['qr_instructions_text'] ?? ''); ?></textarea>
                        <div class="checkbox-group">
                            <label>
                                <input type="checkbox" id="toggle-qr-instructions-share" <?php echo $surveySettings['show_qr_instructions_share'] ? 'checked' : ''; ?>> Show QR Instructions
                            </label>
                        </div>
                    </div>
                    <div class="setting-group">
                        <label for="edit-footer-note-share">Footer Note Text (Share Page):</label>
                       <textarea id="edit-footer-note-share" rows="2"><?php echo htmlspecialchars($surveySettings['footer_note_text'] ?? 'Thank you for helping us improve our services.'); ?></textarea>

                        <div class="checkbox-group">
                            <label>
                                <input type="checkbox" id="toggle-footer-note-share" <?php echo $surveySettings['show_footer_note_share'] ? 'checked' : ''; ?>> Show Footer Note
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <hr> <button onclick="savePreviewSettings()">Save Preview Settings</button>
          <button onclick="resetPreviewSettings()">Reset Preview</button>
        </div>
    </div>

    <div class="bottom-controls">
        <button onclick="window.location.href='update_form?survey_id=<?php echo $surveyId; ?>'" class="action-button">
            <i class="fas fa-arrow-left"></i> Back
        </button>
        <button id="share-btn" class="action-button">
            <i class="fas fa-share"></i> Share
        </button>
        <button onclick="window.location.href='survey_page.php?survey_id=<?php echo $surveyId; ?>'" class="action-button">
            <i class="fas fa-rocket"></i> Generate
        </button>
    </div>

<script>
    const surveyType = "<?php echo $survey['type']; ?>";
    const surveyId = "<?php echo $surveyId; ?>";
    const hierarchyLevelMap = <?php echo json_encode($hierarchyLevels); ?>;

    document.addEventListener('DOMContentLoaded', function() {
        // --- 1. DOM Element References ---
        const logoImg = document.getElementById('moh-logo');
        const logoUpload = document.getElementById('logo-upload');
        const toggleLogo = document.getElementById('toggle-logo');
        const logoSection = document.getElementById('logo-section');

        const flagBlackColorPicker = document.getElementById('flag-black-color-picker');
        const flagYellowColorPicker = document = document.getElementById('flag-yellow-color-picker');
        const flagRedColorPicker = document.getElementById('flag-red-color');
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

        // Facility Filter Elements
        const instanceKeyFilterGroup = document.getElementById('instance-key-filter-group');
        const hierarchyLevelFilterGroup = document.getElementById('hierarchy-level-filter-group');
        const controlInstanceKeySelect = document.getElementById('control-instance-key-select');
        const controlHierarchyLevelSelect = document.getElementById('control-hierarchy-level-select');
        const facilitySearchInput = document.getElementById('facility-search');
        const facilityResultsDiv = document.getElementById('facility-results');
        const pathDisplay = document.getElementById('path-display');
        const facilityIdInput = document.getElementById('facility_id');
        const hierarchyDataInput = document.getElementById('hierarchy_data');

        const toggleLocationRowGeneral = document.getElementById('toggle-location-row-general');
        const locationRowGeneral = document.getElementById('location-row-general');

        const toggleLocationRowPeriodAge = document.getElementById('toggle-location-row-period-age');
        const locationRowPeriodAge = document.getElementById('location-row-period-age');

        const toggleOwnershipSection = document.getElementById('toggle-ownership-section');
        const ownershipSection = document.getElementById('ownership-section');

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
        const toggleQrInstructionsShare = document.getElementById('toggle-qr-instructions-share'); // Fixed here

        const editFooterNoteShare = document.getElementById('edit-footer-note-share');
        const toggleFooterNoteShare = document.getElementById('toggle-footer-note-share');


        // --- 2. Helper Functions ---
        function showToast(message, type = 'success') {
            let toast = document.createElement('div');
            toast.textContent = message;
            toast.style.position = 'fixed';
            toast.style.bottom = '180px';
            toast.style.left = '50%';
            toast.style.transform = 'translateX(-50%)';
            toast.style.background = type === 'success' ? 'green' : 'red';
            toast.style.color = '#fff';
            toast.style.padding = '12px 28px';
            toast.style.borderRadius = '6px';
            toast.style.boxShadow = '0 2px 8px rgba(0,0,0,0.15)';
            toast.style.fontSize = '16px';
            toast.style.zIndex = '2000';
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s';

            document.body.appendChild(toast);
            setTimeout(() => { toast.style.opacity = '1'; }, 10);
            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => { document.body.removeChild(toast); }, 400);
            }, type === 'success' ? 1800 : 3000);
        }

        // --- 3. Core Logic Functions ---

       /* --- Replace your applyTypeSpecificControls function with this: --- */
        function applyTypeSpecificControls() {
            if (surveyType === 'dhis2') {
                // Show the Form Sections Visibility accordion
                if (formSectionsAccordionItem) formSectionsAccordionItem.classList.remove('hidden-element');

                // Hide only the specific toggles/groups
                const locationRowGeneralGroup = document.getElementById('toggle-location-row-general-group');
                const locationRowPeriodAgeGroup = document.getElementById('toggle-location-row-period-age-group');
                const ownershipSectionGroup = document.getElementById('toggle-ownership-section-group');
                const submitButtonGroup = document.getElementById('toggle-submit-button-group');

                if (locationRowGeneralGroup) locationRowGeneralGroup.classList.add('hidden-element');
                if (locationRowPeriodAgeGroup) locationRowPeriodAgeGroup.classList.add('hidden-element');
                if (ownershipSectionGroup) ownershipSectionGroup.classList.add('hidden-element');
                if (submitButtonGroup) submitButtonGroup.classList.add('hidden-element');

                // Show the facility section and its filters (do NOT hide them)
                if (facilitySection) facilitySection.classList.remove('hidden-element');
                if (instanceKeyFilterGroup) instanceKeyFilterGroup.style.display = 'block';
                if (hierarchyLevelFilterGroup) hierarchyLevelFilterGroup.style.display = 'block';

                // Optionally, hide the rating instructions group if you want
                if (ratingInstructionsControlGroup) ratingInstructionsControlGroup.classList.add('hidden-element');

                // Hide the actual form sections in the preview as well
                if (locationRowGeneral) locationRowGeneral.classList.add('hidden-element');
                if (locationRowPeriodAge) locationRowPeriodAge.classList.add('hidden-element');
                if (ownershipSection) ownershipSection.classList.add('hidden-element');
                if (ratingInstruction1) ratingInstruction1.classList.add('hidden-element');
                if (ratingInstruction2) ratingInstruction2.classList.add('hidden-element');

            } else if (surveyType === 'local') {
                // Show everything for local
                if (formSectionsAccordionItem) formSectionsAccordionItem.classList.remove('hidden-element');
                if (ratingInstructionsControlGroup) ratingInstructionsControlGroup.classList.remove('hidden-element');

                // Show all setting groups
                const locationRowGeneralGroup = document.getElementById('toggle-location-row-general-group');
                const locationRowPeriodAgeGroup = document.getElementById('toggle-location-row-period-age-group');
                const ownershipSectionGroup = document.getElementById('toggle-ownership-section-group');
                const submitButtonGroup = document.getElementById('toggle-submit-button-group');

                if (locationRowGeneralGroup) locationRowGeneralGroup.classList.remove('hidden-element');
                if (locationRowPeriodAgeGroup) locationRowPeriodAgeGroup.classList.remove('hidden-element');
                if (ownershipSectionGroup) ownershipSectionGroup.classList.remove('hidden-element');
                if (submitButtonGroup) submitButtonGroup.classList.remove('hidden-element');

                // Show facility section and filters
                if (facilitySection) facilitySection.classList.remove('hidden-element');
                if (instanceKeyFilterGroup) instanceKeyFilterGroup.style.display = 'block';
                if (hierarchyLevelFilterGroup) hierarchyLevelFilterGroup.style.display = 'block';
            }
        }
   window.savePreviewSettings = async function() {
        // ... (your existing savePreviewSettings logic) ...
        const settings = {
            surveyId: surveyId,
            logoSrc: logoImg.src,
            showLogo: toggleLogo.checked,
            flagBlackColor: flagBlackColorPicker.value,
            flagYellowColor: flagYellowColorPicker.value, // Make sure this element is correctly referenced above
            flagRedColor: flagRedColorPicker.value,
            showFlagBar: toggleFlagBar.checked,
            titleText: editTitle.value,
            showTitle: toggleTitle.checked,
            subheadingText: editSubheading.value,
            showSubheading: toggleSubheading.checked,
            showSubmitButton: toggleSubmitButton.checked,

            ratingInstruction1Text: editRatingInstruction1 ? editRatingInstruction1.value : '',
            ratingInstruction2Text: editRatingInstruction2 ? editRatingInstruction2.value : '',
            showRatingInstructions: toggleRatingInstructions ? toggleRatingInstructions.checked : false,
            
            showFacilitySection: surveyType === 'dhis2' || (toggleFacilitySection ? toggleFacilitySection.checked : false),
            
            showLocationRowGeneral: toggleLocationRowGeneral ? toggleLocationRowGeneral.checked : false,
            showLocationRowPeriodAge: toggleLocationRowPeriodAge ? toggleLocationRowPeriodAge.checked : false,
            showOwnershipSection: toggleOwnershipSection ? toggleOwnershipSection.checked : false,

            republicTitleText: editRepublicTitleShare.value,
            showRepublicTitleShare: toggleRepublicTitleShare.checked,
            ministrySubtitleText: editMinistrySubtitleShare.value,
            showMinistrySubtitleShare: toggleMinistrySubtitleShare.checked,
            qrInstructionsText: editQrInstructionsShare.value,
            showQrInstructionsShare: toggleQrInstructionsShare.checked,
            footerNoteText: editFooterNoteShare.value,
            showFooterNoteShare: toggleFooterNoteShare.checked,

            selectedInstanceKey: controlInstanceKeySelect ? controlInstanceKeySelect.value : null,
            selectedHierarchyLevel: controlHierarchyLevelSelect ? (controlHierarchyLevelSelect.value === '' ? null : parseInt(controlHierarchyLevelSelect.value, 10)) : null,
        };

        try {
            const response = await fetch('save_survey_settings.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(settings)
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Failed to save settings due to a server error.');
            }

            showToast(data.message, 'success');

        } catch (error) {
            console.error('Error saving settings:', error);
            showToast(error.message || 'An unexpected error occurred while saving.', 'error');
        }
    };

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

        let currentFilteredLocations = []; // This will hold locations filtered by Instance and Level

        // Renamed function for clarity: fetches locations based on current filters
        async function updateLocationsBasedOnFilters() {
            const instanceKey = controlInstanceKeySelect.value;
            const hierarchyLevel = controlHierarchyLevelSelect.value;

            // Clear previous display elements
            facilitySearchInput.value = '';
            facilityResultsDiv.innerHTML = '';
            facilityResultsDiv.style.display = 'none'; // Hide dropdown
            facilityIdInput.value = '';
            pathDisplay.textContent = '';
            hierarchyDataInput.value = '';

            if (!instanceKey || !hierarchyLevel) {
                currentFilteredLocations = []; // Clear data if filters are not fully set
                facilitySearchInput.disabled = true; // Disable search input
                // Display the prompt immediately in the results div if filters are not selected
                filterAndDisplaySearchResults('');
                return; // Do not proceed with fetch
            }

            // Both filters are selected, enable input and fetch data
            facilitySearchInput.disabled = false;
            try {
                const params = new URLSearchParams();
                params.append('instance_key', instanceKey);
                params.append('hierarchylevel', hierarchyLevel);

                const response = await fetch(`get_locations.php?${params.toString()}`);
                if (!response.ok) {
                    // Check for a non-200 status code explicitly
                    const errorText = await response.text(); // Get response body
                    throw new Error(`Network response was not ok (${response.status}): ${errorText}`);
                }
                const responseData = await response.json();

                if (responseData.error) { // Check if the PHP script returned an application-level error
                    throw new Error(`Server Error: ${responseData.error}`);
                }

                currentFilteredLocations = responseData; // Update the master list
                filterAndDisplaySearchResults(''); // Display all loaded locations (empty search term)
            } catch (error) {
                console.error('Error fetching locations:', error);
                facilityResultsDiv.innerHTML = `<div style="padding: 8px; color: red;">${error.message || 'Error loading locations from server.'}</div>`;
                facilityResultsDiv.style.display = 'block'; // Show error message
                facilitySearchInput.disabled = true; // Disable on error
            }
        }

        async function fetchLocationPath(locationId) {
            if (!locationId) {
                return '';
            }
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
                // Displaying this error might be too disruptive for a path, so just log
                return '';
            }
        }

        function filterAndDisplaySearchResults(searchTerm) {
            facilityResultsDiv.innerHTML = ''; // Always clear previous results
            facilityResultsDiv.style.display = 'block'; // Ensure dropdown area is visible for messages/results

            if (facilitySearchInput.disabled) {
                facilityResultsDiv.innerHTML = '<div style="padding: 8px; color: #888;">Select an Instance and Level to load locations.</div>';
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
                    facilityResultsDiv.appendChild(div);
                });
            } else {
                if (searchTerm.length > 0) {
                    facilityResultsDiv.innerHTML = '<div style="padding: 8px; color: #888;">No matching locations found for your search.</div>';
                } else {
                    // This case means filters are selected, but no locations were returned by the server
                    // or currentFilteredLocations is empty for some other reason (e.g., specific filter combo yields no results)
                    facilityResultsDiv.innerHTML = '<div style="padding: 8px; color: #888;">No locations available for selected filters.</div>';
                }
            }
        }

        // --- 4. Event Listeners for Live Preview Updates ---

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

        toggleLogo.addEventListener('change', function() {
            logoSection.classList.toggle('hidden-element', !this.checked);
            if (toggleLogoUrl) toggleLogoUrl.checked = this.checked;
        });
        if (toggleLogoUrl) {
            toggleLogoUrl.addEventListener('change', function() {
                if (toggleLogo) toggleLogo.checked = this.checked;
                logoSection.classList.toggle('hidden-element', !this.checked);
            });
        }

        flagBlackColorPicker.addEventListener('input', function() { flagBlackElement.style.backgroundColor = this.value; });
        // FIX: Corrected typo for flagYellowColorPicker
        flagYellowColorPicker.addEventListener('input', function() { flagYellowElement.style.backgroundColor = this.value; });
        flagRedColorPicker.addEventListener('input', function() { flagRedElement.style.backgroundColor = this.value; });
        toggleFlagBar.addEventListener('change', function() { flagBarElement.classList.toggle('hidden-element', !this.checked); });

        editTitle.addEventListener('input', function() { surveyTitle.textContent = this.value; });
        toggleTitle.addEventListener('change', function() { surveyTitle.classList.toggle('hidden-element', !this.checked); });

        editSubheading.addEventListener('input', function() { surveySubheading.textContent = this.value; });
        toggleSubheading.addEventListener('change', function() { surveySubheading.classList.toggle('hidden-element', !this.checked); });

        if (surveyType === 'local') {
            if (editRatingInstruction1) editRatingInstruction1.addEventListener('input', function() { ratingInstruction1.textContent = this.value; });
            if (editRatingInstruction2) editRatingInstruction2.addEventListener('input', function() { ratingInstruction2.textContent = this.value; });
            if (toggleRatingInstructions) toggleRatingInstructions.addEventListener('change', function() {
                if (ratingInstruction1) ratingInstruction1.classList.toggle('hidden-element', !this.checked);
                if (ratingInstruction2) ratingInstruction2.classList.toggle('hidden-element', !this.checked);
            });
        }

         if (surveyType === 'local' || surveyType === 'dhis2') {
            toggleFacilitySection.addEventListener('change', function() {
                const isChecked = this.checked;
                if (facilitySection) facilitySection.classList.toggle('hidden-element', !isChecked);

                if (instanceKeyFilterGroup) instanceKeyFilterGroup.style.display = isChecked ? 'block' : 'none';
                if (hierarchyLevelFilterGroup) hierarchyLevelFilterGroup.style.display = isChecked ? 'block' : 'none';

                if (isChecked) {
                    updateLocationsBasedOnFilters(); // Re-evaluate and load based on current dropdowns
                } else {
                    // Clear and disable search input when section is hidden
                    facilitySearchInput.value = '';
                    facilityResultsDiv.innerHTML = '';
                    facilityResultsDiv.style.display = 'none';
                    facilityIdInput.value = '';
                    pathDisplay.textContent = '';
                    hierarchyDataInput.value = '';
                    facilitySearchInput.disabled = true;
                }
            });

            controlInstanceKeySelect.addEventListener('change', function() {
                updateLocationsBasedOnFilters(); // Trigger re-fetch and update
            });

            controlHierarchyLevelSelect.addEventListener('change', function() {
                updateLocationsBasedOnFilters(); // Trigger re-fetch and update
            });

            facilitySearchInput.addEventListener('input', function() {
                filterAndDisplaySearchResults(this.value); // Filter the already loaded list
            });

            // Hide dropdown if clicking outside search input or results
            document.addEventListener('click', function(event) {
                if (!facilitySearchInput.contains(event.target) && !facilityResultsDiv.contains(event.target)) {
                    facilityResultsDiv.style.display = 'none';
                }
            });

            // When user focuses on the facility search input
            facilitySearchInput.addEventListener('focus', function() {
                filterAndDisplaySearchResults(this.value); // Show results or prompt
            });

            facilityResultsDiv.addEventListener('click', async function(event) {
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

            if (toggleLocationRowGeneral) toggleLocationRowGeneral.addEventListener('change', function() { if (locationRowGeneral) locationRowGeneral.classList.toggle('hidden-element', !this.checked); });
            if (toggleLocationRowPeriodAge) toggleLocationRowPeriodAge.addEventListener('change', function() { if (locationRowPeriodAge) locationRowPeriodAge.classList.toggle('hidden-element', !this.checked); });
            if (toggleOwnershipSection) toggleOwnershipSection.addEventListener('change', function() { if (ownershipSection) ownershipSection.classList.toggle('hidden-element', !this.checked); });
        }

        toggleSubmitButton.addEventListener('change', function() { submitButtonPreview.classList.toggle('hidden-element', !this.checked); });

        editRepublicTitleShare.addEventListener('input', function() { republicTitleElement.textContent = this.value; });
        toggleRepublicTitleShare.addEventListener('change', function() { republicTitleElement.classList.toggle('hidden-element', !this.checked); });
        editMinistrySubtitleShare.addEventListener('input', function() { ministrySubtitleElement.textContent = this.value; });
        toggleMinistrySubtitleShare.addEventListener('change', function() { ministrySubtitleElement.classList.toggle('hidden-element', !this.checked); });

        // --- 5. Accordion Logic ---
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

        // --- 6. Initial Setup & Share Button ---

        applyTypeSpecificControls(); // Call first to set initial visibility

        window.loadPreviewSettings = function() {
            // Restore actual current values from PHP-rendered DOM
            logoImg.src = "<?php echo htmlspecialchars($surveySettings['logo_path'] ?? ''); ?>";
            toggleLogo.checked = <?php echo $surveySettings['show_logo'] ? 'true' : 'false'; ?>;
            logoSection.classList.toggle('hidden-element', !toggleLogo.checked);

            flagBlackColorPicker.value = "<?php echo htmlspecialchars($surveySettings['flag_black_color'] ?? '#000000'); ?>";
            flagYellowColorPicker.value = "<?php echo htmlspecialchars($surveySettings['flag_yellow_color'] ?? '#FCD116'); ?>";
            flagRedColorPicker.value = "<?php echo htmlspecialchars($surveySettings['flag_red_color'] ?? '#D21034'); ?>";
            flagBlackElement.style.backgroundColor = flagBlackColorPicker.value;
            flagYellowElement.style.backgroundColor = flagYellowColorPicker.value;
            flagRedElement.style.backgroundColor = flagRedColorPicker.value;
            toggleFlagBar.checked = <?php echo $surveySettings['show_flag_bar'] ? 'true' : 'false'; ?>;
            flagBarElement.classList.toggle('hidden-element', !toggleFlagBar.checked);

            editTitle.value = "<?php echo htmlspecialchars($surveySettings['title_text'] ?? ''); ?>";
            surveyTitle.textContent = editTitle.value;
            toggleTitle.checked = <?php echo $surveySettings['show_title'] ? 'true' : 'false'; ?>;
            surveyTitle.classList.toggle('hidden-element', !toggleTitle.checked);

            editSubheading.value = "<?php echo htmlspecialchars($surveySettings['subheading_text'] ?? ''); ?>";
            surveySubheading.textContent = editSubheading.value;
            toggleSubheading.checked = <?php echo $surveySettings['show_subheading'] ? 'true' : 'false'; ?>;
            surveySubheading.classList.toggle('hidden-element', !toggleSubheading.checked);

            if (surveyType === 'local') {
                if (editRatingInstruction1) editRatingInstruction1.value = "<?php echo htmlspecialchars($surveySettings['rating_instruction1_text'] ?? ''); ?>";
                if (ratingInstruction1) ratingInstruction1.textContent = editRatingInstruction1 ? editRatingInstruction1.value : '';
                if (editRatingInstruction2) editRatingInstruction2.value = "<?php echo htmlspecialchars($surveySettings['rating_instruction2_text'] ?? ''); ?>";
                if (ratingInstruction2) ratingInstruction2.textContent = editRatingInstruction2 ? editRatingInstruction2.value : '';
                if (toggleRatingInstructions) toggleRatingInstructions.checked = <?php echo $surveySettings['show_rating_instructions'] ? 'true' : 'false'; ?>;
                if (ratingInstruction1) ratingInstruction1.classList.toggle('hidden-element', !toggleRatingInstructions.checked);
                if (ratingInstruction2) ratingInstruction2.classList.toggle('hidden-element', !toggleRatingInstructions.checked);

                if (toggleFacilitySection) toggleFacilitySection.checked = <?php echo $surveySettings['show_facility_section'] ? 'true' : 'false'; ?>;
                if (facilitySection) facilitySection.classList.toggle('hidden-element', !toggleFacilitySection.checked);

                // Set initial values for filter dropdowns from surveySettings
                controlInstanceKeySelect.value = "<?php echo htmlspecialchars($surveySettings['selected_instance_key'] ?? ''); ?>";
                controlHierarchyLevelSelect.value = "<?php echo htmlspecialchars($surveySettings['selected_hierarchy_level'] ?? ''); ?>";

                // Ensure filter groups visibility matches the facility section toggle
                if (toggleFacilitySection.checked) {
                    if (instanceKeyFilterGroup) instanceKeyFilterGroup.style.display = 'block';
                    if (hierarchyLevelFilterGroup) hierarchyLevelFilterGroup.style.display = 'block';

                    // This is the primary function to call on load to manage search input state and data
                    updateLocationsBasedOnFilters();
                } else {
                    if (instanceKeyFilterGroup) instanceKeyFilterGroup.style.display = 'none';
                    if (hierarchyLevelFilterGroup) hierarchyLevelFilterGroup.style.display = 'none';
                    facilitySearchInput.disabled = true; // Ensure disabled if section is hidden
                }

                if (toggleLocationRowGeneral) toggleLocationRowGeneral.checked = <?php echo $surveySettings['show_location_row_general'] ? 'true' : 'false'; ?>;
                if (locationRowGeneral) locationRowGeneral.classList.toggle('hidden-element', !toggleLocationRowGeneral.checked);

                if (toggleLocationRowPeriodAge) toggleLocationRowPeriodAge.checked = <?php echo $surveySettings['show_location_row_period_age'] ? 'true' : 'false'; ?>;
                if (locationRowPeriodAge) locationRowPeriodAge.classList.toggle('hidden-element', !toggleLocationRowPeriodAge.checked);

                if (toggleOwnershipSection) toggleOwnershipSection.checked = <?php echo $surveySettings['show_ownership_section'] ? 'true' : 'false'; ?>;
                if (ownershipSection) ownershipSection.classList.toggle('hidden-element', !toggleOwnershipSection.checked);
            }

            toggleSubmitButton.checked = <?php echo $surveySettings['show_submit_button'] ? 'true' : 'false'; ?>;
            submitButtonPreview.classList.toggle('hidden-element', !toggleSubmitButton.checked);

            editRepublicTitleShare.value = "<?php echo htmlspecialchars($surveySettings['republic_title_text'] ?? ''); ?>";
            republicTitleElement.textContent = editRepublicTitleShare.value;
            toggleRepublicTitleShare.checked = <?php echo $surveySettings['show_republic_title_share'] ? 'true' : 'false'; ?>;
            republicTitleElement.classList.toggle('hidden-element', !toggleRepublicTitleShare.checked);

            editMinistrySubtitleShare.value = "<?php echo htmlspecialchars($surveySettings['ministry_subtitle_text'] ?? ''); ?>";
            ministrySubtitleElement.textContent = editMinistrySubtitleShare.value;
            toggleMinistrySubtitleShare.checked = <?php echo $surveySettings['show_ministry_subtitle_share'] ? 'true' : 'false'; ?>;
            ministrySubtitleElement.classList.toggle('hidden-element', !toggleMinistrySubtitleShare.checked);

            editQrInstructionsShare.value = "<?php echo htmlspecialchars($surveySettings['qr_instructions_text'] ?? ''); ?>";
            toggleQrInstructionsShare.checked = <?php echo $surveySettings['show_qr_instructions_share'] ? 'true' : 'false'; ?>;

            editFooterNoteShare.value = "<?php echo htmlspecialchars($surveySettings['footer_note_text'] ?? ''); ?>";
            toggleFooterNoteShare.checked = <?php echo $surveySettings['show_footer_note_share'] ? 'true' : 'false'; ?>;
        };

        window.loadPreviewSettings(); // Call on initial load

        document.getElementById('share-btn').addEventListener('click', async function() {
            await window.savePreviewSettings();
            const surveyUrl = window.location.origin + '/fbs/admin/survey_page.php?survey_id=' + surveyId;
            window.location.href = `share_page.php?survey_id=${surveyId}&url=${encodeURIComponent(surveyUrl)}`;
        });
    });
</script>

<script defer src="survey_page.js"></script>
</body>
</html>