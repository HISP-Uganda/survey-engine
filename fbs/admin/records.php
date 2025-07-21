<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require 'connect.php';


$surveyId = $_GET['survey_id'] ?? null;
$submissions = [];
$surveyName = '';

// Fetch all surveys if no survey_id is provided
if (!$surveyId) {
    $surveys = $pdo->query("SELECT id, name FROM survey")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} else {
    // Fetch submissions for the selected survey
    $sortBy = $_GET['sort'] ?? 'created_desc';

    // Define valid sorting options
    $validSortOptions = [
        'created_asc' => 's.created ASC',
        'created_desc' => 's.created DESC',
        'uid_asc' => 's.uid ASC',
        'uid_desc' => 's.uid DESC',
    ];

    $orderBy = $validSortOptions[$sortBy] ?? 's.created DESC';

    try {
        $stmt = $pdo->prepare("
            SELECT 
                s.id,
                s.uid,
                s.age,
                s.sex,
                s.period,
                su.name AS service_unit_name,
                l.name AS location_name,
                o.name AS ownership_name,
                s.created,
                COUNT(sr.id) AS response_count
            FROM submission s
            LEFT JOIN submission_response sr ON s.id = sr.submission_id
            LEFT JOIN service_unit su ON s.service_unit_id = su.id
            LEFT JOIN location l ON s.location_id = l.id
            LEFT JOIN owner o ON s.ownership_id = o.id
            WHERE s.survey_id = :survey_id
            GROUP BY s.id, su.name, l.name, o.name
            ORDER BY $orderBy
        ");
        $stmt->execute(['survey_id' => $surveyId]);
        $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Fetch survey name
        $survey = $pdo->prepare("SELECT name FROM survey WHERE id = :survey_id");
        $survey->execute(['survey_id' => $surveyId]);
        $surveyName = $survey->fetchColumn() ?: 'Unknown Survey';
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Survey Submissions</title>
    <!-- Favicon -->
    <link rel="icon" href="argon-dashboard-master/assets/img/brand/favicon.png" type="image/png">
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet">
    <!-- Icons -->
    <link href="argon-dashboard-master/assets/css/nucleo-icons.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-svg.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Argon CSS -->
    <link href="argon-dashboard-master/assets/css/argon-dashboard.css" rel="stylesheet">
    <!-- Sweet Alert -->
    <link href="argon-dashboard-master/assets/css/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

    <style>
        body {
    overflow-x: hidden;
    position: relative;
}
        .card-blog {
            transition: all 0.3s ease;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }
        .card-blog:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }
        .text-gradient {
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            position: relative;
            z-index: 1;
        }
        .table-responsive {
            border-radius: 12px;
            overflow: hidden;
        }
        .table thead th {
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            text-transform: uppercase; 
            background-color: #f8f9fa;
        }
        .table tbody tr {
            transition: all 0.2s ease;
        }
        .table tbody tr:hover {
            background-color: rgba(233, 236, 239, 0.5);
        }
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px;
            text-align: center;
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #adb5bd;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100">
 <?php include 'components/aside.php'; ?>

    <div class="main-content position-relative border-radius-lg">
        <?php include 'components/navbar.php'; ?>
        
           <!-- Page Title Section -->
 <div class="d-flex align-items-center flex-grow-1" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); padding: 1rem 1.5rem; margin-bottom: 1.5rem;">
            <nav aria-label="breadcrumb" class="flex-grow-1">
            <ol class="breadcrumb mb-0 navbar-breadcrumb" style="background: transparent;">
                <li class="breadcrumb-item">
                <a href="main" class="breadcrumb-link" style="color: #ffd700; font-weight: 600;">
                    <i class="fas fa-home me-1" style="color: #ffd700;"></i>Home
                </a>
                </li>
                <li class="breadcrumb-item active navbar-breadcrumb-active" aria-current="page" style="color: #fff; font-weight: 700;">
                <?= $pageTitle ?>
                </li>
            </ol>
            <h5 class="navbar-title mb-0" style="color: #fff; text-shadow: 0 1px 8px #1e3c72, 0 0 2px #ffd700;">
                <?= $pageTitle ?>
            </h5>
            </nav>
        </div>
        
        <div class="container-fluid py-4">
            <div class="row">
                <div class="col-12">
                    <?php if (!$surveyId): ?>
                        <!-- Survey List View -->
                        <div class="card mb-4">
                            <div class="card-header pb-0">
                                <h4>Select a Survey to View Submissions</h4>
                                <p class="text-sm mb-0">
                                    <i class="fa fa-info-circle me-1"></i>
                                    Click on any survey below to view its submissions
                                </p>
                            </div>
                            <div class="card-body px-0 pt-0 pb-2">
                                <?php if (empty($surveys)): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-clipboard-list"></i>
                                        <h5>No Surveys Found</h5>
                                        <p class="text-muted">There are no surveys available to display.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="row p-4">
                                        <?php foreach ($surveys as $survey): ?>
                                            <div class="col-xl-3 col-md-6 mb-xl-4 mb-4">
                                                <div class="card card-blog card-plain">
                                                    <div class="card-body p-3">
                                                        <div class="d-flex flex-column">
                                                            <h5 class="mb-1 text-gradient text-primary">
                                                                <?php echo htmlspecialchars($survey['name']); ?>
                                                            </h5>
                                                            <a href="records?survey_id=<?php echo $survey['id']; ?>" 
                                                               class="btn btn-outline-primary btn-sm mt-3">
                                                                View Submissions
                                                                <i class="fas fa-arrow-right ms-1"></i>
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Submissions Table View -->
                        <div class="card mb-4">
                            <div class="card-header pb-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h4>Survey: <?php echo htmlspecialchars($surveyName); ?></h4>
                                        <p class="text-sm mb-0">
                                            <i class="fa fa-list me-1"></i>
                                            All submissions for this survey
                                        </p>
                                    </div>
                                    <div>
                                        <button class="btn bg-gradient-secondary mb-0 me-2" onclick="goBack()">
                                            <i class="fas fa-arrow-left me-2"></i> Back to Surveys
                                        </button>
                                        <div class="d-flex align-items-center" style="gap: 1rem;">
                                        <!-- Sort By Dropdown -->
                                        <div class="dropdown">
                                        <button class="btn bg-gradient-info mb-0 dropdown-toggle" type="button" id="sortDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="fas fa-sort me-2"></i> Sort By
                                        </button>
                                        <ul class="dropdown-menu" aria-labelledby="sortDropdown">
                                        <li><a class="dropdown-item" href="?survey_id=<?= $surveyId ?>&sort=created_desc">Newest First</a></li>
                                        <li><a class="dropdown-item" href="?survey_id=<?= $surveyId ?>&sort=created_asc">Oldest First</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item" href="?survey_id=<?= $surveyId ?>&sort=uid_asc">UID (A-Z)</a></li>
                                        <li><a class="dropdown-item" href="?survey_id=<?= $surveyId ?>&sort=uid_desc">UID (Z-A)</a></li>
                                        </ul>
                                        </div>
                                        <!-- Download Dropdown -->
                                        <div class="dropdown">
                                        <button class="btn bg-gradient-success mb-0 dropdown-toggle" type="button" id="downloadDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="fas fa-download me-2"></i> Download
                                        </button>
                                        <ul class="dropdown-menu" aria-labelledby="downloadDropdown">
                                        <li><button type="button" class="dropdown-item" onclick="downloadData('pdf')">PDF Format</button></li>
                                        <li><button type="button" class="dropdown-item" onclick="downloadData('csv')">CSV Format</button></li>
                                        <li><button type="button" class="dropdown-item" onclick="downloadData('excel')">Excel Format</button></li>
                                        <li><button type="button" class="dropdown-item" onclick="downloadData('json')">JSON Format</button></li>
                                        <!-- <li><button type="button" class="dropdown-item" onclick="downloadData('xml')">XML Format</button></li>
                                        <li><button type="button" class="dropdown-item" onclick="downloadData('html')">HTML Format</button></li> -->
                                        </ul>
                                        </div>
                                </div>

                                            <ul class="dropdown-menu">
                                                <li><a class="dropdown-item" href="?survey_id=<?= $surveyId ?>&sort=created_desc">Newest First</a></li>
                                                <li><a class="dropdown-item" href="?survey_id=<?= $surveyId ?>&sort=created_asc">Oldest First</a></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item" href="?survey_id=<?= $surveyId ?>&sort=uid_asc">UID (A-Z)</a></li>
                                                <li><a class="dropdown-item" href="?survey_id=<?= $surveyId ?>&sort=uid_desc">UID (Z-A)</a></li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body px-0 pt-0 pb-2">
                                <?php if (empty($submissions)): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-inbox"></i>
                                        <h5>No Submissions Found</h5>
                                        <p class="text-muted">There are no submissions for this survey yet.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive p-0">
                                        <table class="table align-items-center mb-0">
                                            <thead>
                                                <tr>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">ID</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">UID</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Age</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Sex</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Period</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Service Unit</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Location</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Ownership</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Responses</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Created</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($submissions as $submission): ?>
                                                    <tr>
                                                        <td class="ps-4">
                                                            <p class="text-xs font-weight-bold mb-0"><?php echo $submission['id']; ?></p>
                                                        </td>
                                                        <td>
                                                            <p class="text-xs font-weight-bold mb-0"><?php echo htmlspecialchars($submission['uid']); ?></p>
                                                        </td>
                                                        <td>
                                                            <p class="text-xs font-weight-bold mb-0"><?php echo $submission['age'] ?? 'N/A'; ?></p>
                                                        </td>
                                                        <td>
                                                            <p class="text-xs font-weight-bold mb-0"><?php echo htmlspecialchars($submission['sex'] ?? 'N/A'); ?></p>
                                                        </td>
                                                        <td>
                                                            <p class="text-xs font-weight-bold mb-0"><?php echo htmlspecialchars($submission['period'] ?? 'N/A'); ?></p>
                                                        </td>
                                                        <td>
                                                            <p class="text-xs font-weight-bold mb-0"><?php echo htmlspecialchars($submission['service_unit_name'] ?? 'N/A'); ?></p>
                                                        </td>
                                                        <td>
                                                            <p class="text-xs font-weight-bold mb-0"><?php echo htmlspecialchars($submission['location_name'] ?? 'N/A'); ?></p>
                                                        </td>
                                                        <td>
                                                            <p class="text-xs font-weight-bold mb-0"><?php echo htmlspecialchars($submission['ownership_name'] ?? 'N/A'); ?></p>
                                                        </td>
                                                        <td>
                                                            <span class="badge badge-sm bg-gradient-success"><?php echo $submission['response_count']; ?></span>
                                                        </td>
                                                        <td>
                                                            <p class="text-xs font-weight-bold mb-0"><?php echo date('M d, Y', strtotime($submission['created'])); ?></p>
                                                        </td>
                                                        <td class="align-middle">
                                                            <button class="btn btn-link text-dark px-2 mb-0" onclick="viewSubmission(<?php echo $submission['id']; ?>)">
                                                                <i class="fas fa-eye text-info me-2"></i>
                                                            </button>
                                                            <button class="btn btn-link text-dark px-2 mb-0" onclick="deleteSubmission(<?php echo $submission['id']; ?>)">
                                                                <i class="fas fa-trash-alt text-danger me-2"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

   
    <!-- Core JS Files -->
     <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="argon-dashboard-master/assets/js/core/popper.min.js"></script>
    <script src="argon-dashboard-master/assets/js/core/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/sweetalert2.all.min.js"></script>
    <script src="argon-dashboard-master/assets/js/argon-dashboard.js"></script>
    
    <script>
        // Initialize Argon components
        if (typeof Argon !== 'undefined') {
            Argon.init();
        }

        // Sidebar toggle functionality
       document.addEventListener('DOMContentLoaded', function() {
            var icon = document.getElementById('iconNavbarSidenav');
            if (icon) {
                icon.addEventListener('click', function() {
                    document.body.classList.toggle('g-sidenav-pinned');
                    document.body.classList.toggle('g-sidenav-hidden');
                });
            }
        });

        // Ensure sidebar is visible on page load
        document.addEventListener('DOMContentLoaded', function() {
            if (window.innerWidth > 1200) {
                document.body.classList.remove('g-sidenav-hidden');
                document.body.classList.add('g-sidenav-pinned');
            }
        });

        // View submission details
        function viewSubmission(submissionId) {
            window.location.href = `view_record?submission_id=${submissionId}`;
        }

        // Delete submission with confirmation
        function deleteSubmission(submissionId) {
            Swal.fire({
                title: 'Are you sure?',
                text: "You won't be able to revert this!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch(`delete_submission.php`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `id=${submissionId}&action=delete`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire(
                                'Deleted!',
                                'The submission has been deleted.',
                                'success'
                            ).then(() => location.reload());
                        } else {
                            Swal.fire(
                                'Error!',
                                data.message || 'Failed to delete submission.',
                                'error'
                            );
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire(
                            'Error!',
                            'An error occurred while deleting the submission.',
                            'error'
                        );
                    });
                }
            });
        }

        // Add this to your script section at the bottom of records.php
