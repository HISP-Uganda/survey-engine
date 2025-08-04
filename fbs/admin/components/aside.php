<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$menuItems = [
    [
        'title' => 'Dashboard',
        'icon' => 'fa-tachometer-alt', // Dashboard speedometer icon
        'link' => 'main.php',
        'color' => 'primary',
        'pages' => ['main.php']
    ],
    [
        'title' => 'Question Library',
        'icon' => 'fa-database', // Database icon for question bank
        'link' => 'question_bank.php',
        'color' => 'info',
        'pages' => ['question_bank.php', 'question_manager.php']
    ],
    [
        'title' => 'Survey Management',
        'icon' => 'fa-clipboard-list', // Clipboard with list for surveys
        'link' => 'survey.php',
        'color' => 'success',
        'pages' => ['survey.php', 'sb.php', 'update_form.php', 'preview_form.php']
    ],
    [
        'title' => 'Analytics & Reports',
        'icon' => 'fa-chart-line', // Line chart for analytics
        'link' => 'records.php',
        'color' => 'warning',
        'pages' => ['records.php', 'view_record.php']
    ],
    [
        'title' => 'System Settings',
        'icon' => 'fa-cogs', // Gears icon for settings
        'link' => 'settings.php',
        'color' => 'secondary',
        'pages' => ['settings.php']
    ]
]
;
?>

<aside class="sidenav navbar navbar-vertical navbar-expand-xs border-0 fixed-start custom-sidenav-bg" id="sidenav-main">
    <div class="sidenav-header">
        <button class="sidebar-close-btn p-2 cursor-pointer text-white position-absolute end-0 top-0 d-lg-none"
                id="iconSidenav" aria-label="Close sidebar">
            <i class="fas fa-times"></i>
        </button>

        <a class="navbar-brand m-0 text-center w-100" href="#">
            <img src="argon-dashboard-master/assets/img/istock3.png"
             class="navbar-brand-img"
             alt="logo"
             style="max-height: 6rem; border-radius: 2rem; box-shadow: 0 4px 16px rgba(0,0,0,0.15);">
            <span class="ms-1 font-weight-bold fs-5 d-block mt-2 text-dark">Survey Engine</span>
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
                            <span class="nav-link-text ms-1 text-dark"><?= $item['title'] ?></span>
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
                        <span class="fw-bold text-dark"><?= $_SESSION['admin_username'] ?? 'Admin' ?></span>
                        <small class="text-muted">Administrator</small>
                    </div>
                </div>
                <div class="mt-2 d-grid">
                    <a href="logout.php" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-sign-out-alt me-1"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
</aside>

<div class="sidenav-backdrop"></div>

