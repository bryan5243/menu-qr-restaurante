<?php

declare(strict_types=1);

// Página que ve el cliente después de pedir: estado en vivo, tiempo estimado y botones para la mesa.
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/orders.php';
require __DIR__ . '/../src/layout.php';

start_session();
$pdo = db();
$order = $pdo ? order_by_code($pdo, (string) ($_GET['c'] ?? '')) : null;

// Llamar al mesero o pedir la cuenta (solo pedidos en mesa y abiertos).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $order = $pdo ? order_by_code($pdo, (string) ($input['c'] ?? '')) : null;
    $call = (string) ($input['call'] ?? '');
    if (!csrf_valid($input['csrf'] ?? null) || !$order || $order['kind'] !== 'mesa' || !in_array($order['status'], OPEN_STATUSES, true) || !in_array($call, ['mesero', 'cuenta'], true)) {
        json_out(['ok' => false], 400);
    }
    $pdo->prepare('UPDATE orders SET call_flag = ?, updated_at = ? WHERE id = ?')->execute([$call, now(), (int) $order['id']]);
    json_out(['ok' => true]);
}

if ($order === null) {
    http_response_code(404);
    if (isset($_GET['json'])) {
        json_out(['ok' => false], 404);
    }
    page_header('Pedido no encontrado', 'page-status');
    echo '<main class="wrap status-empty"><h1>No encontramos ese pedido</h1><p>Revisa el enlace o vuelve al menú.</p><a class="btn btn-primary" href="./">Ir al menú</a></main>';
    page_footer([]);
    exit;
}

$items = items_for($pdo, [(int) $order['id']])[(int) $order['id']] ?? [];
$data = order_public($order, $items);
if (isset($_GET['json'])) {
    json_out(['ok' => true, 'order' => $data]);
}

$menuLink = './' . ($order['kind'] === 'mesa' ? '?mesa=' . (int) $order['table_no'] : '');
$whatsapp = setting_on('whatsapp_orders') ? whatsapp_number() : '';
$waText = '';
if ($whatsapp !== '') {
    $lines = ['Hola, hice el pedido #' . (int) $order['id'] . ' (' . order_label($order) . ') en el menú digital:'];
    foreach ($items as $item) {
        $lines[] = '• ' . $item['qty'] . ' x ' . $item['name'] . ($item['opts'] !== '' ? ' [' . $item['opts'] . ']' : '') . ($item['note'] !== '' ? ' (' . $item['note'] . ')' : '');
    }
    $lines[] = 'Total: ' . money((float) $order['total']);
    $lines[] = absolute_status_url($order['code']);
    $waText = implode("\n", $lines);
}

function absolute_status_url(string $code): string
{
    return public_base_url() . 'estado.php?c=' . $code;
}

page_header('Pedido #' . (int) $order['id'], 'page-status');
?>
<header class="top top-solid">
  <div class="wrap top-in">
    <a class="logo" href="<?= e($menuLink) ?>"><?= site_logo() ?></a>
    <a class="btn btn-ghost btn-sm" href="<?= e($menuLink) ?>"><?= icon('atras') ?><span>Menú</span></a>
  </div>
</header>

<main class="wrap status" data-status data-code="<?= e($order['code']) ?>">
  <section class="status-card" data-status-card>
    <p class="status-kicker"><?= e(order_label($order)) ?> · Pedido #<?= (int) $order['id'] ?></p>
    <div class="status-visual" aria-hidden="true">
      <svg class="ring" viewBox="0 0 120 120"><circle cx="60" cy="60" r="52" class="ring-bg"/><circle cx="60" cy="60" r="52" class="ring-fg" data-ring/></svg>
      <span class="status-icon" data-status-icon><?= icon('cocina') ?></span>
    </div>
    <h1 data-status-text><?= e($data['statusText']) ?></h1>
    <p class="status-eta" data-status-eta></p>
    <ol class="steps" data-steps>
      <li data-step="recibido"><i></i>Recibido</li>
      <li data-step="preparando"><i></i>Preparando</li>
      <li data-step="listo"><i></i>Listo</li>
      <li data-step="entregado"><i></i><?= $order['kind'] === 'domicilio' ? 'Entregado' : ($order['kind'] === 'llevar' ? 'Retirado' : 'Servido') ?></li>
    </ol>
  </section>

  <?php if ($order['kind'] === 'mesa'): ?>
    <section class="status-actions" data-open-only>
      <a class="btn btn-primary" href="<?= e($menuLink) ?>"><?= icon('mas') ?> Pedir algo más</a>
      <button class="btn btn-light" type="button" data-call="mesero"><?= icon('campana') ?> Llamar al mesero</button>
      <button class="btn btn-light" type="button" data-call="cuenta"><?= icon('cuenta') ?> Pedir la cuenta</button>
      <p class="call-note" data-call-note hidden></p>
    </section>
  <?php endif; ?>

  <section class="status-detail">
    <h2>Detalle</h2>
    <ul class="status-items" data-status-items></ul>
    <?php if ((float) $order['delivery_fee'] > 0): ?><p class="cart-line"><span>Envío</span><span><?= e(money((float) $order['delivery_fee'])) ?></span></p><?php endif; ?>
    <p class="cart-line cart-total"><span>Total</span><b data-status-total><?= e(money((float) $order['total'])) ?></b></p>
    <?php if ($order['kind'] !== 'mesa'): ?>
      <p class="hint">Pago: <b><?= e(PAYMENTS[$order['payment']] ?? '') ?></b><?= $order['kind'] === 'domicilio' ? ' · Entrega en: ' . e($order['address']) : '' ?></p>
      <?php if ($order['payment'] === 'transferencia'): ?>
        <div class="bank">
          <p class="bank-title"><?= icon('banco') ?> Transfiere <?= e(money((float) $order['total'])) ?> a:</p>
          <dl>
            <dt>Banco</dt><dd><?= e(setting('bank_name')) ?></dd>
            <dt>Tipo</dt><dd><?= e(setting('bank_type')) ?></dd>
            <dt>Número</dt><dd><b data-copy-src><?= e(setting('bank_number')) ?></b> <button type="button" class="copy" data-copy><?= icon('copiar') ?> Copiar</button></dd>
            <dt>Titular</dt><dd><?= e(setting('bank_holder')) ?></dd>
            <?php if (setting('bank_id') !== ''): ?><dt>RUC / C.I.</dt><dd><?= e(setting('bank_id')) ?></dd><?php endif; ?>
          </dl>
          <p class="hint">Muestra el comprobante al recibir tu pedido.</p>
        </div>
      <?php elseif ($order['payment'] === 'otro'): ?>
        <div class="bank"><p><?= nl2br(e(setting('pay_other'))) ?></p></div>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($whatsapp !== ''): ?>
      <a class="btn btn-wa btn-block" href="<?= e(whatsapp_link($waText)) ?>" target="_blank" rel="noopener"><?= icon('whatsapp') ?> Enviar copia por WhatsApp</a>
    <?php endif; ?>
    <p class="hint center">Guarda esta página: se actualiza sola.</p>
  </section>
</main>
<div class="toast" data-toast role="status" aria-live="polite"></div>
<script>window.STATUS = <?= json_encode(['csrf' => csrf_token(), 'order' => $data, 'store' => 'menu:' . substr(md5(setting('name')), 0, 6)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<?php page_footer(['assets/status.js']); ?>
