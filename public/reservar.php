<?php

declare(strict_types=1);

// Reservas: el cliente llena el formulario, la reserva queda en el panel y el restaurante la confirma.
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/orders.php';
require __DIR__ . '/../src/layout.php';

start_session();
$pdo = db();
if ($pdo === null || !setting_on('reservations_enabled')) {
    header('Location: ./');
    exit;
}

$errors = [];
$values = ['name' => '', 'phone' => '', 'date' => date('Y-m-d'), 'time' => '13:00', 'people' => '2', 'notes' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $default) {
        $values[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $people = (int) $values['people'];
    $date = DateTime::createFromFormat('Y-m-d', $values['date']);
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors[] = 'La página caducó. Vuelve a enviar el formulario.';
    }
    if (mb_strlen($values['name']) < 2) {
        $errors[] = 'Escribe tu nombre.';
    }
    if (strlen(preg_replace('/\D/', '', $values['phone'])) < 7) {
        $errors[] = 'Escribe un teléfono para confirmarte.';
    }
    if (!$date || $values['date'] < date('Y-m-d') || $values['date'] > date('Y-m-d', strtotime('+90 days'))) {
        $errors[] = 'Elige una fecha desde hoy hasta dentro de 3 meses.';
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $values['time'])) {
        $errors[] = 'Elige la hora.';
    }
    if ($people < 1 || $people > max(1, (int) setting('max_party'))) {
        $errors[] = 'Para grupos de más de ' . (int) setting('max_party') . ' personas, llámanos.';
    }
    if (time() - (int) ($_SESSION['last_reservation'] ?? 0) < 20) {
        $errors[] = 'Espera unos segundos antes de enviar otra reserva.';
    }
    if (!$errors) {
        $code = bin2hex(random_bytes(6));
        $pdo->prepare('INSERT INTO reservations (code, name, phone, res_date, res_time, people, notes, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$code, mb_substr($values['name'], 0, 120), mb_substr(preg_replace('/[^\d+ ]/', '', $values['phone']), 0, 40), $values['date'], $values['time'], $people, mb_substr($values['notes'], 0, 500), 'pendiente', now()]);
        $_SESSION['last_reservation'] = time();
        header('Location: reservar.php?c=' . $code, true, 303);
        exit;
    }
}

$reservation = null;
if (isset($_GET['c']) && preg_match('/^[a-f0-9]{12}$/', (string) $_GET['c'])) {
    $statement = $pdo->prepare('SELECT * FROM reservations WHERE code = ?');
    $statement->execute([$_GET['c']]);
    $reservation = $statement->fetch() ?: null;
}

function nice_date(string $date): string
{
    $days = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $months = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $t = strtotime($date);
    return $days[(int) date('w', $t)] . ' ' . (int) date('j', $t) . ' de ' . $months[(int) date('n', $t)];
}

page_header('Reservar mesa', 'page-reserve');
?>
<header class="top top-solid">
  <div class="wrap top-in">
    <a class="logo" href="./"><?= site_logo() ?></a>
    <a class="btn btn-ghost btn-sm" href="./"><?= icon('atras') ?><span>Menú</span></a>
  </div>
</header>

<main class="wrap reserve">
<?php if ($reservation): $status = $reservation['status']; ?>
  <section class="status-card res-card">
    <span class="res-icon res-<?= e($status) ?>" aria-hidden="true"><?= icon($status === 'confirmada' ? 'check' : 'calendario') ?></span>
    <p class="status-kicker">Reserva para <?= (int) $reservation['people'] ?> <?= (int) $reservation['people'] === 1 ? 'persona' : 'personas' ?></p>
    <h1><?= e(RES_STATUSES[$status] ?? $status) ?></h1>
    <p class="res-when"><?= e(ucfirst(nice_date($reservation['res_date']))) ?> · <?= e($reservation['res_time']) ?></p>
    <p class="hint"><?= $status === 'pendiente' ? e(setting('reservation_note')) : ($status === 'confirmada' ? '¡Te esperamos! Si no puedes venir, avísanos.' : 'Si tienes dudas, llámanos.') ?></p>
    <p class="hint">A nombre de <b><?= e($reservation['name']) ?></b> · <?= e($reservation['phone']) ?></p>
    <div class="res-actions">
      <?php if (setting_on('whatsapp_reservations') && whatsapp_number() !== '' && $status === 'pendiente'): ?>
        <a class="btn btn-wa" href="<?= e(whatsapp_link('Hola, quiero reservar una mesa para ' . (int) $reservation['people'] . ' personas el ' . nice_date($reservation['res_date']) . ' a las ' . $reservation['res_time'] . '. A nombre de ' . $reservation['name'] . '.')) ?>" target="_blank" rel="noopener"><?= icon('whatsapp') ?> Confirmar por WhatsApp</a>
      <?php endif; ?>
      <?php if (setting('phone') !== ''): ?><a class="btn btn-light" href="tel:<?= e(preg_replace('/[^\d+]/', '', setting('phone'))) ?>"><?= icon('telefono') ?> Llamar</a><?php endif; ?>
      <a class="btn btn-ghost" href="./"><?= icon('plato') ?> Ver el menú</a>
    </div>
    <p class="hint center">Guarda esta página para ver si ya confirmamos tu reserva.</p>
  </section>
<?php else: ?>
  <section class="reserve-head">
    <p class="hero-kicker"><?= icon('calendario') ?> Reservas</p>
    <h1>Reserva tu mesa</h1>
    <p><?= e(setting('schedule_text')) ?>. <?= e(setting('reservation_note')) ?></p>
  </section>
  <form class="reserve-form" method="post" novalidate>
    <?php if ($errors): ?><div class="form-error" role="alert"><?php foreach ($errors as $error): ?><p><?= e($error) ?></p><?php endforeach; ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label class="field">Nombre <input name="name" value="<?= e($values['name']) ?>" required maxlength="120" autocomplete="name"></label>
    <label class="field">Celular <input name="phone" type="tel" inputmode="tel" value="<?= e($values['phone']) ?>" required maxlength="20" autocomplete="tel" placeholder="09…"></label>
    <div class="grid-3">
      <label class="field">Fecha <input name="date" type="date" value="<?= e($values['date']) ?>" min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+90 days')) ?>" required></label>
      <label class="field">Hora <input name="time" type="time" value="<?= e($values['time']) ?>" step="900" required></label>
      <label class="field">Personas <input name="people" type="number" inputmode="numeric" min="1" max="<?= (int) setting('max_party') ?>" value="<?= e($values['people']) ?>" required></label>
    </div>
    <label class="field">¿Algo que debamos saber? (opcional) <textarea name="notes" rows="3" maxlength="500" placeholder="Cumpleaños, silla para bebé, mesa cerca del mar…"><?= e($values['notes']) ?></textarea></label>
    <button class="btn btn-primary btn-block" type="submit"><?= icon('calendario') ?> Enviar reserva</button>
  </form>
<?php endif; ?>
</main>
<?php page_footer([]); ?>
