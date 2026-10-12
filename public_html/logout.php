<?php
/**
 * Logout Handler
 */
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/auth.php';

logout();
flash_set('info', 'Anda telah berhasil keluar dari sistem.');
header('Location: ' . base_url('login.php'));
exit;
