<?php
/**
 * Survey Form Renderer with Skip Logic Support
 * Renders survey forms with conditional display and option filtering
 */

require_once 'skip_logic_helper.php';

/**
 * Render a complete survey form with skip logic
 * @param int $surveyId Survey ID
 * @param PDO $pdo Database connection
 * @param array $options Rendering options
 * @return string HTML output
 */
function renderSurveyForm($surveyId, $pdo, $options = []) {
    $defaults = [
        'form_id' => 'survey_form_' . $surveyId,
        'form_class' => 'survey-form',
        'include_skip_logic' => true,
        'show_required_indicator' => true,
        'response_data' => [] // For pre-filling values
    ];
    
    $options = array_merge($defaults, $options);
    
    // Get survey settings for numbering
    $settingsStmt = $pdo->prepare("SELECT show_numbering, numbering_style FROM survey_settings WHERE survey_id = ?");
    $settingsStmt->execute([$surveyId]);
    $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
    
    $options['show_numbering'] = $settings['show_numbering'] ?? true;
    $options['numbering_style'] = $settings['numbering_style'] ?? 'numeric';
    
    // Get survey questions
    $stmt = $pdo->prepare("
        SELECT 
            q.id,
            q.label,
            q.question_type,
            q.is_required,
            q.translations,
            q.option_set_id,
            q.validation_rules,
            q.skip_logic,
            q.min_selections,
            q.max_selections,
            sq.position
        FROM question q
        JOIN survey_question sq ON q.id = sq.question_id
        WHERE sq.survey_id = ?
        ORDER BY sq.position ASC
    ");
    $stmt->execute([$surveyId]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($questions)) {
        return '<div class="alert alert-warning">No questions found for this survey.</div>';
    }
    
    $html = '<form id="' . htmlspecialchars($options['form_id']) . '" class="' . htmlspecialchars($options['form_class']) . '">';
    
    foreach ($questions as $index => $question) {
        $options['question_number'] = $index + 1;
        $html .= renderQuestion($question, $pdo, $options);
    }
    
    $html .= '</form>';
    
    // Add CSS for question numbering
    if ($options['show_numbering'] && $options['numbering_style'] !== 'none') {
        $html .= '<style>
            .question-number {
                display: inline-block;
                background: #6c757d;
                color: white;
                border-radius: 4px;
                width: 24px;
                height: 20px;
                text-align: center;
                font-size: 0.75rem;
                font-weight: 600;
                line-height: 20px;
                margin-right: 8px;
            }
        </style>';
    }
    
    // Add skip logic JavaScript if enabled
    if ($options['include_skip_logic']) {
        $html .= '<script>' . generateSkipLogicJS($surveyId, $pdo) . '</script>';
    }
    
    return $html;
}

/**
 * Render a single question
 * @param array $question Question data
 * @param PDO $pdo Database connection
 * @param array $options Rendering options
 * @return string HTML output
 */
function renderQuestion($question, $pdo, $options = []) {
    $questionId = $question['id'];
    $questionType = $question['question_type'];
    $label = htmlspecialchars($question['label']);
    $isRequired = $question['is_required'];
    $validationRules = json_decode($question['validation_rules'] ?? '{}', true);
    $skipLogic = json_decode($question['skip_logic'] ?? '[]', true);
    $responseValue = $options['response_data'][$questionId] ?? null;
    
    $requiredIndicator = ($isRequired && $options['show_required_indicator']) ? ' <span class="text-danger">*</span>' : '';
    $requiredAttr = $isRequired ? ' required' : '';
    
    // Question container with data attributes for skip logic
    $html = '<div class="question-container mb-4" data-question-id="' . $questionId . '" data-question-type="' . $questionType . '">';
    
    // Question label with optional numbering
    $html .= '<div class="question-label mb-2">';
    $questionNumberDisplay = '';
    
    if ($options['show_numbering'] && $options['numbering_style'] !== 'none') {
        $questionNumber = $options['question_number'] ?? 1;
        $questionNumberDisplay = '<span class="question-number me-2">' . formatQuestionNumber($questionNumber, $options['numbering_style']) . '</span>';
    }
    
    $html .= '<label class="form-label fw-bold">' . $questionNumberDisplay . $label . $requiredIndicator . '</label>';
    $html .= '</div>';
    
    // Question input based on type
    $html .= '<div class="question-input">';
    
    switch ($questionType) {
        case 'text':
        case 'email':
        case 'phone':
        case 'url':
            $html .= renderTextInput($questionId, $questionType, $validationRules, $responseValue, $requiredAttr);
            break;
            
        case 'textarea':
            $html .= renderTextarea($questionId, $validationRules, $responseValue, $requiredAttr);
            break;
            
        case 'number':
        case 'integer':
        case 'decimal':
        case 'percentage':
            $html .= renderNumberInput($questionId, $questionType, $validationRules, $responseValue, $requiredAttr);
            break;
            
        case 'date':
        case 'datetime':
        case 'time':
            $html .= renderDateInput($questionId, $questionType, $validationRules, $responseValue, $requiredAttr);
            break;
            
        case 'select':
            $html .= renderSelectInput($questionId, $question, $pdo, $responseValue, $requiredAttr);
            break;
            
        case 'radio':
            $html .= renderRadioInput($questionId, $question, $pdo, $responseValue, $requiredAttr);
            break;
            
        case 'checkbox':
            $html .= renderCheckboxInput($questionId, $question, $pdo, $responseValue, $requiredAttr);
            break;
            
        case 'rating':
        case 'likert_scale':
        case 'star_rating':
            $html .= renderRatingInput($questionId, $questionType, $validationRules, $responseValue, $requiredAttr);
            break;
            
        case 'file_upload':
            $html .= renderFileInput($questionId, $validationRules, $requiredAttr);
            break;
            
        default:
            $html .= '<div class="alert alert-warning">Unsupported question type: ' . htmlspecialchars($questionType) . '</div>';
    }
    
    $html .= '</div>';
    
    // Add validation message container
    $html .= '<div class="invalid-feedback" id="feedback_' . $questionId . '"></div>';
    
    $html .= '</div>'; // Close question-container
    
    return $html;
}

function renderTextInput($questionId, $type, $validationRules, $value, $requiredAttr) {
    $inputType = $type === 'text' ? 'text' : $type;
    $attrs = [
        'type="' . $inputType . '"',
        'id="q_' . $questionId . '"',
        'name="q_' . $questionId . '"',
        'class="form-control"',
        'value="' . htmlspecialchars($value ?? '') . '"'
    ];
    
    if ($requiredAttr) $attrs[] = 'required';
    
    if (!empty($validationRules['min_length'])) {
        $attrs[] = 'minlength="' . $validationRules['min_length'] . '"';
    }
    if (!empty($validationRules['max_length'])) {
        $attrs[] = 'maxlength="' . $validationRules['max_length'] . '"';
    }
    
    return '<input ' . implode(' ', $attrs) . '>';
}

function renderTextarea($questionId, $validationRules, $value, $requiredAttr) {
    $attrs = [
        'id="q_' . $questionId . '"',
        'name="q_' . $questionId . '"',
        'class="form-control"',
        'rows="3"'
    ];
    
    if ($requiredAttr) $attrs[] = 'required';
    
    if (!empty($validationRules['min_length'])) {
        $attrs[] = 'minlength="' . $validationRules['min_length'] . '"';
    }
    if (!empty($validationRules['max_length'])) {
        $attrs[] = 'maxlength="' . $validationRules['max_length'] . '"';
    }
    
    return '<textarea ' . implode(' ', $attrs) . '>' . htmlspecialchars($value ?? '') . '</textarea>';
}

function renderNumberInput($questionId, $type, $validationRules, $value, $requiredAttr) {
    $inputType = 'number';
    $step = 'any';
    
    if ($type === 'integer') {
        $step = '1';
    } elseif ($type === 'decimal' && !empty($validationRules['decimals'])) {
        $step = '0.' . str_repeat('0', $validationRules['decimals'] - 1) . '1';
    }
    
    $attrs = [
        'type="' . $inputType . '"',
        'id="q_' . $questionId . '"',
        'name="q_' . $questionId . '"',
        'class="form-control"',
        'step="' . $step . '"',
        'value="' . htmlspecialchars($value ?? '') . '"'
    ];
    
    if ($requiredAttr) $attrs[] = 'required';
    
    if (!empty($validationRules['min'])) {
        $attrs[] = 'min="' . $validationRules['min'] . '"';
    }
    if (!empty($validationRules['max'])) {
        $attrs[] = 'max="' . $validationRules['max'] . '"';
    }
    
    return '<input ' . implode(' ', $attrs) . '>';
}

function renderDateInput($questionId, $type, $validationRules, $value, $requiredAttr) {
    $inputType = $type;
    if ($type === 'datetime') {
        $inputType = 'datetime-local';
    }
    
    $attrs = [
        'type="' . $inputType . '"',
        'id="q_' . $questionId . '"',
        'name="q_' . $questionId . '"',
        'class="form-control"',
        'value="' . htmlspecialchars($value ?? '') . '"'
    ];
    
    if ($requiredAttr) $attrs[] = 'required';
    
    if (!empty($validationRules['min_date'])) {
        $attrs[] = 'min="' . $validationRules['min_date'] . '"';
    }
    if (!empty($validationRules['max_date'])) {
        $attrs[] = 'max="' . $validationRules['max_date'] . '"';
    }
    
    return '<input ' . implode(' ', $attrs) . '>';
}

function renderSelectInput($questionId, $question, $pdo, $value, $requiredAttr) {
    $optionSetId = $question['option_set_id'];
    if (!$optionSetId) {
        return '<div class="alert alert-warning">No options configured for this question.</div>';
    }
    
    $stmt = $pdo->prepare("SELECT option_value FROM option_set_values WHERE option_set_id = ? ORDER BY id");
    $stmt->execute([$optionSetId]);
    $options = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $attrs = [
        'id="q_' . $questionId . '"',
        'name="q_' . $questionId . '"',
        'class="form-select"'
    ];
    
    if ($requiredAttr) $attrs[] = 'required';
    
    $html = '<select ' . implode(' ', $attrs) . '>';
    $html .= '<option value="">Choose an option...</option>';
    
    foreach ($options as $option) {
        $selected = ($value === $option) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($option) . '"' . $selected . '>' . htmlspecialchars($option) . '</option>';
    }
    
    $html .= '</select>';
    
    return $html;
}

function renderRadioInput($questionId, $question, $pdo, $value, $requiredAttr) {
    $optionSetId = $question['option_set_id'];
    if (!$optionSetId) {
        return '<div class="alert alert-warning">No options configured for this question.</div>';
    }
    
    $stmt = $pdo->prepare("SELECT option_value FROM option_set_values WHERE option_set_id = ? ORDER BY id");
    $stmt->execute([$optionSetId]);
    $options = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $html = '<div class="radio-group">';
    
    foreach ($options as $index => $option) {
        $optionId = 'q_' . $questionId . '_' . $index;
        $checked = ($value === $option) ? ' checked' : '';
        
        $html .= '<div class="form-check option-item">';
        $html .= '<input type="radio" id="' . $optionId . '" name="q_' . $questionId . '" value="' . htmlspecialchars($option) . '" class="form-check-input"' . $checked . ($requiredAttr ? ' required' : '') . '>';
        $html .= '<label for="' . $optionId . '" class="form-check-label">' . htmlspecialchars($option) . '</label>';
        $html .= '</div>';
    }
    
    $html .= '</div>';
    
    return $html;
}

function renderCheckboxInput($questionId, $question, $pdo, $value, $requiredAttr) {
    $optionSetId = $question['option_set_id'];
    if (!$optionSetId) {
        return '<div class="alert alert-warning">No options configured for this question.</div>';
    }
    
    $stmt = $pdo->prepare("SELECT option_value FROM option_set_values WHERE option_set_id = ? ORDER BY id");
    $stmt->execute([$optionSetId]);
    $options = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $selectedValues = is_array($value) ? $value : ($value ? [$value] : []);
    
    $html = '<div class="checkbox-group">';
    
    foreach ($options as $index => $option) {
        $optionId = 'q_' . $questionId . '_' . $index;
        $checked = in_array($option, $selectedValues) ? ' checked' : '';
        
        $html .= '<div class="form-check option-item">';
        $html .= '<input type="checkbox" id="' . $optionId . '" name="q_' . $questionId . '[]" value="' . htmlspecialchars($option) . '" class="form-check-input"' . $checked . '>';
        $html .= '<label for="' . $optionId . '" class="form-check-label">' . htmlspecialchars($option) . '</label>';
        $html .= '</div>';
    }
    
    $html .= '</div>';
    
    // Add validation for min/max selections
    if (!empty($question['min_selections']) || !empty($question['max_selections'])) {
        $html .= '<script>';
        $html .= 'document.addEventListener("DOMContentLoaded", function() {';
        $html .= '  const checkboxes = document.querySelectorAll(\'input[name="q_' . $questionId . '[]"]\');';
        $html .= '  checkboxes.forEach(cb => {';
        $html .= '    cb.addEventListener("change", function() {';
        $html .= '      const checked = document.querySelectorAll(\'input[name="q_' . $questionId . '[]"]:checked\');';
        
        if (!empty($question['min_selections'])) {
            $html .= '      if (checked.length < ' . $question['min_selections'] . ') {';
            $html .= '        this.setCustomValidity("Please select at least ' . $question['min_selections'] . ' option(s)");';
            $html .= '      } else { this.setCustomValidity(""); }';
        }
        
        if (!empty($question['max_selections'])) {
            $html .= '      if (checked.length > ' . $question['max_selections'] . ') {';
            $html .= '        this.checked = false;';
            $html .= '        alert("You can select maximum ' . $question['max_selections'] . ' option(s)");';
            $html .= '      }';
        }
        
        $html .= '    });';
        $html .= '  });';
        $html .= '});';
        $html .= '</script>';
    }
    
    return $html;
}

function renderRatingInput($questionId, $type, $validationRules, $value, $requiredAttr) {
    $range = $validationRules['scale_range'] ?? '1-5';
    $rangeParts = explode('-', $range);
    $min = (int)($rangeParts[0] ?? 1);
    $max = (int)($rangeParts[1] ?? 5);
    
    $lowLabel = $validationRules['low_label'] ?? 'Low';
    $highLabel = $validationRules['high_label'] ?? 'High';
    
    if ($type === 'star_rating') {
        return renderStarRating($questionId, $min, $max, $value, $requiredAttr);
    }
    
    $html = '<div class="rating-group">';
    $html .= '<div class="d-flex justify-content-between mb-2">';
    $html .= '<small class="text-muted">' . htmlspecialchars($lowLabel) . '</small>';
    $html .= '<small class="text-muted">' . htmlspecialchars($highLabel) . '</small>';
    $html .= '</div>';
    
    $html .= '<div class="rating-options d-flex gap-3">';
    for ($i = $min; $i <= $max; $i++) {
        $checked = ($value == $i) ? ' checked' : '';
        $html .= '<div class="form-check">';
        $html .= '<input type="radio" id="q_' . $questionId . '_' . $i . '" name="q_' . $questionId . '" value="' . $i . '" class="form-check-input"' . $checked . ($requiredAttr ? ' required' : '') . '>';
        $html .= '<label for="q_' . $questionId . '_' . $i . '" class="form-check-label">' . $i . '</label>';
        $html .= '</div>';
    }
    $html .= '</div>';
    
    $html .= '</div>';
    
    return $html;
}

function renderStarRating($questionId, $min, $max, $value, $requiredAttr) {
    $html = '<div class="star-rating" data-rating="' . htmlspecialchars($value ?? '') . '">';
    $html .= '<input type="hidden" id="q_' . $questionId . '" name="q_' . $questionId . '" value="' . htmlspecialchars($value ?? '') . '"' . ($requiredAttr ? ' required' : '') . '>';
    
    for ($i = 1; $i <= $max; $i++) {
        $filled = ($value >= $i) ? ' filled' : '';
        $html .= '<span class="star' . $filled . '" data-value="' . $i . '">★</span>';
    }
    
    $html .= '</div>';
    
    $html .= '<style>
        .star-rating { font-size: 2rem; cursor: pointer; }
        .star { color: #ddd; transition: color 0.2s; }
        .star:hover, .star.filled { color: #ffc107; }
        .star-rating:hover .star:hover ~ .star { color: #ddd; }
    </style>';
    
    $html .= '<script>
        document.addEventListener("DOMContentLoaded", function() {
            const rating = document.querySelector("[data-question-id=\'' . $questionId . '\'] .star-rating");
            const input = document.getElementById("q_' . $questionId . '");
            const stars = rating.querySelectorAll(".star");
            
            stars.forEach((star, index) => {
                star.addEventListener("click", function() {
                    const value = this.getAttribute("data-value");
                    input.value = value;
                    updateStars(value);
                });
                
                star.addEventListener("mouseover", function() {
                    const value = this.getAttribute("data-value");
                    updateStars(value, true);
                });
            });
            
            rating.addEventListener("mouseleave", function() {
                updateStars(input.value);
            });
            
            function updateStars(value, isHover = false) {
                stars.forEach((star, index) => {
                    if (index < value) {
                        star.classList.add("filled");
                    } else {
                        star.classList.remove("filled");
                    }
                });
            }
        });
    </script>';
    
    return $html;
}

function renderFileInput($questionId, $validationRules, $requiredAttr) {
    $attrs = [
        'type="file"',
        'id="q_' . $questionId . '"',
        'name="q_' . $questionId . '"',
        'class="form-control"'
    ];
    
    if ($requiredAttr) $attrs[] = 'required';
    
    if (!empty($validationRules['file_types'])) {
        $accept = '.' . str_replace(',', ',.', $validationRules['file_types']);
        $attrs[] = 'accept="' . $accept . '"';
    }
    
    $html = '<input ' . implode(' ', $attrs) . '>';
    
    if (!empty($validationRules['max_size'])) {
        $html .= '<small class="form-text text-muted">Maximum file size: ' . $validationRules['max_size'] . 'MB</small>';
    }
    
    return $html;
}

/**
 * Format question number based on numbering style
 * @param int $number Question number
 * @param string $style Numbering style
 * @return string Formatted number
 */
function formatQuestionNumber($number, $style) {
    switch ($style) {
        case 'alphabetic_lower':
            return chr(96 + $number) . '.';
        case 'alphabetic_upper':
            return chr(64 + $number) . '.';
        case 'roman_lower':
            return strtolower(toRomanNumeral($number)) . '.';
        case 'roman_upper':
            return toRomanNumeral($number) . '.';
        case 'numeric':
        default:
            return $number . '.';
    }
}

/**
 * Convert number to Roman numeral
 * @param int $number Number to convert
 * @return string Roman numeral
 */
function toRomanNumeral($number) {
    $values = [1000, 900, 500, 400, 100, 90, 50, 40, 10, 9, 5, 4, 1];
    $symbols = ['M', 'CM', 'D', 'CD', 'C', 'XC', 'L', 'XL', 'X', 'IX', 'V', 'IV', 'I'];
    $result = '';
    
    for ($i = 0; $i < count($values); $i++) {
        while ($number >= $values[$i]) {
            $result .= $symbols[$i];
            $number -= $values[$i];
        }
    }
    
    return $result;
}
?>