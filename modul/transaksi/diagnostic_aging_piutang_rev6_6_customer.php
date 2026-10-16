<?php
/* DIAGNOSTIC AGING PIUTANG REV6 - 6 CUSTOMER | READ ONLY */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}
$paths=[
__DIR__ . '/../../koneksi.php'
];
foreach($paths as $p){if(file_exists($p)){require_once $p;break;}}
if(!isset($conn)&&isset($koneksi))$conn=$koneksi;
if(!isset($conn)||!($conn instanceof mysqli)) die("Koneksi DB belum ditemukan. Sesuaikan path koneksi di bagian atas file.");
mysqli_set_charset($conn,'utf8mb4');

$start='2026-09-01'; $end='2026-09-30'; $base='2026-08-31';
$ids="'C0000173','C0000034','C0000063','C0000195','C0000203','C0000127'";

function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function showQ($conn,$title,$note,$sql){
 echo "<section><h2>".e($title)."</h2><p>".e($note)."</p>";
 $r=mysqli_query($conn,$sql);
 if(!$r){echo "<div class='err'><b>QUERY ERROR:</b> ".e(mysqli_error($conn))."</div><details><summary>SQL</summary><pre>".e($sql)."</pre></details></section>";return;}
 if(!mysqli_num_rows($r)){echo "<div class='empty'>Tidak ada data.</div></section>";return;}
 $f=mysqli_fetch_fields($r); echo "<div class='wrap'><table><thead><tr>";
 foreach($f as $x)echo "<th>".e($x->name)."</th>";
 echo "</tr></thead><tbody>";
 while($row=mysqli_fetch_assoc($r)){echo "<tr>";foreach($f as $x){$v=$row[$x->name];echo "<td>".($v===null?"<i>NULL</i>":e($v))."</td>";}echo "</tr>";}
 echo "</tbody></table></div><details><summary>Lihat SQL</summary><pre>".e($sql)."</pre></details></section>";
 mysqli_free_result($r);
}

$q=[];

$q[]=["1. Master Customer","Verifikasi enam customer.",
"SELECT customer_id,customer,city,is_active FROM m_customer WHERE customer_id IN ($ids) ORDER BY customer_id"];

