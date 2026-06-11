<?php
// index.php
// INTERSCEPTOR AJAX: Jika ada request AJAX, langsung arahkan ke modulnya 
// TANPA memuat header.php agar JSON tidak rusak oleh HTML!
// ======================================================================
if (isset($_GET['action'])) {
    include 'koneksi.php'; // pastikan koneksi ada untuk AJAX
    $page = isset($_GET['page']) ? $_GET['page'] : '';
    
    if ($page == 'sop') {
        include 'modul/transaksi/sop.php'; // Sesuaikan dengan jalur folder sop.php Anda
        exit;
    }
    // Jika ada modul lain yang menggunakan AJAX di kemudian hari, tambahkan di sini
}


// 1. Load Header & Proteksi Session
include 'header.php';

// 2. Mengatur Routing Halaman Utama Berdasarkan Parameter ?page=
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

switch ($page) {
    case 'dashboard':
        echo "
        <div class='d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom'>
            <h1 class='h4'>Dashboard Utama</h1>
        </div>
        <div class='alert alert-info'>Selamat Datang kembali, <b>".$_SESSION['username']."</b>! Anda masuk sebagai <b>".$_SESSION['nama_role']."</b>.</div>
        ";
        break;

    // --- MODUL MASTER DATA ---
    case 'customer':
        include 'modul/master/customer.php';
        break;
    case 'supplier':
        include 'modul/master/supplier.php';
        break;
    case 'import_customer':
        include 'modul/master/import_customer.php';
        break;
     case 'export_customer':
        include 'modul/master/export_customer.php';
        break;
    case 'area':
        include 'modul/master/area.php';
        break;
    case 'marketing':
        include 'modul/master/marketing.php';
        break;
    case 'sales':
        include 'modul/master/sales.php';
        break;
    case 'inventory':
        include 'modul/master/inventory.php';
        break;
    case 'category':
        include 'modul/master/category.php';
        break;
    case 'gudang':
        include 'modul/master/gudang.php';
        break;
    case 'mesin':
        include 'modul/master/mesin.php';
        break;
     case 'export_mesin':
        include 'modul/master/export_mesin.php';
        break;
    case 'import_mesin':
        include 'modul/master/import_mesin.php';
        break;
    case 'uom':
        include 'modul/master/uom.php';
        break;

    // --- MODUL TRANSAKSI ---
    case 'purchase_order':
        include 'modul/transaksi/purchase_order.php';
        break;
    case 'sales_order':
        include 'modul/transaksi/sales_order.php';
        break;
    case 'edit_sales_order':
        include 'modul/transaksi/edit_sales_order.php';
        break;
    case 'save_sales_order':
        include 'modul/transaksi/save_sales_order.php';
        break;
     case 'update_sales_order':
        include 'modul/transaksi/update_sales_order.php';
        break;
    case 'add_sales_order':
        include 'modul/transaksi/add_sales_order.php';
        break;
     case 'sop':
        include 'modul/transaksi/sop.php';
        break;
    case 'shipping':
        include 'modul/transaksi/shipping.php';
        break;
    case 'invoice':
        include 'modul/transaksi/invoice.php';
        break;
   case 'rekap_sales_order':  // Ganti dari 'rekap-sales-order' menjadi 'rekap_sales_order'
        include 'modul/transaksi/rekap_sales_order.php';
        break;
    case 'cetak_rekap_sales_order':  // Tambahkan untuk halaman cetak
        include 'modul/transaksi/cetak_rekap_sales_order.php';
        break;
    // --- PROGRAM / KEAMANAN ---
    case 'ganti-password':
        include 'modul/program/ganti_password.php';
        break;

    // --- DEFAULT JIKA PAGE TIDAK DITEMUKAN ---
    default:
        echo "<div class='alert alert-danger mt-3'>Halaman Modul tidak ditemukan atau Anda tidak memiliki akses!</div>";
        break;
}

// 3. Load Footer Aplikasi
include 'footer.php';
?>