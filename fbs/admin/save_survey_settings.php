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
$showDynamicImages = $data['showDynamicImages'] ?? false;
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

// Dynamic images settings
$dynamicImages = $data['dynamicImages'] ?? [];
$imageLayout = $data['imageLayout'] ?? 'horizontal';


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
            (int)$showDynamicImages,
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
                show_dynamic_images = ?,
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
                survey_id, show_dynamic_images, flag_black_color, flag_yellow_color, flag_red_color, show_flag_bar,
                title_text, show_title, subheading_text, show_subheading, show_submit_button,
                rating_instruction1_text, rating_instruction2_text, show_rating_instructions,
                show_facility_section, republic_title_text, show_republic_title_share, ministry_subtitle_text, show_ministry_subtitle_share,
                qr_instructions_text, show_qr_instructions_share, footer_note_text, show_footer_note_share,
                selected_instance_key, selected_hierarchy_level,
                show_numbering, numbering_style
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $surveyId, (int)$showDynamicImages,
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

    // Handle dynamic images - store as JSON in survey_settings table
    // First, let's check if we need to add columns to survey_settings table
    try {
        $pdo->exec("ALTER TABLE survey_settings ADD COLUMN show_dynamic_images TINYINT(1) DEFAULT 0");
        $pdo->exec("ALTER TABLE survey_settings ADD COLUMN dynamic_images_data JSON");
        $pdo->exec("ALTER TABLE survey_settings ADD COLUMN image_layout_type VARCHAR(20) DEFAULT 'horizontal'");
    } catch (PDOException $e) {
        // Columns may already exist, which is fine
    }
    
    // Prepare dynamic images data for storage
    $processedImages = [];
    if (!empty($dynamicImages)) {
        foreach ($dynamicImages as $index => $imageData) {
            if (!empty($imageData['imageData'])) {
                // Handle base64 image upload
                $base64Image = explode(',', $imageData['imageData'])[1];
                $imageType = explode(';', explode(':', $imageData['imageData'])[1])[0];
                $extension = explode('/', $imageType)[1];
                
                $uploadDir = 'asets/img/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $fileName = 'survey_img_' . $surveyId . '_' . ($index + 1) . '_' . time() . '_' . uniqid() . '.' . $extension;
                $filePath = $uploadDir . $fileName;
                
                if (file_put_contents($filePath, base64_decode($base64Image))) {
                    $processedImages[] = [
                        'order' => $index + 1,
                        'path' => $filePath,
                        'alt_text' => $imageData['altText'] ?? 'Survey Image ' . ($index + 1),
                        'width' => intval($imageData['width'] ?? 100),
                        'height' => intval($imageData['height'] ?? 80),
                        'position' => $imageData['position'] ?? 'center'
                    ];
                }
            }
        }
    }
    
    // Update dynamic images data in survey_settings table
    if (!empty($processedImages) || !empty($dynamicImages)) {
        $updateImagesStmt = $pdo->prepare("
            UPDATE survey_settings 
            SET dynamic_images_data = ?, image_layout_type = ? 
            WHERE survey_id = ?
        ");
        
        $updateImagesStmt->execute([
            json_encode($processedImages),
            $imageLayout,
            $surveyId
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