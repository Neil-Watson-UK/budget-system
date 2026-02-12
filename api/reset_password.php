<?php
// api/save_email.php

// This one line gives us the session, security, config, AND database connection.
require_once __DIR__ . '/../includes/init.php';
// Enable error reporting for debugging (TEMPORARY - REMOVE IN PRODUCTION!)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include the database connection file
require_once 'db_connect.php'; // Adjust path if db_connect.php is in a different directory

$message = '';
$token_valid = false;
$email_from_token = '';

// Check if a token is present in the URL
if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = $_GET['token'];

    // Validate the token against the database
    $stmt_validate = $mysqli->prepare("SELECT email, expires_at FROM password_resets WHERE token = ?");
    $stmt_validate->bind_param("s", $token);
    $stmt_validate->execute();
    $result = $stmt_validate->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $expires_at = strtotime($row['expires_at']);
        $current_time = time();

        if ($current_time < $expires_at) {
            $token_valid = true;
            $email_from_token = $row['email'];
        } else {
            $message = '<p class="error">Password reset link has expired.</p>';
        }
    } else {
        $message = '<p class="error">Invalid password reset link.</p>';
    }
    $stmt_validate->close();
} else {
    $message = '<p class="error">No password reset token provided.</p>';
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token'], $_POST['email_from_token'])) {
    $submitted_token = $_POST['token'];
    $submitted_email = $_POST['email_from_token'];
    $new_password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Re-validate token to prevent direct POSTing without valid token
    $stmt_revalidate = $mysqli->prepare("SELECT email, expires_at FROM password_resets WHERE token = ? AND email = ?");
    $stmt_revalidate->bind_param("ss", $submitted_token, $submitted_email);
    $stmt_revalidate->execute();
    $revalidate_result = $stmt_revalidate->get_result();

    if ($revalidate_result->num_rows > 0) {
        $revalidate_row = $revalidate_result->fetch_assoc();
        $revalidate_expires_at = strtotime($revalidate_row['expires_at']);
        $current_time = time();

        if ($current_time < $revalidate_expires_at) {
            // Token is still valid, proceed with password change
            if (empty($new_password) || empty($confirm_password)) {
                $message = '<p class="error">All password fields are required.</p>';
            } elseif ($new_password !== $confirm_password) {
                $message = '<p class="error">Passwords do not match.</p>';
            } elseif (strlen($new_password) < 8) {
                $message = '<p class="error">Password must be at least 8 characters long.</p>';
            } else {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                // Update user's password
                $stmt_update_password = $mysqli->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
                $stmt_update_password->bind_param("ss", $hashed_password, $submitted_email);

                if ($stmt_update_password->execute()) {
                    // Invalidate the token after successful password reset
                    $stmt_invalidate_token = $mysqli->prepare("DELETE FROM password_resets WHERE token = ?");
                    $stmt_invalidate_token->bind_param("s", $submitted_token);
                    $stmt_invalidate_token->execute();
                    $stmt_invalidate_token->close();

                    $message = '<p class="success">Your password has been reset successfully. You can now <a href="index.php">log in</a>.</p>';
                    $token_valid = false; // Hide the form after successful reset
                } else {
                    $message = '<p class="error">Error updating password: ' . htmlspecialchars($stmt_update_password->error) . '</p>';
                }
                $stmt_update_password->close();
            }
        } else {
            $message = '<p class="error">Password reset link has expired. Please request a new one.</p>';
        }
    } else {
        $message = '<p class="error">Invalid or already used password reset link.</p>';
    }
    $stmt_revalidate->close();
}

// Close the database connection when done
if (isset($mysqli) && $mysqli instanceof mysqli) {
    $mysqli->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet"/>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px auto;
            padding: 20px;
            background-image: url('emailposback.svg');
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
        <img alt="EmailPOS" src="https://eposaudioevents.com/emailtemplates/emailpos.svg" style="width:250px;height:auto; margin-bottom: 20px;">
        <h2>Reset Your Password</h2>
        <?php echo $message; // Display messages here ?>

        <?php if ($token_valid): // Only show the form if the token is valid ?>
        <form method="POST" action="reset_password.php">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <input type="hidden" name="email_from_token" value="<?php echo htmlspecialchars($email_from_token); ?>">

            <label for="password">New Password:</label>
            <input type="password" id="password" name="password" required>

            <label for="confirm_password">Confirm New Password:</label>
            <input type="password" id="confirm_password" name="confirm_password" required>

            <button type="submit">Reset Password</button>
        </form>
        <?php endif; ?>

        <a href="index.php" class="back-link">Back to Login</a>
    </div>
</body>
</html>
