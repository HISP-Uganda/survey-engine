<?php
// DHIS2 Payload Checker tab content for settings page
// This file is included in settings.php when payload_checker tab is active

require_once 'dhis2/dhis2_submit.php';

$message = '';  
$message_type = '';

// Handle Retry Action (only for actual failed submissions, not error logs)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retry_submission_id'])) {
    $submissionIdToRetry = intval($_POST['retry_submission_id']);
    
    // Only allow retry of actual submissions (not error logs)
    if (empty($submissionIdToRetry)) {
        $message = "Cannot retry: Invalid submission ID";
        $message_type = 'warning';
    } else {
        // Determine if this is a regular or tracker submission and get survey ID
        $stmt = $pdo->prepare("
            SELECT survey_id, 'regular' as type FROM submission WHERE id = ?
            UNION 
            SELECT survey_id, 'tracker' as type FROM tracker_submissions WHERE id = ?
        ");
        $stmt->execute([$submissionIdToRetry, $submissionIdToRetry]);
        $submissionInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($submissionInfo) {
        $surveyIdForRetry = $submissionInfo['survey_id'];
        $submissionType = $submissionInfo['type'];
        
        try {
            if ($submissionType === 'tracker') {
                // For tracker submissions, we need to re-process using the tracker system
                $message = "Tracker submission retry is not yet implemented. Submission ID: $submissionIdToRetry is a tracker submission.";
                $message_type = 'warning';
                // TODO: Implement tracker retry functionality
            } else {
                // Regular submission retry using DHIS2SubmissionHandler
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
    } // End of submission ID validation
}

// Ensure dhis2_error_log table exists before querying
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dhis2_error_log (
            id int(11) NOT NULL AUTO_INCREMENT,
            survey_id int(11) NOT NULL,
            error_message TEXT NOT NULL,
            payload_attempted JSON DEFAULT NULL,
            location_data JSON DEFAULT NULL,
            error_occurred_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip_address varchar(45) DEFAULT NULL,
            user_session_id varchar(255) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_survey_id (survey_id),
            KEY idx_occurred_at (error_occurred_at),
            CONSTRAINT fk_error_survey FOREIGN KEY (survey_id) REFERENCES survey(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Exception $e) {
    error_log("Failed to create dhis2_error_log table: " . $e->getMessage());
}

// Fetch submission logs (successes and errors) using separate queries
$logs = [];
try {
    // Get successful submissions first
    $successLogs = [];
    $stmt = $pdo->prepare("
        SELECT
            CONCAT('success_', dsl.id) as log_id,
            dsl.submission_id,
            COALESCE(s.uid, ts.uid) as submission_uid,
            COALESCE(s.created, ts.submitted_at) as submission_date,
            dsl.status,
            dsl.payload_sent,
            dsl.dhis2_response,
            dsl.dhis2_message,
            dsl.submitted_at as log_date,
            dsl.retries,
            sy.name as survey_name,
            sy.dhis2_program_uid,
            sy.dhis2_instance,
            CASE 
                WHEN s.id IS NOT NULL THEN 'regular'
                WHEN ts.id IS NOT NULL THEN 'tracker'
                ELSE 'unknown'
            END as submission_type,
            'success' as log_type
        FROM dhis2_submission_log dsl
        LEFT JOIN submission s ON dsl.submission_id = s.id
        LEFT JOIN tracker_submissions ts ON dsl.submission_id = ts.id
        LEFT JOIN survey sy ON COALESCE(s.survey_id, ts.survey_id) = sy.id
        WHERE (s.id IS NOT NULL OR ts.id IS NOT NULL)
          AND (sy.dhis2_program_uid IS NOT NULL OR sy.dhis2_instance IS NOT NULL)
        ORDER BY dsl.submitted_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $successLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get error logs
    $errorLogs = [];
    $stmt = $pdo->prepare("
        SELECT
            CONCAT('error_', del.id) as log_id,
            NULL as submission_id,
            NULL as submission_uid,
            NULL as submission_date,
            'ERROR' as status,
            del.payload_attempted as payload_sent,
            NULL as dhis2_response,
            del.error_message as dhis2_message,
            del.error_occurred_at as log_date,
            0 as retries,
            sy.name as survey_name,
            sy.dhis2_program_uid,
            sy.dhis2_instance,
            'tracker' as submission_type,
            'error' as log_type
        FROM dhis2_error_log del
        LEFT JOIN survey sy ON del.survey_id = sy.id
        WHERE sy.dhis2_program_uid IS NOT NULL OR sy.dhis2_instance IS NOT NULL
        ORDER BY del.error_occurred_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $errorLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Combine and sort by date
    $logs = array_merge($successLogs, $errorLogs);
    usort($logs, function($a, $b) {
        return strtotime($b['log_date']) - strtotime($a['log_date']);
    });
    
    // Limit to 100 total entries
    $logs = array_slice($logs, 0, 100);
    
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
    .status-ERROR { background-color: #dc2626; }
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
    
    /* Modal z-index fixes for navbar compatibility */
    #detailsModal {
        z-index: 2000 !important;
    }
    
    #detailsModal .modal-backdrop {
        z-index: 1999 !important;
    }
    
    .modal-backdrop.show {
        z-index: 1999 !important;
    }
    
    /* Ensure modal content is above navbar */
    #detailsModal .modal-dialog {
        z-index: 2001 !important;
        position: relative;
    }
    
    /* Custom Modal Styles - Independent of Bootstrap */
    .payload-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(4px);
    }
    
    .payload-modal-dialog {
        max-width: 90%;
        max-height: 90%;
        width: 1200px;
        margin: auto;
        position: relative;
    }
    
    .payload-modal-content {
        background: white;
        border-radius: 12px;
        box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
        max-height: 90vh;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }
    
    .payload-modal-header {
        padding: 1.5rem 2rem;
        border-bottom: 1px solid #e9ecef;
        display: flex;
        justify-content: between;
        align-items: center;
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    }
    
    .payload-modal-title {
        margin: 0;
        font-size: 1.25rem;
        font-weight: 600;
        color: #1e293b;
        flex: 1;
    }
    
    .payload-modal-close {
        background: none;
        border: none;
        font-size: 2rem;
        color: #6c757d;
        cursor: pointer;
        padding: 0;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: all 0.2s ease;
    }
    
    .payload-modal-close:hover {
        background-color: #f8f9fa;
        color: #dc3545;
    }
    
    .payload-modal-body {
        padding: 2rem;
        flex: 1;
        overflow-y: auto;
    }
    
    .payload-modal-footer {
        padding: 1rem 2rem;
        border-top: 1px solid #e9ecef;
        display: flex;
        justify-content: flex-end;
        background: #f8f9fa;
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
                                        <?php if ($log['log_type'] === 'error'): ?>
                                            <h6 class="mb-0 text-sm text-danger">Error Log</h6>
                                            <p class="text-xs text-secondary mb-0">No submission saved</p>
                                        <?php else: ?>
                                            <h6 class="mb-0 text-sm text-dark"><?= htmlspecialchars($log['submission_id']) ?></h6>
                                            <p class="text-xs text-secondary mb-0">UID: <?= htmlspecialchars(substr($log['submission_uid'] ?? '', 0, 8)) ?>...</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div>
                                    <p class="text-sm font-weight-bold mb-0 text-dark"><?= htmlspecialchars($log['survey_name'] ?? 'N/A') ?></p>
                                    <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                        <?php if (isset($log['submission_type'])): ?>
                                            <span class="badge <?= $log['submission_type'] === 'tracker' ? 'bg-info' : 'bg-primary' ?> text-white" style="font-size: 0.7rem;">
                                                <?= $log['submission_type'] === 'tracker' ? 'TRACKER' : 'REGULAR' ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($log['dhis2_instance'])): ?>
                                            <span class="badge bg-success text-white" style="font-size: 0.7rem;">
                                                <?= htmlspecialchars($log['dhis2_instance']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($log['dhis2_program_uid'])): ?>
                                        <p class="text-xs text-secondary mb-0">Program: <?= htmlspecialchars(substr($log['dhis2_program_uid'], 0, 15)) ?>...</p>
                                    <?php endif; ?>
                                </div>
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
                                <p class="text-sm font-weight-bold mb-0 text-dark"><?= date('M d, Y H:i', strtotime($log['log_date'])) ?></p>
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
                                            data-payload="<?= htmlspecialchars(json_encode($payload, JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8') ?>"
                                            data-response="<?= htmlspecialchars(json_encode($response, JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8') ?>">
                                        <i class="fas fa-eye me-1"></i>View
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($log['log_type'] === 'error'): ?>
                                    <span class="text-muted">
                                        <i class="fas fa-info-circle me-1"></i>Debug Only
                                    </span>
                                <?php else: ?>
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
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Details Modal - Custom Implementation -->
<div id="payloadModal" class="payload-modal-overlay" style="display: none;">
    <div class="payload-modal-dialog">
        <div class="payload-modal-content">
            <div class="payload-modal-header">
                <h5 class="payload-modal-title">
                    <i class="fas fa-code me-2"></i>Submission Details
                </h5>
                <button type="button" class="payload-modal-close" onclick="PayloadModal.hide()">&times;</button>
            </div>
            <div class="payload-modal-body">
                <h6 class="text-dark"><i class="fas fa-upload me-2 text-primary"></i>Payload Sent:</h6>
                <pre class="code-block" id="modal-payload"></pre>
                
                <h6 class="mt-4 text-dark"><i class="fas fa-download me-2 text-success"></i>DHIS2 Response:</h6>
                <pre class="code-block" id="modal-response"></pre>
            </div>
            <div class="payload-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="PayloadModal.hide()">
                    <i class="fas fa-times me-1"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // Custom Modal Implementation - Completely Independent of Bootstrap
    window.PayloadModal = {
        show: function(payloadData, responseData) {
            var modal = document.getElementById('payloadModal');
            var modalPayload = document.getElementById('modal-payload');
            var modalResponse = document.getElementById('modal-response');
            
            if (!modal || !modalPayload || !modalResponse) return;
            
            // Populate modal content
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
            
            // Show modal with animation
            modal.style.display = 'flex';
            modal.style.opacity = '0';
            
            setTimeout(function() {
                modal.style.transition = 'opacity 0.3s ease';
                modal.style.opacity = '1';
            }, 10);
            
            // Disable body scroll
            document.body.style.overflow = 'hidden';
        },
        
        hide: function() {
            var modal = document.getElementById('payloadModal');
            if (!modal) return;
            
            // Hide modal with animation
            modal.style.transition = 'opacity 0.3s ease';
            modal.style.opacity = '0';
            
            setTimeout(function() {
                modal.style.display = 'none';
                modal.style.transition = '';
            }, 300);
            
            // Re-enable body scroll
            document.body.style.overflow = '';
        }
    };
    
    // Initialize view button handlers when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        // Handle view button clicks - completely separate from navbar
        document.addEventListener('click', function(e) {
            var button = e.target.closest('.view-details-btn');
            if (button) {
                e.preventDefault();
                e.stopPropagation();
                
                var payloadData = button.getAttribute('data-payload');
                var responseData = button.getAttribute('data-response');
                
                PayloadModal.show(payloadData, responseData);
            }
        });
        
        // Handle Escape key to close modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                var modal = document.getElementById('payloadModal');
                if (modal && modal.style.display !== 'none') {
                    PayloadModal.hide();
                }
            }
        });
        
        // Handle click outside modal to close
        document.addEventListener('click', function(e) {
            var modal = document.getElementById('payloadModal');
            if (e.target === modal) {
                PayloadModal.hide();
            }
        });
    });
</script>