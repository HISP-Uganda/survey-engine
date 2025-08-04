<?php
/**
 * Skip Logic Helper Functions
 * Provides conditional question display and option filtering
 */

/**
 * Parse skip logic conditions
 * @param array $skipLogic Skip logic configuration
 * @return array Parsed conditions
 */
function parseSkipLogic($skipLogic) {
    if (empty($skipLogic) || !is_array($skipLogic)) {
        return [];
    }
    
    $conditions = [];
    
    foreach ($skipLogic as $rule) {
        if (isset($rule['trigger_question_id'], $rule['condition'], $rule['value'], $rule['action'])) {
            $conditions[] = [
                'trigger_question_id' => (int)$rule['trigger_question_id'],
                'condition' => $rule['condition'], // equals, not_equals, contains, greater_than, less_than, etc.
                'value' => $rule['value'],
                'action' => $rule['action'], // show, hide, filter_options
                'target' => $rule['target'] ?? null, // target question or options
            ];
        }
    }
    
    return $conditions;
}

/**
 * Generate skip logic JavaScript for frontend
 * @param int $surveyId Survey ID
 * @param PDO $pdo Database connection
 * @return string JavaScript code
 */
function generateSkipLogicJS($surveyId, $pdo) {
    // Get all questions with skip logic for this survey
    $stmt = $pdo->prepare("
        SELECT 
            q.id,
            q.label,
            q.question_type,
            q.skip_logic,
            sq.position
        FROM question q
        JOIN survey_question sq ON q.id = sq.question_id
        WHERE sq.survey_id = ? AND q.skip_logic IS NOT NULL
        ORDER BY sq.position
    ");
    $stmt->execute([$surveyId]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $js = "
    // Skip Logic System
    const skipLogicRules = {};
    const questionElements = {};
    
    // Initialize skip logic
    function initializeSkipLogic() {
        // Cache question elements
        document.querySelectorAll('[data-question-id]').forEach(el => {
            const questionId = el.getAttribute('data-question-id');
            questionElements[questionId] = el;
        });
        
        // Setup skip logic rules
    ";
    
    foreach ($questions as $question) {
        if (!empty($question['skip_logic'])) {
            $skipLogic = json_decode($question['skip_logic'], true);
            $conditions = parseSkipLogic($skipLogic);
            
            foreach ($conditions as $condition) {
                $js .= "
                addSkipLogicRule({
                    questionId: {$question['id']},
                    triggerQuestionId: {$condition['trigger_question_id']},
                    condition: '{$condition['condition']}',
                    value: " . json_encode($condition['value']) . ",
                    action: '{$condition['action']}',
                    target: " . json_encode($condition['target']) . "
                });
                ";
            }
        }
    }
    
    $js .= "
        // Apply initial skip logic
        applyAllSkipLogic();
        
        // Setup event listeners
        setupSkipLogicListeners();
    }
    
    function addSkipLogicRule(rule) {
        if (!skipLogicRules[rule.triggerQuestionId]) {
            skipLogicRules[rule.triggerQuestionId] = [];
        }
        skipLogicRules[rule.triggerQuestionId].push(rule);
    }
    
    function setupSkipLogicListeners() {
        Object.keys(skipLogicRules).forEach(triggerQuestionId => {
            const element = questionElements[triggerQuestionId];
            if (element) {
                const inputs = element.querySelectorAll('input, select, textarea');
                inputs.forEach(input => {
                    input.addEventListener('change', () => {
                        applySkipLogic(triggerQuestionId);
                    });
                });
            }
        });
    }
    
    function applySkipLogic(triggerQuestionId) {
        const rules = skipLogicRules[triggerQuestionId];
        if (!rules) return;
        
        const triggerValue = getQuestionValue(triggerQuestionId);
        
        rules.forEach(rule => {
            const conditionMet = evaluateCondition(triggerValue, rule.condition, rule.value);
            executeAction(rule, conditionMet);
        });
    }
    
    function applyAllSkipLogic() {
        Object.keys(skipLogicRules).forEach(triggerQuestionId => {
            applySkipLogic(triggerQuestionId);
        });
    }
    
    function getQuestionValue(questionId) {
        const element = questionElements[questionId];
        if (!element) return null;
        
        const inputs = element.querySelectorAll('input, select, textarea');
        const values = [];
        
        inputs.forEach(input => {
            if (input.type === 'checkbox' || input.type === 'radio') {
                if (input.checked) {
                    values.push(input.value);
                }
            } else {
                if (input.value) {
                    values.push(input.value);
                }
            }
        });
        
        return values.length === 1 ? values[0] : values;
    }
    
    function evaluateCondition(triggerValue, condition, expectedValue) {
        if (Array.isArray(triggerValue)) {
            triggerValue = triggerValue.join(',');
        }
        
        switch (condition) {
            case 'equals':
                return triggerValue == expectedValue;
            case 'not_equals':
                return triggerValue != expectedValue;
            case 'contains':
                return String(triggerValue).includes(String(expectedValue));
            case 'not_contains':
                return !String(triggerValue).includes(String(expectedValue));
            case 'greater_than':
                return parseFloat(triggerValue) > parseFloat(expectedValue);
            case 'less_than':
                return parseFloat(triggerValue) < parseFloat(expectedValue);
            case 'greater_equal':
                return parseFloat(triggerValue) >= parseFloat(expectedValue);
            case 'less_equal':
                return parseFloat(triggerValue) <= parseFloat(expectedValue);
            case 'is_empty':
                return !triggerValue || triggerValue === '';
            case 'is_not_empty':
                return triggerValue && triggerValue !== '';
            default:
                return false;
        }
    }
    
    function executeAction(rule, conditionMet) {
        const targetElement = questionElements[rule.questionId];
        if (!targetElement) return;
        
        switch (rule.action) {
            case 'show':
                if (conditionMet) {
                    showQuestion(rule.questionId);
                } else {
                    hideQuestion(rule.questionId);
                }
                break;
            case 'hide':
                if (conditionMet) {
                    hideQuestion(rule.questionId);
                } else {
                    showQuestion(rule.questionId);
                }
                break;
            case 'filter_options':
                if (conditionMet && rule.target) {
                    filterQuestionOptions(rule.questionId, rule.target);
                } else {
                    resetQuestionOptions(rule.questionId);
                }
                break;
        }
    }
    
    function showQuestion(questionId) {
        const element = questionElements[questionId];
        if (element) {
            element.style.display = '';
            element.classList.remove('d-none');
            // Re-enable form validation for this question
            const inputs = element.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                if (input.hasAttribute('data-original-required')) {
                    input.required = true;
                }
            });
        }
    }
    
    function hideQuestion(questionId) {
        const element = questionElements[questionId];
        if (element) {
            element.style.display = 'none';
            element.classList.add('d-none');
            // Clear values and disable validation
            const inputs = element.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                if (input.required) {
                    input.setAttribute('data-original-required', 'true');
                    input.required = false;
                }
                if (input.type === 'checkbox' || input.type === 'radio') {
                    input.checked = false;
                } else {
                    input.value = '';
                }
            });
        }
    }
    
    function filterQuestionOptions(questionId, allowedOptions) {
        const element = questionElements[questionId];
        if (!element) return;
        
        const selects = element.querySelectorAll('select');
        const checkboxes = element.querySelectorAll('input[type=\"checkbox\"]');
        const radios = element.querySelectorAll('input[type=\"radio\"]');
        
        // Filter select options
        selects.forEach(select => {
            const options = select.querySelectorAll('option');
            options.forEach(option => {
                if (option.value && !allowedOptions.includes(option.value)) {
                    option.style.display = 'none';
                    option.disabled = true;
                } else {
                    option.style.display = '';
                    option.disabled = false;
                }
            });
        });
        
        // Filter checkbox/radio options
        [...checkboxes, ...radios].forEach(input => {
            const container = input.closest('.form-check, .option-item');
            if (input.value && !allowedOptions.includes(input.value)) {
                if (container) {
                    container.style.display = 'none';
                }
                input.disabled = true;
                input.checked = false;
            } else {
                if (container) {
                    container.style.display = '';
                }
                input.disabled = false;
            }
        });
    }
    
    function resetQuestionOptions(questionId) {
        const element = questionElements[questionId];
        if (!element) return;
        
        // Reset all options to visible and enabled
        const options = element.querySelectorAll('option');
        options.forEach(option => {
            option.style.display = '';
            option.disabled = false;
        });
        
        const inputs = element.querySelectorAll('input[type=\"checkbox\"], input[type=\"radio\"]');
        inputs.forEach(input => {
            const container = input.closest('.form-check, .option-item');
            if (container) {
                container.style.display = '';
            }
            input.disabled = false;
        });
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeSkipLogic);
    } else {
        initializeSkipLogic();
    }
    ";
    
    return $js;
}

