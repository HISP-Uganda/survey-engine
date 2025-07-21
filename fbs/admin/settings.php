<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require 'connect.php';
require 'dhis2/dhis2_shared.php';
require 'dhis2/dhis2_get_function.php';

// set_time_limit(600); // 10+ minutes

// Initialize variables at the top
$location = [];
$orgUnits = [];
$message = [];
$debug = [];
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'view';
$selectedInstance = isset($_POST['dhis2_instance']) ? $_POST['dhis2_instance'] : (isset($_GET['dhis2_instance']) ? $_GET['dhis2_instance'] : null);
$selectedLevel = isset($_POST['org_level']) ? $_POST['org_level'] : null;
$includeChildren = isset($_POST['include_children']) ? true : false;
$useOrgUnit = isset($_POST['use_org_unit']) ? true : false;
$useSubUnits = isset($_POST['user_sub_units']) ? true : false;
$useSubX2Units = isset($_POST['user_sub_x2_units']) ? true : false;
$useOrgLevel = isset($_POST['use_org_level']) ? true : false;

// Get available org unit levels from DHIS2
function getDHIS2OrgUnitLevels($instance) {
    $levels = [];
    try {
        $levelsData = dhis2_get('/api/organisationUnitLevels?fields=id,name,level&paging=false', $instance);
        if (isset($levelsData['organisationUnitLevels']) && is_array($levelsData['organisationUnitLevels'])) {
            foreach ($levelsData['organisationUnitLevels'] as $level) {
                $levels[$level['level']] = $level['name'];
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching org unit levels: " . $e->getMessage());
    }
    
    // If no levels fetched, provide default ones
    if (empty($levels)) {
        $levels = [
            1 => 'Country',
            2 => 'Region',
            3 => 'District',
            4 => 'DLG',
            5 => 'Subcounty',
            6 => 'Facility'
        ];
    }
    
    return $levels;
}

// Helper functions
function getChildLocations($pdo, $parentId) {
    $query = "SELECT id, uid, name, path, hierarchylevel, parent_id FROM location 
              WHERE parent_id = ? ORDER BY name";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$parentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buildTreeView($pdo, $parentId = null, $level = 0) {
    $html = '';
    
    // Get top-level location (where parent_id is null or 0)
    $query = "SELECT id, uid, name, path, hierarchylevel, parent_id FROM location 
              WHERE " . ($parentId === null ? "parent_id IS NULL" : "parent_id = ?") . " 
              ORDER BY name";
    $stmt = $pdo->prepare($query);
    $stmt->execute($parentId === null ? [] : [$parentId]);
    $location = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($location)) {
        $html .= '<ul class="tree">';
        foreach ($location as $location) {
            // Check if this location has children
            $childStmt = $pdo->prepare("SELECT COUNT(*) FROM location WHERE parent_id = ?");
            $childStmt->execute([$location['id']]);
            $hasChildren = $childStmt->fetchColumn() > 0;
            
            $html .= '<li>';
            $html .= '<span class="tree-item' . ($hasChildren ? ' has-children' : '') . '" ';
            $html .= 'onclick="' . ($hasChildren ? 'toggleNode(this)' : '') . '">';
            $html .= htmlspecialchars($location['name']) . 
                    ' <small class="text-muted">(Level ' . $location['hierarchylevel'] . ' - ' . $location['uid'] . ')</small>';
            $html .= '</span>';
            
            // Recursively build child nodes (initially hidden)
            if ($hasChildren) {
                $html .= '<ul class="subtree" style="display:none;">';
                $html .= buildTreeView($pdo, $location['id'], $level + 1);
                $html .= '</ul>';
            }
            
            $html .= '</li>';
        }
        $html .= '</ul>';
    }
    
    return $html;
}

function getLevelName($level) {
    $levels = [
        1 => 'Country',
        2 => 'Region',
        3 => 'District',
        4 => 'DLG',
        5 => 'Subcounty',
        6 => 'Facility'
    ];
    return $levels[$level] ?? 'Level '.$level;
}

function processParentBatch($pdo, $batch) {
    try {
        $pdo->exec("CREATE TEMPORARY TABLE IF NOT EXISTS temp_batch (
            child_uid VARCHAR(11),
            parent_uid VARCHAR(11)
        ) ENGINE=MEMORY");
        
        $insertStmt = $pdo->prepare("INSERT INTO temp_batch (child_uid, parent_uid) VALUES (?, ?)");
        foreach ($batch as $item) {
            $insertStmt->execute([$item['child_uid'], $item['parent_uid']]);
        }
        
        $updateStmt = $pdo->prepare("
            UPDATE location l
            JOIN temp_batch b ON l.uid = b.child_uid
            JOIN temp_parent_mapping p ON b.parent_uid = p.uid
            SET l.parent_id = p.db_id
        ");
        $updateStmt->execute();
        
        $pdo->exec("TRUNCATE TABLE temp_batch");
    } catch (PDOException $e) {
        throw new Exception("Batch processing failed: " . $e->getMessage());
    }
}


function fetchOrgUnitStructure($instance) {
    try {
        // Simplified query - get only top level units first
        $response = dhis2_get('/api/organisationUnits?paging=false&level=1&fields=id,name,level', $instance);
        
        if (!isset($response['organisationUnits'])) {
            throw new Exception("Invalid response structure from DHIS2");
        }
        
        return $response;
    } catch (Exception $e) {
        error_log("DHIS2 API Error: " . $e->getMessage());
        return ['organisationUnits' => []];
    }
}

// In your POST handler:
if ($selectedInstance && isset($_POST['fetch_orgunits'])) {
    try {
        $apiPath = '/api/organisationUnits?paging=false&fields=id,name,level';
        
        if ($useOrgLevel && $selectedLevel) {
            $apiPath .= '&filter=level:eq:' . $selectedLevel;
        }
        
        if ($useOrgUnit && isset($_POST['root_org_unit'])) {
            $apiPath .= '&filter=id:eq:' . $_POST['root_org_unit'];
        }
        
        if ($useSubUnits) {
            $apiPath = '/api/organisationUnits?paging=false&fields=id,name,level&filter=level:le:3';
        }
        
        $orgUnits = dhis2_get($apiPath, $selectedInstance);
        
        if (empty($orgUnits['organisationUnits'])) {
            throw new Exception("No units found with current filters");
        }
        
    } catch (Exception $e) {
        $message = ['type' => 'error', 'text' => $e->getMessage()];
        $orgUnits = ['organisationUnits' => []];
    }
}
// Main logic
try {
    // View Locations Tab
    if ($activeTab == 'view') {
        $query = "SELECT id, uid, name, path, hierarchylevel, parent_id FROM location 
                WHERE hierarchylevel = 2 OR (parent_id IS NULL AND hierarchylevel <= 2) 
                ORDER BY name";
        $stmt = $pdo->query($query);
        $location = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Load from DHIS2 Tab
    if ($activeTab == 'load') {
        // Automatically fetch org unit structure when instance is selected
        if ($selectedInstance && empty($orgUnits)) {
            $orgUnits = fetchOrgUnitStructure($selectedInstance);
        }
        
        // Get org unit levels for the dropdown
        $orgUnitLevels = [];
        if ($selectedInstance) {
            $orgUnitLevels = getDHIS2OrgUnitLevels($selectedInstance);
        }
        
        // Fetch org units based on selected filters
        if ($selectedInstance && isset($_POST['fetch_orgunits'])) {
            $apiPath = '/api/organisationUnits?paging=false';
            
            // Apply filters based on selection
            if ($useOrgLevel && $selectedLevel) {
                $apiPath .= '&level=' . $selectedLevel;
            }
            
            if ($useOrgUnit) {
                // Use selected org unit as root
                if (isset($_POST['root_org_unit'])) {
                    $apiPath .= '&filter=path:like:' . $_POST['root_org_unit'];
                }
            }
            
            if ($useSubUnits) {
                $apiPath .= '&userSubUnit=true';
            }
            
            if ($useSubX2Units) {
                $apiPath .= '&userSubX2Unit=true';
            }
            
            // Include appropriate fields
            if ($includeChildren) {
                $apiPath .= '&fields=id,name,path,level,parent[id],children[id,name,path,level]';
            } else {
                $apiPath .= '&fields=id,name,path,level,parent[id]';
            }
            
            $orgUnits = dhis2_get($apiPath, $selectedInstance);
            
            if (!$orgUnits || !isset($orgUnits['organisationUnits'])) {
                throw new Exception("No organisation units found or invalid response from DHIS2");
            }
        }
         
// Handle sync to database
// // Handle sync to database - Modified to handle large datasets
// if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['sync_locations'])) {
//     $selectedOrgUnits = $_POST['selected_orgunits'] ?? [];
    
//     if (empty($selectedOrgUnits)) {
//         throw new Exception("No organisation units selected for synchronization");
//     }
    
//     try {
//         // Create a session-based job to track progress
//         $jobId = uniqid('sync_');
//         $_SESSION['sync_jobs'][$jobId] = [
//             'total' => count($selectedOrgUnits),
//             'processed' => 0,
//             'status' => 'preparing',
//             'start_time' => time(),
//             'inserted' => 0,
//             'updated' => 0,
//             'errors' => 0
//         ];
        
//         // Create storage directory if it doesn't exist
//         $storageDir = __DIR__ . '/temp';
//         if (!file_exists($storageDir)) {
//             mkdir($storageDir, 0755, true);
//         }
        
//         // Save selected org units to a file for processing
//         $unitsFile = $storageDir . '/' . $jobId . '_units.json';
//         file_put_contents($unitsFile, json_encode([
//             'instance' => $selectedInstance,
//             'units' => $selectedOrgUnits
//         ]));
//         chmod($unitsFile, 0644);
        
//         // Create an empty CSV file for data
//         $csvFile = $storageDir . '/' . $jobId . '_data.csv';
//         $fp = fopen($csvFile, 'w');
//         fputcsv($fp, ['uid', 'name', 'path', 'hierarchylevel', 'parent_uid']);
//         fclose($fp);
//         chmod($csvFile, 0644);
        
//         // Redirect to a processing page with smaller batch size
//         $message = [
//             'type' => 'info',
//             'text' => 'Starting synchronization of ' . count($selectedOrgUnits) . ' units. Please wait...'
//         ];
        
//         // Set the job as ready for processing
//         $_SESSION['sync_jobs'][$jobId]['status'] = 'ready';

//         // Include JavaScript to handle background processing
//         echo '<script>
//             document.addEventListener("DOMContentLoaded", function() {
//                 // Function to process the next batch
//                // In settings.php, update the processNextBatch function
//                 function processNextBatch(jobId, offset) {
//                     fetch("sync_processor.php?job_id=" + jobId + "&offset=" + offset)
//                     .then(response => response.json())
//                     .then(data => {
//                         // Update progress
//                         updateProgress(data);
                        
//                         if (data.status === "processing") {
//                             // Continue processing
//                             processNextBatch(jobId, data.processed);
//                         } else if (data.status === "importing") {
//                             // Switch to import phase UI
//                             showImportProgress();
//                             processNextBatch(jobId, data.processed);
//                         } else if (data.status === "complete") {
//                             showCompletion(data);
//                         } else if (data.status === "error") {
//                             showError(data);
//                         }
//                     });
//                     }
                                
//                 // Start processing
//                 processNextBatch("' . $jobId . '", 0);
//             });
//         </script>';
        
//         // Display progress bar
//         echo '<div id="sync-status"></div>
//               <div id="sync-progress" class="mt-3">
//                 <h5>Synchronizing organisation units...</h5>
//                 <div class="progress">
//                     <div id="sync-progress-bar" class="progress-bar" role="progressbar" style="width: 0%"></div>
//                 </div>
//                 <p id="sync-progress-text" class="mt-2">0 of ' . count($selectedOrgUnits) . ' units processed (0%)</p>
//               </div>
//               <div id="sync-controls" style="display: none;">
//                 <a href="settings.php?tab=load" class="btn btn-primary">Back to Import</a>
//               </div>';
//     } catch (Exception $e) {
//         $message = ['type' => 'error', 'text' => 'Error: ' . $e->getMessage()];
  
  
//     }

    
// }
//     // Handle new location creation
//     if ($activeTab == 'new') {
//         if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_location'])) {
//             $locationName = $_POST['location_name'] ?? null;
//             $locationPath = $_POST['location_path'] ?? null;



}
} catch (Exception $e) {
    $message = ['type' => 'error', 'text' => 'Error: ' . $e->getMessage()];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Locations</title>
    <link rel="icon" href="argon-dashboard-master/assets/img/brand/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-icons.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-svg.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/argon-dashboard.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/sweetalert2.min.css" rel="stylesheet">
    <style>
        .tree, .tree ul { list-style-type: none; padding-left: 20px; margin-bottom: 0; }
        .tree { padding-left: 0; }
        .tree-item { cursor: pointer; padding: 8px 12px; margin: 2px 0; border-radius: 4px; transition: all 0.2s; }
        .tree-item.has-children::before { content: '►'; margin-right: 5px; font-size: 0.8em; transition: transform 0.2s; }
        .tree-item.has-children.active::before { transform: rotate(90deg); }
        .tree-item:hover { background-color: #f8f9fa; }
        .nested { display: none; margin-left: 20px; border-left: 1px solid #dee2e6; padding-left: 15px; }
        .nested.active { display: block; }
        .tab-header { background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .form-control-label { font-weight: 600; margin-bottom: 8px; display: block; }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100">
    <?php include 'components/aside.php'; ?>
 
    
    <div class="main-content position-relative border-radius-lg">
        <?php include 'components/navbar.php'; ?>
           <!-- Page Title Section -->
        <div class="d-flex align-items-center flex-grow-1 py-3 px-2" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);">
            <nav aria-label="breadcrumb" class="flex-grow-1">
            <ol class="breadcrumb mb-1 navbar-breadcrumb" style="background: transparent;">
                <li class="breadcrumb-item">
                <a href="main" class="breadcrumb-link" style="color: #ffd700; font-weight: 600;">
                    <i class="fas fa-home me-1" style="color: #ffd700;"></i>Home
                </a>
                </li>
                <li class="breadcrumb-item active navbar-breadcrumb-active" aria-current="page" style="color: #fff; font-weight: 700;">
                <?= htmlspecialchars($pageTitle ?? 'Settings') ?>
                </li>
            </ol>
            <h4 class="navbar-title mb-0 mt-1" style="color: #fff; text-shadow: 0 1px 8px #1e3c72, 0 0 2px #ffd700; font-weight: 700;">
                <?= htmlspecialchars($pageTitle ?? 'Settings') ?>
            </h4>
            </nav>
        </div>

        <div class="container-fluid py-4">
            <!-- Tab Navigation -->
            <div class="nav-wrapper">
                <ul class="nav nav-pills nav-fill flex-column flex-md-row" id="tabs-text" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link mb-sm-3 mb-md-0 <?= ($activeTab == 'view') ? 'active' : '' ?>" 
                           href="?tab=view">Org-Unit Viewer</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link mb-sm-3 mb-md-0 <?= ($activeTab == 'load') ? 'active' : '' ?>" 
                           href="?tab=load">Org-Unit Importer</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link mb-sm-3 mb-md-0 <?= ($activeTab == 'new') ? 'active' : '' ?>" 
                           href="?tab=new">DHIS2-Programs-Fetcher</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link mb-sm-3 mb-md-0 <?= ($activeTab == 'questions') ? 'active' : '' ?>" 
                           href="?tab=questions">Mapping-Interface</a>
                    </li>
                </ul>
            </div>

            <!-- Display Messages -->
            <?php if (!empty($message)) : ?>
                <div class="alert alert-<?= $message['type'] == 'success' ? 'success' : 'danger' ?> mt-4">
                    <?= $message['text'] ?>
                </div>
            <?php endif; ?>

        
          
<!-- View Locations Tab Content (keeping the original) -->
<?php include 'dhis2/view.php'; ?>


<?php include 'dhis2/new.php'; ?>
<?php include 'dhis2/questions.php'; ?>
<?php include 'dhis2/load.php'; ?>
           
        </div>
    </div>

    <?php include 'components/fixednav.php'; ?>

    <!-- Core JS Files -->
    <script src="argon-dashboard-master/assets/js/core/popper.min.js"></script>
    <script src="argon-dashboard-master/assets/js/core/bootstrap.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/sweetalert2.all.min.js"></script>
    <script src="argon-dashboard-master/assets/js/argon-dashboard.js"></script>
    
    <script>
      document.addEventListener('DOMContentLoaded', function() {
    // Auto-submit form when instance is selected
    const instanceSelect = document.getElementById('dhis2InstanceSelect');
    if (instanceSelect) {
        instanceSelect.addEventListener('change', function() {
            if (this.value) {
                document.getElementById('dhis2LoadForm').submit();
            }
        });
    }
    
    // Initialize checkboxes with radio-like behavior
    const filterOptions = document.querySelectorAll('.filter-option');
    filterOptions.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            // Toggle org level section visibility
            if (this.id === 'useOrgLevel') {
                const orgLevelSection = document.getElementById('orgLevelSection');
                if (orgLevelSection) {
                    orgLevelSection.style.display = this.checked ? 'block' : 'none';
                }
            }
            
            // Radio-like behavior for filter options
            if (this.checked) {
                filterOptions.forEach(otherCheckbox => {
                    if (otherCheckbox !== this) {
                        otherCheckbox.checked = false;
                    }
                });
            }
        });
    });

    // Tree view functionality with dynamic loading
    const treeItems = document.querySelectorAll('.tree-item.has-children');
    treeItems.forEach(item => {
        item.addEventListener('click', function(e) {
            e.stopPropagation();
            toggleNode(this);
        });
    });

    // Expand first level by default
    const firstLevelItems = document.querySelectorAll('.tree > li > .tree-item.has-children');
    firstLevelItems.forEach(item => {
        item.classList.add('expanded');
        const nestedList = item.closest('li').querySelector('ul.subtree');
        if (nestedList) {
            nestedList.classList.add('show');
        }
    });

    // Confirmation for sync
    document.querySelector('button[name="sync_locations"]')?.addEventListener('click', function(e) {
        const checkedCount = document.querySelectorAll('.org-unit-checkbox:checked').length;
        if (checkedCount === 0) {
            e.preventDefault();
            Swal.fire({
                title: 'No Selection',
                text: 'Please select at least one organisation unit to sync',
                icon: 'warning'
            });
        }
    });
});

// Function to handle filter options change
function handleFilterChange(filterType) {
    const selectedCheckbox = document.getElementById(filterType === 'org_unit' ? 'useOrgUnit' : 
                           filterType === 'sub_units' ? 'userSubUnits' :
                           filterType === 'sub_x2_units' ? 'userSubX2Units' : 'useOrgLevel');
    
    if (selectedCheckbox) {
        selectedCheckbox.checked = true;
        const orgLevelSection = document.getElementById('orgLevelSection');
        if (orgLevelSection) {
            orgLevelSection.style.display = (filterType === 'org_level') ? 'block' : 'none';
        }
    }
}

// Function to check/uncheck all organization units
function checkAll(checked) {
    document.querySelectorAll('.org-unit-checkbox').forEach(checkbox => {
        checkbox.checked = checked;
    });
}

// Function to filter org units by search term
function filterOrgUnits(searchTerm) {
    searchTerm = searchTerm.toLowerCase().trim();
    document.querySelectorAll('#orgUnitTree li').forEach(li => {
        const text = li.textContent.toLowerCase();
        li.style.display = text.includes(searchTerm) ? '' : 'none';
    });
}

// Function to toggle organization unit tree nodes with dynamic loading
function toggleNode(element) {
    const parentLi = element.closest('li');
    const subtree = parentLi.querySelector('ul.subtree');
    const unitId = parentLi.querySelector('.org-unit-checkbox').value;
    
    if (subtree) {
        if (subtree.classList.contains('show')) {
            // Collapse if already expanded
            subtree.classList.remove('show');
            element.classList.remove('expanded');
        } else {
            // Check if subtree needs loading
            if (subtree.children.length === 0) {
                loadChildren(unitId, element);
            } else {
                // Just show existing children
                subtree.classList.add('show');
                element.classList.add('expanded');
            }
        }
    }
}

// Function to dynamically load children
function loadChildren(parentId, element) {
    const loadingSpan = document.createElement('span');
    loadingSpan.className = 'loading-indicator ms-2';
    loadingSpan.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    element.appendChild(loadingSpan);
    
    fetch('get_org_units.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `parent_id=${parentId}&instance=${document.getElementById('dhis2InstanceSelect').value}`
    })
    .then(response => response.json())
    .then(data => {
        element.removeChild(loadingSpan);
        
        if (data.success && data.children?.length > 0) {
            const subtree = element.closest('li').querySelector('ul.subtree');
            subtree.innerHTML = '';
            
            data.children.forEach(child => {
                const childLi = document.createElement('li');
                childLi.innerHTML = `
                    <div class="d-flex align-items-center mb-1">
                        <input type="checkbox" name="selected_orgunits[]" 
                               value="${child.id}" class="org-unit-checkbox me-2">
                        <span class="tree-item ${child.hasChildren ? 'has-children' : ''}" 
                              onclick="${child.hasChildren ? 'toggleNode(this)' : ''}">
                            <i class="fas fa-folder me-1"></i>
                            ${child.name}
                            <small class="text-muted">(${child.id})</small>
                        </span>
                    </div>
                    ${child.hasChildren ? '<ul class="subtree" style="display:none;"></ul>' : ''}
                `;
                subtree.appendChild(childLi);
            });
            
            subtree.classList.add('show');
            element.classList.add('expanded');
        } else {
            element.classList.remove('has-children');
        }
    })
    .catch(error => {
        console.error('Error loading children:', error);
        element.removeChild(loadingSpan);
        Swal.fire({
            title: 'Error',
            text: 'Failed to load child units. Please try again.',
            icon: 'error'
        });
    });
}
    </script>
</body>
</html>