<?php
// sync_monitor.php - Displays progress of sync job with Futuristic Design
session_start();

$jobId = $_GET['job_id'] ?? null;

// Check if job exists, redirect if not found
if (!$jobId || !isset($_SESSION['sync_jobs'][$jobId])) {
    header('Location: settings.php?tab=load&error=Invalid+job+ID');
    exit;
}

$job = $_SESSION['sync_jobs'][$jobId];

// Status constants (ensure these match sync_worker.php for consistency)
define('STATUS_READY', 'ready');
define('STATUS_PROCESSING', 'processing');
define('STATUS_IMPORTING', 'importing');
define('STATUS_COMPLETE', 'complete');
define('STATUS_ERROR', 'error');

// Get progress percentage
$progress = $job['total'] > 0 ? round(($job['processed'] / $job['total']) * 100) : 0;

// Helper functions for PHP-side rendering
// These align with the status variables and colors in your settings.php and previous sync_monitor.php
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
        STATUS_READY => 'bg-info', // Using bg-info for ready state
        STATUS_PROCESSING => 'progress-bar-striped progress-bar-animated bg-primary',
        STATUS_IMPORTING => 'progress-bar-striped progress-bar-animated bg-warning',
        STATUS_COMPLETE => 'bg-success',
        STATUS_ERROR => 'bg-danger'
    ];
    return $classes[$status] ?? 'bg-secondary';
}

function getStatusLabel($status) {
    $labels = [
        STATUS_READY => 'Ready',
        STATUS_PROCESSING => 'Processing',
        STATUS_IMPORTING => 'Importing',
        STATUS_COMPLETE => 'Complete',
        STATUS_ERROR => 'Error'
    ];
    return $labels[$status] ?? 'Unknown';
}

function getStatusMessage($job) {
    // Check for a custom 'message' field in $job for errors
    if ($job['status'] === STATUS_COMPLETE) {
        return "Import completed successfully. {$job['inserted']} new records inserted and {$job['updated']} records updated.";
    } elseif ($job['status'] === STATUS_ERROR) {
        return "An error occurred during processing. Please check the logs for details. Error: " . ($job['message'] ?? 'N/A');
    } elseif ($job['status'] === STATUS_IMPORTING) {
        return "Importing data into the database...";
    } elseif ($job['status'] === STATUS_PROCESSING) {
        return "Processing organization units ({$job['processed']} of {$job['total']})...";
    } else {
        return "Preparing to process organization units...";
    }
}

// Calculate elapsed time and estimated remaining time
$elapsedTime = '';
$estimatedRemaining = '';
$startTimeTimestamp = 0; // Default timestamp for JavaScript

if (!empty($job['start_time'])) {
    try {
        $startTime = new DateTime($job['start_time']);
        $startTimeTimestamp = $startTime->getTimestamp(); // Get Unix timestamp for JS
        $now = new DateTime();
        $elapsedSeconds = $now->getTimestamp() - $startTime->getTimestamp();
        
        $elapsedTime = formatTimeInterval($elapsedSeconds);
        
        if ($progress > 0 && $progress < 100) {
            // Calculate total estimated time based on current progress
            $totalEstimatedSeconds = ($elapsedSeconds / $progress) * 100;
            $remainingSeconds = $totalEstimatedSeconds - $elapsedSeconds;
            $estimatedRemaining = formatTimeInterval($remainingSeconds);
        }
    } catch (Exception $e) {
        error_log("Error parsing start_time in sync_monitor.php: " . $e->getMessage());
        $elapsedTime = 'N/A';
        $estimatedRemaining = 'N/A';
    }
}

