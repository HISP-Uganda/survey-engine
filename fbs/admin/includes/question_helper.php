<?php
/**
 * Question Management Helper Functions
 * Provides reusability and prevents question duplication
 */

/**
 * Find existing question by DHIS2 mapping
 * @param PDO $pdo Database connection
 * @param string $dhis2ElementId DHIS2 data element or attribute ID
 * @param string $label Question label
 * @param string $questionType Question type
 * @return array|null Existing question data or null
 */
function findExistingQuestionByDHIS2Mapping($pdo, $dhis2ElementId, $label, $questionType) {
    $stmt = $pdo->prepare("
        SELECT q.id, q.label, q.question_type, q.option_set_id
        FROM question q
        JOIN question_dhis2_mapping qm ON q.id = qm.question_id
        WHERE (qm.dhis2_dataelement_id = ? OR qm.dhis2_attribute_id = ?)
        AND q.label = ? 
        AND q.question_type = ?
        LIMIT 1
    ");
    $stmt->execute([$dhis2ElementId, $dhis2ElementId, $label, $questionType]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Find existing question by label and type (for non-DHIS2 questions)
 * @param PDO $pdo Database connection
 * @param string $label Question label
 * @param string $questionType Question type
 * @return array|null Existing question data or null
 */
function findExistingQuestionByLabel($pdo, $label, $questionType) {
    $stmt = $pdo->prepare("
        SELECT q.id, q.label, q.question_type, q.option_set_id
        FROM question q
        LEFT JOIN question_dhis2_mapping qm ON q.id = qm.question_id
        WHERE q.label = ? 
        AND q.question_type = ?
        AND qm.question_id IS NULL
        LIMIT 1
    ");
    $stmt->execute([$label, $questionType]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get or create question with reusability logic
 * @param PDO $pdo Database connection
 * @param string $label Question label
 * @param string $questionType Question type
 * @param bool $isRequired Whether question is required
 * @param string|null $dhis2ElementId DHIS2 element ID for mapping
 * @param int|null $optionSetId Option set ID
 * @return int Question ID
 */
function getOrCreateQuestion($pdo, $label, $questionType, $isRequired = true, $dhis2ElementId = null, $optionSetId = null) {
    // First, try to find existing question
    $existingQuestion = null;
    
    if ($dhis2ElementId) {
        // For DHIS2 questions, check by DHIS2 mapping first
        $existingQuestion = findExistingQuestionByDHIS2Mapping($pdo, $dhis2ElementId, $label, $questionType);
    } else {
        // For regular questions, check by label and type
        $existingQuestion = findExistingQuestionByLabel($pdo, $label, $questionType);
    }
    
    if ($existingQuestion) {
        // Update option set if needed
        if ($optionSetId && $existingQuestion['option_set_id'] != $optionSetId) {
            $stmt = $pdo->prepare("UPDATE question SET option_set_id = ? WHERE id = ?");
            $stmt->execute([$optionSetId, $existingQuestion['id']]);
        }
        
        return (int)$existingQuestion['id'];
    }
    
    // Create new question if none exists
    $stmt = $pdo->prepare("
        INSERT INTO question (label, question_type, is_required, option_set_id) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$label, $questionType, $isRequired ? 1 : 0, $optionSetId]);
    
    return (int)$pdo->lastInsertId();
}

/**
 * Create DHIS2 mapping for question
 * @param PDO $pdo Database connection
 * @param int $questionId Question ID
 * @param string|null $dhis2DataElementId DHIS2 data element ID
 * @param string|null $dhis2AttributeId DHIS2 attribute ID
 * @param string|null $dhis2OptionSetId DHIS2 option set ID
 * @param string|null $dhis2ProgramStageId DHIS2 program stage ID
 */
function createOrUpdateDHIS2Mapping($pdo, $questionId, $dhis2DataElementId = null, $dhis2AttributeId = null, $dhis2OptionSetId = null, $dhis2ProgramStageId = null) {
    // Check if mapping already exists
    $stmt = $pdo->prepare("
        SELECT id FROM question_dhis2_mapping 
        WHERE question_id = ? 
        AND (dhis2_dataelement_id = ? OR dhis2_attribute_id = ?)
    ");
    $stmt->execute([$questionId, $dhis2DataElementId, $dhis2AttributeId]);
    $existingMapping = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingMapping) {
        // Update existing mapping
        $stmt = $pdo->prepare("
            UPDATE question_dhis2_mapping 
            SET dhis2_dataelement_id = ?, dhis2_attribute_id = ?, dhis2_option_set_id = ?, dhis2_program_stage_id = ?
            WHERE id = ?
        ");
        $stmt->execute([$dhis2DataElementId, $dhis2AttributeId, $dhis2OptionSetId, $dhis2ProgramStageId, $existingMapping['id']]);
    } else {
        // Create new mapping
        $stmt = $pdo->prepare("
            INSERT INTO question_dhis2_mapping (question_id, dhis2_dataelement_id, dhis2_attribute_id, dhis2_option_set_id, dhis2_program_stage_id) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$questionId, $dhis2DataElementId, $dhis2AttributeId, $dhis2OptionSetId, $dhis2ProgramStageId]);
    }
}

/**
 * Check if question can be safely deleted
 * @param PDO $pdo Database connection
 * @param int $questionId Question ID
 * @param int $excludeSurveyId Survey ID to exclude from check
 * @return bool True if question can be deleted
 */
function canDeleteQuestion($pdo, $questionId, $excludeSurveyId = null) {
    $sql = "SELECT COUNT(*) as count FROM survey_question sq WHERE sq.question_id = ?";
    $params = [$questionId];
    
    if ($excludeSurveyId) {
        $sql .= " AND sq.survey_id != ?";
        $params[] = $excludeSurveyId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result['count'] == 0;
}

/**
 * Get question usage statistics
 * @param PDO $pdo Database connection
 * @param int $questionId Question ID
 * @return array Usage statistics
 */
function getQuestionUsageStats($pdo, $questionId) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT sq.survey_id) as survey_count,
            COUNT(DISTINCT sub.id) as submission_count,
            GROUP_CONCAT(DISTINCT s.name SEPARATOR ', ') as survey_names
        FROM question q
        LEFT JOIN survey_question sq ON q.id = sq.question_id
        LEFT JOIN survey s ON sq.survey_id = s.id
        LEFT JOIN submission sub ON s.id = sub.survey_id
        WHERE q.id = ?
        GROUP BY q.id
    ");
    $stmt->execute([$questionId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'survey_count' => 0,
        'submission_count' => 0,
        'survey_names' => ''
    ];
}
?>