<?php
session_start();

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require_once 'connect.php';
require_once 'includes/question_helper.php';
require_once 'includes/skip_logic_helper.php';

$action = $_GET['action'] ?? 'list';
$questionId = $_GET['id'] ?? null;
$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        if (isset($_POST['save_question'])) {
            // Validate inputs
            $label = trim($_POST['label']);
            $questionType = $_POST['question_type'];
            $isRequired = isset($_POST['is_required']) ? 1 : 0;
            
            if (empty($label)) {
                throw new Exception('Question label is required');
            }
            
            // Skip translations - removed for simplification
            
            // Handle validation rules
            $validationRules = [];
            $validationFields = ['min', 'max', 'decimals', 'min_date', 'max_date', 'min_length', 'max_length', 'file_types', 'max_size', 'scale_range', 'low_label', 'high_label'];
            foreach ($validationFields as $field) {
                if (!empty($_POST["validation_$field"])) {
                    $validationRules[$field] = $_POST["validation_$field"];
                }
            }
            $validationRulesJson = !empty($validationRules) ? json_encode($validationRules) : null;
            
            // Handle skip logic
            $skipLogic = [];
            if (!empty($_POST['skip_logic_rules'])) {
                $skipLogicData = json_decode($_POST['skip_logic_rules'], true);
                if ($skipLogicData) {
                    $skipLogic = $skipLogicData;
                }
            }
            $skipLogicJson = !empty($skipLogic) ? json_encode($skipLogic) : null;
            
            // Handle option set
            $optionSetId = null;
            if (in_array($questionType, ['select', 'radio', 'checkbox'])) {
                if (!empty($_POST['option_set_id'])) {
                    $optionSetId = (int)$_POST['option_set_id'];
                } elseif (!empty($_POST['new_option_set_name'])) {
                    // Create new option set
                    $stmt = $pdo->prepare("INSERT INTO option_set (name) VALUES (?)");
                    $stmt->execute([$_POST['new_option_set_name']]);
                    $optionSetId = $pdo->lastInsertId();
                    
                    // Add options
                    if (!empty($_POST['option_values'])) {
                        $stmt = $pdo->prepare("INSERT INTO option_set_values (option_set_id, option_value) VALUES (?, ?)");
                        foreach ($_POST['option_values'] as $optionValue) {
                            if (!empty($optionValue)) {
                                $stmt->execute([$optionSetId, $optionValue]);
                            }
                        }
                    }
                }
            }
            
            $minSelections = !empty($_POST['min_selections']) ? (int)$_POST['min_selections'] : null;
            $maxSelections = !empty($_POST['max_selections']) ? (int)$_POST['max_selections'] : null;
            
            if ($questionId && $action === 'edit') {
                // Update existing question
                $stmt = $pdo->prepare("
                    UPDATE question 
                    SET label = ?, question_type = ?, is_required = ?, 
                        option_set_id = ?, validation_rules = ?, skip_logic = ?, 
                        min_selections = ?, max_selections = ?, updated = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $label, $questionType, $isRequired, 
                    $optionSetId, $validationRulesJson, $skipLogicJson, 
                    $minSelections, $maxSelections, $questionId
                ]);
                $success_message = "Question updated successfully!";
            } else {
                // Create new question
                $questionId = getOrCreateQuestion(
                    $pdo, $label, $questionType, $isRequired, null, $optionSetId
                );
                
                // Update additional fields
                $stmt = $pdo->prepare("
                    UPDATE question 
                    SET validation_rules = ?, skip_logic = ?, 
                        min_selections = ?, max_selections = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $validationRulesJson, $skipLogicJson, 
                    $minSelections, $maxSelections, $questionId
                ]);
                
                $success_message = "Question created successfully!";
                $action = 'edit'; // Switch to edit mode
            }
            
            // Link to surveys if specified
            if (!empty($_POST['survey_ids'])) {
                // First remove existing links if editing
                if ($action === 'edit') {
                    $stmt = $pdo->prepare("DELETE FROM survey_question WHERE question_id = ?");
                    $stmt->execute([$questionId]);
                }
                
                // Add new links
                $stmt = $pdo->prepare("INSERT IGNORE INTO survey_question (survey_id, question_id) VALUES (?, ?)");
                foreach ($_POST['survey_ids'] as $surveyId) {
                    $stmt->execute([$surveyId, $questionId]);
                }
            }
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollback();
        $error_message = $e->getMessage();
    }
}

// Fetch question data for editing
$question = null;
if ($questionId && in_array($action, ['edit', 'view'])) {
    $stmt = $pdo->prepare("
        SELECT q.*, 
               GROUP_CONCAT(DISTINCT sq.survey_id) as linked_survey_ids
        FROM question q
        LEFT JOIN survey_question sq ON q.id = sq.question_id
        WHERE q.id = ?
        GROUP BY q.id
    ");
    $stmt->execute([$questionId]);
    $question = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$question) {
        $error_message = "Question not found";
        $action = 'list';
    }
}