$q[]=["2. Baseline 31 Agustus 2026","Source bucket sebelum rollover September.",
"SELECT amb.baseline_id,amb.customer_id,mc.customer,amb.baseline_date,
amb.b_1_30,amb.b_31_60,amb.b_61_90,amb.b_lebih,amb.belum_jatuh_tempo,
(COALESCE(amb.b_1_30,0)+COALESCE(amb.b_31_60,0)+COALESCE(amb.b_61_90,0)+COALESCE(amb.b_lebih,0)+COALESCE(amb.belum_jatuh_tempo,0)) total_baseline,
amb.remarks
FROM aging_bucket_migration_baseline amb
LEFT JOIN m_customer mc ON mc.customer_id=amb.customer_id
WHERE amb.baseline_date='$base' AND amb.customer_id IN ($ids)
ORDER BY amb.customer_id"];

$q[]=["3. Opening Balance","Opening resmi customer.",
"SELECT cob.opening_id,cob.customer_id,mc.customer,cob.opening_date,cob.opening_balance,cob.status,cob.remarks
FROM customer_opening_balance cob
LEFT JOIN m_customer mc ON mc.customer_id=cob.customer_id
WHERE cob.customer_id IN ($ids)
ORDER BY cob.customer_id,cob.opening_date,cob.opening_id"];

$q[]=["4. Invoice Agustus - September","Due date dihitung dari invoice_date + days.",
"SELECT hi.customer_id,mc.customer,hi.invoice_no,hi.invoice_date,hi.days,
DATE_ADD(hi.invoice_date,INTERVAL COALESCE(hi.days,30) DAY) due_date_hitung,
hi.subtotal head_subtotal,hi.grand_total head_grand_total,hi.payment_balance,hi.piutang,hi.status,
COALESCE((SELECT SUM(COALESCE(di.subtotal,0)) FROM det_invoice di WHERE di.invoice_no=hi.invoice_no),0) detail_subtotal,
COALESCE((SELECT SUM(COALESCE(di.total,0)) FROM det_invoice di WHERE di.invoice_no=hi.invoice_no),0) detail_total,
CASE
 WHEN DATE_ADD(hi.invoice_date,INTERVAL COALESCE(hi.days,30) DAY)>'$end' THEN 'BJT'
 WHEN DATE_ADD(hi.invoice_date,INTERVAL COALESCE(hi.days,30) DAY) BETWEEN '$start' AND '$end' THEN 'JT SEPTEMBER'
 ELSE 'OVERDUE SEBELUM SEPTEMBER' END posisi_due_date
FROM head_invoice hi LEFT JOIN m_customer mc ON mc.customer_id=hi.customer_id
WHERE hi.customer_id IN ($ids) AND hi.invoice_date BETWEEN '2026-08-01' AND '$end'
ORDER BY hi.customer_id,hi.invoice_date,hi.invoice_no"];

$q[]=["5. Payment Agustus - September","Source, invoice, opening, return, cash, titip dan bayar.",
"SELECT hb.customer_id,mc.customer,hb.bayar_no,hb.bayar_date,hb.total_bayar,
db.id detail_bayar_id,db.payment_source,db.opening_id,db.return_id,db.invoice_no,db.invoice_date,
db.invoice_amount,db.cash_amount,db.titip_amount,db.bayar_amount,db.sisa_after,db.shipping_no,db.remarks
FROM head_bayar hb INNER JOIN detail_bayar db ON db.bayar_no=hb.bayar_no
LEFT JOIN m_customer mc ON mc.customer_id=hb.customer_id
WHERE hb.customer_id IN ($ids) AND hb.bayar_date BETWEEN '2026-08-01' AND '$end'
ORDER BY hb.customer_id,hb.bayar_date,hb.bayar_no,db.id"];

$q[]=["6. Payment September","Movement payment setelah baseline.",
"SELECT hb.customer_id,mc.customer,hb.bayar_date,hb.bayar_no,db.payment_source,db.invoice_no,
db.opening_id,db.return_id,db.invoice_amount,db.cash_amount,db.titip_amount,db.bayar_amount,db.sisa_after
FROM head_bayar hb INNER JOIN detail_bayar db ON db.bayar_no=hb.bayar_no
LEFT JOIN m_customer mc ON mc.customer_id=hb.customer_id
WHERE hb.customer_id IN ($ids) AND hb.bayar_date BETWEEN '$start' AND '$end'
ORDER BY hb.customer_id,hb.bayar_date,hb.bayar_no,db.id"];

$q[]=["7. Summary Payment Source September","Ringkasan per payment_source.",
"SELECT hb.customer_id,mc.customer,
UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE')) payment_source,
COUNT(*) jumlah_detail,SUM(COALESCE(db.invoice_amount,0)) total_invoice_amount,
SUM(COALESCE(db.cash_amount,0)) total_cash,SUM(COALESCE(db.titip_amount,0)) total_titip,
SUM(COALESCE(db.bayar_amount,0)) total_bayar
FROM head_bayar hb INNER JOIN detail_bayar db ON db.bayar_no=hb.bayar_no
LEFT JOIN m_customer mc ON mc.customer_id=hb.customer_id
WHERE hb.customer_id IN ($ids) AND hb.bayar_date BETWEEN '$start' AND '$end'
GROUP BY hb.customer_id,mc.customer,UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'))
ORDER BY hb.customer_id,payment_source"];

$q[]=["8. Detail Titip","Semua movement titip sampai 30 September.",
"SELECT dt.customer_id,mc.customer,dt.id,dt.titip_no,dt.titip_date,dt.transaction_type,dt.ref_no,
dt.amount_in,dt.amount_out,dt.balance_after,dt.keterangan,dt.remarks
FROM detail_titip dt LEFT JOIN m_customer mc ON mc.customer_id=dt.customer_id
WHERE dt.customer_id IN ($ids) AND dt.titip_date<='$end'
ORDER BY dt.customer_id,dt.titip_date,dt.id"];

$q[]=["9. Head Titip","Total, used dan balance titip.",
"SELECT ht.customer_id,mc.customer,ht.titip_no,ht.titip_date,ht.total_titip,ht.used_amount,ht.balance_amount,
ht.status,ht.keterangan,ht.remarks
FROM head_titip ht LEFT JOIN m_customer mc ON mc.customer_id=ht.customer_id
WHERE ht.customer_id IN ($ids) AND ht.titip_date<='$end'
ORDER BY ht.customer_id,ht.titip_date,ht.titip_no"];

$q[]=["10. Titip PAKAI","Relasi detail_titip PAKAI ke bayar_no.",
"SELECT dt.customer_id,mc.customer,dt.titip_no,dt.titip_date,dt.transaction_type,dt.ref_no bayar_no,
hb.bayar_date,dt.amount_in,dt.amount_out,dt.balance_after
FROM detail_titip dt LEFT JOIN head_bayar hb ON hb.bayar_no=dt.ref_no
LEFT JOIN m_customer mc ON mc.customer_id=dt.customer_id
WHERE dt.customer_id IN ($ids) AND UPPER(COALESCE(dt.transaction_type,''))='PAKAI'
ORDER BY dt.customer_id,dt.titip_date,dt.id"];

$q[]=["11. Invoice September + Expected Bucket","Cek terutama BAMBANG dan ADITYA TANI.",
"SELECT hi.customer_id,mc.customer,hi.invoice_no,hi.invoice_date,hi.days,
DATE_ADD(hi.invoice_date,INTERVAL COALESCE(hi.days,30) DAY) due_date_hitung,
hi.subtotal,hi.grand_total,hi.payment_balance,hi.piutang,hi.status,
DATEDIFF('$end',DATE_ADD(hi.invoice_date,INTERVAL COALESCE(hi.days,30) DAY)) overdue_days_per_30_sep,
CASE
 WHEN DATE_ADD(hi.invoice_date,INTERVAL COALESCE(hi.days,30) DAY)>'$end' THEN 'BJT'
 WHEN DATEDIFF('$end',DATE_ADD(hi.invoice_date,INTERVAL COALESCE(hi.days,30) DAY)) BETWEEN 0 AND 30 THEN '1-30'
 WHEN DATEDIFF('$end',DATE_ADD(hi.invoice_date,INTERVAL COALESCE(hi.days,30) DAY)) BETWEEN 31 AND 60 THEN '31-60'
 WHEN DATEDIFF('$end',DATE_ADD(hi.invoice_date,INTERVAL COALESCE(hi.days,30) DAY)) BETWEEN 61 AND 90 THEN '61-90'
 ELSE 'LEBIH' END expected_bucket_by_due_date
FROM head_invoice hi LEFT JOIN m_customer mc ON mc.customer_id=hi.customer_id
WHERE hi.customer_id IN ($ids) AND hi.invoice_date BETWEEN '$start' AND '$end'
ORDER BY hi.customer_id,hi.invoice_date,hi.invoice_no"];

$q[]=["12. Simulasi Baseline Rollover","Posisi bucket September sebelum transaksi September.",
"SELECT amb.customer_id,mc.customer,
amb.b_1_30 aug_1_30,amb.b_31_60 aug_31_60,amb.b_61_90 aug_61_90,amb.b_lebih aug_lebih,amb.belum_jatuh_tempo aug_bjt,
amb.belum_jatuh_tempo sep_1_30_before_tx,amb.b_1_30 sep_31_60_before_tx,amb.b_31_60 sep_61_90_before_tx,
(COALESCE(amb.b_lebih,0)+COALESCE(amb.b_61_90,0)) sep_lebih_before_tx,0 sep_bjt_before_tx,
(COALESCE(amb.b_1_30,0)+COALESCE(amb.b_31_60,0)+COALESCE(amb.b_61_90,0)+COALESCE(amb.b_lebih,0)+COALESCE(amb.belum_jatuh_tempo,0)) total_baseline
FROM aging_bucket_migration_baseline amb
LEFT JOIN m_customer mc ON mc.customer_id=amb.customer_id
WHERE amb.baseline_date='$base' AND amb.customer_id IN ($ids)
ORDER BY amb.customer_id"];
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Diagnostic Aging Rev6</title>
<style>
body{font:13px Arial;margin:20px;background:#f4f5f7;color:#222}main{max-width:1800px;margin:auto}
header,section{background:#fff;border:1px solid #ddd;border-radius:7px;padding:16px;margin-bottom:16px}
h1{margin:0 0 8px}h2{font-size:17px;margin:0 0 5px}p{color:#555}.safe{padding:8px;background:#eef8ee;border:1px solid #b8d8b8}
.wrap{overflow:auto;border:1px solid #ddd}table{border-collapse:collapse;white-space:nowrap;width:100%}
th,td{padding:7px;border-right:1px solid #ddd;border-bottom:1px solid #ddd;text-align:left}th{background:#eee}
.err{background:#fff0f0;color:#a00;padding:10px}.empty{background:#fafafa;padding:10px;color:#777}
details{margin-top:10px}summary{cursor:pointer;font-weight:bold}pre{background:#222;color:#eee;padding:10px;overflow:auto}
</style></head><body><main>
<header><h1>Diagnostic Aging Piutang Rev6</h1>
<p>Periode <b><?=e($start)?></b> s/d <b><?=e($end)?></b> | Baseline <b><?=e($base)?></b></p>
<div class="safe"><b>READ ONLY</b> — tidak ada INSERT, UPDATE, atau DELETE.</div>
<p><b>6 customer:</b> ABU SHOLEH ROMADHONI, ANEKA RASA 1, HERMAN, BAMBANG, HENDRA MULYONO ONGKOWIDJOJO, ADITYA TANI.</p>
</header>
<?php foreach($q as $x) showQ($conn,$x[0],$x[1],$x[2]); ?>
</main></body></html>
