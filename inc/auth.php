<?php
// inc/auth.php
session_start();

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: /index.php');
        exit;
    }
}

function current_user() {
    return $_SESSION['username'] ?? null;
}

function current_user_id() {
    return $_SESSION['user_id'] ?? null;
}

function current_user_role() {
    return $_SESSION['role'] ?? null;
}
