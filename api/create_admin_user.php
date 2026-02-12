<?php
// api/save_email.php

// This one line gives us the session, security, config, AND database connection.
require_once __DIR__ . '/../includes/init.php';
// create_admin_user.php
require_once 'db_connect.php'; // Include your database connection

$new_username = "neil_watson"; // Choose your desired username
$plain_password = "DavieCooper@1975"; // Choose a STRONG, UNIQUE password for your admin user
$new_email = "neil.watson@eposaudio.com"; // Your desired email
$current_timestamp = date('Y-m-d H:i:s'); // Current time for created_at

// Hash the password
$hashed_password = password_hash($plain_password, PASSWORD_DEFAULT);

// Check if user already exists
$stmt_check = $mysqli->prepare("SELECT id FROM users WHERE username = ?");
$stmt_check->bind_param("s", $new_username);
$stmt_check->execute();
$stmt_check->store_result();

if ($stmt_check->num_rows > 0) {
    echo "User '{$new_username}' already exists.<br>";
} else {
    // Insert the new user into the database
    // Adjust columns and placeholders if 'email' or 'created_at' are optional/have defaults
    $stmt = $mysqli->prepare("INSERT INTO users (username, email, password_hash, created_at) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $new_username, $new_email, $hashed_password, $current_timestamp);

    if ($stmt->execute()) {
        echo "User '{$new_username}' created successfully!<br>";
        echo "Username: {$new_username}<br>";
        echo "Password: (the one you set: '{$plain_password}')<br>";
    } else {
        echo "Error creating user: " . $stmt->error . "<br>";
    }
    $stmt->close();
}

$stmt_check->close();
$mysqli->close();
?>