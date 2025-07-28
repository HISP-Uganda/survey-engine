<?php
session_start();

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require 'connect.php';
require 'dhis2/dhis2_shared.php';

$activeTab = $_GET['tab'] ?? 'view';
$message = [];

// Optional: Preload any data or messages for all tabs here

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Locations</title>
    <link rel="icon" href="argon-dashboard-master/assets/img/brand/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-icons.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-svg.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/argon-dashboard.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/sweetalert2.min.css" rel="stylesheet">
    <style>
        /* Base styles from sync_monitor.php (optional, include if these are used elsewhere) */
        
        .metric-value {
            font-size: 2.2rem;
            font-weight: 700;
            line-height: 1;
            display: block;
        }
        .metric-label {
            font-size: 0.85rem;
            color: #6c757d;
        }
        .metric-errors .metric-value {
            color: #dc3545;
        }
        .status-badge {
            font-size: 1rem;
            padding: 0.5em 0.8em;
        }
        .progress-bar {
            font-weight: bold;
            font-size: 1.1rem;
        }
        .sync-message {
            font-size: 1.1rem;
            font-weight: 500;
            margin-top: 1rem;
            padding: 1rem;
            background-color: #f8f9fa;
            border-radius: 0.375rem;
            border: 1px solid #e9ecef;
        }
        .card-header .icon-with-text {
            display: flex;
            align-items: center;
        }
        .card-header .icon-with-text i {
            margin-right: 0.5rem;
        }

        /* --- FUTURISTIC DESIGN STYLES START HERE --- */

        /* Overall Darker Background */
        body.bg-gray-100 {
            background-color: #bfbfbf !important; /* Dark blue/black background */
        }

        /* Main Content Area */
        .main-content {
            background-color: #bfbfbf; /* Slightly lighter dark for content area */
        }
        .container-fluid.py-4 {
            background-color: #bfbfbf; /* Ensure content background matches */
            border-radius: 1rem;
            padding: 2rem !important; /* More padding */
            box-shadow: inset 0 0 15px rgba(0, 0, 0, 0.3); /* Inner shadow for depth */
        }

        /* Text Colors for Dark Background */
        .container-fluid h4, .container-fluid h5, .container-fluid h6,
        .container-fluid p, .container-fluid label, .container-fluid strong, .container-fluid small {
            color: #e2e8f0; /* Light gray text for readability */
        }
        .text-muted {
            color: #94a3b8 !important; /* Muted text slightly lighter */
        }
        .breadcrumb-link, .breadcrumb-item.active {
            text-shadow: 0 1px 5px rgba(0, 0, 0, 0.5); /* Subtle shadow for breadcrumbs */
        }

        /* Page Title Section */
        .navbar-title {
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.7), 0 0 5px #ffd700; /* More pronounced glow for title */
        }

        /* Tab Navigation Wrapper (the container for the pills) */
        .nav-wrapper {
            background: rgba(15, 23, 42, 0.7); /* Dark semi-transparent background */
            backdrop-filter: blur(5px); /* Frosted glass effect */
            border-radius: 1rem; /* Rounded corners */
            padding: 0.75rem; /* Padding inside the wrapper */
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4), 0 0 0 2px rgba(255, 215, 0, 0.1); /* Subtle glow effect */
            display: flex; /* Use flexbox for centering */
            justify-content: center; /* Center the pills */
            margin-bottom: 2rem; /* Space below the nav wrapper */
        }

        .nav-pills .nav-item {
            margin: 0 0.5rem; /* Space between individual pills */
        }

        /* Default Tab Link Style */
        .nav-pills .nav-link {
            color: #e2e8f0; /* Light text for inactive tabs */
            font-weight: 500;
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem; /* More rounded pill shape */
            transition: all 0.3s ease; /* Smooth transitions for all states */
            background: transparent; /* Default transparent background */
            border: 1px solid rgba(255, 255, 255, 0.1); /* Subtle border */
            position: relative;
            overflow: hidden; /* For shimmer effect */
        }

        /* Tab Link Hover State */
        .nav-pills .nav-link:hover {
            color: #fff;
            background: rgba(30, 41, 59, 0.5); /* Slightly darker on hover */
            border-color: rgba(255, 215, 0, 0.3); /* Yellowish hover border */
            transform: translateY(-2px); /* Slight lift effect on hover */
        }

        /* Active Tab Link Style */
        .nav-pills .nav-link.active {
            color: #0f172a !important; /* Dark text for active tab */
            background: linear-gradient(45deg, #ffd700, #ffdb58) !important; /* Gold gradient for active */
            box-shadow: 0 5px 15px rgba(255, 215, 0, 0.4), 0 0 10px rgba(255, 215, 0, 0.6) !important; /* Stronger glow */
            border-color: #ffd700 !important; /* Solid gold border */
            font-weight: 700;
            transform: scale(1.02); /* Slightly larger */
            z-index: 1; /* Bring active tab to front */
        }

        /* Optional: Subtle shimmering effect on active tab */
        .nav-pills .nav-link.active::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: all 0.8s ease;
        }
        .nav-pills .nav-link.active:hover::before {
            left: 100%; /* Shimmer slides across */
        }

        /* Tab Content Area */
        .tab-content {
            background-color: #1a202c; /* Dark background for tab content */
            border-radius: 0.75rem;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3); /* Subtle shadow for depth */
        }

        /* Adjustments for elements within tabs (like forms, tables, cards) */
        .form-select, .form-control {
            background-color: #2d3748 !important; /* Darker input fields */
            color: #e2e8f0 !important;
            border: 1px solid #4a5568 !important;
        }
        .form-select:focus, .form-control:focus {
            border-color: #ffd700 !important; /* Gold focus border */
            box-shadow: 0 0 0 0.25rem rgba(255, 215, 0, 0.25) !important; /* Gold glow on focus */
        }
        .card {
            background-color: #2d3748 !important;
            border: 1px solid #4a5568 !important;
            color: #e2e8f0 !important;
        }
        .card-header, .table-secondary {
            background-color: #1a202c !important; /* Darker header for cards/tables */
            color: #e2e8f0 !important;
        }
        .table-striped > tbody > tr:nth-of-type(odd) > * {
            background-color: #2a3340 !important; /* Darker stripes for tables */
        }
        .table {
            color: #e2e8f0 !important; /* Light text for table content */
        }
        .table thead th {
            border-bottom: 1px solid #4a5568 !important; /* Darker header border */
        }
        .table tbody tr {
            border-bottom: 1px solid #3b4556 !important; /* Darker row separator */
        }

        /* Button Styling to Fit Theme */
        .btn-primary {
            background-color: #ffd700 !important; /* Gold primary button */
            border-color: #ffd700 !important;
            color: #0f172a !important; /* Dark text on gold */
        }
        .btn-primary:hover {
            background-color: #ffdb58 !important;
            border-color: #ffdb58 !important;
            box-shadow: 0 4px 10px rgba(255, 215, 0, 0.3);
        }
        .btn-success { /* Green for success actions like 'Load Location Table' */
            background-color: #28a745 !important;
            border-color: #28a745 !important;
            color: #fff !important;
        }
        .btn-success:hover {
            background-color: #218838 !important;
            border-color: #1e7e34 !important;
        }
        .btn-danger { /* Red for error/delete actions */
            background-color: #dc3545 !important;
            border-color: #dc3545 !important;
            color: #fff !important;
        }
        .btn-danger:hover {
            background-color: #c82333 !important;
            border-color: #bd2130 !important;
        }
        .btn-outline-secondary { /* For disabled/secondary buttons */
            border-color: #6c757d !important;
            color: #6c757d !important;
        }

        /* --- FUTURISTIC DESIGN STYLES END HERE --- */
    </style>
