<?php
/**
 * Enrich DHIS2 Org Units with Local Location Data
 * 
 * Cross-references DHIS2 organization unit UIDs with the local location table
 * to provide rich display information like paths and hierarchies
 */

require_once 'connect.php';

header('Content-Type: application/json');

try {
    // Get POST data
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || !isset($data['orgUnits'])) {
        throw new Exception('Invalid request data');
    }
    
    $dhis2_org_units = $data['orgUnits'];
    $survey_id = $data['surveyId'] ?? null;
    
    if (!is_array($dhis2_org_units)) {
        throw new Exception('orgUnits must be an array');
    }
    
    error_log("Enriching " . count($dhis2_org_units) . " DHIS2 org units with local data");
    
    // Extract UIDs from DHIS2 org units
    $dhis2_uids = array_map(function($orgUnit) {
        return $orgUnit['id'];
    }, $dhis2_org_units);
    
    if (empty($dhis2_uids)) {
        echo json_encode([]);
        exit;
    }
    
    // Query local location table for matching UIDs
    $uid_placeholders = str_repeat('?,', count($dhis2_uids) - 1) . '?';
    $sql = "
        SELECT 
            l.id as local_id,
            l.name as local_name,
            l.uid as dhis2_uid,
            l.parent_id,
            l.level,
            l.code,
            l.path,
            l.created_date,
            l.updated_date
        FROM locations l 
        WHERE l.uid IN ({$uid_placeholders})
        ORDER BY l.level ASC, l.name ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($dhis2_uids);
    $local_locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Found " . count($local_locations) . " matching locations in local database");
    
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
            'path' => null, // Will be built below
            'dhis2_path' => $orgUnit['path'] ?? '',
            'parent_id' => $local_data ? $local_data['parent_id'] : null,
            'has_local_data' => (bool)$local_data
        ];
        
        // Build readable path - prioritize local data but enhance with DHIS2 if needed
        if ($local_data && $local_data['path']) {
            $enriched['path'] = $local_data['path'];
        } else {
            // Build path from DHIS2 data
            $enriched['path'] = buildReadablePath($orgUnit, $dhis2_org_units);
        }
        
        // If we don't have a good path yet, try to build from local database
        if ((!$enriched['path'] || $enriched['path'] === $enriched['name']) && $local_data) {
            $localPath = getLocalLocationPath($local_data['local_id'], $pdo);
            if ($localPath && $localPath !== $enriched['name']) {
                $enriched['path'] = $localPath;
            }
        }
        
        // Add readablePath field for frontend compatibility
        $enriched['readablePath'] = $enriched['path'];
        
        $enriched_locations[] = $enriched;
    }
    
    // Sort by level and name for better display
    usort($enriched_locations, function($a, $b) {
        if ($a['level'] == $b['level']) {
            return strcmp($a['name'], $b['name']);
        }
        return $a['level'] - $b['level'];
    });
    
    error_log("Successfully enriched " . count($enriched_locations) . " locations");
    
    echo json_encode($enriched_locations);
    
} catch (Exception $e) {
    error_log("Location enrichment error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage()
    ]);
}

/**
 * Build a readable path from DHIS2 org unit data
 */
function buildReadablePath($orgUnit, $allOrgUnits) {
    error_log("Building path for org unit: " . json_encode($orgUnit));
    
    $pathParts = [];
    
    // Method 1: Use ancestors array if available (best)
    if (isset($orgUnit['ancestors']) && is_array($orgUnit['ancestors']) && !empty($orgUnit['ancestors'])) {
        error_log("Found ancestors: " . json_encode($orgUnit['ancestors']));
        foreach ($orgUnit['ancestors'] as $ancestor) {
            if (isset($ancestor['displayName']) || isset($ancestor['name'])) {
                $pathParts[] = $ancestor['displayName'] ?? $ancestor['name'];
            }
        }
        $pathParts[] = $orgUnit['displayName'] ?? $orgUnit['name'];
        $path = implode(' → ', $pathParts);
        error_log("Built path from ancestors: " . $path);
        return $path;
    }
    
    // Method 2: Use parent information to build a simple 2-level path
    if (isset($orgUnit['parent']) && is_array($orgUnit['parent'])) {
        $parentName = $orgUnit['parent']['displayName'] ?? $orgUnit['parent']['name'];
        if ($parentName) {
            $path = $parentName . ' → ' . ($orgUnit['displayName'] ?? $orgUnit['name']);
            error_log("Built path from parent: " . $path);
            return $path;
        }
    }
    
    // Method 3: Create lookup table for all org units and try to resolve path UIDs
    if (isset($orgUnit['path']) && $orgUnit['path']) {
        $pathUids = explode('/', trim($orgUnit['path'], '/'));
        error_log("Path UIDs: " . json_encode($pathUids));
        
        // Create lookup for all available org units
        $uidToName = [];
        foreach ($allOrgUnits as $ou) {
            $uidToName[$ou['id']] = $ou['displayName'] ?? $ou['name'];
        }
        
        $resolvedNames = [];
        foreach ($pathUids as $uid) {
            if (isset($uidToName[$uid])) {
                $resolvedNames[] = $uidToName[$uid];
            } else {
                // If we can't resolve the UID, skip building from path
                break;
            }
        }
        
        if (count($resolvedNames) === count($pathUids)) {
            $path = implode(' → ', $resolvedNames);
            error_log("Built path from resolved UIDs: " . $path);
            return $path;
        }
    }
    
    // Method 4: Fallback to just the current org unit name
    $fallback = $orgUnit['displayName'] ?? $orgUnit['name'];
    error_log("Using fallback name: " . $fallback);
    return $fallback;
}

/**
 * Get location path by building hierarchy from local database
 */
function getLocalLocationPath($location_id, $pdo) {
    try {
        $path_parts = [];
        $current_id = $location_id;
        $max_depth = 10; // Prevent infinite loops
        $depth = 0;
        
        while ($current_id && $depth < $max_depth) {
            $stmt = $pdo->prepare("SELECT id, name, parent_id FROM locations WHERE id = ?");
            $stmt->execute([$current_id]);
            $location = $stmt->fetch();
            
            if (!$location) break;
            
            array_unshift($path_parts, $location['name']);
            $current_id = $location['parent_id'];
            $depth++;
        }
        
        return implode(' > ', $path_parts);
        
    } catch (Exception $e) {
        error_log("Error building location path: " . $e->getMessage());
        return '';
    }
}
?>