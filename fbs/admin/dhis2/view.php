<?php if ($activeTab == 'view') : ?>

<style>
    /* Specific styles for the tree view */
    .org-unit-tree-list {
        padding-left: 1.5rem; /* Indentation for nested lists */
        margin-bottom: 0;
    }
    .org-unit-tree-list li {
        margin-bottom: 0.25rem;
    }
    .org-unit-node {
        padding: 0.5rem 0.25rem;
        border-radius: 0.375rem;
        background-color: rgba(255, 255, 255, 0.05); /* Slight background for each node */
        transition: background-color 0.2s ease;
    }
    .org-unit-node:hover {
        background-color: rgba(255, 255, 255, 0.1);
    }
    .org-unit-node span {
        color: #1e293b; /* Dark text for OU names */
        font-weight: 500;
        flex-grow: 1; /* Allows name to take available space */
    }
    .org-unit-node small {
        color: #64748b !important; /* Muted text for UID and level */
        font-size: 0.8em;
        margin-left: 0.5rem;
    }

    /* Tree Toggle Icons */
    .tree-toggle {
        cursor: pointer;
        transition: transform 0.2s ease;
        color: #3b82f6; /* Blue color for expand/collapse icon */
        font-size: 0.8em;
        width: 1.5rem; /* Fixed width to prevent jumping */
        text-align: center;
    }
    .tree-toggle[aria-expanded="true"] {
        transform: rotate(90deg); /* Rotate icon when expanded */
    }
    .fa-circle-dot { /* Style for leaf nodes (no children) */
        color: #64748b; /* Muted color */
        font-size: 0.6em;
        width: 1.5rem; /* Align with toggles */
        text-align: center;
    }

    /* Accordion Customization for Instance Keys */
    .accordion-item {
        background-color: #ffffff; /* White background for accordion items */
        border: 1px solid #e2e8f0; /* Light border */
        margin-bottom: 1rem;
        border-radius: 0.5rem;
        overflow: hidden; /* Ensures border-radius applies to children */
    }
    .accordion-header {
        background-color: #f8fafc; /* Light header for accordion */
        padding: 0.75rem 1.25rem;
        border-bottom: 1px solid #e2e8f0;
    }
    .accordion-button {
        background-color: transparent !important;
        color: #1e293b !important;
        font-weight: 600;
        font-size: 1.1rem;
        padding: 0.75rem 1.25rem;
        transition: all 0.2s ease;
    }
    .accordion-button:not(.collapsed) {
        color: #3b82f6 !important; /* Blue for expanded header */
        background-color: #f8fafc !important; /* Keep light background */
        box-shadow: none;
        border-bottom: 1px solid #3b82f6; /* Blue line under expanded header */
    }
    .accordion-button::after {
        filter: none; /* No inversion for light background */
    }
    .accordion-body {
        padding: 1.25rem;
        color: #1e293b; /* Dark text color in body */
    }

    /* Specific table styles (if you decide to keep the flat table elsewhere) */
    .table-hover tbody tr:hover {
        background-color: #f1f5f9; /* Light hover for light tables */
    }
</style>

<div class="tab-header mb-4">
    <h3 class="text-dark"><i class="fas fa-sitemap me-2 text-primary"></i> Organization Unit Hierarchy Viewer</h3>
</div>

