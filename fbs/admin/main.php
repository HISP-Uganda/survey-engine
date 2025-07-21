<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require 'connect.php'; // Database connection

// --- Data Retrieval ---

// Get survey statistics
$surveyStats = $pdo->query("
    SELECT
        COUNT(id) as total_surveys,
        (SELECT COUNT(id) FROM submission) as total_submissions,
        (SELECT COUNT(DISTINCT survey_id) FROM submission WHERE created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) as active_surveys_last_month,
        (SELECT COUNT(id) FROM submission WHERE created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) as submissions_last_month,
        (SELECT COUNT(id) FROM submission WHERE created >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH) AND created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) as submissions_prev_month
    FROM survey
")->fetch(PDO::FETCH_ASSOC);

// Calculate percentage change for submissions
$submissions_last_month = $surveyStats['submissions_last_month'];
$submissions_prev_month = $surveyStats['submissions_prev_month'];
$submission_growth_percentage = 0;
if ($submissions_prev_month > 0) {
    $submission_growth_percentage = (($submissions_last_month - $submissions_prev_month) / $submissions_prev_month) * 100;
} elseif ($submissions_last_month > 0) {
    $submission_growth_percentage = 100; // If previous month was 0 and current is > 0
}

// Get recent submissions (MODIFIED TO INCLUDE sub.id)
$recentSubmissions = $pdo->query("
    SELECT sub.id, s.name as survey_name, sub.created, sub.uid,
           COUNT(sr.id) as response_count
    FROM submission sub
    JOIN survey s ON sub.survey_id = s.id
    LEFT JOIN submission_response sr ON sub.id = sr.submission_id
    GROUP BY sub.id
    ORDER BY sub.created DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Get survey participation data for chart (Top 10 most submitted surveys)
$surveyParticipation = $pdo->query("
    SELECT s.name, COUNT(sub.id) as submissions
    FROM survey s
    LEFT JOIN submission sub ON s.id = sub.survey_id
    GROUP BY s.id, s.name
    ORDER BY submissions DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Format data for survey participation chart
$chartLabels = [];
$chartData = [];
foreach ($surveyParticipation as $survey) {
    $chartLabels[] = $survey['name'];
    $chartData[] = $survey['submissions'];
}

// Get daily submissions for the last 30 days (for a line chart)
$dailySubmissions = $pdo->query("
    SELECT
        DATE_FORMAT(created, '%Y-%m-%d') as submission_date,
        COUNT(id) as total_daily_submissions
    FROM submission
    WHERE created >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY submission_date
    ORDER BY submission_date ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Format data for daily submissions chart
$dailyChartLabels = [];
$dailyChartData = [];
foreach ($dailySubmissions as $day) {
    $dailyChartLabels[] = $day['submission_date'];
    $dailyChartData[] = $day['total_daily_submissions'];
}

// Get survey status breakdown (active vs. inactive)
// This query might need refinement depending on your exact definition of 'inactive' surveys
// If 'inactive' means no submissions EVER, then this is fine.
// If 'inactive' means no submissions in a certain period, you'd adjust the subquery.
$surveyStatus = $pdo->query("
    SELECT
        'Active' as status,
        COUNT(DISTINCT survey_id) as count
    FROM submission
    UNION ALL
    SELECT
        'Inactive' as status,
        COUNT(s.id) - (SELECT COUNT(DISTINCT survey_id) FROM submission) as count
    FROM survey s
")->fetchAll(PDO::FETCH_ASSOC);

// Format data for survey status chart
$statusLabels = [];
$statusData = [];
foreach ($surveyStatus as $status) {
    $statusLabels[] = $status['status'];
    $statusData[] = $status['count'];
}

// Get average response length
// **IMPORTANT:** You need to replace 'response_value' with the actual column name
// in your 'submission_response' table that stores the text of the answers.
// Common names could be 'answer_text', 'value', 'text_response', etc.
$avgResponseLength = $pdo->query("
    SELECT AVG(LENGTH(response_value)) as avg_length
    FROM submission_response
    WHERE response_value IS NOT NULL AND response_value != ''
")->fetch(PDO::FETCH_ASSOC);
$avgResponseLength = round($avgResponseLength['avg_length'] ?? 0, 1);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link href="argon-dashboard-master/assets/css/nucleo-icons.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-svg.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/argon-dashboard.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Custom CSS to apply the #2a5298 color */
        .bg-gradient-primary {
            background-image: linear-gradient(310deg, #2a5298 0%, #3e6ea0 100%) !important;
        }
        .icon.icon-shape.bg-gradient-primary {
            background-image: linear-gradient(310deg, #2a5298 0%, #3e6ea0 100%) !important;
        }
        .text-primary {
            color: #2a5298 !important;
        }
        /* Adjustments for navbar if it's using the primary color directly */
        .navbar .navbar-brand-img { /* Assuming your navbar brand uses this */
            /* You might need to adjust this filter or replace the image if it's hardcoded and doesn't match */
            /* filter: hue-rotate(200deg) saturate(2); */
        }
        .navbar-vertical.navbar-expand-xs .navbar-nav > .nav-item .nav-link.active .icon,
        .navbar-vertical.navbar-expand-xs .navbar-nav > .nav-item .nav-link.active .text-primary {
            color: #2a5298 !important; /* Ensure active link icon and text match */
        }
        .navbar-vertical.navbar-expand-xs .navbar-nav > .nav-item .nav-link.active {
            background-color: rgba(42, 82, 152, 0.1); /* Lighter background for active link */
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100">
    <?php include 'components/aside.php'; ?>

    <div class="main-content position-relative border-radius-lg">
        <?php include 'components/navbar.php'; ?>

   <!-- Page Title Section -->
         <div class="d-flex align-items-center flex-grow-1" style=" background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); padding: 1rem 1.5rem; margin-bottom: 1.5rem;">
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
                <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-uppercase font-weight-bold">Total Surveys</p>
                                        <h5 class="font-weight-bolder">
                                            <?php echo $surveyStats['total_surveys']; ?>
                                        </h5>
                                        <p class="mb-0">
                                            <span class="text-success text-sm font-weight-bolder">+<?= $surveyStats['active_surveys_last_month'] ?></span>
                                            active last month
                                        </p>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-primary shadow-primary text-center rounded-circle">
                                        <i class="fas fa-clipboard-list text-lg opacity-10"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-uppercase font-weight-bold">Total Submissions</p>
                                        <h5 class="font-weight-bolder">
                                            <?= $surveyStats['total_submissions'] ?>
                                        </h5>
                                        <p class="mb-0">
                                            <?php if ($submission_growth_percentage >= 0): ?>
                                                <span class="text-success text-sm font-weight-bolder">+<?= round($submission_growth_percentage, 1) ?>%</span>
                                                from last month
                                            <?php else: ?>
                                                <span class="text-danger text-sm font-weight-bolder"><?= round($submission_growth_percentage, 1) ?>%</span>
                                                from last month
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-danger shadow-danger text-center rounded-circle">
                                        <i class="fas fa-paper-plane text-lg opacity-10"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-uppercase font-weight-bold">Avg. Submissions/Survey</p>
                                        <h5 class="font-weight-bolder">
                                            <?= $surveyStats['total_surveys'] > 0 ?
                                                round($surveyStats['total_submissions'] / $surveyStats['total_surveys'], 1) : 0 ?>
                                        </h5>
                                        <p class="mb-0">
                                            <span class="text-success text-sm font-weight-bolder">Improved Efficiency</span>
                                        </p>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-success shadow-success text-center rounded-circle">
                                        <i class="fas fa-chart-line text-lg opacity-10"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                 <div class="col-xl-3 col-sm-6">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-uppercase font-weight-bold">Avg. Response Length</p>
                                        <h5 class="font-weight-bolder">
                                            <?= $avgResponseLength ?> chars
                                        </h5>
                                        <p class="mb-0">
                                            <span class="text-info text-sm font-weight-bolder">Insightful Data</span>
                                        </p>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-info shadow-info text-center rounded-circle">
                                        <i class="fas fa-text-width text-lg opacity-10"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mb-4">
                <div class="col-lg-8">
                    <div class="card z-index-2">
                        <div class="card-header pb-0">
                            <h6>Top 10 Survey Participation</h6>
                            <p class="text-sm">
                                <i class="fa fa-chart-bar text-primary"></i>
                                <span class="font-weight-bold">Most popular surveys</span> by submissions
                            </p>
                        </div>
                        <div class="card-body p-3">
                            <div class="chart">
                                <canvas id="surveyChart" class="chart-canvas" height="200"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6>Survey Status Breakdown</h6>
                            <p class="text-sm">
                                <i class="fa fa-info-circle text-primary"></i>
                                Active vs. Inactive Surveys
                            </p>
                        </div>
                        <div class="card-body p-3">
                            <div class="chart">
                                <canvas id="statusChart" class="chart-canvas" height="300"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-12">
                    <div class="card z-index-2">
                        <div class="card-header pb-0">
                            <h6>Daily Submission Trends (Last 30 Days)</h6>
                            <p class="text-sm">
                                <i class="fa fa-calendar-alt text-primary"></i>
                                <span class="font-weight-bold">Track submission volume</span> over time
                            </p>
                        </div>
                        <div class="card-body p-3">
                            <div class="chart">
                                <canvas id="dailySubmissionsChart" class="chart-canvas" height="50"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card mb-4">
                        <div class="card-header pb-0">
                            <h6>Recent Submissions</h6>
                            <p class="text-sm">
                                <i class="fa fa-history text-primary"></i>
                                Last 5 survey responses with details
                            </p>
                        </div>
                        <div class="card-body px-0 pt-0 pb-2">
                            <div class="table-responsive p-0">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Survey Name</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">User ID / Guest UID</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Responses</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Submission Date</th>
                                            <th class="text-secondary opacity-7"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentSubmissions as $submission): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex px-2 py-1">
                                                        <div class="d-flex flex-column justify-content-center">
                                                            <h6 class="mb-0 text-sm"><?= htmlspecialchars($submission['survey_name']) ?></h6>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <p class="text-xs font-weight-bold mb-0"><?= htmlspecialchars($submission['uid']) ?></p>
                                                </td>
                                                <td class="align-middle text-center text-sm">
                                                    <span class="badge badge-sm bg-gradient-success"><?= $submission['response_count'] ?></span>
                                                </td>
                                                <td class="align-middle text-center">
                                                    <span class="text-secondary text-xs font-weight-bold"><?= date('M d, Y H:i', strtotime($submission['created'])) ?></span>
                                                </td>
                                                <td class="align-middle">
                                                    <a href="view_record.php?submission_id=<?= htmlspecialchars($submission['id']) ?>"
                                                    class="text-secondary font-weight-bold text-xs"
                                                    data-toggle="tooltip"
                                                    title="View Details">
                                                    View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<!-- 
    <?php include 'components/fixednav.php'; ?> -->

    <script src="argon-dashboard-master/assets/js/core/popper.min.js"></script>
    <script src="argon-dashboard-master/assets/js/core/bootstrap.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/argon-dashboard.js"></script>

    <script>
        // Set primary color for charts
        const primaryColor = '#2a5298';
        const primaryColorLight = 'rgba(42, 82, 152, 0.6)'; // A lighter shade for backgrounds

        // Survey Participation Chart (Bar Chart for better comparison)
        const ctx1 = document.getElementById('surveyChart').getContext('2d');
        new Chart(ctx1, {
            type: 'bar', // Changed to bar for better comparison of submissions
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [{
                    label: 'Total Submissions',
                    data: <?= json_encode($chartData) ?>,
                    backgroundColor: primaryColorLight, // Use light primary color
                    borderColor: primaryColor, // Use primary color for border
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false // No legend needed for single dataset
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                if (Number.isInteger(value)) {
                                    return value;
                                }
                            }
                        }
                    },
                    x: {
                        ticks: {
                            autoSkip: false, // Prevent skipping labels if many
                            maxRotation: 45, // Rotate labels if they overlap
                            minRotation: 0
                        }
                    }
                }
            }
        });

        // Survey Status Breakdown Chart (Doughnut Chart)
        const ctx2 = document.getElementById('statusChart').getContext('2d');
        new Chart(ctx2, {
            type: 'doughnut',  // radar, scatter, line, bar, pie, doughnut 
            data: {
                labels: <?= json_encode($statusLabels) ?>,
                datasets: [{
                    data: <?= json_encode($statusData) ?>,
                    backgroundColor: [
                        primaryColor,         // Active surveys
                        '#adb5bd'             // Inactive surveys (a neutral grey)
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'right'
                    }
                },
                cutout: '70%'
            }
        });

        // Daily Submissions Chart (Line Chart)
        const ctx3 = document.getElementById('dailySubmissionsChart').getContext('2d');
        new Chart(ctx3, {
            type: 'line',
            data: {
                labels: <?= json_encode($dailyChartLabels) ?>,
                datasets: [{
                    label: 'Daily Submissions',
                    data: <?= json_encode($dailyChartData) ?>,
                    backgroundColor: primaryColorLight,
                    borderColor: primaryColor,
                    tension: 0.4, // Smooth the line
                    fill: true, // Fill area under the line
                    pointRadius: 3,
                    pointBackgroundColor: primaryColor,
                    pointBorderColor: '#fff',
                    pointHoverRadius: 5,
                    pointHoverBackgroundColor: primaryColor
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Submissions'
                        },
                        ticks: {
                            callback: function(value) {
                                if (Number.isInteger(value)) {
                                    return value;
                                }
                            }
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Date'
                        },
                        ticks: {
                            autoSkip: true,
                            maxTicksLimit: 10 // Show fewer labels on x-axis if too many days
                        }
                    }
                }
            }
        });


        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    </script>
</body>
</html>