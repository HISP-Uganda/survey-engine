<?php
// User Management tab content for settings page
// This file is included in settings.php when users tab is active

// Include permission manager
require_once 'includes/PermissionManager.php';

// Initialize permission manager
$permissionManager = new PermissionManager($pdo, $_SESSION['admin_id']);

// Check if user has basic user read permission
if (!$permissionManager->hasPermission('user_read')) {
    echo '<div class="alert alert-danger">';
    echo '<i class="fas fa-exclamation-triangle me-2"></i>';
    echo $permissionManager->getPermissionDeniedMessage('view user management');
    echo '</div>';
    return;
}

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_user'])) {
        $user_id = (int)$_POST['user_id'];
        
        // Check permission to delete users
        if (!$permissionManager->hasPermission('user_delete')) {
            $message = ['type' => 'error', 'text' => $permissionManager->getPermissionDeniedMessage('delete users')];
        }
        // Prevent deleting current user
        elseif ($user_id === $_SESSION['admin_id']) {
            $message = ['type' => 'error', 'text' => 'You cannot delete your own account.'];
        }
        // Check if user can manage this specific user
        elseif (!$permissionManager->canManageUser($user_id)) {
            $message = ['type' => 'error', 'text' => 'You cannot delete this user due to role restrictions.'];
        } else {
            // Get user info for logging before deletion
            $stmt = $pdo->prepare("SELECT username, email FROM admin_users WHERE id = ?");
            $stmt->execute([$user_id]);
            $deletedUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $pdo->prepare("DELETE FROM admin_users WHERE id = ?");
            if ($stmt->execute([$user_id])) {
                $permissionManager->logActivity('user_deleted', 'admin_users', $user_id, [
                    'deleted_username' => $deletedUser['username'],
                    'deleted_email' => $deletedUser['email']
                ]);
                $message = ['type' => 'success', 'text' => 'User deleted successfully.'];
            } else {
                $message = ['type' => 'error', 'text' => 'Failed to delete user.'];
            }
        }
    }
    
    if (isset($_POST['toggle_status'])) {
        $user_id = (int)$_POST['user_id'];
        $new_status = (int)$_POST['new_status'];
        
        // Check permission to update users
        if (!$permissionManager->hasPermission('user_update')) {
            $message = ['type' => 'error', 'text' => $permissionManager->getPermissionDeniedMessage('update user status')];
        }
        // Prevent disabling current user
        elseif ($user_id === $_SESSION['admin_id'] && $new_status === 0) {
            $message = ['type' => 'error', 'text' => 'You cannot disable your own account.'];
        }
        // Check if user can manage this specific user
        elseif (!$permissionManager->canManageUser($user_id)) {
            $message = ['type' => 'error', 'text' => 'You cannot modify this user due to role restrictions.'];
        } else {
            $stmt = $pdo->prepare("UPDATE admin_users SET status = ? WHERE id = ?");
            if ($stmt->execute([$new_status, $user_id])) {
                $status_text = $new_status ? 'activated' : 'deactivated';
                $permissionManager->logActivity('user_status_changed', 'admin_users', $user_id, [
                    'new_status' => $new_status,
                    'action' => $status_text
                ]);
                $message = ['type' => 'success', 'text' => "User {$status_text} successfully."];
            } else {
                $message = ['type' => 'error', 'text' => 'Failed to update user status.'];
            }
        }
    }
    
    if (isset($_POST['add_user'])) {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $role_id = isset($_POST['role_id']) ? (int)$_POST['role_id'] : 2; // Default to admin role
        
        // Check permission to create users
        if (!$permissionManager->hasPermission('user_create')) {
            $message = ['type' => 'error', 'text' => $permissionManager->getPermissionDeniedMessage('create users')];
        }
        // Validate inputs
        elseif (empty($username) || empty($email) || empty($password)) {
            $message = ['type' => 'error', 'text' => 'All fields are required.'];
        } elseif ($password !== $confirm_password) {
            $message = ['type' => 'error', 'text' => 'Passwords do not match.'];
        } elseif (strlen($password) < 6) {
            $message = ['type' => 'error', 'text' => 'Password must be at least 6 characters long.'];
        } else {
            // Validate role assignment permissions
            $assignableRoles = $permissionManager->getAssignableRoles();
            $roleIds = array_column($assignableRoles, 'id');
            if (!in_array($role_id, $roleIds)) {
                $message = ['type' => 'error', 'text' => 'You cannot assign this role.'];
            } else {
                // Check if username or email already exists
                $stmt = $pdo->prepare("SELECT id FROM admin_users WHERE username = ? OR email = ?");
                $stmt->execute([$username, $email]);
                if ($stmt->fetch()) {
                    $message = ['type' => 'error', 'text' => 'Username or email already exists.'];
                } else {
                    // Create new user
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO admin_users (username, email, password, role_id, status, created) VALUES (?, ?, ?, ?, 1, NOW())");
                    if ($stmt->execute([$username, $email, $hashed_password, $role_id])) {
                        $newUserId = $pdo->lastInsertId();
                        $permissionManager->logActivity('user_created', 'admin_users', $newUserId, [
                            'username' => $username,
                            'email' => $email,
                            'role_id' => $role_id
                        ]);
                        $message = ['type' => 'success', 'text' => 'User created successfully.'];
                    } else {
                        $message = ['type' => 'error', 'text' => 'Failed to create user.'];
                    }
                }
            }
        }
    }
    
    if (isset($_POST['update_user_role'])) {
        $user_id = (int)$_POST['user_id'];
        $new_role_id = (int)$_POST['new_role_id'];
        
        // Check permission to assign roles
        if (!$permissionManager->hasPermission('user_role_assign')) {
            $message = ['type' => 'error', 'text' => $permissionManager->getPermissionDeniedMessage('assign user roles')];
        }
        // Cannot change own role
        elseif ($user_id === $_SESSION['admin_id']) {
            $message = ['type' => 'error', 'text' => 'You cannot change your own role.'];
        }
        // Check if user can manage this specific user
        elseif (!$permissionManager->canManageUser($user_id)) {
            $message = ['type' => 'error', 'text' => 'You cannot modify this user due to role restrictions.'];
        } else {
            // Validate role assignment permissions
            $assignableRoles = $permissionManager->getAssignableRoles();
            $roleIds = array_column($assignableRoles, 'id');
            if (!in_array($new_role_id, $roleIds)) {
                $message = ['type' => 'error', 'text' => 'You cannot assign this role.'];
            } else {
                $stmt = $pdo->prepare("UPDATE admin_users SET role_id = ? WHERE id = ?");
                if ($stmt->execute([$new_role_id, $user_id])) {
                    $permissionManager->logActivity('user_role_changed', 'admin_users', $user_id, [
                        'new_role_id' => $new_role_id
                    ]);
                    $message = ['type' => 'success', 'text' => 'User role updated successfully.'];
                } else {
                    $message = ['type' => 'error', 'text' => 'Failed to update user role.'];
                }
            }
        }
    }
}

