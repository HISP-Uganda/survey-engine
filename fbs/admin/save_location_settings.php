<?php
session_start();

if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once 'connect.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $surveyId = $_POST['survey_id'] ?? null;
    $selectedInstanceKey = $_POST['selected_instance_key'] ?? '';
    $selectedHierarchyLevel = $_POST['selected_hierarchy_level'] ?? '';
    
    if (!$surveyId) {
        throw new Exception('Survey ID is required');
    }
    
    // Check if survey settings exist
    $checkStmt = $pdo->prepare("SELECT id FROM survey_settings WHERE survey_id = ?");
    $checkStmt->execute([$surveyId]);
    $exists = $checkStmt->fetch();
    
    if ($exists) {
        // Update existing settings
        $stmt = $pdo->prepare("
            UPDATE survey_settings 
            SET selected_instance_key = ?, selected_hierarchy_level = ?
            WHERE survey_id = ?
        ");
        $stmt->execute([$selectedInstanceKey, $selectedHierarchyLevel, $surveyId]);
    } else {
        // Insert new settings with default values
        $stmt = $pdo->prepare("
            INSERT INTO survey_settings (
                survey_id, selected_instance_key, selected_hierarchy_level,
                logo_path, show_logo, flag_black_color, flag_yellow_color, flag_red_color, show_flag_bar,
                title_text, show_title, subheading_text, show_subheading, show_submit_button,
                rating_instruction1_text, rating_instruction2_text, show_rating_instructions,
                show_facility_section, republic_title_text, show_republic_title_share, 
                ministry_subtitle_text, show_ministry_subtitle_share,
                qr_instructions_text, show_qr_instructions_share, footer_note_text, show_footer_note_share,
                show_numbering, numbering_style
            ) VALUES (
                ?, ?, ?,
                'asets/asets/img/loog.jpg', 1, '#000000', '#FCD116', '#D21034', 1,
                'DHIS2 Tracker Program', 1, '', 0, 1,
                '', '', 0,
                1, 'Republic of Uganda', 1,
                'Ministry of Health', 1,
                '', 1, '', 1,
                0, 'decimal'
            )
        ");
        $stmt->execute([$surveyId, $selectedInstanceKey, $selectedHierarchyLevel]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Location settings saved successfully',
        'data' => [
            'selected_instance_key' => $selectedInstanceKey,
            'selected_hierarchy_level' => $selectedHierarchyLevel
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error saving location settings: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>