<?php
session_start();
require 'connect.php';

$regionId = $_GET['id'] ?? 0;

// Get region details
$stmt = $pdo->prepare("SELECT * FROM location WHERE id = ?");
$stmt->execute([$regionId]);
$region = $stmt->fetch(PDO::FETCH_ASSOC);

// Get parent chain for breadcrumbs
function getParentChain($pdo, $regionId) {
    $chain = [];
    while ($regionId) {
        $stmt = $pdo->prepare("SELECT id, name, parent_id FROM location WHERE id = ?");
        $stmt->execute([$regionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            array_unshift($chain, $row);
            $regionId = $row['parent_id'];
        } else {
            break;
        }
    }
    return $chain;
}
$breadcrumbs = getParentChain($pdo, $regionId);

// Get child locations
function getChildLocations($pdo, $parentId) {
    $query = "SELECT id, uid, name, path, hierarchylevel, parent_id FROM location 
              WHERE parent_id = ? ORDER BY name";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$parentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Build the tree view recursively
function buildTreeView($pdo, $parentId) {
    $html = '';
    $children = getChildLocations($pdo, $parentId);

    if (!empty($children)) {
        $html .= '<ul class="tree">';
        foreach ($children as $child) {
            $hasChildren = !empty(getChildLocations($pdo, $child['id']));
            $html .= '<li>';
            $html .= '<span class="tree-item' . ($hasChildren ? ' has-children' : '') . '" tabindex="0" aria-expanded="false" data-id="' . $child['id'] . '">';
            $html .= $hasChildren
                ? '<i class="fas fa-chevron-right tree-toggle"></i> '
                : '<i class="fas fa-circle-dot tree-leaf"></i> ';
            $html .= htmlspecialchars($child['name']) . ' <small class="text-muted">(' . $child['uid'] . ')</small>';
            $html .= '</span>';
            $html .= buildTreeView($pdo, $child['id']);
            $html .= '</li>';
        }
        $html .= '</ul>';
    }

    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin - Locations</title>
<!-- Favicon -->
<link rel="icon" href="argon-dashboard-master/assets/img/brand/favicon.png" type="image/png">
<!-- Fonts -->
<link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet">
<!-- Icons -->
<link href="argon-dashboard-master/assets/css/nucleo-icons.css" rel="stylesheet">
<link href="argon-dashboard-master/assets/css/nucleo-svg.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<!-- Argon CSS -->
<link href="argon-dashboard-master/assets/css/argon-dashboard.css" rel="stylesheet">
<!-- Sweet Alert -->
<link href="argon-dashboard-master/assets/css/sweetalert2.min.css" rel="stylesheet">
<style>
/* --- Tree View Styling --- */
.tree {
    list-style: none;
    padding-left: 1.5em;
    margin-bottom: 0;
}
.tree > li {
    margin: 0.2em 0;
}
.tree-item {
    cursor: pointer;
    display: flex;
    align-items: center;
    padding: 0.2em 0.5em;
    border-radius: 4px;
    transition: background 0.2s;
    outline: none;
}
.tree-item:hover, .tree-item:focus {
    background: #f0f4f8;
}
.tree-item .fa-chevron-right,
.tree-item .fa-chevron-down {
    margin-right: 0.5em;
    font-size: 0.9em;
    transition: transform 0.2s;
}
.tree-item .fa-circle-dot {
    margin-right: 0.7em;
    color: #adb5bd;
    font-size: 0.7em;
}
.tree ul {
    display: none;
    margin-left: 1.2em;
    border-left: 1px solid #e9ecef;
    padding-left: 0.7em;
}
.tree ul.active {
    display: block;
}
.tree-item.active {
    background: #e9ecef;
    font-weight: 600;
}
.tree-item.has-children .fa-chevron-right {
    transform: rotate(0deg);
}
.tree-item.has-children.active .fa-chevron-right {
    transform: rotate(90deg);
}
.breadcrumb {
    background: none;
    padding: 0;
    margin-bottom: 1.2em;
    font-size: 1.05em;
}
.breadcrumb-item + .breadcrumb-item::before {
    content: "›";
    color: #adb5bd;
    padding: 0 0.5em;
}
.breadcrumb-item.active {
    color: #5e72e4;
    font-weight: 600;
}
</style>
</head>
<body class="g-sidenav-show bg-gray-100">
<?php include 'components/aside.php'; ?>

<div class="main-content position-relative border-radius-lg">
    <?php include 'components/navbar.php'; ?>
    <div class="container-fluid py-4">
        <div class="card">
            <div class="card-header bg-light">
                <div class="row align-items-center">
                    <div class="col">
                        <h4 class="mb-0"><?= htmlspecialchars($region['name']) ?> Hierarchy</h4>
                    </div>
                    <div class="col-auto">
                        <a href="settings.php?tab=view" class="btn btn-sm btn-secondary">
                            <i class="fas fa-arrow-left me-1"></i> Back to Regions
                        </a>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <!-- Breadcrumb Navigation -->
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="settings.php?tab=view">Regions</a></li>
                        <?php foreach ($breadcrumbs as $i => $crumb): ?>
                            <?php if ($i === count($breadcrumbs) - 1): ?>
                                <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($crumb['name']) ?></li>
                            <?php else: ?>
                                <li class="breadcrumb-item">
                                    <a href="view_region.php?id=<?= $crumb['id'] ?>"><?= htmlspecialchars($crumb['name']) ?></a>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ol>
                </nav>
                <!-- Tree View -->
                <div id="locationTree">
                    <?= buildTreeView($pdo, $regionId) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tree View Interactivity -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Expand root tree by default
    document.querySelectorAll('#locationTree > ul.tree').forEach(function(root) {
        root.classList.add('active');
    });

    // Handle expand/collapse
    document.querySelectorAll('.tree-item.has-children').forEach(function(item) {
        item.addEventListener('click', function(e) {
            e.stopPropagation();
            const nestedList = this.parentElement.querySelector('ul.tree');
            if (nestedList) {
                nestedList.classList.toggle('active');
                this.classList.toggle('active');
                // Update ARIA
                this.setAttribute('aria-expanded', this.classList.contains('active') ? 'true' : 'false');
            }
            // Toggle chevron icon
            const icon = this.querySelector('.fa-chevron-right');
            if (icon) {
                icon.classList.toggle('fa-chevron-down', this.classList.contains('active'));
                icon.classList.toggle('fa-chevron-right', !this.classList.contains('active'));
            }
        });
        // Keyboard accessibility
        item.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.click();
            }
        });
    });
});
</script>
</body>
</html>