<?php
// get_submissions.php
// Enhanced: Supports JSON output and date filtering, and acts as an API entry point

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Include the centralized database connection file
// Assuming this file is in /api/ and connect.php is in /admin/
require_once __DIR__ . '/../admin/connect.php'; // Adjust path if connect.php is elsewhere

// Check if the centralized PDO object is available
if (!isset($pdo)) {
    http_response_code(500);
    echo "Database connection failed: Central PDO object not found.";
    exit;
}

// Authentication check (adjust as needed)
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo "Unauthorized";
    exit;
}

// Helper: Parse date or return null
function parse_date($date_str) {
    $d = DateTime::createFromFormat('Y-m-d', $date_str);
    return $d ? $d->format('Y-m-d') : null;
}

// --- API Endpoints ---

// API for all surveys
if (isset($_GET['api']) && $_GET['api'] === 'surveys') {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("SELECT * FROM survey ORDER BY created DESC");
        $surveys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'success' => true,
            'count' => count($surveys),
            'surveys' => $surveys
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error fetching surveys: ' . $e->getMessage()]);
        error_log("API Error (surveys): " . $e->getMessage()); // Log error
    }
    exit;
}

// API for all submission_responses
// Now includes question details
if (isset($_GET['api']) && $_GET['api'] === 'submission_responses') {
    header('Content-Type: application/json');
    try {
        $params = [];
        $sql = "SELECT sr.*, s.uid as submission_uid, s.survey_id, q.label as question_label, q.question_type
                FROM submission_response sr
                JOIN submission s ON sr.submission_id = s.id
                JOIN question q ON sr.question_id = q.id
                WHERE 1=1";

        // Optional filtering by submission_id
        if (isset($_GET['submission_id'])) {
            $submission_id = intval($_GET['submission_id']);
            $sql .= " AND sr.submission_id = :submission_id";
            $params[':submission_id'] = $submission_id;
        }

        // Optional filtering by survey_id
        if (isset($_GET['survey_id'])) {
            $survey_id = intval($_GET['survey_id']);
            $sql .= " AND s.survey_id = :survey_id";
            $params[':survey_id'] = $survey_id;
        }

        $sql .= " ORDER BY sr.created DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $submission_responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'success' => true,
            'count' => count($submission_responses),
            'submission_responses' => $submission_responses
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error fetching submission responses: ' . $e->getMessage()]);
        error_log("API Error (submission_responses): " . $e->getMessage()); // Log error
    }
    exit;
}

// API for all submissions (with optional filters)
if (isset($_GET['api']) && $_GET['api'] === 'submissions_all') {
    header('Content-Type: application/json');
    try {
        // Get parameters for filtering
        $survey_id_filter = isset($_GET['survey_id']) ? intval($_GET['survey_id']) : null;
        $start_date_filter = isset($_GET['start_date']) ? parse_date($_GET['start_date']) : null;
        $end_date_filter = isset($_GET['end_date']) ? parse_date($_GET['end_date']) : null;

        $params = [];
        $sql = "SELECT s.*, sy.name as survey_name FROM submission s JOIN survey sy ON s.survey_id = sy.id WHERE 1=1";
        if ($survey_id_filter) {
            $sql .= " AND s.survey_id = :survey_id";
            $params[':survey_id'] = $survey_id_filter;
        }
        if ($start_date_filter) {
            $sql .= " AND DATE(s.created) >= :start_date";
            $params[':start_date'] = $start_date_filter;
        }
        if ($end_date_filter) {
            $sql .= " AND DATE(s.created) <= :end_date";
            $params[':end_date'] = $end_date_filter;
        }
        $sql .= " ORDER BY s.created DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'count' => count($submissions),
            'submissions' => $submissions
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error fetching all submissions: ' . $e->getMessage()]);
        error_log("API Error (submissions_all): " . $e->getMessage()); // Log error
    }
    exit;
}

// API for all questions
if (isset($_GET['api']) && $_GET['api'] === 'questions') {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("SELECT * FROM question ORDER BY id ASC");
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'success' => true,
            'count' => count($questions),
            'questions' => $questions
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error fetching questions: ' . $e->getMessage()]);
        error_log("API Error (questions): " . $e->getMessage()); // Log error
    }
    exit;
}

