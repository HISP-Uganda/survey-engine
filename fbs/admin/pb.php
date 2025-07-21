<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require 'connect.php';
require 'dhis2/dhis2_shared.php';
require 'dhis2/dhis2_get_function.php';

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>DHIS2-Program-Builder</title>
  <link href="argon-dashboard-master/assets/css/nucleo-icons.css" rel="stylesheet">
  <link href="argon-dashboard-master/assets/css/nucleo-svg.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
  <link href="argon-dashboard-master/assets/css/argon-dashboard.min.css" rel="stylesheet">
  <style>
 body {
      font-family: Arial, sans-serif;
      background-color: #f9f9f9;
      margin: 0;
      padding: 0;
    }
    .container {
    
    }
    h1 {
      text-align: center;
      color: #333;
    }

 .button-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
      align-items: center;
    }
    .button-item {
      display: block;
      text-align: center;
      padding: 10px 20px;
      background-color: #5e72e4;
      color: #fff;
      text-decoration: none;
      border-radius: 5px;
      font-size: 16px;
      transition: background-color 0.3s ease;
      width: 100%;
      max-width: 400px;
    }
    .button-item:hover {
      background-color: #324cdd;
    }
  </style>
</head>
<body>

  <?php include 'components/aside.php'; ?>
  
  <main class="main-content position-relative border-radius-lg">
    <?php include 'components/navbar.php'; ?>

    <div class="container">
   
<div class="card-header pb-0 text-center">
              <h1 class="text-primary">
                <i class="fas fa-server me-2"></i>Existing Survey in SFT But No Existing Program in DHIS2
              </h1>
            </div>
      <p style="text-align: center; color: #888; font-size: 50px;">(This feature is still under development. Coming soon! )</p>

 <div class="button-list">
 <a class="button-item">Create A program/ Dataset in DHIS2 out of an existing Survey</a>    

        <a href="survey" class="button-item" style="background-color: #f5365c;">Back</a>
      </div>
    </div>



    <?php include 'components/fixednav.php'; ?>
  </main>

  <!-- Argon Dashboard JS -->
  <script src="argon-dashboard-master/assets/js/core/popper.min.js"></script>
  <script src="argon-dashboard-master/assets/js/core/bootstrap.min.js"></script>
  <script src="argon-dashboard-master/assets/js/plugins/perfect-scrollbar.min.js"></script>
  <script src="argon-dashboard-master/assets/js/plugins/smooth-scrollbar.min.js"></script>
  <script src="argon-dashboard-master/assets/js/argon-dashboard.min.js"></script>

</body>
</html>