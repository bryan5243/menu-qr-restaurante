<?php

declare(strict_types=1);

// Historial de pedidos por día, con totales por tipo y por forma de pago.
require __DIR__ . '/../../src/admin.php';

$pdo = require_admin('pedidos');
$day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['dia'] ?? '')) ? $_GET['dia'] : date('Y-m-d');
$kind = isset(ORDER_KINDS[$_GET['tipo'] ?? '']) ? $_GET['tipo'] : '';

$where = 'created_at >= ? AND created_at < ?';
$params = [$day . ' 00:00:00', date('Y-m-d', strtotime($day . ' +1 day')) . ' 00:00:00'];
if ($kind !== '') {
    $where .= ' AND kind = ?';
    $params[] = $kind;
}
$statement = $pdo->prepare("SELECT * FROM orders WHERE $where ORDER BY id DESC");
$statement->execute($params);
$orders = $statement->fetchAll();
$items = items_for($pdo, array_column($orders, 'id'));

$total = 0.0;
$byPayment = ['mesa' => 0.0, 'efectivo' => 0.0, 'transferencia' => 0.0, 'otro' => 0.0];
$cancelled = 0;
foreach ($orders as $o) {
    if ($o['status'] === 'cancelado') {
        $cancelled++;
        continue;
    }
    $total += (float) $o['total'];
    $byPayment[$o['kind'] === 'mesa' ? 'mesa' : ($o['payment'] ?: 'efectivo')] += (float) $o['total'];
}
$tagClass = ['recibido' => 'tag-new', 'preparando' => 'tag-warn', 'listo' => 'tag-ok', 'entregado' => 'tag-ok', 'cerrado' => 'tag-muted', 'cancelado' => 'tag-off'];

admin_header('Pedidos', 'pedidos');
?>
<div class="a-head">
  <h1>Pedidos</h1>
  <form class="a-row" method="get">
    <input type="date" name="dia" value="<?= e($day) ?>" aria-label="Día" max="<?= date('Y-m-d') ?>">
    <select name="tipo" aria-label="Tipo"><option value="">Todos</option><?php foreach (ORDER_KINDS as $k => $label): ?><option value="<?= $k ?>"<?= $k === $kind ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
    <button class="a-btn" type="submit">Ver</button>
  </form>
</div>

<div class="a-cards">
  <div class="a-card"><span><?= icon('dinero') ?></span><b><?= e(money($total)) ?></b>Total del día</div>
  <div class="a-card"><span><?= icon('mesa') ?></span><b><?= e(money($byPayment['mesa'])) ?></b>En mesa (cobro en caja)</div>
  <div class="a-card"><span><?= icon('dinero') ?></span><b><?= e(money($byPayment['efectivo'] + $byPayment['otro'])) ?></b>Llevar y domicilio en efectivo</div>
  <div class="a-card"><span><?= icon('banco') ?></span><b><?= e(money($byPayment['transferencia'])) ?></b>Por transferencia</div>
</div>
<p class="hint"><?= count($orders) ?> pedidos<?= $cancelled ? " · $cancelled cancelados (no suman)" : '' ?>. Los pedidos abiertos se manejan en <a href="cocina.php">Cocina</a>.</p>

<?php if (!$orders): ?><div class="panel"><p class="hint">No hay pedidos este día.</p></div><?php endif; ?>
<div class="a-orders">
  <?php foreach ($orders as $o): $list = $items[(int) $o['id']] ?? []; ?>
    <details class="panel a-order">
      <summary>
        <span class="a-order-title"><b>#<?= (int) $o['id'] ?> · <?= e(order_label($o)) ?></b><small><?= e(date('H:i', strtotime((string) $o['created_at']))) ?> · <?= count($list) ?> líneas<?= (int) $o['rounds'] > 1 ? ' · ' . (int) $o['rounds'] . ' rondas' : '' ?><?= $o['kind'] !== 'mesa' ? ' · ' . e(PAYMENTS[$o['payment']] ?? '') : '' ?></small></span>
        <span class="tag <?= $tagClass[$o['status']] ?? '' ?>"><?= e(ORDER_STATUSES[$o['status']][1] ?? $o['status']) ?></span>
        <b class="a-order-total"><?= e(money((float) $o['total'])) ?></b>
      </summary>
      <table class="a-table"><tbody>
        <?php foreach ($list as $it): ?>
          <tr><td><?= (int) $o['rounds'] > 1 ? '<small>R' . $it['round'] . '</small> ' : '' ?><?= e($it['name']) ?><?= $it['note'] !== '' ? '<br><small>' . e($it['note']) . '</small>' : '' ?></td><td>×<?= $it['qty'] ?></td><td><?= e(money($it['qty'] * $it['price'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if ((float) $o['delivery_fee'] > 0): ?><tr><td>Envío</td><td></td><td><?= e(money((float) $o['delivery_fee'])) ?></td></tr><?php endif; ?>
      </tbody></table>
      <?php if ($o['kind'] !== 'mesa'): ?><p class="hint"><b><?= e($o['customer_name']) ?></b> · <a href="tel:<?= e($o['phone']) ?>"><?= e($o['phone']) ?></a><?= $o['address'] !== '' ? ' · ' . e($o['address']) : '' ?></p><?php endif; ?>
      <?php if ($o['notes'] !== ''): ?><p class="hint">Comentario: <?= e($o['notes']) ?></p><?php endif; ?>
      <p class="hint"><a href="../estado.php?c=<?= e($o['code']) ?>" target="_blank" rel="noopener">Ver la página que ve el cliente</a></p>
    </details>
  <?php endforeach; ?>
</div>
<?php admin_footer(); ?>
