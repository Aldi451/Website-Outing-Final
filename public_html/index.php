<?php
/**
 * Root Router
 */
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/auth.php';

if (is_logged_in()) {
    if (is_admin()) {
        header('Location: ' . base_url('admin/index.php'));
        exit;
    } else {
        header('Location: ' . base_url('member/index.php'));
        exit;
    }
} else {
    header('Location: ' . base_url('login.php'));
    exit;
}
