<?php

declare(strict_types=1);

// Recibe el pedido del carrito (o una ronda más para un pedido abierto) y lo deja en la pantalla de cocina.
// Los precios se toman de la base de datos, no del navegador.
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/orders.php';

start_session();

$input = json_decode((string) file_get_contents('php://input'), true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_array($input) || !csrf_valid($input['csrf'] ?? null)) {
    json_out(['ok' => false, 'error' => 'La página caducó. Recárgala e inténtalo de nuevo.'], 400);
}
$pdo = db();
if ($pdo === null) {
    json_out(['ok' => false, 'error' => 'No pudimos registrar el pedido. Pídelo al mesero, por favor.'], 503);
}
if (!accepting_orders()) {
    json_out(['ok' => false, 'error' => 'En este momento no estamos recibiendo pedidos en línea.']);
}
// Evita envíos repetidos por doble toque.
if (time() - (int) ($_SESSION['last_order'] ?? 0) < 8) {
    json_out(['ok' => false, 'error' => 'Espera unos segundos antes de enviar otro pedido.']);
}

try {
    $code = (string) ($input['code'] ?? '');
    $order = $code !== '' ? order_by_code($pdo, $code) : null;
    if ($order !== null && in_array($order['status'], OPEN_STATUSES, true)) {
        add_round($pdo, $order, (array) ($input['items'] ?? []), trim((string) ($input['notes'] ?? '')));
    } else {
        $code = create_order($pdo, $input);
    }
    $_SESSION['last_order'] = time();
    json_out(['ok' => true, 'code' => $code]);
} catch (InvalidArgumentException $exception) {
    json_out(['ok' => false, 'error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('Menú pedido: ' . $exception->getMessage());
    json_out(['ok' => false, 'error' => 'No pudimos registrar el pedido. Inténtalo de nuevo o pídelo al mesero.'], 500);
}
