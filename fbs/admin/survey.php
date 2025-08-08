<?php
session_start();

// Authentication check
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}
require_once 'includes/session_timeout.php';

require 'connect.php';

// Helper function to check if current user can delete
function canUserDelete() {
    if (!isset($_SESSION['admin_role_name']) && !isset($_SESSION['admin_role_id'])) {
        return false;
    }
    
    // Super users can delete - check by role name or role ID
    $roleName = $_SESSION['admin_role_name'] ?? '';
    $roleId = $_SESSION['admin_role_id'] ?? 0;
    
    return ($roleName === 'super_user' || $roleName === 'admin' || $roleId == 1);
}

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
        // Check if user has permission to delete
        if (!canUserDelete()) {
            $_SESSION['error_message'] = "Access denied. Only super users can delete surveys.";
            header("Location: survey.php");
            exit();
        }
        
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
<style>
    /* Neutral styling with improved design */
    body {
        background-color: #f8f9fa !important;
    }
    
    /* Header container */
    .header-container-light {
        background: #ffffff;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        margin-bottom: 1.5rem;
        padding: 1rem 1.5rem;
    }
    
    .breadcrumb-link-light {
        color: #4a5568 !important;
        font-weight: 600;
        text-decoration: none;
        transition: none;
    }
    
    .breadcrumb-link-light:hover {
        color: #2d3748 !important;
    }
    
    .breadcrumb-item-active-light {
        color: #2d3748 !important;
        font-weight: 700;
    }
    
    .navbar-title-light {
        color: #2d3748;
        text-shadow: none;
        font-size: 1.1rem;
    }
    
    /* Card styling */
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
    
    .card-header h6 {
        color: #2d3748 !important;
        font-size: 1rem !important;
        margin-bottom: 0;
    }
    
    .card-body {
        padding: 1rem !important;
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
    
    .btn-outline-primary {
        color: #4a5568 !important;
        border-color: #4a5568 !important;
    }
    
    .btn-outline-primary:hover {
        background-color: #4a5568 !important;
        border-color: #4a5568 !important;
        color: white !important;
        transform: none !important;
    }
    
    .btn-outline-secondary {
        color: #718096 !important;
        border-color: #718096 !important;
    }
    
    .btn-outline-secondary:hover {
        background-color: #718096 !important;
        border-color: #718096 !important;
        color: white !important;
        transform: none !important;
    }
    
    .btn-outline-success {
        color: #059669 !important;
        border-color: #059669 !important;
    }
    
    .btn-outline-success:hover {
        background-color: #059669 !important;
        border-color: #059669 !important;
        color: white !important;
        transform: none !important;
    }
    
    .btn-outline-danger {
        color: #dc3545 !important;
        border-color: #dc3545 !important;
    }
    
    .btn-outline-danger:hover {
        background-color: #dc3545 !important;
        border-color: #dc3545 !important;
        color: white !important;
        transform: none !important;
    }
    
    .btn-outline-dark {
        color: #4a5568 !important;
        border-color: #4a5568 !important;
    }
    
    .btn-outline-dark:hover {
        background-color: #4a5568 !important;
        border-color: #4a5568 !important;
        color: white !important;
        transform: none !important;
    }
    
    /* Badge styling */
    .badge {
        font-size: 0.7rem;
        padding: 0.25rem 0.5rem;
    }
    
    .badge.bg-gradient-primary {
        background: #4a5568 !important;
        color: white;
    }
    
    .badge.bg-gradient-secondary {
        background: #718096 !important;
        color: white;
    }
    
    .badge.bg-gradient-info {
        background: #0891b2 !important;
        color: white;
    }
    
    .badge.bg-gradient-success {
        background: #059669 !important;
        color: white;
    }
    
    /* Table styling */
    .table {
        font-size: 0.875rem;
        color: #2d3748;
    }
    
    .table thead th {
        background-color: #f8f9fa !important;
        border-color: #e2e8f0 !important;
        color: #718096 !important;
        font-weight: 600 !important;
        font-size: 0.75rem !important;
        padding: 0.75rem !important;
    }
    
    .table tbody td {
        border-color: #e2e8f0 !important;
        padding: 0.75rem !important;
        vertical-align: middle;
    }
    
    .table tbody tr:hover {
        background-color: #f8f9fa !important;
    }
    
    /* Status toggle styling */
    .status-toggle {
        font-size: 1rem;
        transition: none;
    }
    
    .status-toggle.active {
        color: #059669 !important;
    }
    
    .status-toggle.inactive {
        color: #718096 !important;
    }
    
    /* Question list styling */
    .question-list-container {
        background-color: #f8f9fa;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        margin-top: 0.5rem;
        padding: 0.5rem;
        max-height: 200px;
        overflow-y: auto;
    }
    
    .question-item {
        padding: 0.375rem 0;
        border-bottom: 1px solid #e2e8f0;
        font-size: 0.8rem;
    }
    
    .question-item:last-child {
        border-bottom: none;
    }
    
    /* Alert styling */
    .alert {
        border-radius: 6px;
        padding: 0.75rem 1rem;
        margin-bottom: 1rem;
        font-size: 0.875rem;
        transition: opacity 0.5s, transform 0.5s;
    }
    
    .alert.hide {
        opacity: 0;
        transform: translateY(-20px) scale(0.95);
    }
    
    .alert-success {
        background-color: #ecfdf5 !important;
        border-color: #059669 !important;
        color: #065f46 !important;
    }
    
    .alert-danger {
        background-color: #fef2f2 !important;
        border-color: #dc3545 !important;
        color: #dc3545 !important;
    }
    
    /* Text colors */
    .text-primary {
        color: #4a5568 !important;
    }
    
    .text-secondary {
        color: #718096 !important;
    }
    
    .text-muted {
        color: #718096 !important;
    }
    
    .text-gray-300 {
        color: #cbd5e0 !important;
    }
    
    .text-gray-400 {
        color: #a0aec0 !important;
    }
    
    .text-gray-500 {
        color: #718096 !important;
    }
    
    /* Empty state styling */
    .text-center .fas.fa-inbox {
        color: #cbd5e0 !important;
    }
    
    /* Action button improvements */
    .action-btn {
        white-space: nowrap;
    }
    
    .disabled-btn {
        opacity: 0.5;
        cursor: not-allowed;
    }
    
    .disabled-btn:hover {
        background-color: transparent !important;
        color: #718096 !important;
    }
    
    /* Reduced spacing */
    .py-4 {
        padding-top: 1.5rem !important;
        padding-bottom: 1.5rem !important;
    }
    
    .py-3 {
        padding-top: 1rem !important;
        padding-bottom: 1rem !important;
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
    
    .gap-2 {
        gap: 0.375rem !important;
    }
    
    /* Custom improvements for better UX */
    .survey-table tbody tr {
        transition: background-color 0.15s ease;
    }
    
    .btn-link {
        color: #4a5568 !important;
        text-decoration: none;
    }
    
    .btn-link:hover {
        color: #2d3748 !important;
    }
    
    /* Scrollbar styling for question lists */
    .question-list-container::-webkit-scrollbar {
        width: 4px;
    }
    
    .question-list-container::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 2px;
    }
    
    .question-list-container::-webkit-scrollbar-thumb {
        background: #e2e8f0;
        border-radius: 2px;
    }
    
    .question-list-container::-webkit-scrollbar-thumb:hover {
        background: #cbd5e0;
    }
</style>
<body class="g-sidenav-show bg-gray-100">
    <?php include 'components/aside.php'; ?>
    
    <main class="main-content position-relative border-radius-lg">
         <?php include 'components/navbar.php'; ?>

        <div class="container-fluid py-3">
           
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item">
                            <a class="breadcrumb-link-light" href="main.php">Dashboard</a>     
                        </li>
                        <li class="breadcrumb-item active breadcrumb-item-active-light" aria-current="page">
                            Manage Surveys  
                        </li>
                    </ol>
                </nav>
    
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
                                                    <?php if ($survey['type'] === 'dhis2' && !empty($survey['dhis2_program_uid'])): ?>
                                                        <!-- DHIS2 Tracker Program - Link to tracker preview -->
                                                        <a href="tracker_preview.php?survey_id=<?php echo $survey['id']; ?>" class="btn btn-sm btn-outline-success mb-0 action-btn" title="Preview Tracker Program">
                                                            <i class="fas fa-eye"></i>
                                                            <span class="btn-text d-none d-md-inline">Preview</span>
                                                        </a>
                                                    <?php else: ?>
                                                        <!-- Regular Survey/DHIS2 Event Program - View Details -->
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="survey_id" value="<?php echo $survey['id']; ?>">
                                                            <button type="submit" name="deploy_survey" class="btn btn-sm btn-outline-success mb-0 action-btn">
                                                                <i class="fas fa-cog"></i>
                                                                <span class="btn-text d-none d-md-inline">View Details</span>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-outline-secondary mb-0 action-btn disabled-btn" title="Survey must be active to deploy">
                                                        <i class="fas fa-cog"></i>
                                                        <span class="btn-text d-none d-md-inline">Inactive</span>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if (canUserDelete()): ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="survey_id" value="<?php echo $survey['id']; ?>">
                                                    <button type="submit" name="delete_survey" class="btn btn-sm btn-outline-danger mb-0 action-btn"
                                                            onclick="return confirm('Are you sure you want to delete this survey?')">
                                                        <i class="fas fa-trash"></i>
                                                        <span class="btn-text d-none d-md-inline">Delete</span>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
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