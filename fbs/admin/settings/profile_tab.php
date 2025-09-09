<?php
// Profile tab content for settings page
// This file is included in settings.php when profile tab is active
require_once __DIR__ . '/../includes/profile_helper.php';

// Handle profile update if this is a POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Handle profile image upload if provided
    $imageUploadResult = null;
    if (!empty($_FILES['profile_image']['name'])) {
        $imageUploadResult = handleProfileImageUpload($_FILES['profile_image'], $_SESSION['admin_id'], $pdo);
        if (!$imageUploadResult['success']) {
            $message = ['type' => 'error', 'text' => $imageUploadResult['message']];
        }
    }
    
    // Validate inputs
    if (empty($username)) {
        $message = ['type' => 'error', 'text' => 'Username is required.'];
    } elseif (!empty($new_password)) {
        if (strlen($new_password) < 6) {
            $message = ['type' => 'error', 'text' => 'New password must be at least 6 characters long.'];
        } elseif ($new_password !== $confirm_password) {
            $message = ['type' => 'error', 'text' => 'New passwords do not match.'];
        } elseif (empty($current_password)) {
            $message = ['type' => 'error', 'text' => 'Current password is required to change password.'];
        } else {
            // Verify current password
            $stmt = $pdo->prepare("SELECT password FROM admin_users WHERE id = ?");
            $stmt->execute([$_SESSION['admin_id']]);
            $current_hash = $stmt->fetchColumn();
            
            if (!password_verify($current_password, $current_hash)) {
                $message = ['type' => 'error', 'text' => 'Current password is incorrect.'];
            } else {
                // Update with new password
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE admin_users SET username = ?, email = ?, password = ? WHERE id = ?");
                if ($stmt->execute([$username, $email, $new_hash, $_SESSION['admin_id']])) {
                    $_SESSION['admin_username'] = $username;
                    $_SESSION['admin_email'] = $email;
                    $successMsg = 'Profile updated successfully with new password.';
                    if ($imageUploadResult && $imageUploadResult['success']) {
                        $successMsg .= ' Profile image updated.';
                    }
                    $message = ['type' => 'success', 'text' => $successMsg];
                } else {
                    $message = ['type' => 'error', 'text' => 'Failed to update profile.'];
                }
            }
        }
    } else {
        // Update without changing password
        $stmt = $pdo->prepare("UPDATE admin_users SET username = ?, email = ? WHERE id = ?");
        if ($stmt->execute([$username, $email, $_SESSION['admin_id']])) {
            $_SESSION['admin_username'] = $username;
            $_SESSION['admin_email'] = $email;
            $successMsg = 'Profile updated successfully.';
            if ($imageUploadResult && $imageUploadResult['success']) {
                $successMsg .= ' Profile image updated.';
            }
            $message = ['type' => 'success', 'text' => $successMsg];
        } else {
            $message = ['type' => 'error', 'text' => 'Failed to update profile.'];
        }
    }
}

// Get current user data
$stmt = $pdo->prepare("SELECT * FROM admin_users WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);

// If user not found, force re-login
if (!$user_data) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Get user statistics
$stats = [];
try {
    // Count surveys created by this user (if created_by column exists)
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM survey WHERE created_by = ?");
        $stmt->execute([$_SESSION['admin_id']]);
        $stats['surveys'] = $stmt->fetchColumn();
    } catch (PDOException $e) {
        // If created_by column doesn't exist, count all surveys
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM survey");
        $stmt->execute();
        $stats['surveys'] = $stmt->fetchColumn();
    }
    
    // Count total submissions
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM survey_responses");
        $stmt->execute();
        $stats['submissions'] = $stmt->fetchColumn();
    } catch (PDOException $e) {
        $stats['submissions'] = 0;
    }
    
    // Count recent activity (last 7 days)
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM survey_responses WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stmt->execute();
        $stats['recent_activity'] = $stmt->fetchColumn();
    } catch (PDOException $e) {
        // Try with 'created' column instead
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM survey_responses WHERE created >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
            $stmt->execute();
            $stats['recent_activity'] = $stmt->fetchColumn();
        } catch (PDOException $e2) {
            $stats['recent_activity'] = 0;
        }
    }
} catch (Exception $e) {
    $stats = ['surveys' => 0, 'submissions' => 0, 'recent_activity' => 0];
}
?>

<style>
    .profile-header {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        color: #1e293b;
        padding: 2rem;
        margin-bottom: 2rem;
        border-radius: 1rem;
        border: 1px solid #e2e8f0;
    }
    .profile-avatar {
        width: 120px !important;
        height: 120px !important;
        border: 4px solid rgba(59, 130, 246, 0.2);
        border-radius: 50%;
        object-fit: cover;
        display: block;
        margin: 0 auto;
    }
    .stats-card {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        padding: 1.5rem;
    }
    .stats-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 30px rgba(59, 130, 246, 0.1);
    }
    .stats-icon {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        margin-bottom: 1rem;
    }
    .form-control:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
    }
</style>

<div class="tab-header mb-4">
    <h3 class="text-dark"><i class="fas fa-user-circle me-2 text-primary"></i>Profile Settings</h3>
    <p class="text-muted mb-0">Manage your account information and security settings</p>
