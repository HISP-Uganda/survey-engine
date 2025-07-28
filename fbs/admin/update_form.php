<?php
session_start();

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require 'connect.php';

// IMPORTANT: This file assumes a working database connection via 'connect.php'.
// If you are experiencing a 500 Internal Server Error (AH00124: Request exceeded the limit of 10 internal redirects),
// it is an Apache web server configuration issue, NOT a PHP code bug within this file.
// This error typically signifies a redirect loop in .htaccess or server config that Apache detects.
// This PHP code CANNOT fix a server-side redirect loop. You must resolve the Apache configuration first
// (e.g., by checking and correcting RewriteRule directives in your .htaccess file or httpd.conf).
// The styling and minor functional changes below will NOT resolve the 500 error.

// Optional: For debugging PHP errors if logs are inaccessible (REMOVE IN PRODUCTION)
// error_reporting(E_ALL);
// ini_set('display_errors', 1);
// ini_set('log_errors', 1);
// ini_set('error_log', __DIR__ . '/php-error.log');


// Check if it's an AJAX request
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_form'])) {
    $surveyId = $_POST['survey_id'] ?? null;
    $questionIds = $_POST['question_ids'] ?? []; // This array holds IDs in their final order
    
    if (!$surveyId) {
        $response = [
            'status' => 'error',
            'message' => 'Survey ID is missing.'
        ];
        
        if ($isAjax) {
            echo json_encode($response);
            exit();
        }
        
        $_SESSION['error_message'] = $response['message'];
        header("Location: survey.php"); // Redirect to survey list
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        // First, remove all existing questions from this survey
        $stmt = $pdo->prepare("DELETE FROM survey_question WHERE survey_id = ?");
        $stmt->execute([$surveyId]);
        
        // Then add the selected questions in the right order
        if (!empty($questionIds)) {
            $insertStmt = $pdo->prepare("INSERT INTO survey_question (survey_id, question_id, position) VALUES (?, ?, ?)");
            foreach ($questionIds as $position => $questionId) {
                // The 'position' in the foreach is 0-indexed, so add 1 for 1-based positioning
                $insertStmt->execute([$surveyId, (int)$questionId, $position + 1]); // Cast to int for safety
            }
        }
        
        $pdo->commit();
        
        $response = [
            'status' => 'success',
            'message' => 'Survey questions updated successfully!'
        ];
        
        if ($isAjax) {
            echo json_encode($response);
            exit();
        }
        
        $_SESSION['success_message'] = $response['message'];
        header("Location: preview_form.php?survey_id=" . $surveyId); // Redirect to preview_form.php
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        
        $response = [
            'status' => 'error',
            'message' => 'Error updating survey questions: ' . $e->getMessage()
        ];
        
        if ($isAjax) {
            echo json_encode($response);
            exit();
        }
        
        $_SESSION['error_message'] = $response['message'];
        header("Location: update_survey_form.php?survey_id=" . $surveyId); // Redirect back with error
        exit();
    }
}

// Handle GET request for loading form page
$surveyId = $_GET['survey_id'] ?? null;

if (!$surveyId) {
    $_SESSION['error_message'] = "Survey ID is missing.";
    header("Location: survey.php"); // Redirect to survey list
    exit();
}

// Fetch survey details
$surveyStmt = $pdo->prepare("SELECT * FROM survey WHERE id = ?");
$surveyStmt->execute([$surveyId]);
$survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);

if (!$survey) {
    $_SESSION['error_message'] = "Survey not found.";
    header("Location: survey.php"); // Redirect to survey list
    exit();
}

// Fetch all questions from the pool (select specific columns for efficiency)
$questionsStmt = $pdo->query("SELECT id, label, question_type, is_required FROM question ORDER BY label");
$questions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch questions already linked to the survey with their positions (select specific columns)
$linkedQuestionsStmt = $pdo->prepare("
    SELECT q.id, q.label, q.question_type, q.is_required, sq.position 
    FROM question q
    JOIN survey_question sq ON q.id = sq.question_id
    WHERE sq.survey_id = ?
    ORDER BY sq.position ASC
");
// Removed redundant fetch survey details
$linkedQuestionsStmt->execute([$surveyId]);
$linkedQuestions = $linkedQuestionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Extract IDs of linked questions for easier checking in the available list
$linkedQuestionIds = array_column($linkedQuestions, 'id');

// If this is an AJAX request for just the questions data (typically handled by a separate endpoint)
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'status' => 'success',
        'linkedQuestions' => $linkedQuestions,
        'allQuestions' => $questions
    ]);
    exit();
}