// --- New API: Get submissions with their questions and responses ---
if (isset($_GET['api']) && $_GET['api'] === 'submissions_with_responses') {
    header('Content-Type: application/json');
    try {
        // Optional filters
        $survey_id_filter = isset($_GET['survey_id']) ? intval($_GET['survey_id']) : null;
        $start_date_filter = isset($_GET['start_date']) ? parse_date($_GET['start_date']) : null;
        $end_date_filter = isset($_GET['end_date']) ? parse_date($_GET['end_date']) : null;

        $params = [];
        $sql = "SELECT s.*, sy.name as survey_name FROM submission s JOIN survey sy ON s.survey_id = sy.id WHERE 1=1";
        if ($survey_id_filter) {
            $sql .= " AND s.survey_id = :survey_id";
            $params[':survey_id'] = $survey_id_filter;
        }
        if ($start_date_filter) {
            $sql .= " AND DATE(s.created) >= :start_date";
            $params[':start_date'] = $start_date_filter;
        }
        if ($end_date_filter) {
            $sql .= " AND DATE(s.created) <= :end_date";
            $params[':end_date'] = $end_date_filter;
        }
        $sql .= " ORDER BY s.created DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // For each submission, fetch its responses (with question details)
        foreach ($submissions as &$submission) {
            $submission_id = $submission['id'];
            $qsql = "SELECT q.id as question_id, q.label as question_label, q.question_type, sr.response_value
                     FROM submission_response sr
                     JOIN question q ON sr.question_id = q.id
                     WHERE sr.submission_id = :submission_id
                     ORDER BY q.id ASC";
            $qstmt = $pdo->prepare($qsql);
            $qstmt->execute([':submission_id' => $submission_id]);
            $submission['responses'] = $qstmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode([
            'success' => true,
            'count' => count($submissions),
            'submissions' => $submissions
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error fetching submissions with responses: ' . $e->getMessage()]);
        error_log("API Error (submissions_with_responses): " . $e->getMessage()); // Log error
    }
    exit;
}


// --- Original get_submissions.php functionality (HTML/JSON for specific survey submissions) ---

// Get parameters
$survey_id = isset($_GET['survey_id']) ? intval($_GET['survey_id']) : null;
$start_date = isset($_GET['start_date']) ? parse_date($_GET['start_date']) : null;
$end_date = isset($_GET['end_date']) ? parse_date($_GET['end_date']) : null;
$format = isset($_GET['format']) ? strtolower($_GET['format']) : 'html';

// Build SQL query for submissions based on survey_id
$params = [];
$sql = "SELECT * FROM submission WHERE 1=1";
if ($survey_id) {
    $sql .= " AND survey_id = :survey_id";
    $params[':survey_id'] = $survey_id;
}
if ($start_date) {
    $sql .= " AND DATE(created) >= :start_date";
    $params[':start_date'] = $start_date;
}
if ($end_date) {
    $sql .= " AND DATE(created) <= :end_date";
    $params[':end_date'] = $end_date;
}
$sql .= " ORDER BY created DESC";

// Fetch data
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Output as JSON if requested (for this specific survey_id focused query)
    if ($format === 'json') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'count' => count($submissions),
            'submissions' => $submissions
        ]);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo "Error fetching submissions: " . $e->getMessage();
    error_log("General Submissions Fetch Error: " . $e->getMessage()); // Log error
    exit;
}

