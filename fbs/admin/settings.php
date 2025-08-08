<?php
session_start();

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}
require_once 'includes/session_timeout.php';
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
    <title>Admin - Settings</title>
    <link rel="icon" type="image/png" href="argon-dashboard-master/assets/img/istock3.png">
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

        /* Overall Lighter Background */
        body.bg-gray-100 {
            background-color: #f8fafc !important; /* Light gray/white background */
        }

        /* Main Content Area */
        .main-content {
            background-color: #f8fafc; /* Light background for content area */
        }
        .container-fluid.py-4 {
            background-color: #ffffff; /* White content background */
            border-radius: 1rem;
            padding: 2rem !important; /* More padding */
            box-shadow: 0 4px 25px rgba(0, 0, 0, 0.08); /* Subtle outer shadow */
        }

        /* Text Colors for Light Background */
        .container-fluid h4, .container-fluid h5, .container-fluid h6,
        .container-fluid p, .container-fluid label, .container-fluid strong, .container-fluid small {
            color: #1e293b; /* Dark text for readability on light background */
        }
        .text-muted {
            color: #64748b !important; /* Muted text for light theme */
        }
        .breadcrumb-link, .breadcrumb-item.active {
            text-shadow: none; /* Remove shadow for light theme */
        }

        /* Page Title Section */
        .navbar-title {
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.7), 0 0 5px #ffd700; /* More pronounced glow for title */
        }

        /* Vertical Tab Navigation Wrapper - Stable Version */
        .nav-wrapper {
            background: #ffffff;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
            border: 1px solid #e2e8f0;
            position: relative;
        }

        .nav-pills {
            display: flex !important;
            flex-direction: column !important;
            gap: 0.5rem;
        }

        .nav-pills .nav-item {
            margin: 0;
            width: 100%;
        }

        /* Default Tab Link Style - Stable Version */
        .nav-pills .nav-link {
            color: #64748b;
            font-weight: 500;
            padding: 1rem 1.5rem;
            border-radius: 0.75rem;
            transition: all 0.2s ease-in-out;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            text-decoration: none;
            display: flex;
            align-items: center;
            width: 100%;
            margin: 0;
        }

        /* Tab Link Hover State - Stable */
        .nav-pills .nav-link:hover {
            color: #1e293b;
            background: #f1f5f9;
            border-color: #cbd5e1;
            text-decoration: none;
        }

        /* Active Tab Link Style - Stable */
        .nav-pills .nav-link.active {
            color: #ffffff !important;
            background: linear-gradient(135deg, #3b82f6, #1e40af) !important;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3) !important;
            border-color: #3b82f6 !important;
            font-weight: 700;
            text-decoration: none;
        }

        /* Remove any conflicting pseudo-elements */
        .nav-pills .nav-link::before,
        .nav-pills .nav-link::after {
            display: none;
        }

        /* Ensure stable positioning and prevent layout shifts */
        .nav-pills .nav-link {
            transform: none !important;
            position: static !important;
            overflow: visible !important;
        }
        
        .nav-pills .nav-link:hover,
        .nav-pills .nav-link.active {
            transform: none !important;
        }
        
        /* Override Bootstrap nav-pills defaults that might cause instability */
        .nav-pills .nav-link:not(.active) {
            background-color: #f8fafc !important;
        }
        
        /* Ensure consistent height for all nav items */
        .nav-pills .nav-item {
            min-height: auto;
        }
        
        /* Fix flexbox alignment issues */
        .nav-pills .nav-link i {
            margin-right: 0.5rem;
            width: 1rem;
            text-align: center;
            flex-shrink: 0;
        }

        /* Ensure HR elements are not clickable and don't inherit nav styling */
        .nav-wrapper hr {
            pointer-events: none !important;
            background: linear-gradient(to right, rgba(0, 0, 0, 0), rgba(0, 0, 0, 0.1), rgba(0, 0, 0, 0)) !important;
            border: none !important;
            height: 1px !important;
            margin: 1rem 0 !important;
            cursor: default !important;
            position: relative !important;
            z-index: -1 !important;
        }
        
        .nav-wrapper hr:hover {
            background: linear-gradient(to right, rgba(0, 0, 0, 0), rgba(0, 0, 0, 0.1), rgba(0, 0, 0, 0)) !important;
            border: none !important;
        }

        /* Prevent any pseudo-elements or empty spaces from being clickable */
        .nav-wrapper *:empty {
            pointer-events: none !important;
        }


        /* Tab Content Area */
        .tab-content {
            background-color: #ffffff; /* White background for tab content */
            border-radius: 0.75rem;
            padding: 2rem;
            box-shadow: 0 4px 25px rgba(0, 0, 0, 0.08); /* Light shadow for depth */
            border: 1px solid #e2e8f0; /* Light border */
        }

        /* Adjustments for elements within tabs (like forms, tables, cards) */
        .form-select, .form-control {
            background-color: #ffffff !important; /* White input fields */
            color: #1e293b !important;
            border: 1px solid #cbd5e1 !important;
        }
        .form-select:focus, .form-control:focus {
            border-color: #3b82f6 !important; /* Blue focus border */
            box-shadow: 0 0 0 0.25rem rgba(59, 130, 246, 0.25) !important; /* Blue glow on focus */
        }
        .card {
            background-color: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            color: #1e293b !important;
        }
        .card-header, .table-secondary {
            background-color: #f8fafc !important; /* Light header for cards/tables */
            color: #1e293b !important;
        }
        .table-striped > tbody > tr:nth-of-type(odd) > * {
            background-color: #f8fafc !important; /* Light stripes for tables */
        }
        .table {
            color: #1e293b !important; /* Dark text for table content */
        }
        .table thead th {
            border-bottom: 1px solid #cbd5e1 !important; /* Light header border */
        }
        .table tbody tr {
            border-bottom: 1px solid #e2e8f0 !important; /* Light row separator */
        }

        /* Button Styling to Fit Theme */
        .btn-primary {
            background-color: #3b82f6 !important; /* Blue primary button */
            border-color: #3b82f6 !important;
            color: #ffffff !important; /* White text on blue */
        }
        .btn-primary:hover {
            background-color: #2563eb !important;
            border-color: #2563eb !important;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
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

          /* Reusable CSS classes for the light theme */
    .header-container-light {
        background: linear-gradient(135deg, #ffffff 0%, #f1f5f9 100%);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        margin-bottom: 1.5rem;
    }
    .breadcrumb-link-light {
        color: #475569 !important;
        font-weight: 600;
        text-decoration: none;
        transition: color 0.3s ease;
    }
    .breadcrumb-link-light:hover {
        color: #1e293b !important;
    }
    .breadcrumb-item-active-light {
        color: #1e293b !important;
        font-weight: 700;
    }
    .navbar-title-light {
        color: #1e293b;
        text-shadow: none;
    }
        /* --- FUTURISTIC DESIGN STYLES END HERE --- */
    </style>
</head>
<body class="g-sidenav-show bg-gray-100">
    <?php include 'components/aside.php'; ?>

    <div class="main-content position-relative border-radius-lg">
         <?php include 'components/navbar.php'; ?>
      
        <div class="container-fluid py-4">
            
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item">
                            <a class="breadcrumb-link-light" href="main.php">Dashboard</a>     
                        </li>
                        <li class="breadcrumb-item active breadcrumb-item-active-light" aria-current="page">
                            Settings Panel
                        </li>
                    </ol>
                </nav>

            <div class="row">
                <div class="col-md-3">
                    <div class="nav-wrapper">
                        <ul class="nav nav-pills flex-column" id="tabs-text" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link mb-sm-3 mb-md-0 <?= ($activeTab == 'view') ? 'active' : '' ?>" href="?tab=view">
                            <i class="fas fa-sitemap me-2"></i>Org-Unit Viewer
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link mb-sm-3 mb-md-0 <?= ($activeTab == 'load') ? 'active' : '' ?>" href="?tab=load">
                            <i class="fas fa-download me-2"></i>Org-Unit Importer
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link mb-sm-3 mb-md-0 <?= ($activeTab == 'new') ? 'active' : '' ?>" href="?tab=new">
                            <i class="fas fa-search me-2"></i>DHIS2-Programs-Fetcher
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link mb-sm-3 mb-md-0 <?= ($activeTab == 'questions') ? 'active' : '' ?>" href="?tab=questions">
                            <i class="fas fa-link me-2"></i>Mapping-Interface
                        </a>
                    </li>
                        </ul>
                        
                        <hr class="horizontal light my-3">
                        
                        <ul class="nav nav-pills flex-column" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link mb-sm-3 mb-md-0 <?= ($activeTab == 'profile') ? 'active' : '' ?>" href="?tab=profile">
                            <i class="fas fa-user-circle me-2"></i>Profile Settings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link mb-sm-3 mb-md-0 <?= ($activeTab == 'users') ? 'active' : '' ?>" href="?tab=users">
                            <i class="fas fa-users-cog me-2"></i>User Management
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link mb-sm-3 mb-md-0 <?= ($activeTab == 'config') ? 'active' : '' ?>" href="?tab=config">
                            <i class="fas fa-cogs me-2"></i>DHIS2 Configuration
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link mb-sm-3 mb-md-0 <?= ($activeTab == 'payload_checker') ? 'active' : '' ?>" href="?tab=payload_checker">
                            <i class="fas fa-bug me-2"></i>Payload Checker
                        </a>
                    </li>
                        </ul>
                    </div>
                </div>
                <div class="col-md-9">

            <?php if (!empty($message)) : ?>
                <div class="alert alert-<?= $message['type'] == 'success' ? 'success' : 'danger' ?> mt-4">
                    <?= $message['text'] ?>
                </div>
            <?php endif; ?>

                    <div class="tab-content">
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
                    case 'profile':
                        include 'settings/profile_tab.php';
                        break;
                    case 'users':
                        include 'settings/users_tab.php';
                        break;
                    case 'config':
                        include 'settings/config_tab.php';
                        break;
                    case 'payload_checker':
                        include 'settings/payload_checker_tab.php';
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
        </div>
    </div>

 

    <!-- Core JS Files -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/sweetalert2.all.min.js"></script>
    <script>
    // Place any global JS here, or keep per-tab scripts inside their respective PHP includes!
    
    // Ensure clean navigation behavior
    document.addEventListener('DOMContentLoaded', function() {
        // Make HR elements completely non-interactive
        const hrElements = document.querySelectorAll('.nav-wrapper hr');
        hrElements.forEach(function(hr) {
            hr.style.pointerEvents = 'none';
            hr.style.cursor = 'default';
            hr.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            });
        });
    });
    </script>
</body>
</html>