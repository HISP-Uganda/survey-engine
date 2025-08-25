<?php
/**
 * Unified Location Management API
 * Handles all location-related operations:
 * - Get location map (UID -> name mapping)
 * - Fetch missing locations from DHIS2
 * - Enrich DHIS2 org units with local data
 */

require_once 'dhis2/dhis2_shared.php';

header('Content-Type: application/json');

try {
    // Determine operation based on request method and parameters
    $method = $_SERVER['REQUEST_METHOD'];
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    $action = $_GET['action'] ?? ($data['action'] ?? '');
    
    switch ($action) {
        case 'get_map':
            handleGetLocationMap();
            break;
            
        case 'fetch_missing':
            handleFetchMissingLocations($data);
            break;
            
        case 'enrich_locations':
            handleEnrichLocations($data);
            break;
            
        default:
            // Auto-detect based on request data
            if (isset($data['orgUnits'])) {
                handleEnrichLocations($data);
            } elseif (isset($data['uids']) && isset($data['dhis2_instance'])) {
                handleFetchMissingLocations($data);
            } else {
                handleGetLocationMap();
            }
    }
    
} catch (Exception $e) {
    error_log("Location manager error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Get UID -> name mapping for all cached locations
 */
function handleGetLocationMap() {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT uid, name FROM location ORDER BY hierarchylevel ASC");
        $stmt->execute();
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $locationMap = [];
        foreach ($locations as $location) {
            $locationMap[$location['uid']] = $location['name'];
        }
        
        echo json_encode($locationMap);
        
    } catch (PDOException $e) {
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

/**
 * Fetch missing location names from DHIS2 and cache them
 */
function handleFetchMissingLocations($data) {
    global $pdo;
    
    if (!isset($data['uids']) || !isset($data['dhis2_instance'])) {
        throw new Exception('Missing required parameters: uids, dhis2_instance');
    }
    
    $uids = $data['uids'];
    $dhis2_instance = $data['dhis2_instance'];
    
    if (!is_array($uids) || empty($uids)) {
        echo json_encode([]);
        return;
    }
    
    $locationNames = [];
    
    // Fetch each UID from DHIS2
    foreach ($uids as $uid) {
        try {
            $endpoint = "organisationUnits/{$uid}?fields=id,name,path";
            $orgUnit = dhis2_get($endpoint, $dhis2_instance);
            
            if ($orgUnit && isset($orgUnit['name'])) {
                $locationNames[$uid] = $orgUnit['name'];
                
                // Cache in local database
                try {
                    $insertStmt = $pdo->prepare("
                        INSERT IGNORE INTO location (instance_key, uid, name, path, hierarchylevel) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $pathDepth = substr_count($orgUnit['path'] ?? '', '/');
                    $insertStmt->execute([
                        $dhis2_instance,
                        $uid,
                        $orgUnit['name'],
                        $orgUnit['path'] ?? "/{$uid}",
                        $pathDepth
                    ]);
                    
                    error_log("Cached location: {$uid} = {$orgUnit['name']}");
                } catch (PDOException $e) {
                    error_log("Failed to cache location {$uid}: " . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching UID {$uid}: " . $e->getMessage());
        }
    }
    
    echo json_encode($locationNames);
}

/**
 * Enrich DHIS2 org units with local database information
 */
function handleEnrichLocations($data) {
    global $pdo;
    
    if (!isset($data['orgUnits'])) {
        throw new Exception('Missing orgUnits parameter');
    }
    
    $dhis2_org_units = $data['orgUnits'];
    $survey_id = $data['surveyId'] ?? null;
    
    if (!is_array($dhis2_org_units)) {
        throw new Exception('orgUnits must be an array');
    }
    
    // Extract UIDs from DHIS2 org units
    $dhis2_uids = array_map(function($orgUnit) {
        return $orgUnit['id'];
    }, $dhis2_org_units);
    
    if (empty($dhis2_uids)) {
        echo json_encode([]);
        return;
    }
    
    // First, try to fetch missing locations from DHIS2
    $dhis2_instance = null;
    if ($survey_id) {
        $surveyStmt = $pdo->prepare("SELECT dhis2_instance FROM survey WHERE id = ?");
        $surveyStmt->execute([$survey_id]);
        $survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);
        $dhis2_instance = $survey['dhis2_instance'] ?? null;
    }
    
    if ($dhis2_instance) {
        // Check for missing UIDs and fetch them
        $placeholders = str_repeat('?,', count($dhis2_uids) - 1) . '?';
        $existingStmt = $pdo->prepare("SELECT uid FROM location WHERE uid IN ({$placeholders})");
        $existingStmt->execute($dhis2_uids);
        $existingUids = array_column($existingStmt->fetchAll(PDO::FETCH_ASSOC), 'uid');
        
        $missingUids = array_diff($dhis2_uids, $existingUids);
        
        if (!empty($missingUids)) {
            // Fetch missing locations in the background
            handleFetchMissingLocations([
                'uids' => $missingUids,
                'dhis2_instance' => $dhis2_instance
            ]);
        }
    }
    
    // Query local location table for matching UIDs
    $uid_placeholders = str_repeat('?,', count($dhis2_uids) - 1) . '?';
    $sql = "
        SELECT 
            l.id as local_id,
            l.name as local_name,
            l.uid as dhis2_uid,
            l.parent_id,
            l.hierarchylevel as level,
            l.uid as code,
            l.path,
            l.created,
            l.updated
        FROM location l 
        WHERE l.uid IN ({$uid_placeholders})
        ORDER BY l.hierarchylevel ASC, l.name ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($dhis2_uids);
    $local_locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create lookup array for local locations by UID
    $local_lookup = [];
    foreach ($local_locations as $location) {
        $local_lookup[$location['dhis2_uid']] = $location;
    }
    
    // Build enriched location data
    $enriched_locations = [];
    
    foreach ($dhis2_org_units as $orgUnit) {
        $uid = $orgUnit['id'];
        $local_data = $local_lookup[$uid] ?? null;
        
        // Build enriched location object
        $enriched = [
            'id' => $local_data ? $local_data['local_id'] : $uid,
            'uid' => $uid,
            'name' => $local_data ? $local_data['local_name'] : ($orgUnit['displayName'] ?? $orgUnit['name']),
            'displayName' => $orgUnit['displayName'] ?? $orgUnit['name'],
            'code' => $local_data ? $local_data['code'] : ($orgUnit['code'] ?? ''),
            'level' => $local_data ? (int)$local_data['level'] : ($orgUnit['level'] ?? 0),
            'path' => $orgUnit['path'] ?? "/{$uid}",
            'dhis2_path' => $orgUnit['path'] ?? '',
            'parent_id' => $local_data ? $local_data['parent_id'] : null,
            'has_local_data' => (bool)$local_data
        ];
        
        // Build readable path using our path conversion logic
        $enriched['readablePath'] = buildReadablePath($orgUnit['path'] ?? "/{$uid}", $local_lookup);
        
        $enriched_locations[] = $enriched;
    }
    
    // Sort by level and name for better display
    usort($enriched_locations, function($a, $b) {
        if ($a['level'] == $b['level']) {
            return strcmp($a['name'], $b['name']);
        }
        return $a['level'] - $b['level'];
    });
    
    echo json_encode($enriched_locations);
}

/**
 * Build readable path from DHIS2 path using local data
 */
function buildReadablePath($dhis2Path, $localLookup) {
    if (!$dhis2Path) return '';
    
    $uids = array_filter(explode('/', trim($dhis2Path, '/')));
    $readableNames = [];
    
    foreach ($uids as $uid) {
        if (isset($localLookup[$uid])) {
            $readableNames[] = $localLookup[$uid]['local_name'];
        } else {
            $readableNames[] = "[{$uid}]"; // Will be resolved by frontend
        }
    }
    
    return implode(' → ', $readableNames);
}