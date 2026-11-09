<?php

declare(strict_types=1);

require_once __DIR__ . '/menu.php';

/** Tipos de pedido. */
const ORDER_KINDS = ['mesa' => 'En mesa', 'llevar' => 'Para llevar', 'domicilio' => 'A domicilio'];

/** Estados: [texto para el cliente, texto corto para cocina]. */
const ORDER_STATUSES = [
    'recibido' => ['Pedido recibido', 'Nuevo'],
    'preparando' => ['En preparación', 'Preparando'],
    'listo' => ['¡Listo!', 'Listo'],
    'entregado' => ['Entregado', 'Entregado'],
    'cerrado' => ['Cuenta cerrada', 'Cerrado'],
    'cancelado' => ['Cancelado', 'Cancelado'],
];

const OPEN_STATUSES = ['recibido', 'preparando', 'listo', 'entregado'];

const PAYMENTS = ['efectivo' => 'Efectivo', 'transferencia' => 'Transferencia', 'otro' => 'Otro medio'];

function order_label(array $order): string
{
    return match ($order['kind']) {
        'mesa' => 'Mesa ' . (int) $order['table_no'],
        'llevar' => 'Para llevar' . ($order['customer_name'] !== '' ? ' · ' . $order['customer_name'] : ''),
        default => 'Domicilio' . ($order['customer_name'] !== '' ? ' · ' . $order['customer_name'] : ''),
    };
}

function enabled_payments(): array
{
    $out = [];
    if (setting_on('pay_cash')) {
        $out['efectivo'] = PAYMENTS['efectivo'];
    }
    if (setting_on('pay_transfer') && setting('bank_number') !== '') {
        $out['transferencia'] = PAYMENTS['transferencia'];
    }
    if (trim(setting('pay_other')) !== '') {
        $out['otro'] = PAYMENTS['otro'];
    }
    return $out ?: ['efectivo' => PAYMENTS['efectivo']];
}

function enabled_kinds(): array
{
    return array_filter(ORDER_KINDS, static fn (string $kind): bool => setting_on('mode_' . $kind), ARRAY_FILTER_USE_KEY);
}

/**
 * Valida los platos que manda el navegador y toma los precios de la base de datos.
 * Devuelve [líneas, subtotal]. Lanza InvalidArgumentException con un mensaje para el cliente.
 */
function priced_lines(PDO $pdo, array $items): array
{
    $statement = $pdo->prepare('SELECT d.id, d.name, d.price, d.available, d.options FROM dishes d JOIN categories c ON c.id = d.category_id WHERE d.id = ? AND d.active = 1 AND c.active = 1');
    $lines = [];
    $subtotal = 0.0;
    foreach (array_slice($items, 0, 40) as $item) {
        $statement->execute([(int) ($item['id'] ?? 0)]);
        $dish = $statement->fetch();
        if (!$dish) {
            continue;
        }
        if ((int) $dish['available'] !== 1) {
            throw new InvalidArgumentException('«' . $dish['name'] . '» se acaba de agotar. Quítalo del pedido para continuar.');
        }
        $qty = max(1, min(50, (int) ($item['qty'] ?? 1)));
        $price = (float) $dish['price'];
        // Opciones elegidas: se validan contra las del plato y los adicionales suman su precio.
        $available = dish_options((string) $dish['options']);
        $picked = array_unique(array_map('intval', array_filter((array) ($item['opts'] ?? []), 'is_numeric')));
        sort($picked);
        $chosen = [];
        foreach ($picked as $index) {
            if (isset($available[$index])) {
                $option = $available[$index];
                $price += $option['price'];
                $chosen[] = ($option['type'] === 'extra' ? '+ ' : '') . $option['name'];
            }
        }
        $price = round($price, 2);
        $subtotal += $price * $qty;
        $lines[] = ['dish_id' => (int) $dish['id'], 'name' => $dish['name'], 'price' => $price, 'qty' => $qty, 'note' => trim(mb_substr((string) ($item['note'] ?? ''), 0, 160)), 'options' => mb_substr(implode(' · ', $chosen), 0, 255)];
    }
    if ($lines === []) {
        throw new InvalidArgumentException('Tu pedido está vacío.');
    }
    return [$lines, round($subtotal, 2)];
}

