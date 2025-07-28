<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require 'connect.php'; // Ensures $pdo is available

$surveyId = $_GET['survey_id'] ?? null;
$submissions = [];
$surveyName = '';

// New: Capture start_date and end_date from GET parameters for filtering displayed data
$startDateParam = $_GET['start_date'] ?? '';
$endDateParam = $_GET['end_date'] ?? '';

// Fetch all surveys if no survey_id is provided
if (!$surveyId) {
    $surveys = $pdo->query("SELECT id, name FROM survey")->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
                s.age,
                s.sex,
                s.period,
                su.name AS service_unit_name,
                l.name AS location_name,
                o.name AS ownership_name,
                s.created,
                COUNT(sr.id) AS response_count
            FROM submission s
            LEFT JOIN submission_response sr ON s.id = sr.submission_id
            LEFT JOIN service_unit su ON s.service_unit_id = su.id
            LEFT JOIN location l ON s.location_id = l.id
            LEFT JOIN owner o ON s.ownership_id = o.id
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

        $sql .= " GROUP BY s.id, su.name, l.name, o.name ORDER BY $orderBy";

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
        /* Page Title Section - Kept from previous theme for consistency as a header */
        .page-title-section {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); /* Dark header */
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        .page-title-section .breadcrumb-link {
            color: #ffd700 !important; /* Gold text for breadcrumbs */
            font-weight: 600;
        }
        .page-title-section .breadcrumb-item.active {
            color: #fff !important; /* White active breadcrumb */
            font-weight: 700;
        }
        .page-title-section .breadcrumb-item a i {
            color: #ffd700 !important; /* Gold icon */
        }
        .page-title-section .navbar-title {
            color: #fff !important; /* White text for page title */
            text-shadow: 0 1px 8px #1e3c72, 0 0 2px #ffd700; /* Subtle glow */
        }

        /* Card Styling - Clean White Theme */
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
        .card-header h4, .card-header p {
            color: #344767 !important; /* Darker text for headers */
        }

        /* Buttons - Clean, Non-Gradient, White-Theme Friendly */
        .btn {
            border-radius: 8px !important;
            font-weight: 600 !important;
            transition: all 0.2s ease-in-out !important;
        }
        /* Default outline style */
        .btn-outline-primary {
            color: #007bff !important;
            border-color: #007bff !important;
            background-color: transparent !important;
        }
        .btn-outline-primary:hover {
            color: #fff !important;
            background-color: #007bff !important;
        }
        .btn-outline-info {
            color: #17a2b8 !important;
            border-color: #17a2b8 !important;
            background-color: transparent !important;
        }
        .btn-outline-info:hover {
            color: #fff !important;
            background-color: #17a2b8 !important;
        }
        .btn-outline-success {
            color: #28a745 !important;
            border-color: #28a745 !important;
            background-color: transparent !important;
        }
        .btn-outline-success:hover {
            color: #fff !important;
            background-color: #28a745 !important;
        }
        .btn-outline-danger {
            color: #dc3545 !important;
            border-color: #dc3545 !important;
            background-color: transparent !important;
        }
        .btn-outline-danger:hover {
            color: #fff !important;
            background-color: #dc3545 !important;
        }
        .btn-outline-secondary { /* For Back button */
            color: #6c757d !important;
            border-color: #6c757d !important;
            background-color: transparent !important;
        }
        .btn-outline-secondary:hover {
            color: #fff !important;
            background-color: #6c757d !important;
        }

        /* Override Argon's specific gradient buttons to be flat/outline */
        .btn.bg-gradient-secondary, 
        .btn.bg-gradient-info, 
        .btn.bg-gradient-success {
            background-image: none !important;
            /* Revert to solid colors for clarity on purpose */
            background-color: #6c757d !important; /* Default secondary */
            border-color: #6c757d !important;
            color: #fff !important;
        }
        .btn.bg-gradient-info {
            background-color: #17a2b8 !important; /* Info blue */
            border-color: #17a2b8 !important;
        }
        .btn.bg-gradient-success {
            background-color: #28a745 !important; /* Success green */
            border-color: #28a745 !important;
        }


        /* Survey List Cards (card-blog) */
        .card-blog { 
            background-color: #f8f9fa !important; /* Slightly off-white for distinction */
            border: 1px solid #dee2e6 !important; /* Light border */
            box-shadow: none !important;
            transition: all 0.3s ease;
        }
        .card-blog:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1) !important;
        }
        .card-blog .text-gradient.text-primary {
            background-image: linear-gradient(195deg, #42424a 0%, #1a1a1a 100%) !important; /* Dark gradient for headings */
            -webkit-text-fill-color: transparent;
            -webkit-background-clip: text;
        }
        .card-blog h5 {
            color: #212529 !important; /* Ensure heading text is dark */
        }
        
        /* Table Styling */
        .table-responsive {
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e9ecef; /* Subtle border for the whole table container */
        }
        .table {
            color: #212529 !important; /* Dark text for table content */
        }
        .table thead th {
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            background-color: #e9ecef !important; /* Light gray header background */
            color: #6c757d !important; /* Muted dark text for headers */
            font-weight: 700 !important;
            border-bottom: 1px solid #dee2e6 !important;
        }
        .table tbody tr {
            background-color: #fff !important; /* White row background */
            transition: all 0.2s ease;
        }
        .table tbody tr:hover {
            background-color: #f2f2f2 !important; /* Lighter gray on hover */
        }
        .table td, .table th {
            padding: 12px 15px !important; /* More padding for cells */
        }
        .table p { /* For text within cells */
            color: #212529 !important;
        }
        .table small {
            color: #6c757d !important;
        }

        /* Badge in table */
        .badge.bg-gradient-success {
            background: linear-gradient(135deg, #28a745 0%, #218838 100%) !important; /* Green gradient */
            color: #fff;
        }
        .btn-link .fas {
            font-size: 1.1rem; /* Slightly larger icons for actions */
        }
        .btn-link .text-info {
            color: #17a2b8 !important; /* Standard Bootstrap info color */
        }
        .btn-link .text-danger {
            color: #dc3545 !important; /* Standard Bootstrap danger color */
        }

        /* Empty state */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px; /* More padding */
            text-align: center;
            color: #6c757d !important; /* Muted text */
        }
        .empty-state i {
            font-size: 4rem; /* Larger icon */
            margin-bottom: 1.5rem;
            color: #adb5bd; /* Light muted color for icon */
        }
        .empty-state h5 {
            color: #343a40 !important; /* Darker heading */
            margin-bottom: 0.5rem;
        }
        .empty-state p {
            font-size: 1.1rem;
        }

        /* Dropdown menus for sort/download */
        .dropdown-menu {
            border-radius: 8px !important;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1) !important;
            background-color: #fff !important;
            border: none !important;
            padding: 0.5rem 0;
        }
        .dropdown-item {
            color: #212529 !important;
            font-weight: 500 !important;
            padding: 0.75rem 1.5rem !important;
        }
        .dropdown-item:hover {
            background-color: #f8f9fa !important;
            color: #007bff !important;
        }
        .dropdown-divider {
            border-top-color: #e9ecef !important;
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

        /* Custom period filter group */
        .period-filter-group .form-control {
            border-color: #ced4da;
            padding: 0.5rem 0.75rem; /* Adjust padding for date inputs */
            height: auto; /* Allow height to adjust based on content */
        }
        .period-filter-group .form-control:focus {
            box-shadow: 0 0 0 0.25rem rgba(0, 123, 255, 0.25);
            border-color: #80bdff;
        }
        .period-filter-group label {
            color: #344767;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .period-filter-group .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
            color: #fff;
        }
        .period-filter-group .btn-secondary:hover {
            background-color: #5a6268;
            border-color: #545b62;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100">
 <?php include 'components/aside.php'; ?>

    <div class="main-content position-relative border-radius-lg">
       
        
        <div class="d-flex align-items-center flex-grow-1 page-title-section">
            <nav aria-label="breadcrumb" class="flex-grow-1">
                <ol class="breadcrumb mb-0 navbar-breadcrumb" style="background: transparent;">
                    <li class="breadcrumb-item">
                        <a href="main" class="breadcrumb-link">
                            <i class="fas fa-home me-1"></i>Home
                        </a>
                    </li>
                    <li class="breadcrumb-item active navbar-breadcrumb-active" aria-current="page">
                        Survey Submissions
                    </li>
                </ol>
                <h5 class="navbar-title mb-0">
                    Survey Submissions
                </h5>
            </nav>
        </div>
        
        <div class="container-fluid py-4">
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
                                    <div class="row p-4">
                                        <?php foreach ($surveys as $survey): ?>
                                            <div class="col-xl-3 col-md-6 mb-xl-4 mb-4">
                                                <div class="card card-blog card-plain">
                                                    <div class="card-body p-3">
                                                        <div class="d-flex flex-column">
                                                            <h5 class="mb-1 text-gradient text-primary">
                                                                <?php echo htmlspecialchars($survey['name']); ?>
                                                            </h5>
                                                            <a href="records.php?survey_id=<?php echo $survey['id']; ?>" 
                                                               class="btn btn-outline-primary btn-sm mt-3">
                                                                View Submissions
                                                                <i class="fas fa-arrow-right ms-1"></i>
                                                            </a>
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
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Age</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Sex</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Period</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Service Unit</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Location</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Ownership</th>
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
                                                            <p class="text-xs font-weight-bold mb-0"><?php echo $submission['age'] ?? 'N/A'; ?></p>
                                                        </td>
                                                        <td>
                                                            <p class="text-xs font-weight-bold mb-0"><?php echo htmlspecialchars($submission['sex'] ?? 'N/A'); ?></p>
                                                        </td>
                                                        <td>
                                                            <p class="text-xs font-weight-bold mb-0"><?php echo htmlspecialchars($submission['period'] ?? 'N/A'); ?></p>
                                                        </td>
                                                        <td>
                                                            <p class="text-xs font-weight-bold mb-0"><?php echo htmlspecialchars($submission['service_unit_name'] ?? 'N/A'); ?></p>
                                                        </td>
                                                        <td>
                                                            <p class="text-xs font-weight-bold mb-0"><?php echo htmlspecialchars($submission['location_name'] ?? 'N/A'); ?></p>
                                                        </td>
                                                        <td>
                                                            <p class="text-xs font-weight-bold mb-0"><?php echo htmlspecialchars($submission['ownership_name'] ?? 'N/A'); ?></p>
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
                                                            <button class="btn btn-link text-dark px-2 mb-0" onclick="deleteSubmission(<?php echo $submission['id']; ?>)">
                                                                <i class="fas fa-trash-alt text-danger me-2"></i>
                                                            </button>
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
                        body: `id=${submissionId}&action=delete`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire(
                                'Deleted!',
                                'The submission has been deleted.',
                                'success'
                            ).then(() => location.reload());
                        } else {
                            Swal.fire(
                                'Error!',
                                data.message || 'Failed to delete submission.',
                                'error'
                            );
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire(
                            'Error!',
                            'An error occurred while deleting the submission.',
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