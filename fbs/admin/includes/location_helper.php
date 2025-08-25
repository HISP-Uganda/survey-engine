<?php

/**
 * Location Helper Functions
 * Converts DHIS2 paths to human-readable format
 */

/**
 * Convert DHIS2 orgunit path to human-readable format
 * 
 * @param string $dhis2Path - DHIS2 path like "/KJPN4PduBWe/yXncUmqtQYF/Gwk4wkLz7EW/F2gyHGKOZgR"
 * @param PDO $pdo - Database connection
 * @return string - Human readable path like "MoES Uganda → Acholi Region → Gulu District → Gulu CC"
 */
function convertDhis2PathToReadable($dhis2Path, $pdo) {
    // Remove leading/trailing slashes and split by /
    $uids = array_filter(explode('/', trim($dhis2Path, '/')));
    
    if (empty($uids)) {
        return $dhis2Path;
    }
    
    try {
        // Create placeholders for IN clause
        $placeholders = str_repeat('?,', count($uids) - 1) . '?';
        
        // Query location table to get names ordered by hierarchy level
        $stmt = $pdo->prepare("
            SELECT uid, name, hierarchylevel 
            FROM location 
            WHERE uid IN ($placeholders)
            ORDER BY hierarchylevel ASC
        ");
        
        $stmt->execute($uids);
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($locations)) {
            return $dhis2Path; // Return original if no matches found
        }
        
        // Create a map for quick lookup
        $locationMap = [];
        foreach ($locations as $loc) {
            $locationMap[$loc['uid']] = $loc['name'];
        }
        
        // Build readable path maintaining the hierarchy order
        $readableNames = [];
        foreach ($uids as $uid) {
            if (isset($locationMap[$uid])) {
                $readableNames[] = $locationMap[$uid];
            }
        }
        
        return implode(' → ', $readableNames);
        
    } catch (PDOException $e) {
        error_log("Error converting DHIS2 path: " . $e->getMessage());
        return $dhis2Path; // Return original on error
    }
}

/**
 * Get the leaf (final) location name from DHIS2 path
 * 
 * @param string $dhis2Path - DHIS2 path
 * @param PDO $pdo - Database connection
 * @return string - Just the final location name
 */
function getLeafLocationName($dhis2Path, $pdo) {
    $uids = array_filter(explode('/', trim($dhis2Path, '/')));
    
    if (empty($uids)) {
        return '';
    }
    
    // Get the last UID (leaf node)
    $leafUid = end($uids);
    
    try {
        $stmt = $pdo->prepare("SELECT name FROM location WHERE uid = ? LIMIT 1");
        $stmt->execute([$leafUid]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['name'] : $leafUid;
        
    } catch (PDOException $e) {
        error_log("Error getting leaf location name: " . $e->getMessage());
        return $leafUid;
    }
}

/**
 * JavaScript helper to convert paths on the client side
 * Call this function to get the JS code for client-side conversion
 */
function getLocationConversionJS() {
    return "
    // Location conversion map (populated from server)
    let locationMap = {};
    
    // Function to convert DHIS2 path to readable format
    function convertPathToReadable(dhis2Path) {
        if (!dhis2Path) return '';
        
        const uids = dhis2Path.split('/').filter(uid => uid.length > 0);
        const readableNames = uids.map(uid => locationMap[uid] || uid);
        
        return readableNames.join(' → ');
    }
    
    // Function to load location map from server
    async function loadLocationMap() {
        try {
            const response = await fetch('get_location_map.php');
            const data = await response.json();
            locationMap = data;
        } catch (error) {
            console.error('Error loading location map:', error);
        }
    }
    ";
}