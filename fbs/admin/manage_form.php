<?php
session_start();

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require 'connect.php';

// Centralized input validation function
function validateInput($input, $type = 'string', $maxLength = 255) {
    // Trim whitespace
    $input = trim($input);

    // Validate based on type
    switch ($type) {
        case 'string':
            // Remove potentially harmful HTML tags
            $input = strip_tags($input);
            
            // Check length
            if (strlen($input) > $maxLength) {
                throw new Exception("Input exceeds maximum length of {$maxLength} characters");
            }
            break;

        case 'int':
            if (!filter_var($input, FILTER_VALIDATE_INT)) {
                throw new Exception("Invalid integer input");
            }
            break;

        case 'array':
            if (!is_array($input)) {
                throw new Exception("Input must be an array");
            }
            break;
    }

    return $input;
}

// Logging function (consider replacing with proper logging library in production)
function logError($message, $context = []) {
    // In a real-world scenario, use a proper logging mechanism
    error_log(json_encode([
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s'),
        'context' => $context
    ]));
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add question to surveys
    if (isset($_POST['add_question'])) {
        try {
            // Start database transaction
            $pdo->beginTransaction();

            // Validate inputs
            $questionLabel = validateInput($_POST['question_label'], 'string', 500);
            $questionType = validateInput($_POST['question_type'], 'string', 50);
            
            // Optional: Validate option set ID
            $optionSetId = !empty($_POST['option_set_id']) 
                ? validateInput($_POST['option_set_id'], 'int') 
                : null;

            // Validate survey IDs if provided
            $surveyIds = !empty($_POST['survey_ids']) 
                ? array_map(function($id) { 
                    return validateInput($id, 'int'); 
                }, $_POST['survey_ids']) 
                : [];

            // Prepare translations (if any)
            $translations = !empty($_POST['translations']) 
                ? json_encode($_POST['translations']) 
                : null;

            // Validate required fields
            if (empty($questionLabel) || empty($questionType)) {
                throw new Exception("Question label and type are required.");
            }

            // Insert the new question
            $stmt = $pdo->prepare("
                INSERT INTO question (
                    label, 
                    question_type, 
                    is_required, 
                    translations, 
                    option_set_id, 
                    created, 
                    updated
                ) VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $questionLabel,
                $questionType,
                isset($_POST['is_required']) ? 1 : 0,
                $translations,
                $optionSetId
            ]);
            $questionId = $pdo->lastInsertId();

            // Link question to selected surveys
            if (!empty($surveyIds)) {
                $surveyStmt = $pdo->prepare("
                    INSERT INTO survey_question (survey_id, question_id) 
                    VALUES (?, ?)
                ");
                foreach ($surveyIds as $surveyId) {
                    $surveyStmt->execute([$surveyId, $questionId]);
                }
            }

            $pdo->commit();
            $_SESSION['success_message'] = "Question added successfully!";

        } catch (Exception $e) {
            // Rollback transaction on error
            $pdo->rollBack();

            // Log the error
            logError("Question Add Error", [
                'message' => $e->getMessage(),
                'post_data' => array_diff_key($_POST, array_flip(['translations']))
            ]);

            // Set user-friendly error message
            $_SESSION['error_message'] = "Failed to add question: " . $e->getMessage();
        }

        // Always redirect to prevent form resubmission
        header("Location: manage_form.php");
        exit(); 
    }

    // Delete question
    if (isset($_POST['delete_question'])) {
        try {
            // Validate question ID
            $questionId = validateInput($_POST['question_id'], 'int');

            $pdo->beginTransaction();

            // Check if question exists before deleting
            $checkStmt = $pdo->prepare("SELECT id FROM question WHERE id = ?");
            $checkStmt->execute([$questionId]);
            if ($checkStmt->rowCount() === 0) {
                throw new Exception("Question not found");
            }

            // Delete from survey_question first
            $stmt = $pdo->prepare("DELETE FROM survey_question WHERE question_id = ?");
            $stmt->execute([$questionId]);

            // Delete from question
            $stmt = $pdo->prepare("DELETE FROM question WHERE id = ?");
            $stmt->execute([$questionId]);

            $pdo->commit();
            $_SESSION['success_message'] = "Question deleted successfully!";

        } catch (Exception $e) {
            $pdo->rollBack();

            // Log the error
            logError("Question Delete Error", [
                'message' => $e->getMessage(),
                'question_id' => $questionId
            ]);

            $_SESSION['error_message'] = "Failed to delete question: " . $e->getMessage();
        }

        // Redirect to refresh the page
        header("Location: manage_form.php");
        exit();
    }
}

