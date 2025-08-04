<?php
/**
 * Permission Manager Class
 * Handles role-based access control and permission checking
 */
class PermissionManager {
    private $pdo;
    private $userId;
    private $userPermissions = null;
    private $userRole = null;
    
    public function __construct($pdo, $userId) {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->loadUserPermissions();
    }
    
    /**
     * Load user permissions from database
     */
    private function loadUserPermissions() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    au.id, au.username, au.role_id,
                    ur.name as role_name, ur.display_name as role_display_name,
                    GROUP_CONCAT(p.name) as permissions
                FROM admin_users au
                LEFT JOIN user_roles ur ON au.role_id = ur.id
                LEFT JOIN role_permissions rp ON ur.id = rp.role_id
                LEFT JOIN permissions p ON rp.permission_id = p.id
                WHERE au.id = ? AND au.status = 1
                GROUP BY au.id
            ");
            $stmt->execute([$this->userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $this->userRole = [
                    'id' => $result['role_id'],
                    'name' => $result['role_name'],
                    'display_name' => $result['role_display_name']
                ];
                $this->userPermissions = $result['permissions'] ? explode(',', $result['permissions']) : [];
            } else {
                $this->userPermissions = [];
                $this->userRole = null;
            }
        } catch (PDOException $e) {
            error_log("Permission loading error: " . $e->getMessage());
            $this->userPermissions = [];
            $this->userRole = null;
        }
    }
    
    /**
     * Check if user has specific permission
     */
    public function hasPermission($permission) {
        return in_array($permission, $this->userPermissions ?? []);
    }
    
    /**
     * Check if user has any of the specified permissions
     */
    public function hasAnyPermission($permissions) {
        if (!is_array($permissions)) {
            $permissions = [$permissions];
        }
        
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check if user has all specified permissions
     */
    public function hasAllPermissions($permissions) {
        if (!is_array($permissions)) {
            $permissions = [$permissions];
        }
        
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($permission)) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Get user role information
     */
    public function getUserRole() {
        return $this->userRole;
    }
    
    /**
     * Get all user permissions
     */
    public function getUserPermissions() {
        return $this->userPermissions;
    }
    
    /**
     * Check if user is super admin
     */
    public function isSuperAdmin() {
        return $this->userRole && $this->userRole['name'] === 'super_admin';
    }
    
    /**
     * Check if user can manage another user (role hierarchy)
     */
    public function canManageUser($targetUserId) {
        if (!$this->hasPermission('user_update') && !$this->hasPermission('user_delete')) {
            return false;
        }
        
        // Super admin can manage anyone
        if ($this->isSuperAdmin()) {
            return true;
        }
        
        // Users cannot manage themselves for role changes
        if ($targetUserId == $this->userId) {
            return false;
        }
        
        // Get target user's role
        try {
            $stmt = $this->pdo->prepare("
                SELECT ur.name as role_name, ur.id as role_id
                FROM admin_users au
                LEFT JOIN user_roles ur ON au.role_id = ur.id
                WHERE au.id = ?
            ");
            $stmt->execute([$targetUserId]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Cannot manage super admins unless you are one
            if ($targetUser && $targetUser['role_name'] === 'super_admin') {
                return $this->isSuperAdmin();
            }
            
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Get available roles that current user can assign
     */
    public function getAssignableRoles() {
        try {
            if ($this->isSuperAdmin()) {
                // Super admin can assign any role
                $stmt = $this->pdo->prepare("SELECT * FROM user_roles ORDER BY id");
                $stmt->execute();
            } else {
                // Other users cannot assign super_admin role
                $stmt = $this->pdo->prepare("SELECT * FROM user_roles WHERE name != 'super_admin' ORDER BY id");
                $stmt->execute();
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
    
    /**
     * Log user activity
     */
    public function logActivity($action, $targetType = null, $targetId = null, $details = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO user_activity_log (user_id, action, target_type, target_id, details, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $this->userId,
                $action,
                $targetType,
                $targetId,
                $details ? json_encode($details) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (PDOException $e) {
            error_log("Activity logging error: " . $e->getMessage());
        }
    }
    
    /**
     * Generate permission error message
     */
    public function getPermissionDeniedMessage($action = 'perform this action') {
        return "You don't have permission to {$action}. Contact your administrator if you need access.";
    }
}
?>