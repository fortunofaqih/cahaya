<?php
// modul/master/customer.php

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

// ----------------------------------------------------
// TAMPILKAN ALERT DARI SESSION (PRG Pattern)
// ----------------------------------------------------
$alert_message = "";
if (isset($_SESSION['alert'])) {
    $alert_message = $_SESSION['alert'];
    unset($_SESSION['alert']);
}

// ----------------------------------------------------
// PROSES EXPORT EXCEL
// ----------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == 'export_excel') {
    if (ob_get_level()) ob_end_clean();
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=Master_Customer_Lengkap_" . date('Ymd_His') . ".xls");
    header("Cache-Control: no-cache, must-revalidate");
    ?>
    <table border="1">
        <thead>
            <tr style="background-color: #f2f2f2; font-weight: bold;">
                <th>Customer ID</th><th>Customer</th><th>City</th><th>Address</th>
                <th>Contact Person</th><th>Contact Person Phone</th><th>ID Number</th>
                <th>Phone</th><th>Credit Limit</th><th>Email</th><th>Type</th>
                <th>Parent ID</th><th>Parent Customer</th><th>Bagian</th>
                <th>Is Active</th><th>User Created</th><th>Date Created</th>
                <th>User Modified</th><th>Date Modified</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $q_excel = mysqli_query($conn, "SELECT * FROM m_customer ORDER BY customer_id ASC");
            while ($d = mysqli_fetch_assoc($q_excel)) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($d['customer_id']) . "</td>";
                echo "<td>" . htmlspecialchars($d['customer']) . "</td>";
                echo "<td>" . htmlspecialchars($d['city']) . "</td>";
                echo "<td>" . htmlspecialchars($d['address']) . "</td>";
                echo "<td>" . htmlspecialchars($d['contact_person']) . "</td>";
                echo "<td>" . htmlspecialchars($d['contact_person_phone']) . "</td>";
                echo "<td>" . htmlspecialchars($d['id_number']) . "</td>";
                echo "<td>" . htmlspecialchars($d['phone']) . "</td>";
                echo "<td>" . number_format($d['credit_limit'], 2) . "</td>";
                echo "<td>" . htmlspecialchars($d['email']) . "</td>";
                echo "<td>" . htmlspecialchars($d['type']) . "</td>";
                echo "<td>" . htmlspecialchars($d['parent_id']) . "</td>";
                echo "<td>" . htmlspecialchars($d['parent_customer']) . "</td>";
                echo "<td>" . htmlspecialchars($d['bagian']) . "</td>";
                echo "<td>" . htmlspecialchars($d['is_active']) . "</td>";
                echo "<td>" . htmlspecialchars($d['user_created']) . "</td>";
                echo "<td>" . htmlspecialchars($d['date_created']) . "</td>";
                echo "<td>" . htmlspecialchars($d['user_modified']) . "</td>";
                echo "<td>" . htmlspecialchars($d['date_modified']) . "</td>";
                echo "</tr>";
            }
            ?>
        </tbody>
    </table>
    <?php
    exit;
}

// ----------------------------------------------------
// LOGIC BACKEND (INSERT, UPDATE, DELETE)
// ----------------------------------------------------

