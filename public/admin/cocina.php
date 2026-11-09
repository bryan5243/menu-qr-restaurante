<?php

declare(strict_types=1);

// Pantalla de cocina y meseros: los pedidos llegan solos, con la mesa, y se avanzan con un toque.
require __DIR__ . '/../../src/admin.php';

$pdo = require_admin('cocina');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
    if (!csrf_valid($input['csrf'] ?? null)) {
        json_out(['ok' => false, 'error' => 'La sesión cambió. Recarga la página.'], 400);
    }
    $order = order_by_id($pdo, (int) ($input['id'] ?? 0));
    if (!$order || !in_array($order['status'], OPEN_STATUSES, true)) {
        json_out(['ok' => false, 'error' => 'Ese pedido ya no está abierto.']);
    }
    $id = (int) $order['id'];
    $set = static function (array $fields) use ($pdo, $id): void {
        $fields['updated_at'] = now();
        $sql = implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($fields)));
        $pdo->prepare("UPDATE orders SET $sql WHERE id = ?")->execute([...array_values($fields), $id]);
    };
    switch ((string) ($input['action'] ?? '')) {
        case 'eta': // Empezar a preparar con tiempo estimado
            $minutes = max(1, min(180, (int) ($input['minutes'] ?? setting('default_eta'))));
            $set(['status' => 'preparando', 'eta_minutes' => $minutes, 'eta_at' => date('Y-m-d H:i:s', time() + $minutes * 60)]);
            break;
        case 'more': // +5 minutos
            $base = max(time(), strtotime((string) ($order['eta_at'] ?? 'now')));
            $set(['eta_minutes' => (int) $order['eta_minutes'] + 5, 'eta_at' => date('Y-m-d H:i:s', $base + 300)]);
            break;
        case 'listo':
            $set(['status' => 'listo']);
            break;
        case 'entregado':
            // Para llevar y domicilio terminan al entregarse; en mesa la cuenta sigue abierta.
            $order['kind'] === 'mesa' ? $set(['status' => 'entregado']) : $set(['status' => 'cerrado', 'closed_at' => now()]);
            break;
        case 'cerrar':
            $set(['status' => 'cerrado', 'call_flag' => '', 'closed_at' => now()]);
            break;
        case 'cancelar':
            $set(['status' => 'cancelado', 'closed_at' => now()]);
            break;
        case 'atendido':
            $set(['call_flag' => '']);
            break;
        default:
            json_out(['ok' => false, 'error' => 'Acción no válida.'], 400);
    }
    json_out(['ok' => true]);
}

if (isset($_GET['json'])) {
    $pending = (int) $pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'pendiente'")->fetchColumn();
    json_out(['ok' => true, 'now' => time() * 1000, 'orders' => kitchen_orders($pdo), 'reservations' => $pending]);
}

admin_header('Cocina', 'cocina', 'page-kitchen');
?>
<div class="k-head">
  <div>
    <h1>Pedidos en vivo</h1>
    <p class="hint" data-k-sync>Se actualiza solo cada 4 segundos.</p>
  </div>
  <div class="a-row">
    <a class="a-btn a-btn-sm" href="reservas.php" data-k-res hidden><?= icon('calendario') ?> <span></span></a>
    <button class="a-btn a-btn-sm" type="button" data-k-sound><?= icon('sonido') ?> <span>Activar sonido</span></button>
    <button class="a-btn a-btn-sm" type="button" data-k-full>Pantalla completa</button>
  </div>
</div>
<div class="k-calls" data-k-calls></div>
<div class="k-board" data-k-board>
  <section class="k-col" data-col="recibido"><h2>Nuevos <span>0</span></h2><div class="k-list"></div></section>
  <section class="k-col" data-col="preparando"><h2>Preparando <span>0</span></h2><div class="k-list"></div></section>
  <section class="k-col" data-col="listo"><h2>Listos para entregar <span>0</span></h2><div class="k-list"></div></section>
  <section class="k-col" data-col="entregado"><h2>Mesas atendidas <span>0</span></h2><div class="k-list"></div></section>
</div>
<script>window.KITCHEN = <?= json_encode(['csrf' => csrf_token(), 'eta' => (int) setting('default_eta') ?: 20], JSON_HEX_TAG) ?>;</script>
<?php admin_footer(['../assets/cocina.js']); ?>