function insert_lines(PDO $pdo, int $orderId, int $round, array $lines): void
{
    $insert = $pdo->prepare('INSERT INTO order_items (order_id, round, dish_id, name, price, qty, note, options, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($lines as $line) {
        $insert->execute([$orderId, $round, $line['dish_id'], $line['name'], $line['price'], $line['qty'], $line['note'], $line['options'] ?? '', now()]);
    }
}

/** Crea un pedido nuevo. Devuelve su código. */
function create_order(PDO $pdo, array $input): string
{
    $kind = (string) ($input['kind'] ?? '');
    if (!isset(enabled_kinds()[$kind])) {
        throw new InvalidArgumentException('Elige cómo quieres tu pedido.');
    }
    $name = trim(mb_substr((string) ($input['name'] ?? ''), 0, 120));
    $phone = trim(mb_substr(preg_replace('/[^\d+ ]/', '', (string) ($input['phone'] ?? '')), 0, 40));
    $address = trim(mb_substr((string) ($input['address'] ?? ''), 0, 255));
    $notes = trim(mb_substr((string) ($input['notes'] ?? ''), 0, 500));
    $table = null;
    $payment = '';
    $fee = 0.0;

    if ($kind === 'mesa') {
        $table = (int) ($input['table'] ?? 0);
        if ($table < 1 || $table > max(1, (int) setting('tables'))) {
            throw new InvalidArgumentException('Ese número de mesa no existe. Escanea el código QR de tu mesa.');
        }
    } else {
        if ($name === '' || strlen(preg_replace('/\D/', '', $phone)) < 7) {
            throw new InvalidArgumentException('Escribe tu nombre y un teléfono para avisarte.');
        }
        if ($kind === 'domicilio' && mb_strlen($address) < 6) {
            throw new InvalidArgumentException('Escribe la dirección de entrega con una referencia.');
        }
        $payment = (string) ($input['payment'] ?? '');
        if (!isset(enabled_payments()[$payment])) {
            throw new InvalidArgumentException('Elige cómo vas a pagar.');
        }
        if ($kind === 'domicilio') {
            $fee = max(0, (float) setting('delivery_fee'));
        }
    }

    [$lines, $subtotal] = priced_lines($pdo, (array) ($input['items'] ?? []));
    $code = bin2hex(random_bytes(6));
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO orders (code, kind, table_no, customer_name, phone, address, payment, notes, status, rounds, delivery_fee, total, created_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)')
            ->execute([$code, $kind, $table, $name, $phone, $address, $payment, $notes, 'recibido', $fee, $subtotal + $fee, now(), now()]);
        insert_lines($pdo, insert_id($pdo, 'orders'), 1, $lines);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
    return $code;
}

