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

// Fetch survey details (id, type, name)
$surveyStmt = $conn->prepare("SELECT id, type, name FROM survey WHERE id = ?");
$surveyStmt->bind_param("i", $surveyId);
$surveyStmt->execute();
$surveyResult = $surveyStmt->get_result();
$survey = $surveyResult->fetch_assoc();
$surveyStmt->close();

if (!$survey) {
    die("Survey not found.");
}

// Determine survey type
$surveyType = $survey['type'] ?? 'local';

// Set default survey title
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
    $surveySettings = $existingSettings;
} else {
    // Fallback defaults if no settings exist (should have been created by preview_form.php)
    $surveySettings = [
        'logo_path' => 'asets/asets/img/loog.jpg',
        'show_logo' => 1,
        'flag_black_color' => '#000000',
        'flag_yellow_color' => '#FCD116',
        'flag_red_color' => '#D21034',
        'show_flag_bar' => 1,
        'title_text' => $defaultSurveyTitle,
        'show_title' => 1,
        'subheading_text' => $translations['subheading'] ?? 'This tool is used to obtain clients\' feedback about their experience with the services and promote quality improvement, accountability, and transparency within the healthcare system.',
        'show_subheading' => 1,
        'show_submit_button' => 1,
        'rating_instruction1_text' => $translations['rating_instruction'] ?? '1. Please rate each of the following parameters according to your experience today on a scale of 1 to 4.',
        'rating_instruction2_text' => $translations['rating_scale'] ?? 'where \'0\' means Poor, \'1\' Fair, \'2\' Good and \'3\' Excellent',
        'show_rating_instructions' => 1,
        'show_facility_section' => 1,
        'show_location_row_general' => 1,
        'show_location_row_period_age' => 1,
        'show_ownership_section' => 1,
        'republic_title_text' => 'THE REPUBLIC OF UGANDA',
        'show_republic_title_share' => 1,
        'ministry_subtitle_text' => 'MINISTRY OF HEALTH',
        'show_ministry_subtitle_share' => 1,
        'qr_instructions_text' => 'Scan this QR Code to Give Your Feedback on Services Received',
        'show_qr_instructions_share' => 1,
        'footer_note_text' => 'Thank you for helping us improve our services.',
        'show_footer_note_share' => 1,
    ];
}
$settingsStmt->close();


// echo '<pre style="background: lightyellow; padding: 10px; border: 1px solid orange;">';
// echo 'DEBUGGING $surveySettings in survey_page.php for survey_id=' . $surveyId . ':<br>';
// print_r($surveySettings);
// echo '</pre>';

