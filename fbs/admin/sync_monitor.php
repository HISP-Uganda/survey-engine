<?php
// sync_monitor.php - Displays progress of sync job
session_start();
$jobId = $_GET['job_id'] ?? null;

// Check if job exists
if (!$jobId || !isset($_SESSION['sync_jobs'][$jobId])) {
    header('Location: settings.php?tab=load&error=Invalid+job+ID');
    exit;
}

$job = $_SESSION['sync_jobs'][$jobId];

// Status constants
define('STATUS_READY', 'ready');
define('STATUS_PROCESSING', 'processing');
define('STATUS_IMPORTING', 'importing');
define('STATUS_COMPLETE', 'complete');
define('STATUS_ERROR', 'error');

// Get progress percentage
$progress = $job['total'] > 0 ? round(($job['processed'] / $job['total']) * 100) : 0;

// Get status label
$statusLabels = [
    STATUS_READY => 'Ready',
    STATUS_PROCESSING => 'Processing',
    STATUS_IMPORTING => 'Importing',
    STATUS_COMPLETE => 'Complete',
    STATUS_ERROR => 'Error'
];

$statusLabel = $statusLabels[$job['status']] ?? 'Unknown';

// Calculate elapsed time if available
$elapsedTime = '';
$estimatedRemaining = '';
if (!empty($job['start_time'])) {
    $startTime = new DateTime($job['start_time']);
    $now = new DateTime();
    $elapsedSeconds = $now->getTimestamp() - $startTime->getTimestamp();
    
    // Format elapsed time
    $elapsedTime = formatTimeInterval($elapsedSeconds);
    
    // Calculate estimated remaining time
    if ($progress > 0 && $progress < 100) {
        $totalEstimatedSeconds = $elapsedSeconds * (100 / $progress);
        $remainingSeconds = $totalEstimatedSeconds - $elapsedSeconds;
        $estimatedRemaining = formatTimeInterval($remainingSeconds);
    }
}

