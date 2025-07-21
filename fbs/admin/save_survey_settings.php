<?php
ob_start();
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

$servername = "localhost";
$username = "root";
$password = "root";
$dbname = "fbtv3";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    http_response_code(500);
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $conn->connect_error]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Use 405 Method Not Allowed for incorrect method
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
    exit();
}

$input = file_get_contents('php://input');
$settings = json_decode($input, true);

// Validate JSON decoding
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input: ' . json_last_error_msg()]);
    exit();
}

$surveyId = $settings['surveyId'] ?? null;
if (!$surveyId || !is_numeric($surveyId)) {
    http_response_code(400);
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid Survey ID. A numeric Survey ID is required.']);
    exit();
}

// Fetch survey name for default title handling
$surveyName = '';
$surveyNameStmt = $conn->prepare("SELECT name FROM survey WHERE id = ?");
if ($surveyNameStmt) {
    $surveyNameStmt->bind_param("i", $surveyId);
    $surveyNameStmt->execute();
    $surveyNameResult = $surveyNameStmt->get_result();
    if ($row = $surveyNameResult->fetch_assoc()) {
        $surveyName = $row['name'];
    }
    $surveyNameStmt->close();
}

$uploadDir = __DIR__ . '/uploads/survey_logos/';
$uploadWebPath = 'uploads/survey_logos/';

// Ensure upload directory exists and is writable
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0775, true)) {
        http_response_code(500);
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Failed to create upload directory. Check server permissions.']);
        exit();
    }
}
if (!is_writable($uploadDir)) {
    http_response_code(500);
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Upload directory is not writable. Please check permissions for: ' . $uploadDir]);
    exit();
}

$logoPath = $settings['logoSrc'] ?? 'asets/asets/img/loog.jpg'; // Default logo path

// Handle base64 encoded image upload
if (isset($settings['logoSrc']) && strpos($settings['logoSrc'], 'data:image/') === 0) {
    @list($type, $data) = explode(';', $settings['logoSrc']);
    @list(, $data) = explode(',', $data);
    $data = base64_decode($data);

    $mimeType = explode(':', $type)[1] ?? '';
    $extension = '';

    switch ($mimeType) {
        case 'image/png':
            $extension = 'png';
            break;
        case 'image/jpeg':
            $extension = 'jpg';
            break;
        case 'image/gif':
            $extension = 'gif';
            break;
        default:
            http_response_code(400);
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Unsupported image format provided. Only PNG, JPEG, and GIF are allowed.']);
            exit();
    }

    $fileName = 'survey_' . $surveyId . '_' . time() . '_' . uniqid() . '.' . $extension;
    $filePath = $uploadDir . $fileName;
    $webPath = $uploadWebPath . $fileName;

    if (file_put_contents($filePath, $data)) {
        $logoPath = $webPath;
    } else {
        http_response_code(500);
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Failed to save uploaded image file to the server.']);
        exit();
    }
}

// Assign other values from the received settings, providing sensible defaults
$showLogo = (int)($settings['showLogo'] ?? 1); // Default to true
$flagBlackColor = $settings['flagBlackColor'] ?? '#000000';
$flagYellowColor = $settings['flagYellowColor'] ?? '#FCD116';
$flagRedColor = $settings['flagRedColor'] ?? '#D21034';
$showFlagBar = (int)($settings['showFlagBar'] ?? 1); // Default to true

$titleText = trim($settings['titleText'] ?? '');
if (empty($titleText)) {
    $titleText = $surveyName; // Use survey name if title is empty
}
$showTitle = (int)($settings['showTitle'] ?? 1); // Default to true

$subheadingText = $settings['subheadingText'] ?? 'This tool is used to obtain clients\' feedback about their experience with the services and promote quality improvement, accountability, and transparency within the healthcare system.';
$showSubheading = (int)($settings['showSubheading'] ?? 1); // Default to true
$showSubmitButton = (int)($settings['showSubmitButton'] ?? 1); // Default to true

$ratingInstruction1Text = $settings['ratingInstruction1Text'] ?? '1. Please rate each of the following parameters according to your experience today on a scale of 1 to 4.';
$ratingInstruction2Text = $settings['ratingInstruction2Text'] ?? 'where \'0\' means Poor, \'1\' Fair, \'2\' Good and \'3\' Excellent';
$showRatingInstructions = (int)($settings['showRatingInstructions'] ?? 1); // Default to true