// Fetch questions and options
$questions = $conn->query("
    SELECT q.id, q.label, q.question_type, q.is_required, q.translations, q.option_set_id, sq.position
    FROM question q
    JOIN survey_question sq ON q.id = sq.question_id
    WHERE sq.survey_id = $surveyId
    ORDER BY sq.position ASC
");

$questionsArray = [];
if ($questions) {
    while ($question = $questions->fetch_assoc()) {
        $question['options'] = [];
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

$conn->close(); // Close DB connection
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($surveySettings['title_text'] ?? $defaultSurveyTitle); ?></title>
    <link rel="stylesheet" href="../styles.css">
    <style>
        /* Your existing CSS (copied from your provided code) */
        body {
            font-family: Arial, sans-serif;
            background-color: #f8f9fa;
            margin: 0;
            padding: 0;
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

        /* Utility class for hiding elements */
        .hidden-element {
            display: none !important;
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


    </style>
</head>
<body>
  
    <div class="container" id="form-content">



        <div class="header-section" id="logo-section" style="display: <?php echo ($surveySettings['show_logo'] ?? true) ? 'block' : 'none'; ?>;">
            <div class="logo-container">
             <img id="moh-logo"
     src="<?php echo htmlspecialchars($surveySettings['logo_path'] ?? '/assets/img/logo.jpg'); ?>"
     alt="Ministry of Health Logo"
     style="background: #fff; border: 1px solid #ccc; padding: 8px; border-radius: 8px; max-width: 100%; height: 170px; object-fit: contain;"
     onerror="this.onerror=null;this.src='/assets/img/logo.jpg';">
            </div>
            <div class="title" id="republic-title" style="display: <?php echo ($surveySettings['show_republic_title_share'] ?? true) ? 'block' : 'none'; ?>;"><?php echo htmlspecialchars($surveySettings['republic_title_text'] ?? 'THE REPUBLIC OF UGANDA'); ?></div>
            <div class="subtitle" id="ministry-subtitle" style="display: <?php echo ($surveySettings['show_ministry_subtitle_share'] ?? true) ? 'block' : 'none'; ?>;"><?php echo htmlspecialchars($surveySettings['ministry_subtitle_text'] ?? 'MINISTRY OF HEALTH'); ?></div>
        </div>

        <div class="flag-bar" id="flag-bar" style="display: <?php echo ($surveySettings['show_flag_bar'] ?? true) ? 'flex' : 'none'; ?>;">
            <div class="flag-black" id="flag-black-color" style="background-color: <?php echo htmlspecialchars($surveySettings['flag_black_color'] ?? '#000000'); ?>;"></div>
            <div class="flag-yellow" id="flag-yellow-color" style="background-color: <?php echo htmlspecialchars($surveySettings['flag_yellow_color'] ?? '#FCD116'); ?>;"></div>
            <div class="flag-red" id="flag-red-color" style="background-color: <?php echo htmlspecialchars($surveySettings['flag_red_color'] ?? '#D21034'); ?>;"></div>
        </div>

        <h2 id="survey-title" data-translate="title" style="display: <?php echo ($surveySettings['show_title'] ?? true) ? 'block' : 'none'; ?>;"><?php echo htmlspecialchars($surveySettings['title_text'] ?? $defaultSurveyTitle); ?></h2>
        <h3 id="survey-subtitle" data-translate="client_satisfaction_tool"><?php echo $translations['client_satisfaction_tool'] ?? 'USER FEEDBACK TOOL'; ?></h3>
        <p class="subheading" id="survey-subheading" data-translate="subheading" style="display: <?php echo ($surveySettings['show_subheading'] ?? true) ? 'block' : 'none'; ?>;">
            <?php echo htmlspecialchars($surveySettings['subheading_text'] ?? $translations['subheading'] ?? 'This tool is used to obtain clients\' feedback about their experience with the services and promote quality improvement, accountability, and transparency within the healthcare system.'); ?>
        </p>

        <form action="survey_page_submit.php" method="POST" onsubmit="return validateForm()">
            <input type="hidden" name="survey_id" value="<?php echo htmlspecialchars($surveyId); ?>">
            <input type="hidden" name="submission_language" value="<?php echo htmlspecialchars($language); ?>">

            <div class="facility-section" id="facility-section" style="display: <?php echo ($surveySettings['show_facility_section'] ?? true) ? 'block' : 'none'; ?>;">
                <div class="form-group">
                    <label for="facility-search">Health Facility:</label>
                    <div class="searchable-dropdown">
                        <input type="text" id="facility-search" placeholder="Type to search facilities..." autocomplete="off">
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

                <div class="location-row" id="location-row-general" style="display: <?php echo ($surveySettings['show_location_row_general'] ?? true) ? 'flex' : 'none'; ?>;">
                    <div class="form-group">
                        <label for="serviceUnit" data-translate="service_unit"><?php echo $translations['service_unit'] ?? 'Service Unit'; ?>:</label>
                        <select id="serviceUnit" name="serviceUnit">
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

                <div class="location-row" id="location-row-period-age" style="display: <?php echo ($surveySettings['show_location_row_period_age'] ?? true) ? 'flex' : 'none'; ?>;">
                    <div class="reporting-period-container">
                        <label for="reporting_period" data-translate="reporting_period"><?php echo $translations['reporting_period'] ?? 'Present Day'; ?></label>
                        <input
                            type="date"
                            id="reporting_period"
                            name="reporting_period"
                            value="<?php echo date('Y-m-d'); ?>"
                            readonly
                            style="background-color: #f5f5f5; cursor: not-allowed;"
                        >
                        <span class="placeholder-text">Current date is automatically selected</span>
                    </div>

                    <div class="form-group" style="width: 400px; padding: 0px;">
                        <label for="age" data-translate="age"><?php echo $translations['age'] ?? 'Age'; ?>:</label>
                        <input
                            type="number"
                            id="age"
                            name="age"
                            min="14"
                            max="99"
                            onblur="this.value = Math.max(14, Math.min(99, parseInt(this.value) || ''))"
                            oninvalid="this.setCustomValidity('Please enter an age between 14 and 99')"
                            oninput="this.setCustomValidity('')"
                        >
                    </div>
                </div>

                <div class="radio-group" id="ownership-section" style="display: <?php echo ($surveySettings['show_ownership_section'] ?? true) ? 'block' : 'none'; ?>;">
                    <label class="radio-label" data-translate="ownership"><?php echo $translations['ownership'] ?? 'Ownership'; ?></label>
                    <div class="radio-options" id="ownership-options">
                    </div>
                </div>
                <p id="rating-instruction-1" data-translate="rating_instruction" style="display: <?php echo ($surveySettings['show_rating_instructions'] ?? true) ? 'block' : 'none'; ?>;"><?php echo htmlspecialchars($surveySettings['rating_instruction1_text'] ?? $translations['rating_instruction'] ?? '1. Please rate each of the following parameters according to your experience today on a scale of 1 to 4.'); ?></p>
                <p id="rating-instruction-2" data-translate="rating_scale" style="color: red; font-size: 12px; font-style: italic; display: <?php echo ($surveySettings['show_rating_instructions'] ?? true) ? 'block' : 'none'; ?>;"><?php echo htmlspecialchars($surveySettings['rating_instruction2_text'] ?? $translations['rating_scale'] ?? 'where \'0\' means Poor, \'1\' Fair, \'2\' Good and \'3\' Excellent'); ?></p>

            <?php endif; ?>
           <div id="validation-message" style="display:none; color: #fff; background: #e74c3c; padding: 10px; border-radius: 4px; margin-bottom: 15px;"></div>
           <?php foreach ($questionsArray as $index => $question): ?>
            <div class="form-group survey-question"
                 data-question-index="<?php echo $index; ?>"
                 style="display: none;">
                <div class="radio-label">
                    <span class="question-number"><?php echo ($index + 1) . '.'; ?></span>
                    <?php echo htmlspecialchars($question['label']); ?>
                    <?php if ($question['is_required']): ?>
                        <span class="required-indicator" style="color: red;">*</span>
                    <?php endif; ?>
                </div>
                <?php if ($question['question_type'] == 'radio'): ?>
                    <div class="radio-options" style="display: flex; flex-wrap: wrap; gap: 12px;">
                        <?php foreach ($question['options'] as $option): ?>
                           <div class="radio-option" style="flex: 1 1 220px; min-width: 180px;">
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
                    <div class="radio-options">
                       <div class="checkbox-options" style="display: flex; flex-wrap: wrap; gap: 12px;">
                        <?php foreach ($question['options'] as $option): ?>
                            <div class="checkbox-option" style="flex: 1 1 220px; min-width: 180px;">
                                <input type="checkbox"
                                       id="option_<?php echo $question['id']; ?>_<?php echo $option['id']; ?>"
                                       name="question_<?php echo $question['id']; ?>[]"
                                       value="<?php echo htmlspecialchars($option['option_value']); ?>"
                                       <?php echo $question['is_required'] ? 'required' : ''; ?>>
                                <label for="option_<?php echo $question['id']; ?>_<?php echo $option['id']; ?>">
                                    <?php echo htmlspecialchars($option['option_value']); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>


                <?php elseif ($question['question_type'] == 'select'): ?>
                    <select class="form-control" name="question_<?php echo $question['id']; ?>" style="width: 60%;" <?php echo $question['is_required'] ? 'required' : ''; ?>>
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
                           name="question_<?php echo $question['id']; ?>"
                           <?php echo $question['is_required'] ? 'required' : ''; ?>>
                <?php elseif ($question['question_type'] == 'textarea'): ?>
                    <textarea class="form-control"
                              name="question_<?php echo $question['id']; ?>"
                              rows="3"
                              style="width: 80%;"
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
<?php endif; ?>


            </div>
        <?php endforeach; ?>


    </div>

  <div class="pagination-controls">
    <button type="button" id="prev-page-btn" style="display: none;">Back</button>
    <button type="button" id="next-page-btn">Next</button>
    <button type="submit" id="submit-button-final" style="display: none;"><?php echo $translations['submit'] ?? 'Submit Feedback'; ?></button>
</div>
        </form>
    </div>

    <script>
        // No need for global variables that fetch elements initially, as PHP renders their style directly.
        // These are only needed for JS if you plan to dynamically change them *after* page load using JS.

        document.addEventListener('DOMContentLoaded', function() {
            // PHP has already applied all settings directly into the HTML's style attributes and content.
            // Therefore, there is no need for a JavaScript `applySavedSettings()` function or localStorage here.
            // The elements are rendered correctly on the server side.

            // Any elements that were conditionally hidden by PHP's style="display:none;" will be hidden.
            // Any elements that had their content set by PHP's echo will show that content.

            // --- Language switcher function (if you uncommented it) ---
            const languageSelect = document.getElementById('languageSelect');
            if (languageSelect) {
                languageSelect.addEventListener('change', function() {
                    var selectedLang = this.value;
                    window.location.href = "survey_page.php?survey_id=<?php echo $surveyId; ?>&language=" + selectedLang;
                });
            }

            // --- Print function (if you uncommented it) ---
            const printButton = document.getElementById('print-button');
            if (printButton) {
                printButton.addEventListener('click', function() {
                    window.print();
                });
            }


            // --- Form validation function ---
            // This function still relies on checking visibility based on current display style
            function validateForm() {
                const facilitySectionElement = document.getElementById('facility-section'); // Re-fetch or pass element
                const facilitySectionVisible = facilitySectionElement && facilitySectionElement.style.display !== 'none';
                const facilityId = document.getElementById('facility_id').value;

                if (facilitySectionVisible && !facilityId) {
                    alert('Please select a Health Facility.');
                    document.getElementById('facility-search').focus();
                    return false;
                }
                return true;
            }

            // --- Pagination logic for survey questions ---
            const QUESTIONS_PER_PAGE = 10;
            const questions = Array.from(document.querySelectorAll('.survey-question'));
            const totalQuestions = questions.length;
            const totalPages = Math.ceil(totalQuestions / QUESTIONS_PER_PAGE);

            let currentPage = 1;

            const prevBtn = document.getElementById('prev-page-btn');
            const nextBtn = document.getElementById('next-page-btn');
            const submitBtn = document.getElementById('submit-button-final');

            function showPage(page) {
                questions.forEach(q => q.style.display = 'none');
                const start = (page - 1) * QUESTIONS_PER_PAGE;
                const end = Math.min(start + QUESTIONS_PER_PAGE, totalQuestions);
                for (let i = start; i < end; i++) {
                    questions[i].style.display = '';
                }

                prevBtn.style.display = (page > 1) ? '' : 'none';
                nextBtn.style.display = (page < totalPages) ? '' : 'none';
                submitBtn.style.display = (page === totalPages) ? '' : 'none';
            }

            prevBtn.addEventListener('click', function() {
                if (currentPage > 1) {
                    currentPage--;
                    showPage(currentPage);
                    window.scrollTo(0, 0);
                }
            });

            nextBtn.addEventListener('click', function() {
                if (!validateCurrentPage()) return;
                if (currentPage < totalPages) {
                    currentPage++;
                    showPage(currentPage);
                    window.scrollTo(0, 0);
                }
            });

            /* --- Add this HTML where appropriate, e.g., above your survey form --- */



function showValidationMessage(msg) {
    const msgDiv = document.getElementById('validation-message');
    msgDiv.textContent = msg;
    msgDiv.style.display = 'block';
    // Optionally, auto-hide after a few seconds:
    setTimeout(() => { msgDiv.style.display = 'none'; }, 4000);
}

function validateCurrentPage() {
    // Hide previous message
    document.getElementById('validation-message').style.display = 'none';

    const visibleQuestions = questions.filter(q => q.style.display !== 'none');
    for (const q of visibleQuestions) {
        const requiredInputs = q.querySelectorAll('[required]');
        for (const input of requiredInputs) {
            if (input.type === 'radio' || input.type === 'checkbox') {
                const name = input.name;
                const group = q.querySelectorAll(`[name="${name}"]`);
                if (![...group].some(i => i.checked)) {
                    showValidationMessage('Please answer all required questions on this page.');
                    input.focus();
                    return false;
                }
            } else if (!input.value) {
                showValidationMessage('Please answer all required questions on this page.');
                input.focus();
                return false;
            }
        }
    }
    return true;
}


            document.querySelector('form').addEventListener('submit', function(e) {
                for (const q of questions) {
                    const requiredInputs = q.querySelectorAll('[required]');
                    for (const input of requiredInputs) {
                        if (input.type === 'radio' || input.type === 'checkbox') {
                            const name = input.name;
                            const group = q.querySelectorAll(`[name="${name}"]`);
                            if (![...group].some(i => i.checked)) {
                                alert('Please answer all required questions.');
                                input.focus();
                                e.preventDefault();
                                return false;
                            }
                        } else if (!input.value) {
                            alert('Please answer all required questions.');
                            input.focus();
                            e.preventDefault();
                            return false;
                        }
                    }
                }
                return true;
            });

            // Show the first page on load
            showPage(currentPage);

            // Star rating logic
            document.querySelectorAll('.star-rating').forEach(function(starRatingDiv) {
                const stars = Array.from(starRatingDiv.querySelectorAll('.star'));
                const input = starRatingDiv.querySelector('input[type="hidden"]');
                let selectedValue = 0;

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

    </script>

    <script defer src="./survey_page.js"></script>

    <script defer src="../translations.js"></script>
</body>
</html>