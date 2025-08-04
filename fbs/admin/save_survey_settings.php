<?php
// save_survey_settings.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

require_once 'connect.php'; // Include your database connection

header('Content-Type: application/json');

// Log incoming request for debugging
error_log("save_survey_settings.php called");
error_log("Request method: " . $_SERVER['REQUEST_METHOD']);
error_log("Raw input: " . file_get_contents('php://input'));

if (!isset($pdo)) {
    error_log("PDO object not found");
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit;
}

// Get the raw POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("JSON decode error: " . json_last_error_msg());
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input: ' . json_last_error_msg()]);
    exit;
}

error_log("Decoded data: " . print_r($data, true));

$surveyId = $data['surveyId'] ?? null;

if (!$surveyId) {
    error_log("Survey ID missing from request");
    echo json_encode(['status' => 'error', 'message' => 'Survey ID is missing.']);
    exit;
}

error_log("Processing survey ID: " . $surveyId);

// Extract other settings from $data
$logoPath = $data['logoSrc'] ?? null; // Can be base64 data URL or existing path
$showLogo = $data['showLogo'] ?? false;
$flagBlackColor = $data['flagBlackColor'] ?? '#000000';
$flagYellowColor = $data['flagYellowColor'] ?? '#FCD116';
$flagRedColor = $data['flagRedColor'] ?? '#D21034';
$showFlagBar = $data['showFlagBar'] ?? false;
$titleText = $data['titleText'] ?? '';
$showTitle = $data['showTitle'] ?? false;
$subheadingText = $data['subheadingText'] ?? '';
$showSubheading = $data['showSubheading'] ?? false;
$showSubmitButton = $data['showSubmitButton'] ?? false;
$ratingInstruction1Text = $data['ratingInstruction1Text'] ?? '';
$ratingInstruction2Text = $data['ratingInstruction2Text'] ?? '';
$showRatingInstructions = $data['showRatingInstructions'] ?? false;
$showFacilitySection = $data['showFacilitySection'] ?? false;
$republicTitleText = $data['republicTitleText'] ?? '';
$showRepublicTitleShare = $data['showRepublicTitleShare'] ?? false;
$ministrySubtitleText = $data['ministrySubtitleText'] ?? '';
$showMinistrySubtitleShare = $data['showMinistrySubtitleShare'] ?? false;
$qrInstructionsText = $data['qrInstructionsText'] ?? '';
$showQrInstructionsShare = $data['showQrInstructionsShare'] ?? false;
$footerNoteText = $data['footerNoteText'] ?? '';
$showFooterNoteShare = $data['showFooterNoteShare'] ?? false;

// NEW: Get the selected instance key and hierarchy level
$selectedInstanceKey = $data['selectedInstanceKey'] ?? null;
$selectedHierarchyLevel = $data['selectedHierarchyLevel'] ?? null;

// Question numbering settings
$showNumbering = $data['showNumbering'] ?? true;
$numberingStyle = $data['numberingStyle'] ?? 'numeric';

// Handle logo upload if it's a new base64 image
if (strpos($logoPath, 'data:image/') === 0) {
    $base64Image = explode(',', $logoPath)[1];
    $imageType = explode(';', explode(':', $logoPath)[1])[0]; // e.g., image/jpeg
    $extension = explode('/', $imageType)[1]; // e.g., jpeg
    $uploadDir = 'asets/asets/img/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    $fileName = 'logo_' . uniqid() . '.' . $extension;
    $filePath = $uploadDir . $fileName;
    if (file_put_contents($filePath, base64_decode($base64Image))) {
        $logoPath = $filePath; // Update logoPath to the saved file path
    } else {
        error_log("Failed to save uploaded logo.");
        $logoPath = 'asets/asets/img/loog.jpg'; // Fallback to default
    }
}

