<?php
require_once __DIR__ . '/return_bootstrap.php';

$returnId = trim((string)($_GET['return_id'] ?? ''));
if ($returnId === '') {
    http_response_code(422);
    echo '<div class="alert alert-danger mb-0">Return ID tidak valid.</div>';
    exit;
}

$sql = "
    SELECT
        inventory_id,
        inventory_name,
        return_quantity,
        uom,
        return_quantity_pack,
        uom_pack,
        uom_detail,
        price_unit,
        return_subtotal,
        remarks_detail
    FROM detail_retur_invoice
    WHERE return_id = ?
    ORDER BY id
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $returnId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<div class="table-responsive">
    <table class="table table-sm table-bordered mb-0">
        <thead class="table-secondary">
        <tr>
            <th>No.</th>
            <th>Inventory ID</th>
            <th>Inventory Name</th>
            <th class="text-end">Qty Return</th>
            <th>UoM</th>
            <th class="text-end">Qty Pack Return</th>
            <th>UoM Pack</th>
            <th>UoM Detail</th>
            <th class="text-end">Price</th>
            <th class="text-end">Return Subtotal</th>
            <th>Remarks</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $index => $row): ?>
            <tr>
                <td><?= $index + 1 ?></td>
                <td><?= h($row['inventory_id']) ?></td>
                <td><?= h($row['inventory_name']) ?></td>
                <td class="text-end"><?= number_format((float)$row['return_quantity'], 4, '.', ',') ?></td>
                <td><?= h($row['uom']) ?></td>
                <td class="text-end"><?= number_format((float)$row['return_quantity_pack'], 4, '.', ',') ?></td>
                <td><?= h($row['uom_pack']) ?></td>
                <td><?= h($row['uom_detail']) ?></td>
                <td class="text-end"><?= money((float)$row['price_unit']) ?></td>
                <td class="text-end"><?= money((float)$row['return_subtotal']) ?></td>
                <td><?= h($row['remarks_detail']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
