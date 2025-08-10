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

// Restrict access to config tab for super users and admins only
if ($activeTab == 'config' && (!isset($_SESSION['admin_role_id']) || !in_array($_SESSION['admin_role_id'], [1, 2]))) {
    $activeTab = 'view'; // Redirect to default tab
    $message = ['type' => 'error', 'text' => 'Access denied. DHIS2 Configuration is only accessible to Super Administrators and Administrators.'];
}

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
    <link href="dhis2/settings.css" rel="stylesheet">
    <style>
        #settings-body .brand-img {
            display: none;
        }

        /* Base Neutral Color Scheme */
        :root {
            --neutral-50: #f9fafb;
            --neutral-100: #f3f4f6;
            --neutral-200: #e5e7eb;
            --neutral-300: #d1d5db;
            --neutral-400: #9ca3af;
            --neutral-500: #6b7280;
            --neutral-600: #4b5563;
            --neutral-700: #374151;
            --neutral-800: #1f2937;
            --neutral-900: #111827;
            --primary-600: #2563eb;
            --primary-700: #1d4ed8;
            --success-600: #16a34a;
            --danger-600: #dc2626;
        }

        /* Overall Clean Background */
        body.bg-gray-100 {
            background-color: var(--neutral-50) !important;
        }

        /* Main Content Area - Reduced Padding */
        .main-content {
            background-color: var(--neutral-50);
        }
        
        .container-fluid.py-4 {
            background-color: #ffffff;
            border-radius: 0.5rem;
            padding: 1.5rem !important;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
            margin-bottom: 1rem;
        }

        /* Typography - Consistent with Aside */
        .container-fluid h1, .container-fluid h2, .container-fluid h3 {
            font-size: 1.25rem !important;
            font-weight: 600 !important;
            color: var(--neutral-800) !important;
            margin-bottom: 0.75rem !important;
        }
        
        .container-fluid h4, .container-fluid h5, .container-fluid h6 {
            font-size: 1.125rem !important;
            font-weight: 500 !important;
            color: var(--neutral-700) !important;
            margin-bottom: 0.5rem !important;
        }
        
        .container-fluid p, .container-fluid label, .container-fluid span {
            font-size: 0.875rem !important;
            color: var(--neutral-600) !important;
            line-height: 1.4 !important;
            margin-bottom: 0.5rem !important;
        }
        
        .container-fluid small {
            font-size: 0.75rem !important;
            color: var(--neutral-500) !important;
        }
        
        .text-muted {
            color: var(--neutral-500) !important;
        }

        /* Breadcrumb - Cleaner */
        .breadcrumb {
            background-color: transparent;
            padding: 0;
            margin-bottom: 1rem;
        }
        
        .breadcrumb-link-light {
            color: var(--neutral-500) !important;
            font-size: 0.875rem !important;
            font-weight: 400 !important;
            text-decoration: none;
        }
        
        .breadcrumb-link-light:hover {
            color: var(--neutral-700) !important;
        }
        
        .breadcrumb-item-active-light {
            color: var(--neutral-800) !important;
            font-size: 0.875rem !important;
            font-weight: 500 !important;
        }

        /* Vertical Tab Navigation - Compact and Neutral */
        .nav-wrapper {
            background: #ffffff;
            border-radius: 0.5rem;
            padding: 1rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
            border: 1px solid var(--neutral-200);
        }

        .nav-pills {
            display: flex !important;
            flex-direction: column !important;
            gap: 0.25rem;
        }

        .nav-pills .nav-item {
            margin: 0;
            width: 100%;
        }

        /* Tab Links - Consistent with Aside Styling */
        .nav-pills .nav-link {
            color: var(--neutral-600);
            font-size: 0.875rem !important;
            font-weight: 400;
            padding: 0.625rem 0.875rem !important;
            border-radius: 0.375rem;
            transition: all 0.15s ease-in-out;
            background: transparent;
            border: none;
            text-decoration: none;
            display: flex;
            align-items: center;
            width: 100%;
            margin: 0;
        }

        .nav-pills .nav-link:hover {
            color: var(--neutral-800);
            background: var(--neutral-100);
            text-decoration: none;
        }

        .nav-pills .nav-link.active {
            color: #ffffff !important;
            background: var(--primary-600) !important;
            font-weight: 500 !important;
            text-decoration: none;
        }

        .nav-pills .nav-link i {
            margin-right: 0.5rem;
            width: 1rem;
            text-align: center;
            flex-shrink: 0;
            font-size: 0.875rem;
        }

        /* Dividers */
        .nav-wrapper hr {
            border: none;
            height: 1px;
            background-color: var(--neutral-200);
            margin: 0.75rem 0;
        }

        /* Tab Content Area - Reduced Padding */
        .tab-content {
            background-color: #ffffff;
            border-radius: 0.5rem;
            padding: 1.5rem !important;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--neutral-200);
        }

        /* Tab Headers - Consistent Sizing */
        .tab-header h3 {
            font-size: 1.25rem !important;
            font-weight: 600 !important;
            color: var(--neutral-800) !important;
            margin-bottom: 0.5rem !important;
        }
        
        .tab-header p {
            font-size: 0.875rem !important;
            color: var(--neutral-500) !important;
            margin-bottom: 1rem !important;
        }

        /* Form Controls */
        .form-select, .form-control, .form-control-sm {
            background-color: #ffffff !important;
            color: var(--neutral-700) !important;
            border: 1px solid var(--neutral-300) !important;
            font-size: 0.875rem !important;
            padding: 0.5rem 0.75rem !important;
            border-radius: 0.375rem !important;
        }
        
        .form-select:focus, .form-control:focus, .form-control-sm:focus {
            border-color: var(--primary-600) !important;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1) !important;
            outline: none !important;
        }

        /* Labels */
        .form-label, label {
            font-size: 0.875rem !important;
            font-weight: 500 !important;
            color: var(--neutral-700) !important;
            margin-bottom: 0.375rem !important;
        }

        /* Cards - Minimal Style */
        .card {
            background-color: #ffffff !important;
            border: 1px solid var(--neutral-200) !important;
            border-radius: 0.5rem !important;
            box-shadow: none !important;
            margin-bottom: 1rem !important;
        }
        
        .card-header {
            background-color: var(--neutral-50) !important;
            color: var(--neutral-800) !important;
            border-bottom: 1px solid var(--neutral-200) !important;
            padding: 1rem !important;
            font-size: 1rem !important;
            font-weight: 500 !important;
        }
        
        .card-body {
            padding: 1rem !important;
        }

        /* Tables */
        .table {
            color: var(--neutral-700) !important;
            font-size: 0.875rem !important;
        }
        
        .table thead th {
            background-color: var(--neutral-50) !important;
            color: var(--neutral-800) !important;
            font-size: 0.75rem !important;
            font-weight: 600 !important;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--neutral-300) !important;
            padding: 0.75rem !important;
        }
        
        .table tbody td {
            padding: 0.75rem !important;
            border-bottom: 1px solid var(--neutral-200) !important;
        }
        
        .table-striped > tbody > tr:nth-of-type(odd) > * {
            background-color: var(--neutral-50) !important;
        }

        /* Buttons - Neutral and Consistent */
        .btn {
            font-size: 0.875rem !important;
            font-weight: 500 !important;
            padding: 0.5rem 1rem !important;
            border-radius: 0.375rem !important;
            border: none !important;
            transition: all 0.15s ease-in-out;
        }
        
        .btn-sm {
            font-size: 0.75rem !important;
            padding: 0.375rem 0.75rem !important;
        }
        
        .btn-primary {
            background-color: var(--primary-600) !important;
            color: #ffffff !important;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-700) !important;
        }
        
        .btn-success {
            background-color: var(--success-600) !important;
            color: #ffffff !important;
        }
        
        .btn-danger {
            background-color: var(--danger-600) !important;
            color: #ffffff !important;
        }
        
        .btn-secondary, .btn-outline-secondary {
            background-color: var(--neutral-500) !important;
            color: #ffffff !important;
            border: 1px solid var(--neutral-500) !important;
        }

        /* Alert Messages */
        .alert {
            font-size: 0.875rem !important;
            padding: 0.75rem 1rem !important;
            border-radius: 0.375rem !important;
            border: 1px solid !important;
            margin-bottom: 1rem !important;
        }

        /* Status Badges */
        .status-badge, .badge {
            font-size: 0.75rem !important;
            font-weight: 500 !important;
            padding: 0.25rem 0.5rem !important;
            border-radius: 0.25rem !important;
        }

        /* Code Blocks - Payload Checker */
        .code-block {
            background-color: var(--neutral-100) !important;
            border: 1px solid var(--neutral-200) !important;
            color: var(--neutral-800) !important;
            font-size: 0.75rem !important;
            padding: 0.75rem !important;
            border-radius: 0.375rem !important;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace !important;
        }

        /* Remove Excessive Margins */
        .mb-4, .mb-3 {
            margin-bottom: 1rem !important;
        }
        
        .mt-4, .mt-3 {
            margin-top: 1rem !important;
        }
        
        .py-4, .py-3 {
            padding-top: 1rem !important;
            padding-bottom: 1rem !important;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100" id="settings-body">
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
                    <?php if (isset($_SESSION['admin_role_id']) && in_array($_SESSION['admin_role_id'], [1, 2])): ?>
                    <li class="nav-item">
                        <a class="nav-link mb-sm-3 mb-md-0 <?= ($activeTab == 'config') ? 'active' : '' ?>" href="?tab=config">
                            <i class="fas fa-cogs me-2"></i>DHIS2 Configuration
                        </a>
                    </li>
                    <?php endif; ?>
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
    window.addEventListener('load', function() {
        const logo = document.querySelector('.brand-img');
        if (logo) {
            logo.style.display = 'block';
        }
    });
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