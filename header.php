<?php
// header.php
session_start();
include __DIR__ . '/koneksi.php';

// Proteksi halaman: Jika belum login, tendang balik ke login.php
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

$user_role = $_SESSION['id_role']; // 1 = IT Admin, 2 = Marketing, 3 = Finance
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cahaya App</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome untuk Icon Menu -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <style>
        body { font-size: 13px; background-color: #f8f9fa; }
        .sidebar { 
            min-height: 100vh; 
            background-color: #212529; 
            color: #fff;
            position: sticky;
            top: 56px;
            max-height: calc(100vh - 56px);
            overflow-y: auto;
        }
        .sidebar .nav-link { color: rgba(255,255,255,.75); padding: 0.5rem 1rem; font-size: 12px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background-color: #343a40; }
        .menu-header { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #6c757d; padding: 0.75rem 1rem 0.25rem; font-weight: bold; }
        /* Style Crystal Report */
        .table-crystal { font-size: 11px; font-family: Arial, sans-serif; white-space: nowrap; }
        .table-crystal th, .table-crystal td { padding: 0.2rem 0.4rem !important; border: 1px solid #dee2e6; vertical-align: middle; }
        .table-crystal thead th { background-color: #e9ecef; font-weight: bold; text-align: center; color: #333; }
        .btn-micro { padding: 0.1rem 0.3rem; font-size: 10px; line-height: 1.2; }
        /* Responsive Sidebar */
        @media (max-width: 767.98px) {
            .sidebar { 
                position: fixed; 
                top: 56px; 
                left: 0; 
                width: 100%; 
                z-index: 1020; 
                max-height: calc(100vh - 56px); 
                overflow-y: auto; 
            }
            .sidebar.collapse { display: none; }
            .sidebar.collapse.show { display: block; }
        }
    </style>
</head>
<body>

<!-- Navbar Atas -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top border-bottom border-secondary">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold text-info" href="index.php">Mutiaracahaya Plastindo <span class="badge bg-secondary style-font text-white" style="font-size: 10px;">ERP</span></a>
        <button class="navbar-toggler d-md-none" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="ms-auto d-flex align-items-center">
            <span class="text-light me-3 small d-none d-sm-inline"><i class="fa fa-user me-1"></i> <?= $_SESSION['username']; ?> (<?= $_SESSION['nama_role']; ?>)</span>
            <a href="index.php?page=ganti-password" class="btn btn-sm btn-outline-warning me-2"><i class="fa fa-key"></i> Password</a>
            <a href="logout.php" class="btn btn-sm btn-danger" onclick="return confirm('Yakin ingin keluar sistem?')"><i class="fa fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
</nav>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar Menu Dinamis Berdasarkan Role -->
        <nav class="col-md-3 col-lg-2 d-md-block sidebar collapse p-0 border-end border-secondary" id="sidebarMenu">
            <div class="position-sticky pt-2">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link <?= !isset($_GET['page']) ? 'active' : '' ?>" href="index.php">
                            <i class="fa fa-tachometer-alt me-2"></i> Dashboard
                        </a>
                    </li>

                    <!-- ================== MASTER DATA ================== -->
                    <!-- Hanya diakses oleh IT Admin (1) & Marketing (2) -->
                    <?php if ($user_role == 1 || $user_role == 2) : ?>
                        <div class="menu-header">Master Data</div>
                        <li class="nav-item"><a class="nav-link" href="index.php?page=customer"><i class="fa fa-users me-2"></i> Customer</a></li>
                        <!-- <li class="nav-item"><a class="nav-link" href="index.php?page=supplier"><i class="fa fa-truck me-2"></i> Supplier</a></li> -->
                         <li class="nav-item"><a class="nav-link" href="index.php?page=area"><i class="fa fa-map-marker-alt me-2"></i> Area</a></li>
                         <li class="nav-item"><a class="nav-link" href="index.php?page=marketing"><i class="fa fa-chart-line me-2"></i> Marketing</a></li>
                        <li class="nav-item"><a class="nav-link" href="index.php?page=sales"><i class="fa fa-user-tie me-2"></i> Sales</a></li>
                        <li class="nav-item"><a class="nav-link" href="index.php?page=inventory"><i class="fa fa-boxes me-2"></i> Inventory</a></li>
                        <li class="nav-item"><a class="nav-link" href="index.php?page=category"><i class="fa fa-tags me-2"></i> Category</a></li>
                        <li class="nav-item"><a class="nav-link" href="index.php?page=gudang"><i class="fa fa-warehouse me-2"></i> Gudang</a></li>
                        <li class="nav-item"><a class="nav-link" href="index.php?page=mesin"><i class="fa fa-cogs me-2"></i> Mesin</a></li>
                         <li class="nav-item"><a class="nav-link" href="index.php?page=uom"><i class="fa fa-balance-scale me-2"></i>UOM</a></li>
                    <?php endif; ?>

                    <!-- ================== TRANSAKSI ================== -->
                    <div class="menu-header">Transaksi</div>
                    <!-- Akses Marketing (2) & IT Admin (1) -->
                    <?php if ($user_role == 1 || $user_role == 2) : ?>
                        <!--<li class="nav-item"><a class="nav-link" href="index.php?page=purchase_order"><i class="fa fa-file-invoice me-2"></i> Purchase Order</a></li>-->
                        <li class="nav-item"><a class="nav-link" href="index.php?page=sales_order"><i class="fa fa-file-invoice me-2"></i> Sales Order</a></li>
                        <li class="nav-item"><a class="nav-link" href="index.php?page=sop"><i class="fa fa-industry me-2"></i> SOP / SPK</a></li>
                        <li class="nav-item"><a class="nav-link" href="index.php?page=shipping"><i class="fa fa-shipping-fast me-2"></i> Shipping (Surat Jalan)</a></li>
                    <?php endif; ?>
                    
                    <!-- Akses Finance (3) & IT Admin (1) -->
                    <?php if ($user_role == 1 || $user_role == 3) : ?>
                        <li class="nav-item"><a class="nav-link" href="index.php?page=invoice"><i class="fa fa-file-signature me-2"></i> Invoice</a></li>
                        <li class="nav-item"><a class="nav-link" href="index.php?page=retur-invoice"><i class="fa fa-undo me-2"></i> Retur Invoice</a></li>
                        <li class="nav-item"><a class="nav-link" href="index.php?page=pembayaran"><i class="fa fa-money-bill-wave me-2"></i> Pembayaran</a></li>
                        <li class="nav-item"><a class="nav-link" href="index.php?page=downpayment"><i class="fa fa-hand-holding-usd me-2"></i> Downpayment</a></li>
                        <li class="nav-item"><a class="nav-link" href="index.php?page=titip-uang"><i class="fa fa-wallet me-2"></i> Titip Uang</a></li>
                    <?php endif; ?>

                    <!-- ================== LAPORAN ================== -->
                    <div class="menu-header">Laporan</div>
                    <li class="nav-item"><a class="nav-link" href="index.php?page=kartu-stok"><i class="fa fa-book me-2"></i> Kartu Stok</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.php?page=rekap_sales_order"><i class="fa fa-chart-line me-2"></i> Rekap Sales Order</a></li>
                    <?php if ($user_role == 1 || $user_role == 3) : ?>
                        <div class="menu-header">Laporan Piutang</div>
                        <li class="nav-item"><a class="nav-link" href="index.php?page=aging"><i class="fa fa-clock me-2"></i> Aging Piutang</a></li>
                    <?php endif; ?>

                    <!-- ================== IT BACKEND ADMIN ================== -->
                    <?php if ($user_role == 1) : ?>
                        <div class="menu-header">IT Program Admin</div>
                        <li class="nav-item"><a class="nav-link" href="index.php?page=konfigurasi"><i class="fa fa-sliders-h me-2"></i> Konfigurasi Server</a></li>
                        <li class="nav-item"><a class="nav-link" href="index.php?page=user-akses"><i class="fa fa-user-shield me-2"></i> User Akses</a></li>
                        <li class="nav-item"><a class="nav-link" href="index.php?page=backup"><i class="fa fa-database me-2"></i> Backup Database</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </nav>

        <!-- Konten Utama Aplikasi (Buka tag col-md-9) -->
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-3">