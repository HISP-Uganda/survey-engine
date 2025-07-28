<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

// Adjust paths based on your actual file structure
// If 'connect.php' is in 'fbs/', and 'admin/' is in 'fbs/'
require_once 'connect.php';
// If 'dhis2_submit.php' is in 'fbs/dhis2/', and 'admin/' is in 'fbs/'
require_once 'dhis2/dhis2_submit.php';

if (!isset($pdo)) {
    die("Database connection failed: PDO object not found.");
}

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
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>DHIS2 Payload Checker</title>
  <link href="argon-dashboard-master/assets/css/nucleo-icons.css" rel="stylesheet">
  <link href="argon-dashboard-master/assets/css/nucleo-svg.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
  <link href="argon-dashboard-master/assets/css/argon-dashboard.min.css" rel="stylesheet">
  <style>
    :root {
        --primary-color: #1e3c72;
        --primary-hover-color: #162c57;
        --primary-light-color: #3b5a9a;
    }
    body { font-family: Arial, sans-serif; background-color: #f9f9f9; }
    h1, h2 { color: var(--primary-color); margin-bottom: 30px; }
    .card-header.bg-gradient-primary { background-image: linear-gradient(310deg, var(--primary-color) 0%, var(--primary-light-color) 100%) !important; }
    .text-primary { color: var(--primary-color) !important; }
    .btn-primary { background-color: var(--primary-color); border-color: var(--primary-color); }
    .btn-primary:hover { background-color: var(--primary-hover-color); border-color: var(--primary-hover-color); }
    .code-block {
      background-color: #f8f9fa;
      border: 1px solid #e9ecef;
      padding: 10px;
      border-radius: 5px;
      font-family: monospace;
      white-space: pre-wrap; /* Preserve whitespace and wrap long lines */
      word-wrap: break-word; /* Break long words */
      max-height: 300px;
      overflow-y: auto;
      margin-top: 10px;
      font-size: 0.85em;
    }
    .status-badge {
        padding: 5px 10px;
        border-radius: 5px;
        font-weight: bold;
        color: white;
    }
    .status-SUCCESS { background-color: #28a745; }
    .status-FAILED { background-color: #dc3545; }
    .status-SKIPPED { background-color: #ffc107; color: #343a40; }
    .status-PENDING { background-color: #6c757d; }
    .expand-btn {
        cursor: pointer;
        color: var(--primary-color);
        font-weight: bold;
        margin-top: 5px;
        display: block;
    }
  </style>
</head>
<body>

  <?php include 'components/aside.php'; ?>

  <main class="main-content position-relative border-radius-lg">
    

    <?php
    $pageTitle = "DHIS2 Payload Checker";
    ?>
    <div class="d-flex align-items-center flex-grow-1" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);  padding: 1rem 1.5rem; margin-bottom: 1.5rem;">
      <nav aria-label="breadcrumb" class="flex-grow-1">
        <ol class="breadcrumb mb-0 navbar-breadcrumb" style="background: transparent;">
          <li class="breadcrumb-item">
            <a href="main" class="breadcrumb-link" style="color: #ffd700; font-weight: 600;">
              <i class="fas fa-home me-1" style="color: #ffd700;"></i>Home
            </a>
          </li>
          <li class="breadcrumb-item active navbar-breadcrumb-active" aria-current="page" style="color: #fff; font-weight: 700;">
            <?= htmlspecialchars($pageTitle) ?>
          </li>
        </ol>
        <h5 class="navbar-title mb-0" style="color: #fff; text-shadow: 0 1px 8px #1e3c72, 0 0 2px #ffd700;">
          <?= htmlspecialchars($pageTitle) ?>
        </h5>
      </nav>
    </div>

    <div class="container-fluid py-4">
      <div class="row">
        <div class="col-12">
          <div class="card shadow-lg mb-5">
            <div class="card-header pb-0 text-center bg-gradient-primary text-white rounded-top">
              <h1 class="mb-1">
                <span class="text-white">
                  <i class="fas fa-bug me-2" style="color: #000; 8px #fff;"></i>
                  DHIS2 Payload Checker
                </span>
              </h1>
              <p class="mb-0">Review DHIS2 submission attempts and retry failed ones.</p>
            </div>
            <div class="card-body px-4">

              <?php if ($message): ?>
                <div class="alert alert-<?= $message_type ?>" role="alert">
                  <?= htmlspecialchars($message) ?>
                </div>
              <?php endif; ?>

              <?php if (empty($logs)): ?>
                <div class="alert alert-info">No DHIS2 submission logs found. Make a submission to see data here.</div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover align-items-center mb-0">
                    <thead>
                      <tr>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Submission ID</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Survey</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Retries</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Attempted At</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Reason/Details</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Payload/Response</th>
                        <th class="text-secondary opacity-7">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($logs as $log): ?>
                      <tr>
                        <td>
                          <div class="d-flex px-2 py-1">
                            <div class="d-flex flex-column justify-content-center">
                              <h6 class="mb-0 text-xs"><?= htmlspecialchars($log['submission_id']) ?></h6>
                              <p class="text-xs text-secondary mb-0">UID: <?= htmlspecialchars($log['submission_uid']) ?></p>
                            </div>
                          </div>
                        </td>
                        <td><p class="text-xs font-weight-bold mb-0"><?= htmlspecialchars($log['survey_name'] ?? 'N/A') ?></p></td>
                        <td>
                          <span class="status-badge status-<?= htmlspecialchars($log['status']) ?>">
                            <?= htmlspecialchars($log['status']) ?>
                          </span>
                        </td>
                        <td><p class="text-xs font-weight-bold mb-0"><?= htmlspecialchars($log['retries']) ?></p></td>
                        <td><p class="text-xs font-weight-bold mb-0"><?= htmlspecialchars($log['submitted_at']) ?></p></td>
                        <td>
                          <p class="text-xs text-secondary mb-0">
                            <?= htmlspecialchars($log['dhis2_message'] ?? 'No message.') ?>
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
                                    View
                                </button>
                            <?php else: ?>
                                <p class="text-xs text-secondary mb-0">N/A</p>
                            <?php endif; ?>
                        </td>
                        <td>
                          <form method="POST" action="payload_checker.php" style="display:inline;">
                            <input type="hidden" name="retry_submission_id" value="<?= $log['submission_id'] ?>">
                            <?php if ($log['status'] === 'FAILED' || $log['status'] === 'SKIPPED'): ?>
                                <button type="submit" class="btn btn-sm btn-warning mb-0"><i class="fas fa-redo"></i> Retry</button>
                            <?php else: ?>
                                <button type="submit" class="btn btn-sm btn-secondary mb-0" disabled><i class="fas fa-redo"></i> Retry</button>
                            <?php endif; ?>
                          </form>
                        </td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="detailsModalLabel">Submission Details</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <h6>Payload Sent:</h6>
            <pre class="code-block" id="modal-payload"></pre>
            <h6 class="mt-4">DHIS2 Response:</h6>
            <pre class="code-block" id="modal-response"></pre>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>

  </main>
  
 <script src="argon-dashboard-master/assets/js/core/popper.min.js"></script>
<script src="argon-dashboard-master/assets/js/core/bootstrap.min.js"></script>
<script src="argon-dashboard-master/assets/js/plugins/perfect-scrollbar.min.js"></script>
<script src="argon-dashboard-master/assets/js/plugins/smooth-scrollbar.min.js"></script>
<script src="argon-dashboard-master/assets/js/argon-dashboard.min.js"></script>
<script>
    // Custom modal script from payload_checker.php goes here
    document.addEventListener('DOMContentLoaded', function() {
        var detailsModal = document.getElementById('detailsModal');
        if (detailsModal) { // Add a check if modal element exists
            detailsModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                var payloadData = button.getAttribute('data-payload');
                var responseData = button.getAttribute('data-response');
                var modalPayload = detailsModal.querySelector('#modal-payload');
                var modalResponse = detailsModal.querySelector('#modal-response');
                modalPayload.textContent = payloadData ? JSON.stringify(JSON.parse(payloadData), null, 2) : 'No payload recorded or payload is not valid JSON.';
                modalResponse.textContent = responseData ? JSON.stringify(JSON.parse(responseData), null, 2) : 'No response recorded or response is not valid JSON.';
            });
        }
    });
</script>
</body>
</html>