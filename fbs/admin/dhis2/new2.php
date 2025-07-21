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
                <form method="post">
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
        $selectedInstance = $_POST['dhis2_instance'] ?? '';
        $selectedDomain = $_POST['domain'] ?? '';
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

    private function renderMappingStatusResults() {
        if (empty($_POST['dhis2_instance']) || empty($_POST['domain']) || 
            ($_POST['domain'] == 'tracker' && empty($_POST['program'])) || 
            ($_POST['domain'] == 'aggregate' && empty($_POST['dataset']))) {
            return;
        }

        $dataElements = $this->getDataElementsForSelection();
        $mappedElements = $this->getMappedElements(array_keys($dataElements));

        if (empty($dataElements)) {
            echo '<div class="alert alert-warning">No data elements found</div>';
            return;
        }

        ?>
        <div class="alert alert-info border-0 shadow-sm">
            <div class="d-flex align-items-center mb-3">
                <i class="fas fa-map-marker-alt fa-2x me-3 text-primary"></i>
                <h5 class="mb-0">Existing Mappings Found</h5>
            </div>
            <p>Select a survey to view mappings:</p>
            <select id="survey-dropdown" class="form-select mb-3" onchange="filterMappingsBySurvey(this.value)">
                <option value="">-- Select Survey --</option>
                <?php foreach ($mappedElements as $surveyId => $surveyMappings): ?>
                    <option value="<?= htmlspecialchars($surveyId) ?>"><?= htmlspecialchars($surveyMappings['survey_name']) ?></option>
                <?php endforeach; ?>
            </select>

            <div id="survey-mappings-container">
                <!-- Mappings will be dynamically loaded here based on the selected survey -->
            </div>
        </div>

        <script>
        function filterMappingsBySurvey(surveyId) {
            const mappingsContainer = document.getElementById('survey-mappings-container');
            mappingsContainer.innerHTML = ''; // Clear previous mappings

            if (!surveyId) return;

            // Fetch and display mappings for the selected survey
            const mappings = <?= json_encode($mappedElements) ?>;
            if (mappings[surveyId]) {
                const table = document.createElement('table');
                table.className = 'table table-striped table-hover';
                table.innerHTML = `
                    <thead class="table-light">
                        <tr>
                            <th>Data Element</th>
                            <th>Mapped Question</th>
                            <th>Survey</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${mappings[surveyId].map(mapping => `
                            <tr>
                                <td>${mapping.dataElement}</td>
                                <td>${mapping.question}</td>
                                <td>${mapping.survey}</td>
                                <td class="text-center">
                                    <a href="?tab=questions&survey_id=${mapping.survey_id}" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                `;
                mappingsContainer.appendChild(table);
            }
        }
        </script>
        <?php
    }

    private function handleFormSubmissions() {
        if ($_SERVER['REQUEST_METHOD'] != 'POST') {
            return;
        }

        if (isset($_POST['save_single_mapping']) && $this->activeTab == 'questions') {
            $this->saveSingleMapping();
            header("Location: ?tab=questions&survey_id=" . $_POST['survey_id']);
            exit;
        }

        if (isset($_POST['save_mapping']) && $this->activeTab == 'questions') {
            $this->saveMultipleMappings();
            header("Location: ?tab=questions&survey_id=" . $_POST['survey_id']);
            exit;
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