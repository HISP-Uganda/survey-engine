<?php
session_start();

// Database connection using mysqli
$servername = "localhost";
$username = "root";
$password = "root";
$dbname = "fbtv3";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get survey_id from the URL
$surveyId = $_GET['survey_id'] ?? null;

if (!$surveyId) {
    die("Survey ID is missing.");
}



// Fetch survey details including survey name AND TYPE
$surveyStmt = $conn->prepare("SELECT id, type, name FROM survey WHERE id = ?");
$surveyStmt->bind_param("i", $surveyId);
$surveyStmt->execute();
$surveyResult = $surveyStmt->get_result();
$survey = $surveyResult->fetch_assoc();

if (!$survey) {
    die("Survey not found.");
}

// Set the default survey title from the database
// THIS IS LINE 48 IN YOUR ORIGINAL CODE IF THE ERROR WAS THERE,
// SO THE INSERT LOGIC SHOULD COME *AFTER* THIS LINE.
$defaultSurveyTitle = htmlspecialchars($survey['name'] ?? 'Ministry of Health Client Satisfaction Feedback Tool');

// Fetch translations for the selected language
$language = isset($_GET['language']) ? $_GET['language'] : 'en'; // Default to English
$translations = [];
$query = "SELECT key_name, translations FROM default_text";
$translations_result = $conn->query($query);
while ($row = $translations_result->fetch_assoc()) {
    $decoded_translations = json_decode($row['translations'], true);
    $translations[$row['key_name']] = $decoded_translations[$language] ?? $row['key_name'];
}

// Fetch survey settings from the database
$surveySettings = [];
$settingsStmt = $conn->prepare("SELECT * FROM survey_settings WHERE survey_id = ?");
$settingsStmt->bind_param("i", $surveyId);
$settingsStmt->execute();
$settingsResult = $settingsStmt->get_result();
$existingSettings = $settingsResult->fetch_assoc();

