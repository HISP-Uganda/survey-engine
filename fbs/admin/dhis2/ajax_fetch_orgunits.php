<?php
// dhis2/ajax_fetch_orgunits.php
session_start();
// Ensure dhis2_shared.php is included. This file contains dhis2_get and getDhis2Config.
require_once __DIR__ . '/dhis2_shared.php';

// Set aggressive error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Hide errors from direct output for AJAX
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log'); // Make sure this path is writable

// Output buffering to capture the HTML snippet to be returned
ob_start();

$instanceKey = $_POST['dhis2_instance'] ?? '';
$selectedLevel = $_POST['org_level'] ?? '';

if (empty($instanceKey) || empty($selectedLevel)) {
    echo '<div class="alert alert-warning futuristic-alert text-white">Please select both a DHIS2 instance and an OrgUnit level.</div>';
    $html = ob_get_clean();
    echo $html;
    exit;
}

$orgUnits = [];
try {
    // Retrieve the DHIS2 instance configuration
    $config = getDhis2Config($instanceKey);
    if (!$config) {
        throw new Exception("DHIS2 instance configuration not found or not active for key: " . $instanceKey);
    }

    // Construct the DHIS2 API endpoint to fetch organisation units
    $endpoint = 'organisationUnits?fields=id,name,path,level,parent[id]&filter=level:eq:' . $selectedLevel . '&paging=false';
    $resp = dhis2_get($endpoint, $instanceKey); // Use the shared dhis2_get function

    // Process the API response
    if (!empty($resp['organisationUnits'])) {
        foreach ($resp['organisationUnits'] as $ou) {
            $orgUnits[] = [
                'uid'        => $ou['id'],
                'name'       => $ou['name'],
                'path'       => $ou['path'] ?? '',
                'level'      => $ou['level'],
                'parent_uid' => $ou['parent']['id'] ?? ''
            ];
        }
    }

    // Render output based on whether org units were found
    if (empty($orgUnits)) {
        echo '<div class="alert alert-info text-center py-4 futuristic-alert text-white">
                <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                <h4 class="text-white">No Organisation Units Found</h4>
                <p class="mb-0">No organisation units found for the selected level in this instance.</p>
              </div>';
    } else {
        // Render the form and table if units are found
        ?>
        <form id="orgUnitsForm" method="post" action="create_sync_job.php" class="card futuristic-card card-body shadow-lg">
            <input type="hidden" name="dhis2_instance" value="<?= htmlspecialchars($instanceKey) ?>">
            <input type="hidden" name="org_level" value="<?= htmlspecialchars($selectedLevel) ?>">
            <input type="hidden" name="selection_type" id="selectionType" value="manual">
            <h5 class="text-white mb-3"><i class="fas fa-list-ul me-2 text-primary"></i>Organisation Units (<?= count($orgUnits) ?> found)</h5>
            <div class="mb-3 text-muted">Select the organisation units to import or sync.</div>
            <div class="table-responsive mb-3" style="max-height:500px; overflow-y:auto; border: 1px solid #4a5568; border-radius: 0.5rem;">
                <table class="table table-sm table-striped align-middle">
                    <thead class="table-secondary sticky-top" style="z-index: 1;">
                        <tr>
                            <th><input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)" class="form-check-input bg-dark"></th>
                            <th>Name</th>
                            <th>UID</th>
                            <th>Path</th>
                            <th>Level</th>
                            <th>Parent UID</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orgUnits as $ou): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="ou-checkbox form-check-input bg-dark"
                                        name="selected_orgunits[]"
                                        value="<?= htmlspecialchars(json_encode($ou)) ?>">
                                </td>
                                <td><?= htmlspecialchars($ou['name']) ?></td>
                                <td><small class="text-muted"><?= $ou['uid'] ?></small></td>
                                <td><small class="text-muted"><?= $ou['path'] ?></small></td>
                                <td><?= $ou['level'] ?></td>
                                <td><small class="text-muted"><?= $ou['parent_uid'] ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" name="sync_locations" class="btn btn-success btn-lg" id="syncBtn">
                <i class="fas fa-upload me-2"></i> Load Location Table
            </button>
        </form>
        <div class="text-center mt-4" id="jobStatus" style="display:none">
            <span class="spinner-border spinner-border-sm text-primary" role="status"></span>
            <span class="ms-2 text-white">Creating sync job, please wait...</span>
        </div>
        <script>
            // Re-define toggleSelectAll if this snippet is reloaded dynamically
            if (typeof toggleSelectAll !== 'function') {
                window.toggleSelectAll = function(master) {
                    document.querySelectorAll('.ou-checkbox').forEach(cb => cb.checked = master.checked);
                };
            }

            // AJAX submit for orgUnitsForm (delegated to document in main load.php script if possible,
            // but here for dynamic forms it's often re-attached directly to the form).
            // Use a flag to prevent multiple event listeners if the element is replaced.
            var orgUnitsForm = document.getElementById('orgUnitsForm');
            if (orgUnitsForm && !orgUnitsForm.dataset.listenerAttached) {
                orgUnitsForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var syncBtn = document.getElementById('syncBtn');
                    syncBtn.disabled = true;
                    document.getElementById('jobStatus').style.display = "block";
                    var formData = new FormData(orgUnitsForm);

                    // Collect selected checkboxes' values
                    var selected = [];
                    document.querySelectorAll('.ou-checkbox:checked').forEach(cb => {
                        selected.push(cb.value);
                    });
                    // Remove existing `selected_orgunits[]` and append new ones (correctly handles empty if none selected)
                    formData.delete('selected_orgunits[]');
                    selected.forEach(val => formData.append('selected_orgunits[]', val));

                    fetch('create_sync_job.php', { // Path to create_sync_job.php
                        method: 'POST',
                        body: formData
                    })
                    .then(resp => resp.json())
                    .then(data => {
                        if (data.status === "success") {
                            window.location.href = "sync_monitor.php?job_id=" + encodeURIComponent(data.job_id); // Redirect to monitor
                        } else {
                            alert("Sync job could not be created: " + (data.message || "Unknown error"));
                            syncBtn.disabled = false;
                            document.getElementById('jobStatus').style.display = "none";
                        }
                    })
                    .catch(err => {
                        alert("Network or server error creating sync job: " + err);
                        syncBtn.disabled = false;
                        document.getElementById('jobStatus').style.display = "none";
                    });
                });
                orgUnitsForm.dataset.listenerAttached = 'true'; // Mark listener as attached
            }
        </script>
        <?php
    }
} catch (Exception $e) {
    // Catch any exceptions during API call or config retrieval
    echo '<div class="alert alert-danger futuristic-alert">Error fetching organisation units: ' . htmlspecialchars($e->getMessage()) . '</div>';
    error_log("ajax_fetch_orgunits.php: " . $e->getMessage()); // Log detailed error
}

$html = ob_get_clean(); // Get the buffered HTML content
echo $html; // Output the captured HTML
exit; // Terminate script