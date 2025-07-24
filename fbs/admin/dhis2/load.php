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

    /* Adjust checkbox background color to be darker */
    .form-check-input.bg-dark {
        background-color: #3b4556 !important; /* Darker grey for checkboxes */
        border-color: #4a5568 !important;
    }
    .form-check-input.bg-dark:checked {
        background-color: #ffd700 !important; /* Gold when checked */
        border-color: #ffd700 !important;
    }

    /* Sticky table header for scrollable area */
    .table-responsive thead th {
        position: sticky;
        top: 0;
        z-index: 2; /* Ensure it's above other content in the scrollable div */
        background-color: #1a202c !important; /* Re-apply background color for sticky header */
        border-bottom: 1px solid #4a5568 !important; /* Ensure border is visible */
    }

    /* Adjust button size further if "btn-lg" is not enough */
    .btn.btn-lg {
        padding: 0.75rem 2rem; /* More generous padding */
        font-size: 1.15rem; /* Slightly larger text */
    }

</style>

<div class="container-fluid py-4"> <h4 class="mb-4 text-white"><i class="fas fa-globe-africa me-2 text-primary"></i> Load Locations from DHIS2</h4>

    <div class="card futuristic-card card-body mb-4 shadow-lg">
        <div class="mb-3 text-white"><strong>Step 1:</strong> Select DHIS2 Instance</div>
        <select name="dhis2_instance" id="dhis2_instance_select" class="form-select" required>
            <option value="">-- Select Instance --</option>
            <?php foreach ($instances as $inst): ?>
                <option value="<?= htmlspecialchars($inst['instance_key']) ?>" <?= ($selectedInstance === $inst['instance_key']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($inst['description']) ?> (<?= htmlspecialchars($inst['instance_key']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div id="levelSelectionContainer" class="card futuristic-card card-body mb-4 shadow-lg" style="display:none;">
        <div class="mb-3 text-white"><strong>Step 2:</strong> Select OrgUnit Level</div>
        <select name="org_level" id="org_level_select" class="form-select" required>
            <option value="">-- Select Level --</option>
            </select>
        <div id="levelLoadingSpinner" class="text-center mt-2" style="display:none;">
            <i class="fas fa-spinner fa-spin fa-lg text-primary"></i>
        </div>
        <div class="mt-4">
            <button type="button" class="btn btn-primary btn-lg" id="fetchOrgUnitsBtn">
                <i class="fas fa-search me-2"></i> Fetch Org Units
            </button>
        </div>
    </div>

    <div id="orgUnitsTableContainer">
        <?php if ($selectedInstance && $selectedLevel): // Show initial spinner if instance and level are pre-selected ?>
            <div class="text-center text-white py-4">
                <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
                <p class="mt-2">Loading organisation units...</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var dhis2Select = document.getElementById('dhis2_instance_select');
    var levelSelectionContainer = document.getElementById('levelSelectionContainer');
    var orgLevelSelect = document.getElementById('org_level_select');
    var levelLoadingSpinner = document.getElementById('levelLoadingSpinner');
    var fetchOrgUnitsBtn = document.getElementById('fetchOrgUnitsBtn');
    var orgUnitsTableContainer = document.getElementById('orgUnitsTableContainer');

    // Initial selected values from PHP for JS use (if page was loaded with GET params)
    const initialInstance = "<?= htmlspecialchars($selectedInstance) ?>";
    const initialLevel = "<?= htmlspecialchars($selectedLevel) ?>";

    // Helper function to show loading spinner in a given element
    function showLoading(element, message = 'Loading...') {
        element.innerHTML = `<div class="text-center text-white py-4">
                                <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
                                <p class="mt-2">${message}</p>
                             </div>`;
    }

    // Function to load OrgUnit Levels into Step 2 dropdown via AJAX
    function loadOrgUnitLevels() {
        var instanceKey = dhis2Select.value;
        orgLevelSelect.innerHTML = '<option value="">-- Loading --</option>'; // Show loading in dropdown
        orgLevelSelect.disabled = true;
        levelLoadingSpinner.style.display = 'block'; // Show spinner next to dropdown
        fetchOrgUnitsBtn.disabled = true; // Disable "Fetch Org Units" button

        levelSelectionContainer.style.display = 'none'; // Initially hide Step 2 container
        orgUnitsTableContainer.innerHTML = ''; // Clear Step 3 content

        if (!instanceKey) { // If no instance is selected, reset and hide
            orgLevelSelect.innerHTML = '<option value="">-- Select Level --</option>';
            levelLoadingSpinner.style.display = 'none';
            return;
        }

        // Fetch levels from the AJAX endpoint
        fetch('dhis2/ajax_get_orgunit_levels.php?instance=' + encodeURIComponent(instanceKey))
        .then(resp => {
            if (!resp.ok) throw new Error(`HTTP error! status: ${resp.status}`);
            return resp.json();
        })
        .then(data => {
            orgLevelSelect.innerHTML = '<option value="">-- Select Level --</option>'; // Reset dropdown
            levelLoadingSpinner.style.display = 'none'; // Hide spinner

            if (data.success && Array.isArray(data.levels) && data.levels.length) {
                data.levels.forEach(function(lvl) {
                    var opt = document.createElement('option');
                    opt.value = lvl.level;
                    opt.textContent = lvl.displayName + " (Level " + lvl.level + ")";
                    orgLevelSelect.appendChild(opt);
                });
                orgLevelSelect.disabled = false;
                fetchOrgUnitsBtn.disabled = false; // Enable "Fetch Org Units" button

                // If an initial level was set (e.g., from URL), select it and trigger unit fetch
                if (initialInstance === instanceKey && initialLevel) {
                    orgLevelSelect.value = initialLevel;
                    fetchOrganisationUnits(); // Automatically trigger Step 3
                }

            } else {
                orgLevelSelect.innerHTML = '<option value="">-- No levels found --</option>';
                fetchOrgUnitsBtn.disabled = true;
                // Display error/info directly in container
                orgUnitsTableContainer.innerHTML = `<div class="alert alert-warning futuristic-alert text-white">No org unit levels found for this instance.</div>`;
            }
            levelSelectionContainer.style.display = 'block'; // Show Step 2 container
        })
        .catch(function(err) {
            console.error("AJAX error loading levels: ", err);
            orgLevelSelect.innerHTML = '<option value="">-- Error loading --</option>';
            levelLoadingSpinner.style.display = 'none';
            fetchOrgUnitsBtn.disabled = true;
            orgUnitsTableContainer.innerHTML = `<div class="alert alert-danger futuristic-alert text-white">Network error loading levels: ${err}</div>`;
        });
    }

    // Function to fetch Organisation Units and display the table via AJAX
    function fetchOrganisationUnits() {
        var instanceKey = dhis2Select.value;
        var orgLevel = orgLevelSelect.value;

        if (!instanceKey || !orgLevel) {
            alert("Please select both a DHIS2 instance and an OrgUnit level.");
            return;
        }

        showLoading(orgUnitsTableContainer, 'Fetching Organisation Units...'); // Show spinner in results area

        const formData = new FormData();
        formData.append('dhis2_instance', instanceKey);
        formData.append('org_level', orgLevel);

        // Fetch HTML content from the new endpoint
        fetch('dhis2/ajax_fetch_orgunits.php', {
            method: 'POST',
            body: formData
        })
        .then(resp => {
            if (!resp.ok) throw new Error(`HTTP error! status: ${resp.status}`);
            return resp.text(); // Expect HTML snippet
        })
        .then(html => {
            orgUnitsTableContainer.innerHTML = html; // Inject HTML
            // Re-execute any scripts embedded in the loaded HTML (e.g., toggleSelectAll, orgUnitsForm submit listener)
            const scripts = orgUnitsTableContainer.querySelectorAll('script');
            scripts.forEach(script => {
                const newScript = document.createElement('script');
                newScript.textContent = script.textContent;
                document.body.appendChild(newScript).remove(); // Execute and remove
            });
        })
        .catch(function(err) {
            console.error("AJAX error fetching org units: ", err);
            orgUnitsTableContainer.innerHTML = `<div class="alert alert-danger futuristic-alert">Network error fetching organisation units: ${err}</div>`;
        });
    }

    // Event Listeners for main dropdowns and button
    dhis2Select.addEventListener('change', loadOrgUnitLevels);
    fetchOrgUnitsBtn.addEventListener('click', fetchOrganisationUnits);

    // Initial page load: If an instance is pre-selected (e.g., from a URL parameter),
    // trigger the level loading process.
    if (initialInstance) {
        loadOrgUnitLevels();
    }
});
</script>