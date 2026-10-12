<?php
/**
 * CSRF Protection Helper
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Menghasilkan CSRF token unik dan menyimpannya di session
 * @return string
 */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        if (function_exists('random_bytes')) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } else {
            $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
        }
    }
    return $_SESSION['csrf_token'];
}

/**
 * Menghasilkan input hidden HTML berisi CSRF token
 * @return string
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Memvalidasi token CSRF yang dikirim via POST atau Header
 * @param string|null $token
 * @return bool
 */
function validate_csrf($token = null) {
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}
