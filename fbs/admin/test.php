<?php
// settings.php - DHIS2 Mapping Interface

// New Tab - Check Mapping Status
if ($activeTab == 'new') : ?>
    <div class="tab-header">
        <h3><i class="fas fa-search me-2"></i>Check DHIS2 Mapping Status</h3>
    </div>
    
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="get">
                <input type="hidden" name="tab" value="new">
                
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-control-label">Select DHIS2 Instance</label>
                            <select name="dhis2_instance" class="form-control form-select" id="dhis2InstanceSelect" onchange="this.form.submit()">
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
                    
                    <?php if (!empty($_GET['dhis2_instance'])): ?>
                    <div class="col-md-6">
                        <label class="form-label">Data Domain</label>
                        <select name="domain" class="form-select" required onchange="this.form.submit()">
                            <option value="">-- Select Domain --</option>
                            <option value="tracker" <?= ($_GET['domain'] ?? '') == 'tracker' ? 'selected' : '' ?>>Tracker</option>
                            <option value="aggregate" <?= ($_GET['domain'] ?? '') == 'aggregate' ? 'selected' : '' ?>>Aggregate</option>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($_GET['dhis2_instance']) && !empty($_GET['domain'])): ?>
                <div class="row mb-4">
                    <div class="col-md-12">
                        <?php if ($_GET['domain'] == 'tracker'): ?>
                        <label class="form-label">Event Program</label>
                        <select name="program" class="form-select" required onchange="this.form.submit()">
                            <option value="">-- Select Program --</option>
                            <?php
                            try {
                                $programs = dhis2_get('/api/programs?filter=programType:eq:WITHOUT_REGISTRATION&fields=id,name', $_GET['dhis2_instance']);
                                if (isset($programs['programs'])) {
                                    foreach ($programs['programs'] as $program) {
                                        $selected = ($_GET['program'] ?? '') == $program['id'] ? 'selected' : '';
                                        echo '<option value="'.$program['id'].'" '.$selected.'>'.$program['name'].'</option>';
                                    }
                                }
                            } catch (Exception $e) {
                                echo '<option value="" disabled>Error loading programs: '.htmlspecialchars($e->getMessage()).'</option>';
                            }
                            ?>
                        </select>
                        <?php elseif ($_GET['domain'] == 'aggregate'): ?>
                        <label class="form-label">Data Set</label>
                        <select name="dataset" class="form-select" required onchange="this.form.submit()">
                            <option value="">-- Select Data Set --</option>
                            <?php
                            try {
                                $datasets = dhis2_get('/api/dataSets?fields=id,name', $_GET['dhis2_instance']);
                                if (isset($datasets['dataSets'])) {
                                    foreach ($datasets['dataSets'] as $dataset) {
                                        $selected = ($_GET['dataset'] ?? '') == $dataset['id'] ? 'selected' : '';
                                        echo '<option value="'.$dataset['id'].'" '.$selected.'>'.$dataset['name'].'</option>';
                                    }
                                }
                            } catch (Exception $e) {
                                echo '<option value="" disabled>Error loading datasets: '.htmlspecialchars($e->getMessage()).'</option>';
                            }
                            ?>
                        </select>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php 
                // Check if program or dataset is selected and fetch data elements
                $dataElements = [];
                $optionSets = [];
                
                if (!empty($_GET['dhis2_instance']) && !empty($_GET['domain'])) {
                    if ($_GET['domain'] == 'tracker' && !empty($_GET['program'])) {
                        // Get program data elements
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
                                        
                                        if (!empty($de['optionSet'])) {
                                            $optionSets[$de['optionSet']['id']] = $de['optionSet'];
                                        }
                                    }
                                }
                            }
                        }
                    } elseif ($_GET['domain'] == 'aggregate' && !empty($_GET['dataset'])) {
                        // Get dataset data elements
                        $datasetInfo = dhis2_get('/api/dataSets/'.$_GET['dataset'].'?fields=id,name,dataSetElements[dataElement[id,name,optionSet[id,name]]]', $_GET['dhis2_instance']);
                        
                        if (!empty($datasetInfo['dataSetElements'])) {
                            foreach ($datasetInfo['dataSetElements'] as $dse) {
                                $de = $dse['dataElement'];
                                $dataElements[$de['id']] = [
                                    'name' => $de['name'],
                                    'optionSet' => $de['optionSet'] ?? null
                                ];
                                
                                if (!empty($de['optionSet'])) {
                                    $optionSets[$de['optionSet']['id']] = $de['optionSet'];
                                }
                            }
                        }
                    }
                }
                
                // Check if any of these data elements are already mapped
                $mappedElements = [];
                if (!empty($dataElements)) {
                    $elementIds = array_keys($dataElements);
                    $placeholders = implode(',', array_fill(0, count($elementIds), '?'));
                    
                    $checkStmt = $pdo->prepare("
                        SELECT qdm.dhis2_dataelement_id, q.label, q.id as question_id, s.name as survey_name, s.id as survey_id
                        FROM question_dhis2_mapping qdm
                        JOIN question q ON qdm.question_id = q.id
                        JOIN survey_question sq ON q.id = sq.question_id
                        JOIN survey s ON sq.survey_id = s.id
                        WHERE qdm.dhis2_dataelement_id IN ($placeholders)
                    ");
                    $checkStmt->execute($elementIds);
                    $mappedElements = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
                }
                
                // Display results if we have data elements
                if (!empty($dataElements) && ($_GET['domain'] == 'tracker' && !empty($_GET['program']) || 
                                             $_GET['domain'] == 'aggregate' && !empty($_GET['dataset']))):
                    if (!empty($mappedElements)): ?>
                        <div class="alert alert-info border-0 shadow-sm">
                            <div class="d-flex align-items-center mb-3">
                                <i class="fas fa-map-marker-alt fa-2x me-3 text-primary"></i>
                                <h5 class="mb-0">Existing Mappings Found</h5>
                            </div>
                            <p>The following data elements are already mapped to questions:</p>
                            
                            <div class="table-responsive mt-3">
                                <table class="table table-striped table-hover">
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
                                            <tr>
                                                <td><?= htmlspecialchars($dataElements[$mapping['dhis2_dataelement_id']]['name']) ?></td>
                                                <td><?= htmlspecialchars($mapping['label']) ?></td>
                                                <td><?= htmlspecialchars($mapping['survey_name']) ?></td>
                                                <td class="text-center">
                                                    <a href="?tab=questions&dhis2_instance=<?= $_GET['dhis2_instance'] ?>&domain=<?= $_GET['domain'] ?>&<?= $_GET['domain'] == 'tracker' ? 'program='.$_GET['program'] : 'dataset='.$_GET['dataset'] ?>&survey_id=<?= $mapping['survey_id'] ?>&question_id=<?= $mapping['question_id'] ?>" 
                                                       class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="mt-3">
                                <a href="?tab=questions&dhis2_instance=<?= $_GET['dhis2_instance'] ?>&domain=<?= $_GET['domain'] ?>&<?= $_GET['domain'] == 'tracker' ? 'program='.$_GET['program'] : 'dataset='.$_GET['dataset'] ?>" 
                                   class="btn btn-primary">
                                    <i class="fas fa-edit me-1"></i> View & Edit All Mappings
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning border-0 shadow-sm">
                            <div class="d-flex align-items-center mb-3">
                                <i class="fas fa-exclamation-triangle fa-2x me-3 text-warning"></i>
                                <h5 class="mb-0">No Existing Mappings Found</h5>
                            </div>
                            <p>None of the <?= count($dataElements) ?> data elements in this <?= $_GET['domain'] == 'tracker' ? 'program' : 'dataset' ?> are currently mapped to questions.</p>
                            
                            <div class="mt-3">
                                <a href="?tab=questions&dhis2_instance=<?= $_GET['dhis2_instance'] ?>&domain=<?= $_GET['domain'] ?>&<?= $_GET['domain'] == 'tracker' ? 'program='.$_GET['program'] : 'dataset='.$_GET['dataset'] ?>" 
                                   class="btn btn-primary">
                                    <i class="fas fa-plus-circle me-1"></i> Create Mappings
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </form>
        </div>
    </div>
<?php elseif ($activeTab == 'questions') : ?>
    <div class="tab-header">
        <h3><i class="fas fa-map me-2"></i>Map DHIS2 Data Elements to Questions</h3>
    </div>
    
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="tab" value="questions">
                
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-control-label">DHIS2 Instance</label>
                            <select name="dhis2_instance" class="form-control form-select" id="dhis2InstanceSelect" onchange="this.form.submit()">
                                <option value="">-- Select Instance --</option>
                                <?php 
                                $jsonConfig = json_decode(file_get_contents('dhis2/dhis2.json'), true);
                                foreach ($jsonConfig as $key => $config) : ?>
                                    <option value="<?= $key ?>" <?= ($selectedInstance == $key || ($_GET['dhis2_instance'] ?? '') == $key) ? 'selected' : '' ?>>
                                        <?= $key ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <?php if (!empty($_GET['dhis2_instance'])): ?>
                    <div class="col-md-4">
                        <label class="form-label">Data Domain</label>
                        <select name="domain" class="form-select" required onchange="this.form.submit()">
                            <option value="">-- Select Domain --</option>
                            <option value="tracker" <?= ($_GET['domain'] ?? '') == 'tracker' ? 'selected' : '' ?>>Tracker</option>
                            <option value="aggregate" <?= ($_GET['domain'] ?? '') == 'aggregate' ? 'selected' : '' ?>>Aggregate</option>
                        </select>
                    </div>
                    
                    <?php if (!empty($_GET['domain'])): ?>
                    <div class="col-md-4">
                        <?php if ($_GET['domain'] == 'tracker'): ?>
                        <label class="form-label">Event Program</label>
                        <select name="program" class="form-select" required onchange="this.form.submit()">
                            <option value="">-- Select Program --</option>
                            <?php
                            try {
                                $programs = dhis2_get('/api/programs?filter=programType:eq:WITHOUT_REGISTRATION&fields=id,name', $_GET['dhis2_instance']);
                                if (isset($programs['programs'])) {
                                    foreach ($programs['programs'] as $program) {
                                        $selected = ($_GET['program'] ?? '') == $program['id'] ? 'selected' : '';
                                        echo '<option value="'.$program['id'].'" '.$selected.'>'.$program['name'].'</option>';
                                    }
                                }
                            } catch (Exception $e) {
                                echo '<option value="" disabled>Error loading programs: '.htmlspecialchars($e->getMessage()).'</option>';
                            }
                            ?>
                        </select>
                        <?php elseif ($_GET['domain'] == 'aggregate'): ?>
                        <label class="form-label">Data Set</label>
                        <select name="dataset" class="form-select" required onchange="this.form.submit()">
                            <option value="">-- Select Data Set --</option>
                            <?php
                            try {
                                $datasets = dhis2_get('/api/dataSets?fields=id,name', $_GET['dhis2_instance']);
                                if (isset($datasets['dataSets'])) {
                                    foreach ($datasets['dataSets'] as $dataset) {
                                        $selected = ($_GET['dataset'] ?? '') == $dataset['id'] ? 'selected' : '';
                                        echo '<option value="'.$dataset['id'].'" '.$selected.'>'.$dataset['name'].'</option>';
                                    }
                                }
                            } catch (Exception $e) {
                                echo '<option value="" disabled>Error loading datasets: '.htmlspecialchars($e->getMessage()).'</option>';
                            }
                            ?>
                        </select>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                
                <?php 
                // Check if we have the required data for mapping
                $dataElements = [];
                $optionSets = [];
                
                if (!empty($_GET['dhis2_instance']) && !empty($_GET['domain'])) {
                    if ($_GET['domain'] == 'tracker' && !empty($_GET['program'])) {
                        // Get program data elements
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
                                        
                                        if (!empty($de['optionSet'])) {
                                            $optionSets[$de['optionSet']['id']] = $de['optionSet'];
                                        }
                                    }
                                }
                            }
                        }
                    } elseif ($_GET['domain'] == 'aggregate' && !empty($_GET['dataset'])) {
                        // Get dataset data elements
                        $datasetInfo = dhis2_get('/api/dataSets/'.$_GET['dataset'].'?fields=id,name,dataSetElements[dataElement[id,name,optionSet[id,name]]]', $_GET['dhis2_instance']);
                        
                        if (!empty($datasetInfo['dataSetElements'])) {
                            foreach ($datasetInfo['dataSetElements'] as $dse) {
                                $de = $dse['dataElement'];
                                $dataElements[$de['id']] = [
                                    'name' => $de['name'],
                                    'optionSet' => $de['optionSet'] ?? null
                                ];
                                
                                if (!empty($de['optionSet'])) {
                                    $optionSets[$de['optionSet']['id']] = $de['optionSet'];
                                }
                            }
                        }
                    }
                }
                
                // Get option set mappings if we have any option sets
                $optionSetMappings = [];
                if (!empty($optionSets)) {
                    $setIds = array_keys($optionSets);
                    $placeholders = implode(',', array_fill(0, count($setIds), '?'));
                    
                    $mappingStmt = $pdo->prepare("
                        SELECT dhis2_option_set_id, local_value, dhis2_option_code 
                        FROM dhis2_option_set_mapping 
                        WHERE dhis2_option_set_id IN ($placeholders)
                        ORDER BY dhis2_option_set_id, local_value
                    ");
                    $mappingStmt->execute($setIds);
                    
                    while ($row = $mappingStmt->fetch(PDO::FETCH_ASSOC)) {
                        $optionSetMappings[$row['dhis2_option_set_id']][] = $row;
                    }
                }
                
                // Handle two modes: editing a specific question or showing all questions for a survey
                $editingSingleQuestion = !empty($_GET['question_id']);
                $surveyId = $_GET['survey_id'] ?? null;
                
                if ($editingSingleQuestion && !empty($_GET['question_id'])) {
                    // Get specific question
                    $questionStmt = $pdo->prepare("
                        SELECT q.id, q.label, q.question_type, qdm.dhis2_dataelement_id, qdm.dhis2_option_set_id
                        FROM question q
                        LEFT JOIN question_dhis2_mapping qdm ON q.id = qdm.question_id
                        WHERE q.id = ?
                    ");
                    $questionStmt->execute([$_GET['question_id']]);
                    $question = $questionStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($question): ?>
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
                                                    <option value="<?= $id ?>" <?= $selected ?>>
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
                                                    <option value="<?= $id ?>" <?= $selected ?>>
                                                        <?= htmlspecialchars($set['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <input type="hidden" name="single_mapping[question_id]" value="<?= $question['id'] ?>">
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
                                    <a href="?tab=questions&dhis2_instance=<?= $_GET['dhis2_instance'] ?>&domain=<?= $_GET['domain'] ?>&<?= $_GET['domain'] == 'tracker' ? 'program='.$_GET['program'] : 'dataset='.$_GET['dataset'] ?>&survey_id=<?= $surveyId ?>" 
                                       class="btn btn-outline-secondary ms-2">
                                        <i class="fas fa-arrow-left me-1"></i> Back to All Questions
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i> Question not found.
                        </div>
                    <?php endif; ?>
                    
                <?php elseif (!empty($_GET['dhis2_instance']) && !empty($_GET['domain']) && 
                           (($_GET['domain'] == 'tracker' && !empty($_GET['program'])) || 
                            ($_GET['domain'] == 'aggregate' && !empty($_GET['dataset'])))):
                    
                    // Choose or create a survey
                    ?>
                    <div class="mb-4">
                        <label class="form-label">Select Survey to Map Questions From</label>
                        <select name="survey_id" class="form-select" onchange="this.form.submit()">
                            <option value="">-- Select Survey --</option>
                            <?php
                            $surveys = $pdo->query("SELECT id, name FROM survey ORDER BY name");
                            while ($survey = $surveys->fetch(PDO::FETCH_ASSOC)) {
                                $selected = isset($_GET['survey_id']) && $_GET['survey_id'] == $survey['id'] ? 'selected' : '';
                                echo '<option value="'.$survey['id'].'" '.$selected.'>'.htmlspecialchars($survey['name']).'</option>';
                            }
                            ?>
                        </select>
                    </div>
                    
                    <?php if (!empty($_GET['survey_id'])): 
                        // Get survey questions
                        $surveyId = $_GET['survey_id'];
                        $questions = $pdo->prepare("
                            SELECT q.id, q.label, q.question_type, qdm.dhis2_dataelement_id, qdm.dhis2_option_set_id
                            FROM survey_question sq
                            JOIN question q ON sq.question_id = q.id
                            LEFT JOIN question_dhis2_mapping qdm ON q.id = qdm.question_id
                            WHERE sq.survey_id = ?
                            ORDER BY sq.position
                        ");
                        $questions->execute([$surveyId]);
                        $questions = $questions->fetchAll(PDO::FETCH_ASSOC);
                        
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
                                                    <small class="text-muted"><?= $question['question_type'] ?></small>
                                                </td>
                                                <td>
                                                    <select name="mapping[<?= $question['id'] ?>][data_element]" class="form-select form-select-sm">
                                                        <option value="">-- Not Mapped --</option>
                                                        <?php foreach ($dataElements as $id => $element): ?>
                                                            <?php $selected = $question['dhis2_dataelement_id'] == $id ? 'selected' : '' ?>
                                                            <option value="<?= $id ?>" <?= $selected ?>>
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
                                                            <option value="<?= $id ?>" <?= $selected ?>>
                                                                <?= htmlspecialchars($set['name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td class="text-center">
                                                    <a href="?tab=questions&dhis2_instance=<?= $_GET['dhis2_instance'] ?>&domain=<?= $_GET['domain'] ?>&<?= $_GET['domain'] == 'tracker' ? 'program='.$_GET['program'] : 'dataset='.$_GET['dataset'] ?>&survey_id=<?= $surveyId ?>&question_id=<?= $question['id'] ?>" 
                                                       class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </a>
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
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php
// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['save_single_mapping'])) {
        // Handle single question mapping save
        try {
            $questionId = $_POST['single_mapping']['question_id'] ?? null;
            $dataElement = $_POST['single_mapping']['data_element'] ?? null;
            $optionSet = $_POST['single_mapping']['option_set'] ?? null;
            
            if (empty($questionId)) {
                throw new Exception("Question ID is required");
            }
            
            // Delete existing mapping
            $deleteStmt = $pdo->prepare("DELETE FROM question_dhis2_mapping WHERE question_id = ?");
            $deleteStmt->execute([$questionId]);
            
            // Insert new mapping if data element is selected
            if (!empty($dataElement)) {
                $insertStmt = $pdo->prepare("
                    INSERT INTO question_dhis2_mapping 
                    (question_id, dhis2_dataelement_id, dhis2_option_set_id) 
                    VALUES (?, ?, ?)
                ");
                $insertStmt->execute([$questionId, $dataElement, $optionSet]);
            }
            
            // Redirect back to prevent form resubmission
            $redirectUrl = "?tab=questions&dhis2_instance=".$_GET['dhis2_instance']."&domain=".$_GET['domain']."&";
            $redirectUrl .= $_GET['domain'] == 'tracker' ? 'program='.$_GET['program'] : 'dataset='.$_GET['dataset'];
            $redirectUrl .= "&survey_id=".$surveyId."&question_id=".$questionId;
            
            header("Location: ".$redirectUrl);
            exit();
            
        } catch (Exception $e) {
            echo '<div class="alert alert-danger">Error saving mapping: '.htmlspecialchars($e->getMessage()).'</div>';
        }
    } elseif (isset($_POST['save_mapping'])) {
        // Handle bulk mapping save
        try {
            if (empty($_POST['mapping'])) {
                throw new Exception("No mappings to save");
            }
            
            $pdo->beginTransaction();
            
            // First delete all existing mappings for these questions
            $questionIds = array_keys($_POST['mapping']);
            $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
            
            $deleteStmt = $pdo->prepare("
                DELETE FROM question_dhis2_mapping 
                WHERE question_id IN ($placeholders)
            ");
            $deleteStmt->execute($questionIds);
            
            // Insert new mappings
            $insertStmt = $pdo->prepare("
                INSERT INTO question_dhis2_mapping 
                (question_id, dhis2_dataelement_id, dhis2_option_set_id) 
                VALUES (?, ?, ?)
            ");
            
            foreach ($_POST['mapping'] as $questionId => $mapping) {
                if (!empty($mapping['data_element'])) {
                    $optionSet = $mapping['option_set'] ?? null;
                    $insertStmt->execute([$questionId, $mapping['data_element'], $optionSet]);
                }
            }
            
            $pdo->commit();
            
            // Show success message
            echo '<div class="alert alert-success">Mappings saved successfully!</div>';
            
        } catch (Exception $e) {
            $pdo->rollBack();
            echo '<div class="alert alert-danger">Error saving mappings: '.htmlspecialchars($e->getMessage()).'</div>';
        }
    }
}
?>