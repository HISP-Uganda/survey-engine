<?php
session_start();
require_once 'connect.php'; // Use require_once for consistency

global $pdo; // Access the PDO connection from connect.php

$error = ""; // Variable to store error messages
$username_value = ""; // To retain username value on failed login

// Check for success message from registration
$success_message = "";
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']); // Clear the message after displaying it
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(htmlspecialchars($_POST['username'])); // Sanitize input
    $password = $_POST['password']; // Password not htmlspecialchars'd here as it's for verification

    // Retain username value for form repopulation on error
    $username_value = $username;

    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        try {
            // Fetch admin user from the database by username
            $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ?");
            $stmt->execute([$username]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($admin && password_verify($password, $admin['password'])) {
                // Login successful
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_username'] = $admin['username'];
                header("Location: main"); // Redirect to your admin dashboard/main page
                exit();
            } else {
                // Login failed - invalid credentials
                $error = "Invalid username or password.";
            }
        } catch (PDOException $e) {
            // Log the actual database error for debugging
            error_log("Login PDO Error: " . $e->getMessage());
            $error = "A database error occurred during login. Please try again later.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - FormBase</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Your provided CSS from the previous design */
        :root {
            --primary: #0F172A;
            --primary-light: #1E293B;
            --primary-dark: #020617;
            --secondary: #3B82F6; /* Adjusted to match your previous blue */
            --secondary-light: #60A5FA;
            --secondary-dark: #1D4ED8;
            --accent: #06B6D4;
            --success: #10B981;
            --error: #EF4444;
            --warning: #F59E0B;
            --background: #F8FAFC;
            --surface: #FFFFFF;
            --surface-secondary: #F1F5F9;
            --text-primary: #0F172A;
            --text-secondary: #475569;
            --text-muted: #64748B;
            --border: #E2E8F0;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><defs><radialGradient id="a" cx="50%" cy="50%"><stop offset="0%" stop-color="rgba(59,130,246,0.1)"/><stop offset="100%" stop-color="transparent"/></radialGradient></defs><circle cx="200" cy="200" r="300" fill="url(%23a)"/><circle cx="800" cy="800" r="400" fill="url(%23a)"/></svg>');
            animation: float 20s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }

        .login-container { /* Renamed from register-container */
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 480px;
        }

        .login-card { /* Renamed from register-card */
            background: var(--surface);
            border-radius: 20px;
            box-shadow: var(--shadow-xl);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
        }

        .login-header { /* Renamed from register-header */
            background: linear-gradient(135deg, var(--secondary) 0%, var(--accent) 100%);
            padding: 40px 40px 30px;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .login-header::before { /* Renamed from register-header */
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grid" width="10" height="10" patternUnits="userSpaceOnUse"><path d="M 10 0 L 0 0 0 10" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="0.5"/></pattern></defs><rect width="100%" height="100%" fill="url(%23grid)"/></svg>');
        }

        .login-header .logo {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }

        .login-header .subtitle {
            font-size: 16px;
            opacity: 0.9;
            font-weight: 400;
            position: relative;
            z-index: 2;
        }

        .form-container {
            padding: 40px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 8px;
            letter-spacing: 0.025em;
        }

        .input-group {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 16px;
            z-index: 2;
        }

        .form-control {
            width: 100%;
            padding: 16px 16px 16px 48px;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 16px;
            font-weight: 400;
            color: var(--text-primary);
            background: var(--surface);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: inherit;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            background: var(--surface);
        }

        .form-control:focus + .input-icon {
            color: var(--secondary);
        }

        /* Combined error/success message style */
        .form-message {
            font-size: 14px;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            animation: slideIn 0.3s ease;
        }
        .form-message.error-text {
            color: var(--error);
        }
        .form-message.success-text {
            color: var(--success);
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .btn {
            width: 100%;
            padding: 16px 24px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            font-family: inherit;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary), var(--secondary-dark));
            color: white;
            box-shadow: var(--shadow-md);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-secondary {
            background: var(--surface-secondary);
            color: var(--text-secondary);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--border);
            color: var(--text-primary);
        }

        .auth-links {
            text-align: center;
            margin-top: 24px;
        }

        .auth-links a {
            color: var(--secondary);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .auth-links a:hover {
            color: var(--secondary-dark);
        }

        .back-button {
            position: absolute;
            top: 20px;
            left: 20px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 12px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
            backdrop-filter: blur(10px);
            z-index: 10;
        }

        .back-button:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-1px);
        }

        .loading-spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top: 2px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive Design */
        @media (max-width: 480px) {
            .login-card {
                margin: 10px;
                border-radius: 16px;
            }

            .login-header {
                padding: 30px 24px 20px;
            }

            .form-container {
                padding: 30px 24px;
            }

            .form-control {
                padding: 14px 14px 14px 44px;
            }

            .btn {
                padding: 14px 20px;
            }
        }
    </style>
</head>
<body>
    <button class="back-button" onclick="window.location.href='../../index.php'">
        <i class="fas fa-arrow-left"></i> Back
    </button>

    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo">FormBase</div>
                <div class="subtitle">Login to your professional account</div>
            </div>

            <div class="form-container">
                <?php if (!empty($success_message)): ?>
                <div id="successAlert" class="form-message success-text" style="margin-bottom: 20px; display: flex;">
                    <i class="fas fa-check-circle"></i>
                    <span id="successText"><?php echo htmlspecialchars($success_message); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                <div id="errorAlert" class="form-message error-text" style="margin-bottom: 20px; display: flex;">
                    <i class="fas fa-exclamation-circle"></i>
                    <span id="errorText"><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

                <form id="loginForm" method="POST" action="login.php">
                    <div class="form-group">
                        <label class="form-label" for="username">Username or Email</label>
                        <div class="input-group">
                            <input class="form-control" type="text" name="username" id="username" placeholder="Enter your username or email" required value="<?php echo htmlspecialchars($username_value); ?>">
                            <i class="fas fa-user input-icon"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password">Password</label>
                        <div class="input-group">
                            <input class="form-control" type="password" name="password" id="password" placeholder="Enter your password" required>
                            <i class="fas fa-lock input-icon"></i>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <span id="submitText">Login</span>
                        <div class="loading-spinner" id="loadingSpinner"></div>
                    </button>
                </form>

                <div class="auth-links">
                    <p style="color: var(--text-muted); margin-bottom: 8px;">Don't have an account?</p>
                    <a href="register.php">Create an account here</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        const loginForm = document.getElementById('loginForm');
        const submitBtn = document.getElementById('submitBtn');
        const submitText = document.getElementById('submitText');
        const loadingSpinner = document.getElementById('loadingSpinner');
        const errorAlert = document.getElementById('errorAlert');
        const successAlert = document.getElementById('successAlert'); // For new success message display

        // Hide PHP error/success messages on user input
        document.getElementById('username').addEventListener('input', function() {
            if (errorAlert) errorAlert.style.display = 'none';
            if (successAlert) successAlert.style.display = 'none';
        });
        document.getElementById('password').addEventListener('input', function() {
            if (errorAlert) errorAlert.style.display = 'none';
            if (successAlert) successAlert.style.display = 'none';
        });

        loginForm.addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value.trim();

            if (username === '' || password === '') {
                e.preventDefault(); // Prevent submission
                if (errorAlert) {
                    document.getElementById('errorText').textContent = "Please enter both username and password.";
                    errorAlert.style.display = 'flex';
                }
            } else {
                // Show loading state
                submitBtn.disabled = true;
                submitText.style.display = 'none';
                loadingSpinner.style.display = 'block';
                if (errorAlert) errorAlert.style.display = 'none';
                if (successAlert) successAlert.style.display = 'none';
            }
        });
    </script>
</body>
</html>