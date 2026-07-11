<?php
// header.php
session_start();
include __DIR__ . '/koneksi.php';

// ======================================================================
// 1. CEK SESSION TOKEN UNTUK MENCEGAH LOGIN GANDA (SINGLE LOGIN)
// ======================================================================
if (isset($_SESSION['id_user']) && isset($_SESSION['session_token'])) {
    $user_id = $_SESSION['id_user'];
    $token = $_SESSION['session_token'];
    
    $checkQuery = "SELECT session_token FROM sys_users WHERE id_user = '$user_id'";
    $checkResult = mysqli_query($conn, $checkQuery);
    $checkData = mysqli_fetch_assoc($checkResult);
    
    if ($checkData && $checkData['session_token'] !== $token) {
        // Token tidak sesuai, berarti ada login dari tempat lain
        session_destroy();
        header("Location: login.php?msg=double_login");
        exit;
    }
}

// ======================================================================
// 2. PROTEKSI HALAMAN - Jika belum login, redirect ke login.php
// ======================================================================
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

// ======================================================================
// 3. AMBIL DATA USER & ROLE
// ======================================================================
$user_id = $_SESSION['id_user'];
$user_role = $_SESSION['id_role']; // 1 = IT Admin, 2 = Marketing, 3 = Finance, dll

// ======================================================================
// 4. AMBIL DAFTAR MENU YANG DIIZINKAN UNTUK USER INI
// ======================================================================
$menuAccessQuery = "SELECT menu_key FROM sys_user_menu_access WHERE id_user = '$user_id'";
$menuAccessResult = mysqli_query($conn, $menuAccessQuery);
$allowedMenus = [];
while ($row = mysqli_fetch_assoc($menuAccessResult)) {
    $allowedMenus[] = $row['menu_key'];
}

// ======================================================================
// 5. FUNGSI HELPER UNTUK CEK AKSES MENU
// ======================================================================
function canAccessMenu($menuKey, $allowedMenus) {
    global $user_role;
    // IT Admin (id_role = 1) dapat mengakses semua menu
    if ($user_role == 1) return true;
    return in_array($menuKey, $allowedMenus);
}

// ======================================================================
// 6. AMBIL DAFTAR SEMUA MENU DARI DATABASE UNTUK DITAMPILKAN DI SIDEBAR
// ======================================================================
$allMenusQuery = "SELECT * FROM sys_menus WHERE is_active = 1 ORDER BY menu_group, sort_order";
$allMenusResult = mysqli_query($conn, $allMenusQuery);
$menusByGroup = [];
while ($menu = mysqli_fetch_assoc($allMenusResult)) {
    $group = $menu['menu_group'];
    if (!isset($menusByGroup[$group])) {
        $menusByGroup[$group] = [];
    }
    $menusByGroup[$group][] = $menu;
}

// ======================================================================
// 7. LABEL GROUP UNTUK TAMPILAN
// ======================================================================
$groupLabels = [
    'utama' => 'Utama',
    'master' => 'Master Data',
    'transaksi' => 'Transaksi',
    'laporan' => 'Laporan',
    'program' => 'Program / Keamanan',
    'it' => 'IT Program Admin'
];

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
    <!-- Flatpickr CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <!-- Flatpickr JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
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
        .sidebar .nav-link { 
            color: rgba(255,255,255,.75); 
            padding: 0.5rem 1rem; 
            font-size: 12px; 
        }
        .sidebar .nav-link:hover, 
        .sidebar .nav-link.active { 
            color: #fff; 
            background-color: #343a40; 
        }
        .sidebar .nav-link i { 
            width: 18px; 
            text-align: center; 
        }
        .menu-header { 
            font-size: 10px; 
            text-transform: uppercase; 
            letter-spacing: 1px; 
            color: #6c757d; 
            padding: 0.75rem 1rem 0.25rem; 
            font-weight: bold; 
        }
        /* Style Crystal Report */
        .table-crystal { font-size: 11px; font-family: Arial, sans-serif; white-space: nowrap; }
        .table-crystal th, 
        .table-crystal td { 
            padding: 0.2rem 0.4rem !important; 
            border: 1px solid #dee2e6; 
            vertical-align: middle; 
        }
        .table-crystal thead th { 
            background-color: #e9ecef; 
            font-weight: bold; 
            text-align: center; 
            color: #333; 
        }
        .btn-micro { 
            padding: 0.1rem 0.3rem; 
            font-size: 10px; 
            line-height: 1.2; 
        }
        /* Badge role di navbar */
        .badge-role {
            font-size: 9px;
            padding: 2px 8px;
            border-radius: 10px;
        }
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

