<?php if ($activeTab == 'load') : ?>
    <div class="tab-header">
        <h3><i class="fas fa-sync-alt me-2"></i> Load from DHIS2</h3>
    </div>
    
    <div class="card">
        <div class="card-body">
            <form method="post" action="?tab=load" id="dhis2LoadForm">
                <div class="row mb-4">
                    <div class="col-md-8">
                        <div class="form-group">
                            <label class="form-control-label">Select DHIS2 Instance</label>
                            <select name="dhis2_instance" class="form-control" id="dhis2InstanceSelect" onchange="this.form.submit()">
                                <option value="">-- Select Instance --</option>
                                <?php 
                                $jsonConfig = json_decode(file_get_contents('dhis2/dhis2.json'), true);
                                foreach ($jsonConfig as $key => $config) : ?>
                                    <option value="<?= $key ?>" <?= ($selectedInstance == $key) ? 'selected' : '' ?>>
                                        <?= $key ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <?php if ($selectedInstance) : ?>
                <div class="row mb-3">
                    <div class="col-12">
                        <h5>Select Org Unit By</h5>
                    </div>
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="form-check">
                            <input class="form-check-input filter-option" type="checkbox" id="useOrgUnit" 
                                   name="use_org_unit" <?= $useOrgUnit ? 'checked' : '' ?>
                                   onchange="handleFilterChange('org_unit')">
                            <label class="form-check-label" for="useOrgUnit">Use Org Unit</label>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="form-check">
                            <input class="form-check-input filter-option" type="checkbox" id="userSubUnits" 
                                   name="user_sub_units" <?= $useSubUnits ? 'checked' : '' ?>
                                   onchange="handleFilterChange('sub_units')">
                            <label class="form-check-label" for="userSubUnits">User sub-units</label>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="form-check">
                            <input class="form-check-input filter-option" type="checkbox" id="userSubX2Units" 
                                   name="user_sub_x2_units" <?= $useSubX2Units ? 'checked' : '' ?>
                                   onchange="handleFilterChange('sub_x2_units')">
                            <label class="form-check-label" for="userSubX2Units">User sub x 2 units</label>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="form-check">
                            <input class="form-check-input filter-option" type="checkbox" id="useOrgLevel" 
                                   name="use_org_level" <?= $useOrgLevel ? 'checked' : '' ?>
                                   onchange="handleFilterChange('org_level')">
                            <label class="form-check-label" for="useOrgLevel">Use Org Level</label>
                        </div>
                    </div>
                </div>
                
                <div class="row mb-4" id="orgLevelSection" style="display: <?= $useOrgLevel ? 'block' : 'none' ?>;">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-control-label">Select Level</label>
                            <select name="org_level" class="form-control">
                                <option value="">-- Select Level --</option>
                                <?php 
                                if (!empty($orgUnitLevels)):
                                    foreach ($orgUnitLevels as $level => $name): ?>
                                        <option value="<?= $level ?>" <?= ($selectedLevel == $level) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($name) ?> (Level <?= $level ?>)
                                        </option>
                                    <?php endforeach;
                                endif; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="text-center mt-4">
                    <button type="submit" class="btn btn-primary" name="fetch_orgunits" id="fetchOrgUnitsBtn">
                        <i class="fas fa-search me-2"></i> Fetch Org Units
                    </button>
                </div>
                <?php endif; ?>
            </form>
            
            <!-- Display fetched org units in paginated table -->
            <?php if (!empty($orgUnits) && isset($_POST['fetch_orgunits'])) : ?>
                <?php
                // Get all units from DHIS2
                $allUnits = $orgUnits['organisationUnits'];
                $totalUnits = count($allUnits);
                
                // Group units into chunks of 500
                $unitsPerGroup = 500;
                $displayPerGroup = 11; // Number to display from each group
                $totalGroups = ceil($totalUnits / $unitsPerGroup);
                $currentGroup = 1;
                
                // Get current group's units
                $currentGroupStart = ($currentGroup - 1) * $unitsPerGroup;
                $currentGroupUnits = array_slice($allUnits, $currentGroupStart, $unitsPerGroup);
                $displayUnits = array_slice($currentGroupUnits, 0, $displayPerGroup);
                ?>
                
                <div class="mt-5">
                    <h4 class="mb-3">ORGANISATION UNITS</h4>
                    <form method="post" action="?tab=load" id="orgUnitsForm">
                        <input type="hidden" name="dhis2_instance" value="<?= $selectedInstance ?>">
                        <input type="hidden" name="total_units" id="totalUnits" value="<?= $totalUnits ?>">
                        <input type="hidden" name="all_units" id="allUnits" value="<?= htmlspecialchars(json_encode($allUnits)) ?>">
                        <input type="hidden" name="selected_action" id="selectedAction" value="">
                        <?php if ($useOrgLevel && $selectedLevel): ?>
                            <input type="hidden" name="org_level" value="<?= $selectedLevel ?>">
                        <?php endif; ?>
                        
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between mb-3">
                                    <div>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllUnits()">
                                            Deselect All
                                        </button>
                                    </div>
                                    <div class="text-muted">
                                        <span id="totalCount"><?= $totalUnits ?></span> units total
                                    </div>
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="table table-striped" id="orgUnitsTable">
                                        <thead>
                                            <tr>
                                                <th width="40">
                                                    <input type="checkbox" id="selectDisplayedCheckbox" onchange="toggleSelectDisplayed(this)">
                                                </th>
                                                <th>Name</th>
                                                <th>UID</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($displayUnits as $unit) : ?>
                                                <tr>
                                                    <td>
                                                        <input type="checkbox" name="displayed_units[]" 
                                                               value="<?= $unit['id'] ?>" class="org-unit-checkbox displayed-unit">
                                                    </td>
                                                    <td><?= htmlspecialchars($unit['name']) ?></td>
                                                    <td><?= $unit['id'] ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center mt-3">
                                    <div class="text-muted">
                                        Showing <span id="displayedCount"><?= count($displayUnits) ?></span> of 
                                        <span id="groupCount"><?= count($currentGroupUnits) ?></span> units in Group <?= $currentGroup ?>
                                    </div>
                                    
                                    <div id="paginationControls">
                                        <div class="d-flex align-items-center">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                    onclick="changeGroup(<?= $currentGroup - 1 ?>)" id="prevGroupBtn" disabled>
                                                Previous Group
                                            </button>
                                            <span class="mx-3" id="currentGroup">Group <?= $currentGroup ?> of <?= $totalGroups ?></span>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                    onclick="changeGroup(<?= $currentGroup + 1 ?>)" id="nextGroupBtn" 
                                                    <?= $totalGroups <= 1 ? 'disabled' : '' ?>>
                                                Next Group
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <button type="button" class="btn btn-primary btn-sm me-2" 
                                                onclick="selectCurrentGroup()">
                                            Select This Group (<?= count($currentGroupUnits) ?>)
                                        </button>
                                        <button type="button" class="btn btn-success btn-sm" 
                                                onclick="selectAllGroups()" id="selectAllGroupsBtn">
                                            Select All Groups (<?= $totalUnits ?>)
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="text-center mt-4">
                            <div class="alert alert-info" id="selectionSummary" style="display: none;">
                                <span id="selectedCount">0</span> units selected for loading
                            </div>
                            <button type="submit" name="sync_locations" class="btn btn-success btn-lg" id="loadLocationBtn">
                                <i class="fas fa-upload me-2"></i> Load Location Table
                            </button>
                        </div>
                    </form>
                </div>
                
                <script>
                // Store all org units in JavaScript
                const allOrgUnits = <?= json_encode($allUnits) ?>;
                const unitsPerGroup = 500;
                const displayPerGroup = 11;
                const totalGroups = Math.ceil(allOrgUnits.length / unitsPerGroup);
                let currentGroup = 1;
                let selectedUnits = new Set();
                
                // Function to update the table with current group's data
                function updateTable(group) {
                    const startIndex = (group - 1) * unitsPerGroup;
                    const endIndex = Math.min(startIndex + unitsPerGroup, allOrgUnits.length);
                    const groupUnits = allOrgUnits.slice(startIndex, endIndex);
                    const displayUnits = groupUnits.slice(0, displayPerGroup);
                    
                    // Update table body
                    const tbody = document.querySelector('#orgUnitsTable tbody');
                    tbody.innerHTML = '';
                    
                    displayUnits.forEach(unit => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td>
                                <input type="checkbox" name="displayed_units[]" 
                                       value="${unit.id}" class="org-unit-checkbox displayed-unit"
                                       ${selectedUnits.has(unit.id) ? 'checked' : ''}>
                            </td>
                            <td>${unit.name}</td>
                            <td>${unit.id}</td>
                        `;
                        tbody.appendChild(row);
                    });
                    
                    // Update pagination controls
                    document.getElementById('currentGroup').textContent = `Group ${group} of ${totalGroups}`;
                    document.getElementById('prevGroupBtn').disabled = group <= 1;
                    document.getElementById('nextGroupBtn').disabled = group >= totalGroups;
                    
                    // Update counts
                    document.getElementById('displayedCount').textContent = displayUnits.length;
                    document.getElementById('groupCount').textContent = groupUnits.length;
                    
                    // Update select all checkbox for displayed units
                    const allDisplayedChecked = displayUnits.every(unit => selectedUnits.has(unit.id));
                    document.getElementById('selectDisplayedCheckbox').checked = allDisplayedChecked;
                    
                    currentGroup = group;
                }
                
                // Change to specific group
                function changeGroup(group) {
                    if (group < 1 || group > totalGroups) return;
                    updateTable(group);
                }
                
                // Select all currently displayed units (11)
                function toggleSelectDisplayed(checkbox) {
                    const checkboxes = document.querySelectorAll('.displayed-unit');
                    checkboxes.forEach(cb => {
                        cb.checked = checkbox.checked;
                        if (checkbox.checked) {
                            selectedUnits.add(cb.value);
                        } else {
                            selectedUnits.delete(cb.value);
                        }
                    });
                    
                    updateSelectionSummary();
                }
                
                // Select all units in current group (500)
                function selectCurrentGroup() {
                    const startIndex = (currentGroup - 1) * unitsPerGroup;
                    const endIndex = Math.min(startIndex + unitsPerGroup, allOrgUnits.length);
                    const groupUnits = allOrgUnits.slice(startIndex, endIndex);
                    
                    // Add all units from this group to selected set
                    groupUnits.forEach(unit => {
                        selectedUnits.add(unit.id);
                    });
                    
                    // Update checkboxes in current display
                    document.querySelectorAll('.displayed-unit').forEach(checkbox => {
                        checkbox.checked = true;
                    });
                    
                    // Update the "select all displayed" checkbox
                    document.getElementById('selectDisplayedCheckbox').checked = true;
                    
                    updateSelectionSummary();
                    showAlert(`Added ${groupUnits.length} units from Group ${currentGroup} to selection`);
                }
                
                // Select all units from all groups
                function selectAllGroups() {
                    allOrgUnits.forEach(unit => {
                        selectedUnits.add(unit.id);
                    });
                    
                    // Update all checkboxes in current display
                    document.querySelectorAll('.displayed-unit').forEach(checkbox => {
                        checkbox.checked = true;
                    });
                    
                    // Update the "select all displayed" checkbox
                    document.getElementById('selectDisplayedCheckbox').checked = true;
                    
                    updateSelectionSummary();
                    showAlert(`Added all ${allOrgUnits.length} units to selection`);
                }
                
                // Deselect all units
                function deselectAllUnits() {
                    selectedUnits.clear();
                    
                    // Update all checkboxes in current display
                    document.querySelectorAll('.displayed-unit').forEach(checkbox => {
                        checkbox.checked = false;
                    });
                    
                    // Update the "select all displayed" checkbox
                    document.getElementById('selectDisplayedCheckbox').checked = false;
                    
                    updateSelectionSummary();
                    showAlert('Cleared all selections');
                }
                
                // Update the selection summary display
                function updateSelectionSummary() {
                    const summary = document.getElementById('selectionSummary');
                    const countSpan = document.getElementById('selectedCount');
                    
                    countSpan.textContent = selectedUnits.size;
                    
                    if (selectedUnits.size > 0) {
                        summary.style.display = 'block';
                    } else {
                        summary.style.display = 'none';
                    }
                }
                
                // Show temporary alert message
                function showAlert(message) {
                    const alert = document.createElement('div');
                    alert.className = 'alert alert-info alert-dismissible fade show';
                    alert.style.position = 'fixed';
                    alert.style.bottom = '20px';
                    alert.style.right = '20px';
                    alert.style.zIndex = '1000';
                    alert.innerHTML = `
                        ${message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    `;
                    
                    document.body.appendChild(alert);
                    
                    // Auto-remove after 3 seconds
                    setTimeout(() => {
                        alert.remove();
                    }, 3000);
                }
                
                // Before form submission, add all selected units to hidden input
                document.getElementById('orgUnitsForm').addEventListener('submit', function(e) {
                    if (selectedUnits.size === 0) {
                        e.preventDefault();
                        showAlert('Please select at least one unit to load');
                        return;
                    }
                    
                    // Create hidden input with all selected units
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'selected_orgunits_json';
                    hiddenInput.value = JSON.stringify(Array.from(selectedUnits));
                    this.appendChild(hiddenInput);
                });
                
                // Initialize the table
                document.addEventListener('DOMContentLoaded', function() {
                    updateTable(1);
                });
                </script>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>