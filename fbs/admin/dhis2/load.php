<?php
require_once __DIR__ . '/dhis2_shared.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

$instances = getAllDhis2Configs();
// Preserve selected values from GET parameters for initial JS state
$selectedInstance = $_GET['dhis2_instance'] ?? '';
$selectedLevel = $_GET['org_level'] ?? '';

// The orgUnits and orgUnitsFetched logic is now handled by AJAX,
// so these variables are no longer directly needed for initial rendering.
$orgUnits = [];
$orgUnitsFetched = false; // Always false on initial load, AJAX will handle

?>

<style>
    /* Add specific styles for the load.php components here if needed,
       or rely on the global styles from settings.php which are already futuristic. */

    /* Adjust checkbox background color to be lighter */
    .form-check-input.bg-light {
        background-color: #ffffff !important; /* White for checkboxes */
        border-color: #cbd5e1 !important;
    }
    .form-check-input.bg-light:checked {
        background-color: #3b82f6 !important; /* Blue when checked */
        border-color: #3b82f6 !important;
    }

    /* Sticky table header for scrollable area */
    .table-responsive thead th {
        position: sticky;
        top: 0;
        z-index: 2; /* Ensure it's above other content in the scrollable div */
        background-color: #f8fafc !important; /* Light background for sticky header */
        border-bottom: 1px solid #cbd5e1 !important; /* Light border is visible */
    }

    /* Adjust button size further if "btn-lg" is not enough */
    .btn.btn-lg {
        padding: 0.75rem 2rem; /* More generous padding */
        font-size: 1.15rem; /* Slightly larger text */
    }

</style>

