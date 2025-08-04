<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require 'connect.php';

$surveyId = $_GET['survey_id'] ?? null;
$action = $_GET['action'] ?? null;

if (!$surveyId || !$action) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

// Verify survey exists
try {
    $surveyCheck = $pdo->prepare("SELECT id FROM survey WHERE id = ?");
    $surveyCheck->execute([$surveyId]);
    if (!$surveyCheck->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Survey not found']);
        exit();
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit();
}

header('Content-Type: application/json');

try {
    switch ($action) {
        case 'timeline':
            echo json_encode(getTimelineData($pdo, $surveyId));
            break;
        case 'question_analysis':
            echo json_encode(getQuestionAnalysis($pdo, $surveyId));
            break;
        case 'predictions':
            echo json_encode(getPredictiveAnalytics($pdo, $surveyId));
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Dashboard API error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}

function getTimelineData($pdo, $surveyId) {
    $dateRange = $_GET['date_range'] ?? 'all';
    $startDate = $_GET['start_date'] ?? null;
    $endDate = $_GET['end_date'] ?? null;
    
    // Build date filter
    $dateFilter = '';
    $params = ['survey_id' => $surveyId];
    
    switch ($dateRange) {
        case 'today':
            $dateFilter = 'AND DATE(s.created) = CURDATE()';
            break;
        case 'week':
            $dateFilter = 'AND s.created >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
            break;
        case 'month':
            $dateFilter = 'AND s.created >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
            break;
        case 'quarter':
            $dateFilter = 'AND s.created >= DATE_SUB(NOW(), INTERVAL 3 MONTH)';
            break;
        case 'year':
            $dateFilter = 'AND s.created >= DATE_SUB(NOW(), INTERVAL 1 YEAR)';
            break;
        case 'custom':
            if ($startDate && $endDate) {
                $dateFilter = 'AND DATE(s.created) BETWEEN :start_date AND :end_date';
                $params['start_date'] = $startDate;
                $params['end_date'] = $endDate;
            }
            break;
    }
    
    $sql = "
        SELECT 
            DATE(s.created) as date,
            COUNT(*) as count
        FROM submission s
        WHERE s.survey_id = :survey_id
        $dateFilter
        GROUP BY DATE(s.created)
        ORDER BY date ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $timeline = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return ['success' => true, 'timeline' => $timeline];
}

function getQuestionAnalysis($pdo, $surveyId) {
    $questionId = $_GET['question_id'] ?? null;
    if (!$questionId) {
        return ['success' => false, 'message' => 'Question ID required'];
    }
    
    $dateRange = $_GET['date_range'] ?? 'all';
    $startDate = $_GET['start_date'] ?? null;
    $endDate = $_GET['end_date'] ?? null;
    
    // Build date filter for submissions
    $dateFilter = '';
    $params = ['survey_id' => $surveyId, 'question_id' => $questionId];
    
    switch ($dateRange) {
        case 'today':
            $dateFilter = 'AND DATE(sub.created) = CURDATE()';
            break;
        case 'week':
            $dateFilter = 'AND sub.created >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
            break;
        case 'month':
            $dateFilter = 'AND sub.created >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
            break;
        case 'quarter':
            $dateFilter = 'AND sub.created >= DATE_SUB(NOW(), INTERVAL 3 MONTH)';
            break;
        case 'year':
            $dateFilter = 'AND sub.created >= DATE_SUB(NOW(), INTERVAL 1 YEAR)';
            break;
        case 'custom':
            if ($startDate && $endDate) {
                $dateFilter = 'AND DATE(sub.created) BETWEEN :start_date AND :end_date';
                $params['start_date'] = $startDate;
                $params['end_date'] = $endDate;
            }
            break;
    }
    
    // Get question type
    $questionStmt = $pdo->prepare("SELECT question_type FROM question WHERE id = ?");
    $questionStmt->execute([$questionId]);
    $questionType = $questionStmt->fetchColumn();
    
    // Get response distribution
    $sql = "
        SELECT 
            sr.response_value as response,
            COUNT(*) as count
        FROM submission_response sr
        JOIN submission sub ON sr.submission_id = sub.id
        WHERE sub.survey_id = :survey_id 
        AND sr.question_id = :question_id
        $dateFilter
        GROUP BY sr.response_value
        ORDER BY count DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate statistics
    $stats = calculateQuestionStats($pdo, $surveyId, $questionId, $questionType, $dateFilter, $params);
    
    return [
        'success' => true, 
        'responses' => $responses,
        'stats' => $stats
    ];
}

function calculateQuestionStats($pdo, $surveyId, $questionId, $questionType, $dateFilter, $params) {
    // Total responses for this question
    $totalSql = "
        SELECT COUNT(*) as total
        FROM submission_response sr
        JOIN submission sub ON sr.submission_id = sub.id
        WHERE sub.survey_id = :survey_id 
        AND sr.question_id = :question_id
        $dateFilter
    ";
    
    $totalStmt = $pdo->prepare($totalSql);
    $totalStmt->execute($params);
    $totalResponses = $totalStmt->fetchColumn();
    
    // Total submissions for survey (for response rate calculation)
    $submissionParams = ['survey_id' => $surveyId];
    $submissionDateFilter = str_replace('sub.created', 's.created', $dateFilter);
    if (isset($params['start_date'])) $submissionParams['start_date'] = $params['start_date'];
    if (isset($params['end_date'])) $submissionParams['end_date'] = $params['end_date'];
    
    $totalSubmissionsSql = "
        SELECT COUNT(*) as total
        FROM submission s
        WHERE s.survey_id = :survey_id
        $submissionDateFilter
    ";
    
    $totalSubmissionsStmt = $pdo->prepare($totalSubmissionsSql);
    $totalSubmissionsStmt->execute($submissionParams);
    $totalSubmissions = $totalSubmissionsStmt->fetchColumn();
    
    // Most common response
    $mostCommonSql = "
        SELECT sr.response_value
        FROM submission_response sr
        JOIN submission sub ON sr.submission_id = sub.id
        WHERE sub.survey_id = :survey_id 
        AND sr.question_id = :question_id
        $dateFilter
        GROUP BY sr.response_value
        ORDER BY COUNT(*) DESC
        LIMIT 1
    ";
    
    $mostCommonStmt = $pdo->prepare($mostCommonSql);
    $mostCommonStmt->execute($params);
    $mostCommon = $mostCommonStmt->fetchColumn() ?: 'N/A';
    
    // Calculate average score for numeric responses
    $averageScore = null;
    if (in_array($questionType, ['rating', 'number', 'integer', 'decimal'])) {
        $avgSql = "
            SELECT AVG(CAST(sr.response_value AS DECIMAL(10,2))) as avg_score
            FROM submission_response sr
            JOIN submission sub ON sr.submission_id = sub.id
            WHERE sub.survey_id = :survey_id 
            AND sr.question_id = :question_id
            AND sr.response_value REGEXP '^[0-9]+(\\.[0-9]+)?$'
            $dateFilter
        ";
        
        $avgStmt = $pdo->prepare($avgSql);
        $avgStmt->execute($params);
        $averageScore = round($avgStmt->fetchColumn(), 2);
    }
    
    $responseRate = $totalSubmissions > 0 ? round(($totalResponses / $totalSubmissions) * 100, 1) : 0;
    
    return [
        'total_responses' => $totalResponses,
        'average_score' => $averageScore,
        'most_common' => $mostCommon,
        'response_rate' => $responseRate
    ];
}

function getPredictiveAnalytics($pdo, $surveyId) {
    // Get submission data for the last 30 days
    $sql = "
        SELECT 
            DATE(created) as date,
            COUNT(*) as count
        FROM submission 
        WHERE survey_id = :survey_id 
        AND created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(created)
        ORDER BY date ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['survey_id' => $surveyId]);
    $recentData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($recentData)) {
        return [
            'success' => true,
            'predictions' => [
                'next_7_days' => 0,
                'trend' => 'No data',
                'peak_day' => 'No data',
                'completion_rate' => 0
            ]
        ];
    }
    
    // Calculate simple predictions
    $daily_counts = array_column($recentData, 'count');
    $average_daily = array_sum($daily_counts) / count($daily_counts);
    $predicted_7_days = round($average_daily * 7);
    
    // Determine trend (simple linear trend)
    $trend = 'Stable';
    if (count($daily_counts) >= 2) {
        $recent_avg = array_sum(array_slice($daily_counts, -7)) / min(7, count($daily_counts));
        $older_avg = array_sum(array_slice($daily_counts, 0, -7)) / max(1, count($daily_counts) - 7);
        
        if ($recent_avg > $older_avg * 1.1) {
            $trend = '↗️ Increasing';
        } elseif ($recent_avg < $older_avg * 0.9) {
            $trend = '↘️ Decreasing';
        }
    }
    
    // Find peak day of week
    $dayOfWeekSql = "
        SELECT 
            DAYNAME(created) as day_name,
            COUNT(*) as count
        FROM submission 
        WHERE survey_id = :survey_id 
        AND created >= DATE_SUB(NOW(), INTERVAL 90 DAY)
        GROUP BY DAYOFWEEK(created), DAYNAME(created)
        ORDER BY count DESC
        LIMIT 1
    ";
    
    $dayStmt = $pdo->prepare($dayOfWeekSql);
    $dayStmt->execute(['survey_id' => $surveyId]);
    $peakDay = $dayStmt->fetchColumn() ?: 'No data';
    
    // Calculate completion rate (average responses per submission)
    $completionSql = "
        SELECT 
            s.id,
            COUNT(sr.id) as response_count,
            (SELECT COUNT(*) FROM question q JOIN survey_question sq ON q.id = sq.question_id WHERE sq.survey_id = :survey_id) as total_questions
        FROM submission s
        LEFT JOIN submission_response sr ON s.id = sr.submission_id
        WHERE s.survey_id = :survey_id
        AND s.created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY s.id
    ";
    
    $completionStmt = $pdo->prepare($completionSql);
    $completionStmt->execute(['survey_id' => $surveyId]);
    $completionData = $completionStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $completion_rate = 0;
    if (!empty($completionData)) {
        $rates = [];
        foreach ($completionData as $submission) {
            if ($submission['total_questions'] > 0) {
                $rates[] = ($submission['response_count'] / $submission['total_questions']) * 100;
            }
        }
        $completion_rate = !empty($rates) ? round(array_sum($rates) / count($rates), 1) : 0;
    }
    
    return [
        'success' => true,
        'predictions' => [
            'next_7_days' => $predicted_7_days,
            'trend' => $trend,
            'peak_day' => $peakDay,
            'completion_rate' => $completion_rate
        ]
    ];
}
?>