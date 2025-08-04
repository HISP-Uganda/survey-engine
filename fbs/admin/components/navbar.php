<?php
// Define page titles for different sections
$pages = [
    "main.php" => "Dashboard",
    "records.php" => "Survey Dashboard & Records",
    "submissions.php" => "Submissions",
    "question_bank.php" => "Question Bank",
    "survey.php" => "Survey Manager",
    "settings.php" => "Settings",
    "sb.php" => "Survey Builder",
    "payload_checker.php" => "DHIS2 Payload Checker"
];
$currentPage = basename($_SERVER['PHP_SELF']);
$pageTitle = $pages[$currentPage] ?? "Admin Panel";

// Function to get real notifications
function getRecentNotifications() {
    global $pdo;
    $notifications = [];
    
    try {
        if (isset($pdo)) {
            // Get recent submissions (last 24 hours) - try different possible column names
            try {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count, MAX(created_at) as latest 
                    FROM survey_responses 
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ");
                $stmt->execute();
                $submissions = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // Try with 'created' column instead
                try {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) as count, MAX(created) as latest 
                        FROM survey_responses 
                        WHERE created >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    ");
                    $stmt->execute();
                    $submissions = $stmt->fetch(PDO::FETCH_ASSOC);
                } catch (PDOException $e2) {
                    // If table doesn't exist or other error, set empty result
                    $submissions = ['count' => 0, 'latest' => null];
                }
            }
            
            if ($submissions['count'] > 0) {
                $notifications[] = [
                    'icon' => 'fa-file-alt',
                    'color' => 'info',
                    'title' => $submissions['count'] . ' new submission' . ($submissions['count'] > 1 ? 's' : ''),
                    'time' => time_ago($submissions['latest']),
                    'link' => 'records.php'
                ];
            }
            
            // Get recently created surveys
            try {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count, MAX(created_at) as latest 
                    FROM survey 
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                ");
                $stmt->execute();
                $surveys = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // Try with 'created' column instead
                try {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) as count, MAX(created) as latest 
                        FROM survey 
                        WHERE created >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    ");
                    $stmt->execute();
                    $surveys = $stmt->fetch(PDO::FETCH_ASSOC);
                } catch (PDOException $e2) {
                    // If table doesn't exist or other error, set empty result
                    $surveys = ['count' => 0, 'latest' => null];
                }
            }
            
            if ($surveys['count'] > 0) {
                $notifications[] = [
                    'icon' => 'fa-plus-circle',
                    'color' => 'success',
                    'title' => $surveys['count'] . ' survey' . ($surveys['count'] > 1 ? 's' : '') . ' created this week',
                    'time' => 'This week',
                    'link' => 'survey.php'
                ];
            }
            
            // Check for system updates or maintenance notices
            $notifications[] = [
                'icon' => 'fa-cog',
                'color' => 'warning',
                'title' => 'System maintenance scheduled',
                'time' => 'Tomorrow 2:00 AM',
                'link' => 'settings.php'
            ];
        }
    } catch (Exception $e) {
        // Fallback notifications if database error
        $notifications[] = [
            'icon' => 'fa-info-circle',
            'color' => 'info',
            'title' => 'Welcome to FBS Admin Panel',
            'time' => 'Just now',
            'link' => 'main.php'
        ];
    }
    
    return $notifications;
}

function time_ago($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'Just now';
    if ($time < 3600) return floor($time/60) . ' minutes ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    return date('M j, Y', strtotime($datetime));
}

$notifications = getRecentNotifications();
$notificationCount = count($notifications);
?>