try {
    error_log("Starting database operations");
    
    // Check if settings exist for this survey
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM survey_settings WHERE survey_id = ?");
    $checkStmt->execute([$surveyId]);
    $exists = $checkStmt->fetchColumn();
    
    error_log("Settings exist for survey $surveyId: " . ($exists ? 'YES' : 'NO'));

    if ($exists) {
        // Update existing settings
        error_log("Updating existing settings");
        
        $updateParams = [
            $logoPath, (int)$showLogo,
            $flagBlackColor, $flagYellowColor, $flagRedColor, (int)$showFlagBar,
            $titleText, (int)$showTitle,
            $subheadingText, (int)$showSubheading, (int)$showSubmitButton,
            $ratingInstruction1Text, $ratingInstruction2Text, (int)$showRatingInstructions,
            (int)$showFacilitySection,
            $republicTitleText, (int)$showRepublicTitleShare, $ministrySubtitleText, (int)$showMinistrySubtitleShare,
            $qrInstructionsText, (int)$showQrInstructionsShare, $footerNoteText, (int)$showFooterNoteShare,
            $selectedInstanceKey, $selectedHierarchyLevel,
            (int)$showNumbering, $numberingStyle,
            $surveyId
        ];
        
        error_log("Update parameters: " . print_r($updateParams, true));
        
        $stmt = $pdo->prepare("
            UPDATE survey_settings SET
                logo_path = ?, show_logo = ?,
                flag_black_color = ?, flag_yellow_color = ?, flag_red_color = ?, show_flag_bar = ?,
                title_text = ?, show_title = ?,
                subheading_text = ?, show_subheading = ?, show_submit_button = ?,
                rating_instruction1_text = ?, rating_instruction2_text = ?, show_rating_instructions = ?,
                show_facility_section = ?,
                republic_title_text = ?, show_republic_title_share = ?, ministry_subtitle_text = ?, show_ministry_subtitle_share = ?,
                qr_instructions_text = ?, show_qr_instructions_share = ?, footer_note_text = ?, show_footer_note_share = ?,
                selected_instance_key = ?, selected_hierarchy_level = ?,
                show_numbering = ?, numbering_style = ?
            WHERE survey_id = ?
        ");
        
        $result = $stmt->execute($updateParams);
        error_log("Update result: " . ($result ? 'SUCCESS' : 'FAILED'));
        
        if (!$result) {
            error_log("Update error info: " . print_r($stmt->errorInfo(), true));
        }
    } else {
        // Insert new settings (this should ideally be handled by preview_form.php's initial load)
        // but included here for completeness/robustness.
        $stmt = $pdo->prepare("
            INSERT INTO survey_settings (
                survey_id, logo_path, show_logo, flag_black_color, flag_yellow_color, flag_red_color, show_flag_bar,
                title_text, show_title, subheading_text, show_subheading, show_submit_button,
                rating_instruction1_text, rating_instruction2_text, show_rating_instructions,
                show_facility_section, republic_title_text, show_republic_title_share, ministry_subtitle_text, show_ministry_subtitle_share,
                qr_instructions_text, show_qr_instructions_share, footer_note_text, show_footer_note_share,
                selected_instance_key, selected_hierarchy_level,
                show_numbering, numbering_style
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $surveyId, $logoPath, (int)$showLogo,
            $flagBlackColor, $flagYellowColor, $flagRedColor, (int)$showFlagBar,
            $titleText, (int)$showTitle,
            $subheadingText, (int)$showSubheading, (int)$showSubmitButton,
            $ratingInstruction1Text, $ratingInstruction2Text, (int)$showRatingInstructions,
            (int)$showFacilitySection,
            $republicTitleText, (int)$showRepublicTitleShare, $ministrySubtitleText, (int)$showMinistrySubtitleShare,
            $qrInstructionsText, (int)$showQrInstructionsShare, $footerNoteText, (int)$showFooterNoteShare,
            $selectedInstanceKey, $selectedHierarchyLevel,
            (int)$showNumbering, $numberingStyle
        ]);
    }

    echo json_encode(['status' => 'success', 'message' => 'Settings saved successfully!']);

} catch (PDOException $e) {
    error_log("Database error saving survey settings: " . $e->getMessage());
    error_log("PDO Error Code: " . $e->getCode());
    error_log("PDO Error File: " . $e->getFile() . " Line: " . $e->getLine());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("General error saving survey settings: " . $e->getMessage());
    error_log("Error Code: " . $e->getCode());
    error_log("Error File: " . $e->getFile() . " Line: " . $e->getLine());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode(['status' => 'error', 'message' => 'Unexpected error: ' . $e->getMessage()]);
}
?>