<?php
/**
 * Question Groupings API
 * Handles saving and retrieving question groupings for tracker programs
 */

session_start();
require_once '../connect.php';
require_once '../includes/session_timeout.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$input = file_get_contents('php://input');
$data = json_decode($input, true);

try {
    switch ($method) {
        case 'GET':
            handleGetGroupings();
            break;
        case 'POST':
            handleSaveGroupings($data);
            break;
        case 'DELETE':
            handleDeleteGroupings($data);
            break;
        default:
            throw new Exception('Method not allowed');
    }
} catch (Exception $e) {
    error_log("Question groupings API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Get groupings for a survey
 */
function handleGetGroupings() {
    global $pdo;
    
    $surveyId = $_GET['survey_id'] ?? null;
    if (!$surveyId) {
        throw new Exception('Survey ID is required');
    }
    
    // Get all groups for this survey
    $stmt = $pdo->prepare("
        SELECT g.*, 
               GROUP_CONCAT(qa.question_id ORDER BY qa.question_order) as question_ids,
               GROUP_CONCAT(qa.question_order ORDER BY qa.question_order) as question_orders
        FROM question_groups g
        LEFT JOIN question_group_assignments qa ON g.id = qa.group_id
        WHERE g.survey_id = ?
        GROUP BY g.id
        ORDER BY g.stage_id, g.group_order, g.id
    ");
    $stmt->execute([$surveyId]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the response by stage
    $groupings = [];
    foreach ($groups as $group) {
        $stageId = $group['stage_id'];
        
        if (!isset($groupings[$stageId])) {
            $groupings[$stageId] = [];
        }
        
        $questions = [];
        if (!empty($group['question_ids'])) {
            $questionIds = explode(',', $group['question_ids']);
            $questionOrders = explode(',', $group['question_orders']);
            
            for ($i = 0; $i < count($questionIds); $i++) {
                $questions[] = [
                    'questionId' => $questionIds[$i],
                    'questionOrder' => intval($questionOrders[$i] ?? $i)
                ];
            }
        }
        
        $groupings[$stageId][] = [
            'groupId' => $group['id'],
            'groupName' => $group['group_name'],
            'groupOrder' => intval($group['group_order']),
            'isDefault' => boolval($group['is_default']),
            'questions' => $questions
        ];
    }
    
    echo json_encode(['success' => true, 'groupings' => $groupings]);
}

/**
 * Save groupings for a survey
 */
function handleSaveGroupings($data) {
    global $pdo;
    
    $surveyId = $data['survey_id'] ?? null;
    $groupings = $data['groupings'] ?? [];
    
    if (!$surveyId || !is_array($groupings)) {
        throw new Exception('Invalid data provided');
    }
    
    $pdo->beginTransaction();
    
    try {
        // Clear existing groupings for this survey
        $stmt = $pdo->prepare("DELETE FROM question_groups WHERE survey_id = ?");
        $stmt->execute([$surveyId]);
        
        // Insert new groupings
        foreach ($groupings as $stageId => $stageGroups) {
            foreach ($stageGroups as $groupIndex => $group) {
                // Insert group
                $stmt = $pdo->prepare("
                    INSERT INTO question_groups (survey_id, stage_id, group_name, group_order, is_default)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $isDefault = ($group['groupName'] === 'Ungrouped Questions' || isset($group['isDefault']) && $group['isDefault']) ? 1 : 0;
                $stmt->execute([
                    $surveyId,
                    $stageId,
                    $group['groupName'],
                    $groupIndex,
                    $isDefault
                ]);
                
                $groupId = $pdo->lastInsertId();
                
                // Insert question assignments
                if (!empty($group['questions']) && is_array($group['questions'])) {
                    foreach ($group['questions'] as $questionIndex => $question) {
                        if (!empty($question['questionId'])) {
                            $stmt = $pdo->prepare("
                                INSERT INTO question_group_assignments (group_id, question_id, question_order)
                                VALUES (?, ?, ?)
                            ");
                            $stmt->execute([
                                $groupId,
                                $question['questionId'],
                                $question['questionOrder'] ?? $questionIndex
                            ]);
                        }
                    }
                }
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Groupings saved successfully to database'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }
}

/**
 * Delete all groupings for a survey (reset to default)
 */
function handleDeleteGroupings($data) {
    global $pdo;
    
    $surveyId = $data['survey_id'] ?? null;
    if (!$surveyId) {
        throw new Exception('Survey ID is required');
    }
    
    $stmt = $pdo->prepare("DELETE FROM question_groups WHERE survey_id = ?");
    $stmt->execute([$surveyId]);
    
    // Insert default group
    $stmt = $pdo->prepare("
        INSERT INTO question_groups (survey_id, stage_id, group_name, group_order, is_default)
        VALUES (?, 'default_stage', 'Ungrouped Questions', 0, 1)
    ");
    $stmt->execute([$surveyId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Groupings reset to default'
    ]);
}
?>