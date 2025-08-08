<?php
session_start();

// Security check
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require 'connect.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Survey Checker</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
</head>
<body class='container py-4'>
<h2>Survey Status Check</h2>";

try {
    // Check surveys
    $stmt = $pdo->query("SELECT id, name, type, dhis2_program_uid FROM survey ORDER BY id DESC LIMIT 10");
    $surveys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<div class='mb-4'>
            <h4>Current Surveys:</h4>
            <table class='table table-striped'>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>DHIS2 Program UID</th>
                    <th>Action</th>
                </tr>";
    
    foreach ($surveys as $survey) {
        $hasProgram = !empty($survey['dhis2_program_uid']);
        echo "<tr class='" . ($hasProgram ? 'table-success' : 'table-warning') . "'>";
        echo "<td>{$survey['id']}</td>";
        echo "<td>" . htmlspecialchars($survey['name']) . "</td>";
        echo "<td>" . htmlspecialchars($survey['type'] ?? 'local') . "</td>";
        echo "<td>" . ($hasProgram ? htmlspecialchars($survey['dhis2_program_uid']) : '<em>Not set</em>') . "</td>";
        echo "<td>";
        if (!$hasProgram) {
            echo "<a href='?add_program_uid=" . $survey['id'] . "' class='btn btn-sm btn-primary'>Add Program UID</a>";
        }
        echo "</td>";
        echo "</tr>";
    }
    echo "</table></div>";
    
    // Handle adding program UID
    if (isset($_GET['add_program_uid'])) {
        $surveyId = $_GET['add_program_uid'];
        $programUID = 'PROG_' . uniqid();
        
        $stmt = $pdo->prepare("UPDATE survey SET dhis2_program_uid = ? WHERE id = ?");
        $stmt->execute([$programUID, $surveyId]);
        
        echo "<div class='alert alert-success'>
                Added DHIS2 Program UID '{$programUID}' to survey ID {$surveyId}. 
                <a href='check_surveys.php'>Refresh page</a>
              </div>";
    }
    
    // Show form to manually add program UID
    echo "<div class='card'>
            <div class='card-header'><h5>Manually Add DHIS2 Program UID</h5></div>
            <div class='card-body'>
                <form method='POST'>
                    <div class='row'>
                        <div class='col-md-4'>
                            <select name='survey_id' class='form-control' required>
                                <option value=''>Select Survey</option>";
    
    foreach ($surveys as $survey) {
        if (empty($survey['dhis2_program_uid'])) {
            echo "<option value='{$survey['id']}'>" . htmlspecialchars($survey['name']) . "</option>";
        }
    }
    
    echo "                </select>
                        </div>
                        <div class='col-md-4'>
                            <input type='text' name='program_uid' class='form-control' placeholder='Enter DHIS2 Program UID' required>
                        </div>
                        <div class='col-md-4'>
                            <button type='submit' name='manual_add' class='btn btn-success'>Add Program UID</button>
                        </div>
                    </div>
                </form>
            </div>
          </div>";
    
    // Handle manual form submission
    if (isset($_POST['manual_add'])) {
        $surveyId = $_POST['survey_id'];
        $programUID = $_POST['program_uid'];
        
        $stmt = $pdo->prepare("UPDATE survey SET dhis2_program_uid = ? WHERE id = ?");
        $stmt->execute([$programUID, $surveyId]);
        
        echo "<div class='alert alert-success mt-3'>
                Successfully added DHIS2 Program UID '{$programUID}' to survey ID {$surveyId}. 
                <a href='check_surveys.php'>Refresh page</a>
              </div>";
    }
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
}

echo "<div class='mt-4'>
        <a href='survey.php' class='btn btn-secondary'>Back to Surveys</a>
      </div>
</body>
</html>";
?>