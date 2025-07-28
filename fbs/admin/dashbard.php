<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require 'connect.php';

// Fetch all surveys with submission counts
$stmt = $pdo->query("
    SELECT 
        s.id, 
        s.name, 
        COUNT(sub.id) AS submission_count 
    FROM 
        survey s 
    LEFT JOIN 
        submission sub ON s.id = sub.survey_id 
    GROUP BY 
        s.id, s.name
    ORDER BY 
        s.name ASC
");

$surveys = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Survey Dashboard</title>
    <!-- Argon Dashboard CSS -->
    <link href="argon-dashboard-master/assets/css/nucleo-icons.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-svg.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/argon-dashboard.min.css" rel="stylesheet">
    <style>
        .survey-card {
            transition: all 0.3s ease;
            border: none;
            box-shadow: 0 0 2rem 0 rgba(34, 78, 167, 0.10);
        }
        .survey-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1.5rem rgba(34, 78, 167, 0.18);
        }
        .survey-card .card-body {
            padding: 1.5rem;
        }
        .survey-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #224ea7;
            margin-bottom: 1rem;
        }
        .submission-count {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .submission-count i {
            font-size: 1.2rem;
            margin-right: 0.75rem;
            color: #224ea7;
        }
        .submission-count .count {
            font-weight: 600;
            color: #224ea7;
        }
        .no-submissions {
            color: #adb5bd;
            font-style: italic;
        }
        .card-footer {
            background-color: #224ea7;
            border-top: none;
            padding: 0.75rem 1.5rem 1.5rem;
        }
        .card-footer .btn {
            background: #fff;
            color: #224ea7;
            border: 1px solid #224ea7;
        }
        .card-footer .btn:hover {
            background: #224ea7;
            color: #fff;
        }
        .empty-state {
            text-align: center;
            padding: 3rem 0;
        }
        .empty-state-icon {
            font-size: 3rem;
            color: #224ea7;
            margin-bottom: 1rem;
        }
    </style>
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
                        <?= htmlspecialchars($pageTitle ?? 'Analytics') ?>
                    </li>
                </ol>
                <h4 class="navbar-title mb-0 mt-1" style="color: #fff; text-shadow: 0 1px 8px #1e3c72, 0 0 2px #ffd700; font-weight: 700;">
                    <?= htmlspecialchars($pageTitle ?? 'Analytics') ?>
                </h4>
            </nav>
        </div>

        <div class="container-fluid py-4">
            <!-- Header -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0">Survey Dashboard</h6>
                            <p class="text-sm text-secondary mb-0">
                                <?php echo count($surveys); ?> active surveys
                            </p>
                        </div>
                        <a href="survey.php" class="btn bg-gradient-primary mb-0">
                            <i class="fas fa-plus me-1"></i> Create New Survey
                        </a>
                    </div>
                </div>
            </div>

            <!-- Survey Cards -->
            <?php if (empty($surveys)): ?>
                <div class="card empty-state">
                    <div class="card-body">
                        <div class="empty-state-icon">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <h5 class="text-secondary">No surveys found</h5>
                        <p class="text-sm text-secondary mb-4">
                            Get started by creating your first survey
                        </p>
                        <a href="create_survey.php" class="btn bg-gradient-primary">
                            <i class="fas fa-plus me-1"></i> Create Survey
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($surveys as $survey): ?>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card survey-card">
                                <div class="card-body">
                                    <h5 class="survey-title"><?php echo htmlspecialchars($survey['name']); ?></h5>
                                    <div class="submission-count">
                                        <i class="fas fa-clipboard-list"></i>
                                        <span class="count">
                                            <?php if ($survey['submission_count'] > 0): ?>
                                                <?php echo $survey['submission_count']; ?> submission<?php echo $survey['submission_count'] != 1 ? 's' : ''; ?>
                                            <?php else: ?>
                                                <span class="no-submissions">No submissions yet</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="card-footer pt-0">
                                    <a href="records.php?survey_id=<?php echo $survey['id']; ?>" 
                                       class="btn btn-sm bg-gradient-info mb-0 w-100">
                                        <i class="fas fa-eye me-1"></i> View Records
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- <?php include 'components/fixednav.php'; ?> -->
    
    <!-- Core JS Files -->
    <script src="argon-dashboard-master/assets/js/core/popper.min.js"></script>
    <script src="argon-dashboard-master/assets/js/core/bootstrap.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/argon-dashboard.min.js"></script>
    
    <script>
        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    </script>
</body>
</html>