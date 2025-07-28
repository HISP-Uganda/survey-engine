<?php
session_start();

// Authentication check
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require 'connect.php';

// Initialize variables
$surveys = [];

// Fetch surveys from database
try {
    $stmt = $pdo->query("
        SELECT survey.*, 
               (SELECT COUNT(*) 
                FROM survey_question 
                WHERE survey_question.survey_id = survey.id) AS question_count 
        FROM survey
    ");
    $surveys = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Failed to fetch surveys: " . $e->getMessage();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['deploy_survey'])) {
        $surveyId = $_POST['survey_id'];
        header("Location: update_form.php?survey_id=" . urlencode($surveyId));
        exit();
    }
    
    if (isset($_POST['update_status'])) {
        $surveyId = $_POST['survey_id'];
        $newStatus = $_POST['is_active'];
        
        try {
            $stmt = $pdo->prepare("UPDATE survey SET is_active = ? WHERE id = ?");
            $stmt->execute([$newStatus, $surveyId]);
            $_SESSION['success_message'] = "Survey status updated successfully";
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Failed to update survey status: " . $e->getMessage();
        }
        
        header("Location: survey.php");
        exit();
    }
    
    if (isset($_POST['create_survey'])) {
        header("Location: sb.php");
        exit();
}
    
    if (isset($_POST['delete_survey'])) {
        $surveyId = $_POST['survey_id'];
        
        try {
            $pdo->beginTransaction();
            
            // Delete survey questions links
            $stmt = $pdo->prepare("DELETE FROM survey_question WHERE survey_id = ?");
            $stmt->execute([$surveyId]);
            
            // Delete the survey
            $stmt = $pdo->prepare("DELETE FROM survey WHERE id = ?");
            $stmt->execute([$surveyId]);
            
            $pdo->commit();
            $_SESSION['success_message'] = "Survey deleted successfully";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Failed to delete survey: " . $e->getMessage();
        }
        
        header("Location: survey.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Survey Management</title>
    <!-- Argon Dashboard CSS -->
    <link href="argon-dashboard-master/assets/css/nucleo-icons.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-svg.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/argon-dashboard.min.css" rel="stylesheet">
</head>
<body class="g-sidenav-show bg-gray-100">
    <?php include 'components/aside.php'; ?>
    
    <main class="main-content position-relative border-radius-lg">
       

           <!-- Page Title Section -->
      <div class="d-flex align-items-center flex-grow-1 py-3 px-2" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);">
            <nav aria-label="breadcrumb" class="flex-grow-1">
                <ol class="breadcrumb mb-1 navbar-breadcrumb" style="background: transparent;">
                    <li class="breadcrumb-item">
                        <a href="main" class="breadcrumb-link" style="color: #ffd700; font-weight: 600;">
                            <i class="fas fa-home me-1" style="color: #ffd700;"></i>Home
                        </a>
                    </li>
                    <li class="breadcrumb-item active navbar-breadcrumb-active" aria-current="page" style="color: #fff; font-weight: 700;">
                        <?= htmlspecialchars($pageTitle ?? 'Survey') ?>
                    </li>
                </ol>
                <h4 class="navbar-title mb-0 mt-1" style="color: #fff; text-shadow: 0 1px 8px #1e3c72, 0 0 2px #ffd700; font-weight: 700;">
                    <?= htmlspecialchars($pageTitle ?? 'Survey') ?>
                </h4>
            </nav>
        </div>
        <div class="container-fluid py-4">
            <!-- Alerts -->
            <div id="alerts-container">
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show custom-alert" role="alert">
                        <span class="alert-icon"><i class="ni ni-like-2"></i></span>
                        <span class="alert-text"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></span>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show custom-alert" role="alert">
                        <span class="alert-icon"><i class="ni ni-support-16"></i></span>
                        <span class="alert-text"><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></span>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
            <style>
                .custom-alert {
                    transition: opacity 0.5s, transform 0.5s;
                }
                .custom-alert.hide {
                    opacity: 0;
                    transform: translateY(-20px) scale(0.95);
                }
            </style>
            <script>
                // Auto-hide alerts after 10 seconds with animation
                window.addEventListener('DOMContentLoaded', function() {
                    setTimeout(function() {
                        document.querySelectorAll('.custom-alert').forEach(function(alert) {
                            alert.classList.add('hide');
                            setTimeout(function() {
                                alert.remove();
                            }, 500); // Wait for animation to finish
                        });
                    }, 10000);
                });
            </script>

           

            <!-- Existing Surveys Card -->
            <div class="card">
               
                <div class="card-header pb-0">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <a href="sb.php" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i> Create New Survey
                        </a>
                        <button type="button" class="btn btn-outline-secondary ms-2" id="configAccessBtn">
                            <i class="fas fa-cog me-2"></i> Config
                        </button>
                        <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                document.getElementById('configAccessBtn').addEventListener('click', function() {
                                    var pwd = prompt('Enter password to access config:');
                                    if (pwd === 'config') {
                                        window.location.href = 'config.php';
                                    } else if (pwd !== null) {
                                        alert('Incorrect password.');
                                    }
                                });
                            });
                        </script>
                    </div>
                     <div class="d-flex justify-content-between align-items-center mb-3">
                       
                        <div class="d-flex align-items-left">
                            <h6 class="mb-0">Existing Surveys</h6>
                        </div>
                        <div>
                            <span class="badge bg-gradient-primary ms-2"><?php echo count($surveys); ?> surveys</span>
                        </div>
                    </div>
                <div class="card-body px-0 pt-0 pb-2">
                    <?php if ($surveys): ?>
                        <div class="table-responsive p-0">
                            <table class="table align-items-center mb-0 survey-table">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Survey Name</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Type</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Dates</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Questions</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($surveys as $survey): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex px-2 py-1">
                                                <div>
                                                    <h6 class="mb-0 text-sm"><?php echo htmlspecialchars($survey['name']); ?></h6>
                                                    <!-- <?php if ($survey['type'] === 'dhis2'): ?>
                                                        <p class="text-xs text-secondary mb-0">
                                                            <?php echo htmlspecialchars($survey['dhis2_instance']); ?> / 
                                                            <?php echo htmlspecialchars($survey['domain_type']); ?> / 
                                                            <?php echo htmlspecialchars($survey['program_dataset']); ?>
                                                        </p>
                                                    <?php endif; ?> -->
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-sm <?php echo $survey['type'] === 'local' ? 'bg-gradient-secondary' : 'bg-gradient-info'; ?>">
                                                <?php echo strtoupper($survey['type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($survey['start_date'] || $survey['end_date']): ?>
                                                <p class="text-xs font-weight-bold mb-0">
                                                    <?php echo $survey['start_date'] ? date('M j, Y', strtotime($survey['start_date'])) : 'N/A'; ?> - 
                                                    <?php echo $survey['end_date'] ? date('M j, Y', strtotime($survey['end_date'])) : 'N/A'; ?>
                                                </p>
                                            <?php else: ?>
                                                <p class="text-xs text-secondary mb-0">No dates set</p>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="survey_id" value="<?php echo $survey['id']; ?>">
                                                <input type="hidden" name="is_active" value="<?php echo $survey['is_active'] ? 0 : 1; ?>">
                                                <button type="submit" name="update_status" class="btn btn-link p-0 border-0 bg-transparent">
                                                    <i class="fas fa-power-off status-toggle <?php echo $survey['is_active'] ? 'active' : 'inactive'; ?>"></i>
                                                    <span class="text-xs ms-1"><?php echo $survey['is_active'] ? 'Active' : 'Inactive'; ?></span>
                                                </button>
                                            </form>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <span class="badge badge-sm bg-gradient-secondary me-2"><?php echo $survey['question_count']; ?></span>
                                                <button class="btn btn-sm btn-outline-dark mb-0" onclick="toggleQuestions(<?php echo $survey['id']; ?>)">
                                                    <i class="fas fa-eye me-1" id="toggle-icon-<?php echo $survey['id']; ?>"></i>
                                                    <span class="d-none d-md-inline">View</span>
                                                </button>
                                            </div>
                                            
                                            <!-- Questions List (Hidden by default) -->
                                            <div id="questions-<?php echo $survey['id']; ?>" class="question-list-container d-none">
                                                <?php
                                                $stmt = $pdo->prepare("
                                                    SELECT q.id, q.label, q.question_type, sq.position
                                                    FROM question q
                                                    JOIN survey_question sq ON q.id = sq.question_id
                                                    WHERE sq.survey_id = ?
                                                    ORDER BY sq.position ASC
                                                ");
                                                $stmt->execute([$survey['id']]);
                                                $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                                
                                                if ($questions):
                                                    foreach ($questions as $question):
                                                ?>
                                                    <div class="question-item">
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <span class="text-sm"><?php echo htmlspecialchars($question['label']); ?></span>
                                                            <span class="badge badge-xs <?php echo $question['question_type'] === 'text' ? 'bg-gradient-info' : 'bg-gradient-success'; ?>">
                                                                <?php echo $question['question_type']; ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                <?php
                                                    endforeach;
                                                else:
                                                ?>
                                                    <div class="text-center p-3">
                                                        <p class="text-xs text-secondary mb-0">No questions added yet</p>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        
                                        <td class="text-end">
                                            <div class="d-flex justify-content-end gap-2">
                                                <!-- <?php if ($survey['type'] === 'local'): ?>
                                                    <a href="pb.php?survey_id=<?php echo urlencode($survey['id']); ?>" class="btn btn-sm btn-outline-primary mb-0 action-btn" title="Publish to DHIS2">
                                                        <i class="fas fa-cloud-upload-alt"></i>
                                                        <span class="btn-text d-none d-md-inline">DHIS2 Publisher</span>
                                                    </a>
                                                <?php endif; ?> -->

                                                <?php if ($survey['is_active']): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="survey_id" value="<?php echo $survey['id']; ?>">
                                                        <button type="submit" name="deploy_survey" class="btn btn-sm btn-outline-success mb-0 action-btn">
                                                            <i class="fas fa-cog"></i>
                                                            <span class="btn-text d-none d-md-inline">View Details</span>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-outline-secondary mb-0 action-btn disabled-btn" title="Survey must be active to deploy">
                                                        <i class="fas fa-cog"></i>
                                                        <span class="btn-text d-none d-md-inline">Inactive</span>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="survey_id" value="<?php echo $survey['id']; ?>">
                                                    <button type="submit" name="delete_survey" class="btn btn-sm btn-outline-danger mb-0 action-btn"
                                                            onclick="return confirm('Are you sure you want to delete this survey?')">
                                                        <i class="fas fa-trash"></i>
                                                        <span class="btn-text d-none d-md-inline">Delete</span>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center p-4">
                            <i class="fas fa-inbox fa-3x text-gray-300 mb-3"></i>
                            <h6 class="text-gray-500 mb-1">No surveys created yet</h6>
                            <p class="text-sm text-gray-400">Create your first survey using the button above</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

<!--    
    <?php include 'components/fixednav.php'; ?> -->
    
    <!-- Argon Dashboard JS -->
    <script src="argon-dashboard-master/assets/js/core/popper.min.js"></script>
    <script src="argon-dashboard-master/assets/js/core/bootstrap.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/argon-dashboard.min.js"></script>
    
    <script>
        // Toggle questions visibility
        function toggleQuestions(surveyId) {
            const questionsDiv = document.getElementById(`questions-${surveyId}`);
            const toggleIcon = document.getElementById(`toggle-icon-${surveyId}`);
            
            if (questionsDiv.classList.contains('d-none')) {
                questionsDiv.classList.remove('d-none');
                toggleIcon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                questionsDiv.classList.add('d-none');
                toggleIcon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }
        
    
        
    </script>
</body>
</html>