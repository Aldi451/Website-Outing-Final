<?php
/**
 * Header & Navbar Component
 */
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';

// Pastikan sesi berjalan dengan cookie yang aman (HttpOnly / SameSite / Secure)
start_secure_session();

$pageTitle = $pageTitle ?? 'Outing Management System';
$currentUser = current_user();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle); ?> - Outing System</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="<?= base_url('assets/css/style.css'); ?>" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
    <div class="container-fluid px-3 px-lg-4">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= is_admin() ? base_url('admin/index.php') : base_url('member/index.php'); ?>">
            <span class="badge bg-primary fs-6 p-2"><i class="bi bi-compass"></i></span>
            <span>Outing System</span>
        </a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarContent">
            <?php if (is_logged_in()): ?>
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <?php if (is_admin()): ?>
                        <li class="nav-nav-item">
                            <a class="nav-link <?= strpos($_SERVER['SCRIPT_NAME'], 'admin/index.php') !== false ? 'active' : ''; ?>" href="<?= base_url('admin/index.php'); ?>">
                                <i class="bi bi-grid-fill me-1"></i> Dashboard Aktif
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= strpos($_SERVER['SCRIPT_NAME'], 'admin/history.php') !== false ? 'active' : ''; ?>" href="<?= base_url('admin/history.php'); ?>">
                                <i class="bi bi-clock-history me-1"></i> History Outing
                            </a>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle <?= (strpos($_SERVER['SCRIPT_NAME'], 'users.php') !== false || strpos($_SERVER['SCRIPT_NAME'], 'categories.php') !== false) ? 'active' : ''; ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-database-gear me-1"></i> Data Master
                            </a>
                            <ul class="dropdown-menu shadow-sm border-0">
                                <li><a class="dropdown-item" href="<?= base_url('admin/users.php'); ?>"><i class="bi bi-people me-2"></i>Kelola User</a></li>
                                <li><a class="dropdown-item" href="<?= base_url('admin/categories.php'); ?>"><i class="bi bi-tags me-2"></i>Kategori Pengeluaran</a></li>
                            </ul>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= strpos($_SERVER['SCRIPT_NAME'], 'import.php') !== false ? 'active' : ''; ?>" href="<?= base_url('admin/import.php'); ?>">
                                <i class="bi bi-file-earmark-spreadsheet me-1"></i> Import Excel
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link <?= strpos($_SERVER['SCRIPT_NAME'], 'member/index.php') !== false ? 'active' : ''; ?>" href="<?= base_url('member/index.php'); ?>">
                                <i class="bi bi-house-door-fill me-1"></i> Outing Saya
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>

                <div class="d-flex align-items-center gap-2">
                    <div class="text-light text-end d-none d-sm-block me-2">
                        <div class="fw-semibold small"><?= htmlspecialchars($currentUser['nama']); ?></div>
                        <div class="text-muted" style="font-size: 0.75rem;">
                            <?= is_admin() ? '<span class="badge bg-danger">ADMIN</span>' : '<span class="badge bg-secondary">MEMBER</span>'; ?>
                            ID: <?= htmlspecialchars($currentUser['user_id']); ?>
                        </div>
                    </div>
                    <a href="<?= base_url('logout.php'); ?>" class="btn btn-outline-light btn-sm" data-confirm="Apakah Anda yakin ingin logout?">
                        <i class="bi bi-box-arrow-right me-1"></i> Keluar
                    </a>
                </div>
            <?php else: ?>
                <div class="ms-auto">
                    <a href="<?= base_url('login.php'); ?>" class="btn btn-primary btn-sm px-3">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Login
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</nav>

<main class="py-4">
    <div class="container-fluid px-3 px-lg-4">
        <?= flash_render(); ?>
