<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../../admin/connect.php';

$method = $_SERVER['REQUEST_METHOD'];
$surveyId = $_GET['survey_id'] ?? $_POST['survey_id'] ?? null;

// For POST requests, also check JSON body for survey_id
if (!$surveyId && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $surveyId = $input['survey_id'] ?? null;
}

if (!$surveyId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Survey ID is required']);
    exit();
}

if ($method === 'GET') {
    // Load groupings for a survey
    try {
        $stmt = $pdo->prepare("
            SELECT stage_id, group_title, questions, group_order 
            FROM tracker_groupings 
            WHERE survey_id = ? 
            ORDER BY stage_id, group_order ASC
        ");
        $stmt->execute([$surveyId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Organize groupings by stage_id
        $groupings = [];
        foreach ($results as $row) {
            if (!isset($groupings[$row['stage_id']])) {
                $groupings[$row['stage_id']] = [];
            }
            
            $groupings[$row['stage_id']][] = [
                'groupTitle' => $row['group_title'],
                'questions' => json_decode($row['questions'], true),
                'groupOrder' => $row['group_order']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'data' => $groupings
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error in public groupings API: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    
} elseif ($method === 'POST') {
    // Save groupings for a survey (allow from public for form submissions)
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['groupings'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Groupings data is required']);
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        // First, delete existing groupings for this survey
        $deleteStmt = $pdo->prepare("DELETE FROM tracker_groupings WHERE survey_id = ?");
        $deleteStmt->execute([$surveyId]);
        
        // Insert new groupings
        $insertStmt = $pdo->prepare("
            INSERT INTO tracker_groupings (survey_id, stage_id, group_title, questions, group_order)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        foreach ($input['groupings'] as $stageId => $groups) {
            foreach ($groups as $index => $group) {
                $insertStmt->execute([
                    $surveyId,
                    $stageId,
                    $group['groupTitle'],
                    json_encode($group['questions']),
                    $index
                ]);
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Groupings saved successfully'
        ]);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Database error saving groupings in public API: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    
} elseif ($method === 'DELETE') {
    // Delete all groupings for a survey
    try {
        $stmt = $pdo->prepare("DELETE FROM tracker_groupings WHERE survey_id = ?");
        $stmt->execute([$surveyId]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Groupings deleted successfully'
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error deleting groupings in public API: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
?>