// Helper function to format time intervals (PHP side, adapted from sample)
function formatTimeInterval($seconds) {
    if ($seconds < 0) return "0 seconds"; // Handle negative values gracefully
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
    <link rel="icon" href="argon-dashboard-master/assets/img/brand/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-icons.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-svg.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/argon-dashboard.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/sweetalert2.min.css" rel="stylesheet">
    
    <style>
        /* Custom styles for the sync monitor (adapted for futuristic dark theme) */
        /* These styles should ideally be in a global CSS file or settings.php for consistency */
        body {
            background-color: #0d121c !important; /* Dark background from your futuristic theme */
            color: #e2e8f0; /* Light text for readability */
        }
        .container {
            padding-top: 40px;
            padding-bottom: 40px;
        }
        .card {
            background-color: #1a202c !important; /* Dark card background */
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.5) !important; /* Stronger shadow */
            border-radius: 15px !important; /* More rounded corners */
            border: 1px solid #2d3748 !important; /* Subtle border */
            color: #e2e8f0 !important; /* Light text for card content */
        }
        .card-header {
            background-color: #0f172a !important; /* Even darker header */
            border-bottom: 1px solid #2d3748 !important; /* Darker border at bottom of header */
            padding: 20px 25px !important; /* More padding */
            color: #ffd700 !important; /* Gold text for header title */
            font-weight: bold;
            font-size: 1.25rem;
        }
        .card-header h4 {
            color: inherit !important; /* Inherit color from card-header */
            margin-bottom: 0;
        }
        .progress {
            height: 30px !important; /* Taller progress bar */
            box-shadow: inset 0 2px 5px rgba(0,0,0,0.3) !important; /* Darker inset shadow for depth */
            border-radius: 15px !important; /* Rounded corners for the progress bar container */
            background-color: #2d3748 !important; /* Darker background for the progress track */
            overflow: hidden; /* Ensures the progress-bar fill respects border-radius */
        }
        .progress-bar {
            line-height: 30px !important; /* Center text vertically within the bar */
            font-weight: bold !important;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.5) !important; /* More prominent text shadow */
            transition: width 0.6s ease-in-out !important; /* Smooth transition for width changes */
            color: #0d121c !important; /* Dark text on the gold bar for contrast */
            background-color: #ffd700 !important; /* Default gold color for the bar fill */
            animation: progress-pulse 2s infinite ease-in-out; /* Subtle pulse animation */
        }
        /* Pulse animation for progress bar background/shadow */
        @keyframes progress-pulse {
            0% { box-shadow: 0 0 0 rgba(255, 215, 0, 0); }
            50% { box-shadow: 0 0 10px rgba(255, 215, 0, 0.5); } /* Glow effect */
            100% { box-shadow: 0 0 0 rgba(255, 215, 0, 0); }
        }

        /* Status badges (adapted to futuristic theme colors) */
        .badge {
            font-size: 0.95rem !important;
            padding: 0.5em 0.8em !important;
        }
        .badge.bg-primary { background-color: #007bff !important; } /* Standard Argon blue */
        .badge.bg-success { background-color: #28a745 !important; } /* Standard Argon green */
        .badge.bg-danger { background-color: #dc3545 !important; } /* Standard Argon red */
        .badge.bg-warning { background-color: #ffc107 !important; } /* Standard Argon yellow */
        .badge.bg-info { background-color: #17a2b8 !important; }   /* Standard Argon cyan */
        .badge.bg-secondary { background-color: #6c757d !important; } /* Standard Argon gray */

        /* Time information styles */
        .time-info {
            font-size: 0.95rem;
            color: #94a3b8; /* Muted light gray for general info text */
        }
        .time-info i {
            color: #ffd700; /* Gold icon for time info */
        }
        .time-remaining {
            font-weight: bold;
            color: #00bcd4; /* Brighter cyan for emphasis on remaining time */
        }

        /* Stat cards (Processed, Imported, Updated) */
        .stat-card {
            background-color: #2d3748 !important; /* Darker card background for stats */
            border: 1px solid #4a5568 !important; /* Subtle border */
            border-radius: 10px !important; /* Rounded corners */
            transition: all 0.3s ease; /* Smooth hover effect */
            color: #e2e8f0 !important; /* Light text for stat card content */
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2); /* Subtle shadow */
        }
        .stat-card:hover {
            transform: translateY(-5px); /* Lift effect on hover */
            box-shadow: 0 8px 20px rgba(0,0,0,0.3); /* Enhanced shadow on hover */
        }
        .stat-card .card-title {
            color: #ffd700 !important; /* Gold title for stat cards */
            font-size: 1.1rem;
        }
        .stat-card .card-title i {
            color: inherit !important; /* Inherit color from card-title (gold) */
        }
        .stat-card .card-text {
            font-size: 2.5rem !important; /* Larger numbers for counts */
            font-weight: bold;
            color: #e2e8f0 !important; /* Light text for numbers */
            line-height: 1.2;
        }
        .stat-card .card-text small {
            font-size: 0.6em; /* Smaller "new" and "records" labels */
            color: #94a3b8 !important; /* Muted color for small text */
        }

        /* Status Message Card */
        #status-message-card {
            background-color: #2d3748 !important; /* Dark background */
            border: 1px solid #4a5568 !important; /* Subtle border */
        }
        #status-message-card .card-title {
            color: #ffd700 !important; /* Gold title */
        }
        #status-message {
            color: #e2e8f0 !important; /* Light message text */
            font-size: 1.05rem;
        }

        /* Buttons (match settings.php theme) */
        .btn-primary {
            background-color: #ffd700 !important;
            border-color: #ffd700 !important;
            color: #0f172a !important; /* Dark text on gold */
        }
        .btn-primary:hover {
            background-color: #ffdb58 !important;
            border-color: #ffdb58 !important;
            box-shadow: 0 4px 10px rgba(255, 215, 0, 0.3);
        }
        .btn-danger {
            background-color: #dc3545 !important;
            border-color: #dc3545 !important;
            color: #fff !important;
        }
        .btn-danger:hover {
            background-color: #c82333 !important;
            border-color: #bd2130 !important;
        }
        .btn-secondary {
            background-color: #6c757d !important;
            border-color: #6c757d !important;
            color: #e2e8f0 !important;
        }
        .btn-secondary:hover {
            background-color: #5a6268 !important;
            border-color: #545b62 !important;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-sync-alt me-3"></i> Sync Progress</h4>
                    </div>
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <p class="mb-1 text-muted"><strong>Job ID:</strong></p>
                                <p class="fw-bold fs-6"><?= htmlspecialchars($jobId) ?></p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <p class="mb-1 text-muted"><strong>Status:</strong></p>
                                <span id="job-status" class="badge bg-<?= getStatusColor($job['status']) ?>">
                                    <?= getStatusLabel($job['status']) ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <p class="mb-1 text-muted"><strong>DHIS2 Instance:</strong></p>
                                <p class="fw-bold fs-6"><?= htmlspecialchars($job['instance_key'] ?? $job['instance'] ?? 'N/A') ?></p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <p class="mb-1 text-muted"><strong>Started:</strong></p>
                                <p class="fw-bold fs-6"><?= $job['created_at'] ?></p>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="time-info">
                                    <i class="far fa-clock me-1"></i> Elapsed: 
                                    <span id="elapsed-time"><?= $elapsedTime ?: 'Calculating...' ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="time-info text-md-end">
                                    <i class="far fa-hourglass me-1"></i> Estimated remaining: 
                                    <span id="remaining-time" class="time-remaining">
                                        <?= $estimatedRemaining ?: 'Calculating...' ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
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
                        
                        <div class="row mb-4 gx-3">
                            <div class="col-md-4">
                                <div class="card stat-card">
                                    <div class="card-body text-center">
                                        <h5 class="card-title"><i class="fas fa-tasks me-2"></i>Processed</h5>
                                        <p id="processed-count" class="card-text">
                                            <?= $job['processed'] ?> / <small><?= $job['total'] ?></small>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card stat-card">
                                    <div class="card-body text-center">
                                        <h5 class="card-title"><i class="fas fa-plus-circle me-2"></i>Inserted</h5>
                                        <p id="imported-count" class="card-text">
                                            <?= $job['inserted'] ?> <small>new</small>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card stat-card">
                                    <div class="card-body text-center">
                                        <h5 class="card-title"><i class="fas fa-pencil-alt me-2"></i>Skipped/Updated</h5>
                                        <p id="updated-count" class="card-text">
                                            <?= $job['updated'] ?> <small>records</small>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <div class="card" id="status-message-card">
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
                                    <a href="settings.php?tab=load" class="btn btn-primary btn-lg">
                                        <i class="fas fa-arrow-left me-2"></i> Back to Load Page
                                    </a>
                                <?php elseif ($job['status'] === STATUS_ERROR): ?>
                                    <a href="settings.php?tab=load" class="btn btn-danger btn-lg">
                                        <i class="fas fa-times-circle me-2"></i> Job Failed - Return to Load Page
                                    </a>
                                <?php else: ?>
                                    <button type="button" class="btn btn-secondary btn-lg" disabled>
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

    <script src="argon-dashboard-master/assets/js/core/popper.min.js"></script>
    <script src="argon-dashboard-master/assets/js/core/bootstrap.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/argon-dashboard.js"></script>
    
    <?php if ($job['status'] !== STATUS_COMPLETE && $job['status'] !== STATUS_ERROR): ?>
    <script>
        // Poll for job status updates
        const jobId = "<?= $jobId ?>";
        const totalUnits = <?= $job['total'] ?>;
        // Ensure start_time is treated as milliseconds for JS Date
        let startTime = <?= !empty($job['start_time']) ? (new DateTime($job['start_time']))->getTimestamp() * 1000 : 'Date.now()' ?>;
        let lastProgress = <?= $progress ?>;
        let pollingIntervalId; // Renamed to avoid confusion with variable name

        // JS helper to format time (replicated from PHP for client-side calculations)
        function formatTime(seconds) {
            if (seconds < 0) return "0 seconds";
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

        // JS helper to get status label (replicated from PHP)
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

        // JS helper to get status color (replicated from PHP)
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

        // JS helper to get progress bar class (replicated from PHP)
        function getProgressBarClass(status) {
            const classes = {
                'ready': 'bg-info', // Consistent with PHP for initial load
                'processing': 'progress-bar-striped progress-bar-animated bg-primary',
                'importing': 'progress-bar-striped progress-bar-animated bg-warning',
                'complete': 'bg-success',
                'error': 'bg-danger'
            };
            return classes[status] || 'bg-secondary';
        }

        function updateProgress() {
            // Fetch status from sync_worker.php (your processing script)
            fetch(`sync_worker.php?job_id=${jobId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    const now = Date.now();
                    const elapsedMs = now - startTime;
                    const elapsedSec = Math.floor(elapsedMs / 1000);
                    
                    const currentProgress = Math.round((data.processed / data.total) * 100);
                    
                    // Update progress bar
                    const progressBar = document.getElementById('progress-bar');
                    progressBar.style.width = currentProgress + '%';
                    progressBar.setAttribute('aria-valuenow', currentProgress);
                    // Remove all existing bg- and animation classes before adding new one
                    ['bg-info', 'bg-primary', 'bg-warning', 'bg-success', 'bg-danger', 'bg-secondary',
                     'progress-bar-striped', 'progress-bar-animated'].forEach(cls => progressBar.classList.remove(cls));
                    progressBar.classList.add(...getProgressBarClass(data.status).split(' ')); // Add new classes
                    document.getElementById('progress-text').textContent = currentProgress + '%';
                    
                    // Update counts
                    document.getElementById('processed-count').innerHTML = `${data.processed} / <small>${data.total}</small>`;
                    document.getElementById('imported-count').innerHTML = `${data.inserted} <small>new</small>`;
                    document.getElementById('updated-count').innerHTML = `${data.updated} <small>records</small>`;
                    
                    // Update status badge
                    const statusElement = document.getElementById('job-status');
                    statusElement.textContent = getStatusLabel(data.status);
                    // Remove existing bg- classes before adding new one
                    ['bg-info', 'bg-primary', 'bg-warning', 'bg-success', 'bg-danger', 'bg-secondary'].forEach(cls => statusElement.classList.remove(cls));
                    statusElement.classList.add(`bg-${getStatusColor(data.status)}`);
                    
                    // Update status message
                    // Note: data.message from sync_worker might be generic, PHP's getStatusMessage is more detailed
                    document.getElementById('status-message').textContent = data.message; 
                    
                    // Update time information
                    document.getElementById('elapsed-time').textContent = formatTime(elapsedSec);
                    
                    // Calculate estimated remaining time
                    if (currentProgress > 0 && currentProgress < 100) {
                        const totalEstimatedSec = elapsedSec * (100 / currentProgress);
                        const remainingSec = totalEstimatedSec - elapsedSec;
                        document.getElementById('remaining-time').textContent = formatTime(remainingSec);
                    } else if (currentProgress === 100) {
                        document.getElementById('remaining-time').textContent = 'Complete';
                    } else { // Progress is 0, still calculating
                        document.getElementById('remaining-time').textContent = 'Calculating...';
                    }
                    
                    // Check if job is complete or has error
                    if (data.status === 'complete' || data.status === 'error') {
                        clearInterval(pollingIntervalId); // Stop polling
                        // Reload page to show final status and trigger button change
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000); // Give it a moment before reloading
                        return;
                    }
                    
                    // Adjust polling interval dynamically
                    let pollInterval = 1200; // Default poll every 1.2 seconds
                    // You can add more complex logic here if needed, based on data.processed speed.
                    
                    // Continue polling
                    pollingIntervalId = setTimeout(updateProgress, pollInterval); // Use setTimeout for adaptive polling
                })
                .catch(error => {
                    console.error('Error polling for job status:', error);
                    clearInterval(pollingIntervalId); // Stop polling on error
                    document.getElementById('status-message').textContent = `Network error or server issue: ${error.message}. Check browser console for details.`;
                    document.getElementById('job-status').className = `badge bg-danger`;
                    document.getElementById('job-status').textContent = 'Error';
                    document.getElementById('remaining-time').textContent = 'Error';
                    // Do not reload automatically on network error unless desired, allow user to debug
                });
        }
        
        // Start polling when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Initial call to update progress immediately
            updateProgress();
            
            // Update elapsed time counter every second locally (for smooth display)
            // This is separate from polling for continuous elapsed time update
            setInterval(function() {
                const elapsedSec = Math.floor((Date.now() - startTime) / 1000);
                document.getElementById('elapsed-time').textContent = formatTime(elapsedSec);
                
                // Only update remaining time locally if job is still active
                const currentStatusText = document.getElementById('job-status').textContent;
                const activeStatuses = [getStatusLabel('processing'), getStatusLabel('importing')];
                
                if (activeStatuses.includes(currentStatusText)) {
                    const currentProgress = parseInt(document.getElementById('progress-bar').getAttribute('aria-valuenow'));
                    if (currentProgress > 0 && currentProgress < 100) {
                        const totalEstimatedSec = elapsedSec * (100 / currentProgress);
                        const remainingSec = totalEstimatedSec - elapsedSec;
                        document.getElementById('remaining-time').textContent = formatTime(remainingSec);
                    }
                }
            }, 1000); // Update every 1 second
        });
    </script>
    <?php endif; ?>
</body>
</html>