/**
 * Validate skip logic configuration
 * @param array $skipLogic Skip logic rules
 * @param PDO $pdo Database connection
 * @param int $surveyId Survey ID for validation context
 * @return array Validation result with errors if any
 */
function validateSkipLogic($skipLogic, $pdo, $surveyId = null) {
    $errors = [];
    
    if (empty($skipLogic) || !is_array($skipLogic)) {
        return ['valid' => true, 'errors' => []];
    }
    
    foreach ($skipLogic as $index => $rule) {
        $ruleErrors = [];
        
        // Validate required fields
        if (empty($rule['trigger_question_id'])) {
            $ruleErrors[] = 'Trigger question is required';
        }
        
        if (empty($rule['condition'])) {
            $ruleErrors[] = 'Condition is required';
        }
        
        if (!isset($rule['value'])) {
            $ruleErrors[] = 'Value is required';
        }
        
        if (empty($rule['action'])) {
            $ruleErrors[] = 'Action is required';
        }
        
        // Validate condition type
        $validConditions = ['equals', 'not_equals', 'contains', 'not_contains', 'greater_than', 'less_than', 'greater_equal', 'less_equal', 'is_empty', 'is_not_empty'];
        if (!empty($rule['condition']) && !in_array($rule['condition'], $validConditions)) {
            $ruleErrors[] = 'Invalid condition type';
        }
        
        // Validate action type
        $validActions = ['show', 'hide', 'filter_options'];
        if (!empty($rule['action']) && !in_array($rule['action'], $validActions)) {
            $ruleErrors[] = 'Invalid action type';
        }
        
        // Validate trigger question exists (if survey context provided)
        if ($surveyId && !empty($rule['trigger_question_id'])) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM survey_question sq 
                WHERE sq.survey_id = ? AND sq.question_id = ?
            ");
            $stmt->execute([$surveyId, $rule['trigger_question_id']]);
            if ($stmt->fetchColumn() == 0) {
                $ruleErrors[] = 'Trigger question not found in this survey';
            }
        }
        
        if (!empty($ruleErrors)) {
            $errors["rule_$index"] = $ruleErrors;
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}
?>