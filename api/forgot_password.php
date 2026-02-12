<?php
// api/save_email.php

// This one line gives us the session, security, config, AND database connection.
require_once __DIR__ . '/../includes/init.php';

// Include the database connection file
require_once 'db_connect.php'; // Adjust path if db_connect.php is in a different directory

$message = ''; // To store success or error messages

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $message = '<p class="error">Please enter your email address.</p>';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = '<p class="error">Invalid email format.</p>';
    } else {
        // Check if the email exists in the users table
        $stmt_check_email = $mysqli->prepare("SELECT id, username, name FROM users WHERE email = ?");
        $stmt_check_email->bind_param("s", $email);
        $stmt_check_email->execute();
        $result = $stmt_check_email->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $username = $user['username'];
            $name = $user['name'];

            // Generate a unique token
            $token = bin2hex(random_bytes(32)); // 64-character hex string
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour')); // Token valid for 1 hour

            // Store the token in the password_resets table
            // First, delete any old tokens for this email to keep the table clean
            $stmt_delete_old = $mysqli->prepare("DELETE FROM password_resets WHERE email = ?");
            $stmt_delete_old->bind_param("s", $email);
            $stmt_delete_old->execute();
            $stmt_delete_old->close();

            $stmt_insert_token = $mysqli->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
            $stmt_insert_token->bind_param("sss", $email, $token, $expires);

            if ($stmt_insert_token->execute()) {
                // Construct the reset link
                $reset_link = "https://eposaudioevents.com/emailtemplates/reset_password.php?token=" . $token; // Adjust URL if needed

                // Send the password reset email
                $to = $email;
                $subject = "Password Reset Request for EmailPOS";
                $body = "Hi " . htmlspecialchars($name) . ",\n\n"
                      . "You have requested a password reset for your EmailPOS account.\n"
                      . "Please click on the following link to reset your password:\n\n"
                      . $reset_link . "\n\n"
                      . "This link will expire in 1 hour.\n\n"
                      . "If you did not request a password reset, please ignore this email.\n\n"
                      . "Thank you!";
                $headers = "From: no-reply@eposaudioevents.com\r\n" .
                           "Reply-To: no-reply@eposaudioevents.com\r\n" .
                           "X-Mailer: PHP/" . phpversion();

                if (mail($to, $subject, $body, $headers)) {
                    $message = '<p class="success">A password reset link has been sent to your email address. Please check your inbox (and spam folder).</p>';
                } else {
                    $message = '<p class="error">Failed to send the password reset email. Please try again later.</p>';
                }
            } else {
                $message = '<p class="error">Error generating reset token: ' . htmlspecialchars($stmt_insert_token->error) . '</p>';
            }
            $stmt_insert_token->close();
        } else {
            $message = '<p class="error">No account found with that email address.</p>';
        }
        $stmt_check_email->close();
    }
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
    <title>Forgot Password</title>
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
        input[type="email"] {
            width: calc(100% - 22px);
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        button {
            background-color: #007bff; /* Blue for action */
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            margin-top: 10px;
        }
        button:hover {
            background-color: #0056b3;
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
        <h2>Forgot Password</h2>
        <?php echo $message; // Display messages here ?>
        <form method="POST" action="forgot_password.php">
            <label for="email">Enter your email address:</label>
            <input type="email" id="email" name="email" required>
            <button type="submit">Send Reset Link</button>
        </form>
        <a href="index.php" class="back-link">Back to Login</a>
    </div>
</body>
</html>
