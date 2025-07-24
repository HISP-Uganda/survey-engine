<?php
// dhis2/ajax_get_mapping_status.php
session_start();
require_once __DIR__ . '/dhis2_shared.php'; // Path to dhis2_shared.php
require_once __DIR__ . '/../connect.php'; // Path to connect.php

error_reporting(E_ALL);
ini_set('display_errors', 0); // Hide errors for AJAX output
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');

class AjaxMappingHelper {
    private $pdo;
    private $dhis2ConfigDetails;

    public function __construct($pdo, $instanceKey) {
        $this->pdo = $pdo;
        $this->dhis2ConfigDetails = getDhis2Config($instanceKey);
        if (!$this->dhis2ConfigDetails) {
            throw new Exception("DHIS2 instance configuration not found for: " . $instanceKey);
        }
    }

    public function getDataElementsForSelection($instanceKey, $domain, $programId = null, $datasetId = null) {
        $dataElements = [];
        if ($domain === 'tracker' && !empty($programId)) {
            $programInfo = dhis2_get('/programs/'.$programId.'?fields=id,name,programStages[programStageDataElements[dataElement[id,name,optionSet[id,name]]]', $instanceKey);
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
        } elseif ($domain === 'aggregate' && !empty($datasetId)) {
            $datasetInfo = dhis2_get('/dataSets/'.$datasetId.'?fields=id,name,dataSetElements[dataElement[id,name,optionSet[id,name]]]', $instanceKey);
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

    public function getOptionSetsForDataElements($dataElements) {
        $optionSets = [];
        foreach ($dataElements as $element) {
            if (!empty($element['optionSet'])) {
                $optionSets[$element['optionSet']['id']] = $element['optionSet'];
            }
        }
        return $optionSets;
    }

    public function getMappedElements($elementIds) {
        if (empty($elementIds)) return [];
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
}

$instanceKey = $_GET['instance'] ?? '';
$domain = $_GET['domain'] ?? '';
$programId = $_GET['program'] ?? '';
$datasetId = $_GET['dataset'] ?? '';

if (empty($instanceKey) || empty($domain) ||
    ($domain === 'tracker' && empty($programId)) ||
    ($domain === 'aggregate' && empty($datasetId))) {
    echo '<div class="alert alert-warning futuristic-alert">Please select a DHIS2 instance, domain, and program/dataset to view mappings.</div>';
    exit;
}

try {
    $helper = new AjaxMappingHelper($pdo, $instanceKey); // $pdo is from connect.php
    $dataElements = $helper->getDataElementsForSelection($instanceKey, $domain, $programId, $datasetId);
    $mappedElements = $helper->getMappedElements(array_keys($dataElements));

    if (empty($dataElements)) {
        echo '<div class="alert alert-warning futuristic-alert">No data elements found for this selection.</div>';
        exit;
    }

    // --- Render the HTML snippet ---
    if (!empty($mappedElements)): ?>
        <div class="alert alert-info border-0 shadow-sm futuristic-alert">
            <div class="d-flex align-items-center mb-3">
                <i class="fas fa-map-marker-alt fa-2x me-3 text-primary"></i>
                <h5 class="mb-0 text-white">Existing Mappings Found</h5>
            </div>
            <p class="text-white">The following data elements are already mapped to questions:</p>

            <div class="mb-3">
                <label for="surveyFilter" class="form-label text-white">Filter by Survey</label>
                <select id="surveyFilter" class="form-select" onchange="filterBySurvey()">
                    <option value="">-- All Surveys --</option>
                    <?php foreach (array_unique(array_column($mappedElements, 'survey_name')) as $surveyName): ?>
                        <option value="<?= htmlspecialchars($surveyName) ?>"><?= htmlspecialchars($surveyName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="table-responsive mt-3">
                <table class="table table-striped table-hover" id="mappingTable">
                    <thead class="table-secondary">
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
                                    <a href="?tab=questions&dhis2_instance=<?= htmlspecialchars($instanceKey) ?>&domain=<?= htmlspecialchars($domain) ?>&<?= $domain === 'tracker' ? 'program='.htmlspecialchars($programId) : 'dataset='.htmlspecialchars($datasetId) ?>&survey_id=<?= $mapping['survey_id'] ?>&question_id=<?= $mapping['question_id'] ?>"
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
                // This function needs to be global or re-executed after AJAX load
                // Check if the function exists to prevent re-declaration errors if the snippet loads multiple times.
                if (typeof filterBySurvey !== 'function') {
                    window.filterBySurvey = function() { // Make it global
                        const filter = document.getElementById('surveyFilter').value.toLowerCase();
                        const rows = document.querySelectorAll('#mappingTable tbody tr');
                        rows.forEach(row => {
                            const survey = row.getAttribute('data-survey').toLowerCase();
                            row.style.display = filter === '' || survey === filter ? '' : 'none';
                        });
                    }
                }
            </script>
        </div>
    <?php else: // No existing mappings found ?>
        <div class="alert alert-warning border-0 shadow-sm futuristic-alert">
            <div class="d-flex align-items-center mb-3">
                <i class="fas fa-exclamation-triangle fa-2x me-3 text-warning"></i>
                <h5 class="mb-0 text-white">No Existing Mappings Found</h5>
            </div>
            <p class="text-white">None of the <?= count($dataElements) ?> data elements in this <?= htmlspecialchars($domain) === 'tracker' ? 'program' : 'dataset' ?> are currently mapped to any questions in the system. You can create new mappings below.</p>

            <div class="table-responsive mt-3">
                <form method="post" id="newMappingForm">
                    <table class="table table-striped table-hover">
                        <thead class="table-secondary">
                            <tr>
                                <th>Data Element</th>
                                <th>Question Name (Suggestion)</th>
                                <th>Option Set</th>
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
                                                <?php
                                                // This part needs the helper's getOptionSetsForDataElements and getOptionSetMappings
                                                // Make sure these methods are available in AjaxMappingHelper if needed here.
                                                // For now, assuming only optionSet name is displayed.
                                                $optionSetsForElement = $helper->getOptionSetsForDataElements([$element]); // Corrected: pass single element
                                                foreach ($optionSetsForElement as $optionSetId => $optionSet): ?>
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
                    <input type="hidden" name="dhis2_instance" value="<?= htmlspecialchars($instanceKey) ?>">
                    <input type="hidden" name="domain" value="<?= htmlspecialchars($domain) ?>">
                    <?php if ($domain === 'tracker'): ?>
                        <input type="hidden" name="program" value="<?= htmlspecialchars($programId) ?>">
                    <?php else: ?>
                        <input type="hidden" name="dataset" value="<?= htmlspecialchars($datasetId) ?>">
                    <?php endif; ?>
                    <button type="submit" name="save_new_mappings" class="btn btn-primary mt-3">
                        <i class="fas fa-save me-2"></i>Save New Mappings
                    </button>
                </form>
            </div>
        </div>
    <?php endif;
} catch (Exception $e) {
    echo '<div class="alert alert-danger futuristic-alert">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    error_log("ajax_get_mapping_status.php: " . $e->getMessage());
}
?>