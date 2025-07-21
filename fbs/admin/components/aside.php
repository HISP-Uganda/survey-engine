<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$menuItems = [
    [
        'title' => 'Home Dashboard',
       'icon' => 'fa-file-lines',
          'link' => 'main.php',
        'color' => 'light', // Light stands out on dark blue
        'pages' => ['main.php']
    ],
    [
        'title' => 'Question-Bank',
        'icon' => 'fa-question-circle', // Question mark for question bank
        // 'icon' => 'fa-file-lines', // More form-like icon
        'link' => 'manage_form.php',
        'color' => 'success', // Green pops on blue
        'pages' => ['manage_form.php']
    ],
    [
        'title' => 'Analytics',
       'icon' => 'fa-chart-bar', // Clear analytics icon
        'link' => 'dashbard.php',
        'color' => 'warning', // Yellow/orange for analytics
        'pages' => ['dashbard.php']
    ],
    [
        'title' => 'Records',
           'icon' => 'fa-inbox', // Inbox for records
        'link' => 'records.php',
        'color' => 'info', // Cyan/teal for contrast
        'pages' => ['records.php']
    ],
    [
        'title' => 'Surveys',
       'icon' => 'fa-list-check', // Checklist for surveys
        'link' => 'survey.php',
        'color' => 'danger', // Red for attention
        'pages' => ['survey.php']
    ],
    [
    'title' => 'Settings',
    'icon' => 'fa-sliders-h',
    'link' => 'settings.php',
    'color' => 'light', // Light for visibility
    'pages' => ['settings.php']
    ]
    ]
;
?>

<aside class="sidenav navbar navbar-vertical navbar-expand-xs border-0 fixed-start custom-sidenav-bg" id="sidenav-main">
    <div class="sidenav-header">
        <i class="fas fa-times p-3 cursor-pointer text-white opacity-5 position-absolute end-0 top-0 d-none d-xl-none"
           id="iconSidenav" aria-label="Close sidebar"></i>

        <a class="navbar-brand m-0 text-center w-100" href="#">
            <img src="argon-dashboard-master/assets/img/istock.jpg"
             class="navbar-brand-img"
             alt="logo"
             style="max-height: 6rem; border-radius: 2rem; box-shadow: 0 4px 16px rgba(0,0,0,0.15);">
            <span class="ms-1 font-weight-bold fs-5 d-block mt-2 text-white">Admin Panel</span>
        </a>
    </div>

    <hr class="horizontal light mt-0 mb-1">

    <div class="collapse navbar-collapse w-auto h-100" id="sidenav-collapse-main">
        <div class="nav-scroller">
            <ul class="navbar-nav">
                <?php foreach ($menuItems as $item): ?>
                    <?php $isActive = in_array($currentPage, $item['pages']); ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $isActive ? 'active bg-gradient-'.$item['color'] : '' ?>" href="<?= $item['link'] ?>">
                            <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
                                <i class="fas <?= $item['icon'] ?> text-<?= $item['color'] ?> text-sm opacity-10"></i>
                            </div>
                            <span class="nav-link-text ms-1 text-white"><?= $item['title'] ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="mt-auto p-3 user-profile-section">
                <hr class="horizontal light mb-3">
                <div class="d-flex align-items-center">
                    <div class="avatar avatar-sm me-2">
                        <img src="argon-dashboard-master/assets/img/ship.jpg"
                             alt="User"
                             class="avatar-img rounded-circle border border-2 border-white">
                    </div>
                    <div class="d-flex flex-column">
                        <span class="fw-bold text-white"><?= $_SESSION['admin_username'] ?? 'Admin' ?></span>
                        <small class="text-white text-opacity-75">Administrator</small>
                    </div>
                </div>
                <div class="mt-2 d-grid">
                    <a href="../../../index.php" class="btn btn-sm btn-outline-light">
                        <i class="fas fa-sign-out-alt me-1"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
</aside>

<div class="sidenav-backdrop"></div>

