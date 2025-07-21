<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require 'connect.php';

// Ensure submissionId is sanitized
$submissionId = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (!$submissionId) {
    die("<div class='alert alert-danger text-center'>Submission ID is required.</div>");
}

// Fetch submission details
$submissionQuery = $pdo->prepare("SELECT * FROM response_form WHERE id = ?");
$submissionQuery->execute([$submissionId]);
$submission = $submissionQuery->fetch(PDO::FETCH_ASSOC);

if (!$submission) {
    die("<div class='alert alert-danger text-center'>Submission not found.</div>");
}

// Fetch responses for the submission
$responsesQuery = $pdo->prepare("
    SELECT r.question_id, r.response, q.label
    FROM responses r
    JOIN question q ON r.question_id = q.id
    WHERE r.form_id = ?
");
$responsesQuery->execute([$submissionId]);
$responses = $responsesQuery->fetchAll(PDO::FETCH_ASSOC);

// Fetch location details
// $locationQuery = $pdo->prepare("
//     SELECT l.name AS location_name, l.path AS location_path, 
//            hfl.name AS health_facility_level
//     FROM location l
//     LEFT JOIN health_facility_level hfl ON l.health_facility_level_id = hfl.id
//     WHERE l.id = ?
// ");
$locationQuery->execute([$submission['location_id'] ?? null]);
$location = $locationQuery->fetch(PDO::FETCH_ASSOC);

// Fetch ownership details
$ownershipQuery = $pdo->prepare("
    SELECT o.name AS ownership
    FROM location_ownership lo
    JOIN owner o ON lo.owner_id = o.id
    WHERE lo.location_id = ?
");
$ownershipQuery->execute([$submission['location_id'] ?? null]);
$ownership = $ownershipQuery->fetch(PDO::FETCH_ASSOC);


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Submission</title>

    <!-- Bootstrap & Custom Styles -->
    <link href="assets/plugins/bootstrap/bootstrap.css" rel="stylesheet" />
    <link href="assets/font-awesome/css/font-awesome.css" rel="stylesheet" />
    <link href="assets/css/style.css" rel="stylesheet" />
    <link href="assets/css/main-style.css" rel="stylesheet" />
    <link href="assets/plugins/dataTables/dataTables.bootstrap.css" rel="stylesheet" />
    
   

    <style>
       /* General body styling */
body {
    background-color: #f8f9fa;
    font-family: Arial, sans-serif;
    overflow-x: hidden; /* Prevent horizontal scrolling */

}

/* Main content container */
.main-content {
    max-width: 100%; /* Ensure it doesn't exceed the screen width */
    margin: 0 auto;
    padding: 20px;
    min-height: 100vh; /* Ensure it takes the full viewport height */
                       /* Prevent horizontal scrolling */
    margin-left: 100px;
    padding-top: -3px;     /* Space for top navbar */
   
    padding-bottom: 5px;  /* Bottom padding */
    padding-left: 10px;    /* Space from sidebar */

}

/* Card container */
.card {
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    padding: 150px;
    background-color: white;
    padding-top: 50px;     /* Space for top navbar */
}

/* Table Styling */
.table {
    width: 100%;
    border-collapse: collapse;
}

/* Make tables scrollable on smaller screens */
.table-responsive {
    width: 100%;
    overflow-x: auto;
}

/* Ensure table cells don't break layout */
.table th, .table td {
    padding: 10px;
    text-align: left;
    word-wrap: break-word;
    white-space: normal; /* Prevent text from overflowing */
}

/* Responsive fix for smaller screens */
@media (max-width: 768px) {
    .main-content {
        padding: 10px;
    }
    .table-responsive {
        overflow-x: auto;
    }
    .card {
        padding: 15px;
    }
}

      
    </style>
</head>
<body>
<?php include 'components/nav.php'; ?>
<?php include 'components/sidebar.php'; ?>

<div class="main-content">
    <div class="container">
        <div class="card p-4 bg-white">
            <h2 class="mb-4"><i class="fa fa-file-text"></i> Submission Details</h2>

            <h4 class="text-primary"><i class="fa fa-user"></i> User Information</h4>
            <table class="table table-striped">
                <tbody>
                    <tr><th>UID</th><td><?php echo htmlspecialchars($submission['uid']); ?></td></tr>
                    <tr><th>Location</th><td><?php echo htmlspecialchars($location['location_name'] ?? 'N/A'); ?></td></tr>
                    <tr><th>Location Path</th><td><?php echo htmlspecialchars($location['location_path'] ?? 'N/A'); ?></td></tr>
                    <tr><th>Age</th><td><?php echo htmlspecialchars($submission['age']); ?></td></tr>
                    <tr><th>Sex</th><td><?php echo htmlspecialchars($submission['sex']); ?></td></tr>
                    <tr><th>Period</th><td><?php echo htmlspecialchars($submission['period']); ?></td></tr>
                    <tr><th>Created</th><td><?php echo htmlspecialchars($submission['created']); ?></td></tr>
                    <!-- <tr><th>Health Facility Level</th><td><?php echo htmlspecialchars($location['health_facility_level'] ?? 'N/A'); ?></td></tr> -->
                    <tr><th>Ownership</th><td><?php echo htmlspecialchars($ownership['ownership'] ?? 'N/A'); ?></td></tr>
                </tbody>
            </table>

            <h4 class="text-primary mt-4"><i class="fa fa-list"></i> Responses</h4>
            <table class="table table-bordered">
                <thead class="thead-dark">
                    <tr>
                        <th>Question</th>
                        <th>Response</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($responses as $response): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($response['label']); ?></td>
                            <td><?php echo htmlspecialchars(json_decode($response['response'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <button class="btn btn-secondary back-btn" onclick="window.history.back()">
                <i class="fa fa-arrow-left"></i> Back
            </button>
        </div>
    </div>
</div>

<!-- JS Scripts -->
<script src="assets/plugins/jquery-1.10.2.js"></script>
<script src="assets/plugins/bootstrap/bootstrap.min.js"></script>
<script src="assets/plugins/metisMenu/jquery.metisMenu.js"></script>
<script src="assets/plugins/pace/pace.js"></script>
<script src="assets/scripts/siminta.js"></script>
<script src="assets/plugins/dataTables/jquery.dataTables.js"></script>
<script src="assets/plugins/dataTables/dataTables.bootstrap.js"></script>
<script>
    $(document).ready(function () {
        $('.table').DataTable();
    });
</script>

</body>
</html>
