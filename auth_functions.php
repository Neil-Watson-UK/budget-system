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

// USER MANAGEMENT FUNCTIONS - ADD THESE
function getAllUsers() {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT * FROM users ORDER BY role, username");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function createUser($userData) {
    $pdo = getDBConnection();
    
    $stmt = $pdo->prepare("
        INSERT INTO users (username, email, password_hash, display_name, role, region, is_active) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    return $stmt->execute([
        $userData['username'],
        $userData['email'],
        hashPassword($userData['password']),
        $userData['display_name'],
        $userData['role'],
        $userData['region'] ?? null,
        $userData['is_active'] ?? true
    ]);
}

function updateUser($userId, $userData) {
    $pdo = getDBConnection();
    
    if (!empty($userData['password'])) {
        $stmt = $pdo->prepare("
            UPDATE users SET 
            username = ?, email = ?, password_hash = ?, display_name = ?, 
            role = ?, region = ?, is_active = ?
            WHERE id = ?
        ");
        return $stmt->execute([
            $userData['username'],
            $userData['email'],
            hashPassword($userData['password']),
            $userData['display_name'],
            $userData['role'],
            $userData['region'] ?? null,
            $userData['is_active'] ?? true,
            $userId
        ]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE users SET 
            username = ?, email = ?, display_name = ?, 
            role = ?, region = ?, is_active = ?
            WHERE id = ?
        ");
        return $stmt->execute([
            $userData['username'],
            $userData['email'],
            $userData['display_name'],
            $userData['role'],
            $userData['region'] ?? null,
            $userData['is_active'] ?? true,
            $userId
        ]);
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