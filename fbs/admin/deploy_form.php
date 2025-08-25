<?php
session_start();

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}
require_once 'includes/session_timeout.php';
require 'connect.php';

$surveyId = $_GET['survey_id'] ?? null;

if (!$surveyId) {
    $_SESSION['error_message'] = "Survey ID is missing.";
    header("Location: survey");
    exit();
}

// Fetch survey details
$surveyStmt = $pdo->prepare("SELECT * FROM survey WHERE id = ?");
$surveyStmt->execute([$surveyId]);
$survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);

if (!$survey) {
    $_SESSION['error_message'] = "Survey not found.";
    header("Location: survey");
    exit();
}

// Fetch all questions from the pool
$questionsStmt = $pdo->query("SELECT * FROM question ORDER BY label");
$questions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch questions already linked to the survey
$linkedQuestionsStmt = $pdo->prepare("
    SELECT q.* 
    FROM question q
    JOIN survey_question sq ON q.id = sq.question_id
    WHERE sq.survey_id = ?
    ORDER BY sq.position ASC
");
$linkedQuestionsStmt->execute([$surveyId]);
$linkedQuestions = $linkedQuestionsStmt->fetchAll(PDO::FETCH_ASSOC);



// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['link_questions'])) {
        try {
            $pdo->beginTransaction();
            
            $questionIds = $_POST['question_ids'] ?? [];
            
            // Remove existing links
            $stmt = $pdo->prepare("DELETE FROM survey_question WHERE survey_id = ?");
            $stmt->execute([$surveyId]);
            
            // Add new links
            foreach ($questionIds as $questionId) {
                $stmt = $pdo->prepare("INSERT INTO survey_question (survey_id, question_id) VALUES (?, ?)");
                $stmt->execute([$surveyId, $questionId]);
            }
            
            $pdo->commit();
            $_SESSION['success_message'] = "Questions linked to survey successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
        
        header("Location: survey?survey_id=$surveyId");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deploy Survey</title>
   <link href="asets/asets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="asets/asets/css/nucleo-svg.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="asets/asets/css/argon-dashboard.css" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100">
    <?php include 'components/aside.php'; ?>
    
    <div class="main-content position-relative border-radius-lg">
        <?php include 'components/navbar.php'; ?>

        <div class="container-fluid py-4">
            <h1>Deploy Survey: <?php echo htmlspecialchars($survey['name']); ?></h1>
            
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger"><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
            <?php endif; ?>

            <!-- Link Questions to Survey -->
            <div class="card">
                <div class="card-header">
                    <h3>Link Questions to Survey</h3>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="survey_id" value="<?php echo $surveyId; ?>">
                        <div class="mb-3">
    <label>Select Questions:</label>
    <?php foreach ($questions as $question): ?>
        <div class="form-check">
            <input type="checkbox" class="form-check-input" name="question_ids[]" value="<?php echo $question['id']; ?>"
                <?php echo in_array($question['id'], array_column($linkedQuestions, 'id')) ? 'checked' : ''; ?>>
            <label class="form-check-label"><?php echo htmlspecialchars($question['label']); ?></label>
        </div>
    <?php endforeach; ?>
</div>
                        <button type="submit" name="link_questions" class="btn btn-primary">Link Questions</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php include 'components/fixednav.php'; ?>
</body>
</html>