// Otherwise, if not 'json' and not exited, it means format is 'html' or default.
// The HTML output would typically follow here.
// For example:
?>
<!DOCTYPE html>
<html>
<head>
    <title>Survey Submissions</title>
    <style>
        body { font-family: sans-serif; margin: 20px; }
        h1, h2 { color: #333; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
        form { margin-bottom: 20px; padding: 15px; border: 1px solid #eee; background: #f9f9f9; }
        label { margin-right: 15px; }
        input[type="number"], input[type="date"] { padding: 5px; border: 1px solid #ddd; }
        button { padding: 8px 15px; background-color: #007bff; color: white; border: none; cursor: pointer; }
        button:hover { background-color: #0056b3; }
        a { color: #007bff; text-decoration: none; margin-left: 10px; }
        a:hover { text-decoration: underline; }
        pre { white-space: pre-wrap; word-wrap: break-word; font-size: 0.9em; background-color: #f5f5f5; padding: 5px; border: 1px solid #eee; }
        .api-info { margin-top: 40px; border-top: 2px solid #eee; padding-top: 20px; }
        .api-info h2 { margin-bottom: 15px; }
        .api-info p { margin-bottom: 5px; }
        .api-info code { background-color: #e9e9e9; padding: 2px 4px; border-radius: 3px; }
    </style>
</head>
<body>
  

    <div class="api-info">
        <h2>API Endpoints Explained</h2>
        <p>This `get_submissions.php` file also serves as an API gateway for various datasets. You can access these JSON APIs directly:</p>

        <h3>1. Get All Surveys</h3>
        <p>Returns a JSON array of all surveys in the `survey` table.</p>
        <p><strong>Endpoint:</strong> <a href="?api=surveys" target="_blank"><code>/get_submissions.php?api=surveys</code></a></p>
        <p><strong>Example Output:</strong></p>
        <pre><code>{
  "success": true,
  "count": 2,
  "surveys": [
    {
      "id": "1",
      "name": "Health Facility Survey Q1 2024",
      "created": "2024-01-15 10:00:00",
      "updated": "2024-01-15 10:00:00",
      "type": "local",
      "start_date": "2024-01-01",
      "end_date": "2024-03-31",
      "is_active": "1",
      "dhis2_instance": null,
      "program_dataset": null
    },
    {
      "id": "2",
      "name": "Community Health Survey Pilot",
      "created": "2024-02-01 09:30:00",
      "updated": "2024-02-01 09:30:00",
      "type": "dhis2",
      "start_date": "2024-02-01",
      "end_date": "2024-02-29",
      "is_active": "1",
      "dhis2_instance": "prod_dhis2",
      "program_dataset": "AbCdEfGhIjK"
    }
  ]
}</code></pre>

        <h3>2. Get All Submissions (Summary)</h3>
        <p>Returns a JSON array of all submissions in the `submission` table, with optional filtering. This endpoint provides high-level submission data without individual question responses.</p>
        <p><strong>Endpoint:</strong> <a href="?api=submissions_all" target="_blank"><code>/get_submissions.php?api=submissions_all</code></a></p>
        <p><strong>Optional Parameters:</strong></p>
        <ul>
            <li><code>survey_id</code>: Filter submissions by a specific survey ID. <br>Example: <a href="?api=submissions_all&survey_id=1" target="_blank"><code>/get_submissions.php?api=submissions_all&survey_id=1</code></a></li>
            <li><code>start_date</code>: Filter submissions created on or after this date (YYYY-MM-DD). <br>Example: <a href="?api=submissions_all&start_date=2024-01-01" target="_blank"><code>/get_submissions.php?api=submissions_all&start_date=2024-01-01</code></a></li>
            <li><code>end_date</code>: Filter submissions created on or before this date (YYYY-MM-DD). <br>Example: <a href="?api=submissions_all&end_date=2024-03-31" target="_blank"><code>/get_submissions.php?api=submissions_all&end_date=2024-03-31</code></a></li>
            <li>Combine parameters: <a href="?api=submissions_all&survey_id=1&start_date=2024-01-01&end_date=2024-03-31" target="_blank"><code>/get_submissions.php?api=submissions_all&survey_id=1&start_date=2024-01-01&end_date=2024-03-31</code></a></li>
        </ul>
        <p><strong>Example Output Structure:</strong></p>
        <pre><code>{
  "success": true,
  "count": 5,
  "submissions": [
    {
      "id": "101",
      "uid": "abc123def45",
      "age": "30",
      "sex": "Male",
      "period": "2024Q1",
      "service_unit_id": "5",
      "location_id": "1001",
      "ownership_id": "1",
      "survey_id": "1",
      "created": "2024-01-20 11:00:00",
      "updated": "2024-01-20 11:00:00",
      "survey_name": "Health Facility Survey Q1 2024"
    }
  ]
}</code></pre>

        <h3>3. Get All Individual Submission Responses (Flat List)</h3>
        <p>Returns a JSON array of all individual responses in the `submission_response` table, now including the associated question's label and type from the `question` table. This is useful for getting a flat list of all answers across all submissions or a specific submission/survey.</p>
        <p><strong>Endpoint:</strong> <a href="?api=submission_responses" target="_blank"><code>/get_submissions.php?api=submission_responses</code></a></p>
        <p><strong>Optional Parameters:</strong></p>
        <ul>
            <li><code>submission_id</code>: Filter responses by a specific submission ID. <br>Example: <a href="?api=submission_responses&submission_id=101" target="_blank"><code>/get_submissions.php?api=submission_responses&submission_id=101</code></a></li>
            <li><code>survey_id</code>: Filter responses by the `survey_id` of the submission they belong to. <br>Example: <a href="?api=submission_responses&survey_id=1" target="_blank"><code>/get_submissions.php?api=submission_responses&survey_id=1</code></a></li>
        </ul>
        <p><strong>Example Output Structure:</strong></p>
        <pre><code>{
  "success": true,
  "count": 10,
  "submission_responses": [
    {
      "id": "1",
      "submission_id": "101",
      "question_id": "501",
      "response_value": "Yes",
      "created": "2024-01-20 11:05:00",
      "updated": "2024-01-20 11:05:00",
      "submission_uid": "abc123def45",
      "survey_id": "1",
      "question_label": "Is the facility functional?",
      "question_type": "radio"
    },
    {
      "id": "2",
      "submission_id": "101",
      "question_id": "502",
      "response_value": "35",
      "created": "2024-01-20 11:05:10",
      "updated": "2024-01-20 11:05:10",
      "submission_uid": "abc123def45",
      "survey_id": "1",
      "question_label": "Number of patients seen today?",
      "question_type": "text"
    }
  ]
}</code></pre>

        <h3>4. Get All Questions</h3>
        <p>Returns a JSON array of all questions in the `question` table.</p>
        <p><strong>Endpoint:</strong> <a href="?api=questions" target="_blank"><code>/get_submissions.php?api=questions</code></a></p>
        <p><strong>Example Output:</strong></p>
        <pre><code>{
  "success": true,
  "count": 3,
  "questions": [
    {
      "id": "501",
      "label": "Is the facility functional?",
      "question_type": "radio",
      "is_required": "1",
      "translations": null,
      "option_set_id": "1",
      "created": "2024-01-10 08:00:00",
      "updated": "2024-01-10 08:00:00"
    },
    {
      "id": "502",
      "label": "Number of patients seen today?",
      "question_type": "text",
      "is_required": "1",
      "translations": null,
      "option_set_id": null,
      "created": "2024-01-10 08:01:00",
      "updated": "2024-01-10 08:01:00"
    }
  ]
}</code></pre>

        <h3>5. Get Submissions with Nested Questions and Responses</h3>
        <p>This API endpoint is ideal for getting a comprehensive view of each submission, where all associated **questions and their respective responses are nested directly within each submission object**. This provides a complete survey record in a single JSON structure.</p>
        <p><strong>Endpoint:</strong> <a href="?api=submissions_with_responses" target="_blank"><code>/get_submissions.php?api=submissions_with_responses</code></a></p>
        <p><strong>Optional Parameters:</strong></p>
        <ul>
            <li><code>survey_id</code>: Filter submissions (and their nested responses) by a specific survey ID. <br>Example: <a href="?api=submissions_with_responses&survey_id=1" target="_blank"><code>/get_submissions.php?api=submissions_with_responses&survey_id=1</code></a></li>
            <li><code>start_date</code>: Filter submissions created on or after this date (YYYY-MM-DD). <br>Example: <a href="?api=submissions_with_responses&start_date=2024-01-01" target="_blank"><code>/get_submissions.php?api=submissions_with_responses&start_date=2024-01-01</code></a></li>
            <li><code>end_date</code>: Filter submissions created on or before this date (YYYY-MM-DD). <br>Example: <a href="?api=submissions_with_responses&end_date=2024-03-31" target="_blank"><code>/get_submissions.php?api=submissions_with_responses&end_date=2024-03-31</code></a></li>
            <li>Combine parameters: <a href="?api=submissions_with_responses&survey_id=1&start_date=2024-01-01&end_date=2024-03-31" target="_blank"><code>/get_submissions.php?api=submissions_with_responses&survey_id=1&start_date=2024-01-01&end_date=2024-03-31</code></a></li>
        </ul>
        <p><strong>Example Output Structure:</strong></p>
        <pre><code>{
  "success": true,
  "count": 1,
  "submissions": [
    {
      "id": "101",
      "uid": "abc123def45",
      "age": "30",
      "sex": "Male",
      "period": "2024Q1",
      "service_unit_id": "5",
      "location_id": "1001",
      "ownership_id": "1",
      "survey_id": "1",
      "created": "2024-01-20 11:00:00",
      "updated": "2024-01-20 11:00:00",
      "survey_name": "Health Facility Survey Q1 2024",
      "responses": [
        {
          "question_id": "501",
          "question_label": "Is the facility functional?",
          "question_type": "radio",
          "response_value": "Yes"
        },
        {
          "question_id": "502",
          "question_label": "Number of patients seen today?",
          "question_type": "text",
          "response_value": "35"
        },
        {
          "question_id": "503",
          "question_label": "Comments on facility condition:",
          "question_type": "textarea",
          "response_value": "Needs minor repairs in the waiting area."
        }
      ]
    }
  ]
}</code></pre>
    </div>
</body>
</html>