<?php
// save_survey_settings.php
require_once 'connect.php'; // Include your database connection

header('Content-Type: application/json');

if (!isset($pdo)) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit;
}

// Get the raw POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input.']);
    exit;
}

$surveyId = $data['surveyId'] ?? null;

if (!$surveyId) {
    echo json_encode(['status' => 'error', 'message' => 'Survey ID is missing.']);
    exit;
}

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
$showLocationRowGeneral = $data['showLocationRowGeneral'] ?? false;
$showLocationRowPeriodAge = $data['showLocationRowPeriodAge'] ?? false;
$showOwnershipSection = $data['showOwnershipSection'] ?? false;
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
    // Check if settings exist for this survey
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM survey_settings WHERE survey_id = ?");
    $checkStmt->execute([$surveyId]);
    $exists = $checkStmt->fetchColumn();

    if ($exists) {
        // Update existing settings
        $stmt = $pdo->prepare("
            UPDATE survey_settings SET
                logo_path = ?, show_logo = ?,
                flag_black_color = ?, flag_yellow_color = ?, flag_red_color = ?, show_flag_bar = ?,
                title_text = ?, show_title = ?,
                subheading_text = ?, show_subheading = ?, show_submit_button = ?,
                rating_instruction1_text = ?, rating_instruction2_text = ?, show_rating_instructions = ?,
                show_facility_section = ?, show_location_row_general = ?, show_location_row_period_age = ?, show_ownership_section = ?,
                republic_title_text = ?, show_republic_title_share = ?, ministry_subtitle_text = ?, show_ministry_subtitle_share = ?,
                qr_instructions_text = ?, show_qr_instructions_share = ?, footer_note_text = ?, show_footer_note_share = ?,
                selected_instance_key = ?, selected_hierarchy_level = ? -- Added new columns here
            WHERE survey_id = ?
        ");
        $stmt->execute([
            $logoPath, (int)$showLogo,
            $flagBlackColor, $flagYellowColor, $flagRedColor, (int)$showFlagBar,
            $titleText, (int)$showTitle,
            $subheadingText, (int)$showSubheading, (int)$showSubmitButton,
            $ratingInstruction1Text, $ratingInstruction2Text, (int)$showRatingInstructions,
            (int)$showFacilitySection, (int)$showLocationRowGeneral, (int)$showLocationRowPeriodAge, (int)$showOwnershipSection,
            $republicTitleText, (int)$showRepublicTitleShare, $ministrySubtitleText, (int)$showMinistrySubtitleShare,
            $qrInstructionsText, (int)$showQrInstructionsShare, $footerNoteText, (int)$showFooterNoteShare,
            $selectedInstanceKey, $selectedHierarchyLevel, // Values for new columns
            $surveyId
        ]);
    } else {
        // Insert new settings (this should ideally be handled by preview_form.php's initial load)
        // but included here for completeness/robustness.
        $stmt = $pdo->prepare("
            INSERT INTO survey_settings (
                survey_id, logo_path, show_logo, flag_black_color, flag_yellow_color, flag_red_color, show_flag_bar,
                title_text, show_title, subheading_text, show_subheading, show_submit_button,
                rating_instruction1_text, rating_instruction2_text, show_rating_instructions,
                show_facility_section, show_location_row_general, show_location_row_period_age, show_ownership_section,
                republic_title_text, show_republic_title_share, ministry_subtitle_text, show_ministry_subtitle_share,
                qr_instructions_text, show_qr_instructions_share, footer_note_text, show_footer_note_share,
                selected_instance_key, selected_hierarchy_level -- Added new columns here
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $surveyId, $logoPath, (int)$showLogo,
            $flagBlackColor, $flagYellowColor, $flagRedColor, (int)$showFlagBar,
            $titleText, (int)$showTitle,
            $subheadingText, (int)$showSubheading, (int)$showSubmitButton,
            $ratingInstruction1Text, $ratingInstruction2Text, (int)$showRatingInstructions,
            (int)$showFacilitySection, (int)$showLocationRowGeneral, (int)$showLocationRowPeriodAge, (int)$showOwnershipSection,
            $republicTitleText, (int)$showRepublicTitleShare, $ministrySubtitleText, (int)$showMinistrySubtitleShare,
            $qrInstructionsText, (int)$showQrInstructionsShare, $footerNoteText, (int)$showFooterNoteShare,
            $selectedInstanceKey, $selectedHierarchyLevel // Values for new columns
        ]);
    }

    echo json_encode(['status' => 'success', 'message' => 'Settings saved successfully!']);

} catch (PDOException $e) {
    error_log("Database error saving survey settings: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Error saving settings: ' . $e->getMessage()]);
}
?>