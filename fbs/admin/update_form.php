<?php
session_start();

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require 'connect.php';

// Check if it's an AJAX request
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_form'])) {
    $surveyId = $_POST['survey_id'] ?? null;
    $questionIds = $_POST['question_ids'] ?? [];
    
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
        header("Location: survey.php");
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
                $insertStmt->execute([$surveyId, $questionId, $position + 1]); // Position starts from 1
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
        header("Location: preview_form.php?survey_id=" . $surveyId); // Redirect to preview_form.php with survey_id
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
        header("Location: update_survey_form.php?survey_id=" . $surveyId);
        exit();
    }
}

// Handle GET request for loading form page
$surveyId = $_GET['survey_id'] ?? null;

if (!$surveyId) {
    $_SESSION['error_message'] = "Survey ID is missing.";
    header("Location: survey.php");
    exit();
}

// Fetch survey details
$surveyStmt = $pdo->prepare("SELECT * FROM survey WHERE id = ?");
$surveyStmt->execute([$surveyId]);
$survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);

if (!$survey) {
    $_SESSION['error_message'] = "Survey not found.";
    header("Location: survey.php");
    exit();
}

// Fetch all questions from the pool
$questionsStmt = $pdo->query("SELECT * FROM question ORDER BY label");
$questions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch questions already linked to the survey with their positions
$linkedQuestionsStmt = $pdo->prepare("
    SELECT q.*, sq.position 
    FROM question q
    JOIN survey_question sq ON q.id = sq.question_id
    WHERE sq.survey_id = ?
    ORDER BY sq.position ASC
");
// Fetch survey details
$surveyStmt = $pdo->prepare("SELECT * FROM survey WHERE id = ?");
$surveyStmt->execute([$surveyId]);
$survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);

if (!$survey) {
    $_SESSION['error_message'] = "Survey not found.";
    header("Location: survey.php");
    exit();
}




$linkedQuestionsStmt->execute([$surveyId]);
$linkedQuestions = $linkedQuestionsStmt->fetchAll(PDO::FETCH_ASSOC);

$linkedQuestionIds = array_column($linkedQuestions, 'id');

// If this is an AJAX request for just the questions data
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'status' => 'success',
        'linkedQuestions' => $linkedQuestions,
        'allQuestions' => $questions
    ]);
    exit();
}



?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Survey Form - <?php echo htmlspecialchars($survey['name']); ?></title>
    <link href="asets/asets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="asets/asets/css/nucleo-svg.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="asets/asets/css/argon-dashboard.css" rel="stylesheet" />
    <style>
        .question-item {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .question-item:hover {
            background-color: #f8f9fa !important;
        }
        .drag-handle {
            cursor: grab;
            color: #adb5bd;
        }
        .question-item.sortable-ghost {
            opacity: 0.5;
            background: #e9ecef !important;
        }
        .search-container {
            position: sticky;
            top: 0;
            background: white;
            padding: 10px 0;
            z-index: 10;
            border-bottom: 1px solid #dee2e6;
        }
        .questions-panel {
            max-height: 500px;
            overflow-y: auto;
        }
        .question-number {
            font-weight: bold;
            margin-right: 8px;
            min-width: 24px;
            display: inline-block;
            text-align: right;
        }
    
    </style>
</head>
<body class="g-sidenav-show bg-gray-100">
    <?php include 'components/aside.php'; ?>
    
    <div class="main-content position-relative border-radius-lg">
        <?php include 'components/navbar.php'; ?>

        <div class="container-fluid py-4">
            <div class="row mb-4">
                <div class="col-lg-8">
                 
                    <h4 class="text-muted"><?php echo htmlspecialchars($survey['name']); ?></h4>
                </div>
                <div class="col-lg-4 text-end">
                    <a href="survey" class="btn btn-outline-primary">
                        <i class="fas fa-arrow-left"></i> Back to Surveys
                    </a>
                </div>
            </div>
            
            <!-- Alerts Container -->
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
            
            <!-- Update Form -->
            <form id="update-form" method="POST">
                <input type="hidden" name="survey_id" value="<?php echo $surveyId; ?>">
                
                <div class="row">
                    <!-- Available Questions Panel -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h3>Available Questions</h3>
                                <div class="search-container mt-2">
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="fas fa-search"></i>
                                        </span>
                                        <input type="text" id="search-questions" class="form-control" placeholder="Search questions...">
                                    </div>
                                </div>
                            </div>
                            <div class="card-body questions-panel">
                                <div id="available-questions">
                                    <?php foreach ($questions as $question): 
                                        $isLinked = in_array($question['id'], $linkedQuestionIds);
                                    ?>
                                    <div id="available-question-<?php echo $question['id']; ?>" 
                                        class="question-item p-2 mb-2 bg-white border rounded <?php echo $isLinked ? 'd-none' : ''; ?>">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div><?php echo htmlspecialchars($question['label']); ?></div>
                                            <button type="button" class="btn btn-sm btn-primary add-question-btn" 
                                                data-question-id="<?php echo $question['id']; ?>"
                                                data-question-label="<?php echo htmlspecialchars($question['label']); ?>">
                                                <i class="fas fa-plus"></i> Add
                                            </button>
                                        </div>
                                        <div class="text-muted small">
                                            Type: <?php echo ucfirst($question['question_type']); ?> | 
                                            Required: <?php echo $question['is_required'] ? 'Yes' : 'No'; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Selected Questions Panel -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h3>Selected Questions</h3>
                                <div class="text-muted">Drag questions to reorder</div>
                            </div>
                            <div class="card-body questions-panel">
                                <div id="selected-questions">
                                    <?php foreach ($linkedQuestions as $index => $question): ?>
                                    <div id="selected-question-<?php echo $question['id']; ?>" class="question-item p-2 mb-2 bg-white border rounded d-flex justify-content-between align-items-center">
                                        <div class="drag-handle me-2"><i class="fas fa-grip-lines"></i></div>
                                        <div class="flex-grow-1">
                                            <span class="question-number"><?php echo $question['position'] ?? ($index + 1); ?>.</span>
                                            <span class="question-label"><?php echo htmlspecialchars($question['label']); ?></span>
                                        </div>
                                        <input type="hidden" name="question_ids[]" value="<?php echo $question['id']; ?>">
                                        <input type="hidden" class="question-position" name="positions[]" value="<?php echo $question['position']; ?>">
                                        <input type="hidden" class="question-number-input" name="question_numbers[]" value="<?php echo $question['position'] ?? ($index + 1); ?>">
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
                            <div class="card-footer">
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="fas fa-save"></i> Save Survey Questions
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- <?php include 'components/fixednav.php'; ?> -->

    <script src="asets/asets/js/core/popper.min.js"></script>
    <script src="asets/asets/js/core/bootstrap.min.js"></script>
    <script src="asets/asets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="asets/asets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.14.0/Sortable.min.js"></script>
    <script src="asets/asets/js/argon-dashboard.js"></script>
    <script src="js/update_form.js"></script>

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
</html>