<div class="card futuristic-card shadow-lg">
    <div class="card-header bg-light py-3">
        <h4 class="mb-0 text-dark">
            <i class="fas fa-globe me-2 text-info"></i>
            Locations by DHIS2 Instance
        </h4>
    </div>
    <div class="card-body">
        <?php
        // 1. Fetch all locations
        // Order by instance_key, then hierarchylevel to ensure parents are processed before children,
        // then path for consistent alphabetical ordering within levels.
        $stmt = $pdo->query("SELECT id, instance_key, uid, name, path, hierarchylevel, parent_id FROM location ORDER BY instance_key, hierarchylevel ASC, path ASC");
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Organize into a hierarchical structure grouped by instance_key
        $groupedLocations = [];
        $locationMap = []; // Temporary map to hold references and build tree

        // First pass: Store all locations in a map by ID and initialize children array
        foreach ($locations as $loc) {
            $locationMap[$loc['id']] = $loc;
            $locationMap[$loc['id']]['children'] = [];
        }

        // Second pass: Build the parent-child relationships
        foreach ($locations as $loc) {
            if ($loc['parent_id'] !== null && isset($locationMap[$loc['parent_id']])) {
                // If a location has a parent_id and parent exists in map, add it as a child
                $locationMap[$loc['parent_id']]['children'][] = &$locationMap[$loc['id']];
            } else {
                // If no parent_id or parent not found (e.g., top-level unit or parent not fetched),
                // add it as a top-level unit for its instance_key
                if (!isset($groupedLocations[$loc['instance_key']])) {
                    $groupedLocations[$loc['instance_key']]['top_level_units'] = [];
                }
                $groupedLocations[$loc['instance_key']]['top_level_units'][] = &$locationMap[$loc['id']];
            }
        }
        // Unset the map to free memory and break references
        unset($locationMap);

        // 3. Recursive function to render the tree HTML
        function renderOrgUnitTree($orgUnits) {
            if (empty($orgUnits)) {
                return ''; // Return empty string if no units to render
            }
            $html = '<ul class="org-unit-tree-list list-unstyled">';
            foreach ($orgUnits as $unit) {
                $html .= '<li>';
                $html .= '<div class="d-flex align-items-center org-unit-node">';
                $hasChildren = !empty($unit['children']);

                // Toggle icon for nodes with children, or a dot for leaf nodes
                if ($hasChildren) {
                    $html .= '<i class="fas fa-chevron-right tree-toggle me-2" data-bs-toggle="collapse" data-bs-target="#collapse-' . $unit['id'] . '" aria-expanded="false" aria-controls="collapse-' . $unit['id'] . '"></i>';
                } else {
                    $html .= '<i class="fas fa-circle-dot fa-xs text-muted me-2" style="font-size: 0.7em; width: 1.5rem; text-align: center;"></i>'; // Dot for leaf nodes
                }

                $html .= '<span>' . htmlspecialchars($unit['name']);
                $html .= ' <small class="text-muted">(L' . htmlspecialchars($unit['hierarchylevel']) . ' - UID: ' . htmlspecialchars($unit['uid']) . ')</small></span>';
     
                $html .= '</div>'; // End org-unit-node

                // Recursively render children in a collapsible div
                if ($hasChildren) {
                    $html .= '<div class="collapse" id="collapse-' . $unit['id'] . '">';
                    $html .= renderOrgUnitTree($unit['children']);
                    $html .= '</div>';
                }
                $html .= '</li>';
            }
            $html .= '</ul>';
            return $html;
        }

        // Check if any locations are found
        if (!empty($groupedLocations)) :
        ?>
            <div class="accordion" id="dhis2InstanceAccordion">
                <?php foreach ($groupedLocations as $instanceKey => $instanceData) : ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="heading<?= htmlspecialchars($instanceKey) ?>">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?= htmlspecialchars($instanceKey) ?>" aria-expanded="false" aria-controls="collapse<?= htmlspecialchars($instanceKey) ?>">
                                <i class="fas fa-cube me-2 text-warning"></i>
                                DHIS2 Instance: <?= htmlspecialchars($instanceKey) ?>
                                <span class="badge bg-secondary ms-3"><?= count($instanceData['top_level_units']) ?> Top-Level Units</span>
                            </button>
                        </h2>
                        <div id="collapse<?= htmlspecialchars($instanceKey) ?>" class="accordion-collapse collapse" aria-labelledby="heading<?= htmlspecialchars($instanceKey) ?>" data-bs-parent="#dhis2InstanceAccordion">
                            <div class="accordion-body">
                                <?php
                                if (!empty($instanceData['top_level_units'])) {
                                    echo renderOrgUnitTree($instanceData['top_level_units']);
                                } else {
                                    echo '<p class="text-muted">No top-level organization units found for this instance.</p>';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <div class="text-center py-5">
                <i class="fas fa-map-marker-alt fa-3x text-muted mb-3"></i>
                <h4 class="text-dark">No locations found</h4>
                <p class="text-muted">Please load metadata from DHIS2 to populate organization units.</p>
                <a href="?tab=load" class="btn btn-primary mt-3">
                    <i class="fas fa-sync-alt me-2"></i> Load Metadata
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>