// Helper function to format time
function formatTimeInterval($seconds) {
    if ($seconds < 60) {
        return $seconds . " seconds";
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        $secs = $seconds % 60;
        return "$minutes min, $secs sec";
    } else {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return "$hours hr, $minutes min";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync Monitor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        .progress {
            height: 25px;
            box-shadow: 0 1px 5px rgba(0,0,0,0.1) inset;
            border-radius: 10px;
            background-color: #f5f5f5;
        }
        .progress-bar {
            line-height: 25px;
            font-weight: bold;
            text-shadow: 1px 1px 1px rgba(0,0,0,0.3);
            transition: width 0.6s ease;
        }
        .card {
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid rgba(0,0,0,0.125);
            padding: 15px 20px;
        }
        .stat-card {
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.1);
        }
        .time-info {
            font-size: 0.9rem;
            color: #6c757d;
        }
        .time-remaining {
            font-weight: bold;
            color: #0d6efd;
        }
        #progress-text {
            font-size: 14px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-sync-alt me-2"></i> Sync Progress</h4>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Job ID:</strong> <?= htmlspecialchars($jobId) ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Status:</strong> 
                                <span id="job-status" class="badge bg-<?= getStatusColor($job['status']) ?>">
                                    <?= $statusLabel ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>DHIS2 Instance:</strong> <?= htmlspecialchars($job['instance']) ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Started:</strong> <?= $job['created_at'] ?>
                            </div>
                        </div>
                        
                        <!-- Time information -->
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="time-info">
                                    <i class="far fa-clock me-1"></i> Elapsed: 
                                    <span id="elapsed-time"><?= $elapsedTime ?: 'Calculating...' ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="time-info">
                                    <i class="far fa-hourglass me-1"></i> Estimated remaining: 
                                    <span id="remaining-time" class="time-remaining">
                                        <?= $estimatedRemaining ?: 'Calculating...' ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <div class="progress">
                                    <div id="progress-bar" class="progress-bar <?= getProgressBarClass($job['status']) ?>" 
                                         role="progressbar" style="width: <?= $progress ?>%;" 
                                         aria-valuenow="<?= $progress ?>" aria-valuemin="0" aria-valuemax="100">
                                        <span id="progress-text"><?= $progress ?>%</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <div class="card stat-card bg-light">
                                    <div class="card-body text-center">
                                        <h5 class="card-title"><i class="fas fa-tasks me-2"></i>Processed</h5>
                                        <p id="processed-count" class="card-text fs-4">
                                            <?= $job['processed'] ?> / <?= $job['total'] ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card stat-card bg-light">
                                    <div class="card-body text-center">
                                        <h5 class="card-title"><i class="fas fa-plus-circle me-2"></i>Imported</h5>
                                        <p id="imported-count" class="card-text fs-4">
                                            <?= $job['inserted'] ?> new
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card stat-card bg-light">
                                    <div class="card-body text-center">
                                        <h5 class="card-title"><i class="fas fa-pencil-alt me-2"></i>Updated</h5>
                                        <p id="updated-count" class="card-text fs-4">
                                            <?= $job['updated'] ?> records
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h5 class="card-title"><i class="fas fa-info-circle me-2"></i>Status Message</h5>
                                        <p id="status-message" class="card-text">
                                            <?= getStatusMessage($job) ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12 text-center">
                                <?php if ($job['status'] === STATUS_COMPLETE): ?>
                                    <a href="settings.php?tab=load" class="btn btn-primary">
                                        <i class="fas fa-arrow-left me-2"></i> Back to Load Page
                                    </a>
                                <?php elseif ($job['status'] === STATUS_ERROR): ?>
                                    <a href="settings.php?tab=load" class="btn btn-danger">
                                        <i class="fas fa-times-circle me-2"></i> Job Failed - Return to Load Page
                                    </a>
                                <?php else: ?>
                                    <button type="button" class="btn btn-secondary" disabled>
                                        <i class="fas fa-spinner fa-spin me-2"></i> Processing...
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($job['status'] !== STATUS_COMPLETE && $job['status'] !== STATUS_ERROR): ?>
    <script>
        // Poll for job status updates
        const jobId = "<?= $jobId ?>";
        const totalUnits = <?= $job['total'] ?>;
        let lastProgress = <?= $progress ?>;
        let startTime = <?= !empty($job['start_time']) ? strtotime($job['start_time']) * 1000 : 'Date.now()' ?>;
        let lastUpdateTime = Date.now();
        let pollingInterval;
        let refreshThreshold = 1; // Refresh page when progress increases by 5%
        
        function updateProgress() {
            fetch(`sync_processor.php?job_id=${jobId}&offset=<?= $job['processed'] ?>`)
                .then(response => response.json())
                .then(data => {
                    const now = Date.now();
                    const elapsedMs = now - startTime;
                    const elapsedSec = Math.floor(elapsedMs / 1000);
                    
                    // Update progress bar
                    const progress = Math.round((data.processed / data.total) * 100);
                    document.getElementById('progress-bar').style.width = progress + '%';
                    document.getElementById('progress-bar').setAttribute('aria-valuenow', progress);
                    document.getElementById('progress-text').textContent = progress + '%';
                    
                    // Update counts
                    document.getElementById('processed-count').textContent = 
                        `${data.processed} / ${data.total}`;
                    document.getElementById('imported-count').textContent = 
                        `${data.inserted} new`;
                    document.getElementById('updated-count').textContent = 
                        `${data.updated} records`;
                    
                    // Update status
                    const statusElement = document.getElementById('job-status');
                    statusElement.textContent = getStatusLabel(data.status);
                    statusElement.className = `badge bg-${getStatusColor(data.status)}`;
                    
                    // Update status message
                    document.getElementById('status-message').textContent = data.message;
                    
                    // Update progress bar class
                    const progressBar = document.getElementById('progress-bar');
                    progressBar.className = `progress-bar ${getProgressBarClass(data.status)}`;
                    
                    // Update time information
                    document.getElementById('elapsed-time').textContent = formatTime(elapsedSec);
                    
                    // Calculate estimated remaining time
                    if (progress > 0 && progress < 100) {
                        const totalEstimatedSec = elapsedSec * (100 / progress);
                        const remainingSec = totalEstimatedSec - elapsedSec;
                        document.getElementById('remaining-time').textContent = formatTime(remainingSec);
                    }
                    
                    // Check if job is complete or has error
                    if (data.status === 'complete' || data.status === 'error') {
                        document.getElementById('remaining-time').textContent = 'Complete';
                        clearInterval(pollingInterval);
                        // Reload page to show final status
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                        return;
                    }
                    
                    // Check if we should refresh the page based on progress increment
                    if (progress - lastProgress >= refreshThreshold) {
                        lastProgress = progress;
                        window.location.reload();
                        return;
                    }
                    
                    // Adjust polling interval based on update frequency
                    const updateTimeGap = now - lastUpdateTime;
                    let pollInterval = 1000; // Default 1 second
                    
                    if (updateTimeGap > 5000) {
                        // If updates are slow, poll less frequently (every 3 seconds)
                        pollInterval = 3000;
                    } else if (updateTimeGap < 500) {
                        // If updates are fast, poll more frequently (every 0.5 seconds)
                        pollInterval = 500;
                    }
                    
                    lastUpdateTime = now;
                    
                    // Continue polling
                    setTimeout(updateProgress, pollInterval);
                })
                .catch(error => {
                    console.error('Error polling for job status:', error);
                    // If there's an error, wait longer before retrying
                    setTimeout(updateProgress, 5000);
                });
        }
        
        function formatTime(seconds) {
            if (seconds < 60) {
                return Math.floor(seconds) + " seconds";
            } else if (seconds < 3600) {
                const minutes = Math.floor(seconds / 60);
                const secs = Math.floor(seconds % 60);
                return `${minutes} min, ${secs} sec`;
            } else {
                const hours = Math.floor(seconds / 3600);
                const minutes = Math.floor((seconds % 3600) / 60);
                return `${hours} hr, ${minutes} min`;
            }
        }
        
        function getStatusLabel(status) {
            const labels = {
                'ready': 'Ready',
                'processing': 'Processing',
                'importing': 'Importing',
                'complete': 'Complete',
                'error': 'Error'
            };
            return labels[status] || 'Unknown';
        }
        
        function getStatusColor(status) {
            const colors = {
                'ready': 'info',
                'processing': 'primary',
                'importing': 'warning',
                'complete': 'success',
                'error': 'danger'
            };
            return colors[status] || 'secondary';
        }
        
        function getProgressBarClass(status) {
            const classes = {
                'ready': '',
                'processing': 'progress-bar-striped progress-bar-animated',
                'importing': 'progress-bar-striped progress-bar-animated bg-warning',
                'complete': 'bg-success',
                'error': 'bg-danger'
            };
            return classes[status] || '';
        }
        
        // Start polling when page loads
        document.addEventListener('DOMContentLoaded', function() {
            updateProgress();
            
            // Update elapsed time counter every second
            setInterval(function() {
                const elapsedSec = Math.floor((Date.now() - startTime) / 1000);
                document.getElementById('elapsed-time').textContent = formatTime(elapsedSec);
            }, 1000);
        });
    </script>
    <?php endif; ?>

    <?php
    // Helper functions
    function getStatusColor($status) {
        $colors = [
            STATUS_READY => 'info',
            STATUS_PROCESSING => 'primary',
            STATUS_IMPORTING => 'warning',
            STATUS_COMPLETE => 'success',
            STATUS_ERROR => 'danger'
        ];
        return $colors[$status] ?? 'secondary';
    }

    function getProgressBarClass($status) {
        $classes = [
            STATUS_READY => '',
            STATUS_PROCESSING => 'progress-bar-striped progress-bar-animated',
            STATUS_IMPORTING => 'progress-bar-striped progress-bar-animated bg-warning',
            STATUS_COMPLETE => 'bg-success',
            STATUS_ERROR => 'bg-danger'
        ];
        return $classes[$status] ?? '';
    }

    function getStatusMessage($job) {
        if ($job['status'] === STATUS_COMPLETE) {
            return "Import completed successfully. {$job['inserted']} new records inserted and {$job['updated']} records updated.";
        } elseif ($job['status'] === STATUS_ERROR) {
            return "An error occurred during processing. Please check the logs for details.";
        } elseif ($job['status'] === STATUS_IMPORTING) {
            return "Importing data into the database...";
        } elseif ($job['status'] === STATUS_PROCESSING) {
            return "Processing organization units ({$job['processed']} of {$job['total']})...";
        } else {
            return "Preparing to process organization units...";
        }
    }
    ?>
</body>
</html>