<?php
// DHIS2 Payload Checker tab content for settings page
// This file is included in settings.php when payload_checker tab is active

require_once 'dhis2/dhis2_submit.php';

$message = '';  
$message_type = '';

// Handle Retry Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retry_submission_id'])) {
    $submissionIdToRetry = intval($_POST['retry_submission_id']);
    
    // Fetch the original survey ID associated with this submission
    $stmt = $pdo->prepare("SELECT survey_id FROM submission WHERE id = ?");
    $stmt->execute([$submissionIdToRetry]);
    $surveyIdForRetry = $stmt->fetchColumn();

    if ($surveyIdForRetry) {
        try {
            // Instantiate the DHIS2SubmissionHandler with the correct survey ID
            $dhis2Submitter = new DHIS2SubmissionHandler($pdo, $surveyIdForRetry);

            if ($dhis2Submitter->isReadyForSubmission()) {
                $retryResult = $dhis2Submitter->processSubmission($submissionIdToRetry);

                if ($retryResult['success']) {
                    $message = "Retry successful for Submission ID: $submissionIdToRetry. Message: " . $retryResult['message'];
                    $message_type = 'success';
                } else {
                    $message = "Retry failed for Submission ID: $submissionIdToRetry. Error: " . $retryResult['message'];
                    $message_type = 'danger';
                }
            } else {
                $message = "Cannot retry Submission ID: $submissionIdToRetry. DHIS2 configuration for survey ID $surveyIdForRetry is invalid.";
                $message_type = 'warning';
            }
        } catch (Exception $e) {
            $message = "An unexpected error occurred during retry for Submission ID $submissionIdToRetry: " . $e->getMessage();
            $message_type = 'danger';
            error_log("Payload Checker Retry Error: " . $e->getMessage());
        }
    } else {
        $message = "Error: Original survey ID not found for submission ID: $submissionIdToRetry.";
        $message_type = 'danger';
    }
}

// Fetch submission logs
$logs = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            dsl.id as log_id,
            dsl.submission_id,
            s.uid as submission_uid,
            s.created as submission_date,
            dsl.status,
            dsl.payload_sent,
            dsl.dhis2_response,
            dsl.dhis2_message,
            dsl.submitted_at,
            dsl.retries,
            sy.name as survey_name
        FROM dhis2_submission_log dsl
        JOIN submission s ON dsl.submission_id = s.id
        JOIN survey sy ON s.survey_id = sy.id
        ORDER BY dsl.submitted_at DESC
        LIMIT 100 -- Limit to last 100 logs for performance
    ");
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $message = "Error fetching submission logs: " . $e->getMessage();
    $message_type = 'danger';
    error_log("Payload Checker Load Logs Error: " . $e->getMessage());
}
?>

