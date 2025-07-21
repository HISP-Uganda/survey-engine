<?php
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $age = $_POST['age']; 
    $sex = $_POST['sex'];
    $facilityId = $_POST['facility']; // This is the location_id (facility is a location)
    $serviceUnitId = $_POST['serviceUnit']; // This is the service_unit_id
    // $healthFacilityLevelId = $_POST['health_facility_level'];
    $ownershipId = $_POST['ownership']; // This is the ownership_id
    $reportingPeriod = $_POST['reporting_period'];

    // Validate ownership_id
    $stmt = $pdo->prepare("SELECT id FROM owner WHERE id = ?");
    $stmt->execute([$ownershipId]);
    if (!$stmt->fetch()) {
        die("Invalid ownership_id: $ownershipId");
    }

    // Insert into response_form table
    $stmt = $pdo->prepare("INSERT INTO response_form (uid, age, sex, location_id, service_unit_id, health_facility_level_id, ownership_id, period) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([uniqid(), $age, $sex, $facilityId, $serviceUnitId, $healthFacilityLevelId, $ownershipId, $reportingPeriod]);
    $formId = $pdo->lastInsertId();

    // Save responses
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'question_') === 0) {
            $questionId = str_replace('question_', '', $key);

            // Handle different question types
            if (is_array($value)) {
                // For checkbox questions (multiple selections)
                $response = json_encode($value);
            } else {
                // For radio, text, textarea, and select questions
                $response = json_encode($value);
            }

            // Insert the response into the database
            $stmt = $pdo->prepare("INSERT INTO responses (form_id, question_id, response) VALUES (?, ?, ?)");
            $stmt->execute([$formId, $questionId, $response]);
        }
    }

    echo "Form submitted successfully!";
} else {
    echo "Invalid request method.";
}
?>