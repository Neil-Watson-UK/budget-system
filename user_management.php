<?php
// user_management.php - CORRECTED VERSION
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once 'config.php';
require_once 'auth_functions.php';
require_once 'functions.php';

requireLogin();
requireAdmin();

$pdo = getDBConnection();
$message = '';

// Handle form actions
if ($_POST['action'] ?? '') {
    switch ($_POST['action']) {
        case 'create_user':
            // Debug: log what we're receiving
            error_log("Creating user with role: " . ($_POST['role'] ?? 'NOT SET'));
            if (createUser($_POST)) {
                $message = "User created successfully!";
            } else {
                $message = "Error creating user. Check server logs for details.";
            }
            break;
            
        case 'update_user':
            // Debug: log what we're receiving
            error_log("Updating user ID " . ($_POST['user_id'] ?? '') . " with role: " . ($_POST['role'] ?? 'NOT SET'));
            if (updateUser($_POST['user_id'], $_POST)) {
                $message = "User updated successfully!";
            } else {
                $message = "Error updating user. Check server logs for details.";
            }
            break;
            
        case 'delete_user':
            if (deleteUser($_POST['user_id'])) {
                $message = "User deleted successfully!";
            } else {
                $message = "Error deleting user.";
            }
            break;
    }
}

$users = getAllUsers();
if (empty($users)) {
    // Check if users table exists and has data
    try {
        $testStmt = $pdo->query("SELECT COUNT(*) as cnt FROM users");
        $count = $testStmt->fetch(PDO::FETCH_ASSOC);
        if ($count && $count['cnt'] == 0) {
            $message = "No users found in database.";
        }
    } catch (PDOException $e) {
        $message = "Error loading users: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .glass-panel {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 20px;
            margin-bottom: 20px;
        }
        .role-badge {
            font-size: 0.8em;
        }
    </style>
</head>
<body class="bg-dark text-light">
    <div class="container mt-4">
        <?php include 'header.php'; ?>
        
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1>👥 User Management</h1>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
                        ➕ Add New User
                    </button>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-info"><?= $message ?></div>
                <?php endif; ?>

                <div class="glass-panel">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Display Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Region</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted">
                                            No users found. <?= $message ? htmlspecialchars($message) : 'Click "Add New User" to create one.' ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                                        <td><?= htmlspecialchars($user['display_name']) ?></td>
                                        <td><?= htmlspecialchars($user['email']) ?></td>
                                        <td>
                                            <span class="badge 
                                                <?= $user['role'] === 'admin' ? 'bg-danger' : 
                                                   ($user['role'] === 'manager' ? 'bg-warning' : 
                                                   ($user['role'] === 'sales_person' ? 'bg-info' : 'bg-secondary')) ?>">
                                                <?= htmlspecialchars($user['role']) ?>
                                            </span>
                                        </td>
                                        <td><?= $user['region'] ?: 'All' ?></td>
                                        <td>
                                            <span class="badge <?= $user['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                                <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                        <td><?= $user['last_login'] ? date('M j, Y g:i A', strtotime($user['last_login'])) : 'Never' ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-warning" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editUserModal"
                                                    data-user='<?= json_encode($user) ?>'>
                                                Edit
                                            </button>
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" 
                                                            onclick="return confirm('Are you sure?')">
                                                        Delete
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create User Modal -->
    <div class="modal fade" id="createUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark text-light">
                <div class="modal-header">
                    <h5 class="modal-title">Create New User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create_user">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Username *</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email *</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Display Name *</label>
                            <input type="text" name="display_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password *</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Role</label>
                                <select name="role" class="form-select">
                                    <option value="user">User</option>
                                    <option value="manager">Manager</option>
                                    <option value="admin">Admin</option>
                                    <option value="sales_person">Sales Person</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Region (Optional)</label>
                                <select name="region" class="form-select">
                                    <option value="">All Regions</option>
                                    <?php foreach ($REGIONAL_SETTINGS as $region => $settings): ?>
                                        <option value="<?= $region ?>"><?= $region ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark text-light">
                <div class="modal-header">
                    <h5 class="modal-title">Edit User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Username *</label>
                            <input type="text" name="username" id="edit_username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email *</label>
                            <input type="email" name="email" id="edit_email" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Display Name *</label>
                            <input type="text" name="display_name" id="edit_display_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password (leave blank to keep current)</label>
                            <input type="password" name="password" class="form-control">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Role</label>
                                <select name="role" id="edit_role" class="form-select">
                                    <option value="user">User</option>
                                    <option value="manager">Manager</option>
                                    <option value="admin">Admin</option>
                                    <option value="sales_person">Sales Person</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Region</label>
                                <select name="region" id="edit_region" class="form-select">
                                    <option value="">All Regions</option>
                                    <?php foreach ($REGIONAL_SETTINGS as $region => $settings): ?>
                                        <option value="<?= $region ?>"><?= $region ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" id="edit_is_active" class="form-check-input" value="1">
                            <label class="form-check-label" for="edit_is_active">Active User</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Populate edit modal with user data
        document.addEventListener('DOMContentLoaded', function() {
            const editModal = document.getElementById('editUserModal');
            editModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const userData = JSON.parse(button.getAttribute('data-user'));
                
                document.getElementById('edit_user_id').value = userData.id;
                document.getElementById('edit_username').value = userData.username;
                document.getElementById('edit_email').value = userData.email;
                document.getElementById('edit_display_name').value = userData.display_name;
                document.getElementById('edit_role').value = userData.role;
                document.getElementById('edit_region').value = userData.region || '';
                document.getElementById('edit_is_active').checked = userData.is_active == 1;
            });
        });
    </script>
</body>
</html>