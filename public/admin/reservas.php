<?php

declare(strict_types=1);

require __DIR__ . '/../../src/admin.php';

$pdo = require_admin('reservas');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_post();
    $status = (string) ($_POST['status'] ?? '');
    if (isset(RES_STATUSES[$status])) {
        $pdo->prepare('UPDATE reservations SET status = ? WHERE id = ?')->execute([$status, (int) ($_POST['id'] ?? 0)]);
        flash('Reserva actualizada: ' . mb_strtolower(RES_STATUSES[$status]) . '.');
    }
    redirect('reservas.php' . (isset($_POST['ver']) ? '?ver=' . rawurlencode((string) $_POST['ver']) : ''));
}

$view = in_array($_GET['ver'] ?? '', ['proximas', 'pasadas'], true) ? $_GET['ver'] : 'proximas';
$statement = $view === 'proximas'
    ? $pdo->prepare('SELECT * FROM reservations WHERE res_date >= ? ORDER BY res_date, res_time LIMIT 200')
    : $pdo->prepare('SELECT * FROM reservations WHERE res_date < ? ORDER BY res_date DESC, res_time DESC LIMIT 200');
$statement->execute([date('Y-m-d')]);
$rows = $statement->fetchAll();
$tag = ['pendiente' => 'tag-warn', 'confirmada' => 'tag-ok', 'rechazada' => 'tag-off', 'cancelada' => 'tag-off', 'atendida' => 'tag-muted'];
$days = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
$name = setting('name');

admin_header('Reservas', 'reservas');
?>
<div class="a-head"><h1>Reservas</h1></div>
<nav class="a-tabs" aria-label="Reservas">
  <a href="?ver=proximas"<?= $view === 'proximas' ? ' aria-current="page"' : '' ?>>Hoy y próximas</a>
  <a href="?ver=pasadas"<?= $view === 'pasadas' ? ' aria-current="page"' : '' ?>>Pasadas</a>
</nav>
<p class="hint">Confirma cada reserva y avísale al cliente con un toque. El mensaje sale desde el WhatsApp del celular que estés usando.</p>
<?php if (!$rows): ?><div class="panel"><p class="hint">No hay reservas aquí.</p></div><?php endif; ?>
<div class="a-res-list">
  <?php $lastDate = null; foreach ($rows as $r): ?>
    <?php if ($r['res_date'] !== $lastDate): $lastDate = $r['res_date']; ?>
      <h2 class="a-day"><?= $r['res_date'] === date('Y-m-d') ? 'Hoy' : e($days[(int) date('w', strtotime($r['res_date']))] . ' ' . date('d/m', strtotime($r['res_date']))) ?></h2>
    <?php endif; ?>
    <article class="panel a-res">
      <div class="a-res-time"><b><?= e($r['res_time']) ?></b><small><?= (int) $r['people'] ?> pers.</small></div>
      <div class="a-res-main">
        <p><b><?= e($r['name']) ?></b> <span class="tag <?= $tag[$r['status']] ?? '' ?>"><?= e(RES_STATUSES[$r['status']] ?? $r['status']) ?></span></p>
        <p class="hint"><a href="tel:<?= e($r['phone']) ?>"><?= e($r['phone']) ?></a><?= $r['notes'] !== '' ? ' · ' . e($r['notes']) : '' ?></p>
      </div>
      <div class="a-row a-res-actions">
        <?php
        $msg = $r['status'] === 'rechazada'
            ? "Hola {$r['name']}, lo sentimos: para el " . date('d/m', strtotime($r['res_date'])) . " a las {$r['res_time']} no tenemos mesa disponible. ¿Te sirve otro horario? — $name"
            : "Hola {$r['name']}, tu reserva en $name para {$r['people']} personas el " . date('d/m', strtotime($r['res_date'])) . " a las {$r['res_time']} está confirmada. ¡Te esperamos!";
        $wa = whatsapp_link($msg, $r['phone']);
        ?>
        <?php foreach (['confirmada' => 'Confirmar', 'rechazada' => 'Sin mesa', 'atendida' => 'Llegó', 'cancelada' => 'Canceló'] as $st => $label): if ($st === $r['status']) continue; ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="ver" value="<?= e($view) ?>"><button class="a-btn a-btn-sm<?= $st === 'confirmada' ? ' a-btn-primary' : '' ?>" name="status" value="<?= $st ?>"><?= $label ?></button></form>
        <?php endforeach; ?>
        <?php if ($wa !== '' && in_array($r['status'], ['confirmada', 'rechazada'], true)): ?><a class="a-btn a-btn-sm a-btn-wa" href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= icon('whatsapp') ?> Avisar</a><?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<?php admin_footer(); ?>