/** Agrega platos a un pedido abierto (por ejemplo, la mesa pide otra ronda). */
function add_round(PDO $pdo, array $order, array $items, string $note = ''): void
{
    if (!in_array($order['status'], OPEN_STATUSES, true)) {
        throw new InvalidArgumentException('Este pedido ya se cerró. Haz un pedido nuevo.');
    }
    [$lines, $subtotal] = priced_lines($pdo, $items);
    if ($note !== '') {
        $lines[0]['note'] = trim($lines[0]['note'] . ' · ' . mb_substr($note, 0, 120), ' ·');
    }
    $round = (int) $order['rounds'] + 1;
    $pdo->beginTransaction();
    try {
        insert_lines($pdo, (int) $order['id'], $round, $lines);
        // La ronda nueva vuelve a la columna «Nuevos» de cocina si el pedido ya estaba listo o servido.
        $status = in_array($order['status'], ['listo', 'entregado'], true) ? 'recibido' : $order['status'];
        $pdo->prepare('UPDATE orders SET rounds = ?, total = total + ?, status = ?, updated_at = ? WHERE id = ?')
            ->execute([$round, $subtotal, $status, now(), (int) $order['id']]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function order_by_code(PDO $pdo, string $code): ?array
{
    if (!preg_match('/^[a-f0-9]{12}$/', $code)) {
        return null;
    }
    $statement = $pdo->prepare('SELECT * FROM orders WHERE code = ?');
    $statement->execute([$code]);
    return $statement->fetch() ?: null;
}

function order_by_id(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $statement->execute([$id]);
    return $statement->fetch() ?: null;
}

/** Platos de varios pedidos a la vez, agrupados por id de pedido. */
function items_for(PDO $pdo, array $orderIds): array
{
    if ($orderIds === []) {
        return [];
    }
    $in = implode(',', array_fill(0, count($orderIds), '?'));
    $statement = $pdo->prepare("SELECT * FROM order_items WHERE order_id IN ($in) ORDER BY round, id");
    $statement->execute(array_values(array_map('intval', $orderIds)));
    $out = [];
    foreach ($statement->fetchAll() as $row) {
        $out[(int) $row['order_id']][] = ['round' => (int) $row['round'], 'name' => $row['name'], 'qty' => (int) $row['qty'], 'price' => (float) $row['price'], 'note' => $row['note'], 'opts' => (string) ($row['options'] ?? '')];
    }
    return $out;
}

/** Minutos que faltan según el tiempo estimado que puso cocina (null si no hay). */
function eta_left(array $order): ?int
{
    if (empty($order['eta_at'])) {
        return null;
    }
    return (int) max(0, ceil((strtotime((string) $order['eta_at']) - time()) / 60));
}

/** Datos del pedido que ve el cliente en su celular. */
function order_public(array $order, array $items): array
{
    return [
        'code' => $order['code'],
        'number' => (int) $order['id'],
        'kind' => $order['kind'],
        'label' => order_label($order),
        'status' => $order['status'],
        'statusText' => ORDER_STATUSES[$order['status']][0] ?? $order['status'],
        'eta' => eta_left($order),
        'etaAt' => $order['eta_at'] ? strtotime((string) $order['eta_at']) * 1000 : null,
        'etaTotal' => $order['eta_minutes'] ? (int) $order['eta_minutes'] * 60000 : null,
        'call' => $order['call_flag'],
        'payment' => $order['payment'],
        'fee' => (float) $order['delivery_fee'],
        'total' => (float) $order['total'],
        'rounds' => (int) $order['rounds'],
        'items' => $items,
        'open' => in_array($order['status'], OPEN_STATUSES, true),
    ];
}

/** Pedidos abiertos para la pantalla de cocina y meseros. */
function kitchen_orders(PDO $pdo): array
{
    $in = implode(',', array_fill(0, count(OPEN_STATUSES), '?'));
    $statement = $pdo->prepare("SELECT * FROM orders WHERE status IN ($in) ORDER BY created_at, id");
    $statement->execute(OPEN_STATUSES);
    $orders = $statement->fetchAll();
    $items = items_for($pdo, array_column($orders, 'id'));
    $out = [];
    foreach ($orders as $order) {
        $out[] = order_public($order, $items[(int) $order['id']] ?? []) + [
            'id' => (int) $order['id'],
            'name' => $order['customer_name'],
            'phone' => $order['phone'],
            'address' => $order['address'],
            'notes' => $order['notes'],
            'created' => strtotime((string) $order['created_at']) * 1000,
            'updated' => strtotime((string) $order['updated_at']) * 1000,
            'paymentText' => PAYMENTS[$order['payment']] ?? '',
        ];
    }
    return $out;
}

/** Estados de una reserva. */
const RES_STATUSES = ['pendiente' => 'Pendiente de confirmar', 'confirmada' => 'Confirmada', 'rechazada' => 'No disponible', 'cancelada' => 'Cancelada', 'atendida' => 'Atendida'];