// PERBAIKAN: Blok simpan hanya dieksekusi jika POST BUKAN dari tombol search
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['btn_search'])) {
    $action_form            = $_POST['action_form'];
    $id                     = mysqli_real_escape_string($conn, trim($_POST['id']));
    
    if ($action_form == 'insert' && isset($_POST['chk_auto_id'])) {
        $q_max = mysqli_query($conn, "SELECT customer_id FROM m_customer WHERE customer_id REGEXP '^C[0-9]{7}$' ORDER BY customer_id DESC LIMIT 1");
        $row_max = mysqli_fetch_assoc($q_max);
        if ($row_max['customer_id']) {
            $last_num = intval(substr($row_max['customer_id'], 1));
            $next_num = $last_num + 1;
        } else {
            $next_num = 1;
        }
        $id = "C" . str_pad($next_num, 7, '0', STR_PAD_LEFT);
    }

    $customer               = mysqli_real_escape_string($conn, trim($_POST['customer']));
    $city                   = mysqli_real_escape_string($conn, trim($_POST['city']));
    $address                = mysqli_real_escape_string($conn, trim($_POST['address']));
    $contact_person         = mysqli_real_escape_string($conn, trim($_POST['contact_person']));
    $contact_person_phone   = mysqli_real_escape_string($conn, trim($_POST['contact_person_phone']));
    
    $id_number              = mysqli_real_escape_string($conn, trim($_POST['id_number']));
    $id_name                = $customer; 
    $phone                  = mysqli_real_escape_string($conn, trim($_POST['phone']));
    $credit_limit           = floatval($_POST['credit_limit']);
    $email                  = mysqli_real_escape_string($conn, trim($_POST['email']));
    $old_code               = mysqli_real_escape_string($conn, trim($_POST['old_code']));
    $area_code              = mysqli_real_escape_string($conn, trim($_POST['city'])); 
    $remarks                = mysqli_real_escape_string($conn, trim($_POST['remarks']));
    $type                   = mysqli_real_escape_string($conn, trim($_POST['type']));
    $parent_id              = mysqli_real_escape_string($conn, trim($_POST['parent_id']));
    $parent_customer        = mysqli_real_escape_string($conn, trim($_POST['parent_customer']));
    $bagian                 = mysqli_real_escape_string($conn, trim($_POST['bagian']));
    $is_active              = isset($_POST['is_active']) ? 'Checked' : 'Unchecked';
    
    $user_now               = $_SESSION['username'];
    $datetime_now           = date('Y-m-d H:i:s');

    if ($action_form == 'insert') {
        $cek = mysqli_query($conn, "SELECT customer_id FROM m_customer WHERE customer_id='$id'");
        if (mysqli_num_rows($cek) > 0) {
            $_SESSION['alert'] = "<div class='alert alert-danger p-1 mb-1 small style-crystal'>Gagal Simpan: ID Customer sudah terdaftar!</div>";
        } else {
            $sql_insert = "INSERT INTO m_customer (customer_id, customer, city, address, contact_person, contact_person_phone, id_number, id_name, phone, credit_limit, email, old_code, area_code, remarks, type, parent_id, parent_customer, bagian, is_active, user_created, date_created) 
                           VALUES ('$id', '$customer', '$city', '$address', '$contact_person', '$contact_person_phone', '$id_number', '$id_name', '$phone', '$credit_limit', '$email', '$old_code', '$area_code', '$remarks', '$type', '$parent_id', '$parent_customer', '$bagian', '$is_active', '$user_now', '$datetime_now')";
            if (mysqli_query($conn, $sql_insert)) {
                $_SESSION['alert'] = "<div class='alert alert-success p-1 mb-1 small style-crystal'>Data Customer Berhasil Disimpan!</div>";
            } else {
                $_SESSION['alert'] = "<div class='alert alert-danger p-1 mb-1 small style-crystal'>Error: " . mysqli_error($conn) . "</div>";
            }
        }
        
        // Redirect setelah POST (PRG Pattern) - CEGAH DOUBLE SUBMIT
        echo "<script>window.location.href='index.php?page=customer';</script>";
        exit;
        
    } elseif ($action_form == 'update') {
        $sql_update = "UPDATE m_customer SET 
                        customer='$customer', city='$city', address='$address', 
                        contact_person='$contact_person', contact_person_phone='$contact_person_phone',
                        id_number='$id_number', id_name='$id_name', phone='$phone', credit_limit='$credit_limit', 
                        email='$email', old_code='$old_code', area_code='$area_code', remarks='$remarks', type='$type', 
                        parent_id='$parent_id', parent_customer='$parent_customer', bagian='$bagian', 
                        is_active='$is_active', 
                        user_modified='$user_now', date_modified='$datetime_now' 
                       WHERE customer_id='$id'";
        if (mysqli_query($conn, $sql_update)) {
            $_SESSION['alert'] = "<div class='alert alert-success p-1 mb-1 small style-crystal'>Perubahan Data Customer Berhasil Disimpan!</div>";
        } else {
            $_SESSION['alert'] = "<div class='alert alert-danger p-1 mb-1 small style-crystal'>Error: " . mysqli_error($conn) . "</div>";
        }
        
        // Redirect setelah POST (PRG Pattern)
        echo "<script>window.location.href='index.php?page=customer';</script>";
        exit;
    }
}

