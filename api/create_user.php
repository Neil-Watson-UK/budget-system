<?php
// api/create_user.php - Handles creation of new users by administrators.

// This one line gives us the session, security, config, AND database connection.
// init.php already calls session_start() and includes db_connect.php.
require_once __DIR__ . '/../includes/init.php';

// --- REMOVED: Redundant error reporting and session start, handled by init.php ---
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
// session_start();
// require_once 'db_connect.php';
// --- END REMOVED ---

$message = ''; // To store success or error messages
$new_username = ''; // Initialize to empty for form fields
$new_email = '';    // Initialize to empty for form fields
$new_name = '';     // Initialize new 'name' field

// --- Security Check: Only logged-in ADMIN users can access this page ---
// Redirect to login page if not logged in, or if not an admin. Use BASE_APP_URL for consistency.
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['user_level']) || $_SESSION['user_level'] !== 'admin') {
    header('Location: ' . BASE_APP_URL . 'index.php');
    exit;
}
// --- End Security Check ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_username = trim($_POST['username'] ?? '');
    $new_email = trim($_POST['email'] ?? '');
    $new_password = $_POST['password'] ?? ''; // Keep plain text password for email, but hash for DB
    $confirm_password = $_POST['confirm_password'] ?? '';
    $new_name = trim($_POST['name'] ?? ''); // Get the new 'name' field
    $current_timestamp = date('Y-m-d H:i:s');

    // Input Validation
    if (empty($new_username) || empty($new_email) || empty($new_password) || empty($confirm_password) || empty($new_name)) { // Added new_name to validation
        $message = '<p class="error">All fields are required.</p>';
        // Clear fields on error
        $new_username = '';
        $new_email = '';
        $new_name = ''; // Clear new 'name' field on error
    } elseif ($new_password !== $confirm_password) {
        $message = '<p class="error">Passwords do not match.</p>';
        // Clear fields on error
        $new_username = '';
        $new_email = '';
        $new_name = ''; // Clear new 'name' field on error
    } elseif (strlen($new_password) < 8) { // Example: minimum password length
        $message = '<p class="error">Password must be at least 8 characters long.</p>';
        // Clear fields on error
        $new_username = '';
        $new_email = '';
        $new_name = ''; // Clear new 'name' field on error
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $message = '<p class="error">Invalid email format.</p>';
        // Clear fields on error
        $new_username = '';
        $new_email = '';
        $new_name = ''; // Clear new 'name' field on error
    } else {
        // Check if username or email already exists
        $stmt_check = $mysqli->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt_check->bind_param("ss", $new_username, $new_email);
        $stmt_check->execute();
        $stmt_check->store_result();

        if ($stmt_check->num_rows > 0) {
            $message = '<p class="error">Username or Email already exists.</p>';
            // Clear fields on error
            $new_username = '';
            $new_email = '';
            $new_name = ''; // Clear new 'name' field on error
        } else {
            // Hash the password before storing in the database
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            // Insert new user into the database - Added 'name' column
            // IMPORTANT: You will need to add a 'name' column to your 'users' table in your database.
            // Example SQL: ALTER TABLE users ADD COLUMN name VARCHAR(255) AFTER username;
            // The default user_level is 'user', so no need to specify it here
            $stmt = $mysqli->prepare("INSERT INTO users (username, name, email, password_hash, created_at) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $new_username, $new_name, $new_email, $hashed_password, $current_timestamp); // Added $new_name to bind_param

            if ($stmt->execute()) {
                $message = '<p class="success">User "' . htmlspecialchars($new_username) . '" created successfully!</p>';

                // --- Email Confirmation Logic ---
                $to = $new_email;
                $subject = "Your login details for EmailPOS";
                // IMPORTANT: Use BASE_APP_URL for the login link for consistency and HTTPS.
                $login_link = BASE_APP_URL . "index.php";
                $body = "Hi " . htmlspecialchars($new_name) . ",\n\n" // Used new_name in greeting
                      . "Your account for EmailPOS has been created.\n\n"
                      . "Username: " . htmlspecialchars($new_username) . "\n"
                      . "Password: " . htmlspecialchars($new_password) . "\n\n" // WARNING: Sending plain text password is a security risk.
                                                                           // Consider sending a password reset link instead.
                      . "Login here: " . $login_link . "\n\n"
                      . "Thank you!";
                $headers = "From: no-reply@eposaudioevents.com\r\n" . // Consider making this dynamic if needed
                           "Reply-To: no-reply@eposaudioevents.com\r\n" .
                           "X-Mailer: PHP/" . phpversion();

                // Attempt to send the email
                if (mail($to, $subject, $body, $headers)) {
                    $message .= '<p class="success">A confirmation email has been sent to ' . htmlspecialchars($new_email) . '.</p>';
                } else {
                    $message .= '<p class="error">Failed to send confirmation email. Please check your server\'s mail configuration.</p>';
                }
                // --- End Email Confirmation Logic ---

                // Clear form fields after successful submission
                $new_username = '';
                $new_email = '';
                $new_name = ''; // Clear new 'name' field on success
            } else {
                $message = '<p class="error">Error creating user: ' . htmlspecialchars($stmt->error) . '</p>';
                // Clear fields on database error as well
                $new_username = '';
                $new_email = '';
                $new_name = ''; // Clear new 'name' field on error
            }
            $stmt->close();
        }
        $stmt_check->close();
    }
}
// The $mysqli connection is automatically closed when the script finishes due to init.php's setup.
// if (isset($mysqli) && $mysqli instanceof mysqli) {
//     $mysqli->close();
// }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New User</title>
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet"/>
    <link rel="stylesheet" href="<?php echo BASE_APP_URL; ?>assets/css/emailpos.css"/>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px auto;
            padding: 20px;
            /* Use BASE_APP_URL for images */
            background-image: url('<?php echo BASE_APP_URL; ?>emailposback.svg'); /* Assuming emailposback.svg is in the root */
            background-size: cover;
            background-repeat: no-repeat;
            background-attachment: fixed;
            background-position: center center;
            background-color: #f5f5f5;
        }
        .container {
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            width: 400px;
            margin: 50px auto;
            text-align: center;
        }
        h2 {
            color: #333;
            margin-bottom: 20px;
        }
        label {
            display: block;
            text-align: left;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: calc(100% - 22px);
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        button {
            background-color: #28a745; /* Green for success/add */
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            margin-top: 10px;
        }
        button:hover {
            background-color: #218838;
        }
        .success {
            color: green;
            margin-bottom: 15px;
        }
        .error {
            color: red;
            margin-bottom: 15px;
        }
        .back-link {
            display: block;
            margin-top: 20px;
            text-decoration: none;
            color: #007bff;
        }
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <img alt="EmailPOS" src="<?php echo BASE_APP_URL; ?>assets/images/emailpos.svg" style="width:250px;height:auto; margin-bottom: 20px;">
        <h2>Create New User</h2>
        <?php echo $message; // Display messages here ?>
        <form method="POST" action="<?php echo BASE_APP_URL; ?>api/create_user.php">
            <label for="name">Name:</label>
            <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($new_name); ?>" required>

            <label for="username">Username:</label>
            <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($new_username); ?>" required>

            <label for="email">Email:</label>
            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($new_email); ?>" required>

            <label for="password">Password:</label>
            <input type="password" id="password" name="password" required>

            <label for="confirm_password">Confirm Password:</label>
            <input type="password" id="confirm_password" name="confirm_password" required>

            <button type="submit">Create User</button>
        </form>
        <a href="<?php echo BASE_APP_URL; ?>emailpos.php" class="back-link">Back to Editor</a>
    </div>
</body>
</html>