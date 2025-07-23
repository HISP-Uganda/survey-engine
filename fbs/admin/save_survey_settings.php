<?php
ob_start(); // Start output buffering
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include the centralized database connection file
// Since it's in the same directory, a direct filename is sufficient.
require_once 'connect.php';

// Check if the PDO object is available from connect.php
if (!isset($pdo)) {
    http_response_code(500);
    ob_clean(); // Clean any previous output before sending JSON
    echo json_encode(['success' => false, 'message' => 'Database connection failed: Central PDO object not found.']);
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
try {
    $surveyNameStmt = $pdo->prepare("SELECT name FROM survey WHERE id = ?");
    $surveyNameStmt->execute([$surveyId]);
    $row = $surveyNameStmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $surveyName = $row['name'];
    }
} catch (PDOException $e) {
    error_log("Database error fetching survey name: " . $e->getMessage());
    // Continue with empty surveyName, default title will be used
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
// PDO handles type binding automatically, no explicit type string is needed
$sql = "
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
";

try {
    $stmt = $pdo->prepare($sql);

    // No type string needed for PDO bindValue/execute
    $params = [
        $logoPath, $showLogo,
        $flagBlackColor, $flagYellowColor, $flagRedColor, $showFlagBar,
        $titleText, $showTitle,
        $subheadingText, $showSubheading,
        $showSubmitButton,
        $ratingInstruction1Text, $ratingInstruction2Text, $showRatingInstructions,
        $showFacilitySection, $showLocationRowGeneral, $showLocationRowPeriodAge, $showOwnershipSection,
        $republicTitleText, $showRepublicTitleShare,
        $ministrySubtitleText, $showMinistrySubtitleShare,
        $qrInstructionsText, $showQrInstructionsShare,
        $footerNoteText, $showFooterNoteShare,
        (int)$surveyId // Ensure surveyId is correctly cast for the WHERE clause
    ];

    if ($stmt->execute($params)) {
        ob_clean(); // Ensure no prior output before JSON
        echo json_encode(['success' => true, 'message' => 'Survey settings saved successfully.']);
    } else {
        // Log the PDO error information
        error_log('Failed to execute statement: ' . json_encode($stmt->errorInfo()));
        http_response_code(500);
        ob_clean(); // Ensure no prior output before JSON
        echo json_encode(['success' => false, 'message' => 'Failed to save survey settings due to a database error.']);
    }

} catch (PDOException $e) {
    error_log('Database error during settings update: ' . $e->getMessage());
    http_response_code(500);
    ob_clean(); // Ensure no prior output before JSON
    echo json_encode(['success' => false, 'message' => 'Internal server error during settings update.']);
}

// PDO connections automatically close when the script finishes.
// No explicit $stmt->close() or $pdo->close() needed here.

exit(); // Ensure no further code is executed after JSON response
?>