if (isset($_GET['action']) && $_GET['action'] == 'delete') {
    $id_del = mysqli_real_escape_string($conn, $_GET['id']);
    $delete_query = mysqli_query($conn, "DELETE FROM m_customer WHERE customer_id='$id_del'");
    if ($delete_query && mysqli_affected_rows($conn) > 0) {
        $_SESSION['alert'] = "<div class='alert alert-success p-1 mb-1 small style-crystal'>Data Customer Berhasil Dihapus!</div>";
    } else {
        $_SESSION['alert'] = "<div class='alert alert-danger p-1 mb-1 small style-crystal'>Error Delete: " . mysqli_error($conn) . "</div>";
    }
    echo "<script>window.location.href='index.php?page=customer';</script>";
    exit;
}

// Filter Pencarian
$search_keyword = "";
$where_clause = "";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['btn_search'])) {
    $search_keyword = mysqli_real_escape_string($conn, trim($_POST['search_keyword']));
    if (!empty($search_keyword)) {
        $where_clause = "WHERE customer_id LIKE '%$search_keyword%' OR customer LIKE '%$search_keyword%' OR city LIKE '%$search_keyword%'";
    }
}
?>

<style>
    .style-crystal {
        font-family: "Segoe UI", Tahoma, Arial, sans-serif !important;
        font-size: 11px !important;
    }
    .table-crystal-report {
        font-family: "Segoe UI", Tahoma, Arial, sans-serif !important;
        font-size: 11px !important;
        border-collapse: collapse !important;
        width: 100%;
    }
    .table-crystal-report th {
        background-color: #f0f4f8 !important;
        color: #2b4c7e !important;
        font-weight: bold !important;
        text-align: center !important;
        padding: 2px 4px !important;
        border: 1px solid #c0cddb !important;
        white-space: nowrap !important;
    }
    .table-crystal-report td {
        padding: 1px 4px !important;
        border: 1px solid #d3d3d3 !important;
        line-height: 1.1 !important;
        white-space: nowrap !important;
        vertical-align: middle !important;
        height: 18px !important;
    }
    .table-crystal-report tbody tr:hover {
        background-color: #e8f2fe !important;
    }
    .text-uppercase-crystal {
        text-transform: uppercase !important;
    }
    .btn-micro {
        padding: 0px 3px !important;
        font-size: 9px !important;
        line-height: 1.1 !important;
        border-radius: 1px !important;
    }
    .col-cut-address {
        max-width: 180px !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
    }
    .col-cut-remarks {
        max-width: 100px !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
    }
</style>

<div class="style-crystal d-print-none">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-0 pb-1 mb-1 border-bottom">
        <h5 class="fw-bold text-dark m-0"><i class="fa fa-users text-primary"></i> Master Data Customer</h5>
        <div class="btn-group gap-2">
            <!-- Tombol Export Excel - Lebih Besar -->
            <button class="btn btn-success fw-bold" style="padding: 6px 14px; font-size: 12px; border-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.1);" onclick="window.location.href='modul/master/export_customer.php'">
                <i class="fa fa-file-excel-o" style="font-size: 14px; margin-right: 6px;"></i> 
                Export to Excel
            </button>
            
            <!-- Tombol Print - Lebih Besar -->
             <!-- <button class="btn btn-secondary fw-bold" style="padding: 6px 14px; font-size: 12px; border-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.1);" onclick="window.print()">
                <i class="fa fa-print" style="font-size: 14px; margin-right: 6px;"></i> 
                Print Data
            </button> -->
            
            <!-- Tombol Tambah Data - Lebih Besar -->
            <button class="btn btn-primary fw-bold" style="padding: 6px 14px; font-size: 12px; border-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.1);" onclick="showModalTambah()">
                <i class="fa fa-plus-circle" style="font-size: 14px; margin-right: 6px;"></i> 
                Tambah Customer
            </button>
        </div>
    </div>
    <?= $alert_message; ?>
