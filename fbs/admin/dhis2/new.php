<?php
// settings.php - DHIS2 Mapping Interface

class DHIS2MappingInterface {
    private $pdo;
    private $activeTab;
    private $selectedInstance;
    
    public function __construct($pdo, $activeTab, $selectedInstance) {
        $this->pdo = $pdo;
        $this->activeTab = $activeTab;
        $this->selectedInstance = $selectedInstance;
    }
    
    public function render() {
        if ($this->activeTab == 'new') {
            $this->renderMappingStatusTab();
        } elseif ($this->activeTab == 'questions') {
            $this->renderQuestionMappingTab();
        }
        
        $this->handleFormSubmissions();
    }
    
    private function renderMappingStatusTab() {
        ?>
        <div class="tab-header">
            <h3><i class="fas fa-search me-2"></i>Check DHIS2 Mapping Status</h3>
        </div>
        
        <div class="card shadow-sm">
            <div class="card-body">
                <form method="get">
                    <input type="hidden" name="tab" value="new">
                    <?php $this->renderInstanceAndDomainSelection(); ?>
                    <?php $this->renderProgramOrDatasetSelection(); ?>
                    <?php $this->renderMappingStatusResults(); ?>
                </form>
            </div>
        </div>
        <?php
    }
    
    private function renderQuestionMappingTab() {
        ?>
        <div class="tab-header">
            <h3><i class="fas fa-map me-2"></i>Map DHIS2 Data Elements to Questions</h3>
        </div>
        
        <div class="card shadow-sm">
            <div class="card-body">
            <form method="post">
                <input type="hidden" name="tab" value="questions">

                <?php if ($this->shouldShowQuestionMappingInterface()): ?>
                <?php if ($this->isEditingSingleQuestion()): ?>
                    <!-- Render form for editing a single question mapping -->
                    <?php $this->renderSingleQuestionMappingForm(); ?>
                <?php else: ?>
                    <!-- Render survey selection and question list for mapping -->
                    <?php $this->renderSurveySelection(); ?>
                    <?php $this->renderQuestionListForMapping(); ?>
                <?php endif; ?>
                <?php endif; ?>
            </form>
            </div>
        </div>
        <?php $this->renderOptionSetJavaScript(); ?>
        <?php
    }
    
