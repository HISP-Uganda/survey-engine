<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

require 'connect.php'; // Ensure this file establishes $conn

// try {
//     $conn = new PDO("mysql:host=localhost;dbname=fbtv3;charset=utf8", "root", "root");
//     $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Set error mode to exception
// } catch (PDOException $e) {
//     die("Database connection failed: " . $e->getMessage());
// }

$conn = $pdo; // Use the PDO connection from connect.php

// Handle Create
if (isset($_POST['create'])) {
    $url = $_POST['url'];
    $username = $_POST['username'];
    $password = base64_encode($_POST['password']); // Base64 encode the password
    $key = $_POST['key'];
    $description = $_POST['description'];
    $status = isset($_POST['status']) ? 1 : 0;

    $stmt = $conn->prepare("INSERT INTO dhis2_instances (url, username, password, `key`, description, status) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$url, $username, $password, $key, $description, $status]);
    header("Location: config.php");
    exit();
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

    $stmt = $conn->prepare("UPDATE dhis2_instances SET url=?, username=?, password=?, `key`=?, description=?, status=? WHERE id=?");
    $stmt->execute([$url, $username, $password, $key, $description, $status, $id]);
    header("Location: config.php");
    exit();
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM dhis2_instances WHERE id=?");
    $stmt->execute([$id]);
    header("Location: config.php");
    exit();
}

// Fetch for edit
$edit = null;
if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM dhis2_instances WHERE id=?");
    $stmt->execute([$id]);
    $edit = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Fetch all configs
$stmt = $conn->query("SELECT * FROM dhis2_instances ORDER BY id DESC");
$configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DHIS2 Instances Management</title>
    <link href="argon-dashboard-master/assets/css/nucleo-icons.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/nucleo-svg.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link href="argon-dashboard-master/assets/css/argon-dashboard.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2a5298;
            --secondary-color: #6c757d;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
            --warning-color: #ffc107;
            --light-bg: #f8f9fa;
            --dark-text: #343a40;
            --light-text: #adb5bd;
            --white: #fff;
            --border-color: #dee2e6;
            --box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            --button-hover-opacity: 0.9;
        }

        body {
            font-family: 'Open Sans', sans-serif;
            background-color: var(--light-bg);
            margin: 0;
            padding: 0;
            display: flex; /* Flex container for sidebar and main content */
            min-height: 100vh;
        }

        /* Sidebar Styling (Remains fixed on left) */
        .sidebar {
            width: 250px;
            background-color: var(--primary-color);
            color: var(--white);
            padding-top: 20px;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            position: fixed; /* Keep sidebar fixed */
            height: 100%;
            overflow-y: auto;
            z-index: 1030; /* Ensure sidebar is above other content but below navbar if needed */
        }

        .sidebar a {
            padding: 12px 20px;
            display: block;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        .sidebar a:hover {
            background-color: #3b61a3;
            color: var(--white);
        }

        .sidebar .nav-link.active {
            background-color: #3b61a3;
            color: var(--white);
            font-weight: bold;
        }

        /* Main Content Area */
        .main-content {
            flex-grow: 1;
            margin-left: 250px; /* Pushes main content right to clear fixed sidebar */
            padding: 0; /* Remove padding here, use padding inside container-fluid */
            display: flex;
            flex-direction: column;
        }

        /* Navbar Styling - Keep as is, but ensure it's positioned correctly */
        /* Assuming 'components/navbar.php' provides its own styling */
        .navbar {
            position: sticky; /* Makes navbar stick to the top when scrolling */
            top: 0;
            z-index: 1020; /* Ensure navbar is above main content but below sidebar (if fixed) */
            background-color: var(--white); /* Example: ensure it has a background */
            box-shadow: 0 2px 5px rgba(0,0,0,0.05); /* Optional: add a subtle shadow */
            padding: 15px 20px; /* Example padding */
        }


        .container-fluid.py-4 {
            padding: 30px; /* Apply padding here instead of main-content */
            background: var(--white);
            border-radius: 8px;
            box-shadow: var(--box-shadow);
            margin-top: 20px; /* Add some space below the navbar */
            margin-bottom: 20px;
        }

        h1 {
            text-align: center;
            color: var(--dark-text);
            margin-bottom: 30px;
            font-weight: 600;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            color: var(--white);
            cursor: pointer;
            transition: background-color 0.3s ease, opacity 0.3s ease;
            font-weight: 500;
        }

        .btn-primary { background-color: var(--primary-color); }
        .btn-secondary { background-color: var(--secondary-color); }
        .btn-danger { background-color: var(--danger-color); }
        .btn-warning { background-color: var(--warning-color); color: var(--dark-text); }
        .btn-info { background-color: var(--info-color); }
        .btn-success { background-color: var(--success-color); }

        .btn:hover { opacity: var(--button-hover-opacity); }

        .form-group { margin-bottom: 15px; }
        label {
            display: block;
            margin-bottom: 8px;
            color: var(--dark-text);
            font-weight: bold;
        }
        input[type="text"],
        input[type="password"],
        textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            box-sizing: border-box;
            margin-bottom: 5px;
            font-size: 1rem;
        }
        textarea { resize: vertical; min-height: 80px; }
        input[type="checkbox"] { margin-right: 8px; transform: scale(1.1); }
        .form-check-label { margin-bottom: 0; }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.08);
            border-radius: 8px;
            overflow: hidden;
        }
        th, td {
            border: 1px solid var(--border-color);
            padding: 15px;
            text-align: left;
            vertical-align: middle;
        }
        thead th {
            background-color: var(--primary-color);
            color: var(--white);
            font-weight: bold;
            text-transform: uppercase;
            font-size: 0.9rem;
        }
        tbody tr:nth-child(even) { background-color: var(--light-bg); }
        tbody tr:hover { background-color: #e9ecef; }

        .actions a.btn { margin-right: 5px; font-size: 0.85rem; padding: 6px 10px; }

        #creationSection {
            background-color: var(--light-bg);
            padding: 25px;
            border-radius: 8px;
            border: 1px solid #eee;
            margin-bottom: 30px;
            box-shadow: inset 0 0 10px rgba(0,0,0,0.05);
        }

        .toggle-button-group {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            justify-content: center;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative; /* Make sidebar relative on small screens */
                box-shadow: none;
            }
            .main-content {
                margin-left: 0; /* Remove left margin for main content */
                padding: 15px; /* Add some padding back for small screens */
            }
            .toggle-button-group {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <?php include 'components/aside.php'; ?>

    <div class="main-content position-relative border-radius-lg">
        <?php include 'components/navbar.php'; ?>

        <div class="container-fluid py-4">
            <h1 class="mb-4">Manage DHIS2 Instances</h1>

            <div class="toggle-button-group">
                <button type="button" class="btn btn-primary" id="showCreationBtn" style="<?= $edit ? 'display:none;' : '' ?>">
                    <i class="fas fa-plus-circle me-2"></i> Add New Instance
                </button>
                <button type="button" class="btn btn-secondary" id="hideCreationBtn" style="display:none;">
                    <i class="fas fa-times-circle me-2"></i> Cancel Creation
                </button>
            </div>

            <div id="creationSection" style="<?= $edit ? '' : 'display:none;' ?>">
                <form method="post" id="configForm">
                    <?php if ($edit): ?>
                        <input type="hidden" name="id" value="<?= htmlspecialchars($edit['id']) ?>">
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="url">URL <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="url" name="url" required value="<?= htmlspecialchars($edit['url'] ?? '') ?>" placeholder="e.g., https://dhis2.example.org">
                    </div>
                    <div class="form-group">
                        <label for="username">Username <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="username" name="username" required value="<?= htmlspecialchars($edit['username'] ?? '') ?>" placeholder="DHIS2 Username">
                    </div>
                    <div class="form-group">
                        <label for="password">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="password" name="password" required value="<?= htmlspecialchars($edit['password'] ?? '') ?>" placeholder="DHIS2 Password">
                    </div>
                    <div class="form-group">
                        <label for="key">Key <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="key" name="key" required value="<?= htmlspecialchars($edit['key'] ?? '') ?>" placeholder="Unique Key for Instance">
                    </div>
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea class="form-control" id="description" name="description" placeholder="Brief description of the DHIS2 instance"><?= htmlspecialchars($edit['description'] ?? '') ?></textarea>
                    </div>
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="status" name="status" <?= (isset($edit['status']) && $edit['status']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="status">Active Instance</label>
                    </div>
                    <button type="submit" name="<?= $edit ? 'update' : 'create' ?>" class="btn btn-primary me-2">
                        <i class="fas <?= $edit ? 'fa-save' : 'fa-plus' ?> me-2"></i> <?= $edit ? 'Update Instance' : 'Create Instance' ?>
                    </button>
                    <?php if ($edit): ?>
                        <a href="config.php" class="btn btn-secondary">
                            <i class="fas fa-ban me-2"></i> Cancel
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="toggle-button-group">
                <button type="button" class="btn btn-info" id="showTableBtn">
                    <i class="fas fa-list me-2"></i> View Current Instances
                </button>
                <button type="button" class="btn btn-secondary" id="hideTableBtn" style="display:none;">
                    <i class="fas fa-eye-slash me-2"></i> Hide Instances Table
                </button>
            </div>

            <div id="configTableWrapper" style="display:none;">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>URL</th>
                                <th>Username</th>
                                <th>Key</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($configs)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">No DHIS2 instances configured yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($configs as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['id']) ?></td>
                                        <td><?= htmlspecialchars($row['url']) ?></td>
                                        <td><?= htmlspecialchars($row['username']) ?></td>
                                        <td><?= htmlspecialchars($row['key']) ?></td>
                                        <td><?= htmlspecialchars($row['description']) ?></td>
                                        <td>
                                            <button type="button"
                                                class="btn btn-sm status-toggle-btn <?= $row['status'] ? 'btn-success' : 'btn-danger' ?>"
                                                data-id="<?= $row['id'] ?>"
                                                data-status="<?= $row['status'] ?>"
                                                title="Toggle Status">
                                                <i class="fas <?= $row['status'] ? 'fa-toggle-on' : 'fa-toggle-off' ?>"></i>
                                            </button>
                                        </td>
                                        <td><?= htmlspecialchars($row['created']) ?></td>
                                        <td class="actions">
                                            <a href="config.php?edit=<?= $row['id'] ?>" class="btn btn-warning">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <a href="config.php?delete=<?= $row['id'] ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this DHIS2 instance? This action cannot be undone.')">
                                                <i class="fas fa-trash"></i> Delete
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-4 text-center">
                <a href="survey.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i> Back to Survey Dashboard
                </a>
            </div>
        </div>
        <?php include 'components/fixednav.php'; ?>
    </div>

    <script src="argon-dashboard-master/assets/js/core/popper.min.js"></script>
    <script src="argon-dashboard-master/assets/js/core/bootstrap.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="argon-dashboard-master/assets/js/argon-dashboard.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var showCreationBtn = document.getElementById('showCreationBtn');
            var hideCreationBtn = document.getElementById('hideCreationBtn');
            var creationSection = document.getElementById('creationSection');
            var showTableBtn = document.getElementById('showTableBtn');
            var hideTableBtn = document.getElementById('hideTableBtn');
            var configTableWrapper = document.getElementById('configTableWrapper');

            // Initial state based on whether an edit is in progress
            <?php if ($edit): ?>
                creationSection.style.display = 'block';
                if (showCreationBtn) showCreationBtn.style.display = 'none';
                if (hideCreationBtn) hideCreationBtn.style.display = 'inline-block';
            <?php else: ?>
                creationSection.style.display = 'none';
                if (showCreationBtn) showCreationBtn.style.display = 'inline-block';
                if (hideCreationBtn) hideCreationBtn.style.display = 'none';
            <?php endif; ?>

            if (showCreationBtn) {
                showCreationBtn.addEventListener('click', function() {
                    creationSection.style.display = 'block';
                    showCreationBtn.style.display = 'none';
                    hideCreationBtn.style.display = 'inline-block';
                });
            }

            if (hideCreationBtn) {
                hideCreationBtn.addEventListener('click', function() {
                    creationSection.style.display = 'none';
                    hideCreationBtn.style.display = 'none';
                    showCreationBtn.style.display = 'inline-block';
                });
            }

            if (showTableBtn) {
                showTableBtn.addEventListener('click', function() {
                    configTableWrapper.style.display = 'block';
                    showTableBtn.style.display = 'none';
                    hideTableBtn.style.display = 'inline-block';
                });
            }

            if (hideTableBtn) {
                hideTableBtn.addEventListener('click', function() {
                    configTableWrapper.style.display = 'none';
                    hideTableBtn.style.display = 'none';
                    showTableBtn.style.display = 'inline-block';
                });
            }

            // Status Toggle Button Logic
            document.querySelectorAll('.status-toggle-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id = this.getAttribute('data-id');
                    var currentStatus = this.getAttribute('data-status');
                    var button = this;

                    fetch('toggle_status.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'id=' + encodeURIComponent(id) + '&status=' + encodeURIComponent(currentStatus)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            button.setAttribute('data-status', data.new_status);
                            button.classList.toggle('btn-success', data.new_status == 1);
                            button.classList.toggle('btn-danger', data.new_status == 0);
                            button.querySelector('i').className = 'fas ' + (data.new_status == 1 ? 'fa-toggle-on' : 'fa-toggle-off');
                        } else {
                            alert('Failed to toggle status: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error toggling status:', error);
                        alert('An error occurred while toggling status.');
                    });
                });
            });
        });
    </script>
</body>
</html>