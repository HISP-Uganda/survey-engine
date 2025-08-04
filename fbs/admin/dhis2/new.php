<?php
// new.php - DHIS2 Mapping Interface with AJAX loading and Futuristic Design

require_once __DIR__ . '/dhis2_shared.php'; // Provides getAllDhis2Configs, getDhis2Config, cURL helpers
                                         // and connects to DB via connect.php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');

class DHIS2MappingInterface {
    private $pdo;
    private $activeTab;
    private $selectedInstanceKey;
    private $dhis2ConfigDetails = null;

    public function __construct($pdo, $activeTab, $selectedInstanceKey) {
        $this->pdo = $pdo;
        $this->activeTab = $activeTab;
        $this->selectedInstanceKey = $selectedInstanceKey;

        if (!empty($this->selectedInstanceKey)) {
            $this->dhis2ConfigDetails = getDhis2Config($this->selectedInstanceKey);
            if (!$this->dhis2ConfigDetails) {
                error_log("new.php (Class): Failed to load detailed DHIS2 config for instance: " . $this->selectedInstanceKey);
                $this->selectedInstanceKey = '';
            }
        }
    }

    public function render() {
        $this->activeTab = $_GET['tab'] ?? 'new';

        if ($this->activeTab === 'new') {
            $this->renderMappingStatusTab();
        } elseif ($this->activeTab === 'questions') {
            $this->renderQuestionMappingTab();
        }

        // Handle POST submissions for mappings.
        // If these forms are also submitted via AJAX, this part would need modification
        // to respond with JSON instead of printing HTML alerts directly.
        $this->handleFormSubmissions();
    }