$showFacilitySection = (int)($settings['showFacilitySection'] ?? 1); // Default to true
$showLocationRowGeneral = (int)($settings['showLocationRowGeneral'] ?? 1); // Default to true
$showLocationRowPeriodAge = (int)($settings['showLocationRowPeriodAge'] ?? 1); // Default to true
$showOwnershipSection = (int)($settings['showOwnershipSection'] ?? 1); // Default to true

$republicTitleText = $settings['republicTitleText'] ?? 'THE REPUBLIC OF UGANDA';
$showRepublicTitleShare = (int)($settings['showRepublicTitleShare'] ?? 1); // Default to true
$ministrySubtitleText = $settings['ministrySubtitleText'] ?? 'MINISTRY OF HEALTH';
$showMinistrySubtitleShare = (int)($settings['showMinistrySubtitleShare'] ?? 1); // Default to true
$qrInstructionsText = $settings['qrInstructionsText'] ?? 'Scan this QR Code to Give Your Feedback on Services Received';
$showQrInstructionsShare = (int)($settings['showQrInstructionsShare'] ?? 1); // Default to true
$footerNoteText = $settings['footerNoteText'] ?? 'Thank you for helping us improve our services.';
$showFooterNoteShare = (int)($settings['showFooterNoteShare'] ?? 1); // Default to true

// Prepare UPDATE statement
// Count the number of ? placeholders in the SQL query:
// There are 26 columns being updated and 1 WHERE clause condition. Total 27 parameters.
$stmt = $conn->prepare("
    UPDATE survey_settings SET
        logo_path = ?,
        show_logo = ?,
        flag_black_color = ?,
        flag_yellow_color = ?,
        flag_red_color = ?,
        show_flag_bar = ?,
        title_text = ?,
        show_title = ?,
        subheading_text = ?,
        show_subheading = ?,
        show_submit_button = ?,
        rating_instruction1_text = ?,
        rating_instruction2_text = ?,
        show_rating_instructions = ?,
        show_facility_section = ?,
        show_location_row_general = ?,
        show_location_row_period_age = ?,
        show_ownership_section = ?,
        republic_title_text = ?,
        show_republic_title_share = ?,
        ministry_subtitle_text = ?,
        show_ministry_subtitle_share = ?,
        qr_instructions_text = ?,
        show_qr_instructions_share = ?,
        footer_note_text = ?,
        show_footer_note_share = ?
    WHERE survey_id = ?
");

if (!$stmt) {
    error_log('Failed to prepare statement: ' . $conn->error);
    http_response_code(500);
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Internal server error: Failed to prepare database statement.']);
    exit();
}

// 26 fields to update + 1 WHERE clause = 27 parameters
// s = string, i = integer. The type string must have 27 characters.
$type_string = "sisssisisiissiiiiisisiisiii"; // Corrected to 27 characters

$params = [
    $logoPath, (int)$showLogo, // Ensure int casting for boolean-like values
    $flagBlackColor, $flagYellowColor, $flagRedColor, (int)$showFlagBar,
    $titleText, (int)$showTitle,
    $subheadingText, (int)$showSubheading,
    (int)$showSubmitButton,
    $ratingInstruction1Text, $ratingInstruction2Text, (int)$showRatingInstructions,
    (int)$showFacilitySection, (int)$showLocationRowGeneral, (int)$showLocationRowPeriodAge, (int)$showOwnershipSection,
    $republicTitleText, (int)$showRepublicTitleShare,
    $ministrySubtitleText, (int)$showMinistrySubtitleShare,
    $qrInstructionsText, (int)$showQrInstructionsShare,
    $footerNoteText, (int)$showFooterNoteShare,
    (int)$surveyId // Ensure surveyId is an integer
];

// Double-check: Parameter count must exactly match the type string length
if (strlen($type_string) !== count($params)) {
    error_log("Critical Error: Type string length (" . strlen($type_string) . ") does not match parameter count (" . count($params) . ") for bind_param. This indicates a developer mistake.");
    http_response_code(500);
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error: Configuration mismatch. Please contact support.'
    ]);
    exit();
}

// Use call_user_func_array to bind parameters as bind_param does not accept an array directly
// The '...' operator in PHP 5.6+ can unpack the array for bind_param, which is cleaner.
$stmt->bind_param($type_string, ...$params);

if ($stmt->execute()) {
    ob_clean();
    echo json_encode(['success' => true, 'message' => 'Survey settings saved successfully.']);
} else {
    error_log('Failed to execute statement: ' . $stmt->error);
    http_response_code(500);
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Failed to save survey settings due to a database error: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>