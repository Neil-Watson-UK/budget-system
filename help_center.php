<?php
// help_center.php - Simple Help Center
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Simple database connection (if needed for user-specific help)
function getDB() {
    $host = 'localhost';
    $db   = 'cmmbudget';
    $user = 'budgetadmin';
    $pass = 'NotReevesP13453';
    $charset = 'utf8mb4';
    
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    
    try {
        return new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
}

// Handle contact form submission
$message_sent = false;
$contact_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');
    
    if (empty($name) || empty($email) || empty($message)) {
        $contact_error = "Please fill in all fields";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $contact_error = "Please enter a valid email address";
    } else {
        // For now, just show success message
        // In production, you would save to database or send email
        $message_sent = true;
        
        // Optional: Save to database
        // try {
        //     $pdo = getDB();
        //     $stmt = $pdo->prepare("INSERT INTO help_requests (name, email, message) VALUES (?, ?, ?)");
        //     $stmt->execute([$name, $email, $message]);
        // } catch (Exception $e) {
        //     // Log error but don't show to user
        //     error_log("Help request save failed: " . $e->getMessage());
        // }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help Center - <?= defined('APP_NAME') ? APP_NAME : 'Budget System' ?></title>
    
    <!-- CoreUI CSS -->
    <link rel="stylesheet" href="assets/core/css/coreui.min.css">
    <!-- CoreUI Icons -->
    <link rel="stylesheet" href="assets/core/css/coreui-icons.min.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Custom styles -->
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
            padding: 20px;
        }
        
        .help-container {
            max-width: 1200px;
            margin: 0 auto;
            animation: fadeIn 0.5s ease-out;
        }
        
        .help-header {
            background: linear-gradient(135deg, #00a399 0%, #00353d 100%);
            color: white;
            padding: 3rem 2rem;
            text-align: center;
            border-radius: 16px 16px 0 0;
            margin-bottom: 2rem;
        }
        
        .help-logo {
            height: 80px;
            margin-bottom: 1.5rem;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
        }
        
        .help-header h1 {
            font-weight: 700;
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            letter-spacing: -0.5px;
        }
        
        .help-header p {
            opacity: 0.9;
            font-weight: 400;
            font-size: 1.1rem;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .search-box {
            max-width: 600px;
            margin: 2rem auto 0;
        }
        
        .search-box .input-group {
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid rgba(255, 255, 255, 0.3);
            background: rgba(255, 255, 255, 0.1);
        }
        
        .search-box .form-control {
            background: transparent;
            border: none;
            color: white;
            padding: 1rem 1.5rem;
            font-size: 1rem;
        }
        
        .search-box .form-control::placeholder {
            color: rgba(255, 255, 255, 0.7);
        }
        
        .search-box .btn {
            background: white;
            color: #00a399;
            border: none;
            padding: 0 1.5rem;
            font-weight: 600;
        }
        
        .main-content {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
            margin-bottom: 3rem;
        }
        
        @media (min-width: 992px) {
            .main-content {
                grid-template-columns: 2fr 1fr;
            }
        }
        
        .card {
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid #e1e5eb;
            padding: 1.5rem;
            font-weight: 600;
            color: #2a3547;
            font-size: 1.25rem;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        .category-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #00a399 0%, #00353d 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            color: white;
            font-size: 1.25rem;
        }
        
        .category-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #e1e5eb;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s ease;
        }
        
        .category-item:hover {
            background: #f8f9fa;
            transform: translateX(5px);
            text-decoration: none;
        }
        
        .category-item:last-child {
            border-bottom: none;
        }
        
        .category-content h5 {
            margin-bottom: 0.25rem;
            font-weight: 600;
            color: #2a3547;
        }
        
        .category-content p {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 0;
        }
        
        .faq-item {
            border-bottom: 1px solid #e1e5eb;
            padding: 1.25rem 0;
        }
        
        .faq-item:last-child {
            border-bottom: none;
        }
        
        .faq-question {
            font-weight: 600;
            color: #2a3547;
            margin-bottom: 0.5rem;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .faq-answer {
            color: #6c757d;
            line-height: 1.6;
        }
        
        .contact-form .form-control {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            border: 1px solid #e1e5eb;
            margin-bottom: 1rem;
        }
        
        .contact-form .form-control:focus {
            border-color: #00a399;
            box-shadow: 0 0 0 3px rgba(0, 163, 153, 0.15);
        }
        
        .contact-form textarea {
            min-height: 150px;
            resize: vertical;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #00a399 0%, #00353d 100%);
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #009389 0%, #002a30 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 163, 153, 0.3);
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
        }
        
        .help-footer {
            text-align: center;
            padding: 2rem;
            background: white;
            border-radius: 0 0 16px 16px;
            border-top: 1px solid #e1e5eb;
            color: #6c757d;
        }
        
        .quick-links {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }
        
        .quick-links a {
            color: #00a399;
            text-decoration: none;
            font-weight: 500;
        }
        
        .quick-links a:hover {
            text-decoration: underline;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media (max-width: 768px) {
            .help-header {
                padding: 2rem 1rem;
            }
            
            .help-header h1 {
                font-size: 2rem;
            }
            
            .quick-links {
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="help-container">
        <div class="help-header">
            <img src="https://eposaudioevents.com/budgets/assets/budgettoollogo.svg" 
                 alt="Budget System Logo" 
                 class="help-logo">
            <h1>Help Center</h1>
            <p>Find answers, guides, and support for the Budget System</p>
            
            <div class="search-box">
                <div class="input-group">
                    <input type="text" 
                           class="form-control" 
                           placeholder="Search for help articles, guides, or FAQs...">
                    <button class="btn" type="button">
                        <i class="cil-search"></i> Search
                    </button>
                </div>
            </div>
        </div>
        
        <div class="main-content">
            <!-- Left Column: Main Content -->
            <div>
                <!-- Quick Start Guide -->
                <div class="card">
                    <div class="card-header">
                        <i class="cil-rocket me-2"></i> Quick Start Guide
                    </div>
                    <div class="card-body">
                        <div class="category-item">
                            <div class="category-icon">
                                <i class="cil-chart-line"></i>
                            </div>
                            <div class="category-content">
                                <h5>Dashboard Overview</h5>
                                <p>Learn how to navigate your budget dashboard and understand key metrics</p>
                            </div>
                        </div>
                        
                        <div class="category-item">
                            <div class="category-icon">
                                <i class="cil-cloud-upload"></i>
                            </div>
                            <div class="category-content">
                                <h5>Importing Data</h5>
                                <p>Step-by-step guide to importing CSV files into the system</p>
                            </div>
                        </div>
                        
                        <div class="category-item">
                            <div class="category-icon">
                                <i class="cil-chart-pie"></i>
                            </div>
                            <div class="category-content">
                                <h5>Creating Reports</h5>
                                <p>How to generate and customize budget reports for different regions</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Frequently Asked Questions -->
                <div class="card">
                    <div class="card-header">
                        <i class="cil-question me-2"></i> Frequently Asked Questions
                    </div>
                    <div class="card-body">
                        <div class="faq-item">
                            <div class="faq-question" onclick="toggleFAQ(this)">
                                How do I add a new budget item?
                                <i class="cil-chevron-bottom"></i>
                            </div>
                            <div class="faq-answer" style="display: none;">
                                Navigate to "Add Item" from the dashboard or use the Quick Actions section. 
                                Fill in the required fields including amount, category, and region. 
                                The item will appear in your budget immediately.
                            </div>
                        </div>
                        
                        <div class="faq-item">
                            <div class="faq-question" onclick="toggleFAQ(this)">
                                Can I export data for specific date ranges?
                                <i class="cil-chevron-bottom"></i>
                            </div>
                            <div class="faq-answer" style="display: none;">
                                Yes! Use the Filtered Export feature to select specific date ranges, 
                                regions, or categories. The system supports CSV and Excel formats.
                            </div>
                        </div>
                        
                        <div class="faq-item">
                            <div class="faq-question" onclick="toggleFAQ(this)">
                                How are conversion rates applied?
                                <i class="cil-chevron-bottom"></i>
                            </div>
                            <div class="faq-answer" style="display: none;">
                                Conversion rates are automatically applied based on the selected currency 
                                and date. You can view and manage rates in the Conversion Rates section.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Column: Contact & Resources -->
            <div>
                <!-- Contact Support -->
                <div class="card">
                    <div class="card-header">
                        <i class="cil-envelope-open me-2"></i> Contact Support
                    </div>
                    <div class="card-body">
                        <?php if ($message_sent): ?>
                            <div class="alert alert-success">
                                <i class="cil-check-circle me-2"></i>
                                Thank you! Your message has been sent. We'll respond within 24 hours.
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($contact_error): ?>
                            <div class="alert alert-danger">
                                <i class="cil-warning me-2"></i>
                                <?= htmlspecialchars($contact_error) ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" class="contact-form">
                            <input type="text" 
                                   name="name" 
                                   class="form-control" 
                                   placeholder="Your Name"
                                   value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                                   required>
                            
                            <input type="email" 
                                   name="email" 
                                   class="form-control" 
                                   placeholder="Email Address"
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                   required>
                            
                            <textarea name="message" 
                                      class="form-control" 
                                      placeholder="How can we help you?"
                                      required><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
                            
                            <button type="submit" 
                                    name="contact_submit" 
                                    class="btn btn-primary w-100">
                                <i class="cil-send me-2"></i> Send Message
                            </button>
                        </form>
                        
                        <div class="mt-3 text-center">
                            <small class="text-muted">
                                <i class="cil-clock me-1"></i>
                                Response time: Typically within 24 hours
                            </small>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Resources -->
                <div class="card">
                    <div class="card-header">
                        <i class="cil-link me-2"></i> Quick Resources
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="import.php" class="btn btn-outline-primary">
                                <i class="cil-cloud-upload me-2"></i> Import Guide
                            </a>
                            <a href="reports.php" class="btn btn-outline-primary">
                                <i class="cil-chart-pie me-2"></i> Report Templates
                            </a>
                            <a href="regional_view.php" class="btn btn-outline-primary">
                                <i class="cil-map me-2"></i> Regional Views
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="help-footer">
            <p>Still need help? Check our documentation or contact our support team.</p>
            <div class="quick-links">
                <a href="index.php"><i class="cil-home me-1"></i> Dashboard</a>
                <a href="login.php"><i class="cil-account-login me-1"></i> Login</a>
                <a href="mailto:support@budgetsystem.com"><i class="cil-envelope-letter me-1"></i> Email Support</a>
            </div>
        </div>
    </div>
    
    <!-- CoreUI JavaScript -->
    <script src="assets/core/js/coreui.bundle.min.js"></script>
    
    <!-- Help Center Scripts -->
    <script>
        // Toggle FAQ answers
        function toggleFAQ(element) {
            const answer = element.nextElementSibling;
            const icon = element.querySelector('i');
            
            if (answer.style.display === 'none' || answer.style.display === '') {
                answer.style.display = 'block';
                icon.classList.remove('cil-chevron-bottom');
                icon.classList.add('cil-chevron-top');
            } else {
                answer.style.display = 'none';
                icon.classList.remove('cil-chevron-top');
                icon.classList.add('cil-chevron-bottom');
            }
        }
        
        // Search functionality (simple)
        document.querySelector('.search-box button').addEventListener('click', function() {
            const searchTerm = document.querySelector('.search-box input').value;
            if (searchTerm.trim()) {
                alert('Search for: ' + searchTerm + '\n\nIn a real implementation, this would filter the help content.');
                // In production: filter FAQ and guide items based on search term
            }
        });
        
        // Enter key triggers search
        document.querySelector('.search-box input').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.querySelector('.search-box button').click();
            }
        });
        
        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new coreui.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>
</body>
</html>