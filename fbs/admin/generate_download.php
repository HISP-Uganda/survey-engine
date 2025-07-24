<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/../vendor/autoload.php';
// Mpdf is usually loaded by Composer's autoload.php,
// so explicitly requiring Mpdf.php might be redundant or incorrect depending on setup.
// If you encounter issues, try commenting out the line below.
// require_once __DIR__ . '/../vendor/mpdf/mpdf/src/Mpdf.php'; 

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
// Make sure Mpdf\Mpdf is correctly aliased or used if not auto-loaded
use Mpdf\Mpdf; // Add this line if Mpdf is not loading automatically

require 'connect.php'; // Path to your database connection file

// Get parameters from POST request
$surveyId = filter_var($_POST['survey_id'] ?? null, FILTER_VALIDATE_INT);
$sortBy = $_POST['sort'] ?? 'created_desc';
$format = strtolower($_POST['format'] ?? 'csv');
$startDate = $_POST['start_date'] ?? null; // <-- Correctly receive start_date
$endDate = $_POST['end_date'] ?? null;     // <-- Correctly receive end_date

// Log received parameters for debugging
error_log("Generate Download: Survey ID=" . $surveyId . ", Format=" . $format . ", SortBy=" . $sortBy . ", Start Date=" . ($startDate ?? 'N/A') . ", End Date=" . ($endDate ?? 'N/A'));

// Validate survey ID
if (!$surveyId) {
    die("Valid Survey ID is required.");
}

// Define sorting options (safe to use directly after validation)
$validSortOptions = [
    'created_asc' => 's.created ASC',
    'created_desc' => 's.created DESC',
    'uid_asc' => 's.uid ASC',
    'uid_desc' => 's.uid DESC',
];
$orderBy = $validSortOptions[$sortBy] ?? 's.created DESC'; // Default if sortBy is invalid

