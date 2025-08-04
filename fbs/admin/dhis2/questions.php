<?php
// questions.php - DHIS2 Question Mapping Interface (rendered by new.php)
// This file should be included when activeTab is 'questions'

if ($activeTab == 'questions') :
?>

<style>
    /* Question mapping specific styles */
    .mapping-container {
        background-color: #ffffff;
        border-radius: 0.75rem;
        padding: 1.5rem;
        border: 1px solid #e2e8f0;
        margin-bottom: 1.5rem;
    }
    
    .question-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 0.5rem;
        padding: 1rem;
        margin-bottom: 1rem;
        transition: all 0.3s ease;
    }
    
    .question-card:hover {
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        transform: translateY(-2px);
    }
    
    .mapping-status {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        border-radius: 1rem;
        font-size: 0.875rem;
        font-weight: 500;
    }
    
    .mapping-status.mapped {
        background-color: #dcfce7;
        color: #166534;
        border: 1px solid #bbf7d0;
    }
    
    .mapping-status.unmapped {
        background-color: #fef2f2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }
    
    .data-element-select {
        min-width: 250px;
    }
    
    .option-preview {
        background: #f1f5f9;
        border: 1px solid #cbd5e1;
        border-radius: 0.375rem;
        padding: 0.75rem;
        margin-top: 0.5rem;
        max-height: 200px;
        overflow-y: auto;
    }
</style>

<div class="tab-header mb-4">
    <h3 class="text-dark"><i class="fas fa-link me-2 text-primary"></i>Question-Element Mapping Interface</h3>
    <p class="text-muted mb-0">Map survey questions to DHIS2 data elements and option sets</p>
</div>

<?php
// Check if we have the necessary parameters to show the mapping interface
$hasRequiredParams = !empty($_GET['dhis2_instance']) && !empty($_GET['domain']) && 
                     (($_GET['domain'] === 'tracker' && !empty($_GET['program'])) ||
                      ($_GET['domain'] === 'aggregate' && !empty($_GET['dataset'])));

if (!$hasRequiredParams):
?>
    <div class="alert alert-info">
        <div class="d-flex align-items-center">
            <i class="fas fa-info-circle fa-2x text-info me-3"></i>
            <div>
                <h5 class="alert-heading mb-1">Configuration Required</h5>
                <p class="mb-0">Please configure your DHIS2 instance, domain, and program/dataset in the "DHIS2-Programs-Fetcher" tab before mapping questions.</p>
                <a href="?tab=new" class="btn btn-info btn-sm mt-2">
                    <i class="fas fa-arrow-left me-1"></i> Go to Configuration
                </a>
            </div>
        </div>
    </div>
<?php else: ?>
    
    <div class="mapping-container">
        <div class="row mb-4">
            <div class="col-md-6">
                <h5 class="text-dark mb-3">Current Configuration</h5>
                <div class="config-summary">
                    <div class="mb-2">
                        <strong>Instance:</strong> 
                        <span class="text-primary"><?= htmlspecialchars($_GET['dhis2_instance']) ?></span>
                    </div>
                    <div class="mb-2">
                        <strong>Domain:</strong> 
                        <span class="badge bg-secondary"><?= ucfirst(htmlspecialchars($_GET['domain'])) ?></span>
                    </div>
                    <div class="mb-2">
                        <strong><?= $_GET['domain'] === 'tracker' ? 'Program' : 'Dataset' ?>:</strong>
                        <span class="text-info">
                            <?= htmlspecialchars($_GET['domain'] === 'tracker' ? ($_GET['program'] ?? 'Not selected') : ($_GET['dataset'] ?? 'Not selected')) ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <h5 class="text-dark mb-3">Survey Selection</h5>
                <?php if (!empty($_GET['survey_id'])): ?>
                    <?php
                    $surveyStmt = $pdo->prepare("SELECT name FROM survey WHERE id = ?");
                    $surveyStmt->execute([$_GET['survey_id']]);
                    $survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);
                    ?>
                    <div class="selected-survey">
                        <div class="mb-2">
                            <strong>Selected Survey:</strong>
                            <span class="text-success"><?= htmlspecialchars($survey['name'] ?? 'Unknown Survey') ?></span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        No survey selected. Please select a survey to map its questions.
                        <a href="survey.php" class="btn btn-warning btn-sm ms-2">
                            <i class="fas fa-list me-1"></i> Select Survey
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (!empty($_GET['survey_id'])): ?>
            <hr class="my-4">
            
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="text-dark mb-0">Question Mappings</h5>
                <div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="expandAllMappings">
                        <i class="fas fa-expand-alt me-1"></i> Expand All
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="collapseAllMappings">
                        <i class="fas fa-compress-alt me-1"></i> Collapse All
                    </button>
                </div>
            </div>
            
            <?php
            // Get questions for the selected survey
            $questionsStmt = $pdo->prepare("
                SELECT q.id, q.label, q.question_type, q.option_set_id,
                       qdm.dhis2_dataelement_id,
                       GROUP_CONCAT(osv.option_value ORDER BY osv.id) as options
                FROM survey_question sq
                JOIN question q ON sq.question_id = q.id
                LEFT JOIN question_dhis2_mapping qdm ON q.id = qdm.question_id
                LEFT JOIN option_set_values osv ON q.option_set_id = osv.option_set_id
                WHERE sq.survey_id = ?
                GROUP BY q.id, q.label, q.question_type, q.option_set_id, qdm.dhis2_dataelement_id
                ORDER BY sq.position
            ");
            $questionsStmt->execute([$_GET['survey_id']]);
            $questions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($questions)):
            ?>
                <div class="text-center py-5">
                    <i class="fas fa-question-circle fa-3x text-muted mb-3"></i>
                    <h4 class="text-dark">No Questions Found</h4>
                    <p class="text-muted">This survey doesn't contain any questions yet.</p>
                </div>
            <?php else: ?>
                    <?php foreach ($questions as $index => $question): ?>
                        <div class="question-card" data-question-id="<?= $question['id'] ?>">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="flex-grow-1">
                                    <h6 class="text-dark mb-1">
                                        Question <?= $index + 1 ?>: <?= htmlspecialchars($question['label']) ?>
                                    </h6>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($question['question_type']) ?></span>
                                        <?php if (!empty($question['dhis2_dataelement_id'])): ?>
                                            <span class="mapping-status mapped">
                                                <i class="fas fa-check-circle me-1"></i> Mapped
                                            </span>
                                        <?php else: ?>
                                            <span class="mapping-status unmapped">
                                                <i class="fas fa-times-circle me-1"></i> Not Mapped
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-outline-secondary btn-sm toggle-mapping" 
                                        data-target="mapping-<?= $question['id'] ?>">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                            
                            <div class="mapping-details collapse" id="mapping-<?= $question['id'] ?>">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Question Details:</strong> This question is of type "<?= htmlspecialchars($question['question_type']) ?>" 
                                    <?php if (!empty($question['dhis2_dataelement_id'])): ?>
                                        and is currently mapped to DHIS2 Data Element: <?= htmlspecialchars($question['dhis2_dataelement_id']) ?>
                                    <?php else: ?>
                                        and is not currently mapped to any DHIS2 Data Element
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (!empty($question['options'])): ?>
                                <div class="mt-3">
                                    <label class="form-label text-dark">Question Options Preview</label>
                                    <div class="option-preview">
                                        <?php
                                        $options = explode(',', $question['options']);
                                        if (is_array($options)) {
                                            foreach ($options as $option) {
                                                $option = trim($option);
                                                if (!empty($option)) {
                                                    echo '<span class="badge bg-light text-dark border me-1 mb-1">' . 
                                                         htmlspecialchars($option) . '</span>';
                                                }
                                            }
                                        }
                                        ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="mt-4">
                        <a href="?tab=new&dhis2_instance=<?= htmlspecialchars($_GET['dhis2_instance']) ?>&domain=<?= htmlspecialchars($_GET['domain']) ?>&<?= $_GET['domain'] === 'tracker' ? 'program=' . htmlspecialchars($_GET['program']) : 'dataset=' . htmlspecialchars($_GET['dataset']) ?>" 
                           class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i> Back to Configuration
                        </a>
                    </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle mapping details
    document.querySelectorAll('.toggle-mapping').forEach(button => {
        button.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const target = document.getElementById(targetId);
            const icon = this.querySelector('i');
            
            if (target.classList.contains('show')) {
                target.classList.remove('show');
                icon.className = 'fas fa-chevron-down';
            } else {
                target.classList.add('show');
                icon.className = 'fas fa-chevron-up';
            }
        });
    });
    
    // Expand all mappings
    document.getElementById('expandAllMappings')?.addEventListener('click', function() {
        document.querySelectorAll('.mapping-details').forEach(detail => {
            detail.classList.add('show');
        });
        document.querySelectorAll('.toggle-mapping i').forEach(icon => {
            icon.className = 'fas fa-chevron-up';
        });
    });
    
    // Collapse all mappings
    document.getElementById('collapseAllMappings')?.addEventListener('click', function() {
        document.querySelectorAll('.mapping-details').forEach(detail => {
            detail.classList.remove('show');
        });
        document.querySelectorAll('.toggle-mapping i').forEach(icon => {
            icon.className = 'fas fa-chevron-down';
        });
    });
    
    // Toggle functionality for question details is handled above
});
</script>

<?php endif; ?>