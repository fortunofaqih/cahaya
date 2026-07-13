<?php
// index.php
// ======================================================================
// HANDLER UNTUK BACKUP (DOWNLOAD, DELETE, ACTION)
// ======================================================================
if (isset($_GET['download']) || isset($_GET['delete']) || isset($_GET['action'])) {
    include 'koneksi.php';
    
    // Jika ada parameter download, delete, atau action=backup
    if (isset($_GET['download']) || isset($_GET['delete']) || (isset($_GET['action']) && $_GET['action'] == 'backup')) {
        include 'modul/program/backup.php';
        exit;
    }
}

// ======================================================================
// AJAX INTERSCEPTOR
// ======================================================================
if (isset($_GET['action'])) {
    include 'koneksi.php';
    $page = isset($_GET['page']) ? $_GET['page'] : '';
    
    if ($page == 'sop') {
        include 'modul/transaksi/sop.php';
        exit;
    }
}

// ======================================================================
// LOAD HEADER & PROTEKSI SESSION
// ======================================================================
include 'header.php';

// ======================================================================
// ROUTING HALAMAN
// ======================================================================
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

switch ($page) {
    case 'dashboard':
        // Cek maintenance mode untuk IT Admin
        $configQuery = "SELECT maintenance_mode FROM sys_config WHERE id_config = 1";
        $configResult = mysqli_query($conn, $configQuery);
        $configData = mysqli_fetch_assoc($configResult);
        
        if ($configData && $configData['maintenance_mode'] == '1' && $_SESSION['id_role'] == 1) {
            echo "
            <div class='alert alert-warning alert-dismissible fade show' role='alert'>
                <i class='fa fa-exclamation-triangle me-2'></i>
                <strong>⚠️ Maintenance Mode AKTIF!</strong> 
                Hanya IT Admin yang dapat mengakses sistem. 
                Nonaktifkan di <a href='index.php?page=konfigurasi' class='alert-link'>Konfigurasi Server</a>
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
            </div>
            ";
        }
        
        echo "
        <div class='d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom'>
            <h1 class='h4'>Dashboard Utama</h1>
        </div>
        <div class='alert alert-info'>Selamat Datang kembali, <b>".htmlspecialchars($_SESSION['username'])."</b>! Selamat Bekerja <b>".htmlspecialchars($_SESSION['nama_role'])."</b>.</div>
        ";
        break;

    // --- MODUL MASTER DATA ---
    case 'customer':
        include 'modul/master/customer.php';
        break;
    case 'import_customer':
        include 'modul/master/import_customer.php';
        break;
    case 'import_uom':
        include 'modul/master/import_uom_page.php';
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
    case 'add_shipping':
        include 'modul/transaksi/add_shipping.php';
        break;
    case 'save_shipping':
        include 'modul/transaksi/save_shipping.php';
        break;
    case 'edit_shipping':
        include 'modul/transaksi/edit_shipping.php';
        break;
    case 'update_shipping':
        include 'modul/transaksi/update_shipping.php';
        break;
    case 'delete_shipping':
        include 'modul/transaksi/delete_shipping.php';
        break;
    case 'cetak_shipping':
        include 'modul/transaksi/cetak_shipping.php';
        break;
    case 'rekap_sales_order':
        include 'modul/transaksi/rekap_sales_order.php';
        break;
    case 'cetak_rekap_sales_order':
        include 'modul/transaksi/cetak_rekap_sales_order.php';
        break;
    case 'kartu_stok_order_customer':
        include 'modul/transaksi/kartu_stok_order_customer.php';
        break;
    case 'cetak_kartu_stok_order_customer':
        include 'modul/transaksi/cetak_kartu_stok_order_customer.php';
        break;
    case 'ajax_kartu_stok_order_customer_detail':
        include 'modul/transaksi/ajax_kartu_stok_order_customer_detail.php';
        break;
    case 'invoice':
        include 'modul/transaksi/invoice.php';
        break;
    case 'add_invoice':
        include 'modul/transaksi/add_invoice.php';
        break;
    case 'save_invoice':
        include 'modul/transaksi/save_invoice.php';
        break;
    case 'cetak_invoice':
        include 'modul/transaksi/cetak_invoice.php';
        break;
    case 'edit_invoice':
        include 'modul/transaksi/edit_invoice.php';
        break;
    case 'delete_invoice':
        include 'modul/transaksi/delete_invoice.php';
        break;
    case 'cetak_slip_shipping':
        include 'modul/transaksi/cetak_slip_shipping.php';
        break;
    case 'cetak_slip_without_uom_default':
        include 'modul/transaksi/cetak_slip_without_uom_default.php';
        break;
    case 'kartu_piutang':
        include 'modul/transaksi/kartu_piutang.php';
        break;
    case 'cetak_kartu_piutang':
        include 'modul/transaksi/cetak_kartu_piutang.php';
        break;
    case 'pembayaran':
        include 'modul/transaksi/pembayaran.php';
        break;
    case 'add_bayar':
        include 'modul/transaksi/add_bayar.php';
        break;
    case 'edit_bayar':
        include 'modul/transaksi/edit_bayar.php';
        break;
    case 'titip_uang':
        include 'modul/transaksi/titip_uang.php';
        break;
    case 'add_titip':
        include 'modul/transaksi/add_titip.php';
        break;
    case 'edit_titip':
        include 'modul/transaksi/edit_titip.php';
        break;
    case 'detail_titip':
        include 'modul/transaksi/detail_titip.php';
        break;
    case 'aging_piutang':
        include 'modul/transaksi/aging_piutang.php';
        break;
    case 'cetak_aging_piutang_global':
        include 'modul/transaksi/cetak_aging_piutang_global.php';
        break;
    case 'cetak_aging_piutang_detail':
        include 'modul/transaksi/cetak_aging_piutang_detail.php';
        break;

    // --- PROGRAM / KEAMANAN ---
    case 'ganti-password':
        include 'modul/program/ganti_password.php';
        break;
    case 'user-akses':
        include 'modul/program/user_management.php';
        break;
    case 'add-menu':
        include 'modul/program/add_menu.php';
        break;
    case 'konfigurasi':
        include 'modul/program/konfigurasi.php';
        break;
    case 'backup':
        include 'modul/program/backup.php';
        break;

    // --- DEFAULT ---
    default:
        echo "<div class='alert alert-danger mt-3'>Halaman Modul tidak ditemukan atau Anda tidak memiliki akses!</div>";
        break;
}

// ======================================================================
// LOAD FOOTER
// ======================================================================
include 'footer.php';
?>