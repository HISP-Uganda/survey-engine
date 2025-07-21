<?php
// Ensure dhis2_shared.php is included as it contains the database logic for instances
// This is already stated as being included in settings.php, so no explicit include here
// is necessary if settings.php handles it. If not, add:
// require_once 'dhis2_shared.php';

if ($activeTab == 'load') :
?>
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
                                // Establish database connection within the scope of load.php
                                // This assumes you have a database connection setup that can be reused
                                // or you establish a new one here. For simplicity, I'm using
                                // the connection details from dhis2_shared.php.
                                $dbHost = 'localhost';
                                $dbUser = 'root';
                                $dbPass = 'root';
                                $dbName = 'fbtv3';

                                $mysqli = @new mysqli($dbHost, $dbUser, $dbPass, $dbName);

                                if ($mysqli->connect_errno) {
                                    echo "<option value=\"\" disabled>Error connecting to database: " . $mysqli->connect_error . "</option>";
                                } else {
                                    $sql = "SELECT `key`, description FROM dhis2_instances WHERE status = 1 ORDER BY `key` ASC";
                                    $result = $mysqli->query($sql);

                                    if ($result) {
                                        while ($row = $result->fetch_assoc()) {
                                            $instanceKey = htmlspecialchars($row['key']);
                                            $instanceDescription = htmlspecialchars($row['description']);
                                            $selected = ($selectedInstance == $instanceKey) ? 'selected' : '';
                                            echo "<option value=\"{$instanceKey}\" {$selected}>{$instanceDescription} ({$instanceKey})</option>";
                                        }
                                        $result->free();
                                    } else {
                                        echo "<option value=\"\" disabled>Error fetching instances: " . $mysqli->error . "</option>";
                                    }
                                    $mysqli->close();
                                }
                                ?>
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
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input filter-option" type="checkbox" id="useOrgLevel" name="use_org_level" <?= $useOrgLevel ? 'checked' : '' ?> onchange="handleFilterChange('org_level')">
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
                                    // $orgUnitLevels would need to be populated from the selected DHIS2 instance
                                    // This typically involves an API call to the DHIS2 instance, which would be handled
                                    // in your settings.php or a dedicated processing script before load.php is rendered.
                                    // For this refactor, we assume $orgUnitLevels is already available if $selectedInstance is set.
                                    if (!empty($orgUnitLevels)) :
                                        foreach ($orgUnitLevels as $level => $name) : ?>
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

            <?php if (!empty($orgUnits) && isset($_POST['fetch_orgunits'])) : ?>
                <div class="mt-5">
                    <h4 class="mb-3">ORGANISATION UNITS</h4>
                    <form method="post" action="?tab=load" id="orgUnitsForm">
                        <input type="hidden" name="dhis2_instance" value="<?= $selectedInstance ?>">
                        <input type="hidden" name="total_units" id="totalUnits" value="<?= count($orgUnits['organisationUnits']) ?>">
                        <input type="hidden" name="selection_type" id="selectionType" value="page">
                        <?php if ($useOrgLevel && $selectedLevel) : ?>
                            <input type="hidden" name="org_level" value="<?= $selectedLevel ?>">
                        <?php endif; ?>

                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between mb-3">
                                    <div>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectCurrentGroup()">
                                            Select Group
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="selectAllGroups()">
                                            Select All Groups
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="deselectAll()">
                                            Deselect All
                                        </button>
                                    </div>
                                    <div class="text-muted">
                                        <span id="totalCount"><?= count($orgUnits['organisationUnits']) ?></span> total units
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-striped" id="orgUnitsTable">
                                        <thead>
                                            <tr>
                                                <th width="40">
                                                    <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)">
                                                </th>
                                                <th>Name</th>
                                                <th>UID</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            // Display first 500 units initially
                                            $unitsPerPage = 500;
                                            $totalUnits = count($orgUnits['organisationUnits']);
                                            $totalPages = ceil($totalUnits / $unitsPerPage);
                                            $currentPage = 1;

                                            // Just display first 10 for initial view - will be replaced by JS
                                            $displayUnits = array_slice($orgUnits['organisationUnits'], 0, 10);

                                            foreach ($displayUnits as $unit) : ?>
                                                <tr>
                                                    <td>
                                                        <input type="checkbox" name="selected_orgunits[]" value="<?= $unit['id'] ?>" class="org-unit-checkbox">
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
                                        Showing <span id="itemsPerPage">10</span> of <span id="currentGroupSize">500</span> units in group
                                    </div>

                                    <div id="paginationControls">
                                        <div class="d-flex align-items-center">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="changePage(1)" id="firstPageBtn" disabled>
                                                << </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="previousPage()" id="prevPageBtn" disabled>
                                                        < </button>
                                                            <span class="mx-2" id="currentPage">Group 1 of <?= $totalPages ?></span>
                                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="nextPage()" id="nextPageBtn" <?= $totalPages <= 1 ? 'disabled' : '' ?>>
                                                                > </button>
                                                                    <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="changePage(<?= $totalPages ?>)" id="lastPageBtn" <?= $totalPages <= 1 ? 'disabled' : '' ?>>
                                                                        >>
                                                                    </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="text-center mt-4">
                            <button type="submit" name="sync_locations" class="btn btn-success btn-lg">
                                <i class="fas fa-upload me-2"></i> Load Location Table
                            </button>
                        </div>
                    </form>
                </div>

                <script>
                    // Store all org units in JavaScript for pagination
                    const allOrgUnits = <?= json_encode($orgUnits['organisationUnits']) ?>;
                    const unitsPerGroup = 500; // 500 units per group
                    const unitsToDisplay = 10; // Show 10 units on screen at a time for performance
                    let currentPage = 1;
                    const totalPages = Math.ceil(allOrgUnits.length / unitsPerGroup);

                    // Track which units are selected (by ID)
                    let selectedUnits = new Set();
                    let selectionType = "none"; // "none", "group", "all"

                    function updateTable(page) {
                        const startGroupIndex = (page - 1) * unitsPerGroup;
                        const endGroupIndex = Math.min(startGroupIndex + unitsPerGroup, allOrgUnits.length);
                        const currentGroupUnits = allOrgUnits.slice(startGroupIndex, endGroupIndex);

                        // For display, just show the first few units of this group
                        const displayUnits = currentGroupUnits.slice(0, unitsToDisplay);

                        // Update table body
                        const tbody = document.querySelector('#orgUnitsTable tbody');
                        tbody.innerHTML = '';

                        displayUnits.forEach(unit => {
                            const row = document.createElement('tr');
                            const isSelected = selectedUnits.has(unit.id);
                            row.innerHTML = `
                                <td>
                                    <input type="checkbox" name="selected_orgunits[]"
                                           value="${unit.id}" class="org-unit-checkbox"
                                           ${isSelected ? 'checked' : ''}>
                                </td>
                                <td>${escapeHtml(unit.name)}</td>
                                <td>${unit.id}</td>
                            `;
                            tbody.appendChild(row);
                        });

                        // Update pagination controls
                        document.getElementById('currentPage').textContent = `Group ${page} of ${totalPages}`;
                        document.getElementById('firstPageBtn').disabled = page <= 1;
                        document.getElementById('prevPageBtn').disabled = page <= 1;
                        document.getElementById('nextPageBtn').disabled = page >= totalPages;
                        document.getElementById('lastPageBtn').disabled = page >= totalPages;

                        // Update items count text
                        document.getElementById('itemsPerPage').textContent = displayUnits.length;
                        document.getElementById('currentGroupSize').textContent = currentGroupUnits.length;

                        currentPage = page;

                        // Update the select all checkbox based on current group selection status
                        updateSelectAllCheckbox();
                    }

                    function escapeHtml(text) {
                        const div = document.createElement('div');
                        div.textContent = text;
                        return div.innerHTML;
                    }

                    function changePage(page) {
                        if (page < 1 || page > totalPages) return;
                        updateTable(page);
                    }

                    function nextPage() {
                        changePage(currentPage + 1);
                    }

                    function previousPage() {
                        changePage(currentPage - 1);
                    }

                    function toggleSelectAll(checkbox) {
                        const startIndex = (currentPage - 1) * unitsPerGroup;
                        const endIndex = Math.min(startIndex + unitsPerGroup, allOrgUnits.length);

                        // Get all checkboxes in the table
                        const checkboxes = document.querySelectorAll('.org-unit-checkbox');

                        // Set all visible checkboxes to the same state
                        checkboxes.forEach(cb => {
                            cb.checked = checkbox.checked;
                        });

                        // Update the selection state for all units in this group
                        for (let i = startIndex; i < endIndex; i++) {
                            const unitId = allOrgUnits[i].id;
                            if (checkbox.checked) {
                                selectedUnits.add(unitId);
                            } else {
                                selectedUnits.delete(unitId);
                            }
                        }

                        // Update selection type if needed
                        if (checkbox.checked) {
                            selectionType = "group";
                            document.getElementById('selectionType').value = "group";
                        } else if (selectionType === "group") {
                            selectionType = "none";
                            document.getElementById('selectionType').value = "none";
                        }
                    }

                    function updateSelectAllCheckbox() {
                        const startIndex = (currentPage - 1) * unitsPerGroup;
                        const endIndex = Math.min(startIndex + unitsPerGroup, allOrgUnits.length);

                        let allSelected = true;
                        for (let i = startIndex; i < endIndex; i++) {
                            if (!selectedUnits.has(allOrgUnits[i].id)) {
                                allSelected = false;
                                break;
                            }
                        }

                        document.getElementById('selectAllCheckbox').checked = allSelected;
                    }

                    function selectCurrentGroup() {
                        const startIndex = (currentPage - 1) * unitsPerGroup;
                        const endIndex = Math.min(startIndex + unitsPerGroup, allOrgUnits.length);

                        // Select all units in this group
                        for (let i = startIndex; i < endIndex; i++) {
                            selectedUnits.add(allOrgUnits[i].id);
                        }

                        // Update checkboxes in the visible table
                        const checkboxes = document.querySelectorAll('.org-unit-checkbox');
                        checkboxes.forEach(cb => {
                            cb.checked = true;
                        });

                        // Update the select all checkbox
                        document.getElementById('selectAllCheckbox').checked = true;

                        // Set selection type
                        selectionType = "group";
                        document.getElementById('selectionType').value = "group";

                        // Create hidden inputs for all IDs in this group (for form submission)
                        createHiddenInputs(startIndex, endIndex);
                    }

                    function selectAllGroups() {
                        // Select all units across all groups
                        allOrgUnits.forEach(unit => {
                            selectedUnits.add(unit.id);
                        });

                        // Update checkboxes in the visible table
                        const checkboxes = document.querySelectorAll('.org-unit-checkbox');
                        checkboxes.forEach(cb => {
                            cb.checked = true;
                        });

                        // Update the select all checkbox
                        document.getElementById('selectAllCheckbox').checked = true;

                        // Set selection type
                        selectionType = "all";
                        document.getElementById('selectionType').value = "all";

                        // Create hidden inputs for all IDs (for form submission)
                        createHiddenInputs(0, allOrgUnits.length);
                    }

                    function deselectAll() {
                        // Clear all selections
                        selectedUnits.clear();

                        // Update checkboxes in the visible table
                        const checkboxes = document.querySelectorAll('.org-unit-checkbox');
                        checkboxes.forEach(cb => {
                            cb.checked = false;
                        });

                        // Update the select all checkbox
                        document.getElementById('selectAllCheckbox').checked = false;

                        // Set selection type
                        selectionType = "none";
                        document.getElementById('selectionType').value = "none";

                        // Remove any hidden inputs for selection
                        removeHiddenInputs();
                    }


                    // Event listener for form submission
                    document.addEventListener('DOMContentLoaded', function() {
                        const orgUnitsForm = document.getElementById('orgUnitsForm');
                        if (orgUnitsForm) {
                            orgUnitsForm.addEventListener('submit', function(e) {
                                e.preventDefault();

                                // Show loading spinner
                                const loadBtn = document.querySelector('button[name="sync_locations"]');
                                const originalBtnText = loadBtn.innerHTML;
                                loadBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Creating Sync Job...';
                                loadBtn.disabled = true;

                                // Create form data
                                const formData = new FormData(orgUnitsForm);

                                // Send AJAX request to create sync job
                                fetch('create_sync_job.php', {
                                        method: 'POST',
                                        body: formData
                                    })
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.status === 'success') {
                                            // Redirect to monitor page
                                            window.location.href = 'sync_monitor.php?job_id=' + data.job_id;
                                        } else {
                                            // Show error message
                                            alert('Error: ' + data.message);
                                            loadBtn.innerHTML = originalBtnText;
                                            loadBtn.disabled = false;
                                        }
                                    })
                                    .catch(error => {
                                        console.error('Error creating sync job:', error);
                                        alert('An error occurred. Please try again.');
                                        loadBtn.innerHTML = originalBtnText;
                                        loadBtn.disabled = false;
                                    });
                            });
                        }
                    });
                    // Function to create hidden inputs for selected units
                    function createHiddenInputs(startIndex, endIndex) {
                        // Remove existing hidden inputs first
                        removeHiddenInputs();

                        // Create a hidden input container if one doesn't exist
                        let container = document.getElementById('hiddenInputsContainer');
                        if (!container) {
                            container = document.createElement('div');
                            container.id = 'hiddenInputsContainer';
                            container.style.display = 'none';
                            document.getElementById('orgUnitsForm').appendChild(container);
                        }

                        // Create hidden inputs for unit IDs
                        for (let i = startIndex; i < endIndex; i++) {
                            if (i >= allOrgUnits.length) break;

                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = 'selected_orgunits[]';
                            input.value = allOrgUnits[i].id;
                            container.appendChild(input);
                        }
                    }

                    // Function to remove hidden inputs
                    function removeHiddenInputs() {
                        const container = document.getElementById('hiddenInputsContainer');
                        if (container) {
                            container.innerHTML = '';
                        }
                    }



                    // Handle individual checkbox changes
                    document.addEventListener('change', function(e) {
                        if (e.target && e.target.classList.contains('org-unit-checkbox')) {
                            const unitId = e.target.value;

                            if (e.target.checked) {
                                selectedUnits.add(unitId);
                            } else {
                                selectedUnits.delete(unitId);
                            }

                            // Update the "Select All" checkbox
                            updateSelectAllCheckbox();
                        }
                    });

                    // Initialize the table and selection handling
                    document.addEventListener('DOMContentLoaded', function() {
                        updateTable(1);

                        // Add form submit handler to process selections
                        document.getElementById('orgUnitsForm').addEventListener('submit', function(e) {
                            // Ensure proper handling of selection type before submission
                            const selectionTypeInput = document.getElementById('selectionType');

                            if (selectionType === "all") {
                                // Create hidden inputs for all units
                                createHiddenInputs(0, allOrgUnits.length);
                            } else if (selectionType === "group") {
                                const startIndex = (currentPage - 1) * unitsPerGroup;
                                const endIndex = Math.min(startIndex + unitsPerGroup, allOrgUnits.length);
                                createHiddenInputs(startIndex, endIndex);
                            } else {
                                // For individual selections, ensure each selected unit has a hidden input
                                removeHiddenInputs();
                                let container = document.getElementById('hiddenInputsContainer');
                                if (!container) {
                                    container = document.createElement('div');
                                    container.id = 'hiddenInputsContainer';
                                    container.style.display = 'none';
                                    document.getElementById('orgUnitsForm').appendChild(container);
                                }

                                selectedUnits.forEach(unitId => {
                                    const input = document.createElement('input');
                                    input.type = 'hidden';
                                    input.name = 'selected_orgunits[]';
                                    input.value = unitId;
                                    container.appendChild(input);
                                });
                            }
                        });
                    });
                    // Submit handler for the form
                    document.getElementById('orgUnitsForm').addEventListener('submit', function(e) {
                        // Prepare for submission based on selection type
                        const selectionType = document.getElementById('selectionType').value;

                        if (selectionType === "all") {
                            // Create hidden inputs for all units
                            createHiddenInputs(0, allOrgUnits.length);
                        } else if (selectionType === "group") {
                            // Create hidden inputs for current group
                            const startIndex = (currentPage - 1) * unitsPerGroup;
                            const endIndex = Math.min(startIndex + unitsPerGroup, allOrgUnits.length);
                            createHiddenInputs(startIndex, endIndex);
                        } else if (selectionType === "none") {
                            // Only submit selected units which are currently checked
                            const checkboxes = document.querySelectorAll('.org-unit-checkbox:checked');
                            if (checkboxes.length === 0) {
                                alert('Please select at least one organization unit');
                                e.preventDefault();
                                return false;
                            }
                        }
                    });
                </script>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>