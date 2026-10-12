<?php
/**
 * Authentication & Authorization System
 */

require_once __DIR__ . '/helpers.php';

// Pastikan sesi berjalan dengan cookie yang aman (HttpOnly / SameSite / Secure)
start_secure_session();

/**
 * Cek apakah user sedang login
 * @return bool
 */
function is_logged_in() {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['role']);
}

/**
 * Mendapatkan data user yang sedang login
 * @return array|null
 */
function current_user() {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id'        => $_SESSION['user_db_id'] ?? null,
        'user_id'   => $_SESSION['user_id'] ?? null,
        'nama'      => $_SESSION['nama'] ?? null,
        'role'      => $_SESSION['role'] ?? null,
        'status'    => $_SESSION['status'] ?? 'active',
    ];
}

/**
 * Cek apakah user adalah Admin
 * @return bool
 */
function is_admin() {
    return is_logged_in() && ($_SESSION['role'] === 'admin');
}

/**
 * Cek apakah user adalah Member
 * @return bool
 */
function is_member() {
    return is_logged_in() && ($_SESSION['role'] === 'member');
}

/**
 * Memastikan user sudah login, jika belum redirect ke login
 */
function require_login() {
    if (!is_logged_in()) {
        flash_set('warning', 'Silakan login terlebih dahulu untuk mengakses halaman ini.');
        header('Location: ' . base_url('login.php'));
        exit;
    }
}

/**
 * Memastikan user memiliki hak akses Admin
 * Jika Member mencoba akses, redirect paksa ke dashboard member
 */
function require_admin() {
    require_login();
    if (!is_admin()) {
        flash_set('danger', 'Akses ditolak! Anda tidak memiliki izin untuk mengakses area Administrator.');
        header('Location: ' . base_url('member/index.php'));
        exit;
    }
}

/**
 * Memastikan user memiliki hak akses Member (atau Admin yang sedang meninjau)
 */
function require_member() {
    require_login();
}

/**
 * Proses Logout aman
 */
function logout() {
    start_secure_session();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        if (PHP_VERSION_ID >= 70300) {
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params["path"],
                'domain'   => $params["domain"],
                'secure'   => $params["secure"],
                'httponly' => $params["httponly"],
                'samesite' => $params["samesite"] ?? 'Lax',
            ]);
        } else {
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
    }
    session_destroy();
}
