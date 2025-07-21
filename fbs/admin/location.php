<?php
require 'connect.php';

header('Content-Type: application/json');

try {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'search_facilities') {
        $searchTerm = $_GET['term'] ?? '';
        
        $query = "SELECT id, name as facility_name, hierarchylevel 
                  FROM location 
                  WHERE hierarchylevel = 5
                  AND name LIKE :searchTerm
                  LIMIT 50";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute(['searchTerm' => '%' . $searchTerm . '%']);
        $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($facilities ?: []);
        exit;
    }
    
    if ($action === 'get_hierarchy') {
        $facilityId = $_GET['facility_id'] ?? 0;
        
        // Get the facility first
        $stmt = $pdo->prepare("SELECT id, name, parent_id, hierarchylevel FROM location WHERE id = ?");
        $stmt->execute([$facilityId]);
        $facility = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$facility) {
            echo json_encode(['error' => 'Facility not found']);
            exit;
        }
        
        // Get all ancestors
        $hierarchy = [];
        $currentId = $facility['parent_id'];
        
        while ($currentId) {
            $stmt = $pdo->prepare("SELECT id, name, parent_id, hierarchylevel FROM location WHERE id = ?");
            $stmt->execute([$currentId]);
            $location = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$location) break;
            
            $hierarchy[] = [
                'id' => $location['id'],
                'name' => $location['name'],
                'level' => $location['hierarchylevel']
            ];
            
            $currentId = $location['parent_id'];
        }
        
        // Reverse to get top-down order
        $hierarchy = array_reverse($hierarchy);
        
        echo json_encode([
            'facility_name' => $facility['name'],
            'hierarchy' => $hierarchy
        ]);
        exit;
    }
    
    echo json_encode(['error' => 'Invalid action']);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}