<div class="container-fluid py-4"> <h4 class="mb-4 text-dark"><i class="fas fa-globe-africa me-2 text-primary"></i> Load Locations from DHIS2</h4>

    <div class="card futuristic-card card-body mb-4 shadow-lg">
        <div class="mb-3 text-dark"><strong>Step 1:</strong> Select DHIS2 Instance</div>
        <select name="dhis2_instance" id="dhis2_instance_select" class="form-select" required>
            <option value="">-- Select Instance --</option>
            <?php foreach ($instances as $inst): ?>
                <option value="<?= htmlspecialchars($inst['instance_key']) ?>" <?= ($selectedInstance === $inst['instance_key']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($inst['description']) ?> (<?= htmlspecialchars($inst['instance_key']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>


    <!-- Quick Sync Option -->
    <div id="quickSyncContainer" class="card futuristic-card card-body mb-4 shadow-lg" style="display:none;">
        <div class="mb-3 text-dark"><strong>Quick Sync:</strong> Sync Complete Hierarchy</div>
        <div class="mb-3 text-muted">
            Automatically sync all organisation units from all levels directly to the location table.
            This bypasses manual selection and ensures complete hierarchy with proper parent-child relationships.
        </div>
        <div class="row">
            <div class="col-md-8">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="sync_type" id="sync_all" value="all" checked>
                    <label class="form-check-label text-dark" for="sync_all">
                        <strong>Sync All Levels</strong> - Complete hierarchy from root to lowest level
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="sync_type" id="sync_selected" value="selected">
                    <label class="form-check-label text-dark" for="sync_selected">
                        <strong>Sync Selected Levels</strong> - Choose specific levels to sync
                    </label>
                </div>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-md-8">
                <div class="mb-3">
                    <label class="form-label text-dark"><strong>Sync Mode:</strong></label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="sync_mode" id="sync_update" value="update" checked>
                        <label class="form-check-label text-dark" for="sync_update">
                            <strong>Update Existing</strong> - Update existing records with fresh DHIS2 data
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="sync_mode" id="sync_skip" value="skip">
                        <label class="form-check-label text-dark" for="sync_skip">
                            <strong>Skip Existing</strong> - Only insert new records, skip existing ones
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="sync_mode" id="sync_fresh" value="fresh">
                        <label class="form-check-label text-dark" for="sync_fresh">
                            <strong>Fresh Start</strong> - Delete existing records for this instance first
                        </label>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <button type="button" class="btn btn-success btn-lg w-100" id="quickSyncBtn">
                    <i class="fas fa-sync-alt me-2"></i> Quick Sync
                </button>
            </div>
        </div>
        <div id="levelCheckboxes" class="mt-3" style="display:none;">
            <!-- Dynamically populated level checkboxes -->
        </div>
        <div id="syncProgress" class="mt-3" style="display:none;">
            <div class="progress">
                <div class="progress-bar progress-bar-striped progress-bar-animated" 
                     role="progressbar" style="width: 0%"></div>
            </div>
            <div class="text-center mt-2">
                <small class="text-muted" id="syncStatus">Preparing sync...</small>
            </div>
        </div>
    </div>

    <div id="orgUnitsTableContainer">
        <?php if ($selectedInstance && $selectedLevel): // Show initial spinner if instance and level are pre-selected ?>
            <div class="text-center text-dark py-4">
                <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
                <p class="mt-2">Loading organisation units...</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var dhis2Select = document.getElementById('dhis2_instance_select');
    var orgUnitsTableContainer = document.getElementById('orgUnitsTableContainer');
    
    // Quick Sync elements
    var quickSyncContainer = document.getElementById('quickSyncContainer');
    var quickSyncBtn = document.getElementById('quickSyncBtn');
    var syncTypeRadios = document.querySelectorAll('input[name="sync_type"]');
    var levelCheckboxes = document.getElementById('levelCheckboxes');
    var syncProgress = document.getElementById('syncProgress');
    var syncStatus = document.getElementById('syncStatus');

    // Initial selected values from PHP for JS use (if page was loaded with GET params)
    const initialInstance = "<?= htmlspecialchars($selectedInstance) ?>";
    const initialLevel = "<?= htmlspecialchars($selectedLevel) ?>";


    // Function to load OrgUnit Levels for Quick Sync
    function loadOrgUnitLevels() {
        var instanceKey = dhis2Select.value;
        
        if (!instanceKey) {
            quickSyncContainer.style.display = 'none';
            orgUnitsTableContainer.innerHTML = '';
            return;
        }

        // Show loading state
        orgUnitsTableContainer.innerHTML = `
            <div class="text-center text-dark py-4">
                <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
                <p class="mt-2">Loading DHIS2 levels...</p>
            </div>
        `;

        // Fetch levels from the AJAX endpoint with timeout handling
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 60000); // 60 second timeout

        fetch('dhis2/ajax_get_orgunit_levels.php?instance=' + encodeURIComponent(instanceKey), {
            signal: controller.signal
        })
        .then(resp => {
            clearTimeout(timeoutId);
            if (!resp.ok) throw new Error(`HTTP error! status: ${resp.status}`);
            return resp.json();
        })
        .then(data => {
            if (data.success && Array.isArray(data.levels) && data.levels.length) {
                // Populate level checkboxes for quick sync
                populateLevelCheckboxes(data.levels);
                quickSyncContainer.style.display = 'block'; // Show Quick Sync option
                orgUnitsTableContainer.innerHTML = ''; // Clear loading message
            } else {
                quickSyncContainer.style.display = 'none';
                orgUnitsTableContainer.innerHTML = `
                    <div class="alert alert-warning futuristic-alert text-white">
                        <h5><i class="fas fa-exclamation-triangle me-2"></i>No Organisation Levels Found</h5>
                        <p class="mb-0">No organisation unit levels found for this DHIS2 instance. Please check the instance configuration.</p>
                    </div>`;
            }
        })
        .catch(function(err) {
            clearTimeout(timeoutId);
            console.error("AJAX error loading levels: ", err);
            quickSyncContainer.style.display = 'none';
            
            let errorMessage = 'Unknown error occurred';
            if (err.name === 'AbortError') {
                errorMessage = 'Request timed out - DHIS2 instance may be slow or unavailable';
            } else if (err.message.includes('500')) {
                errorMessage = 'DHIS2 server error - please try again later';
            } else if (err.message.includes('status')) {
                errorMessage = 'Connection error - please check DHIS2 instance URL';
            } else {
                errorMessage = err.message;
            }
            
            orgUnitsTableContainer.innerHTML = `
                <div class="alert alert-danger futuristic-alert text-white">
                    <h5><i class="fas fa-exclamation-circle me-2"></i>Connection Error</h5>
                    <p class="mb-0"><strong>Error:</strong> ${errorMessage}</p>
                    <p class="mb-0 mt-2"><small>Please verify the DHIS2 instance is accessible and try again.</small></p>
                </div>`;
        });
    }


    // Quick Sync Functions
    function populateLevelCheckboxes(levels) {
        levelCheckboxes.innerHTML = '';
        levels.forEach(function(lvl) {
            const checkboxDiv = document.createElement('div');
            checkboxDiv.className = 'form-check form-check-inline';
            checkboxDiv.innerHTML = `
                <input class="form-check-input" type="checkbox" id="level_${lvl.level}" 
                       value="${lvl.level}" checked>
                <label class="form-check-label text-dark" for="level_${lvl.level}">
                    Level ${lvl.level} (${lvl.displayName})
                </label>
            `;
            levelCheckboxes.appendChild(checkboxDiv);
        });
    }

    function performQuickSync() {
        const instanceKey = dhis2Select.value;
        if (!instanceKey) {
            alert('Please select a DHIS2 instance first');
            return;
        }

        const syncType = document.querySelector('input[name="sync_type"]:checked').value;
        let syncLevels = [];
        
        if (syncType === 'selected') {
            const checkedBoxes = levelCheckboxes.querySelectorAll('input[type="checkbox"]:checked');
            if (checkedBoxes.length === 0) {
                alert('Please select at least one level to sync');
                return;
            }
            syncLevels = Array.from(checkedBoxes).map(cb => cb.value);
        }

        // Show progress and disable button
        syncProgress.style.display = 'block';
        quickSyncBtn.disabled = true;
        syncStatus.textContent = 'Initiating sync...';
        
        const progressBar = syncProgress.querySelector('.progress-bar');
        progressBar.style.width = '20%';

        const syncMode = document.querySelector('input[name="sync_mode"]:checked').value;

        // Prepare form data
        const formData = new FormData();
        formData.append('dhis2_instance', instanceKey);
        formData.append('full_hierarchy', syncType === 'all');
        formData.append('sync_mode', syncMode);
        if (syncType === 'selected') {
            syncLevels.forEach(level => formData.append('sync_levels[]', level));
        }

        // Start streaming sync
        fetch('dhis2/ajax_sync_hierarchy.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            
            function processStream() {
                return reader.read().then(({ done, value }) => {
                    if (done) {
                        quickSyncBtn.disabled = false;
                        return;
                    }
                    
                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop(); // Keep incomplete line in buffer
                    
                    lines.forEach(line => {
                        if (line.trim()) {
                            try {
                                const data = JSON.parse(line);
                                handleProgressUpdate(data);
                            } catch (e) {
                                console.warn('Invalid JSON in stream:', line);
                            }
                        }
                    });
                    
                    return processStream();
                });
            }
            
            return processStream();
        })
        .catch(err => {
            console.error('Quick sync error:', err);
            
            let errorMessage = 'Unknown error occurred';
            let errorDetails = '';
            
            if (err.name === 'AbortError') {
                errorMessage = 'Sync timed out';
                errorDetails = 'The sync process took too long. This may be due to a large dataset or slow DHIS2 server.';
            } else if (err.message.includes('500')) {
                errorMessage = 'Server Error';
                errorDetails = 'DHIS2 server encountered an error. Please check server logs and try again.';
            } else if (err.message.includes('status')) {
                errorMessage = 'Connection Error';
                errorDetails = 'Unable to connect to DHIS2 server. Please check the instance URL and network connectivity.';
            } else {
                errorMessage = 'Sync Failed';
                errorDetails = err.message;
            }
            
            syncStatus.innerHTML = `<span class="text-danger">${errorMessage}</span>`;
            progressBar.style.width = '100%';
            progressBar.classList.add('bg-danger');
            
            orgUnitsTableContainer.innerHTML = `
                <div class="alert alert-danger futuristic-alert text-white">
                    <h5><i class="fas fa-exclamation-circle me-2"></i>${errorMessage}</h5>
                    <p class="mb-0">${errorDetails}</p>
                    <div class="mt-2">
                        <button class="btn btn-outline-light btn-sm" onclick="location.reload()">
                            <i class="fas fa-redo me-1"></i> Retry
                        </button>
                    </div>
                </div>
            `;
            quickSyncBtn.disabled = false;
        });
    }

    function handleProgressUpdate(data) {
        const progressBar = syncProgress.querySelector('.progress-bar');
        
        switch (data.type) {
            case 'start':
                syncStatus.textContent = data.message;
                progressBar.style.width = '5%';
                break;
                
            case 'page':
                const pagePercent = (data.page / data.totalPages) * 30; // Pages are 30% of progress
                progressBar.style.width = Math.max(5, pagePercent) + '%';
                syncStatus.innerHTML = `${data.message}<br><small>Records processed: ${data.processed}</small>`;
                break;
                
            case 'progress':
                const totalPercent = 30 + (data.percentage * 0.6); // Processing is 60% of progress
                progressBar.style.width = totalPercent + '%';
                const statusParts = [`Inserted: ${data.inserted}`, `Updated: ${data.updated}`];
                if (data.skipped > 0) {
                    statusParts.push(`Skipped: ${data.skipped}`);
                }
                syncStatus.innerHTML = `
                    ${data.message}<br>
                    <small>${statusParts.join(' | ')}</small>
                `;
                break;
                
            case 'complete':
            case 'warning':
                progressBar.style.width = '100%';
                const isWarning = data.type === 'warning' || data.summary.processed === 0;
                const alertClass = isWarning ? 'alert-warning' : 'alert-success';
                const iconClass = isWarning ? 'fas fa-exclamation-triangle' : 'fas fa-check-circle';
                const titleText = isWarning ? 'Sync Completed with Issues' : 'Hierarchy Sync Complete';
                
                syncStatus.innerHTML = `
                    <strong>${isWarning ? 'Sync completed with issues!' : 'Sync completed successfully!'}</strong><br>
                    ${data.message}
                `;
                
                // Show result message in main container
                orgUnitsTableContainer.innerHTML = `
                    <div class="alert ${alertClass} futuristic-alert">
                        <h5 class="text-white"><i class="${iconClass} me-2"></i>${titleText}</h5>
                        <p class="mb-2">Levels targeted: ${data.summary.levels_synced}</p>
                        ${data.summary.timeout_occurred ? '<p class="mb-2"><strong>Note:</strong> DHIS2 server timeout occurred during sync.</p>' : ''}
                        <ul class="mb-0">
                            <li>Total processed: ${data.summary.processed}</li>
                            <li>New records inserted: ${data.summary.inserted}</li>
                            <li>Existing records updated: ${data.summary.updated}</li>
                            ${data.summary.skipped > 0 ? `<li>Records skipped: ${data.summary.skipped}</li>` : ''}
                            <li>Sync mode: ${data.summary.sync_mode}</li>
                            <li>Parent relationships resolved: ${data.summary.parent_relationships_resolved}</li>
                            <li>Pages processed: ${data.summary.pages_processed}</li>
                            <li>Errors encountered: ${data.summary.errors}</li>
                        </ul>
                        ${data.errors && data.errors.length > 0 ? '<div class="mt-2"><strong>Errors:</strong><br>' + data.errors.join('<br>') + '</div>' : ''}
                        ${isWarning && data.summary.processed === 0 ? 
                            '<div class="mt-3"><button class="btn btn-outline-light btn-sm" onclick="location.reload()"><i class="fas fa-redo me-1"></i> Try Again</button></div>' : 
                            '<div class="mt-2"><small class="text-muted">Page will refresh in <span id="refreshCounter">5</span> seconds...</small></div>'}
                    </div>
                `;
                
                // Auto-refresh after successful completion
                if (!isWarning || data.summary.processed > 0) {
                    let countdown = 5;
                    const countdownElement = document.getElementById('refreshCounter');
                    const refreshInterval = setInterval(() => {
                        countdown--;
                        if (countdownElement) {
                            countdownElement.textContent = countdown;
                        }
                        if (countdown <= 0) {
                            clearInterval(refreshInterval);
                            location.reload();
                        }
                    }, 1000);
                }
                break;
                
            case 'error':
                progressBar.style.width = '100%';
                
                // Check if it's a partial success (some data processed despite timeout)
                if (data.partial_success && data.processed > 0) {
                    progressBar.classList.add('bg-warning');
                    syncStatus.innerHTML = `<span class="text-warning">Partial sync: ${data.message}</span>`;
                    
                    orgUnitsTableContainer.innerHTML = `
                        <div class="alert alert-warning futuristic-alert">
                            <h5 class="text-white"><i class="fas fa-exclamation-triangle me-2"></i>Partial Sync Completed</h5>
                            <p class="mb-2">${data.message}</p>
                            <ul class="mb-0">
                                <li>Records processed: ${data.processed}</li>
                                <li>New records inserted: ${data.inserted}</li>
                                <li>Existing records updated: ${data.updated}</li>
                            </ul>
                            <div class="mt-3">
                                <button class="btn btn-outline-light btn-sm" onclick="location.reload()">
                                    <i class="fas fa-redo me-1"></i> Resume Sync
                                </button>
                            </div>
                        </div>
                    `;
                } else {
                    progressBar.classList.add('bg-danger');
                    syncStatus.innerHTML = `<span class="text-danger">Sync failed: ${data.error || data.message}</span>`;
                    
                    orgUnitsTableContainer.innerHTML = `
                        <div class="alert alert-danger futuristic-alert">
                            <strong>Sync Failed:</strong> ${data.error || data.message}
                        </div>
                    `;
                }
                break;
        }
    }

    // Event Listeners
    dhis2Select.addEventListener('change', loadOrgUnitLevels);
    quickSyncBtn.addEventListener('click', performQuickSync);
    
    syncTypeRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'selected') {
                levelCheckboxes.style.display = 'block';
            } else {
                levelCheckboxes.style.display = 'none';
            }
        });
    });

    // Initial page load: If an instance is pre-selected (e.g., from a URL parameter),
    // trigger the level loading process.
    if (initialInstance) {
        loadOrgUnitLevels();
    }
});
</script>