// Fetch questions with improved query
$questions = $pdo->query("
    SELECT 
        q.id AS question_id,
        q.label AS question_label,
        q.question_type,
        q.is_required,
        q.translations AS question_translations,
        q.option_set_id,
        q.created AS question_created,
        q.updated AS question_updated,
        os.name AS option_set_name,
        COUNT(DISTINCT osv.id) AS option_count
    FROM question q
    LEFT JOIN option_set os ON q.option_set_id = os.id
    LEFT JOIN option_set_values osv ON os.id = osv.option_set_id
    GROUP BY q.id, q.label, q.question_type, q.is_required, q.translations, 
             q.option_set_id, q.created, q.updated, os.name
    ORDER BY q.created ASC  
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch surveys for the form (with error handling)
try {
    $surveys = $pdo->query("SELECT * FROM survey ORDER BY created DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    logError("Survey Fetch Error", ['message' => $e->getMessage()]);
    $surveys = [];
}

// Fetch option sets with original order preservation
$optionSets = $pdo->query("
    SELECT 
        os.id AS option_set_id,
        os.name AS option_set_name,
        os.created AS option_set_created,
        os.updated AS option_set_updated,
        GROUP_CONCAT(osv.option_value ORDER BY osv.id SEPARATOR ', ') AS options
    FROM option_set os
    LEFT JOIN option_set_values osv ON os.id = osv.option_set_id
    GROUP BY os.id, os.name, os.created, os.updated
")->fetchAll(PDO::FETCH_ASSOC);
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Form | Survey Admin</title>
    <link href="argon-dashboard-master/assets/css/nucleo-icons.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-svg.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/argon-dashboard.css" rel="stylesheet">
    <style>
        :root {
            --main-blue: #1d3866;
            --main-blue-light: #2a4c8a;
            --main-blue-dark: #142646;
            --main-blue-bg: #f5f7fa;
            --accent-gold: #ffd700;
            --accent-gray: #e9ecef;
        }
        .question-card {
            transition: all 0.3s ease;
            margin-bottom: 1.5rem;
            border: 1px solid var(--main-blue-light);
            background: var(--main-blue-bg);
            border-radius: 0.5rem;
        }
        .question-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(29, 56, 102, 0.12);
            border-color: var(--main-blue-dark);
        }
        .question-type-badge {
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
            background: var(--main-blue);
            color: #fff;
            border-radius: 0.25rem;
        }
        .translation-entry {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            align-items: center;
        }
        .option-entry {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            align-items: center;
        }
        .language-select {
            min-width: 150px;
            border-color: var(--main-blue-light);
        }
        .timeline {
            position: relative;
            padding-left: 1.5rem;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--main-blue-light);
        }
        .timeline-item {
            position: relative;
            padding-bottom: 1.5rem;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -1.5rem;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--main-blue);
            margin-top: 4px;
            border: 2px solid #fff;
            box-shadow: 0 0 0 2px var(--main-blue-light);
        }
        /* Buttons and form controls */
        .btn-primary, .btn-outline-primary {
            background: var(--main-blue);
            border-color: var(--main-blue-dark);
        }
        .btn-primary:hover, .btn-outline-primary:hover {
            background: var(--main-blue-dark);
            border-color: var(--main-blue-dark);
        }
        .form-check-input:checked {
            background-color: var(--main-blue-light);
            border-color: var(--main-blue-dark);
        }
        .form-select:focus, .form-control:focus {
            border-color: var(--main-blue-light);
            box-shadow: 0 0 0 0.2rem rgba(29, 56, 102, 0.15);
        }
        /* Table header */
        .table thead th {
            color: var(--main-blue-dark);
            background: var(--accent-gray);
        }
        /* Custom badge for translations */
        .badge.bg-gradient-secondary {
            background: linear-gradient(87deg, var(--main-blue), var(--main-blue-light));
            color: #fff;
        }
        /* Required badge */
        .badge.bg-gradient-danger {
            background: linear-gradient(87deg, #fff, #fff);
            color: var(--accent-gold);
            border: 1px solid var(--accent-gold);
        }
        .survey-card:hover {
    border-color: #1d3866 !important;
    box-shadow: 0 2px 8px rgba(29, 56, 102, 0.15);
    }

    .survey-card.selected {
        border-color: #1d3866 !important;
        background-color: #f8f9ff;
    }

    .form-check-input:checked {
        background-color: #1d3866;
        border-color: #1d3866;
    }

    .required-survey-checkbox:checked {
        background-color: #dc3545;
        border-color: #dc3545;
    }

    .form-check-input:focus {
        border-color: #1d3866;
        box-shadow: 0 0 0 0.2rem rgba(29, 56, 102, 0.25);
    }

    .required-survey-checkbox:focus {
        border-color: #dc3545;
        box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
    }

    .form-check-label {
        user-select: none;
    }
        .form-check-label:hover {
            cursor: pointer;
            color: #1d3866;
        }
.required-asterisk {
    color: #dc3545;           /* Bootstrap's danger/red */
    font-size: 1.4rem;
    margin-left: 0.5rem;
    font-weight: bold;
    vertical-align: middle;
    background: none;
    border: none;
    padding: 0;
}

    </style>
</head>
<body class="g-sidenav-show bg-gray-100">
    <?php include 'components/aside.php'; ?>
    
    <div class="main-content position-relative border-radius-lg">
        <?php include 'components/navbar.php'; ?>
        
           <!-- Page Title Section -->
        <div class="d-flex align-items-center flex-grow-1" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); padding: 1rem 1.5rem; margin-bottom: 1.5rem;">
            <nav aria-label="breadcrumb" class="flex-grow-1">
            <ol class="breadcrumb mb-0 navbar-breadcrumb" style="background: transparent;">
                <li class="breadcrumb-item">
                <a href="main" class="breadcrumb-link" style="color: #ffd700; font-weight: 600;">
                    <i class="fas fa-home me-1" style="color: #ffd700;"></i>Home
                </a>
                </li>
                <li class="breadcrumb-item active navbar-breadcrumb-active" aria-current="page" style="color: #fff; font-weight: 700;">
                <?= $pageTitle ?>
                </li>
            </ol>
            <h5 class="navbar-title mb-0" style="color: #fff; text-shadow: 0 1px 8px #1e3c72, 0 0 2px #ffd700;">
                <?= $pageTitle ?>
            </h5>
            </nav>
        </div>

        <div class="container-fluid py-4">
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                            <h6>Form Management</h6>
                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newQuestionModal">
                                <i class="fas fa-plus me-1"></i> New Question
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= $_SESSION['success_message'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= $_SESSION['error_message'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <div class="row">
                <div class="col-12">
                    <div class="card mb-4">
                        <div class="card-header pb-0">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6>
                                    Existing Questions
                                    <span class="badge bg-gradient-primary ms-2"><?= count($questions) ?></span>
                                </h6>
                                <div class="input-group w-25">
                                    <input type="text" class="form-control" id="questionSearchInput" placeholder="Search questions...">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                </div>
                            </div>
                            <p class="text-sm mb-0">
                                <i class="fas fa-info-circle text-primary"></i>
                                All questions available in the system
                            </p>
                        </div>
                        <div class="card-body px-0 pt-0 pb-2">
                            <?php if ($questions): ?>
                                <div class="table-responsive p-0">
                                    <table class="table align-items-center mb-0" id="questionsTable">
                                        <thead>
                                            <tr>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Question</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Type</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Options</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Translations</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Created</th>
                                                <th class="text-secondary opacity-7"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($questions as $question): ?>
                                                <tr>
                                                    <td>
                                                        <div class="d-flex px-2 py-1">
                                                            <div class="d-flex flex-column justify-content-center">
                                                                <?php
                                                                    $label = htmlspecialchars($question['question_label']);
                                                                    // Break label into two lines if too long (e.g. > 60 chars)
                                                                    if (mb_strlen($label) > 60) {
                                                                        // Find a space near the middle to break at
                                                                        $breakPos = mb_strpos($label, ' ', (int)(mb_strlen($label) / 2));
                                                                        if ($breakPos !== false) {
                                                                            $firstLine = mb_substr($label, 0, $breakPos);
                                                                            $secondLine = mb_substr($label, $breakPos + 1);
                                                                        } else {
                                                                            // Fallback: just break at 60 chars
                                                                            $firstLine = mb_substr($label, 0, 60);
                                                                            $secondLine = mb_substr($label, 60);
                                                                        }
                                                                    } else {
                                                                        $firstLine = $label;
                                                                        $secondLine = '';
                                                                    }
                                                                ?>
                                                                <h6 class="mb-0 text-sm">
                                                                    <?= $firstLine ?>
                                                                    <?php if ($question['is_required']): ?>
                                                                      <span class="required-asterisk">*</span>

  
                                                                    <?php endif; ?>
                                                                </h6>
                                                                <?php if ($secondLine): ?>
                                                                    <h6 class="mb-0 text-sm"><?= $secondLine ?></h6>
                                                                <?php endif; ?>
                                                                <?php if (!empty($question['option_set_name'])): ?>
                                                                    <small class="text-muted">
                                                                        <i class="fas fa-list-ul me-1"></i>
                                                                        <?= htmlspecialchars($question['option_set_name']) ?>
                                                                    </small>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-sm bg-gradient-<?= 
                                                            $question['question_type'] === 'text' ? 'info' : 
                                                            ($question['question_type'] === 'radio' ? 'primary' : 
                                                            ($question['question_type'] === 'checkbox' ? 'warning' : 'secondary')) 
                                                        ?>">
                                                            <?= ucfirst($question['question_type']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="text-xs font-weight-bold"><?= $question['option_count'] ?> options</span>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($question['question_translations'])): ?>
                                                            <?php 
                                                                $translations = json_decode($question['question_translations'], true);
                                                                if (is_array($translations)) {
                                                                    foreach($translations as $lang => $text) {
                                                                        echo "<span class='badge badge-sm bg-gradient-secondary me-1'>{$lang}</span>";
                                                                    }
                                                                }
                                                            ?>
                                                        <?php else: ?>
                                                            <span class="text-xs text-muted">None</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="text-secondary text-xs font-weight-bold">
                                                            <?= date('M d, Y', strtotime($question['question_created'])) ?>
                                                        </span>
                                                    </td>
                                                    <td class="align-middle">
                                                        <a href="edit_question.php?id=<?= $question['question_id'] ?>" class="text-secondary font-weight-bold text-xs" data-toggle="tooltip" title="Edit">
                                                            <i class="fas fa-edit me-2"></i>
                                                        </a>
                                                        <form method="POST" style="display:inline">
                                                            <input type="hidden" name="question_id" value="<?= $question['question_id'] ?>">
                                                            <button type="submit" name="delete_question" class="text-danger font-weight-bold text-xs border-0 bg-transparent" 
                                                                    onclick="return confirm('Are you sure you want to delete this question?')">
                                                                <i class="fas fa-trash-alt"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-inbox fa-3x text-gray-300 mb-3"></i>
                                    <h6 class="text-muted">No questions found</h6>
                                    <p class="text-sm">Click "New Question" to add your first question</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="newQuestionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST" action="">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Question</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-control-label">Question Label</label>
                                    <input type="text" class="form-control" name="question_label" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-control-label">Question Type</label>
                                    <select class="form-select" name="question_type" required onchange="toggleOptionsSection(this.value)">
                                        <option value="text">Text</option>
                                        <option value="radio">Radio</option>
                                        <option value="checkbox">Checkbox</option>
                                        <option value="select">Dropdown</option>
                                          <option value="rating">Rating</option>
                                        <option value="textarea">Text Area</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <!-- Options Section -->
                        <div class="options-section mt-3" id="optionsSection" style="display:none;">
                            <label class="form-label fw-bold mb-2">
                                <i class="fas fa-sliders-h me-1 text-primary"></i> Options Configuration
                            </label>
                            <div class="d-flex gap-3 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="option_source" id="existingOptions" value="existing" checked>
                                    <label class="form-check-label" for="existingOptions">
                                        <i class="fas fa-database me-1"></i> Use Existing Option Set
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="option_source" id="customOptions" value="custom">
                                    <label class="form-check-label" for="customOptions">
                                        <i class="fas fa-pencil-alt me-1"></i> Create Custom Options
                                    </label>
                                </div>
                            </div>
                            <div class="existing-options mb-3" id="existingOptionsContainer">
                                <select class="form-select" name="option_set_id">
                                    <option value="">Select Option Set</option>
                                    <?php foreach ($optionSets as $optionSet): ?>
                                        <option value="<?= $optionSet['option_set_id'] ?>">
                                            <?= htmlspecialchars($optionSet['option_set_name']) ?> 
                                            (<?= htmlspecialchars($optionSet['options']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="custom-options mb-3" id="customOptionsContainer" style="display:none;">
                                <div id="customOptionsList">
                                    <div class="input-group mb-2">
                                        <span class="input-group-text bg-light"><i class="fas fa-list"></i></span>
                                        <input type="text" class="form-control" name="custom_options[]" placeholder="Option">
                                        <button class="btn btn-outline-danger" type="button" onclick="removeOption(this)">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="addOption()">
                                    <i class="fas fa-plus me-1"></i> Add Option
                                </button>
                            </div>
                        </div>

<!-- Surveys Section -->
                        <?php /* --- Survey Assignment Section (Refactored) --- */ ?>
<div class="surveys-section mt-4">
    <label class="form-label fw-bold mb-3">
        <i class="fas fa-poll me-2 text-primary"></i> Survey Assignment
    </label>

     <div class="mt-3 p-3 bg-light rounded">
        <small class="text-muted d-flex align-items-center">
            <i class="fas fa-info-circle me-2"></i>
            Select surveys to assign this question to. Mark as "Required" if the question must be answered in that survey.
        </small>
    </div>

    <div class="row g-3">
        <?php foreach ($surveys as $survey): ?>
            <div class="col-md-6">
                <div class="card h-100 border survey-card" style="border-color: #e0e0e0 !important; transition: all 0.3s ease;">
                    <div class="card-body py-3 px-4">
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center flex-grow-1">
                                <div class="form-check me-3">
                                    <input 
                                        class="form-check-input assign-survey-checkbox" 
                                        type="checkbox" 
                                        name="survey_ids[]" 
                                        value="<?= $survey['id'] ?>" 
                                        id="survey_<?= $survey['id'] ?>" 
                                        style="width: 18px; height: 18px; border: 2px solid #1d3866; border-radius: 4px;"
                                        data-survey-id="<?= $survey['id'] ?>"
                                    >
                                    <label class="form-check-label fw-medium text-dark" for="survey_<?= $survey['id'] ?>" style="font-size: 15px; cursor: pointer;">
                                        <?= htmlspecialchars($survey['name']) ?>
                                    </label>
                                </div>
                            </div>
                            <div class="d-flex align-items-center required-section" style="display: none;" data-survey-id="<?= $survey['id'] ?>">
                                <div class="form-check">
                                    <input 
                                        class="form-check-input required-survey-checkbox" 
                                        type="checkbox" 
                                        name="required_in_survey[<?= $survey['id'] ?>]" 
                                        id="required_<?= $survey['id'] ?>" 
                                        style="width: 16px; height: 16px; border: 2px solid #dc3545; border-radius: 4px;"
                                        data-survey-id="<?= $survey['id'] ?>"
                                    >
                                    <label 
                                        class="form-check-label text-danger fw-medium required-label" 
                                        for="required_<?= $survey['id'] ?>" 
                                        style="font-size: 13px; cursor: pointer;"
                                        data-survey-id="<?= $survey['id'] ?>"
                                    >
                                        <i class="fas fa-asterisk me-1" style="font-size: 10px;"></i>Required
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
   
</div>



<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.assign-survey-checkbox').forEach(function(assignCheckbox) {
        var surveyId = assignCheckbox.getAttribute('data-survey-id');
        var requiredSection = document.querySelector('.required-section[data-survey-id="' + surveyId + '"]');
        var requiredCheckbox = document.querySelector('.required-survey-checkbox[data-survey-id="' + surveyId + '"]');
        var surveyCard = assignCheckbox.closest('.survey-card');

        // Always hide required section on load
        requiredSection.style.display = 'none';
        surveyCard.classList.remove('selected');

        // Handle assign checkbox change
        assignCheckbox.addEventListener('change', function() {
            if (assignCheckbox.checked) {
                surveyCard.classList.add('selected');
                requiredSection.style.display = 'flex';
            } else {
                surveyCard.classList.remove('selected');
                requiredSection.style.display = 'none';
                requiredCheckbox.checked = false;
            }
        });

        // Add hover effects
        surveyCard.addEventListener('mouseenter', function() {
            if (!assignCheckbox.checked) {
                surveyCard.style.borderColor = '#1d3866';
                surveyCard.style.boxShadow = '0 2px 8px rgba(29, 56, 102, 0.15)';
            }
        });

        surveyCard.addEventListener('mouseleave', function() {
            if (!assignCheckbox.checked) {
                surveyCard.style.borderColor = '#e0e0e0';
                surveyCard.style.boxShadow = 'none';
            }
        });
    });
});
</script>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i> Cancel
                        </button>
                        <button type="submit" name="add_question" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Save Question
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- <?php include 'components/fixednav.php'; ?> -->

    <script src="argon-dashboard-master/assets/js/core/popper.min.js"></script>
    <script src="argon-dashboard-master/assets/js/core/bootstrap.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/argon-dashboard.js"></script>

    <script>
        // Available languages (ensure this is correctly populated from PHP)
        const availableLanguages = <?= json_encode($availableLanguages ?? []) ?>; // Added null coalesce for safety

        // Toggle options section based on question type
        function toggleOptionsSection(type) {
            const optionsSection = document.getElementById('optionsSection');
            if (['radio', 'checkbox', 'select', 'rating'].includes(type)) {
                optionsSection.style.display = 'block';
            } else {
                optionsSection.style.display = 'none';
            }
        }

        // Toggle between existing and custom options
        document.querySelectorAll('input[name="option_source"]').forEach(radio => {
            radio.addEventListener('change', function() {
                document.getElementById('existingOptionsContainer').style.display = 
                    this.value === 'existing' ? 'block' : 'none';
                document.getElementById('customOptionsContainer').style.display = 
                    this.value === 'custom' ? 'block' : 'none';
            });
        });

        // Add new option field
        function addOption() {
            const container = document.getElementById('customOptionsList');
            const newOption = document.createElement('div');
            newOption.className = 'input-group mb-2';
            newOption.innerHTML = `
                <input type="text" class="form-control" name="custom_options[]" placeholder="Option">
                <button class="btn btn-outline-danger" type="button" onclick="removeOption(this)">×</button>
            `;
            container.appendChild(newOption);
        }

        // Remove option field
        function removeOption(button) {
            button.closest('.input-group').remove();
        }

        // Add new translation field
        function addTranslation() {
            const container = document.getElementById('translationsContainer');
            const newTranslation = document.createElement('div');
            newTranslation.className = 'translation-entry mb-2';
            newTranslation.innerHTML = `
                <select class="form-select language-select" name="lang[]">
                    ${availableLanguages.map(lang => 
                        `<option value="${lang.code}">${lang.name}</option>`
                    ).join('')}
                </select>
                <input type="text" class="form-control" name="text[]" placeholder="Translated text">
                <button type="button" class="btn btn-outline-danger" onclick="removeTranslation(this)">×</button>
            `;
            container.appendChild(newTranslation);
        }

        // Remove translation field
        function removeTranslation(button) {
            button.closest('.translation-entry').remove();
        }

        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // --- Search Functionality Script ---
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('questionSearchInput');
            const questionsTableBody = document.querySelector('#questionsTable tbody');
            const tableRows = questionsTableBody.querySelectorAll('tr');

            searchInput.addEventListener('keyup', function() {
                const searchTerm = searchInput.value.toLowerCase();

                tableRows.forEach(row => {
                    let rowText = '';
                    // Get text from the Question Label column (first td)
                    const questionLabelCell = row.querySelector('td:first-child h6');
                    if (questionLabelCell) {
                        rowText += questionLabelCell.textContent.toLowerCase();
                    }
                    // You can add more columns to search if needed, e.g., question type
                    const questionTypeCell = row.querySelector('td:nth-child(2) .badge');
                    if (questionTypeCell) {
                        rowText += ' ' + questionTypeCell.textContent.toLowerCase();
                    }
                    
                    if (rowText.includes(searchTerm)) {
                        row.style.display = ''; // Show row
                    } else {
                        row.style.display = 'none'; // Hide row
                    }
                });
            });
        });
        // --- End Search Functionality Script ---
    </script>
</body>
</html>