<?php
// DHIS2 Configuration tab content for settings page
// This file is included in settings.php when config tab is active

// Check if user has super admin or admin role (role_id = 1 or 2)
if (!isset($_SESSION['admin_role_id']) || !in_array($_SESSION['admin_role_id'], [1, 2])) {
    echo '<div class="alert alert-danger">
        <i class="fas fa-lock me-2"></i>
        <strong>Access Denied</strong><br>
        This configuration section is only accessible to Super Administrators and Administrators.
    </div>';
    return;
}

// Handle Create
if (isset($_POST['create'])) {
    $url = $_POST['url'];
    $username = $_POST['username'];
    $password = base64_encode($_POST['password']); // Base64 encode the password
    $key = $_POST['key'];
    $description = $_POST['description'];
    $status = isset($_POST['status']) ? 1 : 0;

    $stmt = $pdo->prepare("INSERT INTO dhis2_instances (url, username, password, instance_key, description, status) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt->execute([$url, $username, $password, $key, $description, $status])) {
        $message = ['type' => 'success', 'text' => 'DHIS2 instance created successfully.'];
    } else {
        $message = ['type' => 'error', 'text' => 'Failed to create DHIS2 instance.'];
    }
}

// Handle Update
if (isset($_POST['update'])) {
    $id = $_POST['id'];
    $url = $_POST['url'];
    $username = $_POST['username'];
    $password = base64_encode($_POST['password']); // Base64 encode the password
    $key = $_POST['key'];
    $description = $_POST['description'];
    $status = isset($_POST['status']) ? 1 : 0;

    $stmt = $pdo->prepare("UPDATE dhis2_instances SET url=?, username=?, password=?, instance_key=?, description=?, status=? WHERE id=?");
    if ($stmt->execute([$url, $username, $password, $key, $description, $status, $id])) {
        $message = ['type' => 'success', 'text' => 'DHIS2 instance updated successfully.'];
    } else {
        $message = ['type' => 'error', 'text' => 'Failed to update DHIS2 instance.'];
    }
}

// Handle Delete
if (isset($_POST['delete'])) {
    $id = $_POST['id'];
    $stmt = $pdo->prepare("DELETE FROM dhis2_instances WHERE id = ?");
    if ($stmt->execute([$id])) {
        $message = ['type' => 'success', 'text' => 'DHIS2 instance deleted successfully.'];
    } else {
        $message = ['type' => 'error', 'text' => 'Failed to delete DHIS2 instance.'];
    }
}

