<?php
// api/admin_panel.php - User Management Panel for Administrators

// This one line gives us the session, security, config, AND database connection.
// init.php already calls session_start() and includes db_connect.php.
require_once __DIR__ . '/../includes/init.php';

// --- REMOVE THESE LINES ---
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
// require_once 'db_connect.php'; // This line is removed as init.php already includes db_connect.php
// --- END REMOVE ---

$message = '';

// --- Security Check: Only logged-in ADMIN users can access this page ---
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['user_level']) || $_SESSION['user_level'] !== 'admin') {
    // Redirect to login page if not logged in or not admin. Use BASE_APP_URL for consistency.
    header('Location: ' . BASE_APP_URL . 'index.php');
    exit;
}
// --- End Security Check ---

// Handle actions (delete, change level, send password reset)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $user_id = $_POST['user_id'] ?? null;
        $target_email = $_POST['email'] ?? null; // For password reset email

        switch ($action) {
            case 'delete_user':
                // Prevent deleting the currently logged-in admin user
                if ($user_id && $user_id == $_SESSION['user_id']) {
                    $message = '<p class="error">You cannot delete your own admin account.</p>';
                } else if ($user_id) {
                    $stmt_delete = $mysqli->prepare("DELETE FROM users WHERE id = ?");
                    $stmt_delete->bind_param("i", $user_id);
                    if ($stmt_delete->execute()) {
                        $message = '<p class="success">User deleted successfully.</p>';
                    } else {
                        $message = '<p class="error">Error deleting user: ' . htmlspecialchars($stmt_delete->error) . '</p>';
                    }
                    $stmt_delete->close();
                } else {
                    $message = '<p class="error">Invalid user ID for deletion.</p>';
                }
                break;

            case 'change_level':
                $new_level = $_POST['new_level'] ?? '';
                if (!in_array($new_level, ['admin', 'user'])) {
                    $message = '<p class="error">Invalid user level specified.</p>';
                } else if ($user_id && $user_id == $_SESSION['user_id'] && $new_level === 'user') {
                    $message = '<p class="error">You cannot demote your own admin account.</p>';
                } else if ($user_id) {
                    $stmt_update_level = $mysqli->prepare("UPDATE users SET user_level = ? WHERE id = ?");
                    $stmt_update_level->bind_param("si", $new_level, $user_id);
                    if ($stmt_update_level->execute()) {
                        $message = '<p class="success">User level updated successfully.</p>';
                    } else {
                        $message = '<p class="error">Error updating user level: ' . htmlspecialchars($stmt_update_level->error) . '</p>';
                    }
                    $stmt_update_level->close();
                } else {
                    $message = '<p class="error">Invalid user ID for level change.</p>';
                }
                break;

            case 'send_password_reset':
                if (empty($target_email) || !filter_var($target_email, FILTER_VALIDATE_EMAIL)) {
                    $message = '<p class="error">Invalid email address for password reset.</p>';
                    break;
                }

                // Generate a unique token
                $token = bin2hex(random_bytes(32)); // 64 character hex string
                $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour')); // Token valid for 1 hour

                // Check if a reset token already exists for this email and delete it
                $stmt_check_token = $mysqli->prepare("DELETE FROM password_resets WHERE email = ?");
                $stmt_check_token->bind_param("s", $target_email);
                $stmt_check_token->execute();
                $stmt_check_token->close();


                // Store the token in the database
                $stmt_insert_token = $mysqli->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
                $stmt_insert_token->bind_param("sss", $target_email, $token, $expires_at);

                if ($stmt_insert_token->execute()) {
                    // Send the email with the reset link
                    // IMPORTANT: Replace hardcoded domain with BASE_APP_URL for flexibility.
                    $reset_link = BASE_APP_URL . "emailtemplates/reset_password.php?token=" . urlencode($token);
                    $subject = "Password Reset Request for Your EmailPOS Account";
                    $body = "Dear User,\n\n"
                            . "An administrator has requested a password reset for your EmailPOS account.\n\n"
                            . "To reset your password, please click on the following link (it is valid for 1 hour):\n"
                            . $reset_link . "\n\n"
                            . "If you did not request this, please ignore this email.\n\n"
                            . "Thank you,\n"
                            . "EmailPOS Team";
                    $headers = "From: no-reply@eposaudioevents.com\r\n" . // Consider making this dynamic if needed
                               "Reply-To: no-reply@eposaudioevents.com\r\n" .
                               "X-Mailer: PHP/" . phpversion();

                    // Attempt to send the email
                    if (mail($target_email, $subject, $body, $headers)) {
                        $message = '<p class="success">Password reset link sent to ' . htmlspecialchars($target_email) . '.</p>';
                    } else {
                        $message = '<p class="error">Failed to send password reset email. Please check your server\'s mail configuration.</p>';
                    }
                } else {
                    $message = '<p class="error">Error generating password reset token: ' . htmlspecialchars($stmt_insert_token->error) . '</p>';
                }
                $stmt_insert_token->close();
                break;

            default:
                $message = '<p class="error">Unknown action.</p>';
                break;
        }
    }
}

// Fetch all users for listing
$users = [];
$stmt_get_users = $mysqli->prepare("SELECT id, name, username, email, user_level, created_at FROM users ORDER BY created_at DESC");
$stmt_get_users->execute();
$result_users = $stmt_get_users->get_result();
while ($row = $result_users->fetch_assoc()) {
    $users[] = $row;
}
$stmt_get_users->close();

