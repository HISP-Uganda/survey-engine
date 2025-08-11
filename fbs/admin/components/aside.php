<?php
require_once __DIR__ . '/../includes/profile_helper.php';
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
            <img src="argon-dashboard-master/assets/img/webhook-icon.png"
             class="navbar-brand-img"
             alt="Survey Engine Logo"
             style="max-height: 4rem; width: auto;">
            <span class="ms-1 font-weight-bold d-block mt-2 text-dark" style="font-size: 1rem;">Survey Engine</span>
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
                        <?php 
                        $profileImagePath = isset($pdo) && isset($_SESSION['admin_id']) ? 
                            getUserProfileImage($_SESSION['admin_id'], $pdo) : 
                            "argon-dashboard-master/assets/img/ship.jpg";
                        ?>
                        <img src="<?php echo htmlspecialchars($profileImagePath); ?>"
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
    /* Neutral Custom Sidenav Background */
    .custom-sidenav-bg {
        background: #ffffff !important;
        color: #2d3748;
        position: relative;
        overflow: hidden;
        border-right: 1px solid #e2e8f0;
    }
    
    /* Remove pattern overlay for neutral design */
    .custom-sidenav-bg::before {
        display: none;
    }

    .custom-sidenav-bg .navbar-brand .font-weight-bold,
    .custom-sidenav-bg .nav-link-text {
        color: #2d3748 !important;
    }

    .custom-sidenav-bg .nav-link {
        color: #4a5568 !important;
        transition: none;
    }

    .custom-sidenav-bg .nav-link:hover {
        background-color: #f8f9fa !important;
        color: #2d3748 !important;
    }

    /* Active Link Styling */
    .custom-sidenav-bg .nav-link.active {
        background: #e2e8f0 !important;
        color: #2d3748 !important;
        font-weight: bold;
        box-shadow: none;
    }

    /* Neutral Icon Styling within Sidenav */
    .custom-sidenav-bg .icon-shape {
        background: #f8f9fa !important;
        color: #4a5568 !important;
        border-radius: 8px !important;
        transition: none;
    }
    
    .custom-sidenav-bg .nav-link:hover .icon-shape {
        background: #e2e8f0 !important;
        transform: none;
        box-shadow: none;
    }

    /* Specific icon colors for active state */
    .custom-sidenav-bg .nav-link.active .icon-shape i {
        color: #2d3748 !important;
    }

    /* Neutral User Profile Section Styling */
    .user-profile-section {
        padding: 1.25rem 1rem;
        background: #f8f9fa;
        margin: 1rem 0.75rem 0.75rem;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
    }

    .user-profile-section .avatar-img {
        border: 2px solid #e2e8f0;
    }

    .user-profile-section .fw-bold {
        color: #2d3748;
        font-size: 0.875rem;
    }

    .user-profile-section .text-muted {
        color: #718096 !important;
        font-size: 0.75rem;
    }

    .user-profile-section .btn-outline-primary {
        border-color: #e2e8f0 !important;
        color: #4a5568 !important;
        transition: none;
        border-radius: 6px;
        font-weight: 500;
        font-size: 0.8rem;
        padding: 0.4rem 0.8rem;
    }

    .user-profile-section .btn-outline-primary:hover {
        background-color: #e2e8f0 !important;
        border-color: #cbd5e0 !important;
        color: #2d3748 !important;
        transform: none;
        box-shadow: none;
    }

    /* Horizontal Rule */
    .horizontal.light {
        background-color: #e2e8f0;
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
        transition: all 0.3s ease;
        box-shadow: 2px 0 8px rgba(0, 0, 0, 0.1);
        border-right: 1px solid #e2e8f0;
    }
    
    .sidebar-close-btn {
        background: #f8f9fa;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        transition: none;
        width: 28px;
        height: 28px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #4a5568;
    }
    
    .sidebar-close-btn:hover {
        background: #e2e8f0;
        transform: none;
        color: #2d3748;
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

    /* Neutral Nav item styling */
    .nav-item {
        margin-bottom: 0.4rem;
    }

    .nav-link {
        border-radius: 6px;
        padding: 0.75rem 1rem;
        margin: 0 0.75rem;
        display: flex;
        align-items: center;
        position: relative;
        overflow: hidden;
    }
    
    /* Remove hover animation */
    .nav-link::before {
        display: none;
    }
    
    .nav-link:hover::before {
        left: 0;
    }

    .icon-shape {
        width: 28px;
        height: 28px;
        flex-shrink: 0;
    }

    /* Scroller for long menus */
    .nav-scroller {
        height: calc(100vh - 110px);
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        padding-bottom: 1rem;
    }

    /* Scrollbar styles for better aesthetics */
    .nav-scroller::-webkit-scrollbar {
        width: 4px;
    }

    .nav-scroller::-webkit-scrollbar-track {
        background: #f8f9fa;
        border-radius: 4px;
    }

    .nav-scroller::-webkit-scrollbar-thumb {
        background-color: #e2e8f0;
        border-radius: 4px;
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