<!-- ====================================================================== -->
<!-- NAVBAR ATAS -->
<!-- ====================================================================== -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top border-bottom border-secondary">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold text-info" href="index.php">
            CP
            <span class="badge bg-secondary text-white" style="font-size: 10px;">ERP</span>
        </a>
        <button class="navbar-toggler d-md-none" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="ms-auto d-flex align-items-center">
            <span class="text-light me-3 small d-none d-sm-inline">
                <i class="fa fa-user me-1"></i> 
                <?= $_SESSION['username']; ?> 
                <span class="badge-role bg-<?= $user_role == 1 ? 'danger' : ($user_role == 2 ? 'info' : 'warning') ?> text-white">
                    <?= $_SESSION['nama_role'] ?? 'User' ?>
                </span>
            </span>
            <a href="index.php?page=ganti-password" class="btn btn-sm btn-outline-warning me-2" title="Ganti Password">
                <i class="fa fa-key"></i>
            </a>
            <a href="logout.php" class="btn btn-sm btn-danger" onclick="return confirm('Yakin ingin keluar sistem?')" title="Logout">
                <i class="fa fa-sign-out-alt"></i>
            </a>
        </div>
    </div>
</nav>

<!-- ====================================================================== -->
<!-- KONTEN UTAMA -->
<!-- ====================================================================== -->
<div class="container-fluid">
    <div class="row">
        <!-- ====================================================================== -->
        <!-- SIDEBAR MENU -->
        <!-- ====================================================================== -->
        <nav class="col-md-3 col-lg-2 d-md-block sidebar collapse p-0 border-end border-secondary" id="sidebarMenu">
            <div class="position-sticky pt-2">
                <ul class="nav flex-column">
                    
                    <!-- ================== DASHBOARD ================== -->
                    <?php if (canAccessMenu('dashboard', $allowedMenus)): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= !isset($_GET['page']) ? 'active' : '' ?>" href="index.php">
                            <i class="fa fa-tachometer-alt me-2"></i> Dashboard
                        </a>
                    </li>
                    <?php endif; ?>

                    <!-- ================== MASTER DATA ================== -->
                    <?php 
                    $hasMasterMenu = false;
                    $masterMenus = ['customer', 'area', 'marketing', 'sales', 'inventory', 'category', 'gudang', 'mesin', 'uom', 'supplier'];
                    foreach ($masterMenus as $menu) {
                        if (canAccessMenu($menu, $allowedMenus)) {
                            $hasMasterMenu = true;
                            break;
                        }
                    }
                    ?>
                    <?php if ($hasMasterMenu): ?>
                    <div class="menu-header">Master Data</div>
                        
                        <?php if (canAccessMenu('customer', $allowedMenus)): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=customer">
                                <i class="fa fa-users me-2"></i> Customer
                            </a>
                        </li>
                        <?php endif; ?>
                        
                      
                        
                        <?php if (canAccessMenu('area', $allowedMenus)): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=area">
                                <i class="fa fa-map-marker-alt me-2"></i> Area
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (canAccessMenu('marketing', $allowedMenus)): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=marketing">
                                <i class="fa fa-chart-line me-2"></i> Marketing
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (canAccessMenu('sales', $allowedMenus)): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=sales">
                                <i class="fa fa-user-tie me-2"></i> Sales
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (canAccessMenu('inventory', $allowedMenus)): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=inventory">
                                <i class="fa fa-boxes me-2"></i> Inventory
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (canAccessMenu('category', $allowedMenus)): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=category">
                                <i class="fa fa-tags me-2"></i> Category
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (canAccessMenu('gudang', $allowedMenus)): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=gudang">
                                <i class="fa fa-warehouse me-2"></i> Gudang
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (canAccessMenu('mesin', $allowedMenus)): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=mesin">
                                <i class="fa fa-cogs me-2"></i> Mesin
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (canAccessMenu('uom', $allowedMenus)): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=uom">
                                <i class="fa fa-balance-scale me-2"></i> UOM
                            </a>
                        </li>
                        <?php endif; ?>
                        
                    <?php endif; ?>

                    <!-- ================== TRANSAKSI ================== -->
                    <?php 
                    $hasTransaksiMenu = false;
                    $transaksiMenus = ['sales_order', 'sop', 'shipping', 'invoice', 'retur-invoice', 'pembayaran', 'downpayment', 'titip_uang', 'purchase_order'];
                    foreach ($transaksiMenus as $menu) {
                        if (canAccessMenu($menu, $allowedMenus)) {
                            $hasTransaksiMenu = true;
                            break;
                        }
                    }
                    ?>
                    <?php if ($hasTransaksiMenu): ?>
                    <div class="menu-header">Transaksi</div>
                        
                      
                        
                        <?php if (canAccessMenu('sales_order', $allowedMenus)): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=sales_order">
                                <i class="fa fa-file-invoice me-2"></i> Sales Order
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (canAccessMenu('sop', $allowedMenus)): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=sop">
                                <i class="fa fa-industry me-2"></i> SOP / SPK
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (canAccessMenu('shipping', $allowedMenus)): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=shipping">
                                <i class="fa fa-shipping-fast me-2"></i> Shipping (Surat Jalan)
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (canAccessMenu('invoice', $allowedMenus)): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=invoice">
                                <i class="fa fa-file-signature me-2"></i> Invoice
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (canAccessMenu('retur-invoice', $allowedMenus)): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=retur-invoice">
                                <i class="fa fa-undo me-2"></i> Retur Invoice
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (canAccessMenu('pembayaran', $allowedMenus)): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=pembayaran">
                                <i class="fa fa-money-bill-wave me-2"></i> Pembayaran
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (canAccessMenu('downpayment', $allowedMenus)): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=downpayment">
                                <i class="fa fa-hand-holding-usd me-2"></i> Downpayment
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (canAccessMenu('titip_uang', $allowedMenus)): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=titip_uang">
                                <i class="fa fa-wallet me-2"></i> Titip Uang
                            </a>
                        </li>
                        <?php endif; ?>
                        
                    <?php endif; ?>

                    <!-- ================== LAPORAN ================== -->
                    <?php 
                    $hasLaporanMenu = false;
                    $laporanMenus = ['kartu-stok', 'rekap_sales_order', 'aging'];
                    foreach ($laporanMenus as $menu) {
                        if (canAccessMenu($menu, $allowedMenus)) {
                            $hasLaporanMenu = true;
                            break;
                        }
                    }
                    ?>
                    <?php if ($hasLaporanMenu): ?>
                    <div class="menu-header">Laporan</div>
                        
                        <?php if (canAccessMenu('kartu-stok', $allowedMenus)): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=kartu_stok_order_customer">
                                <i class="fa fa-book me-2"></i> Kartu Stok Order Customer
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (canAccessMenu('rekap_sales_order', $allowedMenus)): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=rekap_sales_order">
                                <i class="fa fa-chart-line me-2"></i> Rekap Sales Order
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (canAccessMenu('aging', $allowedMenus)): ?>
                        <div class="menu-header">Laporan Piutang</div>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=aging">
                                <i class="fa fa-clock me-2"></i> Aging Piutang
                            </a>
                        </li>
                        <?php endif; ?>
                        
                    <?php endif; ?>

                    <!-- ================== IT BACKEND ADMIN ================== -->
                    <?php if ($user_role == 1): ?>
                    <div class="menu-header">Program Admin</div>
                        
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=konfigurasi">
                                <i class="fa fa-sliders-h me-2"></i> Konfigurasi Server
                            </a>
                        </li>
                        
                        <li class="nav-item">
                            <a class="nav-link <?= isset($_GET['page']) && $_GET['page'] == 'user-akses' ? 'active' : '' ?>" 
                               href="index.php?page=user-akses">
                                <i class="fa fa-user-shield me-2"></i> User Akses
                            </a>
                        </li>
                        
                        <!-- ================== TAMBAH MENU (NEW) ================== -->
                        <li class="nav-item">
                            <a class="nav-link <?= isset($_GET['page']) && $_GET['page'] == 'add-menu' ? 'active' : '' ?>" 
                               href="index.php?page=add-menu">
                                <i class="fa fa-plus-circle me-2"></i> Tambah Menu
                            </a>
                        </li>
                        
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=backup">
                                <i class="fa fa-database me-2"></i> Backup Database
                            </a>
                        </li>
                        
                    <?php endif; ?>
                    
                    <!-- ================== MENU DARI DATABASE (OTOMATIS) ================== -->
                    <?php
                    // Tampilkan menu tambahan dari database yang tidak termasuk dalam menu statis di atas
                    $staticMenus = ['dashboard', 'customer', 'supplier', 'area', 'marketing', 'sales', 
                                   'inventory', 'category', 'gudang', 'mesin', 'uom', 'purchase_order', 
                                   'sales_order', 'sop', 'shipping', 'invoice', 'retur-invoice', 
                                   'pembayaran', 'downpayment', 'titip_uang', 'kartu-stok', 
                                   'rekap_sales_order', 'aging', 'konfigurasi', 'user-akses', 
                                   'add-menu', 'backup', 'ganti-password'];
                    
                    foreach ($menusByGroup as $group => $menus) {
                        $hasGroupMenu = false;
                        foreach ($menus as $menu) {
                            if (!in_array($menu['menu_key'], $staticMenus) && canAccessMenu($menu['menu_key'], $allowedMenus)) {
                                $hasGroupMenu = true;
                                break;
                            }
                        }
                        if ($hasGroupMenu) {
                            $label = isset($groupLabels[$group]) ? $groupLabels[$group] : ucfirst($group);
                            echo '<div class="menu-header">' . $label . '</div>';
                            foreach ($menus as $menu) {
                                if (!in_array($menu['menu_key'], $staticMenus) && canAccessMenu($menu['menu_key'], $allowedMenus)) {
                                    $icon = !empty($menu['icon']) ? $menu['icon'] : 'fa-file';
                                    echo '<li class="nav-item">';
                                    echo '  <a class="nav-link" href="index.php?page=' . $menu['menu_key'] . '">';
                                    echo '    <i class="fa ' . $icon . ' me-2"></i> ' . $menu['menu_name'];
                                    echo '  </a>';
                                    echo '</li>';
                                }
                            }
                        }
                    }
                    ?>
                    
                </ul>
            </div>
        </nav>

        <!-- ====================================================================== -->
        <!-- KONTEN UTAMA APLIKASI (Buka tag col-md-9) -->
        <!-- ====================================================================== -->
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-3">