// 1. First check if survey exists
try {
    $survey = $pdo->prepare("SELECT name FROM survey WHERE id = :survey_id");
    $survey->execute(['survey_id' => $surveyId]);
    $surveyName = $survey->fetchColumn();

    if (!$surveyName) {
        die("Survey not found.");
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// 2. Fetch submissions for the specified survey and date range
try {
    $sql = "
        SELECT
            s.id,
            s.uid,
            s.age,
            s.sex,
            s.period,
            su.name AS service_unit_name,
            l.name AS location_name,
            o.name AS ownership_name,
            s.created
        FROM submission s
        LEFT JOIN service_unit su ON s.service_unit_id = su.id
        LEFT JOIN location l ON s.location_id = l.id
        LEFT JOIN owner o ON s.ownership_id = o.id
        WHERE s.survey_id = :survey_id
    ";

    $params = ['survey_id' => $surveyId]; // Initialize parameters array

    // Add date filtering condition if dates are provided and valid
    if (!empty($startDate) && !empty($endDate)) {
        // Basic date validation (YYYY-MM-DD format is expected from <input type="date">)
        if (strtotime($startDate) !== false && strtotime($endDate) !== false) {
            $sql .= " AND s.created BETWEEN :start_date AND :end_date_inclusive";
            // Convert to full datetime range for inclusive filtering (from start of day to end of day)
            $params['start_date'] = $startDate . ' 00:00:00';
            $params['end_date_inclusive'] = $endDate . ' 23:59:59';
        } else {
            // Log if dates are malformed, but don't stop execution. Filter won't be applied.
            error_log("generate_download.php: Invalid date format received. Not applying date filter. Start: {$startDate}, End: {$endDate}");
        }
    }
    
    $sql .= " ORDER BY " . $orderBy; // Append ORDER BY clause

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params); // Execute with the fully prepared parameters
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    die("Database error fetching submissions: " . $e->getMessage());
}

// 3. Get questions for this survey (unchanged logic)
try {
    $stmt = $pdo->prepare("
        SELECT q.id, q.label
        FROM question q
        JOIN survey_question sq ON q.id = sq.question_id
        WHERE sq.survey_id = :survey_id
        ORDER BY sq.position ASC, q.id ASC
    ");
    $stmt->execute(['survey_id' => $surveyId]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    die("Database error fetching questions: " . $e->getMessage());
}

// Prepare export data (unchanged logic)
$exportData = [];

$headers = [
    'Submission ID', 'UID', 'Age', 'Sex', 'Period', 'Service Unit', 'Location', 'Ownership', 'Date Submitted'
];
foreach ($questions as $question) { $headers[] = $question['label']; }
$exportData[] = $headers;

foreach ($submissions as $submission) {
    // Get responses for this submission
    try {
        $stmt = $pdo->prepare("
            SELECT sr.question_id, sr.response_value FROM submission_response sr WHERE sr.submission_id = :submission_id
        ");
        $stmt->execute(['submission_id' => $submission['id']]);
        $responses = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        // Log error but continue processing other submissions if possible
        error_log("Database error fetching responses for submission ID {$submission['id']}: " . $e->getMessage());
        continue; // Skip this submission if responses can't be fetched
    }

    $groupedResponses = [];
    foreach ($responses as $response) { $groupedResponses[$response['question_id']][] = $response['response_value']; }

    $row = [
        $submission['id'], $submission['uid'], $submission['age'] ?? 'N/A', $submission['sex'] ?? 'N/A',
        $submission['period'] ?? 'N/A', $submission['service_unit_name'] ?? 'N/A',
        $submission['location_name'] ?? 'N/A', $submission['ownership_name'] ?? 'N/A',
        date('Y-m-d H:i:s', strtotime($submission['created']))
    ];
    foreach ($questions as $question) {
        $responseValues = $groupedResponses[$question['id']] ?? [];
        $row[] = !empty($responseValues) ? implode(', ', $responseValues) : 'No response';
    }
    $exportData[] = $row;
}

// Remove empty columns (unchanged logic)
$fieldsToCheck = ['Age', 'Sex', 'Period', 'Service Unit', 'Location', 'Ownership'];
$headerIndexesToRemove = [];
foreach ($fieldsToCheck as $field) {
    $headerIndex = array_search($field, $headers);
    if ($headerIndex !== false) {
        $allEmpty = true;
        for ($i = 1; $i < count($exportData); $i++) {
            $val = $exportData[$i][$headerIndex];
            if ($val !== '' && strtoupper($val) !== 'N/A') { $allEmpty = false; break; }
        }
        if ($allEmpty) { $headerIndexesToRemove[] = $headerIndex; }
    }
}
rsort($headerIndexesToRemove);
foreach ($headerIndexesToRemove as $idx) {
    foreach ($exportData as &$row) { array_splice($row, $idx, 1); }
    unset($row);
    array_splice($headers, $idx, 1);
}

// Prepare structured data for JSON and XML (unchanged logic)
$structuredData = [];
for ($i = 1; $i < count($exportData); $i++) {
    $entry = [];
    foreach ($headers as $index => $header) { $entry[$header] = $exportData[$i][$index] ?? ''; }
    $structuredData[] = $entry;
}

// Generate filename (unchanged logic)
$timestamp = date('Y-m-d_His');
$filename = "survey_data_{$surveyId}_{$timestamp}";

// Generate and output the file based on format (unchanged logic, except headers)
switch ($format) {
    case 'pdf':
        // PDF generation logic here...
        // Ensure mPDF is correctly configured and paths are fine
        // If 'Mpdf' class is not found, try require_once __DIR__ . '/../vendor/mpdf/mpdf/src/Mpdf.php';
        // or check your Composer autoload.
        $mpdf = new Mpdf([ // Use `new Mpdf` if `use Mpdf\Mpdf;` is added
            'orientation' => 'L', 'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 15, 'margin_bottom' => 15,
        ]);
        $mpdf->SetTitle("Survey Data - " . $surveyName);
        $mpdf->SetAuthor("Survey Admin");
        $html = '<!DOCTYPE html><html><head><style>body { font-family: Arial, sans-serif; font-size: 11pt; } h1 { font-size: 16pt; color: #333; margin-bottom: 10px; } .info { margin-bottom: 20px; font-size: 9pt; color: #666; } table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 9pt; } th { background-color: #f2f2f2; padding: 5px; text-align: left; font-weight: bold; border: 1px solid #ddd; } td { padding: 5px; border: 1px solid #ddd; vertical-align: top; }</style></head><body><h1>' . htmlspecialchars($surveyName) . ' - Survey Data</h1><div class="info">Generated on ' . date('F j, Y, g:i a') . '</div><table>';
        $html .= '<tr>'; foreach ($headers as $header) { $html .= '<th>' . htmlspecialchars($header) . '</th>'; } $html .= '</tr>';
        for ($i = 1; $i < count($exportData); $i++) { $html .= '<tr>'; foreach ($exportData[$i] as $value) { $html .= '<td>' . htmlspecialchars($value) . '</td>'; } $html .= '</tr>'; }
        $html .= '</table></body></html>';
        $mpdf->WriteHTML($html);
        $mpdf->Output($filename . '.pdf', 'D');
        break;

    case 'excel':
        $spreadsheet = new Spreadsheet(); $sheet = $spreadsheet->getActiveSheet();
        $spreadsheet->getProperties()->setTitle("Survey Data - " . $surveyName)->setSubject("Survey Responses")->setCreator("Survey Admin");
        foreach ($exportData as $rowIndex => $dataRow) {
            foreach ($dataRow as $columnIndex => $value) { $sheet->setCellValueByColumnAndRow($columnIndex + 1, $rowIndex + 1, $value); }
        }
        $columnIndex = 1; foreach ($headers as $header) { $sheet->getColumnDimensionByColumn($columnIndex)->setAutoSize(true); $columnIndex++; }
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFCCCCCC');
        $writer = new Xlsx($spreadsheet);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '.xlsx"');
        header('Cache-Control: max-age=0');
        $writer->save('php://output');
        break;

    case 'json':
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '.json"');
        echo json_encode(['survey_name' => $surveyName, 'generated_at' => date('Y-m-d H:i:s'), 'submissions' => $structuredData], JSON_PRETTY_PRINT);
        break;

    case 'xml':
        header('Content-Type: application/xml');
        header('Content-Disposition: attachment; filename="' . $filename . '.xml"');
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><survey_data></survey_data>');
        $xml->addChild('survey_name', htmlspecialchars($surveyName));
        $xml->addChild('generated_at', date('Y-m-d H:i:s'));
        $submissions = $xml->addChild('submissions');
        foreach ($structuredData as $submissionData) {
            $submission = $submissions->addChild('submission');
            foreach ($submissionData as $key => $value) {
                $elementName = str_replace(' ', '_', str_replace(['(', ')', '?', '&', "'", '"'], '', $key));
                $submission->addChild($elementName, htmlspecialchars($value));
            }
        }
        echo $xml->asXML();
        break;

    case 'html':
        header('Content-Type: text/html');
        header('Content-Disposition: inline; filename="' . $filename . '.html"');
        echo '<!DOCTYPE html><html><head><title>' . htmlspecialchars($surveyName) . ' - Survey Data</title><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><style>body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; } h1 { color: #2c3e50; margin-bottom: 10px; } .info { margin-bottom: 20px; color: #7f8c8d; } table { width: 100%; border-collapse: collapse; margin-top: 20px; } th { background-color: #3498db; color: white; padding: 10px; text-align: left; } td { padding: 8px; border-bottom: 1px solid #ddd; } tr:hover { background-color: #f5f5f5; } .container { max-width: 1200px; margin: 0 auto; } .download-note { margin-top: 30px; font-style: italic; color: #7f8c8d; }</style></head><body><div class="container"><h1>' . htmlspecialchars($surveyName) . ' - Survey Data</h1><div class="info">Generated on ' . date('F j, Y, g:i a') . '</div><table><thead><tr>';
        foreach ($headers as $header) { echo '<th>' . htmlspecialchars($header) . '</th>'; }
        echo '</tr></thead><tbody>';
        for ($i = 1; $i < count($exportData); $i++) { echo '<tr>'; foreach ($exportData[$i] as $value) { echo '<td>' . htmlspecialchars($value) . '</td>'; } echo '</tr>'; }
        echo '</tbody></table><div class="download-note">Note: You can use the download button to save this data in other formats.</div></div></body></html>';
        break;

    case 'csv':
    default:
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $output = fopen('php://output', 'w');
        foreach ($exportData as $dataRow) { fputcsv($output, $dataRow); }
        fclose($output);
        break;
}

exit();