    private function renderMappingStatusTab() {
        $initialInstance = $_GET['dhis2_instance'] ?? '';
        $initialDomain = $_GET['domain'] ?? '';
        $initialProgram = $_GET['program'] ?? '';
        $initialDataset = $_GET['dataset'] ?? '';
        ?>
        <div class="tab-header mb-4">
            <h3 class="text-dark"><i class="fas fa-search me-2 text-primary"></i>Check DHIS2 Mapping Status</h3>
        </div>

        <div class="card futuristic-card shadow-lg">
            <div class="card-body">
                <form id="mappingForm" method="get">
                    <input type="hidden" name="tab" value="new">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-control-label text-dark">Select DHIS2 Instance</label>
                                <select name="dhis2_instance" id="dhis2_instance_select" class="form-control form-select">
                                    <option value="">-- Select Instance --</option>
                                    <?php
                                    $dhis2Instances = getAllDhis2Configs();
                                    if (empty($dhis2Instances)) {
                                        echo "<option value=\"\" disabled>No active DHIS2 instances found.</option>";
                                    } else {
                                        foreach ($dhis2Instances as $instance) :
                                            $instanceKey = htmlspecialchars($instance['instance_key']);
                                            $instanceDescription = htmlspecialchars($instance['description']);
                                            $selected = ($initialInstance === $instanceKey) ? 'selected' : '';
                                            echo "<option value=\"{$instanceKey}\" {$selected}>{$instanceDescription} ({$instanceKey})</option>";
                                        endforeach;
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label text-dark">Data Domain</label>
                            <select name="domain" id="domain_select" class="form-select" <?= empty($initialInstance) ? 'disabled' : '' ?>>
                                <option value="">-- Select Domain --</option>
                                <option value="tracker" <?= $initialDomain === 'tracker' ? 'selected' : '' ?>>Tracker</option>
                                <option value="aggregate" <?= $initialDomain === 'aggregate' ? 'selected' : '' ?>>Aggregate</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-4" id="programDatasetSection" style="display: <?= (!empty($initialInstance) && !empty($initialDomain)) ? 'block' : 'none' ?>;">
                        <div class="col-md-12">
                            <label class="form-label text-dark" id="programDatasetLabel">Program/Data Set</label>
                            <select name="<?= $initialDomain === 'tracker' ? 'program' : 'dataset' ?>" id="program_dataset_select" class="form-select" <?= (empty($initialProgram) && empty($initialDataset)) ? 'disabled' : '' ?>>
                                <option value="">-- Select --</option>
                                </select>
                             <div id="programDatasetLoadingSpinner" class="text-center mt-2" style="display:none;">
                                <i class="fas fa-spinner fa-spin fa-lg text-primary"></i>
                             </div>
                        </div>
                    </div>
                </form>

                <div id="mappingStatusResults" class="mt-4">
                    <?php if (!empty($initialInstance) && !empty($initialDomain) && (!empty($initialProgram) || !empty($initialDataset))): ?>
                        <div class="text-center text-dark py-4">
                            <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
                            <p class="mt-2">Loading mapping status...</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        // Inject AJAX JavaScript logic at the end of the tab rendering
        $this->renderAjaxJavaScript($initialInstance, $initialDomain, $initialProgram, $initialDataset);
    }

    private function renderQuestionMappingTab() {
        // Design applied to this section's elements as well
        ?>
        <div class="tab-header mb-4">
            <h3 class="text-dark"><i class="fas fa-map me-2 text-primary"></i>Map DHIS2 Data Elements to Questions</h3>
        </div>

        <div class="card futuristic-card shadow-lg">
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="tab" value="questions">

                    <?php if ($this->shouldShowQuestionMappingInterface()): ?>
                        <?php if ($this->isEditingSingleQuestion()): ?>
                            <?php $this->renderSingleQuestionMappingForm(); ?>
                        <?php else: ?>
                            <?php $this->renderSurveySelection(); ?>
                            <?php $this->renderQuestionListForMapping(); ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-info futuristic-alert">
                            <i class="fas fa-info-circle me-2"></i> Select a DHIS2 Instance, Domain, and Program/Dataset on the "Check DHIS2 Mapping Status" tab to configure mappings here.
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <?php $this->renderOptionSetJavaScript(); ?>
        <?php
    }

    private function renderAjaxJavaScript($initialInstance, $initialDomain, $initialProgram, $initialDataset) {
        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const instanceSelect = document.getElementById('dhis2_instance_select');
                const domainSelect = document.getElementById('domain_select');
                const programDatasetSection = document.getElementById('programDatasetSection');
                const programDatasetLabel = document.getElementById('programDatasetLabel');
                const programDatasetSelect = document.getElementById('program_dataset_select');
                const programDatasetLoadingSpinner = document.getElementById('programDatasetLoadingSpinner'); // New spinner for program/dataset
                const mappingStatusResults = document.getElementById('mappingStatusResults');

                const initialInstanceVal = "<?= htmlspecialchars($initialInstance) ?>";
                const initialDomainVal = "<?= htmlspecialchars($initialDomain) ?>";
                const initialProgramVal = "<?= htmlspecialchars($initialProgram) ?>";
                const initialDatasetVal = "<?= htmlspecialchars($initialDataset) ?>";

                // Function to show loading spinner within a target element
                function showLoading(element, message = 'Loading...') {
                    element.innerHTML = `<div class="text-center text-dark py-4">
                                            <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
                                            <p class="mt-2">${message}</p>
                                         </div>`;
                }

                // Function to load Programs or Data Sets via AJAX
                function loadProgramsDatasets() {
                    const instanceKey = instanceSelect.value;
                    const domain = domainSelect.value;

                    // Clear and disable program/dataset select
                    programDatasetSelect.innerHTML = '<option value="">-- Loading --</option>';
                    programDatasetSelect.disabled = true;
                    programDatasetLoadingSpinner.style.display = 'block'; // Show spinner

                    mappingStatusResults.innerHTML = ''; // Clear mapping results

                    if (!instanceKey || !domain) {
                        programDatasetSection.style.display = 'none';
                        programDatasetSelect.innerHTML = '<option value="">-- Select --</option>';
                        programDatasetLoadingSpinner.style.display = 'none'; // Hide spinner
                        return;
                    }

                    programDatasetLabel.textContent = domain === 'tracker' ? 'Program' : 'Data Set';
                    programDatasetSection.style.display = 'block'; // Ensure the section is visible

                    fetch(`dhis2/ajax_get_programs_datasets.php?instance=${encodeURIComponent(instanceKey)}&domain=${encodeURIComponent(domain)}`)
                        .then(response => {
                            if (!response.ok) {
                                throw new Error(`HTTP error! status: ${response.status}`);
                            }
                            return response.json();
                        })
                        .then(data => {
                            programDatasetSelect.innerHTML = '<option value="">-- Select --</option>'; // Reset
                            programDatasetLoadingSpinner.style.display = 'none'; // Hide spinner

                            if (data.success && data.data.length > 0) {
                                data.data.forEach(item => {
                                    const option = document.createElement('option');
                                    option.value = item.id;
                                    option.textContent = item.name;
                                    programDatasetSelect.appendChild(option);
                                });
                                programDatasetSelect.disabled = false;

                                // Attempt to re-select initial value if present and matches current context
                                if (domain === 'tracker' && initialProgramVal && instanceKey === initialInstanceVal && domain === initialDomainVal) {
                                    programDatasetSelect.value = initialProgramVal;
                                } else if (domain === 'aggregate' && initialDatasetVal && instanceKey === initialInstanceVal && domain === initialDomainVal) {
                                    programDatasetSelect.value = initialDatasetVal;
                                }

                                // If a program/dataset is now selected (either initially or by user), load mapping status
                                if (programDatasetSelect.value) {
                                    loadMappingStatus();
                                } else {
                                     mappingStatusResults.innerHTML = ''; // Clear if no program/dataset selected
                                }

                            } else {
                                programDatasetSelect.innerHTML = '<option value="">-- No items found --</option>';
                                mappingStatusResults.innerHTML = `<div class="alert alert-warning futuristic-alert">No ${domain === 'tracker' ? 'programs' : 'datasets'} found for this instance.</div>`;
                            }
                        })
                        .catch(error => {
                            console.error('Error loading programs/datasets:', error);
                            programDatasetSelect.innerHTML = '<option value="">-- Error loading --</option>';
                            programDatasetLoadingSpinner.style.display = 'none'; // Hide spinner
                            mappingStatusResults.innerHTML = `<div class="alert alert-danger futuristic-alert">Error loading ${domain === 'tracker' ? 'programs' : 'datasets'}. Please try again.</div>`;
                        });
                }

                // Function to load Mapping Status Results via AJAX
                function loadMappingStatus() {
                    const instanceKey = instanceSelect.value;
                    const domain = domainSelect.value;
                    const selectedProgramDataset = programDatasetSelect.value;

                    showLoading(mappingStatusResults, 'Fetching mapping status...');

                    if (!instanceKey || !domain || !selectedProgramDataset) {
                        mappingStatusResults.innerHTML = ''; // Clear if any dependencies are missing
                        return;
                    }

                    let url = `dhis2/ajax_get_mapping_status.php?instance=${encodeURIComponent(instanceKey)}&domain=${encodeURIComponent(domain)}`;
                    if (domain === 'tracker') {
                        url += `&program=${encodeURIComponent(selectedProgramDataset)}`;
                    } else {
                        url += `&dataset=${encodeURIComponent(selectedProgramDataset)}`;
                    }

                    fetch(url)
                        .then(response => {
                            if (!response.ok) {
                                throw new Error(`HTTP error! status: ${response.status}`);
                            }
                            return response.text(); // Expect HTML snippet
                        })
                        .then(html => {
                            mappingStatusResults.innerHTML = html;
                            // Re-execute any scripts within the loaded HTML (e.g., filterBySurvey)
                            const scripts = mappingStatusResults.querySelectorAll('script');
                            scripts.forEach(script => {
                                const newScript = document.createElement('script');
                                newScript.textContent = script.textContent;
                                document.body.appendChild(newScript).remove(); // Execute and remove
                            });
                        })
                        .catch(error => {
                            console.error('Error loading mapping status:', error);
                            mappingStatusResults.innerHTML = `<div class="alert alert-danger futuristic-alert">Error loading mapping status. Please try again.</div>`;
                        });
                }

                // Event Listeners
                instanceSelect.addEventListener('change', function() {
                    domainSelect.value = ''; // Reset domain
                    domainSelect.disabled = !instanceSelect.value; // Enable/disable domain select
                    programDatasetSelect.value = ''; // Reset program/dataset
                    programDatasetSelect.disabled = true;
                    programDatasetSection.style.display = 'none'; // Hide program/dataset section
                    programDatasetLoadingSpinner.style.display = 'none'; // Hide spinner
                    mappingStatusResults.innerHTML = ''; // Clear results
                });

                domainSelect.addEventListener('change', function() {
                    programDatasetSelect.value = ''; // Reset program/dataset
                    loadProgramsDatasets(); // Load programs/datasets based on new domain
                });

                programDatasetSelect.addEventListener('change', loadMappingStatus);

                // Initial Load Logic: Trigger AJAX calls if initial values are present
                if (initialInstanceVal) {
                    domainSelect.disabled = false; // Enable domain select
                    if (initialDomainVal) {
                        loadProgramsDatasets(); // This will trigger the chain for program/dataset and then mapping status
                    }
                }
            });
        </script>
        <?php
    }

    // --- Original methods for the "questions" tab and form submissions remain (design applied) ---

    private function renderSurveySelection() {
        $selectedSurvey = $_GET['survey_id'] ?? '';
        ?>
        <div class="mb-4">
            <label class="form-label text-dark">Selected Survey</label>
            <p class="form-control-plaintext text-dark">
            <?php
            $surveyStmt = $this->pdo->prepare("SELECT name FROM survey WHERE id = ?");
            $surveyStmt->execute([$selectedSurvey]);
            $survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);
            echo htmlspecialchars($survey['name'] ?? 'No survey selected');
            ?>
            </p>
        </div>
        <?php
    }

    private function renderQuestionListForMapping() {
        if (empty($_GET['survey_id'])) {
            return;
        }

        $surveyId = $_GET['survey_id'];
        $questions = $this->getSurveyQuestions($surveyId);
        $dataElements = $this->getDataElementsForSelection(); // Assuming this gets DEs for selected instance/program/dataset
        $optionSets = $this->getOptionSetsForDataElements($dataElements);
        $optionSetMappings = $this->getOptionSetMappings(array_keys($optionSets));

        if (empty($questions)): ?>
            <div class="alert alert-info text-center py-4 futuristic-alert">
                <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                <h4 class="text-dark">No Questions Found in This Survey</h4>
                <p class="mb-0">This survey doesn't contain any questions yet.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped">
                    <thead class="table-secondary">
                        <tr>
                            <th width="30%">Question</th>
                            <th width="30%">DHIS2 Data Element</th>
                            <th width="30%">Option Set</th>
                            <th width="10%">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($questions as $question): ?>
                            <tr>
                                <td>
                                    <strong class="text-dark"><?= htmlspecialchars($question['label']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($question['question_type']) ?></small>
                                </td>
                                <td>
                                    <select name="mapping[<?= $question['id'] ?>][data_element]" class="form-select form-select-sm">
                                        <option value="">-- Not Mapped --</option>
                                        <?php foreach ($dataElements as $id => $element): ?>
                                            <?php $selected = $question['dhis2_dataelement_id'] === $id ? 'selected' : '' ?>
                                            <option value="<?= htmlspecialchars($id) ?>" <?= $selected ?>>
                                                <?= htmlspecialchars($element['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select name="mapping[<?= $question['id'] ?>][option_set]" class="form-select form-select-sm">
                                        <option value="">-- No Option Set --</option>
                                        <?php foreach ($optionSets as $id => $set): ?>
                                            <?php $selected = $question['dhis2_option_set_id'] === $id ? 'selected' : '' ?>
                                            <option value="<?= htmlspecialchars($id) ?>" <?= $selected ?>>
                                                <?= htmlspecialchars($set['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="text-center">
                                    <a href="?tab=questions&dhis2_instance=<?= htmlspecialchars($_GET['dhis2_instance']) ?>&domain=<?= htmlspecialchars($_GET['domain']) ?>&<?= $_GET['domain'] === 'tracker' ? 'program='.htmlspecialchars($_GET['program']) : 'dataset='.htmlspecialchars($_GET['dataset']) ?>&survey_id=<?= $surveyId ?>&question_id=<?= $question['id'] ?>"
                                       class="btn btn-sm btn-outline-primary" title="Edit Individual Mapping">
                                        <i class="fas fa-edit"></i>
                                    </a>

                                    <?php if (!empty($question['dhis2_option_set_id'])): ?>
                                    <button type="button" class="btn btn-sm btn-outline-info view-options ms-1"
                                            data-question-id="<?= $question['id'] ?>"
                                            data-option-set="<?= $question['dhis2_option_set_id'] ?>"
                                            title="View Option Set">
                                        <i class="fas fa-list"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr class="option-set-details" id="options-<?= $question['id'] ?>" style="display:none;">
                                <td colspan="4">
                                    <div class="p-3 bg-secondary rounded">
                                        <h6 class="mb-3 text-dark"><i class="fas fa-list-ul me-2"></i>Option Set Values</h6>
                                        <div class="options-container" id="options-container-<?= $question['id'] ?>">
                                            <?php
                                            if (!empty($question['dhis2_option_set_id']) && !empty($optionSetMappings[$question['dhis2_option_set_id']])) {
                                                echo '<div class="table-responsive">';
                                                echo '<table class="table table-sm table-striped mb-0">';
                                                echo '<thead><tr><th class="text-dark">DHIS2 Option Code</th><th class="text-dark">Local Value</th></tr></thead>';
                                                echo '<tbody>';
                                                foreach ($optionSetMappings[$question['dhis2_option_set_id']] as $option) {
                                                    echo '<tr>';
                                                    echo '<td class="text-dark">'.htmlspecialchars($option['dhis2_option_code']).'</td>';
                                                    echo '<td class="text-dark">'.htmlspecialchars($option['local_value']).'</td>';
                                                    echo '</tr>';
                                                }
                                                echo '</tbody></table></div>';
                                            } else {
                                                echo '<p class="mb-0 text-muted">No option set mappings found</p>';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                <button type="submit" name="save_mapping" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Save All Mappings
                </button>
                <a href="?tab=new&dhis2_instance=<?= htmlspecialchars($_GET['dhis2_instance']) ?>&domain=<?= htmlspecialchars($_GET['domain']) ?>&<?= $_GET['domain'] === 'tracker' ? 'program='.htmlspecialchars($_GET['program']) : 'dataset='.htmlspecialchars($_GET['dataset']) ?>"
                   class="btn btn-outline-secondary ms-2">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </a>
            </div>
        <?php endif;
    }

    private function renderSingleQuestionMappingForm() {
        if (empty($_GET['question_id'])) {
            return;
        }

        $question = $this->getQuestionDetails($_GET['question_id']);
        $surveyId = $_GET['survey_id'] ?? null;
        $dataElements = $this->getDataElementsForSelection();
        $optionSets = $this->getOptionSetsForDataElements($dataElements);
        $optionSetMappings = $this->getOptionSetMappings(array_keys($optionSets));

        if (!$question): ?>
            <div class="alert alert-warning futuristic-alert">
                <i class="fas fa-exclamation-triangle me-2"></i> Question not found.
            </div>
        <?php else: ?>
            <div class="card mb-4 border-primary futuristic-card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Edit Question Mapping</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-dark">Question Details</h6>
                            <p class="text-dark"><strong>Label:</strong> <?= htmlspecialchars($question['label']) ?></p>
                            <p class="text-dark"><strong>Type:</strong> <?= htmlspecialchars($question['question_type']) ?></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-dark">DHIS2 Mapping</h6>
                            <div class="mb-3">
                                <label class="form-label text-dark">Data Element</label>
                                <select name="single_mapping[data_element]" class="form-select">
                                    <option value="">-- Not Mapped --</option>
                                    <?php foreach ($dataElements as $id => $element): ?>
                                        <?php $selected = $question['dhis2_dataelement_id'] === $id ? 'selected' : '' ?>
                                        <option value="<?= htmlspecialchars($id) ?>" <?= $selected ?>>
                                            <?= htmlspecialchars($element['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label text-dark">Option Set</label>
                                <select name="single_mapping[option_set]" class="form-select">
                                    <option value="">-- No Option Set --</option>
                                    <?php foreach ($optionSets as $id => $set): ?>
                                        <?php $selected = $question['dhis2_option_set_id'] === $id ? 'selected' : '' ?>
                                        <option value="<?= htmlspecialchars($id) ?>" <?= $selected ?>>
                                            <?= htmlspecialchars($set['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <input type="hidden" name="single_mapping[question_id]" value="<?= htmlspecialchars($question['id']) ?>">
                        </div>
                    </div>

                    <?php if (!empty($question['dhis2_option_set_id'])): ?>
                    <div class="mt-4">
                        <h6 class="text-dark">Option Set Values</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr>
                                        <th class="text-dark">DHIS2 Option Code</th>
                                        <th class="text-dark">Local Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if (!empty($optionSetMappings[$question['dhis2_option_set_id']])) {
                                        foreach ($optionSetMappings[$question['dhis2_option_set_id']] as $option) {
                                            echo '<tr>';
                                            echo '<td class="text-dark">'.htmlspecialchars($option['dhis2_option_code']).'</td>';
                                            echo '<td class="text-dark">'.htmlspecialchars($option['local_value']).'</td>';
                                            echo '</tr>';
                                        }
                                    } else {
                                        echo '<tr><td colspan="2" class="text-muted">No option mappings found</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="mt-4">
                        <button type="submit" name="save_single_mapping" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Mapping
                        </button>
                        <a href="?tab=questions&dhis2_instance=<?= htmlspecialchars($_GET['dhis2_instance']) ?>&domain=<?= htmlspecialchars($_GET['domain']) ?>&<?= $_GET['domain'] === 'tracker' ? 'program='.htmlspecialchars($_GET['program']) : 'dataset='.htmlspecialchars($_GET['dataset']) ?>&survey_id=<?= $surveyId ?>"
                           class="btn btn-outline-secondary ms-2">
                            <i class="fas fa-arrow-left me-1"></i> Back to All Questions
                        </a>
                    </div>
                </div>
            </div>
        <?php endif;
    }

    private function renderOptionSetJavaScript() {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Check if .view-options buttons exist, as this script might be included in a non-AJAX context
            const viewOptionButtons = document.querySelectorAll('.view-options');

            viewOptionButtons.forEach(button => {
                // Ensure event listeners are not duplicated if the script is re-executed
                if (!button.dataset.listenerAttached) {
                    button.addEventListener('click', function() {
                        const questionId = this.getAttribute('data-question-id');
                        const optionRow = document.getElementById('options-' + questionId);

                        if (optionRow.style.display === 'none') {
                            optionRow.style.display = 'table-row';
                            this.classList.add('active');
                        } else {
                            optionRow.style.display = 'none';
                            this.classList.remove('active');
                        }
                    });
                    button.dataset.listenerAttached = 'true'; // Mark as attached
                }
            });
        });
        </script>
        <?php
    }

    private function handleFormSubmissions() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (isset($_POST['save_single_mapping']) && $this->activeTab === 'questions') {
            $this->saveSingleMapping();
        }

        if (isset($_POST['save_mapping']) && $this->activeTab === 'questions') {
            $this->saveMultipleMappings();
        }
    }

    private function saveSingleMapping() {
        try {
            if (empty($_POST['single_mapping']) || empty($_POST['single_mapping']['question_id'])) {
                throw new Exception("Required parameters are missing");
            }

            $questionId = $_POST['single_mapping']['question_id'];
            $dataElement = $_POST['single_mapping']['data_element'] ?? null;
            $optionSet = $_POST['single_mapping']['option_set'] ?? null;

            $this->pdo->beginTransaction();

            $deleteStmt = $this->pdo->prepare("DELETE FROM question_dhis2_mapping WHERE question_id = ?");
            $deleteStmt->execute([$questionId]);

            if (!empty($dataElement)) {
                $insertStmt = $this->pdo->prepare("INSERT INTO question_dhis2_mapping (question_id, dhis2_dataelement_id, dhis2_option_set_id) VALUES (?, ?, ?)");
                $insertStmt->execute([$questionId, $dataElement, $optionSet]);
            }

            $this->pdo->commit();

            echo '<div class="alert alert-success mt-3 mb-3 futuristic-alert">
                <i class="fas fa-check-circle me-2"></i> Question mapping updated successfully
            </div>';

        } catch (Exception $e) {
            $this->pdo->rollBack();
            echo '<div class="alert alert-danger mt-3 mb-3 futuristic-alert">
                <i class="fas fa-exclamation-circle me-2"></i> Error: '.htmlspecialchars($e->getMessage()).'
            </div>';
        }
    }

    private function saveMultipleMappings() {
        try {
            if (empty($_POST['mapping'])) {
                throw new Exception("No mappings provided");
            }

            $this->pdo->beginTransaction();

            $questionIds = array_keys($_POST['mapping']);
            $placeholders = implode(',', array_fill(0, count($questionIds), '?'));

            $deleteStmt = $this->pdo->prepare("DELETE FROM question_dhis2_mapping WHERE question_id IN ($placeholders)");
            $deleteStmt->execute($questionIds);

            $insertStmt = $this->pdo->prepare("INSERT INTO question_dhis2_mapping (question_id, dhis2_dataelement_id, dhis2_option_set_id) VALUES (?, ?, ?)");

            foreach ($_POST['mapping'] as $questionId => $mapping) {
                if (!empty($mapping['data_element'])) {
                    $insertStmt->execute([$questionId, $mapping['data_element'], $mapping['option_set'] ?? null]);
                }
            }

            $this->pdo->commit();

            echo '<div class="alert alert-success mt-3 mb-3 futuristic-alert">
                <i class="fas fa-check-circle me-2"></i> Mappings saved successfully
            </div>';

        } catch (Exception $e) {
            $this->pdo->rollBack();
            echo '<div class="alert alert-danger mt-3 mb-3 futuristic-alert">
                <i class="fas fa-exclamation-circle me-2"></i> Error: '.htmlspecialchars($e->getMessage()).'
            </div>';
        }
    }

    // --- Helper methods for fetching data from DHIS2 and DB (adjusted for consistency) ---

    private function getTrackerPrograms($instanceKey) { // Parameter name adjusted
        if ($this->dhis2ConfigDetails === null || $this->dhis2ConfigDetails['instance_key'] !== $instanceKey) {
            $this->dhis2ConfigDetails = getDhis2Config($instanceKey);
            if (!$this->dhis2ConfigDetails) {
                error_log("new.php (Class): getTrackerPrograms could not find config for: " . $instanceKey);
                return [];
            }
        }
        $programs = dhis2_get('/programs?fields=id,name', $instanceKey);
        return $programs['programs'] ?? [];
    }

    private function getDatasets($instanceKey) { // Parameter name adjusted
        if ($this->dhis2ConfigDetails === null || $this->dhis2ConfigDetails['instance_key'] !== $instanceKey) {
            $this->dhis2ConfigDetails = getDhis2Config($instanceKey);
            if (!$this->dhis2ConfigDetails) {
                error_log("new.php (Class): getDatasets could not find config for: " . $instanceKey);
                return [];
            }
        }
        $datasets = dhis2_get('/dataSets?fields=id,name', $instanceKey);
        return $datasets['dataSets'] ?? [];
    }

    private function getDataElementsForSelection() {
        // This method assumes $_GET parameters for instance, domain, program/dataset are available
        // since it's called after these are selected via AJAX.
        if (empty($_GET['dhis2_instance']) || empty($_GET['domain'])) {
            return [];
        }

        $dataElements = [];
        $instanceKey = $_GET['dhis2_instance'];

        if ($this->dhis2ConfigDetails === null || $this->dhis2ConfigDetails['instance_key'] !== $instanceKey) {
            $this->dhis2ConfigDetails = getDhis2Config($instanceKey);
            if (!$this->dhis2ConfigDetails) {
                error_log("new.php (Class): getDataElementsForSelection could not find config for: " . $instanceKey);
                return [];
            }
        }

        if ($_GET['domain'] === 'tracker' && !empty($_GET['program'])) {
            $programInfo = dhis2_get('/programs/'.$_GET['program'].'?fields=id,name,programStages[programStageDataElements[dataElement[id,name,optionSet[id,name]]]', $instanceKey);

            if (!empty($programInfo['programStages'])) {
                foreach ($programInfo['programStages'] as $stage) {
                    if (isset($stage['programStageDataElements'])) {
                        foreach ($stage['programStageDataElements'] as $psde) {
                            $de = $psde['dataElement'];
                            $dataElements[$de['id']] = [
                                'name' => $de['name'],
                                'optionSet' => $de['optionSet'] ?? null
                            ];
                        }
                    }
                }
            }
        } elseif ($_GET['domain'] === 'aggregate' && !empty($_GET['dataset'])) {
            $datasetInfo = dhis2_get('/dataSets/'.$_GET['dataset'].'?fields=id,name,dataSetElements[dataElement[id,name,optionSet[id,name]]]', $instanceKey);

            if (!empty($datasetInfo['dataSetElements'])) {
                foreach ($datasetInfo['dataSetElements'] as $dse) {
                    $de = $dse['dataElement'];
                    $dataElements[$de['id']] = [
                        'name' => $de['name'],
                        'optionSet' => $de['optionSet'] ?? null
                    ];
                }
            }
        }

        return $dataElements;
    }

    private function getOptionSetsForDataElements($dataElements) {
        $optionSets = [];
        foreach ($dataElements as $element) {
            if (!empty($element['optionSet'])) {
                $optionSets[$element['optionSet']['id']] = $element['optionSet'];
            }
        }
        return $optionSets;
    }

    private function getMappedElements($elementIds) {
        if (empty($elementIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($elementIds), '?'));

        $checkStmt = $this->pdo->prepare("
            SELECT qdm.dhis2_dataelement_id, q.label, q.id as question_id, s.name as survey_name, s.id as survey_id
            FROM question_dhis2_mapping qdm
            JOIN question q ON qdm.question_id = q.id
            JOIN survey_question sq ON q.id = sq.question_id
            JOIN survey s ON sq.survey_id = s.id
            WHERE qdm.dhis2_dataelement_id IN ($placeholders)
        ");
        $checkStmt->execute($elementIds);
        return $checkStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getOptionSetMappings($setIds) {
        if (empty($setIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($setIds), '?'));
        $mappingStmt = $this->pdo->prepare("
            SELECT dhis2_option_set_id, local_value, dhis2_option_code
            FROM dhis2_option_set_mapping
            WHERE dhis2_option_set_id IN ($placeholders)
            ORDER BY dhis2_option_set_id, local_value
        ");
        $mappingStmt->execute($setIds);

        $optionSetMappings = [];
        while ($row = $mappingStmt->fetch(PDO::FETCH_ASSOC)) {
            $optionSetMappings[$row['dhis2_option_set_id']][] = $row;
        }

        return $optionSetMappings;
    }

    private function getSurveyQuestions($surveyId) {
        $stmt = $this->pdo->prepare("
            SELECT q.id, q.label, q.question_type, qdm.dhis2_dataelement_id, qdm.dhis2_option_set_id
            FROM survey_question sq
            JOIN question q ON sq.question_id = q.id
            LEFT JOIN question_dhis2_mapping qdm ON q.id = qdm.question_id
            WHERE sq.survey_id = ?
            ORDER BY sq.position
        ");
        $stmt->execute([$surveyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getQuestionDetails($questionId) {
        $stmt = $this->pdo->prepare("
            SELECT q.id, q.label, q.question_type, qdm.dhis2_dataelement_id, qdm.dhis2_option_set_id
            FROM question q
            LEFT JOIN question_dhis2_mapping qdm ON q.id = qdm.question_id
            WHERE q.id = ?
        ");
        $stmt->execute([$questionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function shouldShowQuestionMappingInterface() {
        return !empty($_GET['dhis2_instance']) && !empty($_GET['domain']) &&
               (($_GET['domain'] === 'tracker' && !empty($_GET['program'])) ||
                ($_GET['domain'] === 'aggregate' && !empty($_GET['dataset'])));
    }

    private function isEditingSingleQuestion() {
        return !empty($_GET['question_id']);
    }
}

// Initialize and render the interface
try {
    $selectedInstance = $_GET['dhis2_instance'] ?? '';
    $mappingInterface = new DHIS2MappingInterface($pdo, $activeTab, $selectedInstance);
    $mappingInterface->render();
} catch (Exception $e) {
    echo '<div class="alert alert-danger futuristic-alert">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    error_log("new.php: Exception during DHIS2MappingInterface render: " . $e->getMessage());
}
?>