// Fetch all instances
$stmt = $pdo->query("SELECT * FROM dhis2_instances ORDER BY created DESC");
$instances = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get editing instance if edit mode
$editing_instance = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM dhis2_instances WHERE id = ?");
    $stmt->execute([$edit_id]);
    $editing_instance = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<style>
    .config-card {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }
    .config-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(59, 130, 246, 0.1);
    }
    .status-indicator {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 0.5rem;
    }
    .status-active {
        background-color: #22c55e;
        box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2);
    }
    .status-inactive {
        background-color: #ef4444;
        box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.2);
    }
    .instance-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #e2e8f0;
    }
    .connection-test {
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        font-weight: 500;
        border: none;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    .test-success {
        background-color: #dcfce7;
        color: #166534;
    }
    .test-error {
        background-color: #fef2f2;
        color: #991b1b;
    }
    .test-loading {
        background-color: #fef3c7;
        color: #92400e;
    }
</style>

<div class="tab-header mb-4">
    <h3 class="text-dark"><i class="fas fa-cogs me-2 text-primary"></i>DHIS2 Configuration</h3>
    <p class="text-muted mb-0">Manage DHIS2 server connections and instances</p>
</div>

<!-- Add/Edit Form -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0 text-dark">
            <i class="fas fa-<?php echo $editing_instance ? 'edit' : 'plus'; ?> me-2"></i>
            <?php echo $editing_instance ? 'Edit DHIS2 Instance' : 'Add New DHIS2 Instance'; ?>
        </h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <?php if ($editing_instance): ?>
                <input type="hidden" name="id" value="<?php echo $editing_instance['id']; ?>">
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="url" class="form-label text-dark">DHIS2 Server URL</label>
                    <input type="url" class="form-control" id="url" name="url" 
                           value="<?php echo htmlspecialchars($editing_instance['url'] ?? ''); ?>" 
                           placeholder="https://dhis2.example.com" required>
                    <small class="text-muted">Full URL to your DHIS2 server</small>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="key" class="form-label text-dark">Instance Key</label>
                    <input type="text" class="form-control" id="key" name="key" 
                           value="<?php echo htmlspecialchars($editing_instance['instance_key'] ?? ''); ?>" 
                           placeholder="unique_key" required>
                    <small class="text-muted">Unique identifier for this instance</small>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="username" class="form-label text-dark">Username</label>
                    <input type="text" class="form-control" id="username" name="username" 
                           value="<?php echo htmlspecialchars($editing_instance['username'] ?? ''); ?>" 
                           placeholder="dhis2_user" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="password" class="form-label text-dark">Password</label>
                    <input type="password" class="form-control" id="password" name="password" 
                           placeholder="<?php echo $editing_instance ? 'Leave blank to keep current password' : 'Enter password'; ?>" 
                           <?php echo $editing_instance ? '' : 'required'; ?>>
                </div>
            </div>
            
            <div class="mb-3">
                <label for="description" class="form-label text-dark">Description</label>
                <textarea class="form-control" id="description" name="description" rows="3" 
                          placeholder="Brief description of this DHIS2 instance"><?php echo htmlspecialchars($editing_instance['description'] ?? ''); ?></textarea>
            </div>
            
            <div class="mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="status" name="status" 
                           <?php echo ($editing_instance['status'] ?? 1) ? 'checked' : ''; ?>>
                    <label class="form-check-label text-dark" for="status">
                        Active (Enable this instance for use)
                    </label>
                </div>
            </div>
            
            <div class="d-flex justify-content-between">
                <?php if ($editing_instance): ?>
                    <a href="?tab=config" class="btn btn-secondary">
                        <i class="fas fa-times me-2"></i>Cancel
                    </a>
                <?php else: ?>
                    <div></div>
                <?php endif; ?>
                
                <button type="submit" name="<?php echo $editing_instance ? 'update' : 'create'; ?>" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>
                    <?php echo $editing_instance ? 'Update Instance' : 'Create Instance'; ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Instances List -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0 text-dark">
            <i class="fas fa-server me-2"></i>DHIS2 Instances (<?php echo count($instances); ?>)
        </h5>
    </div>
    <div class="card-body">
        <?php if (empty($instances)): ?>
            <div class="text-center py-5">
                <i class="fas fa-server fa-3x text-muted mb-3"></i>
                <h4 class="text-dark">No DHIS2 Instances</h4>
                <p class="text-muted">Create your first DHIS2 instance configuration to get started.</p>
            </div>
        <?php else: ?>
            <?php foreach ($instances as $instance): ?>
                <div class="config-card">
                    <div class="instance-header">
                        <div>
                            <h6 class="text-dark mb-1">
                                <span class="status-indicator <?php echo $instance['status'] ? 'status-active' : 'status-inactive'; ?>"></span>
                                <?php echo htmlspecialchars($instance['description'] ?: $instance['instance_key']); ?>
                            </h6>
                            <p class="text-muted mb-0 small">
                                <i class="fas fa-key me-1"></i><?php echo htmlspecialchars($instance['instance_key']); ?>
                            </p>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-outline-info test-connection" 
                                    data-url="<?php echo htmlspecialchars($instance['url']); ?>"
                                    data-username="<?php echo htmlspecialchars($instance['username']); ?>"
                                    data-password="<?php echo htmlspecialchars($instance['password']); ?>">
                                <i class="fas fa-plug me-1"></i>Test
                            </button>
                            <a href="?tab=config&edit=<?php echo $instance['id']; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-edit"></i>
                            </a>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this instance?');">
                                <input type="hidden" name="id" value="<?php echo $instance['id']; ?>">
                                <button type="submit" name="delete" class="btn btn-sm btn-outline-danger">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <p class="text-muted mb-1"><strong>Server URL:</strong></p>
                            <p class="text-dark small mb-2"><?php echo htmlspecialchars($instance['url']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted mb-1"><strong>Username:</strong></p>
                            <p class="text-dark small mb-2"><?php echo htmlspecialchars($instance['username']); ?></p>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <p class="text-muted mb-1"><strong>Status:</strong></p>
                            <span class="badge <?php echo $instance['status'] ? 'bg-success' : 'bg-danger'; ?>">
                                <?php echo $instance['status'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted mb-1"><strong>Created:</strong></p>
                            <p class="text-dark small mb-0">
                                <i class="fas fa-calendar me-1"></i>
                                <?php echo date('M d, Y H:i', strtotime($instance['created'] ?? 'now')); ?>
                            </p>
                        </div>
                    </div>
                    
                    <div id="test-result-<?php echo $instance['id']; ?>" class="mt-3" style="display: none;"></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle connection testing
    document.querySelectorAll('.test-connection').forEach(button => {
        button.addEventListener('click', function() {
            const url = this.getAttribute('data-url');
            const username = this.getAttribute('data-username');
            const password = this.getAttribute('data-password');
            const instanceId = this.closest('.config-card').querySelector('[id^="test-result-"]').id.split('-')[2];
            const resultDiv = document.getElementById('test-result-' + instanceId);
            
            // Show loading state
            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Testing...';
            resultDiv.style.display = 'block';
            resultDiv.innerHTML = '<div class="connection-test test-loading"><i class="fas fa-hourglass-half me-2"></i>Testing connection to DHIS2 server...</div>';
            
            // Actual DHIS2 API connection test
            fetch('/fbs/admin/dhis2/test_connection.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    url: url,
                    username: username,
                    password: password
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    resultDiv.innerHTML = `<div class="connection-test test-success"><i class="fas fa-check-circle me-2"></i>${data.message}</div>`;
                } else {
                    resultDiv.innerHTML = `<div class="connection-test test-error"><i class="fas fa-times-circle me-2"></i>${data.message}</div>`;
                }
                
                // Hide result after 5 seconds
                setTimeout(() => {
                    resultDiv.style.display = 'none';
                }, 5000);
            })
            .catch(error => {
                console.error('Test connection error:', error);
                resultDiv.innerHTML = '<div class="connection-test test-error"><i class="fas fa-times-circle me-2"></i>Network error occurred during connection test.</div>';
                
                // Hide result after 5 seconds
                setTimeout(() => {
                    resultDiv.style.display = 'none';
                }, 5000);
            })
            .finally(() => {
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-plug me-1"></i>Test';
            });
        });
    });
});
</script>