    private function renderInstanceAndDomainSelection() {
        $selectedInstance = $_GET['dhis2_instance'] ?? '';
        $selectedDomain = $_GET['domain'] ?? '';
        ?>
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="form-group">
                    <label class="form-control-label">Select DHIS2 Instance</label>
                    <select name="dhis2_instance" class="form-control form-select" onchange="this.form.submit()">
                        <option value="">-- Select Instance --</option>
                        <?php 
                        $jsonConfig = $this->getDHIS2Config();
                        foreach ($jsonConfig as $key => $config) : ?>
                            <option value="<?= htmlspecialchars($key) ?>" <?= ($selectedInstance == $key) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($key) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <?php if (!empty($selectedInstance)): ?>
            <div class="col-md-6">
                <label class="form-label">Data Domain</label>
                <select name="domain" class="form-select" required onchange="this.form.submit()">
                    <option value="">-- Select Domain --</option>
                    <option value="tracker" <?= $selectedDomain == 'tracker' ? 'selected' : '' ?>>Tracker</option>
                    <option value="aggregate" <?= $selectedDomain == 'aggregate' ? 'selected' : '' ?>>Aggregate</option>
                </select>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function renderProgramOrDatasetSelection() {
        if (empty($_GET['dhis2_instance']) || empty($_GET['domain'])) {
            return;
        }
        
        $selectedDomain = $_GET['domain'];
        $selectedProgram = $_GET['program'] ?? '';
        $selectedDataset = $_GET['dataset'] ?? '';
        ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <?php if ($selectedDomain == 'tracker'): ?>
                    <label class="form-label">Program</label>
                    <select name="program" class="form-select" required onchange="this.form.submit()">
                        <option value="">-- Select Program --</option>
                        <?php
                        try {
                            $programs = $this->getTrackerPrograms($_GET['dhis2_instance']);
                            foreach ($programs as $program) {
                                $selected = $selectedProgram == $program['id'] ? 'selected' : '';
                                echo '<option value="'.htmlspecialchars($program['id']).'" '.$selected.'>'.htmlspecialchars($program['name']).'</option>';
                            }
                        } catch (Exception $e) {
                            echo '<option value="" disabled>Error loading programs: '.htmlspecialchars($e->getMessage()).'</option>';
                        }
                        ?>
                    </select>
                <?php elseif ($selectedDomain == 'aggregate'): ?>
                    <label class="form-label">Data Set</label>
                    <select name="dataset" class="form-select" required onchange="this.form.submit()">
                        <option value="">-- Select Data Set --</option>
                        <?php
                        try {
                            $datasets = $this->getDatasets($_GET['dhis2_instance']);
                            foreach ($datasets as $dataset) {
                                $selected = $selectedDataset == $dataset['id'] ? 'selected' : '';
                                echo '<option value="'.htmlspecialchars($dataset['id']).'" '.$selected.'>'.htmlspecialchars($dataset['name']).'</option>';
                            }
                        } catch (Exception $e) {
                            echo '<option value="" disabled>Error loading datasets: '.htmlspecialchars($e->getMessage()).'</option>';
                        }
                        ?>
                    </select>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    private function renderMappingStatusResults() {
        if (empty($_GET['dhis2_instance']) || empty($_GET['domain']) || 
            ($_GET['domain'] == 'tracker' && empty($_GET['program'])) || 
            ($_GET['domain'] == 'aggregate' && empty($_GET['dataset']))) {
            return;
        }
        
        $dataElements = $this->getDataElementsForSelection();
        $mappedElements = $this->getMappedElements(array_keys($dataElements));
        
        if (empty($dataElements)) {
            echo '<div class="alert alert-warning">No data elements found</div>';
            return;
        }
        
        if (!empty($mappedElements)): ?>
            <div class="alert alert-info border-0 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <i class="fas fa-map-marker-alt fa-2x me-3 text-primary"></i>
                    <h5 class="mb-0">Existing Mappings Found</h5>
                </div>
                <p>The following data elements are already mapped to questions:</p>
                
                <div class="table-responsive mt-3">
                    <div class="mb-3">
                        <label for="surveyFilter" class="form-label">Filter by Survey</label>
                        <select id="surveyFilter" class="form-select" onchange="filterBySurvey()">
                            <option value="">-- All Surveys --</option>
                            <?php foreach (array_unique(array_column($mappedElements, 'survey_name')) as $surveyName): ?>
                                <option value="<?= htmlspecialchars($surveyName) ?>"><?= htmlspecialchars($surveyName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <table class="table table-striped table-hover" id="mappingTable">
                        <thead class="table-light">
                            <tr>
                                <th>Data Element</th>
                                <th>Mapped Question</th>
                                <th>Survey</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mappedElements as $mapping): ?>
                                <tr data-survey="<?= htmlspecialchars($mapping['survey_name']) ?>">
                                    <td><?= htmlspecialchars($dataElements[$mapping['dhis2_dataelement_id']]['name']) ?></td>
                                    <td><?= htmlspecialchars($mapping['label']) ?></td>
                                    <td><?= htmlspecialchars($mapping['survey_name']) ?></td>
                                    <td class="text-center">
                                        <a href="?tab=questions&dhis2_instance=<?= htmlspecialchars($_GET['dhis2_instance']) ?>&domain=<?= htmlspecialchars($_GET['domain']) ?>&<?= $_GET['domain'] == 'tracker' ? 'program='.htmlspecialchars($_GET['program']) : 'dataset='.htmlspecialchars($_GET['dataset']) ?>&survey_id=<?= $mapping['survey_id'] ?>&question_id=<?= $mapping['question_id'] ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <script>
                    function filterBySurvey() {
                        const filter = document.getElementById('surveyFilter').value.toLowerCase();
                        const rows = document.querySelectorAll('#mappingTable tbody tr');
                        
                        rows.forEach(row => {
                            const survey = row.getAttribute('data-survey').toLowerCase();
                            row.style.display = filter === '' || survey === filter ? '' : 'none';
                        });
                    }
                </script>
                
               
            </div>
        <?php else: ?>
            <div class="alert alert-warning border-0 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <i class="fas fa-exclamation-triangle fa-2x me-3 text-warning"></i>
                    <h5 class="mb-0">No Existing Mappings Found</h5>
                </div>
                <p>None of the <?= count($dataElements) ?> data elements in this <?= htmlspecialchars($_GET['domain']) == 'tracker' ? 'program' : 'dataset' ?> are currently mapped to any questions in the system.</p>
             

                <div class="table-responsive mt-3">
                    <form method="post">
                        
                        <table class="table table-striped table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Data Element</th>
                                    <th>Question Name</th>
                                    <th>Option Set</th>
                                    <!-- <th class="text-center">Action</th> -->
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dataElements as $id => $element): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($element['name']) ?></td>
                                        <td>
                                            <input type="text" name="new_mapping[<?= $id ?>][question_name]" 
                                                   class="form-control form-control-sm" 
                                                   value="<?= htmlspecialchars($element['name']) ?>" 
                                                   required>
                                        </td>
                                        <td>
                                            <?php if (!empty($element['optionSet'])): ?>
                                                <select name="new_mapping[<?= $id ?>][option_set]" class="form-select form-select-sm">
                                                    <option value="">-- Select Option Set --</option>
                                                    <?php foreach ($this->getOptionSetsForDataElements([$element]) as $optionSetId => $optionSet): ?>
                                                        <option value="<?= htmlspecialchars($optionSetId) ?>">
                                                            <?= htmlspecialchars($optionSet['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                      
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </form>
                </div>

              
            </div>
        <?php endif;
    }
    
    private function renderSurveySelection() {
        if (empty($_GET['dhis2_instance']) || empty($_GET['domain']) || 
            ($_GET['domain'] == 'tracker' && empty($_GET['program'])) || 
            ($_GET['domain'] == 'aggregate' && empty($_GET['dataset']))) {
            return;
        }
        
        $selectedSurvey = $_GET['survey_id'] ?? '';
        ?>
        <div class="mb-4">
            <label class="form-label">Selected Survey</label>
            <p class="form-control-plaintext">
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
        $dataElements = $this->getDataElementsForSelection();
        $optionSets = $this->getOptionSetsForDataElements($dataElements);
        $optionSetMappings = $this->getOptionSetMappings(array_keys($optionSets));
        
        if (empty($questions)): ?>
            <div class="alert alert-info text-center py-4">
                <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                <h4>No Questions Found in This Survey</h4>
                <p class="mb-0">This survey doesn't contain any questions yet.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped">
                    <thead class="table-light">
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
                                    <strong><?= htmlspecialchars($question['label']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($question['question_type']) ?></small>
                                </td>
                                <td>
                                    <select name="mapping[<?= $question['id'] ?>][data_element]" class="form-select form-select-sm">
                                        <option value="">-- Not Mapped --</option>
                                        <?php foreach ($dataElements as $id => $element): ?>
                                            <?php $selected = $question['dhis2_dataelement_id'] == $id ? 'selected' : '' ?>
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
                                            <?php $selected = $question['dhis2_option_set_id'] == $id ? 'selected' : '' ?>
                                            <option value="<?= htmlspecialchars($id) ?>" <?= $selected ?>>
                                                <?= htmlspecialchars($set['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="text-center">
                                    <a href="?tab=questions&dhis2_instance=<?= htmlspecialchars($_GET['dhis2_instance']) ?>&domain=<?= htmlspecialchars($_GET['domain']) ?>&<?= $_GET['domain'] == 'tracker' ? 'program='.htmlspecialchars($_GET['program']) : 'dataset='.htmlspecialchars($_GET['dataset']) ?>&survey_id=<?= $surveyId ?>&question_id=<?= $question['id'] ?>" 
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
                                    <div class="p-3 bg-light rounded">
                                        <h6 class="mb-3"><i class="fas fa-list-ul me-2"></i>Option Set Values</h6>
                                        <div class="options-container" id="options-container-<?= $question['id'] ?>">
                                            <?php
                                            if (!empty($question['dhis2_option_set_id']) && !empty($optionSetMappings[$question['dhis2_option_set_id']])) {
                                                echo '<div class="table-responsive">';
                                                echo '<table class="table table-sm table-striped mb-0">';
                                                echo '<thead><tr><th>DHIS2 Option Code</th><th>Local Value</th></tr></thead>';
                                                echo '<tbody>';
                                                foreach ($optionSetMappings[$question['dhis2_option_set_id']] as $option) {
                                                    echo '<tr>';
                                                    echo '<td>'.htmlspecialchars($option['dhis2_option_code']).'</td>';
                                                    echo '<td>'.htmlspecialchars($option['local_value']).'</td>';
                                                    echo '</tr>';
                                                }
                                                echo '</tbody></table></div>';
                                            } else {
                                                echo '<p class="mb-0">No option set mappings found</p>';
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
                <a href="?tab=new&dhis2_instance=<?= htmlspecialchars($_GET['dhis2_instance']) ?>&domain=<?= htmlspecialchars($_GET['domain']) ?>&<?= $_GET['domain'] == 'tracker' ? 'program='.htmlspecialchars($_GET['program']) : 'dataset='.htmlspecialchars($_GET['dataset']) ?>" 
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
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i> Question not found.
            </div>
        <?php else: ?>
            <div class="card mb-4 border-primary">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Edit Question Mapping</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Question Details</h6>
                            <p><strong>Label:</strong> <?= htmlspecialchars($question['label']) ?></p>
                            <p><strong>Type:</strong> <?= htmlspecialchars($question['question_type']) ?></p>
                        </div>
                        <div class="col-md-6">
                            <h6>DHIS2 Mapping</h6>
                            <div class="mb-3">
                                <label class="form-label">Data Element</label>
                                <select name="single_mapping[data_element]" class="form-select">
                                    <option value="">-- Not Mapped --</option>
                                    <?php foreach ($dataElements as $id => $element): ?>
                                        <?php $selected = $question['dhis2_dataelement_id'] == $id ? 'selected' : '' ?>
                                        <option value="<?= htmlspecialchars($id) ?>" <?= $selected ?>>
                                            <?= htmlspecialchars($element['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Option Set</label>
                                <select name="single_mapping[option_set]" class="form-select">
                                    <option value="">-- No Option Set --</option>
                                    <?php foreach ($optionSets as $id => $set): ?>
                                        <?php $selected = $question['dhis2_option_set_id'] == $id ? 'selected' : '' ?>
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
                        <h6>Option Set Values</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr>
                                        <th>DHIS2 Option Code</th>
                                        <th>Local Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    if (!empty($optionSetMappings[$question['dhis2_option_set_id']])) {
                                        foreach ($optionSetMappings[$question['dhis2_option_set_id']] as $option) {
                                            echo '<tr>';
                                            echo '<td>'.htmlspecialchars($option['dhis2_option_code']).'</td>';
                                            echo '<td>'.htmlspecialchars($option['local_value']).'</td>';
                                            echo '</tr>';
                                        }
                                    } else {
                                        echo '<tr><td colspan="2">No option mappings found</td></tr>';
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
                        <a href="?tab=questions&dhis2_instance=<?= htmlspecialchars($_GET['dhis2_instance']) ?>&domain=<?= htmlspecialchars($_GET['domain']) ?>&<?= $_GET['domain'] == 'tracker' ? 'program='.htmlspecialchars($_GET['program']) : 'dataset='.htmlspecialchars($_GET['dataset']) ?>&survey_id=<?= $surveyId ?>" 
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
            const viewOptionButtons = document.querySelectorAll('.view-options');
            
            viewOptionButtons.forEach(button => {
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
            });
        });
        </script>
        <?php
    }
    
    private function handleFormSubmissions() {
        if ($_SERVER['REQUEST_METHOD'] != 'POST') {
            return;
        }
        
        if (isset($_POST['save_single_mapping']) && $this->activeTab == 'questions') {
            $this->saveSingleMapping();
        }
        
        if (isset($_POST['save_mapping']) && $this->activeTab == 'questions') {
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
            
            echo '<div class="alert alert-success mt-3 mb-3">
                <i class="fas fa-check-circle me-2"></i> Question mapping updated successfully
            </div>';
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            echo '<div class="alert alert-danger mt-3 mb-3">
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
            
            echo '<div class="alert alert-success mt-3 mb-3">
                <i class="fas fa-check-circle me-2"></i> Mappings saved successfully
            </div>';
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            echo '<div class="alert alert-danger mt-3 mb-3">
                <i class="fas fa-exclamation-circle me-2"></i> Error: '.htmlspecialchars($e->getMessage()).'
            </div>';
        }
    }
    
    // Helper methods
    
    private function getDHIS2Config() {
        $configFile = 'dhis2/dhis2.json';
        if (!file_exists($configFile)) {
            throw new Exception("DHIS2 configuration file not found");
        }
        
        $config = json_decode(file_get_contents($configFile), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid DHIS2 configuration: " . json_last_error_msg());
        }
        
        return $config;
    }
    
    private function getTrackerPrograms($instance) {
        $programs = dhis2_get('/api/programs?fields=id,name', $instance);
        return $programs['programs'] ?? [];
    }
    
    private function getDatasets($instance) {
        $datasets = dhis2_get('/api/dataSets?fields=id,name', $instance);
        return $datasets['dataSets'] ?? [];
    }
    
    private function getDataElementsForSelection() {
        if (empty($_GET['dhis2_instance']) || empty($_GET['domain'])) {
            return [];
        }
        
        $dataElements = [];
        
        if ($_GET['domain'] == 'tracker' && !empty($_GET['program'])) {
            $programInfo = dhis2_get('/api/programs/'.$_GET['program'].'?fields=id,name,programStages[programStageDataElements[dataElement[id,name,optionSet[id,name]]]', $_GET['dhis2_instance']);
            
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
        } elseif ($_GET['domain'] == 'aggregate' && !empty($_GET['dataset'])) {
            $datasetInfo = dhis2_get('/api/dataSets/'.$_GET['dataset'].'?fields=id,name,dataSetElements[dataElement[id,name,optionSet[id,name]]]', $_GET['dhis2_instance']);
            
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
               (($_GET['domain'] == 'tracker' && !empty($_GET['program'])) || 
                ($_GET['domain'] == 'aggregate' && !empty($_GET['dataset'])));
    }
    
    private function isEditingSingleQuestion() {
        return !empty($_GET['question_id']);
    }
}

// Initialize and render the interface
try {
    $mappingInterface = new DHIS2MappingInterface($pdo, $activeTab, $selectedInstance);
    $mappingInterface->render();
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>