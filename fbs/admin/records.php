<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}
require_once 'includes/session_timeout.php';

require 'connect.php'; // Ensures $pdo is available

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

$surveyId = $_GET['survey_id'] ?? null;
$submissions = [];
$surveyName = '';

// New: Capture start_date and end_date from GET parameters for filtering displayed data
$startDateParam = $_GET['start_date'] ?? '';
$endDateParam = $_GET['end_date'] ?? '';

// Fetch all surveys if no survey_id is provided - with submission counts
if (!$surveyId) {
    $surveys = $pdo->query("
        SELECT 
            s.id, 
            s.name, 
            COUNT(sub.id) AS submission_count,
            MAX(sub.created) AS last_submission
        FROM 
            survey s 
        LEFT JOIN 
            submission sub ON s.id = sub.survey_id 
        GROUP BY 
            s.id, s.name
        ORDER BY 
            s.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} else {
    // Fetch submissions for the selected survey
    $sortBy = $_GET['sort'] ?? 'created_desc';

    // Define valid sorting options
    $validSortOptions = [
        'created_asc' => 's.created ASC',
        'created_desc' => 's.created DESC',
        'uid_asc' => 's.uid ASC',
        'uid_desc' => 's.uid DESC',
    ];

    $orderBy = $validSortOptions[$sortBy] ?? 's.created DESC';

    try {
        $sql = "
            SELECT
                s.id,
                s.uid,
                l.name AS location_name,
                s.created,
                COUNT(sr.id) AS response_count
            FROM submission s
            LEFT JOIN submission_response sr ON s.id = sr.submission_id
            LEFT JOIN location l ON s.location_id = l.id
            WHERE s.survey_id = :survey_id
        ";
        
        $params = ['survey_id' => $surveyId];

        // Add date filtering to SQL query for displayed submissions
        if (!empty($startDateParam)) {
            $sql .= " AND s.created >= :start_date";
            $params['start_date'] = $startDateParam . ' 00:00:00'; // Start of the day
        }
        if (!empty($endDateParam)) {
            $sql .= " AND s.created <= :end_date";
            $params['end_date'] = $endDateParam . ' 23:59:59';   // End of the day
        }

        $sql .= " GROUP BY s.id, l.name ORDER BY $orderBy";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Fetch survey name
        $survey = $pdo->prepare("SELECT name FROM survey WHERE id = :survey_id");
        $survey->execute(['survey_id' => $surveyId]);
        $surveyName = $survey->fetchColumn() ?: 'Unknown Survey';
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Survey Submissions</title>
    <link rel="icon" href="argon-dashboard-master/assets/img/brand/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-icons.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-svg.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/argon-dashboard.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <style>
        /* General page background */
        body {
            overflow-x: hidden;
            position: relative;
            background-color: #f8f9fa !important; /* A light, clean background */
        }
        /* Page Title Section - Simplified neutral design */
        .page-title-section {
            background: #ffffff;
            color: #2d3748;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
        }
        .page-title-section .breadcrumb-link {
            color: #4a5568 !important;
            font-weight: 600;
        }
        .page-title-section .breadcrumb-item.active {
            color: #2d3748 !important;
            font-weight: 700;
        }
        .page-title-section .breadcrumb-item a i {
            color: #4a5568 !important;
        }
        .page-title-section .navbar-title {
            color: #2d3748 !important;
            text-shadow: none;
        }

        /* Card Styling - Simplified neutral design */
        .card {
            background-color: #ffffff !important;
            border-radius: 8px !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
            border: 1px solid #e2e8f0 !important;
            color: #2d3748 !important;
        }
        .card-header {
            background-color: #ffffff !important;
            padding: 1.25rem !important;
            border-bottom: 1px solid #e2e8f0 !important;
        }
        .card-header h4, .card-header p {
            color: #344767 !important; /* Darker text for headers */
        }

        /* Buttons - Simplified neutral design */
        .btn {
            border-radius: 6px !important;
            font-weight: 600 !important;
            font-size: 0.875rem !important;
            padding: 0.5rem 1rem !important;
            transition: none !important;
        }
        .btn-outline-primary {
            color: #4a5568 !important;
            border-color: #4a5568 !important;
            background-color: transparent !important;
        }
        .btn-outline-primary:hover {
            color: #fff !important;
            background-color: #4a5568 !important;
            transform: none !important;
        }
        .btn-outline-info {
            color: #4a5568 !important;
            border-color: #4a5568 !important;
            background-color: transparent !important;
        }
        .btn-outline-info:hover {
            color: #fff !important;
            background-color: #4a5568 !important;
            transform: none !important;
        }
        .btn-outline-success {
            color: #4a5568 !important;
            border-color: #4a5568 !important;
            background-color: transparent !important;
        }
        .btn-outline-success:hover {
            color: #fff !important;
            background-color: #4a5568 !important;
            transform: none !important;
        }
        .btn-outline-danger {
            color: #dc3545 !important;
            border-color: #dc3545 !important;
            background-color: transparent !important;
        }
        .btn-outline-danger:hover {
            color: #fff !important;
            background-color: #dc3545 !important;
            transform: none !important;
        }
        .btn-outline-secondary {
            color: #718096 !important;
            border-color: #718096 !important;
            background-color: transparent !important;
        }
        .btn-outline-secondary:hover {
            color: #fff !important;
            background-color: #718096 !important;
            transform: none !important;
        }

        /* Override Argon's gradient buttons to be neutral */
        .btn.bg-gradient-secondary, 
        .btn.bg-gradient-info, 
        .btn.bg-gradient-success {
            background-image: none !important;
            background-color: #4a5568 !important;
            border-color: #4a5568 !important;
            color: #fff !important;
        }
        .btn.bg-gradient-info {
            background-color: #4a5568 !important;
            border-color: #4a5568 !important;
        }
        .btn.bg-gradient-success {
            background-color: #4a5568 !important;
            border-color: #4a5568 !important;
        }


        /* Survey List Cards - Simplified static design */
        .survey-card { 
            background: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
            transition: none;
            min-height: 180px;
            position: relative;
            overflow: hidden;
            border-radius: 6px !important;
        }
        
        .survey-card::before {
            display: none;
        }
        
        .survey-card:hover {
            transform: none;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
            border-color: #e2e8f0 !important;
        }
        
        .survey-card:hover::before {
            opacity: 0;
        }
        
        .survey-card .card-body {
            min-height: 140px;
            display: flex;
            flex-direction: column;
            padding: 0.75rem !important;
        }
        
        .survey-card .bg-light {
            background: #f8f9fa !important;
            border: 1px solid #e2e8f0;
            transition: none;
        }
        
        .survey-card:hover .bg-light {
            background: #f8f9fa !important;
            border-color: #e2e8f0;
        }
        
        /* Button improvements - simplified */
        .survey-card .btn {
            transition: none;
            font-weight: 600;
            letter-spacing: 0;
        }
        
        .survey-card .btn-primary {
            background: #4a5568;
            border: 1px solid #4a5568;
            box-shadow: none;
        }
        
        .survey-card .btn-primary:hover {
            background: #2d3748;
            transform: none;
            box-shadow: none;
        }
        
        .survey-card .btn-outline-info {
            border-color: #4a5568;
            color: #4a5568;
            background: transparent;
        }
        
        .survey-card .btn-outline-info:hover {
            background: #4a5568;
            border-color: #4a5568;
            color: white;
            transform: none;
        }
        
        /* Icon styling - simplified */
        .survey-card .icon-shape {
            background: #4a5568 !important;
            transition: none;
        }
        
        .survey-card:hover .icon-shape {
            transform: none;
            box-shadow: none;
        }
        
        /* Grid improvements - reduced spacing */
        .row.g-4 {
            margin: 0 -8px;
        }
        
        .row.g-4 > * {
            padding: 0 8px;
            margin-bottom: 16px;
        }
        
        /* Responsive adjustments - minimal sizes */
        @media (max-width: 1199px) {
            .survey-card {
                min-height: 170px;
            }
        }
        
        @media (max-width: 767px) {
            .survey-card {
                min-height: 160px;
            }
            
            .survey-card .card-body {
                min-height: 120px;
                padding: 0.625rem !important;
            }
        }
        .survey-card .text-gradient.text-primary {
            background-image: none !important;
            -webkit-text-fill-color: initial;
            -webkit-background-clip: initial;
            color: #2d3748 !important;
        }
        .survey-card h5 {
            color: #2d3748 !important;
            font-size: 1rem !important;
        }
        .card-blog .text-gradient.text-primary {
            background-image: none !important;
            -webkit-text-fill-color: initial;
            -webkit-background-clip: initial;
            color: #2d3748 !important;
        }
        .card-blog h5 {
            color: #2d3748 !important;
        }
        
        /* Table Styling - simplified */
        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }
        .table {
            color: #212529 !important; /* Dark text for table content */
        }
        .table thead th {
            font-size: 0.75rem;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            background-color: #f8f9fa !important;
            color: #718096 !important;
            font-weight: 700 !important;
            border-bottom: 1px solid #e2e8f0 !important;
            padding: 0.875rem !important;
        }
        .table tbody tr {
            background-color: #fff !important;
            transition: none;
        }
        .table tbody tr:hover {
            background-color: #f8f9fa !important;
        }
        .table td, .table th {
            padding: 0.75rem !important;
        }
        .table p {
            color: #2d3748 !important;
        }
        .table small {
            color: #718096 !important;
        }

        /* Badge in table - simplified */
        .badge.bg-gradient-success {
            background: #4a5568 !important;
            color: #fff;
        }
        .btn-link .fas {
            font-size: 1rem;
        }
        .btn-link .text-info {
            color: #4a5568 !important;
        }
        .btn-link .text-danger {
            color: #dc3545 !important;
        }

        /* Empty state - simplified */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 3rem;
            text-align: center;
            color: #718096 !important;
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #cbd5e0;
        }
        .empty-state h5 {
            color: #2d3748 !important;
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }
        .empty-state p {
            font-size: 1rem;
        }

        /* Dropdown menus - simplified */
        .dropdown-menu {
            border-radius: 6px !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important;
            background-color: #fff !important;
            border: 1px solid #e2e8f0 !important;
            padding: 0.5rem 0;
        }
        .dropdown-item {
            color: #2d3748 !important;
            font-weight: 500 !important;
            padding: 0.625rem 1.25rem !important;
            font-size: 0.875rem !important;
        }
        .dropdown-item:hover {
            background-color: #f8f9fa !important;
            color: #4a5568 !important;
        }
        .dropdown-divider {
            border-top-color: #e2e8f0 !important;
        }

        /* SweetAlert2 custom styling for download modal */
        .swal2-popup {
            background-color: #ffffff !important;
            border-radius: 12px !important;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15) !important;
            color: #212529 !important;
        }
        .swal2-title {
            color: #212529 !important;
            font-weight: 700 !important;
        }
        .swal2-html-container {
            color: #344767 !important;
        }
        .swal2-input {
            background-color: #f8f9fa !important;
            border: 1px solid #dee2e6 !important;
            color: #212529 !important;
            font-weight: 500 !important;
        }
        .swal2-input:focus {
            border-color: #007bff !important;
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25) !important;
        }
        .swal2-confirm.swal2-styled {
            background-color: #007bff !important;
            color: #fff !important;
        }
        .swal2-cancel.swal2-styled {
            background-color: #6c757d !important;
            color: #fff !important;
        }

        /* Custom period filter group - simplified */
        .period-filter-group .form-control {
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 0.5rem 0.75rem;
            height: auto;
            font-size: 0.875rem;
        }
        .period-filter-group .form-control:focus {
            box-shadow: 0 0 0 2px rgba(74, 85, 104, 0.1);
            border-color: #4a5568;
        }
        .period-filter-group label {
            color: #2d3748;
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
        }
        .period-filter-group .btn-secondary {
            background-color: #4a5568;
            border-color: #4a5568;
            color: #fff;
        }
        .period-filter-group .btn-secondary:hover {
            background-color: #2d3748;
            border-color: #2d3748;
        }
        .header-container-light {
        background: #ffffff;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 1rem 1.5rem;
        margin-bottom: 1.5rem;
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
    }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100">
 <?php include 'components/aside.php'; ?>

    <div class="main-content position-relative border-radius-lg">
       
      <?php include 'components/navbar.php'; ?>
    
        <div class="container-fluid py-4">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item">
                            <a class="breadcrumb-link-light" href="main.php">Dashboard</a>     
                        </li>
                        <li class="breadcrumb-item active breadcrumb-item-active-light" aria-current="page">
                            Analyze Submissions
                        </li>
                    </ol>
                </nav>
                
            <div class="row">
                <div class="col-12">
                    <?php if (!$surveyId): ?>
                        <div class="card mb-4">
                            <div class="card-header pb-0">
                                <h4>Select a Survey to View Submissions</h4>
                                <p class="text-sm mb-0">
                                    <i class="fa fa-info-circle me-1"></i>
                                    Click on any survey below to view its submissions
                                </p>
                            </div>
                            <div class="card-body px-0 pt-0 pb-2">
                                <?php if (empty($surveys)): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-clipboard-list"></i>
                                        <h5>No Surveys Found</h5>
                                        <p class="text-muted">There are no surveys available to display.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="row p-4 g-4">
                                        <?php foreach ($surveys as $survey): ?>
                                            <div class="col-lg-4 col-md-6 col-sm-12">
                                                <div class="card survey-card h-100" style="border: 1px solid #e3e6f0; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15); transition: all 0.3s ease; border-radius: 16px; overflow: hidden;">
                                                    <div class="card-body p-4 d-flex flex-column h-100">
                                                        <!-- Icon Section -->
                                                        <div class="text-center mb-2">
                                                            <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md mx-auto" 
                                                                 style="width: 35px; height: 35px; display: flex; align-items: center; justify-content: center; border-radius: 50%;">
                                                                <i class="fas fa-poll text-xs text-white"></i>
                                                            </div>
                                                        </div>
                                                        
                                                        <!-- Title Section -->
                                                        <div class="text-center mb-2 flex-grow-0">
                                                            <h5 class="font-weight-bolder mb-1 text-primary" style="font-size: 0.85rem; line-height: 1.1; min-height: 1.5rem; display: flex; align-items: center; justify-content: center;">
                                                                <?php echo htmlspecialchars($survey['name']); ?>
                                                            </h5>
                                                            <p class="text-xs text-muted mb-0" style="font-size: 0.65rem;">ID: #<?php echo $survey['id']; ?></p>
                                                        </div>
                                                        
                                                        <!-- Stats Section -->
                                                        <div class="text-center mb-2 flex-grow-1 d-flex flex-column justify-content-center">
                                                            <div class="bg-light rounded-lg p-1 mb-1">
                                                                <span class="text-2xl font-weight-bolder text-success d-block" style="font-size: 1.25rem;">
                                                                    <?php echo number_format($survey['submission_count']); ?>
                                                                </span>
                                                                <span class="text-xs text-muted" style="font-size: 0.65rem;">Total</span>
                                                            </div>
                                                            
                                                            <?php if ($survey['last_submission']): ?>
                                                                <div class="text-center">
                                                                    <small class="text-muted d-flex align-items-center justify-content-center" style="font-size: 0.6rem;">
                                                                        <i class="fas fa-clock me-1" style="font-size: 0.55rem;"></i>
                                                                        <?php echo date('M j', strtotime($survey['last_submission'])); ?>
                                                                    </small>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        
                                                        <!-- Buttons Section - Fixed at bottom -->
                                                        <div class="mt-auto pt-1">
                                                            <div class="d-flex gap-1">
                                                                <a href="records.php?survey_id=<?php echo $survey['id']; ?>" 
                                                                   class="btn btn-primary btn-sm flex-fill" 
                                                                   style="border-radius: 4px; font-weight: 600; font-size: 0.65rem; padding: 0.25rem; white-space: nowrap;">
                                                                    <i class="fas fa-list me-1" style="font-size: 0.6rem;"></i>Records
                                                                </a>
                                                                <a href="survey_dashboard.php?survey_id=<?php echo $survey['id']; ?>" 
                                                                   class="btn btn-outline-info btn-sm flex-fill" 
                                                                   style="border-radius: 4px; font-weight: 600; font-size: 0.65rem; padding: 0.25rem; white-space: nowrap;">
                                                                    <i class="fas fa-chart-line me-1" style="font-size: 0.6rem;"></i>Dashboard
                                                                </a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card mb-4">
                            <div class="card-header pb-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h4>Survey: <?php echo htmlspecialchars($surveyName); ?></h4>
                                        <p class="text-sm mb-0">
                                            <i class="fa fa-list me-1"></i>
                                            All submissions for this survey
                                            <?php if ($startDateParam && $endDateParam): // Display filter info if dates are applied ?>
                                                <span class="text-info">(Filtered from <?= htmlspecialchars($startDateParam) ?> to <?= htmlspecialchars($endDateParam) ?>)</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="d-flex align-items-center" style="gap: 1rem;">
                                        <button class="btn btn-outline-secondary mb-0" onclick="goBack()">
                                            <i class="fas fa-arrow-left me-2"></i> Back to Surveys
                                        </button>

                                        <div class="dropdown">
                                            <button class="btn btn-outline-info mb-0 dropdown-toggle" type="button" id="sortDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="fas fa-sort me-2"></i> Sort By
                                            </button>
                                            <ul class="dropdown-menu" aria-labelledby="sortDropdown">
                                                <li><a class="dropdown-item" href="?survey_id=<?= $surveyId ?>&sort=created_desc<?= ($startDateParam && $endDateParam) ? '&start_date='.$startDateParam.'&end_date='.$endDateParam : '' ?>">Newest First</a></li>
                                                <li><a class="dropdown-item" href="?survey_id=<?= $surveyId ?>&sort=created_asc<?= ($startDateParam && $endDateParam) ? '&start_date='.$startDateParam.'&end_date='.$endDateParam : '' ?>">Oldest First</a></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item" href="?survey_id=<?= $surveyId ?>&sort=uid_asc<?= ($startDateParam && $endDateParam) ? '&start_date='.$startDateParam.'&end_date='.$endDateParam : '' ?>">UID (A-Z)</a></li>
                                                <li><a class="dropdown-item" href="?survey_id=<?= $surveyId ?>&sort=uid_desc<?= ($startDateParam && $endDateParam) ? '&start_date='.$startDateParam.'&end_date='.$endDateParam : '' ?>">UID (Z-A)</a></li>
                                            </ul>
                                        </div>
                                        
                                        <button class="btn btn-outline-success mb-0" type="button" onclick="showDownloadModal()">
                                            <i class="fas fa-download me-2"></i> Download
                                        </button>
                                    </div>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-md-12">
                                        <form method="get" action="records.php" class="d-flex align-items-end period-filter-group">
                                            <input type="hidden" name="survey_id" value="<?= $surveyId ?>">
                                            <input type="hidden" name="sort" value="<?= htmlspecialchars($sortBy) ?>">
                                            <div class="me-3">
                                                <label for="start_date" class="form-label">Start Date:</label>
                                                <input type="date" class="form-control" id="start_date" name="start_date" value="<?= htmlspecialchars($startDateParam) ?>">
                                            </div>
                                            <div class="me-3">
                                                <label for="end_date" class="form-label">End Date:</label>
                                                <input type="date" class="form-control" id="end_date" name="end_date" value="<?= htmlspecialchars($endDateParam) ?>">
                                            </div>
                                            <button type="submit" class="btn btn-secondary">
                                                <i class="fas fa-filter me-1"></i> Filter
                                            </button>
                                            <?php if ($startDateParam || $endDateParam): // Show reset button if filters are active ?>
                                                <button type="button" class="btn btn-outline-secondary ms-2" onclick="window.location.href='records.php?survey_id=<?= $surveyId ?>&sort=<?= htmlspecialchars($sortBy) ?>'">
                                                    <i class="fas fa-times me-1"></i> Reset Filters
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body px-0 pt-0 pb-2">
                                <?php if (empty($submissions)): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-inbox"></i>
                                        <h5>No Submissions Found</h5>
                                        <p class="text-muted">There are no submissions for this survey matching the selected filters yet.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive p-0">
                                        <table class="table align-items-center mb-0">
                                            <thead>
                                                <tr>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">ID</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">UID</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Location</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Responses</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Created</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($submissions as $submission): ?>
                                                    <tr>
                                                        <td class="ps-4">
                                                            <p class="text-xs font-weight-bold mb-0"><?php echo $submission['id']; ?></p>
                                                        </td>
                                                        <td>
                                                            <p class="text-xs font-weight-bold mb-0"><?php echo htmlspecialchars($submission['uid']); ?></p>
                                                        </td>
                                                        <td>
                                                            <p class="text-xs font-weight-bold mb-0"><?php echo htmlspecialchars($submission['location_name'] ?? 'N/A'); ?></p>
                                                        </td>
                                                        <td>
                                                            <span class="badge badge-sm bg-gradient-success"><?php echo $submission['response_count']; ?></span>
                                                        </td>
                                                        <td>
                                                            <p class="text-xs font-weight-bold mb-0"><?php echo date('M d, Y', strtotime($submission['created'])); ?></p>
                                                        </td>
                                                        <td class="align-middle">
                                                            <button class="btn btn-link text-dark px-2 mb-0" onclick="viewSubmission(<?php echo $submission['id']; ?>)">
                                                                <i class="fas fa-eye text-info me-2"></i>
                                                            </button>
                                                            <?php if (canUserDelete()): ?>
                                                            <button class="btn btn-link text-dark px-2 mb-0" onclick="deleteSubmission(<?php echo $submission['id']; ?>)">
                                                                <i class="fas fa-trash-alt text-danger me-2"></i>
                                                            </button>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

   
    <script src="argon-dashboard-master/assets/js/core/popper.min.js"></script>
    <script src="argon-dashboard-master/assets/js/core/bootstrap.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="argon-dashboard-master/assets/js/argon-dashboard.js"></script>
    
    <script>
        // Initialize Argon components
        if (typeof Argon !== 'undefined') {
            Argon.init();
        }

        // Sidebar toggle functionality
       document.addEventListener('DOMContentLoaded', function() {
            var icon = document.getElementById('iconNavbarSidenav');
            if (icon) {
                icon.addEventListener('click', function() {
                    document.body.classList.toggle('g-sidenav-pinned');
                    document.body.classList.toggle('g-sidenav-hidden');
                });
            }
        });

        // Ensure sidebar is visible on page load
        document.addEventListener('DOMContentLoaded', function() {
            if (window.innerWidth > 1200) {
                document.body.classList.remove('g-sidenav-hidden');
                document.body.classList.add('g-sidenav-pinned');
            }
        });

        // View submission details
        function viewSubmission(submissionId) {
            window.location.href = `view_record.php?submission_id=${submissionId}`; // Corrected to .php extension
        }

        // Delete submission with confirmation
        function deleteSubmission(submissionId) {
            Swal.fire({
                title: 'Are you sure?',
                text: "You won't be able to revert this!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch(`delete_submission.php`, { // Assuming delete_submission.php exists
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `id=${submissionId}`
                    })
                    .then(response => {
                        console.log('Response status:', response.status);
                        console.log('Response headers:', response.headers);
                        return response.text();
                    })
                    .then(text => {
                        console.log('Raw response:', text);
                        try {
                            const data = JSON.parse(text);
                            console.log('Parsed data:', data);
                            if (data.success) {
                                const nextId = data.next_submission_id || 'N/A';
                                Swal.fire(
                                    'Deleted!',
                                    `Submission deleted successfully! Next submission ID will be: ${nextId}`,
                                    'success'
                                ).then(() => location.reload());
                            } else {
                                Swal.fire(
                                    'Error!',
                                    data.message || 'Failed to delete submission.',
                                    'error'
                                );
                            }
                        } catch (e) {
                            console.error('JSON parse error:', e);
                            console.error('Response text:', text);
                            Swal.fire(
                                'Error!',
                                'Invalid response from server.',
                                'error'
                            );
                        }
                    })
                    .catch(error => {
                        console.error('Fetch error:', error);
                        Swal.fire(
                            'Error!',
                            'Network error occurred while deleting the submission.',
                            'error'
                        );
                    });
                }
            });
        }

        // Go back to the list of surveys
        function goBack() {
            window.location.href = 'records.php'; // Corrected to records.php
        }

        // New function to show the download modal
        function showDownloadModal() {
            Swal.fire({
                title: 'Download Submissions',
                html: `
                    <div class="form-group mb-3 text-start">
                        <label for="swal-start-date" class="form-label">Start Date:</label>
                        <input type="date" id="swal-start-date" class="swal2-input form-control">
                    </div>
                    <div class="form-group mb-3 text-start">
                        <label for="swal-end-date" class="form-label">End Date:</label>
                        <input type="date" id="swal-end-date" class="swal2-input form-control">
                    </div>
                    <div class="form-group text-start">
                        <label class="form-label mb-2">Select Format:</label><br>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="swal-format" id="swal-format-pdf" value="pdf" checked>
                            <label class="form-check-label" for="swal-format-pdf">PDF</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="swal-format" id="swal-format-csv" value="csv">
                            <label class="form-check-label" for="swal-format-csv">CSV</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="swal-format" id="swal-format-excel" value="excel">
                            <label class="form-check-label" for="swal-format-excel">Excel</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="swal-format" id="swal-format-json" value="json">
                            <label class="form-check-label" for="swal-format-json">JSON</label>
                        </div>
                    </div>
                `,
                focusConfirm: false, // Prevents auto-focusing on confirm button
                showCancelButton: true,
                confirmButtonText: 'Download',
                preConfirm: () => {
                    const startDate = Swal.getPopup().querySelector('#swal-start-date').value;
                    const endDate = Swal.getPopup().querySelector('#swal-end-date').value;
                    const format = Swal.getPopup().querySelector('input[name="swal-format"]:checked').value;

                    // Basic validation for dates
                    if (!startDate || !endDate) {
                        Swal.showValidationMessage('Please select both start and end dates.');
                        return false;
                    }
                    if (new Date(startDate) > new Date(endDate)) {
                        Swal.showValidationMessage('End date cannot be before start date.');
                        return false;
                    }
                    return { startDate: startDate, endDate: endDate, format: format };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const { startDate, endDate, format } = result.value;
                    // Trigger the download with the selected parameters
                    downloadData(format, startDate, endDate);
                }
            });

            // Set default dates to the modal after it's opened
            Swal.getPopup().addEventListener('shown.bs.modal', () => { // Use shown.bs.modal for Bootstrap 5+ modals
                const today = new Date();
                const year = today.getFullYear();
                const month = String(today.getMonth() + 1).padStart(2, '0');
                const day = String(today.getDate()).padStart(2, '0');
                const formattedToday = `${year}-${month}-${day}`;

                const defaultEndDateInput = Swal.getPopup().querySelector('#swal-end-date');
                const defaultStartDateInput = Swal.getPopup().querySelector('#swal-start-date');

                // Set default end date to today
                defaultEndDateInput.value = formattedToday;

                // Set default start date to, for example, 30 days ago
                const thirtyDaysAgo = new Date(today);
                thirtyDaysAgo.setDate(today.getDate() - 30);
                const formattedThirtyDaysAgo = `${thirtyDaysAgo.getFullYear()}-${String(thirtyDaysAgo.getMonth() + 1).padStart(2, '0')}-${String(thirtyDaysAgo.getDate()).padStart(2, '0')}`;
                defaultStartDateInput.value = formattedThirtyDaysAgo;
            });
        }

        // Modified downloadData function to accept dates
        function downloadData(format, startDate, endDate) {
            const surveyId = <?php echo json_encode($surveyId); ?>;
            const sortBy = '<?php echo $sortBy ?? "created_desc"; ?>';

            // Show loading indicator
            Swal.fire({
                title: 'Preparing Download',
                html: 'Please wait while we prepare your file...',
                timerProgressBar: true,
                didOpen: () => {
                    Swal.showLoading();
                },
                allowOutsideClick: false
            });

            // Create a new form
            const form = document.createElement('form');
            form.method = 'POST'; // Crucial: Use POST method for sending data
            form.action = 'generate_download.php'; // The target PHP script

            // Add parameters as hidden inputs
            const params = {
                survey_id: surveyId,
                sort: sortBy,
                format: format,
                start_date: startDate, // New parameter
                end_date: endDate     // New parameter
            };

            for (const key in params) {
                if (params.hasOwnProperty(key)) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = params[key];
                    form.appendChild(input);
                }
            }

            document.body.appendChild(form); // Append the form to the body
            form.submit(); // Submit the form

            // Close the loading dialog after a short delay (assuming download starts promptly)
            setTimeout(() => {
                Swal.close();
            }, 3000); // 3 seconds should be enough for browser to initiate download
        }

    </script>
</body>
</html>