// Get all users with their roles
$stmt = $pdo->prepare("
    SELECT au.*, ur.name as role_name, ur.display_name as role_display_name 
    FROM admin_users au 
    LEFT JOIN user_roles ur ON au.role_id = ur.id 
    ORDER BY au.created DESC
");
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get assignable roles for the current user
$assignableRoles = $permissionManager->getAssignableRoles();

// Get user statistics
$stats = [];
try {
    $stats['total_users'] = count($users);
$stats['active_users'] = count(array_filter($users, function($user) { return ($user['status'] ?? 0) == 1; }));
    $stats['inactive_users'] = $stats['total_users'] - $stats['active_users'];
} catch (Exception $e) {
    $stats = ['total_users' => 0, 'active_users' => 0, 'inactive_users' => 0];
}
?>

<style>
    .user-card {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        padding: 1.5rem;
    }
    .user-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(59, 130, 246, 0.1);
    }
    .user-avatar {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        border: 3px solid rgba(59, 130, 246, 0.2);
    }
    .status-badge {
        padding: 0.25rem 0.75rem;
        border-radius: 1rem;
        font-size: 0.875rem;
        font-weight: 500;
    }
    .status-active {
        background-color: #dcfce7;
        color: #166534;
        border: 1px solid #bbf7d0;
    }
    .status-inactive {
        background-color: #fef2f2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }
    .role-change-form {
        margin-top: 0.5rem;
    }
    .role-change-form .input-group-sm .form-select {
        font-size: 0.775rem;
    }
    .user-card .badge {
        font-size: 0.7rem;
        padding: 0.35rem 0.6rem;
    }
    .permission-info {
        background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        border-left: 4px solid #3b82f6;
        padding: 1rem;
        border-radius: 0.5rem;
        margin-bottom: 1rem;
    }
</style>

<div class="tab-header mb-4">
    <h3 class="text-dark"><i class="fas fa-users-cog me-2 text-primary"></i>User Management</h3>
    <p class="text-muted mb-0">Manage administrator accounts and permissions</p>
    
    <!-- Current User Role Badge -->
    <div class="mt-2">
        <span class="badge bg-info">
            <i class="fas fa-user-shield me-1"></i>
            Your Role: <?php echo htmlspecialchars($permissionManager->getUserRole()['display_name'] ?? 'Unknown'); ?>
        </span>
        <?php if ($permissionManager->isSuperAdmin()): ?>
            <span class="badge bg-warning text-dark ms-2">
                <i class="fas fa-crown me-1"></i>Super Admin
            </span>
        <?php endif; ?>
    </div>
</div>

<!-- Role Information Panel -->
<div class="permission-info">
    <h6 class="text-dark mb-2"><i class="fas fa-info-circle me-2"></i>Role System Information</h6>
    <div class="row">
        <div class="col-md-3">
            <strong>Super Admin</strong> <i class="fas fa-crown text-warning"></i>
            <small class="d-block text-muted">Full system access including user management</small>
        </div>
        <div class="col-md-3">
            <strong>Administrator</strong> <i class="fas fa-user-shield text-success"></i>
            <small class="d-block text-muted">Survey management without user control</small>
        </div>
        <div class="col-md-3">
            <strong>Editor</strong> <i class="fas fa-edit text-info"></i>
            <small class="d-block text-muted">Can create/edit content but not delete</small>
        </div>
        <div class="col-md-3">
            <strong>Viewer</strong> <i class="fas fa-eye text-secondary"></i>
            <small class="d-block text-muted">Read-only access to surveys and reports</small>
        </div>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-4 mb-3">
        <div class="card">
            <div class="card-body text-center">
                <div class="d-flex align-items-center justify-content-center">
                    <div class="me-3">
                        <i class="fas fa-users fa-2x text-primary"></i>
                    </div>
                    <div>
                        <h3 class="text-dark mb-0"><?php echo $stats['total_users']; ?></h3>
                        <p class="text-muted mb-0">Total Users</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card">
            <div class="card-body text-center">
                <div class="d-flex align-items-center justify-content-center">
                    <div class="me-3">
                        <i class="fas fa-user-check fa-2x text-success"></i>
                    </div>
                    <div>
                        <h3 class="text-dark mb-0"><?php echo $stats['active_users']; ?></h3>
                        <p class="text-muted mb-0">Active Users</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card">
            <div class="card-body text-center">
                <div class="d-flex align-items-center justify-content-center">
                    <div class="me-3">
                        <i class="fas fa-user-times fa-2x text-warning"></i>
                    </div>
                    <div>
                        <h3 class="text-dark mb-0"><?php echo $stats['inactive_users']; ?></h3>
                        <p class="text-muted mb-0">Inactive Users</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add New User -->
<?php if ($permissionManager->hasPermission('user_create')): ?>
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0 text-dark">
            <i class="fas fa-user-plus me-2"></i>Add New Administrator
        </h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <div class="row">
                <div class="col-md-2 mb-3">
                    <label for="username" class="form-label text-dark">Username</label>
                    <input type="text" class="form-control" id="username" name="username" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label for="email" class="form-label text-dark">Email Address</label>
                    <input type="email" class="form-control" id="email" name="email" required>
                </div>
                <div class="col-md-2 mb-3">
                    <label for="password" class="form-label text-dark">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <div class="col-md-2 mb-3">
                    <label for="confirm_password" class="form-label text-dark">Confirm Password</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label for="role_id" class="form-label text-dark">Role</label>
                    <select class="form-select" id="role_id" name="role_id" required>
                        <?php foreach ($assignableRoles as $role): ?>
                            <option value="<?php echo $role['id']; ?>" <?php echo $role['id'] == 2 ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($role['display_name']); ?>
                                <?php if ($role['name'] === 'super_admin'): ?>
                                    <span class="text-warning">⭐</span>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Select the role for this user</small>
                </div>
            </div>
            <div class="d-flex justify-content-end">
                <button type="submit" name="add_user" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i>Add User
                </button>
            </div>
        </form>
    </div>
</div>
<?php else: ?>
<div class="alert alert-info">
    <i class="fas fa-info-circle me-2"></i>
    You don't have permission to create new users.
</div>
<?php endif; ?>

<!-- Users List -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0 text-dark">
            <i class="fas fa-list me-2"></i>Administrator List
        </h5>
    </div>
    <div class="card-body">
        <?php if (empty($users)): ?>
            <div class="text-center py-4">
                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                <h4 class="text-dark">No Users Found</h4>
                <p class="text-muted">No administrator accounts have been created yet.</p>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($users as $user): ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="user-card">
                            <div class="d-flex align-items-center mb-3">
                                <img src="argon-dashboard-master/assets/img/ship.jpg" alt="User" class="user-avatar me-3">
                                <div class="flex-grow-1">
                                    <h6 class="text-dark mb-1">
                                        <?php echo htmlspecialchars($user['username']); ?>
                                        <?php if ($user['id'] === $_SESSION['admin_id']): ?>
                                            <span class="badge bg-primary ms-1" style="font-size: 0.6rem;">You</span>
                                        <?php endif; ?>
                                    </h6>
                                    <p class="text-muted mb-0 small"><?php echo htmlspecialchars($user['email'] ?? 'No email'); ?></p>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="status-badge <?php echo ($user['status'] ?? 0) ? 'status-active' : 'status-inactive'; ?>">
                                        <i class="fas fa-circle me-1" style="font-size: 0.5rem;"></i>
                                        <?php echo $user['status'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                    
                                    <!-- Role Badge -->
                                    <span class="badge <?php 
                                        echo $user['role_name'] === 'super_admin' ? 'bg-warning text-dark' : 
                                            ($user['role_name'] === 'admin' ? 'bg-success' : 
                                            ($user['role_name'] === 'editor' ? 'bg-info' : 'bg-secondary')); 
                                    ?>">
                                        <?php if ($user['role_name'] === 'super_admin'): ?>
                                            <i class="fas fa-crown me-1"></i>
                                        <?php elseif ($user['role_name'] === 'admin'): ?>
                                            <i class="fas fa-user-shield me-1"></i>
                                        <?php elseif ($user['role_name'] === 'editor'): ?>
                                            <i class="fas fa-edit me-1"></i>
                                        <?php else: ?>
                                            <i class="fas fa-eye me-1"></i>
                                        <?php endif; ?>
                                        <?php echo htmlspecialchars($user['role_display_name'] ?? 'No Role'); ?>
                                    </span>
                                </div>
                                
                                <!-- Role Change Form -->
                                <?php if ($permissionManager->hasPermission('user_role_assign') && 
                                         $user['id'] !== $_SESSION['admin_id'] && 
                                         $permissionManager->canManageUser($user['id'])): ?>
                                    <form method="POST" class="role-change-form">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <div class="input-group input-group-sm">
                                            <select name="new_role_id" class="form-select form-select-sm">
                                                <?php foreach ($assignableRoles as $role): ?>
                                                    <option value="<?php echo $role['id']; ?>" 
                                                            <?php echo $role['id'] == $user['role_id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($role['display_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" name="update_user_role" class="btn btn-outline-primary btn-sm">
                                                <i class="fas fa-sync-alt"></i>
                                            </button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                            
                            <div class="text-muted small mb-3">
                                <i class="fas fa-calendar me-1"></i>
                                Joined <?php echo date('M d, Y', strtotime($user['created'] ?? 'now')); ?>
                            </div>
                            
                            <?php if ($user['id'] !== $_SESSION['admin_id'] && $permissionManager->canManageUser($user['id'])): ?>
                                <div class="d-flex gap-2">
                                    <?php if ($permissionManager->hasPermission('user_update')): ?>
                                        <form method="POST" style="flex: 1;">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <input type="hidden" name="new_status" value="<?php echo $user['status'] ? 0 : 1; ?>">
                                            <button type="submit" name="toggle_status" 
                                                    class="btn btn-sm <?php echo $user['status'] ? 'btn-warning' : 'btn-success'; ?> w-100"
                                                    title="<?php echo $user['status'] ? 'Deactivate user' : 'Activate user'; ?>">
                                                <i class="fas fa-<?php echo $user['status'] ? 'pause' : 'play'; ?>"></i>
                                                <?php echo $user['status'] ? 'Deactivate' : 'Activate'; ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <?php if ($permissionManager->hasPermission('user_delete')): ?>
                                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone.');">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" name="delete_user" class="btn btn-sm btn-danger" title="Delete user">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($user['id'] === $_SESSION['admin_id']): ?>
                                <div class="text-center">
                                    <span class="badge bg-info text-white">
                                        <i class="fas fa-user me-1"></i>Current User
                                    </span>
                                </div>
                            <?php else: ?>
                                <div class="text-center">
                                    <span class="badge bg-secondary text-white">
                                        <i class="fas fa-lock me-1"></i>Protected User
                                    </span>
                                    <small class="d-block text-muted mt-1">Cannot manage due to role restrictions</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>