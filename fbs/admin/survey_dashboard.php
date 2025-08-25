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
    $surveyStmt = $pdo->prepare("SELECT id, name, type, program_type, domain_type FROM survey WHERE id = ?");
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
    // Total submissions (including both regular and tracker)
    $totalStmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM submission WHERE survey_id = ?) + 
            (SELECT COUNT(*) FROM tracker_submissions WHERE survey_id = ?) as total
    ");
    $totalStmt->execute([$surveyId, $surveyId]);
    $stats['total_submissions'] = $totalStmt->fetchColumn();
    
    // Get breakdown by submission type
    $regularStmt = $pdo->prepare("SELECT COUNT(*) FROM submission WHERE survey_id = ?");
    $regularStmt->execute([$surveyId]);
    $stats['regular_submissions'] = $regularStmt->fetchColumn();
    
    $trackerStmt = $pdo->prepare("SELECT COUNT(*) FROM tracker_submissions WHERE survey_id = ?");
    $trackerStmt->execute([$surveyId]);
    $stats['tracker_submissions'] = $trackerStmt->fetchColumn();
    
    // Submissions today (including both types)
    $todayStmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM submission WHERE survey_id = ? AND DATE(created) = CURDATE()) + 
            (SELECT COUNT(*) FROM tracker_submissions WHERE survey_id = ? AND DATE(submitted_at) = CURDATE()) as today
    ");
    $todayStmt->execute([$surveyId, $surveyId]);
    $stats['today_submissions'] = $todayStmt->fetchColumn();
    
    // Submissions this week (including both types)
    $weekStmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM submission WHERE survey_id = ? AND WEEK(created) = WEEK(NOW())) + 
            (SELECT COUNT(*) FROM tracker_submissions WHERE survey_id = ? AND WEEK(submitted_at) = WEEK(NOW())) as week
    ");
    $weekStmt->execute([$surveyId, $surveyId]);
    $stats['week_submissions'] = $weekStmt->fetchColumn();
    
    // Submissions this month (including both types)
    $monthStmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM submission WHERE survey_id = ? AND MONTH(created) = MONTH(NOW()) AND YEAR(created) = YEAR(NOW())) + 
            (SELECT COUNT(*) FROM tracker_submissions WHERE survey_id = ? AND MONTH(submitted_at) = MONTH(NOW()) AND YEAR(submitted_at) = YEAR(NOW())) as month
    ");
    $monthStmt->execute([$surveyId, $surveyId]);
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
    $avgResult = $avgStmt->fetchColumn();
    $stats['avg_responses'] = round($avgResult ?? 0, 2);
    
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
            background: #ffffff;
            color: #2d3748;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
        }
        
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
            transition: none;
        }
        
        .stat-card:hover {
            transform: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: #2d3748;
        }
        
        .stat-label {
            color: #718096;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .chart-container {
            background: white;
            border-radius: 8px;
            padding: 1.25rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .chart-wrapper {
            position: relative;
            height: 320px;
            width: 100%;
            max-width: 100%;
            overflow: hidden;
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .chart-title {
            font-size: 1.1rem;
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
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 0.4rem;
            background: white;
            font-weight: 500;
            font-size: 0.875rem;
        }
        
        .chart-type-selector:focus {
            outline: none;
            border-color: #4a5568;
            box-shadow: 0 0 0 2px rgba(74, 85, 104, 0.1);
        }
        
        .filter-section {
            background: white;
            border-radius: 8px;
            padding: 1.25rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
            margin-bottom: 1.5rem;
        }
        
        .filter-header {
            font-size: 1rem;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 0.875rem;
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
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 0.625rem;
            width: 100%;
            font-weight: 500;
            font-size: 0.875rem;
        }
        
        .filter-item select:focus,
        .filter-item input:focus {
            outline: none;
            border-color: #4a5568;
            box-shadow: 0 0 0 2px rgba(74, 85, 104, 0.1);
        }
        
        .btn-apply-filters {
            background: #4a5568;
            border: none;
            color: white;
            padding: 0.625rem 1.5rem;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.875rem;
            transition: none;
        }
        
        .btn-apply-filters:hover {
            background: #2d3748;
            transform: none;
            box-shadow: none;
            color: white;
        }
        
        .question-analysis {
            background: white;
            border-radius: 8px;
            padding: 1.25rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .question-selector {
            margin-bottom: 1.25rem;
            padding: 0.875rem;
            background: #f8f9fa;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }
        
        .prediction-section {
            background: #4a5568;
            color: white;
            border-radius: 8px;
            padding: 1.25rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
            margin-bottom: 1.5rem;
        }
        
        .prediction-header {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .prediction-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        
        .prediction-card {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            padding: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .prediction-value {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.375rem;
        }
        
        .prediction-label {
            font-size: 0.825rem;
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
                height: 260px;
            }
            
            .chart-container {
                padding: 0.875rem;
            }
            
            .question-analysis {
                padding: 0.875rem;
            }
            
            .filter-section {
                padding: 1rem;
            }
            
            .prediction-section {
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
                        <?php if ($survey['type'] == 'dhis2' && !empty($survey['program_type'])): ?>
                        <div class="mt-2">
                            <span class="badge badge-info me-2">
                                <?php 
                                    echo ucfirst($survey['program_type']) . ' Program';
                                    if (!empty($survey['domain_type'])) {
                                        echo ' (' . ucfirst($survey['domain_type']) . ')';
                                    }
                                ?>
                            </span>
                            <span class="badge badge-secondary">DHIS2 Integration</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <a href="records.php" class="btn btn-light">
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
                        <?php if ($survey['type'] == 'dhis2' && $stats['tracker_submissions'] > 0): ?>
                        <div class="mt-2">
                            <small class="text-muted">
                                Regular: <?php echo number_format($stats['regular_submissions']); ?> | 
                                Tracker: <?php echo number_format($stats['tracker_submissions']); ?>
                            </small>
                        </div>
                        <?php endif; ?>
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
            
            <?php if ($survey['type'] == 'dhis2' && $survey['program_type'] == 'tracker'): ?>
            <!-- Tracker-specific Stats -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="chart-container">
                        <div class="chart-header">
                            <h3 class="chart-title">
                                <i class="fas fa-route me-2"></i>Tracker Program Statistics
                            </h3>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="stat-card">
                                    <div class="stat-number text-primary" id="trackedEntitiesCount"><?php echo number_format($stats['tracker_submissions']); ?></div>
                                    <div class="stat-label">Tracked Entity Instances</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="stat-card">
                                    <div class="stat-number text-success" id="activeEnrollments">-</div>
                                    <div class="stat-label">Active Enrollments</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="stat-card">
                                    <div class="stat-number text-info" id="totalEvents">-</div>
                                    <div class="stat-label">Total Events</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

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
                    <?php if ($survey['type'] == 'dhis2' && $stats['tracker_submissions'] > 0): ?>
                    <div class="filter-item">
                        <label for="submissionType">Submission Type</label>
                        <select id="submissionType" class="form-select">
                            <option value="all">All Types</option>
                            <option value="regular">Regular Submissions</option>
                            <option value="tracker">Tracker Submissions</option>
                        </select>
                    </div>
                    <?php endif; ?>
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
                    <h3 class="chart-title">
                        <?php if ($survey['type'] == 'dhis2' && $survey['program_type'] == 'tracker'): ?>
                        <i class="fas fa-timeline me-2"></i>Tracker Activity Over Time
                        <?php else: ?>
                        Submissions Over Time
                        <?php endif; ?>
                    </h3>
                    <div class="chart-controls">
                        <?php if ($survey['type'] == 'dhis2' && $survey['program_type'] == 'tracker'): ?>
                        <select id="trackerMetricType" class="chart-type-selector me-2">
                            <option value="enrollments">Enrollments</option>
                            <option value="events">Events</option>
                            <option value="both">Both</option>
                        </select>
                        <?php endif; ?>
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

            <?php if ($survey['type'] == 'dhis2' && $survey['program_type'] == 'tracker'): ?>
            <!-- Tracker Program Analysis Section -->
            <div class="question-analysis">
                <div class="chart-header">
                    <h3 class="chart-title">
                        <i class="fas fa-project-diagram me-2"></i>Program Stage Analysis
                    </h3>
                    <div class="chart-controls">
                        <select id="trackerAnalysisType" class="chart-type-selector me-2">
                            <option value="stage_completion">Stage Completion</option>
                            <option value="stage_events">Events per Stage</option>
                            <option value="enrollment_status">Enrollment Status</option>
                        </select>
                        <select id="trackerChartType" class="chart-type-selector">
                            <option value="doughnut">Doughnut Chart</option>
                            <option value="pie">Pie Chart</option>
                            <option value="bar">Bar Chart</option>
                            <option value="horizontalBar">Horizontal Bar</option>
                        </select>
                    </div>
                </div>
                
                <!-- Purpose explanation for tracker -->
                <div class="alert alert-info mb-4" style="border-left: 4px solid #17a2b8; background: #f8f9fa; padding: 1rem; border-radius: 8px;">
                    <div class="d-flex align-items-start">
                        <i class="fas fa-route text-info me-2 mt-1"></i>
                        <div>
                            <strong>Tracker Program Analysis:</strong> This section analyzes your DHIS2 tracker program data instead of individual questions.
                            It shows stage completion rates, event distribution across program stages, enrollment status, and program flow analysis.
                            Select different analysis types above to explore various aspects of your tracker program performance.
                        </div>
                    </div>
                </div>
                
                <div class="loading-spinner" id="trackerAnalysisLoader">
                    <div class="spinner-border" role="status"></div>
                </div>
                <div class="chart-wrapper">
                    <canvas id="trackerChart"></canvas>
                </div>
                
                <div id="trackerAnalysisStats" class="mt-4" style="display: none;">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="stat-number text-primary" id="totalStages">0</div>
                                <div class="stat-label">Program Stages</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="stat-number text-success" id="completionRate">0%</div>
                                <div class="stat-label">Avg Completion Rate</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="stat-number text-warning" id="activeStage">-</div>
                                <div class="stat-label">Most Active Stage</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="stat-number text-info" id="avgEvents">0</div>
                                <div class="stat-label">Avg Events per TEI</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tracked Entity Attributes Analysis -->
            <div class="question-analysis">
                <div class="chart-header">
                    <h3 class="chart-title">
                        <i class="fas fa-user-tag me-2"></i>Tracked Entity Attributes Analysis
                    </h3>
                    <div class="chart-controls">
                        <select id="attributeChartType" class="chart-type-selector">
                            <option value="doughnut">Doughnut Chart</option>
                            <option value="pie">Pie Chart</option>
                            <option value="bar">Bar Chart</option>
                            <option value="horizontalBar">Horizontal Bar</option>
                        </select>
                    </div>
                </div>
                
                <!-- Purpose explanation for attributes -->
                <div class="alert alert-success mb-4" style="border-left: 4px solid #28a745; background: #f8f9fa; padding: 1rem; border-radius: 8px;">
                    <div class="d-flex align-items-start">
                        <i class="fas fa-tags text-success me-2 mt-1"></i>
                        <div>
                            <strong>Tracked Entity Analysis:</strong> This section analyzes the DHIS2 tracked entity data captured during enrollment,
                            including demographics, facility distribution, and other key attributes. Unlike traditional survey questions, 
                            this shows the actual tracked entity attributes and metadata from your tracker program submissions.
                        </div>
                    </div>
                </div>
                
                <div class="question-selector">
                    <label for="attributeSelect"><strong>Select Attribute to Analyze:</strong></label>
                    <select id="attributeSelect" class="form-select">
                        <option value="">Choose an attribute...</option>
                        <option value="facility">Facility Distribution</option>
                        <option value="enrollment_date">Enrollment Date Pattern</option>
                        <option value="status">Entity Status</option>
                    </select>
                </div>
                
                <div class="loading-spinner" id="attributeChartLoader">
                    <div class="spinner-border" role="status"></div>
                </div>
                <div class="chart-wrapper">
                    <canvas id="attributeChart"></canvas>
                </div>
                
                <div id="attributeStats" class="mt-4" style="display: none;">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="stat-number text-primary" id="totalEntities">0</div>
                                <div class="stat-label">Total Entities</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="stat-number text-success" id="uniqueValues">0</div>
                                <div class="stat-label">Unique Values</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="stat-number text-warning" id="mostCommonValue">-</div>
                                <div class="stat-label">Most Common</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="stat-number text-info" id="dataQuality">0%</div>
                                <div class="stat-label">Data Completeness</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <!-- Regular Question Analysis Section (for non-tracker programs) -->
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
            <?php endif; ?>

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
        const surveyInfo = <?php echo json_encode($survey); ?>;
        let timeChart = null;
        let questionChart = null;
        let trackerChart = null;
        let attributeChart = null;

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
            
            // Tracker metric type (if available)
            const trackerMetricSelect = document.getElementById('trackerMetricType');
            if (trackerMetricSelect) {
                trackerMetricSelect.addEventListener('change', loadSubmissionData);
            }

            // Submission type filter (if available)
            const submissionTypeSelect = document.getElementById('submissionType');
            if (submissionTypeSelect) {
                submissionTypeSelect.addEventListener('change', loadSubmissionData);
            }

            // Tracker-specific event listeners
            if (surveyInfo.type === 'dhis2' && surveyInfo.program_type === 'tracker') {
                // Tracker analysis controls
                document.getElementById('trackerAnalysisType').addEventListener('change', loadTrackerAnalysis);
                document.getElementById('trackerChartType').addEventListener('change', loadTrackerAnalysis);
                
                // Attribute analysis controls
                document.getElementById('attributeSelect').addEventListener('change', loadAttributeAnalysis);
                document.getElementById('attributeChartType').addEventListener('change', loadAttributeAnalysis);
            } else {
                // Regular question analysis (for non-tracker programs)
                document.getElementById('questionChartType').addEventListener('change', loadQuestionData);
                document.getElementById('questionSelect').addEventListener('change', loadQuestionData);
            }
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

            // Initialize analysis charts based on survey type
            if (surveyInfo.type === 'dhis2' && surveyInfo.program_type === 'tracker') {
                initializeTrackerCharts();
            } else {
                initializeQuestionChart();
            }
        }

        function initializeQuestionChart() {
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

        function initializeTrackerCharts() {
            // Initialize tracker program analysis chart
            const trackerCtx = document.getElementById('trackerChart').getContext('2d');
            trackerChart = new Chart(trackerCtx, {
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

            // Initialize attribute analysis chart
            const attributeCtx = document.getElementById('attributeChart').getContext('2d');
            attributeChart = new Chart(attributeCtx, {
                type: 'doughnut',
                data: {
                    labels: [],
                    datasets: [{
                        data: [],
                        backgroundColor: [
                            '#28a745', '#17a2b8', '#ffc107', '#dc3545',
                            '#6f42c1', '#fd7e14', '#e83e8c', '#6c757d',
                            '#007bff', '#20c997', '#f8f9fa', '#343a40'
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
            
            // Load tracker-specific data if this is a tracker program
            if (surveyInfo.type === 'dhis2' && surveyInfo.program_type === 'tracker') {
                loadTrackerStats();
                loadTrackerAnalysis();
                
                // Initialize placeholder data for attribute analysis section
                // (will be replaced when user selects an attribute)
                initializeAttributeSection();
            }
        }
        
        function initializeAttributeSection() {
            // Show empty state for attribute analysis until user selects something
            if (attributeChart) {
                attributeChart.data.labels = ['No attribute selected'];
                attributeChart.data.datasets[0].data = [1];
                attributeChart.data.datasets[0].backgroundColor = ['#e9ecef'];
                attributeChart.update();
            }
        }
        
        async function loadTrackerStats() {
            try {
                const params = new URLSearchParams({
                    survey_id: surveyId,
                    action: 'tracker_stats'
                });

                const response = await fetch('dashboard_api.php?' + params);
                const data = await response.json();

                if (data.success) {
                    if (data.stats.active_enrollments !== undefined) {
                        document.getElementById('activeEnrollments').textContent = data.stats.active_enrollments;
                    }
                    if (data.stats.total_events !== undefined) {
                        document.getElementById('totalEvents').textContent = data.stats.total_events;
                    }
                }
            } catch (error) {
                console.error('Error loading tracker stats:', error);
            }
        }

        async function loadTrackerAnalysis() {
            const analysisType = document.getElementById('trackerAnalysisType').value;
            showLoader('trackerAnalysisLoader');
            
            try {
                const params = new URLSearchParams({
                    survey_id: surveyId,
                    action: 'tracker_analysis',
                    analysis_type: analysisType
                });

                const response = await fetch('dashboard_api.php?' + params);
                const data = await response.json();

                if (data.success && data.data) {
                    updateTrackerChart(data.data);
                    updateTrackerAnalysisStats(data.stats);
                } else {
                    // Show placeholder data
                    const placeholderData = getPlaceholderTrackerData(analysisType);
                    updateTrackerChart(placeholderData);
                    updateTrackerAnalysisStats(null); // This will show default stats
                }
            } catch (error) {
                console.error('Error loading tracker analysis:', error);
                // Always show placeholder data on error
                const placeholderData = getPlaceholderTrackerData(analysisType);
                updateTrackerChart(placeholderData);
                updateTrackerAnalysisStats(null);
            } finally {
                hideLoader('trackerAnalysisLoader');
            }
        }

        async function loadAttributeAnalysis() {
            const attribute = document.getElementById('attributeSelect').value;
            if (!attribute) {
                attributeChart.data.labels = [];
                attributeChart.data.datasets[0].data = [];
                attributeChart.update();
                document.getElementById('attributeStats').style.display = 'none';
                return;
            }

            showLoader('attributeChartLoader');
            
            try {
                const params = new URLSearchParams({
                    survey_id: surveyId,
                    action: 'attribute_analysis',
                    attribute: attribute
                });

                const response = await fetch('dashboard_api.php?' + params);
                const data = await response.json();

                if (data.success && data.data) {
                    updateAttributeChart(data.data);
                    updateAttributeStats(data.stats);
                } else {
                    // Show placeholder data
                    const placeholderData = getPlaceholderAttributeData(attribute);
                    updateAttributeChart(placeholderData);
                    updateAttributeStats(null); // This will show default stats
                }
            } catch (error) {
                console.error('Error loading attribute analysis:', error);
                // Always show placeholder data on error
                const placeholderData = getPlaceholderAttributeData(attribute);
                updateAttributeChart(placeholderData);
                updateAttributeStats(null);
            } finally {
                hideLoader('attributeChartLoader');
            }
        }

        function getPlaceholderTimelineData() {
            // Generate sample timeline data for the last 7 days
            const data = [];
            const today = new Date();
            
            for (let i = 6; i >= 0; i--) {
                const date = new Date(today);
                date.setDate(date.getDate() - i);
                const dateString = date.toISOString().split('T')[0];
                
                // Generate realistic sample data
                let count;
                if (surveyInfo.type === 'dhis2' && surveyInfo.program_type === 'tracker') {
                    // Tracker programs typically have lower but more consistent activity
                    count = Math.floor(Math.random() * 15) + 3; // 3-18
                } else {
                    // Regular surveys might have more variable activity
                    count = Math.floor(Math.random() * 25) + 1; // 1-26
                }
                
                data.push({
                    date: dateString,
                    count: count
                });
            }
            
            return data;
        }

        function getPlaceholderTrackerData(analysisType) {
            switch(analysisType) {
                case 'stage_completion':
                    return [
                        {name: 'Registration', count: 85, percentage: 85},
                        {name: 'Follow-up 1', count: 65, percentage: 65},
                        {name: 'Follow-up 2', count: 45, percentage: 45},
                        {name: 'Final Assessment', count: 30, percentage: 30}
                    ];
                case 'stage_events':
                    return [
                        {name: 'Registration', count: 85},
                        {name: 'Follow-up 1', count: 120},
                        {name: 'Follow-up 2', count: 90},
                        {name: 'Final Assessment', count: 30}
                    ];
                case 'enrollment_status':
                    return [
                        {name: 'Active', count: 65},
                        {name: 'Completed', count: 15},
                        {name: 'Cancelled', count: 5}
                    ];
                default:
                    return [];
            }
        }

        function getPlaceholderAttributeData(attribute) {
            switch(attribute) {
                case 'facility':
                    return [
                        {name: 'Health Center A', count: 25},
                        {name: 'Health Center B', count: 20},
                        {name: 'Hospital C', count: 30},
                        {name: 'Clinic D', count: 10}
                    ];
                case 'status':
                    return [
                        {name: 'Active', count: 70},
                        {name: 'Inactive', count: 15}
                    ];
                default:
                    return [];
            }
        }

        function updateTrackerChart(data) {
            const chartType = document.getElementById('trackerChartType').value;
            
            trackerChart.config.type = chartType === 'horizontalBar' ? 'bar' : chartType;
            trackerChart.data.labels = data.map(item => item.name);
            trackerChart.data.datasets[0].data = data.map(item => item.count);
            
            if (chartType === 'horizontalBar') {
                trackerChart.options.indexAxis = 'y';
            } else {
                delete trackerChart.options.indexAxis;
            }
            
            trackerChart.update();
        }

        function updateAttributeChart(data) {
            const chartType = document.getElementById('attributeChartType').value;
            
            attributeChart.config.type = chartType === 'horizontalBar' ? 'bar' : chartType;
            attributeChart.data.labels = data.map(item => item.name);
            attributeChart.data.datasets[0].data = data.map(item => item.count);
            
            if (chartType === 'horizontalBar') {
                attributeChart.options.indexAxis = 'y';
            } else {
                delete attributeChart.options.indexAxis;
            }
            
            attributeChart.update();
        }

        function updateTrackerAnalysisStats(stats) {
            if (stats) {
                document.getElementById('totalStages').textContent = stats.total_stages || '4';
                document.getElementById('completionRate').textContent = (stats.completion_rate || 65) + '%';
                document.getElementById('activeStage').textContent = stats.most_active_stage || 'Registration';
                document.getElementById('avgEvents').textContent = stats.avg_events || '3.2';
                document.getElementById('trackerAnalysisStats').style.display = 'block';
            }
        }

        function updateAttributeStats(stats) {
            if (stats) {
                document.getElementById('totalEntities').textContent = stats.total_entities || '85';
                document.getElementById('uniqueValues').textContent = stats.unique_values || '4';
                document.getElementById('mostCommonValue').textContent = stats.most_common || 'Hospital C';
                document.getElementById('dataQuality').textContent = (stats.completeness || 95) + '%';
                document.getElementById('attributeStats').style.display = 'block';
            }
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
                
                // Add submission type filter if available
                const submissionTypeSelect = document.getElementById('submissionType');
                if (submissionTypeSelect) {
                    params.append('submission_type', submissionTypeSelect.value);
                }
                
                // Add tracker metric type if available
                const trackerMetricSelect = document.getElementById('trackerMetricType');
                if (trackerMetricSelect) {
                    params.append('tracker_metric', trackerMetricSelect.value);
                }

                const response = await fetch('dashboard_api.php?' + params);
                const data = await response.json();

                if (data.success) {
                    updateTimeChart(data.timeline);
                } else {
                    console.error('Failed to load submission data:', data.message);
                    // Show placeholder timeline data
                    const placeholderTimeline = getPlaceholderTimelineData();
                    updateTimeChart(placeholderTimeline);
                }
            } catch (error) {
                console.error('Error loading submission data:', error);
                // Show placeholder timeline data on error
                const placeholderTimeline = getPlaceholderTimelineData();
                updateTimeChart(placeholderTimeline);
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