<?php
// Define page titles for different sections
$pages = [
    "main.php" => "Dashboard",
    "records.php" => "Survey Submissions",
    "submissions.php" => "Submissions",
    "manage_form.php" => "Form Builder",
    "survey.php" => "Survey Manager",
    "settings.php" => "Settings",
    "dashbard.php" => "Survey Dashboard"
];
$currentPage = basename($_SERVER['PHP_SELF']);
$pageTitle = $pages[$currentPage] ?? "Admin Panel";
?>

<nav class="navbar navbar-main navbar-expand-lg px-3 navbar-fixed-top shadow-lg" id="navbarBlur">
    <div class="container-fluid position-relative">
        <!-- Sidebar Toggle Buttons -->
        <button class="btn btn-nav-toggle px-0 me-2 d-lg-none" id="sidebarToggle">
            <i class="fas fa-bars fa-lg"></i>
        </button>
        <button class="btn btn-nav-toggle px-0 me-3 d-none d-lg-block" id="sidebarToggleDesktop">
            <i class="fas fa-bars fa-lg"></i>
        </button>

        <!-- Logo Section -->
        <div class="d-flex align-items-center flex-grow-1">
            <a href="main.php" class="navbar-brand d-flex align-items-center" style="color: #fff; font-weight: 700; font-size: 1.25rem; text-decoration: none;">
            <i class="fas fa-rocket me-2" style="color: #ffd700;"></i>
            FBS Admin
            </a>
        </div>

        <!-- Right-aligned navbar items -->
        <div class="ms-auto d-flex align-items-center navbar-actions">
            <!-- Quick Actions Dropdown -->
            <div class="position-relative me-3 d-none d-sm-block dropdown">
                <button class="btn btn-nav-action dropdown-toggle" type="button" id="quickActions" 
                        data-bs-toggle="dropdown" aria-expanded="false" data-bs-auto-close="outside">
                    <i class="fas fa-bolt me-2"></i>
                    <span class="d-none d-md-inline">Quick Actions</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end navbar-dropdown" aria-labelledby="quickActions">
                    <li class="dropdown-header">
                        <i class="fas fa-bolt text-warning me-2"></i>Quick Actions
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item navbar-dropdown-item" href="manage_form.php">
                            <i class="fas fa-plus-circle text-success me-3"></i>
                            <div>
                                <div class="fw-semibold">Create New Form</div>
                                <small class="text-muted">Build a new survey form</small>
                            </div>
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item navbar-dropdown-item" href="records.php">
                            <i class="fas fa-list-alt text-primary me-3"></i>
                            <div>
                                <div class="fw-semibold">View Submissions</div>
                                <small class="text-muted">Check survey responses</small>
                            </div>
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item navbar-dropdown-item" href="survey.php">
                            <i class="fas fa-share-alt text-info me-3"></i>
                            <div>
                                <div class="fw-semibold">Create Survey</div>
                                <small class="text-muted">Make survey live</small>
                            </div>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Notifications -->
            <!-- Notifications Dropdown -->
            <div class="position-relative me-3 d-none d-md-block dropdown">
                <button class="btn btn-nav-icon dropdown-toggle" type="button" id="notificationsDropdown"
                        data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
                    <i class="fas fa-bell"></i>
                    <span class="navbar-badge" id="notificationCount">3</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end navbar-dropdown" aria-labelledby="notificationsDropdown">
                    <li class="dropdown-header">
                        <i class="fas fa-bell text-warning me-2"></i>Notifications
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item navbar-dropdown-item" href="#">
                            <i class="fas fa-info-circle text-info me-3"></i>
                            <div>
                                <div class="fw-semibold">New submission received</div>
                                <small class="text-muted">2 minutes ago</small>
                            </div>
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item navbar-dropdown-item" href="#">
                            <i class="fas fa-check-circle text-success me-3"></i>
                            <div>
                                <div class="fw-semibold">Form published successfully</div>
                                <small class="text-muted">1 hour ago</small>
                            </div>
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item navbar-dropdown-item" href="#">
                            <i class="fas fa-user-plus text-primary me-3"></i>
                            <div>
                                <div class="fw-semibold">New user registered</div>
                                <small class="text-muted">Today</small>
                            </div>
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item text-center text-primary fw-semibold" href="#">
                            View all notifications
                        </a>
                    </li>
                </ul>
            </div>

            <!-- User Dropdown -->
            <div class="dropdown position-relative">
                <a href="#" class="navbar-user-avatar d-flex align-items-center" 
                   id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="avatar-container me-2">
                        <img src="argon-dashboard-master/assets/img/ship.jpg" alt="User" class="avatar-img">
                        <div class="avatar-status"></div>
                    </div>
                    <div class="d-none d-lg-flex flex-column text-start">
                        <span class="navbar-username"><?= $_SESSION['admin_username'] ?? 'Admin' ?></span>
                        <small class="navbar-role">Administrator</small>
                    </div>
                    <i class="fas fa-chevron-down ms-2 d-none d-lg-inline navbar-chevron"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end navbar-dropdown" aria-labelledby="userDropdown">
                    <li class="dropdown-header">
                        <div class="d-flex align-items-center">
                            <img src="argon-dashboard-master/assets/img/ship.jpg" alt="User" class="rounded-circle me-3" style="width: 40px; height: 40px;">
                            <div>
                                <div class="fw-semibold"><?= $_SESSION['admin_username'] ?? 'Admin' ?></div>
                                <small class="text-muted">administrator</small>
                            </div>
                        </div>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item navbar-dropdown-item" href="#">
                            <i class="fas fa-user-circle me-3"></i>My Profile
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item navbar-dropdown-item" href="register.php">
                            <i class="fas fa-user-plus me-3"></i>Register New User
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item navbar-dropdown-item" href="#">
                            <i class="fas fa-cog me-3"></i>Account Settings
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="post" action="../../../index.php" class="m-0">
                            <button class="dropdown-item navbar-dropdown-item text-danger" name="logout">
                                <i class="fas fa-sign-out-alt me-3"></i>Logout
                               
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<!-- Add padding to body to prevent content from hiding under fixed navbar -->
<style>
    /* Body padding to account for fixed navbar */
    body {
        padding-top: 80px; /* Adjust based on navbar height */
    }
    
    /* Enhanced Navbar Styling */
    .navbar-main {
        position: fixed !important;
        top: 0;
        left: 250px; /* Adjust this value to match your sidebar width */
        right: 0;
        z-index: 1050;
       background: linear-gradient(90deg, #020617 0%, #020617 100%) !important;   // 

        backdrop-filter: blur(15px);
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        padding: 0.75rem 1rem;
        min-height: 70px;
        box-shadow: 0 4px 20px rgba(30, 60, 114, 0.15) !important;
        transition: left 0.3s ease; /* Smooth transition when sidebar toggles */
    }
    
    /* Adjust navbar when sidebar is collapsed */
    .sidebar-collapsed .navbar-main {
        left: 80px; /* Adjust this for collapsed sidebar width */
    }
    
    /* Navigation Toggle Buttons */
    .btn-nav-toggle {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        color: #fff;
        border-radius: 8px;
        padding: 0.5rem 0.75rem;
        transition: all 0.3s ease;
    }
    
    .btn-nav-toggle:hover {
        background: rgba(255, 255, 255, 0.2);
        color: #fff;
        transform: translateY(-1px);
    }
    
    /* Breadcrumb Styling */
    .navbar-breadcrumb {
        --bs-breadcrumb-divider: '>';
    }
    
    .navbar-breadcrumb .breadcrumb-item {
        font-size: 0.875rem;
    }
    
    .breadcrumb-link {
        color: #e0e6ed; /* Soft, gentle grayish-blue for less eye strain */
        text-decoration: none;
        transition: color 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.25em;
    }

    .breadcrumb-link i {
        color: #b6c7de !important; /* Make home icon softer and visible */
        font-size: 1em;
        vertical-align: middle;
        margin-right: 0.2em;
    }

    .breadcrumb-link:hover {
        color: #b6c7de; /* Slightly deeper but still soft blue on hover */
    }

    .navbar-breadcrumb-active {
        color: #f1f3f7; /* Very soft off-white for active, less harsh than pure white */
    }
        font-weight: 500;
    }
    
    /* Page Title */
    .navbar-title {
        color: #fff;
        font-weight: 600;
        font-size: 1.25rem;
        margin-top: 0.25rem;
    }
    
    /* Action Buttons */
    .btn-nav-action {
        background: rgba(255, 255, 255, 0.15);
        border: 1px solid rgba(255, 255, 255, 0.2);
        color: #fff;
        border-radius: 8px;
        padding: 0.5rem 1rem;
        font-weight: 500;
        transition: all 0.3s ease;
    }
    
    .btn-nav-action:hover {
        background: rgba(255, 255, 255, 0.25);
        color: #fff;
        transform: translateY(-1px);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    }
    
    .btn-nav-icon {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        color: #fff;
        border-radius: 50%;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        transition: all 0.3s ease;
    }
    
    .btn-nav-icon:hover {
        background: rgba(255, 255, 255, 0.2);
        color: #fff;
        transform: translateY(-1px);
    }
    
    /* Notification Badge */
    .navbar-badge {
        position: absolute;
        top: -5px;
        right: -5px;
        background: #ff4757;
        color: white;
        border-radius: 50%;
        width: 18px;
        height: 18px;
        font-size: 0.75rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        border: 2px solid #1e3c72;
    }
    
    /* User Avatar Styling */
    .navbar-user-avatar {
        text-decoration: none !important;
        color: #fff;
        padding: 0.5rem;
        border-radius: 12px;
        transition: all 0.3s ease;
    }
    
    .navbar-user-avatar:hover {
        background: rgba(255, 255, 255, 0.1);
        color: #fff;
    }
    
    .avatar-container {
        position: relative;
    }
    
    .avatar-img {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        border: 2px solid rgba(255, 255, 255, 0.3);
        object-fit: cover;
    }
    
    .avatar-status {
        position: absolute;
        bottom: 2px;
        right: 2px;
        width: 12px;
        height: 12px;
        background: #2ed573;
        border: 2px solid #1e3c72;
        border-radius: 50%;
    }
    
    .navbar-username {
        font-weight: 600;
        font-size: 0.875rem;
        color: #fff;
    }
    
    .navbar-role {
        color: rgba(255, 255, 255, 0.7);
        font-size: 0.75rem;
    }
    
    .navbar-chevron {
        font-size: 0.75rem;
        color: rgba(255, 255, 255, 0.6);
        transition: transform 0.3s ease;
    }
    
    .dropdown[aria-expanded="true"] .navbar-chevron {
        transform: rotate(180deg);
    }
    
    /* Enhanced Dropdown Styling */
    .navbar-dropdown {
        border: none;
        border-radius: 12px;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        background: #fff;
        min-width: 280px;
        padding: 0.5rem 0;
        margin-top: 0.5rem;
        animation: dropdownFadeIn 0.2s ease-out;
    }
    
    @keyframes dropdownFadeIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .dropdown-header {
        padding: 1rem 1.5rem 0.5rem;
        font-weight: 600;
        color: #333;
        font-size: 0.875rem;
        display: flex;
        align-items: center;
    }
    
    .navbar-dropdown-item {
        padding: 0.75rem 1.5rem;
        color: #333;
        text-decoration: none;
        display: flex;
        align-items: center;
        transition: all 0.2s ease;
        border: none;
        background: none;
        width: 100%;
        text-align: left;
    }
    
    .navbar-dropdown-item:hover {
        background: linear-gradient(135deg, #f8f9ff 0%, #e3f2fd 100%);
        color: #1e3c72;
        transform: translateX(5px);
    }
    
    .navbar-dropdown-item.text-danger:hover {
        background: linear-gradient(135deg, #fff5f5 0%, #fee);
        color: #dc3545;
    }
    
    .navbar-dropdown-item i {
        width: 20px;
        text-align: center;
    }
    
    .dropdown-divider {
        margin: 0.5rem 1rem;
        border-color: #e9ecef;
    }
    
    /* Responsive Design */
    @media (max-width: 991.98px) {
        .navbar-main {
            padding: 0.5rem;
            min-height: 60px;
            left: 0 !important; /* Full width on mobile */
        }
        
        body {
            padding-top: 70px;
        }
        
        .navbar-title {
            font-size: 1.1rem;
        }
        
        .navbar-dropdown {
            min-width: 250px;
        }
    }
    
    @media (max-width: 767.98px) {
        .navbar-actions {
            gap: 0.5rem;
        }
        
        .btn-nav-action span {
            display: none !important;
        }
        
        .navbar-dropdown {
            min-width: 220px;
        }
    }
    
    /* Ensure dropdowns stay above content */
    .dropdown-menu.navbar-dropdown {
        position: absolute !important;
        z-index: 1060 !important;
    }
    
    /* Main content adjustment */
    .main-content {
        position: relative;
        z-index: 1;
    }
    
    /* Loading animation for better UX */
    .navbar-main::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
        animation: shimmer 2s infinite;
    }
    
    @keyframes shimmer {
        0% { transform: translateX(-100%); }
        100% { transform: translateX(100%); }
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Bootstrap dropdowns with enhanced functionality
        const dropdownElements = document.querySelectorAll('.dropdown-toggle');
        dropdownElements.forEach(el => {
            el.addEventListener('show.bs.dropdown', function() {
                const dropdownMenu = this.nextElementSibling;
                dropdownMenu.style.position = 'absolute';
                dropdownMenu.style.zIndex = '1060';
            });
        });
        
        // Enhanced sidebar toggle functionality
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarToggleDesktop = document.getElementById('sidebarToggleDesktop');
        const sidebar = document.getElementById('sidenav-main');
        const navbar = document.getElementById('navbarBlur');
        
        function adjustNavbarPosition() {
            // Check if sidebar exists and adjust navbar accordingly
            if (sidebar && navbar) {
                const isCollapsed = sidebar.classList.contains('collapsed');
                navbar.style.left = isCollapsed ? '80px' : '250px';
                
                // Update body class for additional styling if needed
                if (isCollapsed) {
                    document.body.classList.add('sidebar-collapsed');
                } else {
                    document.body.classList.remove('sidebar-collapsed');
                }
            }
        }
        
        function toggleSidebar() {
            if (sidebar) {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                
                // Adjust navbar position after sidebar toggle
                adjustNavbarPosition();
                
                // Add animation feedback
                const toggleBtn = event.target.closest('button');
                if (toggleBtn) {
                    toggleBtn.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        toggleBtn.style.transform = 'scale(1)';
                    }, 150);
                }
            }
        }
        
        // Add event listeners for sidebar toggle
        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        if (sidebarToggleDesktop) sidebarToggleDesktop.addEventListener('click', toggleSidebar);
        
        // Also listen for any existing sidebar toggle functionality
        // This ensures compatibility with existing sidebar scripts
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                    if (mutation.target === sidebar) {
                        adjustNavbarPosition();
                    }
                }
            });
        });
        
        if (sidebar) {
            observer.observe(sidebar, { attributes: true });
        }
        
        // Restore sidebar state and adjust navbar position
        const sidebarCollapsed = localStorage.getItem('sidebarCollapsed');
        if (sidebar) {
            if (sidebarCollapsed === 'true') {
                sidebar.classList.add('collapsed');
            }
            // Always adjust navbar position on page load
            adjustNavbarPosition();
        } else {
            // If no sidebar found, default navbar to left edge
            if (navbar) {
                navbar.style.left = '0px';
            }
        }
        
        // Add scroll behavior for navbar
        let lastScrollTop = 0;
        
        window.addEventListener('scroll', function() {
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            
            // Add shadow based on scroll position
            if (scrollTop > 0) {
                navbar.style.boxShadow = '0 4px 25px rgba(30, 60, 114, 0.25)';
            } else {
                navbar.style.boxShadow = '0 4px 20px rgba(30, 60, 114, 0.15)';
            }
            
            lastScrollTop = scrollTop;
        });
        
        // Add click outside to close dropdowns
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.dropdown')) {
                const openDropdowns = document.querySelectorAll('.dropdown-menu.show');
                openDropdowns.forEach(dropdown => {
                    const toggle = dropdown.previousElementSibling;
                    if (toggle) {
                        bootstrap.Dropdown.getInstance(toggle)?.hide();
                    }
                });
            }
        });
    });
</script>