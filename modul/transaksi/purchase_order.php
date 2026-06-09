<?php
// modul/transaksi/purchase_order.php

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

// ----------------------------------------------------
// FUNGSI AUTO GENERATE NO PO
// ----------------------------------------------------
function generatePO($conn) {
    $tahun = date('Y');
    
    $q = mysqli_query($conn, "SELECT no_po FROM hed_po WHERE YEAR(created_at) = '$tahun' ORDER BY id DESC LIMIT 1");
    $d = mysqli_fetch_assoc($q);
    
    if ($d) {
        $last = explode('/', $d['no_po'])[0];
        $urut = (int)$last + 1;
    } else {
        $urut = 1;
    }
    
    return str_pad($urut, 3, "0", STR_PAD_LEFT) . "/PO/" . $tahun;
}

// ----------------------------------------------------
// TAMPILKAN ALERT DARI SESSION
// ----------------------------------------------------
$alert_message = "";
if (isset($_SESSION['alert'])) {
    $alert_message = $_SESSION['alert'];
    unset($_SESSION['alert']);
}

// ----------------------------------------------------
// PROSES SIMPAN PO
// ----------------------------------------------------
if (isset($_POST['simpan'])) {
    $no_po = generatePO($conn);
    $tgl = mysqli_real_escape_string($conn, $_POST['tgl']);
    $customer_id = mysqli_real_escape_string($conn, $_POST['customer_id'] ?? '');
    $customer_name = mysqli_real_escape_string($conn, $_POST['customer_name'] ?? '');
    
    // Ambil nama customer dari database jika customer_id diisi
    if (!empty($customer_id)) {
        $q_cust = mysqli_query($conn, "SELECT customer FROM m_customer WHERE customer_id='$customer_id'");
        $cust = mysqli_fetch_assoc($q_cust);
        $customer_name = $cust['customer'];
    }
    
    $user = $_SESSION['username'];
    
    mysqli_query($conn, "INSERT INTO hed_po (no_po, tgl_order, customer, customer_id, created_by, created_at) 
                         VALUES ('$no_po','$tgl','$customer_name','$customer_id','$user', NOW())");
    
    foreach ($_POST['ukuran'] as $i => $u) {
        if (!empty($u)) {
            $jml = mysqli_real_escape_string($conn, $_POST['jml'][$i] ?? 0);
            $harga = mysqli_real_escape_string($conn, $_POST['harga'][$i] ?? 0);
           // Di proses simpan dan update
            $kg = mysqli_real_escape_string($conn, $_POST['kg'][$i] ?? 0);
            // Bersihkan harga_kg hanya angka (double proteksi)
            $kg_clean = preg_replace('/[^0-9]/', '', $kg);
            // Jika kosong, set 0
            $kg_clean = $kg_clean !== '' ? (float)$kg_clean : 0;
            
            mysqli_query($conn, "INSERT INTO det_po (no_po, ukuran, jml_order, harga, harga_kg) 
                                 VALUES ('$no_po', '$u', '$jml', '$harga', '$kg_clean')");
        }
    
    }
    
    $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>PO Berhasil Disimpan! No PO: $no_po</div>";
    echo "<script>window.location.href='index.php?page=purchase_order&action=cetak&no_po=$no_po';</script>";
    exit;
}

// ----------------------------------------------------
// PROSES HAPUS PO
// ----------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['no_po'])) {
    $no_po = mysqli_real_escape_string($conn, $_GET['no_po']);
    mysqli_query($conn, "DELETE FROM det_po WHERE no_po = '$no_po'");
    mysqli_query($conn, "DELETE FROM hed_po WHERE no_po = '$no_po'");
    $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>PO $no_po berhasil dihapus!</div>";
    echo "<script>window.location.href='index.php?page=purchase_order';</script>";
    exit;
}

// ----------------------------------------------------
// PROSES EDIT PO
// ----------------------------------------------------
if (isset($_POST['update'])) {
  
    $tgl           = mysqli_real_escape_string($conn, $_POST['tgl']);
    $customer_name = mysqli_real_escape_string($conn, $_POST['customer_name'] ?? '');
    $customer_id   = mysqli_real_escape_string($conn, $_POST['customer_id'] ?? '');

    $q_cust = mysqli_query($conn, "SELECT customer_id FROM m_customer WHERE customer='$customer_name' LIMIT 1");
    if ($q_cust && mysqli_num_rows($q_cust) > 0) {
        $cust = mysqli_fetch_assoc($q_cust);
        $customer_id = $cust['customer_id'];
    }

    // Update hed_po termasuk no_po baru
    $no_po_lama    = mysqli_real_escape_string($conn, $_POST['no_po_lama']);
    $no_po_baru    = mysqli_real_escape_string($conn, $_POST['no_po']);
    
    // ✅ Validasi: pastikan no_po_lama tidak kosong
    if (empty($no_po_lama)) {
        $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: No PO lama tidak ditemukan!</div>";
        echo "<script>window.location.href='index.php?page=purchase_order';</script>";
        exit;
    }
    
    // ✅ Debug: cek apakah record dengan no_po_lama ada
    $check = mysqli_query($conn, "SELECT id FROM hed_po WHERE no_po='$no_po_lama'");
    if (mysqli_num_rows($check) == 0) {
        $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: Record dengan no_po '$no_po_lama' tidak ditemukan!</div>";
        echo "<script>window.location.href='index.php?page=purchase_order';</script>";
        exit;
    }
    
    // Update hed_po
    $update_hed = mysqli_query($conn, "UPDATE hed_po SET 
        no_po='$no_po_baru',
        tgl_order='$tgl', 
        customer='$customer_name', 
        customer_id='$customer_id' 
        WHERE no_po='$no_po_lama'");
    
    if (!$update_hed) {
        $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error update hed_po: " . mysqli_error($conn) . "</div>";
        echo "<script>window.location.href='index.php?page=purchase_order';</script>";
        exit;
    }

    // Update det_po: hapus lama pakai no_po LAMA, insert pakai no_po BARU
    mysqli_query($conn, "DELETE FROM det_po WHERE no_po='$no_po_lama'");
    if (isset($_POST['ukuran']) && is_array($_POST['ukuran'])) {
        foreach ($_POST['ukuran'] as $i => $u) {
            if (!empty($u)) {
                $jml = mysqli_real_escape_string($conn, $_POST['jml'][$i] ?? 0);
                $harga = mysqli_real_escape_string($conn, $_POST['harga'][$i] ?? 0);
                $kg = mysqli_real_escape_string($conn, $_POST['kg'][$i] ?? 0);
                
                // Bersihkan harga_kg hanya angka
                $kg_clean = preg_replace('/[^0-9]/', '', $kg);
                
                mysqli_query($conn, "INSERT INTO det_po (no_po, ukuran, jml_order, harga, harga_kg) 
                                     VALUES ('$no_po_baru', '$u', '$jml', '$harga', '$kg_clean')");
            }
        }
    }

    $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>PO $no_po_baru berhasil diupdate!</div>";
    echo "<script>window.location.href='index.php?page=purchase_order';</script>";
    exit;
}

// ----------------------------------------------------
// AJAX GET DETAIL PO (Untuk Edit Modal) - LANGSUNG DI SINI
// ----------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == 'get_detail' && isset($_GET['no_po'])) {
    $no_po = mysqli_real_escape_string($conn, $_GET['no_po']);
    // Query ke det_po (BUKAN hed_po)
    $query = mysqli_query($conn, "SELECT ukuran, jml_order, harga, harga_kg FROM det_po WHERE no_po='$no_po'");
    $data = [];
    while ($row = mysqli_fetch_assoc($query)) {
        $data[] = $row;
    }
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ----------------------------------------------------
// TAMPILAN CETAK SO
// ----------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == 'cetak' && isset($_GET['no_po'])) {
    $no_po = mysqli_real_escape_string($conn, $_GET['no_po']);
    $query_h = mysqli_query($conn, "SELECT * FROM hed_po WHERE no_po='$no_po'");
    $h = mysqli_fetch_assoc($query_h);
    $d = mysqli_query($conn, "SELECT * FROM det_po WHERE no_po='$no_po'");
    
    if (!$h) {
        echo "<script>window.location.href='index.php?page=purchase_order';</script>";
        exit;
    }
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Cetak SO - <?= htmlspecialchars($h['no_po']); ?></title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Arial', sans-serif; background: #f8f9fa; padding: 20px; }
            .print-container { background: white; padding: 40px; max-width: 800px; margin: 0 auto; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
            .company-name { text-align: center; font-size: 14px; font-weight: bold; margin-bottom: 20px; }
            .title { text-align: center; font-size: 20px; font-weight: bold; text-decoration: underline; margin-bottom: 25px; text-transform: uppercase; }
            .info-table { margin-bottom: 25px; font-size: 13px; width: 60%; }
            .info-table td { padding: 4px 0; }
            .info-table td:first-child { width: 100px; font-weight: bold; }
            table.main-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; border: 1px solid #000; }
            table.main-table th, table.main-table td { border: 1px solid #000; padding: 8px; font-size: 12px; }
            table.main-table th { background: #e8e8e8; text-align: center; }
            .signature-container { width: 100%; margin-top: 40px; font-size: 12px; overflow: hidden; }
            .sig-box { width: 48%; float: left; text-align: center; }
            .sig-box-right { width: 48%; float: right; text-align: center; }
            .sig-box p, .sig-box-right p { margin-bottom: 60px; font-weight: bold; }
            .button-container { margin-top: 30px; text-align: center; }
            .btn-print { background: #0d6efd; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
            .btn-back { background: #6c757d; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; display: inline-block; }
            @media print { .button-container, .no-print { display: none !important; } body { background: white; padding: 0; } .print-container { box-shadow: none; padding: 0; } }
        </style>
    </head>
    <body>
    <div class="print-container">
        <div class="company-name">PT MUTIARA CAHAYA PLASTINDO</div>
        <div class="title">SALES ORDER</div>
        <table class="info-table">
            <tr><td>Nomor SO</td><td>: <?= htmlspecialchars($h['no_po']); ?></td></tr>
            <tr><td>Tanggal</td><td>: <?= date('d/m/Y', strtotime($h['tgl_order'])); ?></td></tr>
            <tr><td>Customer</td><td>: <?= htmlspecialchars($h['customer']); ?></td></tr>
        </table>
        <table class="main-table">
            <thead>
                <tr>
                    <th width="5%">No</th>
                    <th>Ukuran / Deskripsi</th>
                    <th width="15%">Jml Order</th>
                    <th width="20%">Harga</th>      <!-- Tambahkan ini -->
                    <th width="20%">Harga/Kg</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1; while($row = mysqli_fetch_assoc($d)){ ?>
                <tr>
                    <td style="text-align:center"><?= $no++ ?>.</td>
                    <td><?= htmlspecialchars($row['ukuran']); ?></td>
                    <td style="text-align:center"><?= htmlspecialchars($row['jml_order']); ?></td>
                    <td style="text-align:left"><?= htmlspecialchars($row['harga'] ?: '-'); ?></td>
                    <td style="text-align:right">
                        <?php 
                        if ($row['harga_kg'] > 0) {
                            echo 'Rp ' . number_format($row['harga_kg'], 0, ',', '.');
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
        <div class="signature-container">
            <div class="sig-box"><p>Diperiksa Oleh :</p><div>( ____________________ )</div></div>
            <div class="sig-box-right"><p>Dibuat Oleh :</p><div>( <?= strtoupper(htmlspecialchars($h['created_by'])); ?> )</div></div>
        </div>
    </div>
    <div class="button-container no-print">
        <button class="btn-print" onclick="window.print()">🖨️ Cetak SO</button>
        <a href="index.php?page=sales_order" class="btn-back">← Kembali ke List SO</a>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// ----------------------------------------------------
// FILTER PENCARIAN
// ----------------------------------------------------
$search_keyword = "";
$where_clause = "";
if (isset($_POST['btn_search'])) {
    $search_keyword = mysqli_real_escape_string($conn, trim($_POST['search_keyword']));
    if (!empty($search_keyword)) {
        $where_clause = "WHERE no_po LIKE '%$search_keyword%' OR customer LIKE '%$search_keyword%'";
    }
}

// Ambil data PO
$query_list = mysqli_query($conn, "SELECT * FROM hed_po $where_clause ORDER BY id DESC");
$data_po = [];
while ($row = mysqli_fetch_assoc($query_list)) {
    $data_po[] = $row;
}

// Ambil data customer untuk dropdown
$customers = mysqli_query($conn, "SELECT customer_id, customer FROM m_customer WHERE is_active='Checked' ORDER BY customer ASC");
?>

<!-- CSS STYLE SAMA SEPERTI SEBELUMNYA (tidak diubah) -->
<style>
    .btn-vs {
        padding: 8px 20px !important;
        font-size: 13px !important;
        font-weight: 600 !important;
        border-radius: 5px !important;
        transition: all 0.2s ease;
        box-shadow: 0 1px 3px rgba(0,0,0,0.12);
    }
    .btn-vs i { margin-right: 8px; font-size: 14px; }
    .btn-vs:hover { transform: translateY(-1px); box-shadow: 0 2px 6px rgba(0,0,0,0.15); }
    .btn-add { background: #0d6efd !important; color: white !important; border: none; }
    .btn-add:hover { background: #0b5ed7 !important; }
    .btn-edit { background: #ffc107 !important; color: #000 !important; border: none; padding: 4px 10px !important; font-size: 10px !important; border-radius: 3px; cursor: pointer; }
    .btn-delete { background: #dc3545 !important; color: white !important; border: none; padding: 4px 10px !important; font-size: 10px !important; border-radius: 3px; cursor: pointer; text-decoration: none; display: inline-block; }
    .btn-cetak { background: #17a2b8 !important; color: white !important; border: none; padding: 4px 10px !important; font-size: 10px !important; border-radius: 3px; cursor: pointer; text-decoration: none; display: inline-block; }
    .btn-edit:hover, .btn-delete:hover, .btn-cetak:hover { transform: translateY(-1px); }
    
    .table-crystal-report {
        font-family: 'Segoe UI', Tahoma, Arial, sans-serif !important;
        font-size: 11px !important;
        border-collapse: collapse !important;
        width: 100%;
    }
    .table-crystal-report th {
        background-color: #f0f4f8 !important;
        color: #2b4c7e !important;
        font-weight: bold !important;
        text-align: center !important;
        padding: 8px 10px !important;
        border: 1px solid #c0cddb !important;
        white-space: nowrap !important;
    }
    .table-crystal-report td {
        padding: 6px 10px !important;
        border: 1px solid #d3d3d3 !important;
        line-height: 1.2 !important;
        vertical-align: middle !important;
    }
    .table-crystal-report tbody tr:hover { background-color: #e8f2fe !important; }
    .sticky-col-aksi { position: sticky; left: 0; background: white; z-index: 2; }
    
    .customer-select {
        width: 100%;
        padding: 6px 10px;
        font-size: 12px;
        border: 1px solid #ced4da;
        border-radius: 4px;
    }
</style>

<!-- HALAMAN UTAMA LIST PO (sama seperti sebelumnya) -->
<div class="d-print-none">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-1 pb-2 mb-3 border-bottom">
        <h5 class="fw-bold text-dark m-0"><i class="fa fa-shopping-cart text-success"></i> Purchase Order</h5>
        <div class="d-flex gap-2">
            <button class="btn-vs btn-add" onclick="showModalTambah()"><i class="fa fa-plus-circle"></i> Buat PO Baru</button>
        </div>
    </div>
    <?= $alert_message; ?>
</div>

<!-- Form Pencarian -->
<div class="card shadow-sm mb-3 d-print-none">
    <div class="card-body py-2">
        <form method="POST" action="index.php?page=purchase_order" class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" name="search_keyword" class="form-control form-control-sm" 
                       placeholder="Cari No PO atau Customer..." value="<?= htmlspecialchars($search_keyword) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" name="btn_search" class="btn btn-sm btn-dark"><i class="fa fa-search"></i> Cari Data</button>
                <a href="index.php?page=purchase_order" class="btn btn-sm btn-outline-secondary"><i class="fa fa-sync"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Tabel Data PO -->
<div class="table-responsive" style="max-height: 500px; overflow-y: auto; border: 1px solid #c0cddb;">
    <table class="table-crystal-report table-hover">
        <thead>
            <tr>
                <th class="sticky-col-aksi" style="min-width: 180px;">Aksi</th>
                <th style="min-width: 130px;">No PO</th>
                <th style="min-width: 100px;">Tanggal</th>
                <th style="min-width: 250px;">Customer</th>
                <th style="min-width: 100px;">Dibuat Oleh</th>
                <th style="min-width: 150px;">Tanggal Buat</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($data_po)): ?>
            <tr><td colspan="6" class="text-center text-muted py-3">Tidak ada data PO ditemukan.</td></tr>
            <?php else: ?>
                <?php foreach ($data_po as $d): ?>
                <tr>
                    <td class="sticky-col-aksi" style="white-space: nowrap;">
                        <a href="index.php?page=purchase_order&action=cetak&no_po=<?= urlencode($d['no_po']) ?>" target="_blank" class="btn-cetak"><i class="fa fa-print"></i> Cetak</a>
                        <button class="btn-edit" onclick='showModalEdit(<?= json_encode($d); ?>)'><i class="fa fa-edit"></i> Edit</button>
                        <a href="javascript:void(0)" onclick="confirmDelete('<?= $d['no_po'] ?>', '<?= addslashes($d['no_po']) ?>')" class="btn-delete"><i class="fa fa-trash"></i> Hapus</a>
                    </td>
                    <td class="fw-bold text-primary"><?= htmlspecialchars($d['no_po']) ?></td>
                    <td><?= date('d/m/Y', strtotime($d['tgl_order'])) ?></td>
                    <td><?= htmlspecialchars($d['customer']) ?></td>
                    <td><?= htmlspecialchars($d['created_by']) ?></td>
                    <td><?= htmlspecialchars($d['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- MODAL TAMBAH PO -->
<div class="modal fade d-print-none" id="modalTambahPO" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: 10px;">
            <div class="modal-header bg-dark text-white py-2">
                <h6 class="modal-title fw-bold"><i class="fa fa-plus-circle"></i> Buat Purchase Order Baru</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="index.php?page=purchase_order" id="formTambahPO">
                <div class="modal-body p-3">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">📅 Tanggal Order</label>
                            <input type="date" name="tgl" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-bold small">👥 Nama Customer</label>
                            <select name="customer_id" id="customer_id_tambah" class="customer-select" required>
                                <option value="">-- Pilih Customer --</option>
                                <?php 
                                mysqli_data_seek($customers, 0);
                                while($cust = mysqli_fetch_assoc($customers)): 
                                ?>
                                <option value="<?= $cust['customer_id'] ?>"><?= htmlspecialchars($cust['customer']) ?></option>
                                <?php endwhile; ?>
                            </select>
                            <input type="hidden" name="customer_name" id="customer_name_tambah">
                        </div>
                    </div>
                    <label class="form-label fw-bold small mb-2">📦 Detail Item Pesanan</label>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm" style="font-size: 11px;">
                            <thead class="table-secondary">
                                <tr class="text-center">
                                    <th style="width: 40%;">Ukuran / Produk</th>
                                    <th style="width: 20%;">Jumlah</th>
                                    <th style="width: 20%;">Harga (Rp)</th>
                                    <th style="width: 20%;">Harga/Kg</th>
                                </tr>
                            </thead>
                            <tbody id="detailTambahBody">
                                <?php for ($i = 0; $i < 8; $i++) { ?>
                                <tr>
                                    <td><input type="text" name="ukuran[]" class="form-control form-control-sm" placeholder="Masukkan ukuran produk"></td>
                                    <td><input type="text" name="jml[]" class="form-control form-control-sm text-center" placeholder="0"></td>
                                    <td><input type="text" name="harga[]" class="form-control form-control-sm text-center" placeholder="0"></td>
                                    <td><input type="text" name="kg[]" class="form-control form-control-sm text-center" placeholder="0"></td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="simpan" class="btn btn-sm btn-primary fw-bold px-3"><i class="fa fa-save"></i> Simpan & Cetak PO</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL EDIT PO -->
<div class="modal fade d-print-none" id="modalEditPO" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: 10px;">
            <div class="modal-header bg-warning text-dark py-2">
                <h6 class="modal-title fw-bold"><i class="fa fa-edit"></i> Edit Purchase Order</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="index.php?page=purchase_order" id="formEditPO">
                
                <div class="modal-body p-3">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">📅 Tanggal Order</label>
                            <input type="date" name="tgl" id="edit_tgl" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Nomor PO</label>
                            <input type="text" name="no_po" id="edit_no_po" class="form-control form-control-sm" required>
                            <input type="hidden" name="no_po_lama" id="edit_no_po_lama">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-bold small">👥 Nama Customer</label>
                            <input type="text" name="customer_name" id="edit_customer_name" 
                                class="form-control form-control-sm" 
                                placeholder="Nama customer..." required>
                            <input type="hidden" name="customer_id" id="edit_customer_id">
                            <small class="text-muted" style="font-size:10px;">Ketik nama customer lalu pilih dari saran</small>
                        </div>
                    </div>
                    <label class="form-label fw-bold small mb-2">📦 Detail Item Pesanan</label>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm" style="font-size: 11px;">
                            <thead class="table-secondary">
                                <tr class="text-center">
                                    <th style="width: 40%;">Ukuran / Produk</th>
                                    <th style="width: 20%;">Jumlah</th>
                                    <th style="width: 20%;">Harga (Rp)</th>
                                    <th style="width: 20%;">Harga/Kg</th>
                                </tr>
                            </thead>
                            <tbody id="detailEditBody">
                                <!-- Data akan diisi via AJAX -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="update" class="btn btn-sm btn-warning fw-bold px-3"><i class="fa fa-save"></i> Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var modalTambah, modalEdit;

document.addEventListener("DOMContentLoaded", function() {
    modalTambah = new bootstrap.Modal(document.getElementById('modalTambahPO'));
    modalEdit = new bootstrap.Modal(document.getElementById('modalEditPO'));
    
    // Set customer name on select change - Tambah
    document.getElementById('customer_id_tambah').addEventListener('change', function() {
        var selected = this.options[this.selectedIndex];
        document.getElementById('customer_name_tambah').value = selected.text;
    });
    
   
   
});

function showModalTambah() {
    document.getElementById('formTambahPO').reset();
    document.getElementById('customer_id_tambah').value = '';
    document.getElementById('customer_name_tambah').value = '';
    modalTambah.show();
}

// Pindahkan event listener ke DOMContentLoaded
document.addEventListener("DOMContentLoaded", function() {
    modalTambah = new bootstrap.Modal(document.getElementById('modalTambahPO'));
    modalEdit = new bootstrap.Modal(document.getElementById('modalEditPO'));
    
    // Set customer name on select change - Tambah
    document.getElementById('customer_id_tambah').addEventListener('change', function() {
        var selected = this.options[this.selectedIndex];
        document.getElementById('customer_name_tambah').value = selected.text;
    });
    
    // Tambahkan event listener untuk bersihkan input kg sebelum submit
    document.getElementById('formTambahPO').addEventListener('submit', function(e) {
        var kgInputs = document.querySelectorAll('#formTambahPO input[name="kg[]"]');
        for(var i = 0; i < kgInputs.length; i++) {
            var value = kgInputs[i].value;
            // Hanya ambil angka, hapus titik dan Rp jika ada
            kgInputs[i].value = value.replace(/[^0-9]/g, '');
        }
    });
    
    // Juga untuk form edit
    document.getElementById('formEditPO').addEventListener('submit', function(e) {
        var kgInputs = document.querySelectorAll('#formEditPO input[name="kg[]"]');
        for(var i = 0; i < kgInputs.length; i++) {
            var value = kgInputs[i].value;
            kgInputs[i].value = value.replace(/[^0-9]/g, '');
        }
    });
});


function showModalEdit(dataObj) {
    document.getElementById('formEditPO').reset();
    
    // Reset dan isi nilai
    document.getElementById('edit_no_po').value = dataObj.no_po;
    document.getElementById('edit_tgl').value = dataObj.tgl_order;
    document.getElementById('edit_no_po_lama').value = dataObj.no_po; 
    document.getElementById('edit_customer_name').value = dataObj.customer;
    document.getElementById('edit_customer_id').value = dataObj.customer_id ?? '';
    
    // Kosongkan detail body dulu
    document.getElementById('detailEditBody').innerHTML = '';
    
    // Load detail items via AJAX
    fetch('modul/transaksi/get_detail_po.php?no_po=' + encodeURIComponent(dataObj.no_po))
        .then(response => response.json())
        .then(data => {
            let html = '';
            for (let i = 0; i < 8; i++) {
                let ukuran = i < data.length ? escapeHtml(data[i].ukuran) : '';
                let jml    = i < data.length ? escapeHtml(data[i].jml_order) : '';
                let harga  = i < data.length ? escapeHtml(data[i].harga) : '';
                let kg = i < data.length ? formatNumber(data[i].harga_kg) : '';
                
                html += `<tr>
                    <td><input type="text" name="ukuran[]" class="form-control form-control-sm" value="${ukuran}" placeholder="Masukkan ukuran produk"></td>
                    <td><input type="text" name="jml[]" class="form-control form-control-sm text-center" value="${jml}" placeholder="0"></td>
                    <td><input type="text" name="harga[]" class="form-control form-control-sm text-center" value="${harga}" placeholder="0"></td>
                    <td><input type="text" name="kg[]" class="form-control form-control-sm text-center" value="${kg}" placeholder="0"></td>
                </tr>`;
            }
            document.getElementById('detailEditBody').innerHTML = html;
            modalEdit.show();
        })
        .catch(error => {
            console.error('Error:', error);
            // Tetap tampilkan modal dengan form kosong
            let html = '';
            for (let i = 0; i < 8; i++) {
                html += `<tr>
                    <td><input type="text" name="ukuran[]" class="form-control form-control-sm" placeholder="Masukkan ukuran produk"></td>
                    <td><input type="text" name="jml[]" class="form-control form-control-sm text-center" placeholder="0"></td>
                    <td><input type="text" name="harga[]" class="form-control form-control-sm text-center" placeholder="0"></td>
                    <td><input type="text" name="kg[]" class="form-control form-control-sm text-center" placeholder="0"></td>
                </tr>`;
            }
            document.getElementById('detailEditBody').innerHTML = html;
            modalEdit.show();
        });
}
function formatNumber(num) {
    if (!num || num == 0) return '';
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
}

function confirmDelete(no_po, name) {
    if (confirm('Hapus PO ' + name + '?')) {
        window.location.href = 'index.php?page=purchase_order&action=delete&no_po=' + encodeURIComponent(no_po);
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}
</script>