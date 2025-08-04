<?php
session_start();
require_once 'connect.php'; // Use require_once to ensure it's included only once

global $pdo; // Access the PDO connection established in connect.php

$error = ""; // Variable to store error messages
$username_value = ""; // To retain username value on error
$email_value = "";    // To retain email value on error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(htmlspecialchars($_POST['username']));
    $password = trim($_POST['password']); // Password not htmlspecialchars'd here as it's hashed
    $email = trim(htmlspecialchars($_POST['email']));
    $confirm_password = trim($_POST['confirmPassword']); // For comparison

    // Retain values for form repopulation on error
    $username_value = $username;
    $email_value = $email;

    // Server-side validation
    if (empty($username) || empty($password) || empty($email) || empty($confirm_password)) {
        $error = "All fields are required.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($username) < 3) {
        $error = "Username must be at least 3 characters long.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        try {
            // Check if the username already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE username = ?");
            $stmt->execute([$username]);
            $userExists = $stmt->fetchColumn();

            if ($userExists > 0) {
                $error = "Username already exists. Please choose another one.";
            } else {
                // Check if the email already exists
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE email = ?");
                $stmt->execute([$email]);
                $emailExists = $stmt->fetchColumn();

                if ($emailExists > 0) {
                    $error = "Email address is already registered. Please use another one or log in.";
                } else {
                    // Hash the password securely
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Check if this is the first user (will become Super Admin)
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_users");
                    $stmt->execute();
                    $userCount = $stmt->fetchColumn();
                    
                    // First user gets Super Admin role (role_id = 1), others get Admin role (role_id = 2)
                    $role_id = ($userCount == 0) ? 1 : 2;
                    $role_name = ($userCount == 0) ? 'Super Administrator' : 'Administrator';

                    // Insert new admin user into the database with appropriate role
                    $stmt = $pdo->prepare("INSERT INTO admin_users (username, password, email, role_id, status) VALUES (?, ?, ?, ?, 1)");
                    $success = $stmt->execute([$username, $hashed_password, $email, $role_id]);

                    if ($success) {
                        // Set a success message in session before redirecting
                        if ($userCount == 0) {
                            $_SESSION['success_message'] = "Super Administrator account created successfully! You now have full system access. Please log in.";
                        } else {
                            $_SESSION['success_message'] = "Administrator account created successfully! Please log in.";
                        }
                        header("Location: login.php"); // Redirect to the login page
                        exit(); // Crucial to stop script execution after redirect
                    } else {
                        $error = "Error: Registration failed. Please try again.";
                    }
                }
            }
        } catch (PDOException $e) {
            // Log the actual database error for debugging
            error_log("Registration PDO Error: " . $e->getMessage());
            $error = "A database error occurred during registration. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - FormBase</title>
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

        .register-container {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 480px;
        }

        .register-card {
            background: var(--surface);
            border-radius: 20px;
            box-shadow: var(--shadow-xl);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
        }

        .register-header {
            background: linear-gradient(135deg, var(--secondary) 0%, var(--accent) 100%);
            padding: 40px 40px 30px;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .register-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grid" width="10" height="10" patternUnits="userSpaceOnUse"><path d="M 10 0 L 0 0 0 10" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="0.5"/></pattern></defs><rect width="100%" height="100%" fill="url(%23grid)"/></svg>');
        }

        .register-header .logo {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }

        .register-header .subtitle {
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

        .form-control.error-border { /* New class for JavaScript validation errors */
            border-color: var(--error);
        }

        .form-control.success-border { /* New class for JavaScript validation success */
            border-color: var(--success);
        }

        .password-strength {
            margin-top: 8px;
            font-size: 12px;
        }

        .strength-bar {
            height: 4px;
            background: var(--border);
            border-radius: 2px;
            overflow: hidden;
            margin-top: 4px;
        }

        .strength-fill {
            height: 100%;
            transition: all 0.3s ease;
            border-radius: 2px;
        }

        .strength-weak { background: var(--error); width: 25%; }
        .strength-fair { background: var(--warning); width: 50%; }
        .strength-good { background: var(--accent); width: 75%; }
        .strength-strong { background: var(--success); width: 100%; }

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

        .divider {
            text-align: center;
            margin: 32px 0;
            position: relative;
            color: var(--text-muted);
            font-size: 14px;
        }

        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: var(--border);
        }

        .divider span {
            background: var(--surface);
            padding: 0 16px;
            position: relative;
            z-index: 2;
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
            .register-card {
                margin: 10px;
                border-radius: 16px;
            }

            .register-header {
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

    <div class="register-container">
        <div class="register-card">
            <div class="register-header">
                <div class="logo">FormBase</div>
                <div class="subtitle">Create your professional account</div>
            </div>

            <div class="form-container">
                <?php if (!empty($error)): ?>
                <div id="phpErrorAlert" class="form-message error-text" style="margin-bottom: 20px; display: flex;">
                    <i class="fas fa-exclamation-circle"></i>
                    <span id="phpErrorText"><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

                <form id="registrationForm" method="POST" action="register.php">
                    <div class="form-group">
                        <label class="form-label" for="username">Username</label>
                        <div class="input-group">
                            <input class="form-control" type="text" name="username" id="username" placeholder="Enter your username" required value="<?php echo htmlspecialchars($username_value); ?>">
                            <i class="fas fa-user input-icon"></i>
                        </div>
                        <div id="usernameError" class="form-message error-text" style="display: none;">
                            <i class="fas fa-times-circle"></i>
                            <span>Username must be at least 3 characters long.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="email">Email Address</label>
                        <div class="input-group">
                            <input class="form-control" type="email" name="email" id="email" placeholder="Enter your email" required value="<?php echo htmlspecialchars($email_value); ?>">
                            <i class="fas fa-envelope input-icon"></i>
                        </div>
                        <div id="emailError" class="form-message error-text" style="display: none;">
                            <i class="fas fa-times-circle"></i>
                            <span>Please enter a valid email address.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password">Password</label>
                        <div class="input-group">
                            <input class="form-control" type="password" name="password" id="password" placeholder="Create a strong password" required>
                            <i class="fas fa-lock input-icon"></i>
                        </div>
                        <div class="password-strength">
                            <div class="strength-bar">
                                <div class="strength-fill" id="strengthFill"></div>
                            </div>
                            <div id="strengthText" style="margin-top: 4px; font-size: 12px; color: var(--text-muted);"></div>
                        </div>
                        <div id="passwordErrorMsg" class="form-message error-text" style="display: none;">
                            <i class="fas fa-times-circle"></i>
                            <span>Password must be at least 8 characters and include uppercase, lowercase, numbers, and symbols.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="confirmPassword">Confirm Password</label>
                        <div class="input-group">
                            <input class="form-control" type="password" name="confirmPassword" id="confirmPassword" placeholder="Confirm your password" required>
                            <i class="fas fa-lock input-icon"></i>
                        </div>
                        <div id="confirmPasswordError" class="form-message error-text" style="display: none;">
                            <i class="fas fa-times-circle"></i>
                            <span>Passwords do not match.</span>
                        </div>
                        <div id="confirmPasswordSuccess" class="form-message success-text" style="display: none;">
                            <i class="fas fa-check-circle"></i>
                            <span>Passwords match!</span>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <span id="submitText">Create Account</span>
                        <div class="loading-spinner" id="loadingSpinner"></div>
                    </button>
                </form>

                <div class="auth-links">
                    <p style="color: var(--text-muted); margin-bottom: 8px;">Already have an account?</p>
                    <a href="login.php">Sign in here</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        const form = document.getElementById('registrationForm');
        const usernameInput = document.getElementById('username');
        const emailInput = document.getElementById('email');
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirmPassword');
        const submitBtn = document.getElementById('submitBtn');
        const submitText = document.getElementById('submitText');
        const loadingSpinner = document.getElementById('loadingSpinner');
        const phpErrorAlert = document.getElementById('phpErrorAlert');

        // Helper function to show/hide validation messages and apply borders
        function setValidationState(inputElement, errorElement, successElement, isValid, errorMessage = '') {
            if (isValid) {
                inputElement.classList.remove('error-border');
                inputElement.classList.add('success-border');
                if (errorElement) errorElement.style.display = 'none';
                if (successElement) successElement.style.display = 'flex';
            } else {
                inputElement.classList.remove('success-border');
                inputElement.classList.add('error-border');
                if (errorElement) {
                    errorElement.querySelector('span').textContent = errorMessage || errorElement.querySelector('span').textContent;
                    errorElement.style.display = 'flex';
                }
                if (successElement) successElement.style.display = 'none';
            }
        }

        // Password strength checker
        function checkPasswordStrength(password) {
            const strengthFill = document.getElementById('strengthFill');
            const strengthText = document.getElementById('strengthText');
            
            let strength = 0;
            let text = 'Too short';
            
            // Criteria:
            // 1. Minimum 8 characters
            // 2. Contains lowercase
            // 3. Contains uppercase
            // 4. Contains number
            // 5. Contains special character
            
            if (password.length >= 8) strength++;
            if (password.match(/[a-z]/)) strength++;
            if (password.match(/[A-Z]/)) strength++;
            if (password.match(/[0-9]/)) strength++;
            if (password.match(/[^a-zA-Z0-9]/)) strength++;
            
            strengthFill.className = 'strength-fill'; // Reset classes
            
            switch(strength) {
                case 0:
                case 1:
                    strengthFill.classList.add('strength-weak');
                    text = 'Weak';
                    break;
                case 2:
                    strengthFill.classList.add('strength-fair');
                    text = 'Fair';
                    break;
                case 3:
                case 4:
                    strengthFill.classList.add('strength-good');
                    text = 'Good';
                    break;
                case 5:
                    strengthFill.classList.add('strength-strong');
                    text = 'Strong!';
                    break;
            }
            
            strengthText.textContent = text;
            return strength;
        }

        // Real-time validation for Username
        usernameInput.addEventListener('input', function() {
            const username = this.value.trim();
            const usernameError = document.getElementById('usernameError');
            
            if (username.length === 0) {
                this.classList.remove('error-border', 'success-border');
                usernameError.style.display = 'none';
            } else if (username.length < 3) {
                setValidationState(this, usernameError, null, false);
            } else {
                setValidationState(this, usernameError, null, true);
            }
            if (phpErrorAlert) phpErrorAlert.style.display = 'none'; // Hide PHP error on user input
        });

        // Real-time validation for Email
        emailInput.addEventListener('input', function() {
            const email = this.value.trim();
            const emailError = document.getElementById('emailError');
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            
            if (email.length === 0) {
                this.classList.remove('error-border', 'success-border');
                emailError.style.display = 'none';
            } else if (!emailRegex.test(email)) {
                setValidationState(this, emailError, null, false);
            } else {
                setValidationState(this, emailError, null, true);
            }
            if (phpErrorAlert) phpErrorAlert.style.display = 'none'; // Hide PHP error on user input
        });

        // Real-time validation for Password
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            const passwordErrorMsg = document.getElementById('passwordErrorMsg');
            const strength = checkPasswordStrength(password);
            
            if (password.length === 0) {
                this.classList.remove('error-border', 'success-border');
                passwordErrorMsg.style.display = 'none';
                document.getElementById('strengthFill').style.width = '0%'; // Reset strength bar
                document.getElementById('strengthText').textContent = '';
            } else if (strength < 4) { // Consider 'Good' or 'Strong' as valid for submission
                setValidationState(this, passwordErrorMsg, null, false);
                passwordErrorMsg.querySelector('span').textContent = 'Password needs to be stronger (min 8 chars, mixed case, numbers, symbols).';
            } else {
                setValidationState(this, passwordErrorMsg, null, true);
            }
            
            // Re-validate confirm password if it's not empty
            if (confirmPasswordInput.value.length > 0) {
                validateConfirmPassword();
            }
            if (phpErrorAlert) phpErrorAlert.style.display = 'none'; // Hide PHP error on user input
        });

        // Real-time validation for Confirm Password
        function validateConfirmPassword() {
            const password = passwordInput.value;
            const confirmPassword = confirmPasswordInput.value;
            const confirmPasswordError = document.getElementById('confirmPasswordError');
            const confirmPasswordSuccess = document.getElementById('confirmPasswordSuccess');
            
            if (confirmPassword.length === 0) {
                confirmPasswordInput.classList.remove('error-border', 'success-border');
                confirmPasswordError.style.display = 'none';
                confirmPasswordSuccess.style.display = 'none';
            } else if (password !== confirmPassword) {
                setValidationState(confirmPasswordInput, confirmPasswordError, confirmPasswordSuccess, false);
            } else {
                setValidationState(confirmPasswordInput, confirmPasswordError, confirmPasswordSuccess, true);
            }
        }
        confirmPasswordInput.addEventListener('input', validateConfirmPassword);


        // Form submission handler
        form.addEventListener('submit', function(e) {
            // Re-run all validations before final submission
            let isValid = true;

            // Username validation
            if (usernameInput.value.trim().length < 3) {
                setValidationState(usernameInput, document.getElementById('usernameError'), null, false);
                isValid = false;
            } else {
                setValidationState(usernameInput, document.getElementById('usernameError'), null, true);
            }

            // Email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(emailInput.value.trim())) {
                setValidationState(emailInput, document.getElementById('emailError'), null, false);
                isValid = false;
            } else {
                setValidationState(emailInput, document.getElementById('emailError'), null, true);
            }

            // Password strength validation (client-side enforcement of a 'good' or 'strong' password)
            if (checkPasswordStrength(passwordInput.value) < 4) { // Enforce 'Good' or 'Strong'
                setValidationState(passwordInput, document.getElementById('passwordErrorMsg'), null, false);
                document.getElementById('passwordErrorMsg').querySelector('span').textContent = 'Password needs to be stronger (min 8 chars, mixed case, numbers, symbols).';
                isValid = false;
            } else {
                setValidationState(passwordInput, document.getElementById('passwordErrorMsg'), null, true);
            }

            // Confirm Password validation
            if (passwordInput.value !== confirmPasswordInput.value) {
                setValidationState(confirmPasswordInput, document.getElementById('confirmPasswordError'), document.getElementById('confirmPasswordSuccess'), false);
                isValid = false;
            } else if (confirmPasswordInput.value.length === 0) { // Check if confirm password is empty
                setValidationState(confirmPasswordInput, document.getElementById('confirmPasswordError'), document.getElementById('confirmPasswordSuccess'), false, 'Please confirm your password.');
                isValid = false;
            }
            else {
                setValidationState(confirmPasswordInput, document.getElementById('confirmPasswordError'), document.getElementById('confirmPasswordSuccess'), true);
            }

            if (!isValid) {
                e.preventDefault(); // Prevent form submission
                if (phpErrorAlert) phpErrorAlert.style.display = 'none'; // Hide any PHP error if JS validation fails
            } else {
                // If all client-side validations pass, show loading state
                submitBtn.disabled = true;
                submitText.style.display = 'none';
                loadingSpinner.style.display = 'block';
                if (phpErrorAlert) phpErrorAlert.style.display = 'none'; // Hide any PHP error before submission
            }
        });
    </script>
</body>
</html>