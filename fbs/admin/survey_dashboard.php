<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}
require_once 'includes/session_timeout.php';

require 'connect.php';

$surveyId = $_GET['survey_id'] ?? null;
if (!$surveyId) {
    header("Location: records.php");
    exit();
}

// Fetch survey details
try {
    $surveyStmt = $pdo->prepare("SELECT id, name, type FROM survey WHERE id = ?");
    $surveyStmt->execute([$surveyId]);
    $survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$survey) {
        header("Location: records.php");
        exit();
    }
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    header("Location: records.php");
    exit();
}

// Fetch survey statistics
$stats = [];
try {
    // Total submissions
    $totalStmt = $pdo->prepare("SELECT COUNT(*) as total FROM submission WHERE survey_id = ?");
    $totalStmt->execute([$surveyId]);
    $stats['total_submissions'] = $totalStmt->fetchColumn();
    
    // Submissions today
    $todayStmt = $pdo->prepare("SELECT COUNT(*) as today FROM submission WHERE survey_id = ? AND DATE(created) = CURDATE()");
    $todayStmt->execute([$surveyId]);
    $stats['today_submissions'] = $todayStmt->fetchColumn();
    
    // Submissions this week
    $weekStmt = $pdo->prepare("SELECT COUNT(*) as week FROM submission WHERE survey_id = ? AND WEEK(created) = WEEK(NOW())");
    $weekStmt->execute([$surveyId]);
    $stats['week_submissions'] = $weekStmt->fetchColumn();
    
    // Submissions this month
    $monthStmt = $pdo->prepare("SELECT COUNT(*) as month FROM submission WHERE survey_id = ? AND MONTH(created) = MONTH(NOW()) AND YEAR(created) = YEAR(NOW())");
    $monthStmt->execute([$surveyId]);
    $stats['month_submissions'] = $monthStmt->fetchColumn();
    
    // Average responses per submission
    $avgStmt = $pdo->prepare("
        SELECT AVG(response_count) as avg_responses 
        FROM (
            SELECT COUNT(sr.id) as response_count 
            FROM submission s 
            LEFT JOIN submission_response sr ON s.id = sr.submission_id 
            WHERE s.survey_id = ? 
            GROUP BY s.id
        ) as response_counts
    ");
    $avgStmt->execute([$surveyId]);
    $stats['avg_responses'] = round($avgStmt->fetchColumn(), 2);
    
} catch (PDOException $e) {
    error_log("Database error fetching stats: " . $e->getMessage());
    $stats = [
        'total_submissions' => 0,
        'today_submissions' => 0,
        'week_submissions' => 0,
        'month_submissions' => 0,
        'avg_responses' => 0
    ];
}

// Fetch questions for the survey
$questions = [];
try {
    $questionsStmt = $pdo->prepare("
        SELECT q.id, q.label, q.question_type, sq.position
        FROM question q
        JOIN survey_question sq ON q.id = sq.question_id
        WHERE sq.survey_id = ?
        ORDER BY sq.position ASC
    ");
    $questionsStmt->execute([$surveyId]);
    $questions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching questions: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Survey Analytics Dashboard</title>
    <link rel="icon" href="argon-dashboard-master/assets/img/brand/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-icons.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-svg.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/argon-dashboard.css" rel="stylesheet">
    
    <!-- Chart.js for visualizations -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/date-fns@2.29.3/index.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@2.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    
    <style>
        body {
            background-color: #f8f9fa !important;
        }
        
        .dashboard-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: none;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        
        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
            overflow: hidden;
        }
        
        .chart-wrapper {
            position: relative;
            height: 400px;
            width: 100%;
            max-width: 100%;
            overflow: hidden;
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f8f9fa;
        }
        
        .chart-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #2d3748;
            margin: 0;
        }
        
        .chart-controls {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .chart-type-selector {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.5rem;
            background: white;
            font-weight: 500;
        }
        
        .chart-type-selector:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .filter-section {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }
        
        .filter-header {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        
        .filter-item label {
            font-weight: 500;
            color: #4a5568;
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .filter-item select,
        .filter-item input {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.75rem;
            width: 100%;
            font-weight: 500;
        }
        
        .filter-item select:focus,
        .filter-item input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn-apply-filters {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 0.75rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-apply-filters:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
            color: white;
        }
        
        .question-analysis {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
            overflow: hidden;
        }
        
        .question-selector {
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 10px;
        }
        
        .prediction-section {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }
        
        .prediction-header {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .prediction-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }
        
        .prediction-card {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .prediction-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .prediction-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .loading-spinner {
            display: none;
            text-align: center;
            padding: 2rem;
        }
        
        .loading-spinner .spinner-border {
            color: #667eea;
        }
        
        /* Canvas specific styles */
        canvas {
            max-width: 100% !important;
            height: auto !important;
            display: block;
        }
        
        /* Ensure charts don't overflow their containers */
        #timeChart, #questionChart {
            max-width: 100%;
            max-height: 400px;
        }
        
        @media (max-width: 768px) {
            .dashboard-header {
                padding: 1.5rem;
            }
            
            .chart-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .chart-controls {
                width: 100%;
                justify-content: flex-start;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .chart-wrapper {
                height: 300px;
            }
            
            .chart-container {
                padding: 1rem;
            }
            
            .question-analysis {
                padding: 1rem;
            }
        }
    </style>
</head>

<body class="g-sidenav-show bg-gray-100">
    <?php include 'components/aside.php'; ?>

    <div class="main-content position-relative border-radius-lg">
        <?php include 'components/navbar.php'; ?>
        
        <div class="container-fluid py-4">
            <!-- Dashboard Header -->
            <div class="dashboard-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="mb-2">Survey Analytics Dashboard</h1>
                        <h4 class="mb-0 opacity-8"><?php echo htmlspecialchars($survey['name']); ?></h4>
                    </div>
                    <div>
                        <a href="records.php?survey_id=<?php echo $surveyId; ?>" class="btn btn-light">
                            <i class="fas fa-arrow-left me-2"></i>Back to Records
                        </a>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stat-card">
                        <div class="stat-number text-primary"><?php echo number_format($stats['total_submissions']); ?></div>
                        <div class="stat-label">Total Submissions</div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stat-card">
                        <div class="stat-number text-success"><?php echo number_format($stats['today_submissions']); ?></div>
                        <div class="stat-label">Today</div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stat-card">
                        <div class="stat-number text-warning"><?php echo number_format($stats['week_submissions']); ?></div>
                        <div class="stat-label">This Week</div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stat-card">
                        <div class="stat-number text-info"><?php echo $stats['avg_responses']; ?></div>
                        <div class="stat-label">Avg Responses</div>
                    </div>
                </div>
            </div>

            <!-- Filters Section -->
            <div class="filter-section">
                <div class="filter-header">
                    <i class="fas fa-filter"></i>
                    Filters & Analysis Options
                </div>
                <div class="filter-grid">
                    <div class="filter-item">
                        <label for="dateRange">Date Range</label>
                        <select id="dateRange" class="form-select">
                            <option value="all">All Time</option>
                            <option value="today">Today</option>
                            <option value="week">This Week</option>
                            <option value="month">This Month</option>
                            <option value="quarter">This Quarter</option>
                            <option value="year">This Year</option>
                            <option value="custom">Custom Range</option>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label for="startDate">Start Date</label>
                        <input type="date" id="startDate" class="form-control" disabled>
                    </div>
                    <div class="filter-item">
                        <label for="endDate">End Date</label>
                        <input type="date" id="endDate" class="form-control" disabled>
                    </div>
                    <div class="filter-item d-flex align-items-end">
                        <button id="applyFilters" class="btn btn-apply-filters w-100">
                            <i class="fas fa-chart-line me-2"></i>Apply Filters
                        </button>
                    </div>
                </div>
            </div>

            <!-- Submissions Over Time Chart -->
            <div class="chart-container">
                <div class="chart-header">
                    <h3 class="chart-title">Submissions Over Time</h3>
                    <div class="chart-controls">
                        <select id="timeChartType" class="chart-type-selector">
                            <option value="line">Line Chart</option>
                            <option value="bar">Bar Chart</option>
                            <option value="area">Area Chart</option>
                        </select>
                    </div>
                </div>
                <div class="loading-spinner" id="timeChartLoader">
                    <div class="spinner-border" role="status"></div>
                </div>
                <div class="chart-wrapper">
                    <canvas id="timeChart"></canvas>
                </div>
            </div>

            <!-- Question Analysis Section -->
            <div class="question-analysis">
                <div class="chart-header">
                    <h3 class="chart-title">Question Response Analysis</h3>
                    <div class="chart-controls">
                        <select id="questionChartType" class="chart-type-selector">
                            <option value="doughnut">Doughnut Chart</option>
                            <option value="pie">Pie Chart</option>
                            <option value="bar">Bar Chart</option>
                            <option value="horizontalBar">Horizontal Bar</option>
                        </select>
                    </div>
                </div>
                
                <!-- Purpose explanation -->
                <div class="alert alert-info mb-4" style="border-left: 4px solid #17a2b8; background: #f8f9fa; padding: 1rem; border-radius: 8px;">
                    <div class="d-flex align-items-start">
                        <i class="fas fa-info-circle text-info me-2 mt-1"></i>
                        <div>
                            <strong>Purpose:</strong> This section visualizes how users responded to individual questions in your survey. 
                            Select a question below to see the distribution of responses in interactive charts with detailed statistics including 
                            response rates, most common answers, and averages for numeric questions.
                        </div>
                    </div>
                </div>
                
                <div class="question-selector">
                    <label for="questionSelect"><strong>Select Question to Analyze:</strong></label>
                    <select id="questionSelect" class="form-select">
                        <option value="">Choose a question...</option>
                        <?php foreach ($questions as $question): ?>
                            <option value="<?php echo $question['id']; ?>" data-type="<?php echo $question['question_type']; ?>">
                                Q<?php echo $question['position']; ?>: <?php echo htmlspecialchars(substr($question['label'], 0, 80)); ?><?php echo strlen($question['label']) > 80 ? '...' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="loading-spinner" id="questionChartLoader">
                    <div class="spinner-border" role="status"></div>
                </div>
                <div class="chart-wrapper">
                    <canvas id="questionChart"></canvas>
                </div>
                
                <div id="questionStats" class="mt-4" style="display: none;">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="stat-number text-primary" id="totalResponses">0</div>
                                <div class="stat-label">Total Responses</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="stat-number text-success" id="averageScore">0</div>
                                <div class="stat-label">Average Score</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="stat-number text-warning" id="mostCommon">-</div>
                                <div class="stat-label">Most Common</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="stat-number text-info" id="responseRate">0%</div>
                                <div class="stat-label">Response Rate</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Predictive Analytics Section -->
            <div class="prediction-section">
                <div class="prediction-header">
                    <i class="fas fa-crystal-ball"></i>
                    Predictive Analytics & Insights
                </div>
                <div class="prediction-grid">
                    <div class="prediction-card">
                        <div class="prediction-value" id="predictedNext7Days">-</div>
                        <div class="prediction-label">Predicted submissions next 7 days</div>
                    </div>
                    <div class="prediction-card">
                        <div class="prediction-value" id="trendDirection">-</div>
                        <div class="prediction-label">Current trend</div>
                    </div>
                    <div class="prediction-card">
                        <div class="prediction-value" id="peakDay">-</div>
                        <div class="prediction-label">Peak submission day</div>
                    </div>
                    <div class="prediction-card">
                        <div class="prediction-value" id="completionRate">-</div>
                        <div class="prediction-label">Average completion rate</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="argon-dashboard-master/assets/js/core/popper.min.js"></script>
    <script src="argon-dashboard-master/assets/js/core/bootstrap.min.js"></script>
    <script src="argon-dashboard-master/assets/js/argon-dashboard.js"></script>

    <script>
        const surveyId = <?php echo json_encode($surveyId); ?>;
        let timeChart = null;
        let questionChart = null;

        // Initialize dashboard
        document.addEventListener('DOMContentLoaded', function() {
            initializeCharts();
            setupEventListeners();
            loadInitialData();
            setupResizeHandler();
        });

        function setupEventListeners() {
            // Date range change
            document.getElementById('dateRange').addEventListener('change', function() {
                const customInputs = document.querySelectorAll('#startDate, #endDate');
                customInputs.forEach(input => {
                    input.disabled = this.value !== 'custom';
                    if (this.value !== 'custom') input.value = '';
                });
            });

            // Apply filters
            document.getElementById('applyFilters').addEventListener('click', loadSubmissionData);

            // Chart type changes
            document.getElementById('timeChartType').addEventListener('change', loadSubmissionData);
            document.getElementById('questionChartType').addEventListener('change', loadQuestionData);

            // Question selection
            document.getElementById('questionSelect').addEventListener('change', loadQuestionData);
        }

        function initializeCharts() {
            // Initialize time chart
            const timeCtx = document.getElementById('timeChart').getContext('2d');
            timeChart = new Chart(timeCtx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Submissions',
                        data: [],
                        borderColor: '#667eea',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        x: {
                            type: 'time',
                            time: {
                                unit: 'day'
                            },
                            grid: {
                                display: true,
                                color: 'rgba(0,0,0,0.1)'
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                display: true,
                                color: 'rgba(0,0,0,0.1)'
                            }
                        }
                    },
                    elements: {
                        point: {
                            radius: 3,
                            hoverRadius: 6
                        }
                    }
                }
            });

            // Initialize question chart
            const questionCtx = document.getElementById('questionChart').getContext('2d');
            questionChart = new Chart(questionCtx, {
                type: 'doughnut',
                data: {
                    labels: [],
                    datasets: [{
                        data: [],
                        backgroundColor: [
                            '#667eea', '#764ba2', '#f093fb', '#f5576c',
                            '#4facfe', '#00f2fe', '#43e97b', '#38f9d7',
                            '#ffecd2', '#fcb69f', '#a8edea', '#fed6e3'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            position: 'right',
                            align: 'center',
                            labels: {
                                usePointStyle: true,
                                padding: 20,
                                font: {
                                    size: 12
                                }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0,0,0,0.8)',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            cornerRadius: 8
                        }
                    },
                    layout: {
                        padding: {
                            top: 10,
                            bottom: 10,
                            left: 10,
                            right: 10
                        }
                    }
                }
            });
        }

        function loadInitialData() {
            loadSubmissionData();
            loadPredictiveAnalytics();
        }

        async function loadSubmissionData() {
            showLoader('timeChartLoader');
            
            try {
                const params = new URLSearchParams({
                    survey_id: surveyId,
                    action: 'timeline',
                    date_range: document.getElementById('dateRange').value,
                    start_date: document.getElementById('startDate').value,
                    end_date: document.getElementById('endDate').value
                });

                const response = await fetch('dashboard_api.php?' + params);
                const data = await response.json();

                if (data.success) {
                    updateTimeChart(data.timeline);
                } else {
                    console.error('Failed to load submission data:', data.message);
                }
            } catch (error) {
                console.error('Error loading submission data:', error);
            } finally {
                hideLoader('timeChartLoader');
            }
        }

        async function loadQuestionData() {
            const questionId = document.getElementById('questionSelect').value;
            if (!questionId) {
                questionChart.data.labels = [];
                questionChart.data.datasets[0].data = [];
                questionChart.update();
                document.getElementById('questionStats').style.display = 'none';
                return;
            }

            showLoader('questionChartLoader');
            
            try {
                const params = new URLSearchParams({
                    survey_id: surveyId,
                    action: 'question_analysis',
                    question_id: questionId,
                    date_range: document.getElementById('dateRange').value,
                    start_date: document.getElementById('startDate').value,
                    end_date: document.getElementById('endDate').value
                });

                const response = await fetch('dashboard_api.php?' + params);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                console.log('Question analysis response:', data); // Debug logging

                if (data.success) {
                    if (data.responses && data.responses.length > 0) {
                        updateQuestionChart(data.responses);
                        updateQuestionStats(data.stats);
                    } else {
                        // Show empty state message
                        questionChart.data.labels = ['No data available'];
                        questionChart.data.datasets[0].data = [1];
                        questionChart.data.datasets[0].backgroundColor = ['#e9ecef'];
                        questionChart.update();
                        
                        // Show stats as zeros
                        updateQuestionStats({
                            total_responses: 0,
                            average_score: 'N/A',
                            most_common: 'No responses',
                            response_rate: 0
                        });
                    }
                } else {
                    console.error('Failed to load question data:', data.message);
                    // Show error in chart
                    questionChart.data.labels = ['Error loading data'];
                    questionChart.data.datasets[0].data = [1];
                    questionChart.data.datasets[0].backgroundColor = ['#dc3545'];
                    questionChart.update();
                }
            } catch (error) {
                console.error('Error loading question data:', error);
            } finally {
                hideLoader('questionChartLoader');
            }
        }

        async function loadPredictiveAnalytics() {
            try {
                const params = new URLSearchParams({
                    survey_id: surveyId,
                    action: 'predictions'
                });

                const response = await fetch('dashboard_api.php?' + params);
                const data = await response.json();

                if (data.success) {
                    updatePredictions(data.predictions);
                }
            } catch (error) {
                console.error('Error loading predictions:', error);
            }
        }

        function updateTimeChart(timelineData) {
            const chartType = document.getElementById('timeChartType').value;
            
            timeChart.config.type = chartType === 'area' ? 'line' : chartType;
            timeChart.data.labels = timelineData.map(item => item.date);
            timeChart.data.datasets[0].data = timelineData.map(item => item.count);
            
            if (chartType === 'area') {
                timeChart.data.datasets[0].fill = true;
                timeChart.data.datasets[0].backgroundColor = 'rgba(102, 126, 234, 0.1)';
            } else {
                timeChart.data.datasets[0].fill = false;
                timeChart.data.datasets[0].backgroundColor = 'rgba(102, 126, 234, 0.8)';
            }
            
            timeChart.update();
        }

        function updateQuestionChart(responseData) {
            const chartType = document.getElementById('questionChartType').value;
            
            questionChart.config.type = chartType === 'horizontalBar' ? 'bar' : chartType;
            questionChart.data.labels = responseData.map(item => item.response);
            questionChart.data.datasets[0].data = responseData.map(item => item.count);
            
            if (chartType === 'horizontalBar') {
                questionChart.options.indexAxis = 'y';
            } else {
                delete questionChart.options.indexAxis;
            }
            
            questionChart.update();
        }

        function updateQuestionStats(stats) {
            document.getElementById('totalResponses').textContent = stats.total_responses || 0;
            document.getElementById('averageScore').textContent = stats.average_score || 'N/A';
            document.getElementById('mostCommon').textContent = stats.most_common || 'N/A';
            document.getElementById('responseRate').textContent = (stats.response_rate || 0) + '%';
            document.getElementById('questionStats').style.display = 'block';
        }

        function updatePredictions(predictions) {
            document.getElementById('predictedNext7Days').textContent = predictions.next_7_days || '-';
            document.getElementById('trendDirection').textContent = predictions.trend || '-';
            document.getElementById('peakDay').textContent = predictions.peak_day || '-';
            document.getElementById('completionRate').textContent = (predictions.completion_rate || 0) + '%';
        }

        function showLoader(loaderId) {
            document.getElementById(loaderId).style.display = 'block';
        }

        function hideLoader(loaderId) {
            document.getElementById(loaderId).style.display = 'none';
        }
        
        function setupResizeHandler() {
            let resizeTimeout;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimeout);
                resizeTimeout = setTimeout(function() {
                    // Update legend position based on screen size
                    if (questionChart) {
                        const isMobile = window.innerWidth < 768;
                        questionChart.options.plugins.legend.position = isMobile ? 'bottom' : 'right';
                        questionChart.update();
                    }
                    
                    if (timeChart) {
                        timeChart.resize();
                    }
                    if (questionChart) {
                        questionChart.resize();
                    }
                }, 250);
            });
            
            // Initial legend position setup
            const isMobile = window.innerWidth < 768;
            if (questionChart) {
                questionChart.options.plugins.legend.position = isMobile ? 'bottom' : 'right';
                questionChart.update();
            }
        }
    </script>
</body>
</html>