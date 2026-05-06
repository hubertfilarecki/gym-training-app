<?php declare(strict_types=1);

function start_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function require_login(string $redirect = 'logowanie.php'): void {
    if (!isset($_SESSION['loggedin']) || !isset($_SESSION['user_id'])) {
        header('Location: ' . $redirect);
        exit();
    }
}

function require_admin(string $redirect = 'plany.php?error=access_denied'): void {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        header('Location: ' . $redirect);
        exit();
    }
}

function current_user_id(): ?int {
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function current_username(): string {
    return isset($_SESSION['username']) ? (string) $_SESSION['username'] : 'Gość';
}

function current_user_role(): string {
    return isset($_SESSION['role']) ? (string) $_SESSION['role'] : 'user';
}

function current_profile_picture(): string {
    return isset($_SESSION['profile_picture']) ? (string) $_SESSION['profile_picture'] : 'uploads/default.png';
}
