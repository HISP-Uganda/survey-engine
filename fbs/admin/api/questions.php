<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../connect.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $search = $_GET['search'] ?? '';
    $type_filter = $_GET['type'] ?? '';
    $dhis2_only = isset($_GET['dhis2_only']) ? filter_var($_GET['dhis2_only'], FILTER_VALIDATE_BOOLEAN) : false;
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = min(50, max(10, intval($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $per_page;

    // Build query conditions
    $conditions = ['1 = 1'];
    $params = [];

    if (!empty($search)) {
        $conditions[] = '(q.label LIKE ? OR q.question_type LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    if (!empty($type_filter)) {
        $conditions[] = 'q.question_type = ?';
        $params[] = $type_filter;
    }

    if ($dhis2_only) {
        $conditions[] = '(qm.dhis2_dataelement_id IS NOT NULL OR qm.dhis2_attribute_id IS NOT NULL)';
    }

    $where_clause = implode(' AND ', $conditions);

    // Get total count
    $count_sql = "
        SELECT COUNT(DISTINCT q.id) as total
        FROM question q
        LEFT JOIN question_dhis2_mapping qm ON q.id = qm.question_id
        WHERE $where_clause
    ";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get questions
    $sql = "
        SELECT 
            q.id,
            q.label,
            q.question_type,
            q.is_required,
            q.options,
            q.created,
            qm.dhis2_dataelement_id,
            qm.dhis2_attribute_id,
            qm.dhis2_option_set_id,
            COUNT(DISTINCT sq.survey_id) as usage_count
        FROM question q
        LEFT JOIN question_dhis2_mapping qm ON q.id = qm.question_id
        LEFT JOIN survey_question sq ON q.id = sq.question_id
        WHERE $where_clause
        GROUP BY q.id, q.label, q.question_type, q.is_required, q.options, q.created,
                 qm.dhis2_dataelement_id, qm.dhis2_attribute_id, qm.dhis2_option_set_id
        ORDER BY q.created DESC
        LIMIT $per_page OFFSET $offset
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Parse options for questions that have them
    foreach ($questions as &$question) {
        if (!empty($question['options'])) {
            $question['parsed_options'] = json_decode($question['options'], true) ?: [];
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'questions' => $questions,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => ceil($total / $per_page)
            ]
        ]
    ]);

} elseif ($method === 'POST') {
    // Create new question from modal
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        exit();
    }

    $label = trim($input['label'] ?? '');
    $question_type = trim($input['question_type'] ?? '');
    $is_required = filter_var($input['is_required'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $options = $input['options'] ?? [];

    if (empty($label) || empty($question_type)) {
        http_response_code(400);
        echo json_encode(['error' => 'Label and question type are required']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO question (label, question_type, is_required, options, created, updated) 
            VALUES (?, ?, ?, ?, NOW(), NOW())
        ");
        
        $options_json = !empty($options) ? json_encode($options) : null;
        $stmt->execute([$label, $question_type, $is_required, $options_json]);
        
        $question_id = $pdo->lastInsertId();
        
        // Get the created question
        $stmt = $pdo->prepare("
            SELECT id, label, question_type, is_required, options, created 
            FROM question WHERE id = ?
        ");
        $stmt->execute([$question_id]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!empty($question['options'])) {
            $question['parsed_options'] = json_decode($question['options'], true) ?: [];
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'question' => $question,
                'message' => 'Question created successfully'
            ]
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>