function downloadData(format) {
    const surveyId = <?php echo $surveyId; ?>;
    const sortBy = '<?php echo $sortBy ?? "created_desc"; ?>';

    // Show loading indicator
    Swal.fire({
        title: 'Preparing Download',
        html: 'Please wait while we prepare your file...',
        timerProgressBar: true,
        didOpen: () => {
            Swal.showLoading();
        },
        allowOutsideClick: false
    });

    // Create a new form
    const form = document.createElement('form');
    form.method = 'POST'; // Crucial: Use POST method for sending data
    form.action = 'generate_download.php'; // The target PHP script

    // Add survey ID parameter
    const surveyIdInput = document.createElement('input');
    surveyIdInput.type = 'hidden';
    surveyIdInput.name = 'survey_id';
    surveyIdInput.value = surveyId;
    form.appendChild(surveyIdInput);

    // Add sort parameter
    const sortInput = document.createElement('input');
    sortInput.type = 'hidden';
    sortInput.name = 'sort';
    sortInput.value = sortBy;
    form.appendChild(sortInput);

    // Add format parameter
    const formatInput = document.createElement('input');
    formatInput.type = 'hidden';
    formatInput.name = 'format';
    formatInput.value = format;
    form.appendChild(formatInput);

    document.body.appendChild(form); // Append the form to the body
    form.submit(); // Submit the form

    // Close the loading dialog after a short delay
    setTimeout(() => {
        Swal.close();
    }, 3000);
}

        // Go back to the list of surveys
        function goBack() {
            window.location.href = 'dashbard';
        }

        
    </script>
</body>
</html>