// Fetch data for form
$surveys = [];
$optionSets = [];
$questionTypes = [
    'text' => 'Text Input',
    'textarea' => 'Text Area',
    'select' => 'Dropdown Select',
    'radio' => 'Radio Buttons',
    'checkbox' => 'Checkboxes',
    'number' => 'Number',
    'integer' => 'Integer',
    'decimal' => 'Decimal',
    'percentage' => 'Percentage',
    'date' => 'Date',
    'datetime' => 'Date & Time',
    'time' => 'Time',
    'year' => 'Year',
    'month' => 'Month',
    'email' => 'Email',
    'phone' => 'Phone',
    'url' => 'URL',
    'rating' => 'Rating Scale',
    'likert_scale' => 'Likert Scale',
    'net_promoter_score' => 'Net Promoter Score',
    'star_rating' => 'Star Rating',
    'file_upload' => 'File Upload',
    'signature' => 'Signature',
    'coordinates' => 'GPS Coordinates',
    'color' => 'Color Picker'
];

if (in_array($action, ['create', 'edit'])) {
    // Get surveys for linking
    $stmt = $pdo->prepare("SELECT id, name FROM survey ORDER BY name");
    $stmt->execute();
    $surveys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get option sets
    $stmt = $pdo->prepare("SELECT id, name FROM option_set ORDER BY name");
    $stmt->execute();
    $optionSets = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// For skip logic, get available questions from surveys
$availableQuestions = [];
if ($question && !empty($question['linked_survey_ids'])) {
    $surveyIds = explode(',', $question['linked_survey_ids']);
    $placeholders = str_repeat('?,', count($surveyIds) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT q.id, q.label, q.question_type, MIN(sq.position) as min_position
        FROM question q
        JOIN survey_question sq ON q.id = sq.question_id
        WHERE sq.survey_id IN ($placeholders) AND q.id != ?
        GROUP BY q.id, q.label, q.question_type
        ORDER BY min_position, q.label
    ");
    $stmt->execute([...$surveyIds, $questionId]);
    $availableQuestions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Question Manager - Survey Engine</title>
    <!-- Argon Dashboard CSS -->
    <link href="argon-dashboard-master/assets/css/nucleo-icons.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-svg.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/argon-dashboard.min.css" rel="stylesheet">
    <style>
        /* Neutral styling with reduced spacing */
        body {
            background-color: #f8f9fa !important;
        }
        
        .card {
            background-color: #ffffff !important;
            border-radius: 6px !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
            border: 1px solid #e2e8f0 !important;
            color: #2d3748 !important;
        }
        
        .card-header {
            background-color: #ffffff !important;
            padding: 1rem !important;
            border-bottom: 1px solid #e2e8f0 !important;
        }
        
        .card-header h4 {
            color: #2d3748 !important;
            font-size: 1.1rem !important;
            margin-bottom: 0;
        }
        
        .card-body {
            padding: 1rem !important;
        }
        
        .skip-logic-rule {
            background: #f8f9fa;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 0.875rem;
            margin-bottom: 0.5rem;
        }
        
        .option-input-group {
            margin-bottom: 0.4rem;
        }
        
        .validation-section {
            background: #f8f9fa;
            padding: 0.875rem;
            border-radius: 6px;
            margin-top: 0.75rem;
            border: 1px solid #e2e8f0;
        }
        
        /* Button styling */
        .btn {
            border-radius: 4px !important;
            font-weight: 500 !important;
            font-size: 0.875rem !important;
            padding: 0.5rem 1rem !important;
            transition: none !important;
        }
        
        .btn-sm {
            font-size: 0.8rem !important;
            padding: 0.375rem 0.75rem !important;
        }
        
        .btn-primary {
            background-color: #4a5568 !important;
            border-color: #4a5568 !important;
        }
        
        .btn-primary:hover {
            background-color: #2d3748 !important;
            border-color: #2d3748 !important;
            transform: none !important;
        }
        
        .btn-secondary {
            background-color: #718096 !important;
            border-color: #718096 !important;
        }
        
        .btn-secondary:hover {
            background-color: #4a5568 !important;
            border-color: #4a5568 !important;
            transform: none !important;
        }
        
        .btn-danger {
            background-color: #dc3545 !important;
            border-color: #dc3545 !important;
        }
        
        .btn-danger:hover {
            background-color: #c82333 !important;
            border-color: #c82333 !important;
            transform: none !important;
        }
        
        /* Form controls */
        .form-control, .form-select {
            border: 1px solid #e2e8f0 !important;
            border-radius: 4px !important;
            font-size: 0.875rem !important;
            padding: 0.5rem 0.75rem !important;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #4a5568 !important;
            box-shadow: 0 0 0 2px rgba(74, 85, 104, 0.1) !important;
        }
        
        .form-label {
            color: #2d3748 !important;
            font-weight: 500;
            font-size: 0.875rem;
            margin-bottom: 0.375rem;
        }
        
        /* Alert styling */
        .alert {
            border-radius: 6px;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }
        
        .alert-success {
            background-color: #f0f9ff !important;
            border-color: #4a5568 !important;
            color: #2d3748 !important;
        }
        
        .alert-danger {
            background-color: #fef2f2 !important;
            border-color: #dc3545 !important;
            color: #dc3545 !important;
        }
        
        .alert-info {
            background-color: #f8f9fa !important;
            border-color: #4a5568 !important;
            color: #2d3748 !important;
        }
        
        /* Text colors */
        .text-primary {
            color: #4a5568 !important;
        }
        
        .text-muted {
            color: #718096 !important;
        }
        
        /* Preview section */
        .question-preview {
            background-color: #f8f9fa;
            border: 1px solid #e2e8f0 !important;
        }
        
        /* Skip logic specific */
        .skip-logic-rule .text-primary {
            color: #4a5568 !important;
        }
        
        .skip-logic-rule h6 {
            font-size: 0.9rem;
            margin-bottom: 0.375rem;
        }
        
        /* Input groups */
        .input-group {
            margin-bottom: 0.5rem;
        }
        
        .input-group-text {
            background-color: #f8f9fa;
            border-color: #e2e8f0;
            color: #4a5568;
            font-size: 0.875rem;
        }
        
        /* Checkbox and radio styling */
        .form-check {
            margin-bottom: 0.4rem;
        }
        
        .form-check-input:checked {
            background-color: #4a5568;
            border-color: #4a5568;
        }
        
        .form-check-input:focus {
            border-color: #4a5568;
            box-shadow: 0 0 0 2px rgba(74, 85, 104, 0.1);
        }
        
        /* Reduced spacing overrides */
        .py-4 {
            padding-top: 1.5rem !important;
            padding-bottom: 1.5rem !important;
        }
        
        .py-3 {
            padding-top: 1rem !important;
            padding-bottom: 1rem !important;
        }
        
        .mb-4 {
            margin-bottom: 1rem !important;
        }
        
        .mb-3 {
            margin-bottom: 0.75rem !important;
        }
        
        .mb-2 {
            margin-bottom: 0.5rem !important;
        }
        
        .p-4 {
            padding: 1rem !important;
        }
        
        .p-3 {
            padding: 0.75rem !important;
        }
        
        .p-2 {
            padding: 0.5rem !important;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100">
    <?php include 'components/aside.php'; ?>
    
    <main class="main-content position-relative border-radius-lg">
        <?php include 'components/navbar.php'; ?>
        
        <div class="container-fluid py-3">
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?= htmlspecialchars($success_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?= htmlspecialchars($error_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($action === 'list'): ?>
            <!-- Redirect to question bank -->
            <script>window.location.href = 'question_bank.php';</script>
        <?php elseif ($action === 'view'): ?>
            <!-- Question Preview Mode -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="mb-0">
                                <i class="fas fa-eye"></i> Question Preview
                            </h4>
                            <div>
                                <a href="question_manager.php?action=edit&id=<?= $questionId ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-edit"></i> Edit Question
                                </a>
                                <a href="question_bank.php" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-arrow-left"></i> Back to Question Bank
                                </a>
                            </div>
                        </div>
                        
                        <div class="card-body">
                            <?php if ($question): ?>
                                <!-- Question Preview -->
                                <div class="question-preview p-3 border rounded">
                                    <div class="mb-3">
                                        <h5 class="text-primary">Question Details</h5>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <strong>Type:</strong> <?= htmlspecialchars($questionTypes[$question['question_type']] ?? $question['question_type']) ?>
                                            </div>
                                            <div class="col-md-6">
                                                <strong>Required:</strong> <?= $question['is_required'] ? 'Yes' : 'No' ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <h6 class="fw-bold"><?= htmlspecialchars($question['label']) ?></h6>
                                        <?php if ($question['is_required']): ?>
                                            <span class="text-danger">*</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Render the actual question input -->
                                    <?php
                                    // Get option set if available
                                    $options = [];
                                    if ($question['option_set_id']) {
                                        $stmt = $pdo->prepare("SELECT option_value FROM option_set_values WHERE option_set_id = ? ORDER BY id");
                                        $stmt->execute([$question['option_set_id']]);
                                        $options = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                    }
                                    
                                    switch ($question['question_type']):
                                        case 'text':
                                        case 'email':
                                        case 'phone':
                                        case 'url':
                                            echo '<input type="text" class="form-control" placeholder="Preview - not functional" disabled>';
                                            break;
                                        case 'textarea':
                                            echo '<textarea class="form-control" rows="3" placeholder="Preview - not functional" disabled></textarea>';
                                            break;
                                        case 'number':
                                        case 'integer':
                                        case 'decimal':
                                        case 'percentage':
                                            echo '<input type="number" class="form-control" placeholder="Preview - not functional" disabled>';
                                            break;
                                        case 'date':
                                            echo '<input type="date" class="form-control" disabled>';
                                            break;
                                        case 'datetime':
                                            echo '<input type="datetime-local" class="form-control" disabled>';
                                            break;
                                        case 'time':
                                            echo '<input type="time" class="form-control" disabled>';
                                            break;
                                        case 'select':
                                            echo '<select class="form-control" disabled>';
                                            echo '<option>Choose an option...</option>';
                                            foreach ($options as $option) {
                                                echo '<option>' . htmlspecialchars($option) . '</option>';
                                            }
                                            echo '</select>';
                                            break;
                                        case 'radio':
                                            foreach ($options as $option) {
                                                echo '<div class="form-check">';
                                                echo '<input type="radio" class="form-check-input" disabled>';
                                                echo '<label class="form-check-label">' . htmlspecialchars($option) . '</label>';
                                                echo '</div>';
                                            }
                                            break;
                                        case 'checkbox':
                                            foreach ($options as $option) {
                                                echo '<div class="form-check">';
                                                echo '<input type="checkbox" class="form-check-input" disabled>';
                                                echo '<label class="form-check-label">' . htmlspecialchars($option) . '</label>';
                                                echo '</div>';
                                            }
                                            break;
                                        default:
                                            echo '<div class="alert alert-info">Preview not available for this question type</div>';
                                    endswitch;
                                    ?>
                                </div>
                                
                                <!-- Skip Logic Info -->
                                <?php if (!empty($question['skip_logic'])): ?>
                                    <div class="mt-4">
                                        <h5 class="text-primary">Skip Logic Rules</h5>
                                        <div class="alert alert-info">
                                            <?php
                                            $skipLogicRules = json_decode($question['skip_logic'], true);
                                            if ($skipLogicRules) {
                                                echo "<strong>This question has " . count($skipLogicRules) . " skip logic rule(s):</strong><br>";
                                                foreach ($skipLogicRules as $rule) {
                                                    $triggerQuestionId = $rule['trigger_question_id'] ?? null;
                                                    if ($triggerQuestionId) {
                                                        $stmt = $pdo->prepare("SELECT label FROM question WHERE id = ?");
                                                        $stmt->execute([$triggerQuestionId]);
                                                        $triggerLabel = $stmt->fetchColumn();
                                                        
                                                        echo "• When \"" . htmlspecialchars($triggerLabel) . "\" is \"" . htmlspecialchars($rule['value']) . "\", then this question will " . htmlspecialchars($rule['action']) . "<br>";
                                                    }
                                                }
                                            }
                                            ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Linked Surveys -->
                                <?php if (!empty($question['linked_survey_ids'])): ?>
                                    <div class="mt-4">
                                        <h5 class="text-primary">Linked to Surveys</h5>
                                        <div class="row">
                                            <?php
                                            $surveyIds = explode(',', $question['linked_survey_ids']);
                                            foreach ($surveyIds as $surveyId) {
                                                $stmt = $pdo->prepare("SELECT name FROM survey WHERE id = ?");
                                                $stmt->execute([$surveyId]);
                                                $surveyName = $stmt->fetchColumn();
                                                if ($surveyName) {
                                                    echo '<div class="col-md-6 mb-2">';
                                                    echo '<span class="badge bg-primary">' . htmlspecialchars($surveyName) . '</span>';
                                                    echo '</div>';
                                                }
                                            }
                                            ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="alert alert-danger">Question not found</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif (in_array($action, ['create', 'edit'])): ?>
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="mb-0">
                                <i class="fas fa-<?= $action === 'create' ? 'plus' : 'edit' ?>"></i>
                                <?= $action === 'create' ? 'Create New Question' : 'Edit Question' ?>
                            </h4>
                            <a href="question_bank.php" class="btn btn-secondary btn-sm">
                                <i class="fas fa-arrow-left"></i> Back to Question Bank
                            </a>
                        </div>
                        
                        <div class="card-body">
                            <form method="post" id="questionForm">
                                <input type="hidden" name="save_question" value="1">
                                
                                <!-- Basic Information -->
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="mb-3">
                                            <label for="label" class="form-label">Question Text <span class="text-danger">*</span></label>
                                            <textarea name="label" id="label" class="form-control" rows="3" required><?= htmlspecialchars($question['label'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="question_type" class="form-label">Question Type <span class="text-danger">*</span></label>
                                            <select name="question_type" id="question_type" class="form-select" required>
                                                <option value="">Select Type...</option>
                                                <?php foreach ($questionTypes as $value => $label): ?>
                                                    <option value="<?= $value ?>" <?= ($question['question_type'] ?? '') === $value ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($label) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <div class="form-check">
                                                <input type="checkbox" name="is_required" id="is_required" class="form-check-input" 
                                                       <?= (!empty($question['is_required'])) ? 'checked' : '' ?>>
                                                <label for="is_required" class="form-check-label">Required Question</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Survey Linking -->
                                <div class="mb-4">
                                    <label class="form-label">Link to Surveys <span class="text-danger">*</span></label>
                                    <div class="alert alert-info">
                                        <small><i class="fas fa-info-circle"></i> Select surveys to enable skip logic with other questions from those surveys.</small>
                                    </div>
                                    <div class="row">
                                        <?php foreach ($surveys as $survey): ?>
                                            <div class="col-md-3 mb-2">
                                                <div class="form-check">
                                                    <input type="checkbox" name="survey_ids[]" value="<?= $survey['id'] ?>" 
                                                           class="form-check-input survey-checkbox" id="survey_<?= $survey['id'] ?>"
                                                           <?= (isset($question['linked_survey_ids']) && in_array($survey['id'], explode(',', $question['linked_survey_ids']))) ? 'checked' : '' ?>
                                                           onchange="updateAvailableQuestions()">
                                                    <label for="survey_<?= $survey['id'] ?>" class="form-check-label">
                                                        <?= htmlspecialchars($survey['name']) ?>
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                
                                <!-- Options Section (for select/radio/checkbox types) -->
                                <div id="optionsSection" class="mb-4" style="display: none;">
                                    <h5>Answer Options</h5>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="option_set_id" class="form-label">Use Existing Option Set</label>
                                            <select name="option_set_id" id="option_set_id" class="form-select">
                                                <option value="">Create New Options</option>
                                                <?php foreach ($optionSets as $optionSet): ?>
                                                    <option value="<?= $optionSet['id'] ?>" 
                                                            <?= ($question['option_set_id'] ?? '') == $optionSet['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($optionSet['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-3">
                                            <label for="min_selections" class="form-label">Min Selections</label>
                                            <input type="number" name="min_selections" id="min_selections" class="form-control" 
                                                   value="<?= htmlspecialchars($question['min_selections'] ?? '') ?>">
                                        </div>
                                        
                                        <div class="col-md-3">
                                            <label for="max_selections" class="form-label">Max Selections</label>
                                            <input type="number" name="max_selections" id="max_selections" class="form-control" 
                                                   value="<?= htmlspecialchars($question['max_selections'] ?? '') ?>">
                                        </div>
                                    </div>
                                    
                                    <div id="newOptionsSection">
                                        <div class="mb-3">
                                            <label for="new_option_set_name" class="form-label">Option Set Name</label>
                                            <input type="text" name="new_option_set_name" id="new_option_set_name" class="form-control" 
                                                   placeholder="Enter option set name">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Option Values</label>
                                            <div id="optionValues">
                                                <div class="option-input-group">
                                                    <div class="input-group">
                                                        <input type="text" name="option_values[]" class="form-control" placeholder="Enter option value">
                                                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeOption(this)">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <button type="button" class="btn btn-outline-primary btn-sm mt-2" onclick="addOption()">
                                                <i class="fas fa-plus"></i> Add Option
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Validation Rules Section -->
                                <div class="validation-section">
                                    <h5><i class="fas fa-check-circle"></i> Validation Rules</h5>
                                    <div id="validationFields">
                                        <!-- Validation fields will be populated by JavaScript based on question type -->
                                    </div>
                                </div>
                                
                                <!-- Skip Logic Section -->
                                <div class="mt-4">
                                    <h5><i class="fas fa-code-branch"></i> Skip Logic</h5>
                                    <div class="alert alert-info">
                                        <h6><i class="fas fa-info-circle"></i> How Skip Logic Works:</h6>
                                        <p class="mb-0">Configure when <strong>this question</strong> should be shown/hidden or have its options filtered based on responses to <strong>other questions</strong> in the same surveys.</p>
                                    </div>
                                    
                                    <div id="skipLogicRules">
                                        <!-- Skip logic rules will be populated here -->
                                    </div>
                                    
                                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="addSkipLogicRule()">
                                        <i class="fas fa-plus"></i> Add Skip Logic Rule
                                    </button>
                                    
                                    <input type="hidden" name="skip_logic_rules" id="skipLogicRulesInput">
                                </div>
                                
                                
                                <!-- Form Actions -->
                                <div class="mt-4 pt-3 border-top">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Question
                                    </button>
                                    <a href="question_bank.php" class="btn btn-secondary">
                                        <i class="fas fa-times"></i> Cancel
                                    </a>
                                    <?php if ($action === 'edit'): ?>
                                        <a href="question_manager.php?action=view&id=<?= $questionId ?>" class="btn btn-info">
                                            <i class="fas fa-eye"></i> Preview
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Available questions for skip logic
        let availableQuestions = <?= json_encode($availableQuestions) ?>;
        let skipLogicRuleCount = 0;
        
        // Question type change handler
        document.getElementById('question_type').addEventListener('change', function() {
            const questionType = this.value;
            const optionsSection = document.getElementById('optionsSection');
            const validationFields = document.getElementById('validationFields');
            
            // Show/hide options section
            if (['select', 'radio', 'checkbox'].includes(questionType)) {
                optionsSection.style.display = 'block';
            } else {
                optionsSection.style.display = 'none';
            }
            
            // Update validation fields
            updateValidationFields(questionType);
        });
        
        // Option set selection handler
        document.getElementById('option_set_id').addEventListener('change', function() {
            const newOptionsSection = document.getElementById('newOptionsSection');
            if (this.value) {
                newOptionsSection.style.display = 'none';
            } else {
                newOptionsSection.style.display = 'block';
            }
        });
        
        function addOption() {
            const container = document.getElementById('optionValues');
            const newOption = document.createElement('div');
            newOption.className = 'option-input-group';
            newOption.innerHTML = `
                <div class="input-group">
                    <input type="text" name="option_values[]" class="form-control" placeholder="Enter option value">
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeOption(this)">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;
            container.appendChild(newOption);
        }
        
        function removeOption(button) {
            button.closest('.option-input-group').remove();
        }
        
        function updateValidationFields(questionType) {
            const validationFields = document.getElementById('validationFields');
            let html = '';
            
            switch (questionType) {
                case 'number':
                case 'integer':
                case 'decimal':
                case 'percentage':
                    html = `
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Minimum Value</label>
                                <input type="number" name="validation_min" class="form-control" step="any">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Maximum Value</label>
                                <input type="number" name="validation_max" class="form-control" step="any">
                            </div>
                        </div>
                    `;
                    if (questionType === 'decimal') {
                        html += `
                            <div class="mt-3">
                                <label class="form-label">Decimal Places</label>
                                <input type="number" name="validation_decimals" class="form-control" min="0" max="10">
                            </div>
                        `;
                    }
                    break;
                    
                case 'date':
                case 'datetime':
                    html = `
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Minimum Date</label>
                                <input type="date" name="validation_min_date" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Maximum Date</label>
                                <input type="date" name="validation_max_date" class="form-control">
                            </div>
                        </div>
                    `;
                    break;
                    
                case 'text':
                case 'textarea':
                    html = `
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Minimum Length</label>
                                <input type="number" name="validation_min_length" class="form-control" min="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Maximum Length</label>
                                <input type="number" name="validation_max_length" class="form-control" min="1">
                            </div>
                        </div>
                    `;
                    break;
                    
                case 'file_upload':
                    html = `
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Allowed File Types</label>
                                <input type="text" name="validation_file_types" class="form-control" 
                                       placeholder="e.g., jpg,png,pdf">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Max File Size (MB)</label>
                                <input type="number" name="validation_max_size" class="form-control" min="1">
                            </div>
                        </div>
                    `;
                    break;
                    
                case 'rating':
                case 'likert_scale':
                case 'star_rating':
                    html = `
                        <div class="row">
                            <div class="col-md-4">
                                <label class="form-label">Scale Range</label>
                                <input type="text" name="validation_scale_range" class="form-control" 
                                       placeholder="e.g., 1-5">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Low Label</label>
                                <input type="text" name="validation_low_label" class="form-control" 
                                       placeholder="e.g., Poor">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">High Label</label>
                                <input type="text" name="validation_high_label" class="form-control" 
                                       placeholder="e.g., Excellent">
                            </div>
                        </div>
                    `;
                    break;
            }
            
            validationFields.innerHTML = html;
        }
        
        function addSkipLogicRule() {
            const container = document.getElementById('skipLogicRules');
            const ruleId = 'rule_' + skipLogicRuleCount++;
            
            const ruleHtml = `
                <div class="skip-logic-rule border rounded p-3 mb-3" id="${ruleId}" style="background: #f8f9fa;">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0 text-primary">
                            <i class="fas fa-arrow-right me-1"></i>
                            When another question affects this question
                        </h6>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeSkipLogicRule('${ruleId}')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">When this question:</label>
                            <select class="form-select trigger-question" onchange="loadTriggerQuestionOptions('${ruleId}'); updateSkipLogicData();">
                                <option value="">Choose a trigger question...</option>
                                ${availableQuestions.map(q => `<option value="${q.id}">${q.label.substring(0, 60)}${q.label.length > 60 ? '...' : ''}</option>`).join('')}
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Is answered:</label>
                            <select class="form-select trigger-value" onchange="updateSkipLogicData();" disabled>
                                <option value="">Select trigger first...</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Then this question will:</label>
                            <select class="form-select action" onchange="toggleOptionsFilter('${ruleId}'); updateSkipLogicData();">
                                <option value="">Choose action...</option>
                                <option value="show">🟢 Be shown</option>
                                <option value="hide">🔴 Be hidden</option>
                                <option value="filter_options">🔽 Show only specific options</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3" id="optionsFilterSection_${ruleId}" style="display: none;">
                            <label class="form-label fw-bold">Show only these options:</label>
                            <div class="available-options">
                                <small class="text-muted">Configure answer options first to enable filtering</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-2">
                        <small class="text-muted">
                            <i class="fas fa-lightbulb"></i>
                            Example: When "Age Group" is answered "18-30", then this question will be shown.
                        </small>
                    </div>
                </div>
            `;
            
            container.insertAdjacentHTML('beforeend', ruleHtml);
        }
        
        function removeSkipLogicRule(ruleId) {
            document.getElementById(ruleId).remove();
            updateSkipLogicData();
        }
        
        function updateSkipLogicData() {
            const rules = [];
            const ruleElements = document.querySelectorAll('.skip-logic-rule');
            
            ruleElements.forEach(rule => {
                const triggerQuestion = rule.querySelector('.trigger-question').value;
                const triggerValue = rule.querySelector('.trigger-value').value;
                const action = rule.querySelector('.action').value;
                
                if (triggerQuestion && triggerValue && action) {
                    const ruleData = {
                        trigger_question_id: parseInt(triggerQuestion),
                        condition: 'equals', // Default to equals for simplicity
                        value: triggerValue,
                        action: action
                    };
                    
                    // Handle option filtering
                    if (action === 'filter_options') {
                        const selectedOptions = [];
                        const checkboxes = rule.querySelectorAll('.option-checkbox:checked');
                        checkboxes.forEach(cb => selectedOptions.push(cb.value));
                        
                        if (selectedOptions.length > 0) {
                            ruleData.target = selectedOptions;
                        }
                    }
                    
                    rules.push(ruleData);
                }
            });
            
            document.getElementById('skipLogicRulesInput').value = JSON.stringify(rules);
        }
        
        function loadTriggerQuestionOptions(ruleId) {
            const rule = document.getElementById(ruleId);
            const triggerQuestionId = rule.querySelector('.trigger-question').value;
            const triggerValueSelect = rule.querySelector('.trigger-value');
            
            // Clear existing options
            triggerValueSelect.innerHTML = '<option value="">Loading...</option>';
            triggerValueSelect.disabled = true;
            
            if (!triggerQuestionId) {
                triggerValueSelect.innerHTML = '<option value="">Select trigger first...</option>';
                return;
            }
            
            // Make AJAX call to get question options
            fetch('get_question_options.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ question_id: triggerQuestionId })
            })
            .then(response => response.json())
            .then(data => {
                triggerValueSelect.innerHTML = '<option value="">Choose a value...</option>';
                
                if (data.success && data.options) {
                    data.options.forEach(option => {
                        triggerValueSelect.innerHTML += `<option value="${option.value}">${option.label}</option>`;
                    });
                } else {
                    // Fallback for questions without predefined options
                    triggerValueSelect.innerHTML += `
                        <option value="any_value">Any value entered</option>
                        <option value="is_empty">Is empty/not answered</option>
                        <option value="is_not_empty">Has any value</option>
                    `;
                }
                
                triggerValueSelect.disabled = false;
            })
            .catch(error => {
                console.error('Error loading question options:', error);
                triggerValueSelect.innerHTML = `
                    <option value="">Error loading options</option>
                    <option value="any_value">Any value entered</option>
                `;
                triggerValueSelect.disabled = false;
            });
        }
        
        function toggleOptionsFilter(ruleId) {
            const rule = document.getElementById(ruleId);
            const action = rule.querySelector('.action').value;
            const optionsSection = document.getElementById(`optionsFilterSection_${ruleId}`);
            
            if (action === 'filter_options') {
                optionsSection.style.display = 'block';
                loadCurrentQuestionOptions(ruleId);
            } else {
                optionsSection.style.display = 'none';
            }
        }
        
        function loadCurrentQuestionOptions(ruleId) {
            const optionSetId = document.getElementById('option_set_id').value;
            const newOptionSetName = document.getElementById('new_option_set_name').value;
            const optionsContainer = document.querySelector(`#optionsFilterSection_${ruleId} .available-options`);
            
            // Check if we have an existing option set or new options being created
            if (!optionSetId && !newOptionSetName) {
                const questionType = document.getElementById('question_type').value;
                if (!['select', 'radio', 'checkbox'].includes(questionType)) {
                    optionsContainer.innerHTML = '<small class="text-warning">This question must be a select/radio/checkbox type to use option filtering.</small>';
                    return;
                }
                optionsContainer.innerHTML = '<small class="text-warning">Please configure answer options first to enable option filtering.</small>';
                return;
            }
            
            if (optionSetId) {
                // Load from existing option set
                fetch('get_option_set_values.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ option_set_id: optionSetId })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.options) {
                        let html = `
                            <div class="alert alert-info alert-sm p-2 mb-2">
                                <i class="fas fa-info-circle"></i> 
                                <strong>Option Filtering:</strong> Check the options that should remain visible when the condition is met. 
                                Unchecked options will be hidden.
                            </div>
                            <div class="border rounded p-2" style="max-height: 200px; overflow-y: auto;">
                        `;
                        
                        // Add "Select All" and "Clear All" buttons
                        html += `
                            <div class="d-flex gap-2 mb-2">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="selectAllOptions('${ruleId}')">
                                    <i class="fas fa-check-square"></i> Select All
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearAllOptions('${ruleId}')">
                                    <i class="fas fa-square"></i> Clear All
                                </button>
                            </div>
                            <div class="option-filter-list">
                        `;
                        
                        data.options.forEach((option, index) => {
                            html += `
                                <div class="form-check">
                                    <input class="form-check-input option-checkbox" type="checkbox" value="${option.value}" id="opt_${ruleId}_${index}" onchange="updateSkipLogicData()">
                                    <label class="form-check-label" for="opt_${ruleId}_${index}">
                                        <span class="fw-bold">${option.label}</span>
                                        <small class="text-muted d-block">Value: ${option.value}</small>
                                    </label>
                                </div>
                            `;
                        });
                        
                        html += '</div></div>';
                        optionsContainer.innerHTML = html;
                    } else {
                        optionsContainer.innerHTML = '<small class="text-danger">Error loading options from option set</small>';
                    }
                })
                .catch(error => {
                    console.error('Error loading option set values:', error);
                    optionsContainer.innerHTML = '<small class="text-danger">Error loading options</small>';
                });
            } else if (newOptionSetName) {
                // Load from new options being created
                const optionInputs = document.querySelectorAll('input[name="option_values[]"]');
                let hasOptions = false;
                let html = `
                    <div class="alert alert-info alert-sm p-2 mb-2">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Option Filtering:</strong> Check the options that should remain visible when the condition is met. 
                        Unchecked options will be hidden.
                    </div>
                    <div class="border rounded p-2" style="max-height: 200px; overflow-y: auto;">
                `;
                
                // Add "Select All" and "Clear All" buttons
                html += `
                    <div class="d-flex gap-2 mb-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="selectAllOptions('${ruleId}')">
                            <i class="fas fa-check-square"></i> Select All
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearAllOptions('${ruleId}')">
                            <i class="fas fa-square"></i> Clear All
                        </button>
                    </div>
                    <div class="option-filter-list">
                `;
                
                optionInputs.forEach((input, index) => {
                    if (input.value.trim()) {
                        hasOptions = true;
                        html += `
                            <div class="form-check">
                                <input class="form-check-input option-checkbox" type="checkbox" value="${input.value.trim()}" id="opt_${ruleId}_${index}" onchange="updateSkipLogicData()">
                                <label class="form-check-label" for="opt_${ruleId}_${index}">
                                    <span class="fw-bold">${input.value.trim()}</span>
                                    <small class="text-muted d-block">Value: ${input.value.trim()}</small>
                                </label>
                            </div>
                        `;
                    }
                });
                
                html += '</div></div>';
                
                if (hasOptions) {
                    optionsContainer.innerHTML = html;
                } else {
                    optionsContainer.innerHTML = '<small class="text-warning">Please add some option values first to enable filtering.</small>';
                }
            }
        }
        
        function updateAvailableQuestions() {
            const selectedSurveys = [];
            document.querySelectorAll('.survey-checkbox:checked').forEach(checkbox => {
                selectedSurveys.push(checkbox.value);
            });
            
            if (selectedSurveys.length === 0) {
                availableQuestions = [];
                updateAllSkipLogicDropdowns();
                return;
            }
            
            // Make AJAX call to get questions from selected surveys
            fetch('get_survey_questions.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ 
                    survey_ids: selectedSurveys,
                    exclude_question_id: <?= $questionId ?? 'null' ?>
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.questions) {
                    availableQuestions = data.questions;
                } else {
                    availableQuestions = [];
                }
                updateAllSkipLogicDropdowns();
            })
            .catch(error => {
                console.error('Error loading survey questions:', error);
                availableQuestions = [];
                updateAllSkipLogicDropdowns();
            });
        }
        
        function updateAllSkipLogicDropdowns() {
            // Update all existing skip logic rule dropdowns
            document.querySelectorAll('.trigger-question').forEach(select => {
                const currentValue = select.value;
                select.innerHTML = '<option value="">Choose a trigger question...</option>';
                
                if (availableQuestions.length === 0) {
                    select.innerHTML += '<option value="" disabled>Link to surveys first to see questions</option>';
                } else {
                    availableQuestions.forEach(q => {
                        const selected = currentValue == q.id ? 'selected' : '';
                        select.innerHTML += `<option value="${q.id}" ${selected}>${q.label.substring(0, 60)}${q.label.length > 60 ? '...' : ''}</option>`;
                    });
                }
            });
        }
        
        function selectAllOptions(ruleId) {
            const rule = document.getElementById(ruleId);
            const checkboxes = rule.querySelectorAll('.option-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
            updateSkipLogicData();
        }
        
        function clearAllOptions(ruleId) {
            const rule = document.getElementById(ruleId);
            const checkboxes = rule.querySelectorAll('.option-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            updateSkipLogicData();
        }
        
        // Initialize form
        document.addEventListener('DOMContentLoaded', function() {
            // Trigger question type change to show appropriate sections
            const questionType = document.getElementById('question_type').value;
            if (questionType) {
                document.getElementById('question_type').dispatchEvent(new Event('change'));
            }
            
            // Load available questions for skip logic based on selected surveys
            updateAvailableQuestions();
            
            // Load existing skip logic
            <?php if ($question && !empty($question['skip_logic'])): ?>
                const existingSkipLogic = <?= $question['skip_logic'] ?>;
                existingSkipLogic.forEach(rule => {
                    addSkipLogicRule();
                    const lastRule = document.querySelector('.skip-logic-rule:last-child');
                    
                    // Set trigger question
                    lastRule.querySelector('.trigger-question').value = rule.trigger_question_id;
                    
                    // Load trigger question options first
                    loadTriggerQuestionOptions(lastRule.id);
                    
                    // Set other values with a delay to allow options to load
                    setTimeout(() => {
                        lastRule.querySelector('.trigger-value').value = rule.value;
                        lastRule.querySelector('.action').value = rule.action;
                        
                        // Handle option filtering if applicable
                        if (rule.action === 'filter_options') {
                            toggleOptionsFilter(lastRule.id);
                            if (rule.target && Array.isArray(rule.target)) {
                                // Check the appropriate checkboxes
                                rule.target.forEach(targetValue => {
                                    const checkbox = lastRule.querySelector(`input[value="${targetValue}"]`);
                                    if (checkbox) checkbox.checked = true;
                                });
                            }
                        }
                        
                        updateSkipLogicData();
                    }, 500);
                });
                updateSkipLogicData();
            <?php endif; ?>
        });
    </script>
        </div> <!-- End container-fluid -->
    </main> <!-- End main-content -->
    
    <!-- Bootstrap 5 JS for proper functionality -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Argon Dashboard JS (excluding bootstrap to avoid conflicts) -->
    <script src="argon-dashboard-master/assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/smooth-scrollbar.min.js"></script>
</body>
</html>