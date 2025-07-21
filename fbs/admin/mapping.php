<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>DHIS2 Sync</title>
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
      padding: 20px;
    }
    h1 {
      text-align: center;
      color: #333;
      margin-bottom: 20px;
    }
    .button-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
      align-items: center;
    }
    .button-item {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
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
    .button-item i {
      font-size: 18px;
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
              <i class="fas fa-exclamation-circle me-2"></i> DHIS2 Sync Intergration Menu
              </h1>
            </div>
      <div class="button-list">
        <a href="sb.php" class="button-item">
          <i class="fas fa-sync-alt"></i> No Survey in SFT
        </a>
        <a href="pb.php" class="button-item">
          <i class="fas fa-database"></i> Existing Survey in SFT
        </a>
        <a href="survey.php" class="button-item">
          <i class="fas fa-question-circle"></i> Other Cases
        </a>
        <a href="survey.php" class="button-item" style="background-color: #f5365c;">
          <i class="fas fa-arrow-left"></i> Back
        </a>
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