</div>

<!-- Profile Header -->
<div class="profile-header text-center">
    <div class="row justify-content-center">
        <div class="col-lg-8">
<?php
            // Check if user has custom profile image
            $defaultImage = "argon-dashboard-master/assets/img/ship.jpg";
            $profileImagePath = $defaultImage;
            
            if (!empty($user_data['profile_image']) && file_exists("uploads/profile_images/" . $user_data['profile_image'])) {
                $profileImagePath = "uploads/profile_images/" . $user_data['profile_image'];
            }
            ?>
            <img src="<?php echo htmlspecialchars($profileImagePath); ?>" alt="Profile" class="profile-avatar mb-4" id="profileImage" loading="lazy">
            <h2 class="mb-2"><?php echo htmlspecialchars($user_data['username'] ?? 'Unknown User'); ?></h2>
            <p class="mb-0 text-muted">Administrator</p>
            <p class="text-muted small">Member since <?php echo date('F Y', strtotime($user_data['created_at'] ?? 'now')); ?></p>
        </div>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-4 mb-3">
        <div class="stats-card text-center">
            <div class="stats-icon bg-primary bg-gradient text-white mx-auto">
                <i class="fas fa-list-check"></i>
            </div>
            <h3 class="text-dark mb-1"><?php echo number_format($stats['surveys']); ?></h3>
            <p class="text-muted mb-0">Total Surveys</p>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="stats-card text-center">
            <div class="stats-icon bg-success bg-gradient text-white mx-auto">
                <i class="fas fa-file-alt"></i>
            </div>
            <h3 class="text-dark mb-1"><?php echo number_format($stats['submissions']); ?></h3>
            <p class="text-muted mb-0">Total Submissions</p>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="stats-card text-center">
            <div class="stats-icon bg-info bg-gradient text-white mx-auto">
                <i class="fas fa-chart-line"></i>
            </div>
            <h3 class="text-dark mb-1"><?php echo number_format($stats['recent_activity']); ?></h3>
            <p class="text-muted mb-0">Recent Activity</p>
        </div>
    </div>
</div>

<!-- Profile Form -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0 text-dark">
            <i class="fas fa-edit me-2"></i>Edit Profile Information
        </h5>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <!-- Profile Image Upload -->
            <div class="row mb-4">
                <div class="col-12">
                    <label for="profile_image" class="form-label text-dark">Profile Image</label>
                    <input type="file" class="form-control" id="profile_image" name="profile_image" accept="image/*">
                    <div class="form-text">Upload a profile picture. Supported formats: JPG, PNG, GIF (Max: 2MB)</div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="username" class="form-label text-dark">Username</label>
                    <input type="text" class="form-control" id="username" name="username" 
                           value="<?php echo htmlspecialchars($user_data['username'] ?? ''); ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="email" class="form-label text-dark">Email Address</label>
                    <input type="email" class="form-control" id="email" name="email" 
                           value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>">
                </div>
            </div>
            
            <hr class="my-4">
            <h6 class="text-dark mb-3">Change Password (Optional)</h6>
            
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="current_password" class="form-label text-dark">Current Password</label>
                    <input type="password" class="form-control" id="current_password" name="current_password">
                    <small class="form-text text-muted">Required only when changing password</small>
                </div>
                <div class="col-md-4 mb-3">
                    <label for="new_password" class="form-label text-dark">New Password</label>
                    <input type="password" class="form-control" id="new_password" name="new_password">
                    <small class="form-text text-muted">Minimum 6 characters</small>
                </div>
                <div class="col-md-4 mb-3">
                    <label for="confirm_password" class="form-label text-dark">Confirm New Password</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                </div>
            </div>
            
            <div class="d-flex justify-content-end">
                <button type="submit" name="update_profile" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Update Profile
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Profile image preview functionality
document.addEventListener('DOMContentLoaded', function() {
    const profileImageInput = document.getElementById('profile_image');
    const profileImageElement = document.getElementById('profileImage');
    
    profileImageInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            // Validate file type
            const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            if (!validTypes.includes(file.type)) {
                alert('Please select a valid image file (JPG, PNG, or GIF).');
                e.target.value = '';
                return;
            }
            
            // Validate file size (2MB)
            if (file.size > 2 * 1024 * 1024) {
                alert('File size too large. Maximum 2MB allowed.');
                e.target.value = '';
                return;
            }
            
            // Create preview
            const reader = new FileReader();
            reader.onload = function(e) {
                profileImageElement.src = e.target.result;
                
                // Add a subtle animation
                profileImageElement.style.opacity = '0.5';
                setTimeout(() => {
                    profileImageElement.style.opacity = '1';
                }, 100);
                
                // Show file name
                const fileName = file.name;
                const fileInfo = document.createElement('small');
                fileInfo.className = 'text-muted d-block mt-2';
                fileInfo.textContent = 'Selected: ' + fileName;
                
                // Remove existing file info
                const existingInfo = document.querySelector('.profile-image-info');
                if (existingInfo) {
                    existingInfo.remove();
                }
                
                fileInfo.className += ' profile-image-info';
                profileImageInput.parentNode.appendChild(fileInfo);
            };
            reader.readAsDataURL(file);
        }
    });
});
</script>