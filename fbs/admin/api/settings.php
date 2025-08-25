<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../connect.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // Save tracker settings using dedicated tables
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['survey_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Survey ID is required']);
        exit();
    }
    
    $surveyId = $input['survey_id'];
    
    try {
        $pdo->beginTransaction();
        
        // Handle layout settings in tracker_layout_settings table
        $layoutSettings = [
            'layout_type' => $input['layout_type'] ?? 'horizontal',
            'show_flag_bar' => $input['show_flag_bar'] ?? true,
            'flag_black_color' => $input['flag_black_color'] ?? '#000000',
            'flag_yellow_color' => $input['flag_yellow_color'] ?? '#FCD116',
            'flag_red_color' => $input['flag_red_color'] ?? '#D21034'
        ];
        
        // Insert or update layout settings
        $layoutStmt = $pdo->prepare("
            INSERT INTO tracker_layout_settings (
                survey_id, layout_type, show_flag_bar, flag_black_color, flag_yellow_color, flag_red_color
            ) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                layout_type = VALUES(layout_type),
                show_flag_bar = VALUES(show_flag_bar),
                flag_black_color = VALUES(flag_black_color),
                flag_yellow_color = VALUES(flag_yellow_color),
                flag_red_color = VALUES(flag_red_color),
                updated_at = CURRENT_TIMESTAMP
        ");
        
        $layoutStmt->execute([
            $surveyId,
            $layoutSettings['layout_type'],
            $layoutSettings['show_flag_bar'] ? 1 : 0,
            $layoutSettings['flag_black_color'],
            $layoutSettings['flag_yellow_color'],
            $layoutSettings['flag_red_color']
        ]);
        
        // Handle images in tracker_images table
        if (isset($input['images']) && is_array($input['images'])) {
            // First, deactivate all existing images for this survey
            $deactivateStmt = $pdo->prepare("UPDATE tracker_images SET is_active = 0 WHERE survey_id = ?");
            $deactivateStmt->execute([$surveyId]);
            
            // Insert or update each image
            foreach ($input['images'] as $imageData) {
                if (empty($imageData['image_path'])) continue;
                
                $imageStmt = $pdo->prepare("
                    INSERT INTO tracker_images (
                        survey_id, image_order, image_path, image_alt_text, 
                        width_px, height_px, position_type, is_active
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                    ON DUPLICATE KEY UPDATE
                        image_path = VALUES(image_path),
                        image_alt_text = VALUES(image_alt_text),
                        width_px = VALUES(width_px),
                        height_px = VALUES(height_px),
                        position_type = VALUES(position_type),
                        is_active = 1,
                        updated_at = CURRENT_TIMESTAMP
                ");
                
                $imageStmt->execute([
                    $surveyId,
                    $imageData['order'] ?? 1,
                    $imageData['image_path'],
                    $imageData['alt_text'] ?? '',
                    $imageData['width'] ?? 100,
                    $imageData['height'] ?? 60,
                    $imageData['position'] ?? 'center'
                ]);
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Tracker settings saved successfully'
        ]);
        
    } catch (PDOException $e) {
        $pdo->rollback();
        error_log("Database error saving tracker settings: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }

} elseif ($method === 'GET') {
    // Load tracker settings from dedicated tables
    $surveyId = $_GET['survey_id'] ?? null;
    
    if (!$surveyId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Survey ID is required']);
        exit();
    }
    
    try {
        // Load layout settings
        $layoutStmt = $pdo->prepare("
            SELECT layout_type, show_flag_bar, flag_black_color, flag_yellow_color, flag_red_color
            FROM tracker_layout_settings 
            WHERE survey_id = ?
        ");
        $layoutStmt->execute([$surveyId]);
        $layoutSettings = $layoutStmt->fetch(PDO::FETCH_ASSOC);
        
        // Load active images
        $imageStmt = $pdo->prepare("
            SELECT image_order, image_path, image_alt_text, width_px, height_px, position_type
            FROM tracker_images 
            WHERE survey_id = ? AND is_active = 1
            ORDER BY image_order ASC
        ");
        $imageStmt->execute([$surveyId]);
        $images = $imageStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Prepare response data
        $responseData = [];
        
        if ($layoutSettings) {
            $responseData = [
                'layout_type' => $layoutSettings['layout_type'],
                'show_flag_bar' => (bool)$layoutSettings['show_flag_bar'],
                'flag_black_color' => $layoutSettings['flag_black_color'],
                'flag_yellow_color' => $layoutSettings['flag_yellow_color'],
                'flag_red_color' => $layoutSettings['flag_red_color']
            ];
        } else {
            // Default layout settings
            $responseData = [
                'layout_type' => 'horizontal',
                'show_flag_bar' => true,
                'flag_black_color' => '#000000',
                'flag_yellow_color' => '#FCD116',
                'flag_red_color' => '#D21034'
            ];
        }
        
        // Add images to response
        $responseData['images'] = [];
        foreach ($images as $image) {
            $responseData['images'][] = [
                'order' => $image['image_order'],
                'image_path' => $image['image_path'],
                'alt_text' => $image['image_alt_text'],
                'width' => $image['width_px'],
                'height' => $image['height_px'],
                'position' => $image['position_type']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'data' => $responseData
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error loading tracker settings: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
?>