if ($existingSettings) {
    // If settings exist, use them
    $surveySettings = $existingSettings;
} else {
    // If no settings exist for this survey, insert default values
    // This handles new surveys or surveys created before this feature
    $insertStmt = $conn->prepare("
        INSERT INTO survey_settings (
            survey_id, logo_path, show_logo, flag_black_color, flag_yellow_color, flag_red_color, show_flag_bar,
            title_text, show_title, subheading_text, show_subheading, show_submit_button,
            rating_instruction1_text, rating_instruction2_text, show_rating_instructions,
            show_facility_section, show_location_row_general, show_location_row_period_age, show_ownership_section,
            republic_title_text, show_republic_title_share, ministry_subtitle_text, show_ministry_subtitle_share,
            qr_instructions_text, show_qr_instructions_share, footer_note_text, show_footer_note_share
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
    ");

    $defaultLogoPath = 'asets/asets/img/loog.jpg';
    $defaultShowLogo = 1;
    $defaultFlagBlackColor = '#000000';
    $defaultFlagYellowColor = '#FCD116';
    $defaultFlagRedColor = '#D21034';
    $defaultShowFlagBar = 1;
    $defaultTitleText = $defaultSurveyTitle; // Now $defaultSurveyTitle is defined!
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
    $defaultShowMinistrySubtitleShare = 1;
    $defaultQrInstructionsText = 'Scan this QR Code to Give Your Feedback on Services Received';
    $defaultShowQrInstructionsShare = 1;
    $defaultFooterNoteText = 'Thank you for helping us improve our services.';
    $defaultShowFooterNoteShare = 1;

   $type_string = "sisssisissiisssiiiiiisssisi"; // Confirmed 27 characters

$insertStmt->bind_param($type_string,
    $surveyId, $defaultLogoPath, $defaultShowLogo, $defaultFlagBlackColor, $defaultFlagYellowColor, $defaultFlagRedColor, $defaultShowFlagBar,
    $defaultTitleText, $defaultShowTitle, $defaultSubheadingText, $defaultShowSubheading, $defaultShowSubmitButton,
    $defaultRatingInstruction1Text, $defaultRatingInstruction2Text, $defaultShowRatingInstructions,
    $defaultShowFacilitySection, $defaultShowLocationRowGeneral, $defaultShowLocationRowPeriodAge, $defaultShowOwnershipSection,
    $defaultRepublicTitleText, $defaultShowRepublicTitleShare, $defaultMinistrySubtitleText, $defaultShowMinistrySubtitleShare,
    $defaultQrInstructionsText, $defaultShowQrInstructionsShare, $defaultFooterNoteText, $defaultShowFooterNoteShare
);

    if ($insertStmt->execute()) {
        // After successful insert, re-fetch to populate $surveySettings
        $settingsResult = $conn->query("SELECT * FROM survey_settings WHERE survey_id = $surveyId");
        $surveySettings = $settingsResult->fetch_assoc();
    } else {
        error_log("Error inserting default survey settings: " . $insertStmt->error);
        // Fallback: use hardcoded defaults if DB insert fails
        $surveySettings = [
            'logo_path' => $defaultLogoPath,
            'show_logo' => $defaultShowLogo,
            'flag_black_color' => $defaultFlagBlackColor,
            'flag_yellow_color' => $defaultFlagYellowColor,
            'flag_red_color' => $defaultFlagRedColor,
            'show_flag_bar' => $defaultShowFlagBar,
            'title_text' => $defaultTitleText,
            'show_title' => $defaultShowTitle,
            'subheading_text' => $defaultSubheadingText,
            'show_subheading' => $defaultShowSubheading,
            'show_submit_button' => $defaultShowSubmitButton,
            'rating_instruction1_text' => $defaultRatingInstruction1Text,
            'rating_instruction2_text' => $defaultRatingInstruction2Text,
            'show_rating_instructions' => $defaultShowRatingInstructions,
            'show_facility_section' => $defaultShowFacilitySection,
            'show_location_row_general' => $defaultShowLocationRowGeneral,
            'show_location_row_period_age' => $defaultShowLocationRowPeriodAge,
            'show_ownership_section' => $defaultShowOwnershipSection,
            'republic_title_text' => $defaultRepublicTitleText,
            'show_republic_title_share' => $defaultShowRepublicTitleShare,
            'ministry_subtitle_text' => $defaultMinistrySubtitleText,
            'show_ministry_subtitle_share' => $defaultMinistrySubtitleShare,
            'qr_instructions_text' => $defaultQrInstructionsText,
            'show_qr_instructions_share' => $defaultShowQrInstructionsShare,
            'footer_note_text' => $defaultFooterNoteText,
            'show_footer_note_share' => $defaultShowFooterNoteShare,
        ];
    }
    $insertStmt->close();
}
$settingsStmt->close();

// Fetch questions and options for the selected survey, ordered by position
$questions = $conn->query("
    SELECT q.id, q.label, q.question_type, q.is_required, q.translations, q.option_set_id, sq.position
    FROM question q
    JOIN survey_question sq ON q.id = sq.question_id
    WHERE sq.survey_id = $surveyId
    ORDER BY sq.position ASC
");

$questionsArray = [];
while ($question = $questions->fetch_assoc()) {
    $question['options'] = [];

    // Fetch options for the question with original order
    if ($question['option_set_id']) {
        $options = $conn->query("
            SELECT * FROM option_set_values
            WHERE option_set_id = " . $conn->real_escape_string($question['option_set_id']) . "
            ORDER BY id ASC
        ");

        if ($options) {
            while ($option = $options->fetch_assoc()) {
                $question['options'][] = $option;
            }
        }
    }
    $questionsArray[] = $question;
}



unset($question); // Break the reference with the last element
unset($option);   // Break the reference with the last element




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
        .control-panel input[type="file"] {
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
                        <input type="text" id="facility-search" placeholder="Type to search facilities..." autocomplete="off" required>
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

            <button type="submit" id="submit-button-preview"><?php echo $translations['submit'] ?? 'Submit'; ?></button>
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

                    <div class="setting-group">
                        <div class="checkbox-group">
                            <label>
                          <input type="checkbox" id="toggle-submit-button" <?php echo $surveySettings['show_submit_button'] ? 'checked' : ''; ?>> Show Submit Button                        </label>
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
    // Pass the survey type from PHP to JavaScript
    const surveyType = "<?php echo $survey['type']; ?>"; // IMPORTANT: Get the actual type from PHP
    const surveyId = "<?php echo $surveyId; ?>"; // Pass surveyId for saving

    document.addEventListener('DOMContentLoaded', function() {
        // --- 1. DOM Element References ---
        // Grouping related elements for clarity

        // Branding & Appearance
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

        // Survey Content
        const editTitle = document.getElementById('edit-title');
        const surveyTitle = document.getElementById('survey-title');
        const toggleTitle = document.getElementById('toggle-title');

        const editSubheading = document.getElementById('edit-subheading');
        const surveySubheading = document.getElementById('survey-subheading');
        const toggleSubheading = document.getElementById('toggle-subheading');

        const editRatingInstruction1 = document.getElementById('edit-rating-instruction-1'); // Can be null for DHIS2
        const ratingInstruction1 = document.getElementById('rating-instruction-1'); // Can be null for DHIS2
        const editRatingInstruction2 = document.getElementById('edit-rating-instruction-2'); // Can be null for DHIS2
        const ratingInstruction2 = document.getElementById('rating-instruction-2'); // Can be null for DHIS2
        const toggleRatingInstructions = document.getElementById('toggle-rating-instructions'); // Can be null for DHIS2
        const ratingInstructionsControlGroup = document.getElementById('rating-instructions-control-group'); // Control panel group


        // Form Sections Visibility
        const formSectionsAccordionItem = document.getElementById('form-sections-accordion-item'); // Control panel accordion item
        const toggleFacilitySection = document.getElementById('toggle-facility-section'); // Can be null for DHIS2
        const facilitySection = document.getElementById('facility-section'); // Can be null for DHIS2

        const toggleLocationRowGeneral = document.getElementById('toggle-location-row-general'); // Can be null for DHIS2
        const locationRowGeneral = document.getElementById('location-row-general'); // Can be null for DHIS2

        const toggleLocationRowPeriodAge = document.getElementById('toggle-location-row-period-age'); // Can be null for DHIS2
        const locationRowPeriodAge = document.getElementById('location-row-period-age'); // Can be null for DHIS2

        const toggleOwnershipSection = document.getElementById('toggle-ownership-section'); // Can be null for DHIS2
        const ownershipSection = document.getElementById('ownership-section'); // Can be null for DHIS2

        const toggleSubmitButton = document.getElementById('toggle-submit-button');
        const submitButtonPreview = document.getElementById('submit-button-preview');

        // Share Page Settings
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


        // --- 2. Helper Functions ---

        /**
         * Displays a toast notification.
         * @param {string} message - The message to display.
         * @param {string} type - 'success' or 'error' to determine color and duration.
         */
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
            }, type === 'success' ? 1800 : 3000); // Shorter for success, longer for error
        }

        // --- 3. Core Logic Functions ---

        /**
         * Applies conditional visibility to control panel sections and preview elements
         * based on survey type. This ensures DHIS2-specific fields are hidden.
         */
        function applyTypeSpecificControls() {
            if (surveyType === 'dhis2') {
                // Hide relevant groups in the control panel for DHIS2 surveys
                if (formSectionsAccordionItem) formSectionsAccordionItem.classList.add('hidden-element');
                if (ratingInstructionsControlGroup) ratingInstructionsControlGroup.classList.add('hidden-element');

                // Ensure the preview elements themselves are also hidden for DHIS2
                if (facilitySection) facilitySection.classList.add('hidden-element');
                if (locationRowGeneral) locationRowGeneral.classList.add('hidden-element');
                if (locationRowPeriodAge) locationRowPeriodAge.classList.add('hidden-element');
                if (ownershipSection) ownershipSection.classList.add('hidden-element');
                if (ratingInstruction1) ratingInstruction1.classList.add('hidden-element');
                if (ratingInstruction2) ratingInstruction2.classList.add('hidden-element');
            } else if (surveyType === 'local') {
                // Ensure they are visible in control panel for 'local' surveys
                if (formSectionsAccordionItem) formSectionsAccordionItem.classList.remove('hidden-element');
                if (ratingInstructionsControlGroup) ratingInstructionsControlGroup.classList.remove('hidden-element');
                // Visibility of preview elements is managed by initial PHP render and save/load logic.
            }
        }

        /**
         * Gathers all current preview and share page settings from the DOM
         * and sends them to the database via AJAX.
         */
        window.savePreviewSettings = async function() {
            // Collect settings from the DOM elements
            const settings = {
                surveyId: surveyId,
                logoSrc: logoImg.src, // This will be a Data URL for new uploads or a path for existing
                showLogo: toggleLogo.checked,
                flagBlackColor: flagBlackColorPicker.value,
                flagYellowColor: flagYellowColorPicker.value,
                flagRedColor: flagRedColorPicker.value,
                showFlagBar: toggleFlagBar.checked,
                titleText: editTitle.value,
                showTitle: toggleTitle.checked,
                subheadingText: editSubheading.value,
                showSubheading: toggleSubheading.checked,
                showSubmitButton: toggleSubmitButton.checked,

                // Conditional checks for elements that might not exist based on surveyType
                ratingInstruction1Text: editRatingInstruction1 ? editRatingInstruction1.value : '',
                ratingInstruction2Text: editRatingInstruction2 ? editRatingInstruction2.value : '',
                showRatingInstructions: toggleRatingInstructions ? toggleRatingInstructions.checked : false,
                showFacilitySection: toggleFacilitySection ? toggleFacilitySection.checked : false,
                showLocationRowGeneral: toggleLocationRowGeneral ? toggleLocationRowGeneral.checked : false,
                showLocationRowPeriodAge: toggleLocationRowPeriodAge ? toggleLocationRowPeriodAge.checked : false,
                showOwnershipSection: toggleOwnershipSection ? toggleOwnershipSection.checked : false,

                // Share Page Settings
                republicTitleText: editRepublicTitleShare.value,
                showRepublicTitleShare: toggleRepublicTitleShare.checked,
                ministrySubtitleText: editMinistrySubtitleShare.value,
                showMinistrySubtitleShare: toggleMinistrySubtitleShare.checked,
                qrInstructionsText: editQrInstructionsShare.value,
                showQrInstructionsShare: toggleQrInstructionsShare.checked,
                footerNoteText: editFooterNoteShare.value,
                showFooterNoteShare: toggleFooterNoteShare.checked,
                // Note: logoUrl and toggleLogoUrl are implicitly handled by logoSrc and showLogo
                // We map toggleLogoUrl to showLogo for consistency with the DB column `show_logo`
                // If you need separate control for share page logo, a new DB column would be required.
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
        return; // User cancelled
    }

    try {
        // Send a POST request to the new reset endpoint
        const response = await fetch('reset_survey_settings.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded', // Standard form data
                'X-Requested-With': 'XMLHttpRequest'
            },
            // Send survey_id as URL-encoded form data
            body: `survey_id=${encodeURIComponent(surveyId)}`
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.message || 'Failed to reset settings due to a server error.');
        }

        // Show success toast
        showToast(data.message, 'success');

        // Wait a moment for the toast to be seen, then reload the page
        setTimeout(() => {
            location.reload();
        }, 1000); // Reload after 1 second

    } catch (error) {
        console.error('Error resetting settings:', error);
        showToast(error.message || 'An unexpected error occurred while resetting.', 'error');
    }
};

        // --- 4. Event Listeners for Live Preview Updates ---

        // Logo upload and URL input sync
        logoUpload.addEventListener('change', function(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    logoImg.src = e.target.result;
                    logoUrlInput.value = e.target.result; // Update URL input for share settings preview
                };
                reader.readAsDataURL(file);
            }
        });

        // Toggle Logo visibility
        toggleLogo.addEventListener('change', function() {
            logoSection.classList.toggle('hidden-element', !this.checked);
            // Also update the share page logo URL input checkbox if it's supposed to sync
            if (toggleLogoUrl) toggleLogoUrl.checked = this.checked;
        });
        if (toggleLogoUrl) { // Sync toggleLogoUrl with toggleLogo if they are meant to be linked
            toggleLogoUrl.addEventListener('change', function() {
                if (toggleLogo) toggleLogo.checked = this.checked;
                logoSection.classList.toggle('hidden-element', !this.checked);
            });
        }


        // Flag Bar Color Pickers
        flagBlackColorPicker.addEventListener('input', function() { flagBlackElement.style.backgroundColor = this.value; });
        flagYellowColorPicker.addEventListener('input', function() { flagYellowElement.style.backgroundColor = this.value; });
        flagRedColorPicker.addEventListener('input', function() { flagRedElement.style.backgroundColor = this.value; });
        toggleFlagBar.addEventListener('change', function() { flagBarElement.classList.toggle('hidden-element', !this.checked); });

        // Survey Title
        editTitle.addEventListener('input', function() { surveyTitle.textContent = this.value; });
        toggleTitle.addEventListener('change', function() { surveyTitle.classList.toggle('hidden-element', !this.checked); });

        // Survey Subheading
        editSubheading.addEventListener('input', function() { surveySubheading.textContent = this.value; });
        toggleSubheading.addEventListener('change', function() { surveySubheading.classList.toggle('hidden-element', !this.checked); });

        // Rating Instructions (only if applicable for local surveys)
        if (surveyType === 'local') {
            if (editRatingInstruction1) editRatingInstruction1.addEventListener('input', function() { ratingInstruction1.textContent = this.value; });
            if (editRatingInstruction2) editRatingInstruction2.addEventListener('input', function() { ratingInstruction2.textContent = this.value; });
            if (toggleRatingInstructions) toggleRatingInstructions.addEventListener('change', function() {
                if (ratingInstruction1) ratingInstruction1.classList.toggle('hidden-element', !this.checked);
                if (ratingInstruction2) ratingInstruction2.classList.toggle('hidden-element', !this.checked);
            });
        }

        // Section Visibility Toggles (only if applicable for local surveys)
        if (surveyType === 'local') {
            if (toggleFacilitySection) toggleFacilitySection.addEventListener('change', function() { if (facilitySection) facilitySection.classList.toggle('hidden-element', !this.checked); });
            if (toggleLocationRowGeneral) toggleLocationRowGeneral.addEventListener('change', function() { if (locationRowGeneral) locationRowGeneral.classList.toggle('hidden-element', !this.checked); });
            if (toggleLocationRowPeriodAge) toggleLocationRowPeriodAge.addEventListener('change', function() { if (locationRowPeriodAge) locationRowPeriodAge.classList.toggle('hidden-element', !this.checked); });
            if (toggleOwnershipSection) toggleOwnershipSection.addEventListener('change', function() { if (ownershipSection) ownershipSection.classList.toggle('hidden-element', !this.checked); });
        }

        toggleSubmitButton.addEventListener('change', function() { submitButtonPreview.classList.toggle('hidden-element', !this.checked); });

        // Share Page Element Listeners (for their preview on this page)
        // These don't have direct live preview elements on *this* page that change content,
        // but their visibility toggles affect how they *would* appear on the share page.
        // We ensure their corresponding elements in the preview are toggled if they exist.
        editRepublicTitleShare.addEventListener('input', function() { republicTitleElement.textContent = this.value; });
        toggleRepublicTitleShare.addEventListener('change', function() { republicTitleElement.classList.toggle('hidden-element', !this.checked); });
        editMinistrySubtitleShare.addEventListener('input', function() { ministrySubtitleElement.textContent = this.value; });
        toggleMinistrySubtitleShare.addEventListener('change', function() { ministrySubtitleElement.classList.toggle('hidden-element', !this.checked); });
        // editQrInstructionsShare & editFooterNoteShare don't have corresponding elements on preview_form.php
        // but their values are still collected and sent to DB.
        // The toggles for these (toggleQrInstructionsShare, toggleFooterNoteShare) control visibility
        // on the share page itself, not this preview page.

        // --- 5. Accordion Logic ---
        const accordionHeaders = document.querySelectorAll('.accordion-header');
        accordionHeaders.forEach(header => {
            header.addEventListener('click', function() {
                const content = this.nextElementSibling;
                const icon = this.querySelector('i');

                // Close other open accordions
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

        // Apply type-specific visibility first (e.g., hide DHIS2-only sections)
        applyTypeSpecificControls();

        // The loadPreviewSettings function from previous steps primarily loaded from localStorage.
        // Now, PHP handles the initial rendering of settings directly from the DB.
        // This function can be simplified or removed, as its original purpose is fulfilled by PHP.
        // If you need it to re-apply states after some client-side manipulation, you'd modify it.
        // For now, it will simply ensure existing elements visibility matches the DOM state from PHP.
        window.loadPreviewSettings = function() {
            // Re-apply visibility based on the initial PHP-rendered checked states
            logoSection.classList.toggle('hidden-element', !toggleLogo.checked);
            flagBarElement.classList.toggle('hidden-element', !toggleFlagBar.checked);
            surveyTitle.classList.toggle('hidden-element', !toggleTitle.checked);
            surveySubheading.classList.toggle('hidden-element', !toggleSubheading.checked);

            if (surveyType === 'local') {
                if (ratingInstruction1) ratingInstruction1.classList.toggle('hidden-element', !toggleRatingInstructions.checked);
                if (ratingInstruction2) ratingInstruction2.classList.toggle('hidden-element', !toggleRatingInstructions.checked);
                if (facilitySection) facilitySection.classList.toggle('hidden-element', !toggleFacilitySection.checked);
                if (locationRowGeneral) locationRowGeneral.classList.toggle('hidden-element', !toggleLocationRowGeneral.checked);
                if (locationRowPeriodAge) locationRowPeriodAge.classList.toggle('hidden-element', !toggleLocationRowPeriodAge.checked);
                if (ownershipSection) ownershipSection.classList.toggle('hidden-element', !toggleOwnershipSection.checked);
            }

            submitButtonPreview.classList.toggle('hidden-element', !toggleSubmitButton.checked);
            republicTitleElement.classList.toggle('hidden-element', !toggleRepublicTitleShare.checked);
            ministrySubtitleElement.classList.toggle('hidden-element', !toggleMinistrySubtitleShare.checked);
        };
        window.loadPreviewSettings(); // Call on initial load to set visibility based on PHP's initial checked states


        // Share Button functionality: Save settings, then redirect
        document.getElementById('share-btn').addEventListener('click', async function() {
            // Await the save operation to complete before redirecting
            await window.savePreviewSettings();

            // Construct the URL for the survey page (QR code generation page)
            const surveyUrl = window.location.origin + '/fbs/admin/survey_page.php?survey_id=' + surveyId;

            // Redirect to share_page.php, passing the constructed surveyUrl
            window.location.href = `share_page.php?survey_id=${surveyId}&url=${encodeURIComponent(surveyUrl)}`;
        });
    });
</script>

<script defer src="survey_page.js"></script>
<!-- <script defer src="translations.js"></script> -->
</body>
</html>