<style>
    .code-block {
        background-color: #f8fafc;
        border: 1px solid #e2e8f0;
        padding: 1rem;
        border-radius: 8px;
        font-family: 'Courier New', monospace;
        white-space: pre-wrap;
        word-wrap: break-word;
        max-height: 300px;
        overflow-y: auto;
        margin-top: 10px;
        font-size: 0.85em;
        color: #1e293b;
    }
    .status-badge {
        padding: 0.5rem 0.75rem;
        border-radius: 1rem;
        font-weight: 600;
        font-size: 0.875rem;
        color: white;
        display: inline-block;
    }
    .status-SUCCESS { background-color: #10b981; }
    .status-FAILED { background-color: #ef4444; }
    .status-SKIPPED { background-color: #f59e0b; color: #1f2937; }
    .status-PENDING { background-color: #6b7280; }
    
    .payload-card {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .payload-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 30px rgba(59, 130, 246, 0.1);
    }
</style>

<div class="tab-header mb-4">
    <h3 class="text-dark"><i class="fas fa-bug me-2 text-primary"></i>DHIS2 Payload Checker</h3>
    <p class="text-muted mb-0">Review DHIS2 submission attempts and retry failed ones</p>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (empty($logs)): ?>
    <div class="card payload-card">
        <div class="card-body text-center py-5">
            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
            <h4 class="text-dark">No Submission Logs Found</h4>
            <p class="text-muted">Make a DHIS2 submission to see payload data here.</p>
        </div>
    </div>
<?php else: ?>
    <div class="card payload-card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-dark">
                    <i class="fas fa-list me-2"></i>DHIS2 Submission Logs
                </h5>
                <span class="badge bg-primary"><?= count($logs) ?> logs</span>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-items-center mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="text-uppercase text-secondary text-xs font-weight-bolder opacity-7 px-3">Submission</th>
                            <th class="text-uppercase text-secondary text-xs font-weight-bolder opacity-7 ps-2">Survey</th>
                            <th class="text-uppercase text-secondary text-xs font-weight-bolder opacity-7 ps-2">Status</th>
                            <th class="text-uppercase text-secondary text-xs font-weight-bolder opacity-7 ps-2">Retries</th>
                            <th class="text-uppercase text-secondary text-xs font-weight-bolder opacity-7 ps-2">Attempted At</th>
                            <th class="text-uppercase text-secondary text-xs font-weight-bolder opacity-7 ps-2">Message</th>
                            <th class="text-uppercase text-secondary text-xs font-weight-bolder opacity-7 ps-2">Details</th>
                            <th class="text-secondary opacity-7">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="px-3">
                                <div class="d-flex py-1">
                                    <div class="d-flex flex-column justify-content-center">
                                        <h6 class="mb-0 text-sm text-dark"><?= htmlspecialchars($log['submission_id']) ?></h6>
                                        <p class="text-xs text-secondary mb-0">UID: <?= htmlspecialchars(substr($log['submission_uid'], 0, 8)) ?>...</p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <p class="text-sm font-weight-bold mb-0 text-dark"><?= htmlspecialchars($log['survey_name'] ?? 'N/A') ?></p>
                            </td>
                            <td>
                                <span class="status-badge status-<?= htmlspecialchars($log['status']) ?>">
                                    <?= htmlspecialchars($log['status']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?= htmlspecialchars($log['retries']) ?></span>
                            </td>
                            <td>
                                <p class="text-sm font-weight-bold mb-0 text-dark"><?= date('M d, Y H:i', strtotime($log['submitted_at'])) ?></p>
                            </td>
                            <td>
                                <p class="text-sm text-secondary mb-0" style="max-width: 200px; overflow: hidden; text-overflow: ellipsis;">
                                    <?= htmlspecialchars($log['dhis2_message'] ?? 'No message') ?>
                                </p>
                            </td>
                            <td>
                                <?php
                                $payload = json_decode($log['payload_sent'] ?? '', true);
                                $response = json_decode($log['dhis2_response'] ?? '', true);
                                ?>
                                <?php if ($payload || $response): ?>
                                    <button type="button" class="btn btn-sm btn-info view-details-btn"
                                            data-bs-toggle="modal" data-bs-target="#detailsModal"
                                            data-payload="<?= htmlspecialchars(json_encode($payload, JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8') ?>"
                                            data-response="<?= htmlspecialchars(json_encode($response, JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8') ?>">
                                        <i class="fas fa-eye me-1"></i>View
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="retry_submission_id" value="<?= $log['submission_id'] ?>">
                                    <?php if ($log['status'] === 'FAILED' || $log['status'] === 'SKIPPED'): ?>
                                        <button type="submit" class="btn btn-sm btn-warning" title="Retry submission">
                                            <i class="fas fa-redo me-1"></i>Retry
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-secondary" disabled title="Cannot retry successful submissions">
                                            <i class="fas fa-check me-1"></i>Complete
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-dark" id="detailsModalLabel">
                    <i class="fas fa-code me-2"></i>Submission Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <h6 class="text-dark"><i class="fas fa-upload me-2 text-primary"></i>Payload Sent:</h6>
                <pre class="code-block" id="modal-payload"></pre>
                
                <h6 class="mt-4 text-dark"><i class="fas fa-download me-2 text-success"></i>DHIS2 Response:</h6>
                <pre class="code-block" id="modal-response"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // Handle modal display for payload details
    document.addEventListener('DOMContentLoaded', function() {
        var detailsModal = document.getElementById('detailsModal');
        if (detailsModal) {
            detailsModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                var payloadData = button.getAttribute('data-payload');
                var responseData = button.getAttribute('data-response');
                var modalPayload = detailsModal.querySelector('#modal-payload');
                var modalResponse = detailsModal.querySelector('#modal-response');
                
                try {
                    modalPayload.textContent = payloadData && payloadData !== 'null' ? 
                        JSON.stringify(JSON.parse(payloadData), null, 2) : 
                        'No payload recorded or payload is not valid JSON.';
                } catch (e) {
                    modalPayload.textContent = 'Invalid JSON payload data.';
                }
                
                try {
                    modalResponse.textContent = responseData && responseData !== 'null' ? 
                        JSON.stringify(JSON.parse(responseData), null, 2) : 
                        'No response recorded or response is not valid JSON.';
                } catch (e) {
                    modalResponse.textContent = 'Invalid JSON response data.';
                }
            });
        }
    });
</script>