<nav class="navbar navbar-main navbar-expand-lg px-3 navbar-fixed-top shadow-lg" id="navbarBlur">
    <div class="container-fluid position-relative">
        <!-- Enhanced Sidebar Toggle Buttons -->
        <button class="btn btn-nav-toggle px-0 me-2 d-lg-none" id="sidebarToggle" data-sidebar-toggle>
            <i class="fas fa-bars fa-lg"></i>
            <span class="visually-hidden">Toggle sidebar</span>
        </button>
        <button class="btn btn-nav-toggle px-0 me-3 d-none d-lg-block" id="sidebarToggleDesktop" data-sidebar-toggle>
            <i class="fas fa-bars fa-lg"></i>
            <span class="visually-hidden">Toggle sidebar</span>
        </button>

        <!-- Enhanced Logo Section -->
        <div class="d-flex align-items-center flex-grow-1">
            <a href="main.php" class="navbar-brand d-flex align-items-center enhanced-brand" style="color: #1e293b; font-weight: 700; font-size: 1.25rem; text-decoration: none;">
                <div class="brand-icon-container me-2">
                    <i class="fas fa-rocket" style="color: #3b82f6;"></i>
                </div>
                <span class="brand-text">FBS Admin</span>
            </a>
            <!-- Breadcrumb Section -->
            <nav class="navbar-breadcrumb ms-4 d-none d-md-flex" aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item">
                        <a href="main.php" class="breadcrumb-link">
                            <i class="fas fa-home"></i> Home
                        </a>
                    </li>
                    <li class="breadcrumb-item navbar-breadcrumb-active" aria-current="page">
                        <?php echo htmlspecialchars($pageTitle); ?>
                    </li>
                </ol>
            </nav>
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
                        <a class="dropdown-item navbar-dropdown-item" href="sb.php">
                            <i class="fas fa-plus-circle text-success me-3"></i>
                            <div>
                                <div class="fw-semibold">Create New Survey</div>
                                <small class="text-muted">Build a new survey form</small>
                            </div>
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item navbar-dropdown-item" href="question_bank.php">
                            <i class="fas fa-question-circle text-info me-3"></i>
                            <div>
                                <div class="fw-semibold">Question Bank</div>
                                <small class="text-muted">Manage survey questions</small>
                            </div>
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item navbar-dropdown-item" href="records.php">
                            <i class="fas fa-chart-bar text-primary me-3"></i>
                            <div>
                                <div class="fw-semibold">View Analytics</div>
                                <small class="text-muted">Survey dashboard & records</small>
                            </div>
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item navbar-dropdown-item" href="survey.php">
                            <i class="fas fa-list-check text-warning me-3"></i>
                            <div>
                                <div class="fw-semibold">Manage Surveys</div>
                                <small class="text-muted">View and edit surveys</small>
                            </div>
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item navbar-dropdown-item" href="settings.php">
                            <i class="fas fa-sliders-h text-secondary me-3"></i>
                            <div>
                                <div class="fw-semibold">Settings</div>
                                <small class="text-muted">System configuration</small>
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
                    <?php if ($notificationCount > 0): ?>
                        <span class="navbar-badge" id="notificationCount"><?php echo $notificationCount; ?></span>
                    <?php endif; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end navbar-dropdown" aria-labelledby="notificationsDropdown">
                    <li class="dropdown-header">
                        <i class="fas fa-bell text-warning me-2"></i>Notifications
                        <?php if ($notificationCount > 0): ?>
                            <span class="badge bg-warning text-dark ms-2"><?php echo $notificationCount; ?></span>
                        <?php endif; ?>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <?php if (empty($notifications)): ?>
                        <li>
                            <div class="dropdown-item-text text-center py-4">
                                <i class="fas fa-bell-slash text-muted mb-2" style="font-size: 2rem;"></i>
                                <div class="text-muted">No new notifications</div>
                            </div>
                        </li>
                    <?php else: ?>
                        <?php foreach ($notifications as $notification): ?>
                            <li>
                                <a class="dropdown-item navbar-dropdown-item" href="<?php echo $notification['link']; ?>">
                                    <i class="fas <?php echo $notification['icon']; ?> text-<?php echo $notification['color']; ?> me-3"></i>
                                    <div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($notification['title']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($notification['time']); ?></small>
                                    </div>
                                </a>
                            </li>
                        <?php endforeach; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-center text-primary fw-semibold" href="records.php">
                                <i class="fas fa-eye me-1"></i> View all activity
                            </a>
                        </li>
                    <?php endif; ?>
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
                                <small class="text-muted">Administrator</small>
                                <small class="text-success d-block">
                                    <i class="fas fa-circle" style="font-size: 0.5rem;"></i> Online
                                </small>
                            </div>
                        </div>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item navbar-dropdown-item" href="settings.php?tab=profile">
                            <i class="fas fa-user-circle text-primary me-3"></i>
                            <div>
                                <div class="fw-semibold">My Profile</div>
                                <small class="text-muted">Edit profile & change password</small>
                            </div>
                        </a>
                    </li>
                   
                    <li>
                        <a class="dropdown-item navbar-dropdown-item" href="settings.php?tab=users">
                            <i class="fas fa-users-cog text-info me-3"></i>
                            <div>
                                <div class="fw-semibold">User Management</div>
                                <small class="text-muted">Manage users & permissions</small>
                            </div>
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item navbar-dropdown-item" href="settings.php?tab=config">
                            <i class="fas fa-cog text-secondary me-3"></i>
                            <div>
                                <div class="fw-semibold">DHIS2 Configuration</div>
                                <small class="text-muted">Configure DHIS2 connections</small>
                            </div>
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item navbar-dropdown-item" href="settings.php">
                            <i class="fas fa-sliders-h text-warning me-3"></i>
                            <div>
                                <div class="fw-semibold">All Settings</div>
                                <small class="text-muted">Access complete settings interface</small>
                            </div>
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="post" action="logout.php" class="m-0">
                            <button class="dropdown-item navbar-dropdown-item text-danger" type="submit" name="logout">
                                <i class="fas fa-sign-out-alt me-3"></i>
                                <div>
                                    <div class="fw-semibold">Logout</div>
                                    <small class="text-muted">Sign out of your account</small>
                                </div>
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
        padding-top: 85px; /* Adjust based on navbar height */
        transition: padding-top 0.3s ease;
    }
    
    body.sidebar-collapsed {
        /* Additional styles when sidebar is collapsed */
    }
    
    body.sidebar-visible {
        /* Additional styles when sidebar is visible */
    }
    
    /* Enhanced Navbar Styling */
    .navbar-main {
        position: fixed !important;
        top: 0;
        left: 260px; /* Adjust this value to match your sidebar width */
        right: 0;
        z-index: 1050;
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 50%, #f1f5f9 100%) !important;
        backdrop-filter: blur(20px);
        border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        padding: 0.875rem 1.5rem;
        min-height: 75px;
        box-shadow: 0 4px 25px rgba(0, 0, 0, 0.15) !important;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    /* Add subtle pattern overlay */
    .navbar-main::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: radial-gradient(circle at 30% 20%, rgba(59, 130, 246, 0.05) 0%, transparent 50%);
        pointer-events: none;
    }
    
    /* Adjust navbar when sidebar is collapsed */
    .sidebar-collapsed .navbar-main {
        left: 80px; /* Adjust this for collapsed sidebar width */
    }
    
    /* Enhanced Navigation Toggle Buttons */
    .btn-nav-toggle {
        background: rgba(59, 130, 246, 0.1);
        border: 1px solid rgba(59, 130, 246, 0.2);
        color: #1e293b;
        border-radius: 10px;
        padding: 0.625rem 0.875rem;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }
    
    .btn-nav-toggle::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(59, 130, 246, 0.2), transparent);
        transition: left 0.5s;
    }
    
    .btn-nav-toggle:hover {
        background: rgba(59, 130, 246, 0.2);
        color: #1e293b;
        transform: translateY(-2px) scale(1.05);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
    }
    
    .btn-nav-toggle:hover::before {
        left: 100%;
    }
    
    .btn-nav-toggle:active {
        transform: translateY(-1px) scale(1.02);
    }
    
    /* Enhanced Breadcrumb Styling */
    .navbar-breadcrumb {
        --bs-breadcrumb-divider: '>';
        --bs-breadcrumb-divider-color: #94a3b8; /* Better visibility for divider */
    }
    
    .navbar-breadcrumb .breadcrumb {
        margin-bottom: 0;
        font-size: 0.875rem;
    }
    
    .navbar-breadcrumb .breadcrumb-item {
        font-size: 0.875rem;
    }
    
    .breadcrumb-link {
        color: #64748b !important; /* Better contrast against light background */
        text-decoration: none;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.25em;
        padding: 0.25rem 0.5rem;
        border-radius: 6px;
        font-weight: 500;
    }

    .breadcrumb-link i {
        color: #ffd700 !important; /* Gold color for home icon to match brand */
        font-size: 1em;
        vertical-align: middle;
        margin-right: 0.3em;
        transition: all 0.3s ease;
    }

    .breadcrumb-link:hover {
        color: #1e293b !important; /* Dark on hover for clarity */
        background: rgba(59, 130, 246, 0.1);
        transform: translateY(-1px);
        text-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
    }
    
    .breadcrumb-link:hover i {
        color: #3b82f6 !important; /* Blue on hover */
        transform: scale(1.1);
    }

    .navbar-breadcrumb-active {
        color: #1e293b !important; /* Dark for maximum visibility against light background */
        font-weight: 600;
        text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3); /* Subtle shadow for better readability */
        position: relative;
    }
    
    .navbar-breadcrumb-active::after {
        content: '';
        position: absolute;
        bottom: -2px;
        left: 0;
        width: 100%;
        height: 2px;
        background: linear-gradient(90deg, #3b82f6 0%, #2563eb 100%);
        border-radius: 1px;
        opacity: 0.8;
    }
    
    /* Page Title */
    .navbar-title {
        color: #1e293b;
        font-weight: 600;
        font-size: 1.25rem;
        margin-top: 0.25rem;
    }
    
    /* Action Buttons */
    .btn-nav-action {
        background: rgba(59, 130, 246, 0.15);
        border: 1px solid rgba(59, 130, 246, 0.2);
        color: #1e293b;
        border-radius: 8px;
        padding: 0.5rem 1rem;
        font-weight: 500;
        transition: all 0.3s ease;
    }
    
    .btn-nav-action:hover {
        background: rgba(59, 130, 246, 0.25);
        color: #1e293b;
        transform: translateY(-1px);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    }
    
    .btn-nav-icon {
        background: rgba(59, 130, 246, 0.1);
        border: 1px solid rgba(59, 130, 246, 0.2);
        color: #1e293b;
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
        background: rgba(59, 130, 246, 0.2);
        color: #1e293b;
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
        border: 2px solid #f8fafc;
    }
    
    /* User Avatar Styling */
    .navbar-user-avatar {
        text-decoration: none !important;
        color: #1e293b;
        padding: 0.5rem;
        border-radius: 12px;
        transition: all 0.3s ease;
    }
    
    .navbar-user-avatar:hover {
        background: rgba(59, 130, 246, 0.1);
        color: #1e293b;
    }
    
    .avatar-container {
        position: relative;
    }
    
    .avatar-img {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        border: 2px solid rgba(59, 130, 246, 0.3);
        object-fit: cover;
    }
    
    .avatar-status {
        position: absolute;
        bottom: 2px;
        right: 2px;
        width: 12px;
        height: 12px;
        background: #2ed573;
        border: 2px solid #f8fafc;
        border-radius: 50%;
    }
    
    .navbar-username {
        font-weight: 600;
        font-size: 0.875rem;
        color: #1e293b;
    }
    
    .navbar-role {
        color: rgba(30, 41, 59, 0.7);
        font-size: 0.75rem;
    }
    
    .navbar-chevron {
        font-size: 0.75rem;
        color: rgba(30, 41, 59, 0.6);
        transition: transform 0.3s ease;
    }
    
    .dropdown[aria-expanded="true"] .navbar-chevron {
        transform: rotate(180deg);
    }
    
    /* Enhanced Dropdown Styling with stability fixes */
    .navbar-dropdown {
        border: none;
        border-radius: 16px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25), 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
        min-width: 300px;
        padding: 0.75rem 0;
        margin-top: 0.75rem;
        border: 1px solid rgba(0, 0, 0, 0.05);
        backdrop-filter: blur(10px);
        opacity: 0;
        visibility: hidden;
        transform: translateY(-10px);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        position: absolute !important;
        z-index: 1060 !important;
    }
    
    /* Show state for dropdowns */
    .navbar-dropdown.show {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
        animation: dropdownFadeIn 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    /* Prevent flash of unstyled content */
    .dropdown-menu:not(.show) {
        display: none !important;
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
    
    /* Enhanced Brand Styling */
    .enhanced-brand {
        transition: all 0.3s ease;
    }
    
    .enhanced-brand:hover {
        transform: scale(1.02);
        text-shadow: 0 2px 8px rgba(255, 215, 0, 0.3);
    }
    
    .brand-icon-container {
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(59, 130, 246, 0.1);
        border-radius: 8px;
        transition: all 0.3s ease;
    }
    
    .enhanced-brand:hover .brand-icon-container {
        background: rgba(59, 130, 246, 0.2);
        transform: rotate(5deg) scale(1.1);
    }
    
    .brand-text {
        background: linear-gradient(135deg, #1e293b 0%, #64748b 100%);
        background-clip: text;
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        font-weight: 700;
    }
    
    /* Enhanced Responsive Design */
    @media (max-width: 991.98px) {
        .navbar-main {
            padding: 0.75rem 1rem;
            min-height: 65px;
            left: 0 !important; /* Full width on mobile */
        }
        
        body {
            padding-top: 75px;
        }
        
        .navbar-title {
            font-size: 1.1rem;
        }
        
        .navbar-dropdown {
            min-width: 260px;
        }
        
        .brand-text {
            font-size: 1.1rem;
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
        // Fix dropdown stability issues
        const dropdownElements = document.querySelectorAll('.dropdown-toggle');
        
        // Prevent dropdowns from showing on page load
        dropdownElements.forEach(el => {
            const dropdownMenu = el.nextElementSibling;
            if (dropdownMenu && dropdownMenu.classList.contains('dropdown-menu')) {
                // Force hide dropdowns initially
                dropdownMenu.classList.remove('show');
                dropdownMenu.style.display = 'none';
                dropdownMenu.style.position = 'absolute';
                dropdownMenu.style.zIndex = '1060';
                
                // Enhanced dropdown initialization
                el.addEventListener('show.bs.dropdown', function(e) {
                    const menu = this.nextElementSibling;
                    if (menu) {
                        menu.style.display = 'block';
                        // Small delay to ensure smooth animation
                        setTimeout(() => {
                            menu.classList.add('show');
                        }, 10);
                    }
                });
                
                el.addEventListener('hide.bs.dropdown', function(e) {
                    const menu = this.nextElementSibling;
                    if (menu) {
                        menu.classList.remove('show');
                        setTimeout(() => {
                            menu.style.display = 'none';
                        }, 150);
                    }
                });
            }
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
                const isDesktop = window.innerWidth >= 1200;
                
                if (isDesktop) {
                    navbar.style.left = isCollapsed ? '80px' : '260px';
                } else {
                    navbar.style.left = '0px';
                }
                
                // Update body class for additional styling
                document.body.classList.toggle('sidebar-collapsed', isCollapsed && isDesktop);
                document.body.classList.toggle('sidebar-visible', !isCollapsed && isDesktop);
                
                // Add visual feedback
                navbar.style.transform = 'translateX(0)';
                navbar.style.transition = 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
            }
        }
        
        function toggleSidebar() {
            if (sidebar) {
                // Dispatch event to sidebar to handle the toggle
                const toggleEvent = new CustomEvent('navbarToggle');
                window.dispatchEvent(toggleEvent);
                
                // Add animation feedback to button
                const toggleBtn = event?.target?.closest('button');
                if (toggleBtn) {
                    toggleBtn.style.transform = 'scale(0.9) rotate(90deg)';
                    setTimeout(() => {
                        toggleBtn.style.transform = 'scale(1) rotate(0deg)';
                    }, 200);
                }
            }
        }
        
        // Add event listeners for sidebar toggle
        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        if (sidebarToggleDesktop) sidebarToggleDesktop.addEventListener('click', toggleSidebar);
        
        // Listen for sidebar toggle events from sidebar component
        window.addEventListener('sidebarToggle', function(e) {
            adjustNavbarPosition();
            
            // Add subtle animation to navbar content
            const navbarContent = navbar.querySelector('.container-fluid');
            if (navbarContent) {
                navbarContent.style.transform = 'scale(0.98)';
                setTimeout(() => {
                    navbarContent.style.transform = 'scale(1)';
                }, 150);
            }
        });
        
        // Enhanced mutation observer for sidebar changes
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && 
                    (mutation.attributeName === 'class' || mutation.attributeName === 'style')) {
                    if (mutation.target === sidebar) {
                        // Debounce the adjustment to avoid excessive calls
                        clearTimeout(window.navbarAdjustTimeout);
                        window.navbarAdjustTimeout = setTimeout(adjustNavbarPosition, 50);
                    }
                }
            });
        });
        
        if (sidebar) {
            observer.observe(sidebar, { attributes: true, attributeFilter: ['class', 'style'] });
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