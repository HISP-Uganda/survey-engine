<?php
/**
 * DHIS2 API Proxy for Program Org Units
 * 
 * This proxy fetches organization units assigned to a specific program from DHIS2
 */

require_once 'connect.php';
require_once 'dhis2/dhis2_shared.php';

header('Content-Type: application/json');

try {
    // Validate request parameters
    $endpoint = $_GET['endpoint'] ?? '';
    $programs = $_GET['programs'] ?? '';
    
    if (empty($endpoint) || empty($programs)) {
        throw new Exception('Missing required parameters: endpoint and programs');
    }
    
    // Get DHIS2 instance key
    $instance_key = getDHIS2InstanceKey();
    
    if (!$instance_key) {
        throw new Exception('DHIS2 instance not found');
    }
    
    // Get DHIS2 configuration using dhis2_shared.php function
    $dhis2_config = getDhis2Config($instance_key);
    if (!$dhis2_config) {
        throw new Exception('DHIS2 configuration not found for instance: ' . $instance_key);
    }
    
    error_log("DHIS2 Config retrieved for instance: " . $instance_key);
    error_log("DHIS2 Base URL: " . $dhis2_config['url']);
    
    // Build DHIS2 API URL
    if ($endpoint === 'programs/orgUnits') {
        $api_endpoint = "/api/programs/{$programs}/organisationUnits.json?fields=id,code,name,displayName,path,level,parent[id,name,displayName],ancestors[id,name,displayName]&paging=false";
        error_log("DHIS2 API endpoint: {$api_endpoint}");
    } else {
        throw new Exception('Unsupported endpoint');
    }
    
    // Use dhis2_shared.php function for API call
    $data = dhis2_get($api_endpoint, $instance_key);
    
    if ($data === null) {
        throw new Exception('DHIS2 API call failed');
    }
    
    $http_code = 200; // dhis2_get returns null on error, data on success
    $response = json_encode($data);
    $curl_error = null;
    
    // Log successful request
    error_log("DHIS2 API Proxy: Successfully fetched org units for program {$programs}");
    
    // Return the response
    echo json_encode($data);
    
} catch (Exception $e) {
    error_log("DHIS2 API Proxy Error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage()
    ]);
}

/**
 * Get DHIS2 instance key for the survey
 */
function getDHIS2InstanceKey() {
    global $pdo;
    
    try {
        // Try to get survey_id from query parameter first
        $survey_id = $_GET['survey_id'] ?? null;
        
        if ($survey_id) {
            // Get survey's DHIS2 instance
            $stmt = $pdo->prepare("SELECT dhis2_instance FROM survey WHERE id = ?");
            $stmt->execute([$survey_id]);
            $survey = $stmt->fetch();
            
            if ($survey && $survey['dhis2_instance']) {
                return $survey['dhis2_instance'];
            }
        }
        
        // Fallback: Get any active DHIS2 instance key
        $stmt = $pdo->prepare("SELECT instance_key FROM dhis2_instances WHERE status = 1 LIMIT 1");
        $stmt->execute();
        $instance = $stmt->fetch();
        
        return $instance ? $instance['instance_key'] : null;
        
    } catch (Exception $e) {
        error_log("Error getting DHIS2 instance key: " . $e->getMessage());
        return null;
    }
}
?>