<style>
    /* Custom Sidenav Background and Text Colors */
    .custom-sidenav-bg {
           background: linear-gradient(90deg, #020617 0%, #020617 100%)!important; /* Dark Blue Gradient */
        /* Alternative: More vibrant gradient */
        /* background: linear-gradient(135deg, #4CAF50 0%, #8BC34A 100%) !important; /* Green Gradient */
        /* background: linear-gradient(135deg, #FF5722 0%, #FF9800 100%) !important; /* Orange Gradient */
        color: #fff; /* Default text color for the sidebar */
    }

    .custom-sidenav-bg .navbar-brand .font-weight-bold,
    .custom-sidenav-bg .nav-link-text {
        color: #fff !important; /* Ensure main text is white */
    }

    .custom-sidenav-bg .nav-link {
        color: rgba(255, 255, 255, 0.85) !important; /* Slightly transparent white for inactive links */
        transition: background-color 0.3s ease, color 0.3s ease;
    }

    .custom-sidenav-bg .nav-link:hover {
        background-color: rgba(255, 255, 255, 0.1) !important; /* Light hover effect */
        color: #fff !important;
    }

    /* Active Link Styling */
    .custom-sidenav-bg .nav-link.active {
        background: rgba(255, 255, 255, 0.2) !important; /* More prominent active background */
        color: #fff !important;
        font-weight: bold;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2); /* Subtle shadow for active state */
    }

    /* Icon Styling within Sidenav */
    .custom-sidenav-bg .icon-shape {
        background: rgba(255, 255, 255, 0.15) !important; /* Background for icons */
        color: #fff !important; /* Icon color within the shape */
        border-radius: 0.5rem; /* Slightly more rounded corners for icons */
    }

    /* Specific icon colors for active state */
    .custom-sidenav-bg .nav-link.active .icon-shape i {
        color: #fff !important; /* Active icons are white */
    }

    /* User Profile Section Styling */
    .user-profile-section {
        padding-top: 1rem;
        padding-bottom: 1rem;
    }

    .user-profile-section .avatar-img {
        border: 2px solid rgba(255, 255, 255, 0.5); /* Lighter border for user avatar */
    }

    .user-profile-section .fw-bold {
        color: #fff;
    }

    .user-profile-section .text-muted {
        color: rgba(255, 255, 255, 0.7) !important; /* Lighter grey for sub-text */
    }

    .user-profile-section .btn-outline-danger {
        border-color: rgba(255, 255, 255, 0.5) !important;
        color: #fff !important;
        transition: background-color 0.3s ease, border-color 0.3s ease;
    }

    .user-profile-section .btn-outline-danger:hover {
        background-color: #dc3545 !important; /* Bootstrap red on hover */
        border-color: #dc3545 !important;
        color: #fff !important;
    }

    /* Horizontal Rule */
    .horizontal.light {
        background-image: linear-gradient(to right, rgba(255, 255, 255, 0), rgba(255, 255, 255, 0.4), rgba(255, 255, 255, 0));
        height: 1px;
    }

    /* Overall Sidenav Structure and Responsiveness */
    .sidenav {
        width: 250px;
        min-height: 100vh;
        position: fixed;
        left: 0;
        top: 0;
        z-index: 1030;
        transition: transform 0.3s ease, width 0.3s ease;
        box-shadow: 0 0 15px rgba(0, 0, 0, 0.2); /* Soft shadow for depth */
    }

    .main-content {
        margin-left: 250px;
        transition: margin-left 0.3s ease;
    }

    .sidenav.collapsed {
        width: 80px;
    }

    .sidenav.collapsed ~ .main-content {
        margin-left: 80px;
    }

    .sidenav.collapsed .nav-link-text,
    .sidenav.collapsed .navbar-brand span,
    .sidenav.collapsed .user-profile-section .fw-bold,
    .sidenav.collapsed .user-profile-section small,
    .sidenav.collapsed .user-profile-section .btn {
        display: none !important;
    }

    .sidenav.collapsed .user-profile-section .avatar {
        margin-right: 0 !important;
        margin-left: auto;
        margin-right: auto;
    }

    /* Mobile behavior */
    @media (max-width: 1199.98px) {
        .sidenav:not(.collapsed) {
            transform: translateX(0);
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.3);
        }

        .sidenav {
            transform: translateX(-100%);
        }

        .sidenav-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1029;
            transition: opacity 0.3s ease;
            opacity: 0;
        }

        .sidenav:not(.collapsed) + .sidenav-backdrop {
            display: block;
            opacity: 1;
        }

        .main-content {
            margin-left: 0 !important;
        }
    }

    /* Nav item styling */
    .nav-item {
        margin-bottom: 0.25rem;
    }

    .nav-link {
        border-radius: 0.375rem;
        padding: 0.75rem 1rem;
        margin: 0 0.5rem;
        display: flex;
        align-items: center;
    }

    .icon-shape {
        width: 32px;
        height: 32px;
        flex-shrink: 0; /* Prevent icon from shrinking */
    }

    /* Scroller for long menus */
    .nav-scroller {
        height: calc(100vh - 120px); /* Adjust height based on header/footer */
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        padding-bottom: 1rem; /* Add some padding at the bottom */
    }

    /* Scrollbar styles for better aesthetics */
    .nav-scroller::-webkit-scrollbar {
        width: 6px;
    }

    .nav-scroller::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.05); /* Lighter track */
        border-radius: 10px;
    }

    .nav-scroller::-webkit-scrollbar-thumb {
        background-color: rgba(255, 255, 255, 0.3); /* Lighter thumb */
        border-radius: 10px;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const sidenav = document.getElementById('sidenav-main');
        const iconSidenav = document.getElementById('iconSidenav');
        const backdrop = document.querySelector('.sidenav-backdrop');

        // Toggle sidebar on mobile
        iconSidenav.addEventListener('click', function() {
            sidenav.classList.toggle('collapsed');
            // If the sidebar is now collapsed, hide the backdrop, otherwise show it
            if (sidenav.classList.contains('collapsed')) {
                backdrop.style.opacity = '0';
                setTimeout(() => backdrop.style.display = 'none', 300); // Hide after transition
            } else {
                backdrop.style.display = 'block';
                setTimeout(() => backdrop.style.opacity = '1', 10); // Show with slight delay for transition
            }
            localStorage.setItem('sidebarCollapsed', sidenav.classList.contains('collapsed'));
        });

        // Close sidebar when clicking backdrop
        backdrop.addEventListener('click', function() {
            sidenav.classList.add('collapsed');
            backdrop.style.opacity = '0';
            setTimeout(() => backdrop.style.display = 'none', 300); // Hide after transition
            localStorage.setItem('sidebarCollapsed', true);
        });

        // Load saved state or set initial state for mobile
        function initializeSidebarState() {
            if (window.innerWidth < 1200) {
                // On mobile, sidebar is collapsed by default and only revealed by button
                sidenav.classList.add('collapsed');
                backdrop.style.display = 'none'; // Ensure backdrop is hidden
            } else {
                // On desktop, load saved state or default to open
                if (localStorage.getItem('sidebarCollapsed') === 'true') {
                    sidenav.classList.add('collapsed');
                } else {
                    sidenav.classList.remove('collapsed');
                }
                backdrop.style.display = 'none'; // Always hidden on desktop
            }
        }

        initializeSidebarState(); // Call on initial load

        // Re-evaluate sidebar state on window resize
        window.addEventListener('resize', initializeSidebarState);
    });
</script>