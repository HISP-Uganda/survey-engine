<?php
/**
 * Safe DHIS2 Survey Deletion Function
 * 
 * This function safely deletes a DHIS2 survey and all related data,
 * being smart about shared resources (questions, option sets).
 */

require_once 'connect.php';
require_once 'includes/question_helper.php';

function safeDeleteDHIS2Survey($surveyId, $pdo) {
    try {
        $pdo->beginTransaction();
        
        // Check if survey exists and is DHIS2 type
        $stmt = $pdo->prepare("SELECT id, name FROM survey WHERE id = ? AND type = 'dhis2'");
        $stmt->execute([$surveyId]);
        $survey = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$survey) {
            return ['success' => false, 'message' => 'DHIS2 survey not found'];
        }
        
        $deletionLog = [];
        
        // 1. Get all questions for this survey
        $stmt = $pdo->prepare("
            SELECT DISTINCT q.id, q.option_set_id 
            FROM question q
            JOIN survey_question sq ON q.id = sq.question_id
            WHERE sq.survey_id = ?
        ");
        $stmt->execute([$surveyId]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 2. Process each question
        foreach ($questions as $question) {
            $questionId = $question['id'];
            $optionSetId = $question['option_set_id'];
            
            // Use the helper function to check if question can be deleted
            if (canDeleteQuestion($pdo, $questionId, $surveyId)) {
                // Question not used elsewhere, safe to delete
                
                // Handle option set deletion
                if ($optionSetId) {
                    // Check if option set is used by other questions
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) as count 
                        FROM question q2 
                        WHERE q2.option_set_id = ? AND q2.id != ?
                    ");
                    $stmt->execute([$optionSetId, $questionId]);
                    $optionSetUsage = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                    
                    if ($optionSetUsage == 0) {
                        // Delete option set values and option set
                        $pdo->prepare("DELETE FROM option_set_values WHERE option_set_id = ?")->execute([$optionSetId]);
                        $pdo->prepare("DELETE FROM option_set WHERE id = ?")->execute([$optionSetId]);
                        $deletionLog[] = "Deleted option set: $optionSetId";
                    }
                }
                
                // Delete question (this will cascade to question_dhis2_mapping)
                $pdo->prepare("DELETE FROM question WHERE id = ?")->execute([$questionId]);
                $deletionLog[] = "Deleted question: $questionId";
            }
        }
        
        // 3. Delete the survey (this will cascade to survey_question, submissions, etc.)
        $pdo->prepare("DELETE FROM survey WHERE id = ?")->execute([$surveyId]);
        $deletionLog[] = "Deleted survey: {$survey['name']} (ID: $surveyId)";
        
        // 4. Log the deletion
        $stmt = $pdo->prepare("
            INSERT INTO deletion_log (table_name, record_id, record_name, deleted_at, deleted_by)
            VALUES (?, ?, ?, NOW(), ?)
        ");
        $stmt->execute(['survey', $surveyId, $survey['name'], 'PHP_FUNCTION']);
        
        $pdo->commit();
        
        return [
            'success' => true, 
            'message' => "DHIS2 survey '{$survey['name']}' deleted successfully",
            'details' => $deletionLog
        ];
        
    } catch (Exception $e) {
        $pdo->rollback();
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

// API endpoint usage
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['survey_id'])) {
    session_start();
    
    if (!isset($_SESSION['admin_logged_in'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not authorized']);
        exit();
    }
    
    $surveyId = intval($_POST['survey_id']);
    $result = safeDeleteDHIS2Survey($surveyId, $pdo);
    
    header('Content-Type: application/json');
    echo json_encode($result);
    exit();
}
?>