</head>
<body class="g-sidenav-show bg-gray-100">
    <?php include 'components/aside.php'; ?>

    <div class="main-content position-relative border-radius-lg">
     

        <div class="d-flex align-items-center flex-grow-1 py-3 px-2" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);">
            <nav aria-label="breadcrumb" class="flex-grow-1">
                <ol class="breadcrumb mb-1 navbar-breadcrumb" style="background: transparent;">
                    <li class="breadcrumb-item">
                        <a href="main" class="breadcrumb-link" style="color: #ffd700; font-weight: 600;">
                            <i class="fas fa-home me-1" style="color: #ffd700;"></i>Home
                        </a>
                    </li>
                    <li class="breadcrumb-item active navbar-breadcrumb-active" aria-current="page" style="color: #fff; font-weight: 700;">
                        <?= htmlspecialchars($pageTitle ?? 'Settings') ?>
                    </li>
                </ol>
                <h4 class="navbar-title mb-0 mt-1" style="color: #fff; text-shadow: 0 1px 8px #1e3c72, 0 0 2px #ffd700; font-weight: 700;">
                    <?= htmlspecialchars($pageTitle ?? 'Settings') ?>
                </h4>
            </nav>
        </div>

        <div class="container-fluid py-4">
            <div class="nav-wrapper">
                <ul class="nav nav-pills nav-fill flex-column flex-md-row" id="tabs-text" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link mb-sm-3 mb-md-0 <?= ($activeTab == 'view') ? 'active' : '' ?>" href="?tab=view">Org-Unit Viewer</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link mb-sm-3 mb-md-0 <?= ($activeTab == 'load') ? 'active' : '' ?>" href="?tab=load">Org-Unit Importer</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link mb-sm-3 mb-md-0 <?= ($activeTab == 'new') ? 'active' : '' ?>" href="?tab=new">DHIS2-Programs-Fetcher</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link mb-sm-3 mb-md-0 <?= ($activeTab == 'questions') ? 'active' : '' ?>" href="?tab=questions">Mapping-Interface</a>
                    </li>
                </ul>
            </div>

            <?php if (!empty($message)) : ?>
                <div class="alert alert-<?= $message['type'] == 'success' ? 'success' : 'danger' ?> mt-4">
                    <?= $message['text'] ?>
                </div>
            <?php endif; ?>

            <div class="tab-content mt-3">
                <?php
                // Only include the active tab's content for better performance and clarity
                switch ($activeTab) {
                    case 'view':
                        include 'dhis2/view.php';
                        break;
                    case 'load':
                        include 'dhis2/load.php';
                        break;
                    case 'new':
                        include 'dhis2/new.php';
                        break;
                    case 'questions':
                        include 'dhis2/questions.php';
                        break;
                    default:
                        // Optional: show a "not found" or default message
                        echo '<div class="alert alert-warning">Tab not found.</div>';
                        break;
                }
                ?>
            </div>
        </div>
    </div>

 

    <!-- Core JS Files -->
    <script src="argon-dashboard-master/assets/js/core/popper.min.js"></script>
    <script src="argon-dashboard-master/assets/js/core/bootstrap.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/sweetalert2.all.min.js"></script>
    <script src="argon-dashboard-master/assets/js/argon-dashboard.js"></script>
    <script>
    // Place any global JS here, or keep per-tab scripts inside their respective PHP includes!
    </script>
</body>
</html>