// The $mysqli connection is automatically closed when the script finishes due to init.php's setup.
// If you specifically need to close it here (e.g., if you have a very long-running script), you can.
// if (isset($mysqli) && $mysqli instanceof mysqli) {
//     $mysqli->close();
// }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Manage Users</title>
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet"/>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px auto;
            padding: 20px;
            /* Use BASE_APP_URL for images assuming emailposback.svg is in root or assets/images */
            /* If it's in assets/images, change to <?php echo BASE_APP_URL; ?>assets/images/emailposback.svg */
            background-image: url('<?php echo BASE_APP_URL; ?>emailposback.svg');
            background-size: cover;
            background-repeat: no-repeat;
            background-attachment: fixed;
            background-position: center center;
            background-color: #f5f5f5;
            color: #333;
        }
        .container {
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            max-width: 900px;
            margin: 50px auto;
            text-align: center;
        }
        h2 {
            color: #333;
            margin-bottom: 20px;
        }
        .message {
            margin-bottom: 15px;
            padding: 10px;
            border-radius: 4px;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .actions {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .actions button, .actions select {
            padding: 8px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            white-space: nowrap;
        }
        .delete-btn {
            background-color: #dc3545;
            color: white;
        }
        .delete-btn:hover {
            background-color: #c82333;
        }
        .reset-btn {
            background-color: #ffc107;
            color: #212529;
        }
        .reset-btn:hover {
            background-color: #e0a800;
        }
        .level-select {
            border: 1px solid #ced4da;
            padding: 7px 10px;
            border-radius: 4px;
            background-color: #fff;
            cursor: pointer;
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

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .container {
                margin: 20px auto;
                padding: 15px;
                width: auto;
            }
            table, thead, tbody, th, td, tr {
                display: block;
            }
            thead tr {
                position: absolute;
                top: -9999px;
                left: -9999px;
            }
            tr {
                border: 1px solid #ccc;
                margin-bottom: 10px;
                border-radius: 8px;
                overflow: hidden;
            }
            td {
                border: none;
                border-bottom: 1px solid #eee;
                position: relative;
                padding-left: 50%;
                text-align: right;
            }
            td:before {
                position: absolute;
                top: 6px;
                left: 6px;
                width: 45%;
                padding-right: 10px;
                white-space: nowrap;
                text-align: left;
                font-weight: bold;
            }
            td:nth-of-type(1):before { content: "ID:"; }
            td:nth-of-type(2):before { content: "Name:"; }
            td:nth-of-type(3):before { content: "Username:"; }
            td:nth-of-type(4):before { content: "Email:"; }
            td:nth-of-type(5):before { content: "Level:"; }
            td:nth-of-type(6):before { content: "Created At:"; }
            td:nth-of-type(7):before { content: "Actions:"; }
            .actions {
                justify-content: flex-start;
                margin-top: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <img alt="EmailPOS" src="<?php echo BASE_APP_URL; ?>assets/images/emailpos.svg" style="width:250px;height:auto; margin-bottom: 20px;">
        <h2>Admin Panel - Manage Users</h2>
        <?php if (!empty($message)): ?>
            <p class="message <?php echo strpos($message, 'success') !== false ? 'success' : 'error'; ?>"><?php echo $message; ?></p>
        <?php endif; ?>

        <h3>Existing Users</h3>
        <?php if (empty($users)): ?>
            <p>No users found.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Level</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['id']); ?></td>
                            <td><?php echo htmlspecialchars($user['name']); ?></td>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td>
                                <form method="POST" action="<?php echo BASE_APP_URL; ?>api/admin_panel.php" style="display:inline;">
                                    <input type="hidden" name="action" value="change_level">
                                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['id']); ?>">
                                    <select name="new_level" class="level-select" onchange="this.form.submit()">
                                        <option value="user" <?php echo ($user['user_level'] === 'user') ? 'selected' : ''; ?>>User</option>
                                        <option value="admin" <?php echo ($user['user_level'] === 'admin') ? 'selected' : ''; ?>>Admin</option>
                                    </select>
                                </form>
                            </td>
                            <td><?php echo htmlspecialchars($user['created_at']); ?></td>
                            <td class="actions">
                                <form method="POST" action="<?php echo BASE_APP_URL; ?>api/admin_panel.php" style="display:inline;">
                                    <input type="hidden" name="action" value="send_password_reset">
                                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['id']); ?>">
                                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($user['email']); ?>">
                                    <button type="submit" class="reset-btn">Reset Password</button>
                                </form>
                                <?php if ($user['id'] !== $_SESSION['user_id']): // Prevent admin from deleting themselves ?>
                                    <form method="POST" action="<?php echo BASE_APP_URL; ?>api/admin_panel.php" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete user <?php echo htmlspecialchars($user['username']); ?>? This action cannot be undone.');">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['id']); ?>">
                                        <button type="submit" class="delete-btn">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <a href="<?php echo BASE_APP_URL; ?>emailpos.php" class="back-link">Back to Editor</a>
        <a href="<?php echo BASE_APP_URL; ?>api/create_user.php" class="back-link">Create New User</a>
    </div>
</body>
</html>