<style>
    /* Enhanced Custom Sidenav Background - Light Theme */
    .custom-sidenav-bg {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 50%, #f1f5f9 100%) !important;
        color: #1e293b;
        position: relative;
        overflow: hidden;
        border-right: 1px solid #e2e8f0;
    }
    
    /* Add subtle pattern overlay */
    .custom-sidenav-bg::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: radial-gradient(circle at 20% 50%, rgba(59, 130, 246, 0.03) 0%, transparent 50%),
                    radial-gradient(circle at 80% 20%, rgba(0, 0, 0, 0.02) 0%, transparent 50%);
        pointer-events: none;
    }

    .custom-sidenav-bg .navbar-brand .font-weight-bold,
    .custom-sidenav-bg .nav-link-text {
        color: #1e293b !important; /* Dark text for light theme */
    }

    .custom-sidenav-bg .nav-link {
        color: rgba(30, 41, 59, 0.85) !important; /* Dark color for inactive links */
        transition: background-color 0.3s ease, color 0.3s ease;
    }

    .custom-sidenav-bg .nav-link:hover {
        background-color: rgba(59, 130, 246, 0.1) !important; /* Blue hover effect */
        color: #1e293b !important;
    }

    /* Active Link Styling */
    .custom-sidenav-bg .nav-link.active {
        background: rgba(59, 130, 246, 0.15) !important; /* Blue active background */
        color: #1e293b !important;
        font-weight: bold;
        box-shadow: 0 2px 10px rgba(59, 130, 246, 0.2); /* Blue shadow for active state */
    }

    /* Enhanced Icon Styling within Sidenav */
    .custom-sidenav-bg .icon-shape {
        background: rgba(59, 130, 246, 0.1) !important;
        color: #3b82f6 !important;
        border-radius: 10px !important;
        transition: all 0.3s ease;
        backdrop-filter: blur(10px);
    }
    
    .custom-sidenav-bg .nav-link:hover .icon-shape {
        background: rgba(59, 130, 246, 0.2) !important;
        transform: translateY(-2px) scale(1.05);
        box-shadow: 0 4px 15px rgba(59, 130, 246, 0.2);
    }

    /* Specific icon colors for active state */
    .custom-sidenav-bg .nav-link.active .icon-shape i {
        color: #3b82f6 !important; /* Active icons are blue */
    }

    /* Enhanced User Profile Section Styling */
    .user-profile-section {
        padding: 1.5rem 1rem;
        background: rgba(59, 130, 246, 0.05);
        margin: 1rem 0.75rem 0.75rem;
        border-radius: 16px;
        border: 1px solid rgba(59, 130, 246, 0.1);
        backdrop-filter: blur(10px);
    }

    .user-profile-section .avatar-img {
        border: 2px solid rgba(59, 130, 246, 0.3); /* Blue border for user avatar */
    }

    .user-profile-section .fw-bold {
        color: #1e293b;
    }

    .user-profile-section .text-muted {
        color: rgba(30, 41, 59, 0.7) !important; /* Darker grey for sub-text */
    }

    .user-profile-section .btn-outline-primary {
        border-color: rgba(59, 130, 246, 0.3) !important;
        color: #3b82f6 !important;
        transition: all 0.3s ease;
        border-radius: 8px;
        font-weight: 500;
    }

    .user-profile-section .btn-outline-primary:hover {
        background-color: rgba(59, 130, 246, 0.1) !important;
        border-color: rgba(59, 130, 246, 0.5) !important;
        color: #1e40af !important;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);
    }

    /* Horizontal Rule */
    .horizontal.light {
        background-image: linear-gradient(to right, rgba(0, 0, 0, 0), rgba(0, 0, 0, 0.1), rgba(0, 0, 0, 0));
        height: 1px;
    }

    /* Enhanced Sidenav Structure and Responsiveness */
    .sidenav {
        width: 260px;
        min-height: 100vh;
        position: fixed;
        left: 0;
        top: 0;
        z-index: 1030;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 4px 0 20px rgba(0, 0, 0, 0.15);
        border-right: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .sidebar-close-btn {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 6px;
        transition: all 0.2s ease;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .sidebar-close-btn:hover {
        background: rgba(255, 255, 255, 0.2);
        transform: scale(1.05);
    }

    .main-content {
        margin-left: 260px;
        transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
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

    /* Enhanced Mobile/Tablet behavior */
    @media (max-width: 1199.98px) {
        .sidenav {
            transform: translateX(-100%);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .sidenav.show {
            transform: translateX(0);
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4);
        }

        .sidenav-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1029;
            transition: opacity 0.3s ease, visibility 0.3s ease;
            opacity: 0;
            visibility: hidden;
            backdrop-filter: blur(4px);
        }

        .sidenav.show + .sidenav-backdrop {
            display: block;
            opacity: 1;
            visibility: visible;
        }

        .main-content {
            margin-left: 0 !important;
        }
    }

    /* Enhanced Nav item styling */
    .nav-item {
        margin-bottom: 0.5rem;
    }

    .nav-link {
        border-radius: 12px;
        padding: 0.875rem 1.25rem;
        margin: 0 0.75rem;
        display: flex;
        align-items: center;
        position: relative;
        overflow: hidden;
    }
    
    /* Add subtle hover animation */
    .nav-link::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
        transition: left 0.5s;
    }
    
    .nav-link:hover::before {
        left: 100%;
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

        // Enhanced sidebar toggle with better mobile/desktop handling
        function toggleSidebar() {
            if (window.innerWidth < 1200) {
                // Mobile behavior: show/hide with backdrop
                sidenav.classList.toggle('show');
                updateNavbarPosition();
            } else {
                // Desktop behavior: collapsed/expanded
                sidenav.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidenav.classList.contains('collapsed'));
                updateNavbarPosition();
            }
        }

        // Update navbar position based on sidebar state
        function updateNavbarPosition() {
            const navbar = document.getElementById('navbarBlur');
            if (navbar) {
                const event = new CustomEvent('sidebarToggle', {
                    detail: {
                        isCollapsed: sidenav.classList.contains('collapsed'),
                        isVisible: sidenav.classList.contains('show') || window.innerWidth >= 1200
                    }
                });
                window.dispatchEvent(event);
            }
        }

        // Close sidebar when clicking backdrop (mobile only)
        if (backdrop) {
            backdrop.addEventListener('click', function() {
                sidenav.classList.remove('show');
                updateNavbarPosition();
            });
        }

        // Toggle sidebar when clicking close button
        if (iconSidenav) {
            iconSidenav.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggleSidebar();
            });
        }

        // Initialize sidebar state
        function initializeSidebarState() {
            sidenav.classList.remove('show', 'collapsed');
            
            if (window.innerWidth < 1200) {
                // Mobile: hidden by default
                sidenav.classList.remove('show');
            } else {
                // Desktop: check saved state
                const savedState = localStorage.getItem('sidebarCollapsed');
                if (savedState === 'true') {
                    sidenav.classList.add('collapsed');
                }
            }
            updateNavbarPosition();
        }

        // Handle window resize
        let resizeTimeout;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(initializeSidebarState, 100);
        });

        // Handle clicks outside sidebar on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth < 1200 && 
                sidenav.classList.contains('show') && 
                !sidenav.contains(e.target) && 
                !e.target.closest('[data-sidebar-toggle]')) {
                sidenav.classList.remove('show');
                updateNavbarPosition();
            }
        });

        // Listen for navbar toggle events
        window.addEventListener('navbarToggle', function() {
            toggleSidebar();
        });

        // Initialize on page load
        initializeSidebarState();

        // Add smooth animations
        sidenav.style.transition = 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
    });
</script>