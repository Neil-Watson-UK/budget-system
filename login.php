<?php
// login.php - FIXED VERSION with proper variable scope
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Simple session start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

function getDB() {
    return getDBConnection();
}

// Initialize error variable
$error = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($username && $password) {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                // Login success - update last_login
                $pdo->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?")->execute([$user['id']]);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['display_name'] = $user['display_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['region'] = $user['region'] ?? null;
                $_SESSION['email'] = $user['email'] ?? null;
                
                // CRITICAL: Redirect sales_person users to salesout FIRST (before any redirect parameter)
                $userRole = isset($user['role']) ? trim(strtolower($user['role'])) : '';
                if ($userRole === 'sales_person') {
                    header("Location: salesout/index.php");
                    exit;
                }
                
                // For other users, check redirect parameter
                $redirect = $_GET['redirect'] ?? '';
                if ($redirect && preg_match('/^[a-z0-9_\/\-\.?=&]+$/i', $redirect)) {
                    header("Location: $redirect");
                } elseif (!empty($user['region'])) {
                    // Users with a region default to their regional view
                    header("Location: regional_view.php?region=" . urlencode($user['region']));
                } else {
                    header("Location: index.php");
                }
                exit;
            } else {
                $error = "Invalid username or password";
            }
        } catch (Exception $e) {
            $error = "Database error: " . $e->getMessage();
        }
    } else {
        $error = "Please enter both username and password";
    }
}

// Check if already logged in
if (isset($_SESSION['user_id'])) {
    // Redirect sales_person users to salesout (check first, before any other redirects)
    $userRole = isset($_SESSION['role']) ? trim(strtolower($_SESSION['role'])) : '';
    if ($userRole === 'sales_person') {
        header("Location: salesout/index.php");
        exit;
    }
    
    $redirect = $_GET['redirect'] ?? '';
    if ($redirect && preg_match('/^[a-z0-9_\/\-\.?=&]+$/i', $redirect)) {
        header("Location: $redirect");
    } elseif (!empty($_SESSION['region'])) {
        header("Location: regional_view.php?region=" . urlencode($_SESSION['region']));
    } else {
        header("Location: index.php");
    }
    exit;
}