// Page title for display
$pageTitle = "Update Survey Form: " . htmlspecialchars($survey['name']);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link rel="icon" href="argon-dashboard-master/assets/img/brand/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="argon-dashboard-master/assets/css/nucleo-svg.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/argon-dashboard.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <style>
        /* General Body and Page Title Section - Light Theme */
        body.bg-gray-100 {
            background-color: #f8f9fa !important; /* Ensure a light background */
        }
        .main-content {
            background-color: #f8f9fa !important; /* Match main content background */
        }
        /* Page Title Section (adapted from your records.php light theme header) */
        .page-title-section {
            background: linear-gradient(135deg, #f0f4f8 0%, #e8edf3 100%); /* Light blue-gray gradient */
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        .page-title-section .breadcrumb-link,
        .page-title-section .breadcrumb-item.active {
            color: #344767 !important; /* Darker text for breadcrumbs on light background */
        }
        .page-title-section .breadcrumb-item a i {
            color: #4CAF50 !important; /* Green icon for Home */
        }
        .page-title-section .navbar-title {
            color: #212529 !important; /* Dark text for page title */
            text-shadow: none; /* No shadow for cleaner look */
        }

        /* Card Styling - Clean White */
        .card {
            background-color: #ffffff !important; /* Pure white card background */
            border-radius: 12px !important;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08) !important; /* Pronounced but soft shadow */
            border: none !important; /* Remove default card border */
            color: #212529 !important; /* Dark text for card content */
        }
        .card-header {
            background-color: #ffffff !important; /* White header background */
            padding: 1.5rem !important;
            border-bottom: 1px solid #e9ecef !important; /* Light border */
        }
        .card-header h3 { /* Specific for h3 in card-header */
            color: #344767 !important; /* Dark blue-gray for headers */
            font-weight: 700;
        }

        /* Forms and Inputs */
        .form-control, .input-group-text {
            border-radius: 8px !important;
            border: 1px solid #ced4da !important;
            background-color: #fff !important;
            color: #495057 !important;
        }
        .form-control:focus {
            border-color: #80bdff !important;
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25) !important;
        }
        .input-group-text {
            background-color: #e9ecef !important;
            color: #6c757d !important;
        }

        /* Question Panels - Longer and Styled */
        .questions-panel-container { /* New container to control height */
            height: calc(100vh - 350px); /* Adjust height based on viewport, or a fixed min-height */
            min-height: 600px; /* Minimum height for larger screens */
            max-height: calc(100vh - 200px); /* Max height to avoid overflowing small screens */
            overflow-y: auto;
            border: 1px solid #e9ecef; /* Subtle border for the entire panel area */
            border-radius: 12px;
            padding: 0; /* Remove internal padding, card-body already has it */
            margin-top: 1rem;
        }
        .questions-panel-container .card-body {
            padding: 1.5rem; /* Padding inside the card body */
        }
        .questions-panel { /* This will be the actual scrollable div inside card-body */
             /* The max-height and overflow-y are now managed by questions-panel-container */
             height: 100%; /* Take full height of its parent (card-body inside container) */
             display: flex;
             flex-direction: column;
             gap: 0.5rem; /* Spacing between question items */
        }

        /* Question Item Styling */
        .question-item {
            cursor: pointer;
            transition: all 0.2s ease;
            background-color: #fff !important;
            border: 1px solid #dee2e6; /* Light border */
            border-radius: 8px;
            padding: 1rem !important; /* More padding */
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05); /* Subtle shadow */
            display: flex; /* Ensure flex for alignment */
            align-items: center;
            justify-content: space-between;
        }
        .question-item:hover {
            background-color: #f8f9fa !important; /* Light hover background */
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1); /* Slightly stronger shadow on hover */
        }
        .question-item .flex-grow-1 { /* Added to label span's parent */
            display: flex;
            align-items: center;
        }
        .question-label {
            font-weight: 500;
            color: #344767;
            flex-grow: 1; /* Allow label to expand */
        }
        .question-item .text-muted.small {
            font-size: 0.8rem;
            color: #6c757d !important;
            margin-top: 0.25rem; /* Space below main label */
            display: block; /* Force to new line */
        }
        .question-number { /* For selected questions */
            font-weight: 700;
            margin-right: 12px; /* More space */
            color: #007bff; /* Primary color for numbers */
            min-width: 28px; /* Ensure consistent width */
            text-align: right;
        }
        .drag-handle {
            cursor: grab;
            color: #adb5bd; /* Muted color */
            font-size: 1.2rem; /* Larger icon */
            padding: 0 8px; /* Some padding */
        }
        .question-item.sortable-ghost {
            opacity: 0.5;
            background-color: #e9ecef !important; /* Ghost background */
            border: 1px dashed #007bff; /* Dashed border for ghost */
        }

        /* Search Container */
        .search-container {
            position: sticky;
            top: 0;
            background: white; /* Ensure white background when sticky */
            padding: 10px 0;
            z-index: 10;
            border-bottom: 1px solid #dee2e6; /* Light border */
        }

        /* Card Footer (for save button) */
        .card-footer {
            background-color: #ffffff !important; /* White footer */
            border-top: 1px solid #e9ecef !important; /* Light border */
            padding: 1.5rem !important;
        }

        /* Alerts Container (using SweetAlert2 for display now) */
        /* These styles are for the default Bootstrap alerts if used as fallback */
        #alerts-container .alert {
            border-radius: 8px;
            font-weight: 500;
        }
        #alerts-container .alert-success {
            background-color: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }
        #alerts-container .alert-danger {
            background-color: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }
        
        /* Empty questions message */
        #no-questions-message {
            padding: 2rem;
            background-color: #f8f9fa;
            border-radius: 8px;
            border: 1px dashed #dee2e6;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100">
    <?php include 'components/aside.php'; ?>
    
    <div class="main-content position-relative border-radius-lg">
        <?php include 'components/navbar.php'; ?>

        <div class="d-flex align-items-center flex-grow-1 page-title-section">
            <nav aria-label="breadcrumb" class="flex-grow-1">
                <ol class="breadcrumb mb-0 navbar-breadcrumb" style="background: transparent;">
                    <li class="breadcrumb-item">
                        <a href="main" class="breadcrumb-link">
                            <i class="fas fa-home me-1"></i>Home
                        </a>
                    </li>
                    <li class="breadcrumb-item active navbar-breadcrumb-active" aria-current="page">
                        Survey Questions
                    </li>
                </ol>
                <h5 class="navbar-title mb-0">
                    Update Survey Form: <?php echo htmlspecialchars($survey['name']); ?>
                </h5>
            </nav>
        </div>
        
        <div class="container-fluid py-4">
            <div class="row mb-4">
                <div class="col-lg-8">
                    <h4 class="mb-0 text-dark">Configure Questions for: <?php echo htmlspecialchars($survey['name']); ?></h4>
                    <p class="text-muted text-sm">Drag and drop questions to define their order in the survey.</p>
                </div>
                <div class="col-lg-4 text-end">
                    <a href="survey.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Surveys
                    </a>
                </div>
            </div>
            
            <div id="alerts-container">
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
            </div>
            
            <form id="update-form" method="POST">
                <input type="hidden" name="survey_id" value="<?php echo $surveyId; ?>">
                
                <div class="row">
                    <div class="col-lg-6">
                        <div class="card shadow-lg">
                            <div class="card-header pb-0">
                                <h3><i class="fas fa-list-ul me-2 text-info"></i>Available Questions</h3>
                                <div class="search-container mt-2">
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="fas fa-search"></i>
                                        </span>
                                        <input type="text" id="search-questions" class="form-control" placeholder="Search questions...">
                                    </div>
                                </div>
                            </div>
                            <div class="questions-panel-container">
                                <div class="card-body questions-panel">
                                    <div id="available-questions">
                                        <?php foreach ($questions as $question): 
                                            $isLinked = in_array($question['id'], $linkedQuestionIds);
                                        ?>
                                        <div id="available-question-<?php echo $question['id']; ?>" 
                                            class="question-item p-2 mb-2 <?php echo $isLinked ? 'd-none' : ''; ?>">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div class="flex-grow-1">
                                                    <span class="question-label"><?php echo htmlspecialchars($question['label']); ?></span>
                                                    <span class="text-muted small">
                                                        Type: <?php echo ucfirst($question['question_type']); ?> | 
                                                        Required: <?php echo $question['is_required'] ? 'Yes' : 'No'; ?>
                                                    </span>
                                                </div>
                                                <button type="button" class="btn btn-sm btn-outline-primary add-question-btn" 
                                                    data-question-id="<?php echo $question['id']; ?>"
                                                    data-question-label="<?php echo htmlspecialchars($question['label']); ?>"
                                                    data-question-type="<?php echo htmlspecialchars($question['question_type']); ?>"
                                                    data-question-required="<?php echo htmlspecialchars($question['is_required']); ?>">
                                                    <i class="fas fa-plus"></i> Add
                                                </button>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-6">
                        <div class="card shadow-lg">
                            <div class="card-header pb-0">
                                <h3><i class="fas fa-check-square me-2 text-success"></i>Selected Questions</h3>
                                <p class="text-sm text-muted">Drag questions to reorder them.</p>
                            </div>
                            <div class="questions-panel-container">
                                <div class="card-body questions-panel">
                                    <div id="selected-questions">
                                        <?php foreach ($linkedQuestions as $index => $question): ?>
                                        <div id="selected-question-<?php echo $question['id']; ?>" 
                                            class="question-item p-2 mb-2 d-flex justify-content-between align-items-center">
                                            <div class="drag-handle me-2"><i class="fas fa-grip-lines"></i></div>
                                            <div class="flex-grow-1">
                                                <span class="question-number"><?php echo $question['position'] ?? ($index + 1); ?>.</span>
                                                <span class="question-label"><?php echo htmlspecialchars($question['label']); ?></span>
                                                <span class="text-muted small">
                                                    Type: <?php echo ucfirst($question['question_type']); ?> | 
                                                    Required: <?php echo $question['is_required'] ? 'Yes' : 'No'; ?>
                                                </span>
                                            </div>
                                            <input type="hidden" name="question_ids[]" value="<?php echo $question['id']; ?>">
                                            <button type="button" class="btn btn-sm btn-outline-danger remove-question-btn">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <?php if (empty($linkedQuestions)): ?>
                                    <div id="no-questions-message" class="text-center text-muted py-4">
                                        <i class="fas fa-info-circle fa-2x mb-2"></i>
                                        <p>No questions have been added to this survey yet. Add questions from the available list.</p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-footer">
                                <button type="submit" name="update_form" class="btn btn-success w-100">
                                    <i class="fas fa-save me-2"></i> Save Survey Questions
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script src="asets/asets/js/core/popper.min.js"></script>
    <script src="asets/asets/js/core/bootstrap.min.js"></script>
    <script src="asets/asets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="asets/asets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.14.0/Sortable.min.js"></script>
    <script src="asets/asets/js/argon-dashboard.js"></script>
    <!-- <script src="js/update_form.js"></script> -->

    <script>
   document.addEventListener('DOMContentLoaded', function() {
    const updateForm = document.getElementById('update-form');
    const selectedQuestionsContainer = document.getElementById('selected-questions');
    const availableQuestionsContainer = document.getElementById('available-questions');
    const noQuestionsMessage = document.getElementById('no-questions-message');
    
    // Initialize the sortable question list
    if (selectedQuestionsContainer) {
        new Sortable(selectedQuestionsContainer, {
            animation: 150,
            ghostClass: 'bg-light',
            handle: '.drag-handle',
            onEnd: function() {
                // Update hidden position inputs and question numbers after drag
                updatePositionValues();
                updateQuestionNumbers();
            }
        });
    }
    
    // Update the hidden position fields after sorting
    function updatePositionValues() {
        const questionItems = selectedQuestionsContainer.querySelectorAll('.question-item');
        questionItems.forEach((item, index) => {
            const positionInput = item.querySelector('.question-position');
            if (positionInput) {
                positionInput.value = index + 1;
            }
        });
    }
    
    // Update the question numbers in the selected questions list
    function updateQuestionNumbers() {
        const questionItems = selectedQuestionsContainer.querySelectorAll('.question-item');
        questionItems.forEach((item, index) => {
            const questionNumber = item.querySelector('.question-number');
            const positionInput = item.querySelector('.question-position');
            
            if (questionNumber && positionInput) {
                const newNumber = index + 1;
                questionNumber.textContent = `${newNumber}.`;
                positionInput.value = newNumber; // Update position value
            }
        });
        
        // Toggle no questions message
        if (noQuestionsMessage) {
            noQuestionsMessage.style.display = questionItems.length === 0 ? 'block' : 'none';
        }
    }
    
    // Move question between available and selected lists
    function setupQuestionTransfer() {
        // Add to selected questions
        document.querySelectorAll('.add-question-btn').forEach(button => {
            button.addEventListener('click', function() {
                const questionId = this.dataset.questionId;
                const questionLabel = this.dataset.questionLabel;
                
                // Check if already selected
                if (document.querySelector(`#selected-question-${questionId}`)) {
                    showAlert('warning', 'This question is already added to the survey.');
                    return;
                }
                
                // Get next question number
                const nextNumber = selectedQuestionsContainer.querySelectorAll('.question-item').length + 1;
                
                // Create new selected question element
                const newQuestionElement = document.createElement('div');
                newQuestionElement.id = `selected-question-${questionId}`;
                newQuestionElement.className = 'question-item p-2 mb-2 bg-white border rounded d-flex justify-content-between align-items-center';
                newQuestionElement.innerHTML = `
                    <div class="drag-handle me-2"><i class="fas fa-grip-lines"></i></div>
                    <div class="flex-grow-1">
                        <span class="question-number">${nextNumber}.</span>
                        <span class="question-label">${questionLabel}</span>
                    </div>
                    <input type="hidden" name="question_ids[]" value="${questionId}">
                    <input type="hidden" class="question-position" name="positions[]" value="${nextNumber}">
                    <input type="hidden" class="question-number-input" name="question_numbers[]" value="${nextNumber}">
                    <button type="button" class="btn btn-sm btn-outline-danger remove-question-btn">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                
                selectedQuestionsContainer.appendChild(newQuestionElement);
                
                // Hide no questions message if present
                if (noQuestionsMessage) {
                    noQuestionsMessage.style.display = 'none';
                }
                
                updatePositionValues();
                updateQuestionNumbers();
                
                // Hide from available list
                const availableItem = document.querySelector(`#available-question-${questionId}`);
                if (availableItem) {
                    availableItem.classList.add('d-none');
                }
                
                // Add remove event listener
                newQuestionElement.querySelector('.remove-question-btn').addEventListener('click', function() {
                    removeQuestion(questionId);
                });
            });
        });
        
        // Remove from selected questions
        document.querySelectorAll('.remove-question-btn').forEach(button => {
            button.addEventListener('click', function() {
                const questionItem = this.closest('.question-item');
                const questionId = questionItem.querySelector('input[name="question_ids[]"]').value;
                removeQuestion(questionId);
            });
        });
    }
    
    function removeQuestion(questionId) {
        // Remove from selected
        const selectedItem = document.querySelector(`#selected-question-${questionId}`);
        if (selectedItem) {
            selectedItem.remove();
        }
        
        // Show in available list
        const availableItem = document.querySelector(`#available-question-${questionId}`);
        if (availableItem) {
            availableItem.classList.remove('d-none');
        }
        
        updatePositionValues();
        updateQuestionNumbers();
        
        // Show no questions message if there are no selected questions
        if (noQuestionsMessage && selectedQuestionsContainer.querySelectorAll('.question-item').length === 0) {
            noQuestionsMessage.style.display = 'block';
        }
    }
    
    // Search functionality
    const searchInput = document.getElementById('search-questions');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const questionItems = availableQuestionsContainer.querySelectorAll('.question-item:not(.d-none)');
            
            questionItems.forEach(item => {
                const questionText = item.textContent.toLowerCase();
                if (questionText.includes(searchTerm)) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    }
    
    // Form submission
    if (updateForm) {
        updateForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Ensure positions and question numbers are updated before submitting
            updatePositionValues();
            updateQuestionNumbers();
            
            const formData = new FormData(this);
            formData.append('update_form', '1');
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
            
            fetch('update_form.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                // Reset button state
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
                
                if (data.status === 'success') {
                    showAlert('success', data.message);
                    
                    // Redirect to preview_form.php with survey_id
                    const surveyId = document.querySelector('input[name="survey_id"]').value;
                    window.location.href = `preview_form?survey_id=${surveyId}`;
                } else {
                    showAlert('danger', data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
                showAlert('danger', 'An unexpected error occurred. Please try again.');
            });
        });
    }
    
    // Helper function to show alerts
    function showAlert(type, message) {
        const alertsContainer = document.getElementById('alerts-container');
        if (!alertsContainer) return;
        
        const alertElement = document.createElement('div');
        alertElement.className = `alert alert-${type} alert-dismissible fade show`;
        alertElement.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
        
        alertsContainer.innerHTML = '';
        alertsContainer.appendChild(alertElement);
        
        // Scroll to alert
        window.scrollTo({top: 0, behavior: 'smooth'});
        
        // Auto dismiss after 5 seconds
        setTimeout(() => {
            alertElement.classList.remove('show');
            setTimeout(() => {
                alertElement.remove();
            }, 150);
        }, 5000);
    }
    
    // Initialize
    setupQuestionTransfer();
    updateQuestionNumbers(); // Initial numbering of questions
});
    
    </script>
</body>
</html
                                     