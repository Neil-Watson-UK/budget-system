<?php
// account.php - Logged-in user account management (password, profile)
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode('account.php'));
    exit;
}

$userId = (int)$_SESSION['user_id'];
$user = getCurrentUser($userId);
$message = '';
$error = '';

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (empty($current) || empty($new) || empty($confirm)) {
        $error = 'All password fields are required.';
    } elseif ($new !== $confirm) {
        $error = 'New password and confirmation do not match.';
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif (changeOwnPassword($userId, $current, $new)) {
        $message = 'Password updated successfully.';
    } else {
        $error = 'Current password is incorrect or update failed.';
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $displayName = trim($_POST['display_name'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if (empty($displayName)) {
        $error = 'Display name is required.';
    } elseif (updateOwnProfile($userId, ['display_name' => $displayName, 'email' => $email])) {
        $message = 'Profile updated successfully.';
        $user = getCurrentUser($userId);
    } else {
        $error = 'Failed to update profile.';
    }
}

// Refresh user data
$user = $user ?? getCurrentUser($userId);

require_once __DIR__ . '/header.php';
?>

<div class="container-xl py-4">
        <div class="mb-4">
            <h1 class="page-title"><i class="ti ti-user-circle me-2"></i>My Account</h1>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible" role="alert">
            <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row row-deck row-cards">
            <!-- Account info card -->
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="ti ti-info-circle me-2"></i>Account Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-3 text-muted">Username</div>
                            <div class="col-md-9"><?= htmlspecialchars($user['username'] ?? '-') ?></div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-3 text-muted">Role</div>
                            <div class="col-md-9"><?= htmlspecialchars($user['role'] ?? '-') ?></div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-3 text-muted">Region</div>
                            <div class="col-md-9"><?= !empty($user['region']) ? htmlspecialchars($user['region']) : 'All Regions' ?></div>
                        </div>
                        <div class="row">
                            <div class="col-md-3 text-muted">Last login</div>
                            <div class="col-md-9"><?= $user['last_login'] ? date('M j, Y g:i A', strtotime($user['last_login'])) : 'Never' ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Update profile -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="ti ti-edit me-2"></i>Update Profile</h3>
                    </div>
                    <div class="card-body">
                        <form method="post" action="">
                            <input type="hidden" name="action" value="update_profile">
                            <div class="mb-3">
                                <label class="form-label">Display name</label>
                                <input type="text" name="display_name" class="form-control" value="<?= htmlspecialchars($user['display_name'] ?? '') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Save profile</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Change password -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="ti ti-lock me-2"></i>Change Password</h3>
                    </div>
                    <div class="card-body">
                        <form method="post" action="">
                            <input type="hidden" name="action" value="change_password">
                            <div class="mb-3">
                                <label class="form-label">Current password</label>
                                <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">New password</label>
                                <input type="password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password">
                                <small class="text-muted">At least 8 characters</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Confirm new password</label>
                                <input type="password" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password">
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="ti ti-key me-1"></i>Update password</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
