<?php

declare(strict_types=1);

// Herramientas de la demostración: crear pedidos de ejemplo o vaciar pedidos y reservas.
require __DIR__ . '/../../src/admin.php';

$pdo = require_admin('demo');
check_post();
if (!demo_mode() && !setting_on('demo_notice')) {
    redirect('index.php');
}

if (($_POST['action'] ?? '') === 'reset') {
    $pdo->exec('DELETE FROM order_items');
    $pdo->exec('DELETE FROM orders');
    $pdo->exec('DELETE FROM reservations');
    flash('Listo: se borraron los pedidos y las reservas de la demo.');
    redirect('index.php');
}

// Tres pedidos en distintos estados y dos reservas, para mostrar la pantalla de cocina llena.
$dishes = array_column($pdo->query('SELECT id, name FROM dishes WHERE active = 1 AND available = 1 ORDER BY sort_order')->fetchAll(), 'id', 'name');
$pick = static fn (array $names): array => array_values(array_filter(array_map(static fn ($n, $q) => isset($dishes[$n]) ? ['id' => $dishes[$n], 'qty' => $q] : null, array_keys($names), $names)));
$samples = [
    [['kind' => 'mesa', 'table' => 3], ['Ceviche de camarón' => 2, 'Patacones con queso' => 1, 'Limonada imperial' => 2], 'recibido', null],
    [['kind' => 'mesa', 'table' => 7], ['Arroz marinero' => 1, 'Encocado de camarón' => 1, 'Cerveza nacional 600 ml' => 2], 'preparando', 12],
    [['kind' => 'llevar', 'name' => 'Carlos M.', 'phone' => '0990000001', 'payment' => 'efectivo'], ['Ceviche mixto' => 1, 'Jugo de maracuyá' => 1], 'listo', null],
    [['kind' => 'domicilio', 'name' => 'Lucía P.', 'phone' => '0990000002', 'address' => 'Barrio Central, frente al parque', 'payment' => 'transferencia'], ['Parrillada de mariscos para 2' => 1, 'Coco helado' => 2], 'recibido', null],
];
$created = 0;
foreach ($samples as [$input, $names, $status, $eta]) {
    if (!isset(enabled_kinds()[$input['kind']])) {
        continue;
    }
    if (isset($input['payment']) && !isset(enabled_payments()[$input['payment']])) {
        $input['payment'] = array_key_first(enabled_payments());
    }
    try {
        $code = create_order($pdo, $input + ['items' => $pick($names)]);
        $pdo->prepare('UPDATE orders SET status = ?, eta_minutes = ?, eta_at = ? WHERE code = ?')
            ->execute([$status, $eta, $eta ? date('Y-m-d H:i:s', time() + $eta * 60) : null, $code]);
        $created++;
    } catch (Throwable $exception) {
        error_log('Demo: ' . $exception->getMessage());
    }
}
$insert = $pdo->prepare('INSERT INTO reservations (code, name, phone, res_date, res_time, people, notes, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
$insert->execute([bin2hex(random_bytes(6)), 'Familia Romero', '0990000003', date('Y-m-d'), '13:30', 6, 'Cumpleaños, traen pastel', 'pendiente', now()]);
$insert->execute([bin2hex(random_bytes(6)), 'Andrés V.', '0990000004', date('Y-m-d', strtotime('+1 day')), '12:00', 2, '', 'confirmada', now()]);
flash("Se crearon $created pedidos y 2 reservas de ejemplo. Míralos en Cocina.");
redirect('cocina.php');
