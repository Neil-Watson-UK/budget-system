<?php
// auth_functions.php - Complete version with user management

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function loginUser($username, $password) {
    $pdo = getDBConnection();
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = TRUE");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && verifyPassword($password, $user['password_hash'])) {
        // Update last login
        $updateStmt = $pdo->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
        $updateStmt->execute([$user['id']]);
        
        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['display_name'] = $user['display_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['region'] = $user['region'];
        $_SESSION['email'] = $user['email'];
        
        return true;
    }
    
    return false;
}

// Get current user's details (for account page)
function getCurrentUser($userId) {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT id, username, email, display_name, role, region, last_login, is_active FROM users WHERE id = ?");
        $stmt->execute([(int)$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getCurrentUser error: " . $e->getMessage());
        return null;
    }
}

// Change own password (current user only)
function changeOwnPassword($userId, $currentPassword, $newPassword) {
    if ((int)$userId !== (int)($_SESSION['user_id'] ?? 0)) return false;
    if (strlen($newPassword) < 8) return false;

    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([(int)$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !verifyPassword($currentPassword, $user['password_hash'])) return false;

        $update = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        return $update->execute([hashPassword($newPassword), (int)$userId]);
    } catch (PDOException $e) {
        error_log("changeOwnPassword error: " . $e->getMessage());
        return false;
    }
}

// Update own profile (display_name, email only)
function updateOwnProfile($userId, $data) {
    if ((int)$userId !== (int)($_SESSION['user_id'] ?? 0)) return false;

    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("UPDATE users SET display_name = ?, email = ? WHERE id = ?");
        $ok = $stmt->execute([
            trim($data['display_name'] ?? ''),
            trim($data['email'] ?? ''),
            (int)$userId
        ]);
        if ($ok) {
            $_SESSION['display_name'] = trim($data['display_name'] ?? '');
            $_SESSION['email'] = trim($data['email'] ?? '');
        }
        return $ok;
    } catch (PDOException $e) {
        error_log("updateOwnProfile error: " . $e->getMessage());
        return false;
    }
}

function getAllUsers() {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->query("SELECT * FROM users ORDER BY role, username");
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $result ?: [];
    } catch (PDOException $e) {
        error_log("getAllUsers error: " . $e->getMessage());
        return [];
    }
}

function createUser($userData) {
    try {
        $pdo = getDBConnection();
        
        // Validate role
        $validRoles = ['admin', 'manager', 'user', 'sales_person'];
        $role = trim($userData['role'] ?? 'user');
        if (!in_array($role, $validRoles)) {
            $role = 'user'; // Default to user if invalid
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password_hash, display_name, role, region, is_active) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([
            trim($userData['username'] ?? ''),
            trim($userData['email'] ?? ''),
            hashPassword($userData['password'] ?? ''),
            trim($userData['display_name'] ?? ''),
            $role,
            !empty($userData['region']) ? trim($userData['region']) : null,
            isset($userData['is_active']) ? (bool)$userData['is_active'] : true
        ]);
    } catch (PDOException $e) {
        error_log("createUser error: " . $e->getMessage());
        return false;
    }
}

function updateUser($userId, $userData) {
    try {
        $pdo = getDBConnection();
        
        // Validate role - ensure sales_person is accepted
        $validRoles = ['admin', 'manager', 'user', 'sales_person'];
        $role = isset($userData['role']) ? trim($userData['role']) : 'user';
        if (empty($role) || !in_array($role, $validRoles)) {
            error_log("Invalid or missing role: " . var_export($userData['role'] ?? 'NOT SET', true) . " - defaulting to 'user'");
            $role = 'user'; // Default to user if invalid
        }
        
        if (!empty($userData['password'])) {
            $stmt = $pdo->prepare("
                UPDATE users SET 
                username = ?, email = ?, password_hash = ?, display_name = ?, 
                role = ?, region = ?, is_active = ?
                WHERE id = ?
            ");
            return $stmt->execute([
                trim($userData['username'] ?? ''),
                trim($userData['email'] ?? ''),
                hashPassword($userData['password']),
                trim($userData['display_name'] ?? ''),
                $role,
                !empty($userData['region']) ? trim($userData['region']) : null,
                isset($userData['is_active']) ? (bool)$userData['is_active'] : true,
                (int)$userId
            ]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE users SET 
                username = ?, email = ?, display_name = ?, 
                role = ?, region = ?, is_active = ?
                WHERE id = ?
            ");
            return $stmt->execute([
                trim($userData['username'] ?? ''),
                trim($userData['email'] ?? ''),
                trim($userData['display_name'] ?? ''),
                $role,
                !empty($userData['region']) ? trim($userData['region']) : null,
                isset($userData['is_active']) ? (bool)$userData['is_active'] : true,
                (int)$userId
            ]);
        }
    } catch (PDOException $e) {
        error_log("updateUser error: " . $e->getMessage());
        return false;
    }
}

function deleteUser($userId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND id != ?");
    return $stmt->execute([$userId, $_SESSION['user_id']]); // Prevent self-deletion
}

// SECURITY FUNCTIONS
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }
}

function hasPermission($permission) {
    if (!isset($_SESSION['role'])) return false;
    
    global $USER_ROLES;
    $role = $_SESSION['role'];
    
    return in_array($permission, $USER_ROLES[$role]['permissions']);
}

function requirePermission($permission) {
    requireLogin();
    if (!hasPermission($permission)) {
        http_response_code(403);
        die("Access denied.");
    }
}

function requireAdmin() {
    requirePermission('manage_users');
}
?>