</div>

<div class="card shadow-sm mb-1 d-print-none style-crystal">
    <div class="card-body p-1 bg-light">
        <form method="POST" action="index.php?page=customer" class="row g-2 align-items-center m-0">
            <div class="col-auto col-md-3 p-1">
                <input type="text" name="search_keyword" class="form-control form-control-sm style-crystal" style="padding: 1px 4px; height: 22px;" placeholder="Cari ID, Nama, atau Kota..." value="<?= htmlspecialchars($search_keyword) ?>">
            </div>
            <div class="col-auto p-1">
                <button type="submit" name="btn_search" class="btn btn-xs btn-dark style-crystal" style="padding: 1px 6px; height: 22px;"><i class="fa fa-search"></i> Cari Data</button>
                <a href="index.php?page=customer" class="btn btn-xs btn-outline-secondary style-crystal" style="padding: 1px 6px; height: 22px;"><i class="fa fa-sync"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="table-responsive" style="max-height: 540px; overflow-y: auto; border: 1px solid #c0cddb;">
    <table class="table-crystal-report table-striped">
        <thead>
            <tr>
                <th class="d-print-none" style="position: sticky; left: 0; background: #f0f4f8; z-index: 3; width: 50px;">Aksi</th>
                <th style="position: sticky; left: 40px; background: #f0f4f8; z-index: 3; width: 10px;">ID</th>
                <th style="position: sticky; left: 90px; background: #f0f4f8; z-index: 3; width: 90px; text-align: left;">Customer</th>
                <th style="position: sticky; left: 200px; background: #f0f4f8; z-index: 3; width: 200px; text-align: left;">Address</th>
                <th >City</th>
                <th style="text-align: left;">Contact Person</th>
                <th>Contact Person Phone</th>
                <th>ID Number</th>
                <th>Phone</th>
                <th style="text-align: right;">Credit Limit</th>
                <th>Email</th>
                <th>Old Code</th>
                <th>Remarks</th>
                <th>Type</th>
                <th>Parent ID</th>
                <th>Parent Customer</th>
                <th>Bagian</th>
                <th>Is Active</th>
                <th>User Created</th>
                <th>Date Created</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $query_list = mysqli_query($conn, "SELECT * FROM m_customer $where_clause ORDER BY customer_id DESC");
            if (!$query_list) {
                echo "<td><td colspan='28' class='text-center text-danger py-1'>Error Query: ".mysqli_error($conn)."</td></tr>";
            } elseif (mysqli_num_rows($query_list) == 0) {
                echo "<tr><td colspan='28' class='text-center text-muted py-1'>Tidak ada data customer ditemukan.</td></tr>";
            } else {
                while ($d = mysqli_fetch_assoc($query_list)) {
                ?>
                <tr>
                    <td class="text-center d-print-none" style="position: sticky; left: 0; background: #fff; z-index: 2; box-shadow: 1px 0 2px rgba(0,0,0,0.08);">
                        <button class="btn btn-micro btn-warning text-dark" onclick='showModalEdit(<?= json_encode($d); ?>)'><i class="fa fa-edit"></i></button>
                        <a href="index.php?page=customer&action=delete&id=<?= urlencode($d['customer_id']) ?>" class="btn btn-micro btn-danger" onclick="return confirm('Hapus data <?= $d['customer'] ?>?')"><i class="fa fa-trash"></i></a>
                    </td>
                    <td class="fw-bold text-secondary text-center" style="position: sticky; left: 40px; background: #fff; z-index: 2; box-shadow: 1px 0 2px rgba(0,0,0,0.08); font-family:Consolas, monospace;"><?= htmlspecialchars($d['customer_id']) ?></td>
                    <td class="fw-bold text-primary text-uppercase-crystal" style="position: sticky; left: 90px; background: #fff; z-index: 2; box-shadow: 1px 0 2px rgba(0,0,0,0.08); max-width: 200px; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($d['customer']) ?></td>
                    <td class="text-uppercase-crystal col-cut-address" style="position: sticky; left: 200px; background: #fff; z-index: 2; box-shadow: 2px 0 3px rgba(0,0,0,0.1); color:#555;"><?= htmlspecialchars($d['address']) ?></td>
                    
                    <td class="text-uppercase-crystal"><?= htmlspecialchars($d['city']) ?></td>
                    <td class="text-uppercase-crystal"><?= htmlspecialchars($d['contact_person']) ?></td>
                    <td><?= htmlspecialchars($d['contact_person_phone']) ?></td>
                    
                    <td><?= htmlspecialchars($d['id_number']) ?></td>
                    <td><?= htmlspecialchars($d['phone']) ?></td>
                    <td class="text-end fw-bold text-success"><?= number_format($d['credit_limit'], 2) ?></td>
                    <td><?= htmlspecialchars($d['email']) ?></td>
                    <td><?= htmlspecialchars($d['old_code']) ?></td>
                    <td class="col-cut-remarks"><?= htmlspecialchars($d['remarks']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($d['type']) ?></td>
                    <td><?= htmlspecialchars($d['parent_id']) ?></td>
                    <td class="text-uppercase-crystal"><?= htmlspecialchars($d['parent_customer']) ?></td>
                    <td><?= htmlspecialchars($d['bagian']) ?></td>
                    <td class="text-center fw-bold text-secondary"><?= htmlspecialchars($d['is_active']) ?></td>
                    <td><?= htmlspecialchars($d['user_created']) ?></td>
                    <td class="text-muted" style="font-size:10px;"><?= $d['date_created'] ?></td>
                </tr>
                <?php } 
            } ?>
        </tbody>
    </table>
</div>

<div class="modal fade d-print-none style-crystal" id="modalCustomer" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width: 96%;">
        <div class="modal-content border-0 shadow-lg" style="background-color: #eef1f5;">
            <div class="modal-header bg-secondary text-white py-1 shadow-sm">
                <span class="modal-title fw-bold" id="modalTitle" style="font-size:11px;"><i class="fa fa-edit"></i> Form Master Customer</span>
                <button type="button" class="btn-close btn-close-white" style="font-size:9px; padding: 4px;" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formCustomer" method="POST" action="index.php?page=customer">
                <input type="hidden" name="action_form" id="action_form" value="insert">
                <div class="modal-body p-3">
                    
                    <div class="row g-3">
                        
                        <div class="col-md-4 border-end border-2 border-light shadow-xs bg-white p-3 rounded">
                            <div class="mb-2">
                                <label class="form-label fw-bold mb-0 text-secondary">ID / Kode Customer</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" name="id" id="form_id" class="form-control" placeholder="Auto" value="Auto">
                                    <div class="input-group-text bg-light">
                                        <input class="form-check-input mt-0" type="checkbox" name="chk_auto_id" id="form_chk_auto" value="1" checked onchange="toggleAutoId(this)">
                                        <label class="form-check-label small ms-1 fw-bold" for="form_chk_auto">Auto</label>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-bold mb-0 text-secondary">Customer Name *</label>
                                <input type="text" name="customer" id="form_customer" class="form-control form-control-sm" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-bold mb-0 text-secondary">ID Number (NIK / KTP)</label>
                                <input type="text" name="id_number" id="form_id_number" class="form-control form-control-sm">
                            </div>

                            <div class="mb-2">
                                <label class="form-label fw-bold mb-0 text-secondary">Address</label>
                                <textarea name="address" id="form_address" class="form-control form-control-sm" rows="3" placeholder="Alamat Pengiriman/Operasional..."></textarea>
                            </div>

                        </div>

                        <div class="col-md-4 border-end border-2 border-light bg-white p-3 rounded">
                            <div class="mb-2">
                                <label class="form-label fw-bold mb-0 text-secondary">City</label>
                                <input type="text" name="city" id="form_city" class="form-control form-control-sm" placeholder="Surabaya, Sidoarjo, dsb">
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-bold mb-0 text-secondary">Phone</label>
                                <input type="text" name="phone" id="form_phone" class="form-control form-control-sm">
                            </div>

                            <div class="mb-2">
                                <label class="form-label fw-bold mb-0 text-secondary">Credit Limit (Rp)</label>
                                <input type="number" step="0.01" name="credit_limit" id="form_credit_limit" class="form-control form-control-sm text-end fw-bold text-success" value="0.00">
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-bold mb-0 text-secondary">Email</label>
                                <input type="text" name="email" id="form_email" class="form-control form-control-sm">
                            </div>

                            <div class="mb-2">
                                <label class="form-label fw-bold mb-0 text-secondary">Contact Person</label>
                                <input type="text" name="contact_person" id="form_contact_person" class="form-control form-control-sm">
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-bold mb-0 text-secondary">Contact Person Phone</label>
                                <input type="text" name="contact_person_phone" id="form_contact_person_phone" class="form-control form-control-sm">
                            </div>
                          
                            <div class="mb-2">
                                <label class="form-label fw-bold mb-0 text-secondary">Bidang / Bagian</label>
                                <select name="bagian" id="form_bagian" class="form-select form-select-sm">
                                    <option value="">-- Pilih Bidang --</option>
                                    <option value="Agriculture">Agriculture</option>
                                    <option value="Mining">Mining</option>
                                    <option value="Food & Beverage">Food & Beverage</option>
                                    <option value="Textiles / Apparel">Textiles / Apparel</option>
                                    <option value="Chemicals">Chemicals</option>
                                    <option value="Pharmaceuticals">Pharmaceuticals</option>
                                    <option value="Metal & Machinery">Metal & Machinery</option>
                                    <option value="Electronics">Electronics</option>
                                    <option value="Construction">Construction</option>
                                    <option value="Trade (Wholesale/Retail)">Trade (Wholesale/Retail)</option>
                                    <option value="Transportation">Transportation</option>
                                    <option value="Utilities (Energy/Water)">Utilities (Energy/Water)</option>
                                    <option value="Banking/Finance">Banking/Finance</option>
                                    <option value="Insurance">Insurance</option>
                                    <option value="Services (Consulting,IT,etc.)">Services (Consulting,IT,etc.)</option>
                                    <option value="Healthcare">Healthcare</option>
                                    <option value="Public Sector/Government">Public Sector/Government</option>
                                    <option value="Education">Education</option>
                                    <option value="Others/Miscellaneous">Others/Miscellaneous</option>

                                </select>
                            </div>
                            <div class="mb-0">
                                <label class="form-label fw-bold mb-0 text-secondary">Lain-Lain</label>
                                <input type="text" name="area_code" id="form_area_code" class="form-control form-control-sm" placeholder="Catatan Tambahan Lokasi">
                            </div>
                        </div>

                        <div class="col-md-4 bg-white p-3 rounded">
                            <div class="mb-2">
                                <label class="form-label fw-bold mb-0 text-secondary">Old Code</label>
                                <input type="text" name="old_code" id="form_old_code" class="form-control form-control-sm">
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-bold mb-0 text-secondary">Remark</label>
                                <textarea name="remarks" id="form_remarks" class="form-control form-control-sm" rows="5" placeholder="Tulis catatan atau riwayat khusus di sini..."></textarea>
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-bold mb-0 text-secondary">Type</label>
                                <select name="type" id="form_type" class="form-select form-select-sm">
                                    <option value="Lokal">Lokal</option>
                                    <option value="Ekspor">Ekspor</option>
                                    <option value="Intercompany">Intercompany</option>
                                </select>
                            </div>

                            <div class="mb-2">
                                <label class="form-label fw-bold mb-0 text-secondary">Parent Name</label>
                                <input type="text" name="parent_customer" id="form_parent_customer" class="form-control form-control-sm" value="<?php echo "-" ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold mb-0 text-secondary">Parent Code</label>
                                <input type="text" name="parent_id" id="form_parent_id" class="form-control form-control-sm" value="<?php echo "-" ?>">
                            </div>
                            <div class="mb-0 pt-2 border-top">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="form_is_active" value="Checked" checked>
                                    <label class="form-check-label fw-bold text-dark" for="form_is_active">IsActive</label>
                                </div>
                            </div>
                        </div>

                    </div> </div>
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-sm btn-secondary fw-bold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary fw-bold px-3"><i class="fa fa-save"></i> Save Customer</button>
                </div>
            </form>

        </div>
    </div>
</div>

<script>
var bootstrapModal;

document.addEventListener("DOMContentLoaded", function() {
    bootstrapModal = new bootstrap.Modal(document.getElementById('modalCustomer'));
});

function toggleAutoId(checkboxElement) {
    var idInput = document.getElementById('form_id');
    if (checkboxElement.checked) {
        idInput.value = "Auto";
        idInput.readOnly = true;
    } else {
        idInput.value = "";
        idInput.readOnly = false;
        idInput.focus();
    }
}

function showModalTambah() {
    document.getElementById('formCustomer').reset();
    document.getElementById('action_form').value = 'insert';
    document.getElementById('modalTitle').innerHTML = '<i class="fa fa-plus-circle text-primary"></i> Create New Master Customer';
    
    var chkAuto = document.getElementById('form_chk_auto');
    chkAuto.checked = true;
    chkAuto.disabled = false;
    
    var idInput = document.getElementById('form_id');
    idInput.value = "Auto";
    idInput.readOnly = true;
    
    document.getElementById('form_is_active').checked = true;
    bootstrapModal.show();
}

function showModalEdit(dataObj) {
    document.getElementById('formCustomer').reset();
    document.getElementById('action_form').value = 'update';
    document.getElementById('modalTitle').innerHTML = '<i class="fa fa-edit text-warning"></i> Modify Customer Asset: ' + dataObj.customer;
    
    var chkAuto = document.getElementById('form_chk_auto');
    chkAuto.checked = false;
    chkAuto.disabled = true;
    
    var idInput = document.getElementById('form_id');
    idInput.value = dataObj.customer_id;
    idInput.readOnly = true;

    document.getElementById('form_customer').value = dataObj.customer;
    document.getElementById('form_city').value = dataObj.city;
    document.getElementById('form_address').value = dataObj.address;
    document.getElementById('form_contact_person').value = dataObj.contact_person;
    document.getElementById('form_contact_person_phone').value = dataObj.contact_person_phone;
    document.getElementById('form_id_number').value = dataObj.id_number;
    document.getElementById('form_phone').value = dataObj.phone;
    document.getElementById('form_credit_limit').value = dataObj.credit_limit;
    document.getElementById('form_email').value = dataObj.email;
    document.getElementById('form_old_code').value = dataObj.old_code;
    document.getElementById('form_area_code').value = dataObj.area_code;
    document.getElementById('form_type').value = dataObj.type;
    document.getElementById('form_parent_id').value = dataObj.parent_id;
    document.getElementById('form_parent_customer').value = dataObj.parent_customer;
    document.getElementById('form_bagian').value = dataObj.bagian;
    document.getElementById('form_remarks').value = dataObj.remarks;
    
    document.getElementById('form_is_active').checked = (dataObj.is_active === 'Checked');

    bootstrapModal.show();
}
</script>