// Store the error message in a variable that will be accessible
$display_error = $error;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= defined('APP_NAME') ? APP_NAME : 'Budget System' ?></title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Tabler Core CSS (CDN) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    
    <!-- Tabler Icons (CDN) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.40.0/tabler-icons.min.css">
    
    <!-- Custom styles for login -->
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #f5f7fb 0%, #e4edf5 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            width: 100%;
            max-width: 420px;
            animation: fadeIn 0.5s ease-out;
        }
        
        .login-card {
            border-radius: 16px;
            box-shadow: 0 12px 48px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.3);
            overflow: hidden;
            background: white;
        }
        
        .login-header {
            background: linear-gradient(135deg, #00a399 0%, #00353d 100%);
            color: white;
            padding: 2.5rem 2rem;
            text-align: center;
            position: relative;
        }
        
        .login-header::after {
            content: '';
            position: absolute;
            bottom: -20px;
            left: 50%;
            transform: translateX(-50%);
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 50%;
        }
        
        .login-logo {
            height: 70px;
            margin-bottom: 1.25rem;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
        }
        
        .login-header h4 {
            font-weight: 700;
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
            letter-spacing: -0.5px;
        }
        
        .login-header p {
            opacity: 0.9;
            font-weight: 400;
            font-size: 0.95rem;
        }
        
        .login-body {
            padding: 3rem 2.5rem 2.5rem;
            position: relative;
            z-index: 1;
        }
        
        .form-label {
            font-weight: 600;
            color: #2a3547;
            margin-bottom: 0.75rem;
            font-size: 0.95rem;
            display: block;
        }
        
        .input-group {
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid #e1e5eb;
            transition: all 0.2s ease;
            margin-bottom: 1.5rem;
        }
        
        .input-group:focus-within {
            border-color: #00a399;
            box-shadow: 0 0 0 3px rgba(0, 163, 153, 0.15);
            transform: translateY(-1px);
        }
        
        .input-group-text {
            background: #f8f9fa;
            border: none;
            padding: 0 1rem;
            color: #6c757d;
        }
        
        .form-control {
            border: none;
            padding: 1rem;
            font-size: 1rem;
            background: white;
        }
        
        .form-control:focus {
            box-shadow: none;
            background: white;
        }
        
        /* Updated Button Styles - White Text */
        .btn-primary {
            background: linear-gradient(135deg, #00a399 0%, #00353d 100%);
            border: none;
            padding: 1rem;
            border-radius: 10px;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.2s ease;
            margin-top: 0.5rem;
            width: 100%;
            color: white !important;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #009389 0%, #002a30 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 163, 153, 0.3);
            color: white !important;
        }
        
        .btn-primary:active {
            transform: translateY(0);
            color: white !important;
        }
        
        .login-footer {
            text-align: center;
            padding: 1.5rem;
            background: #f8f9fa;
            border-top: 1px solid #e1e5eb;
            color: #6c757d;
            font-size: 0.875rem;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            animation: slideIn 0.3s ease-out;
        }
        
        
        /* Password toggle button styles */
        #togglePassword {
            background-color: #f8f9fa;
            transition: all 0.2s ease;
            padding: 0 1rem;
            border-top-right-radius: 8px;
            border-bottom-right-radius: 8px;
            border-left: 0;
            border-color: #e1e5eb;
        }
        
        #togglePassword:hover {
            background-color: #e9ecef;
            border-color: #ced4da;
        }
        
        #togglePassword:active {
            background-color: #dee2e6;
        }
        
        #togglePassword:focus {
            box-shadow: 0 0 0 3px rgba(0, 163, 153, 0.15);
            z-index: 3;
        }
        
        /* When password is visible, highlight the field slightly */
        #passwordField[type="text"] {
            background-color: rgba(0, 163, 153, 0.03);
            border-color: #00a399;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        /* Responsive adjustments */
        @media (max-width: 576px) {
            .login-container {
                max-width: 100%;
            }
            
            .login-body {
                padding: 2.5rem 1.5rem 2rem;
            }
            
            .login-header {
                padding: 2rem 1.5rem;
            }
            
            .login-header h4 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <!-- Header with Logo -->
            <div class="login-header">
                <img src="https://eposaudioevents.com/budgets/assets/budgettoollogo.svg" 
                     alt="Budget System Logo" 
                     class="login-logo">
                <h4>Budget System</h4>
                <p>Sign in to your account</p>
            </div>
            
            <!-- Login Form -->
            <div class="login-body">
                <?php if (!empty($display_error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="ti ti-alert-circle me-2"></i>
                        <?= htmlspecialchars($display_error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <form method="POST" class="needs-validation" novalidate>
                    <label class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="ti ti-user"></i>
                        </span>
                        <input type="text" 
                               name="username" 
                               class="form-control" 
                               placeholder="Enter your username" 
                               required>
                    </div>
                    
                    <label class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="ti ti-lock"></i>
                        </span>
                        <input type="password" 
                               name="password" 
                               id="passwordField"
                               class="form-control" 
                               placeholder="Enter your password" 
                               required>
                        <button type="button" 
                                class="btn btn-outline-secondary" 
                                id="togglePassword">
                            <i class="ti ti-eye" id="passwordIcon"></i>
                        </button>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-login me-2"></i>Sign In
                    </button>
                    
                   
                </form>
            </div>
            
            <!-- Footer -->
            <div class="login-footer">
                <div class="small">
                    &copy; <?= date('Y') ?> EPOS
                    <span class="mx-2">•</span>
                    <a href="help_center.php" class="text-decoration-none text-primary">Help Center</a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tabler JavaScript (CDN) -->
    <script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js"></script>
    
    <!-- Bootstrap Bundle (Tabler depends on Bootstrap) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Form validation and auto-hide alerts -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Form validation
            const form = document.querySelector('.needs-validation');
            if (form) {
                form.addEventListener('submit', function(event) {
                    if (!form.checkValidity()) {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                    form.classList.add('was-validated');
                });
            }
            
            // Auto-hide alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
            
            // Focus on username field
            document.querySelector('input[name="username"]')?.focus();
            
            // Show/Hide Password Toggle
            const passwordField = document.getElementById('passwordField');
            const toggleButton = document.getElementById('togglePassword');
            const passwordIcon = document.getElementById('passwordIcon');
            
            if (toggleButton) {
                toggleButton.addEventListener('click', function() {
                    const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordField.setAttribute('type', type);
                    
                    // Toggle icon (Tabler icons)
                    if (type === 'text') {
                        passwordIcon.classList.remove('ti-eye');
                        passwordIcon.classList.add('ti-eye-off');
                        toggleButton.setAttribute('title', 'Hide password');
                    } else {
                        passwordIcon.classList.remove('ti-eye-off');
                        passwordIcon.classList.add('ti-eye');
                        toggleButton.setAttribute('title', 'Show password');
                    }
                });
                
                // Allow toggle with Enter key when focused
                toggleButton.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        this.click();
                    }
                });
                
                // Auto-hide password after 5 seconds if visible
                let hideTimeout;
                toggleButton.addEventListener('click', function() {
                    if (passwordField.type === 'text') {
                        clearTimeout(hideTimeout);
                        hideTimeout = setTimeout(() => {
                            if (passwordField.type === 'text') {
                                passwordField.type = 'password';
                                passwordIcon.classList.remove('ti-eye-off');
                                passwordIcon.classList.add('ti-eye');
                                toggleButton.setAttribute('title', 'Show password');
                            }
                        }, 5000);
                    }
                });
            }
        });
    </script>
</body>
</html>