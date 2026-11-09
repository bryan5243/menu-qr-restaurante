<?php

declare(strict_types=1);

require __DIR__ . '/../../src/admin.php';
require __DIR__ . '/../../src/backup.php';

$pdo = require_admin('respaldo');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_post();
    @set_time_limit(300);
    $stamp = date('Y-m-d_H-i');
    $slug = slugify(setting('name'));
    $tmp = tempnam(sys_get_temp_dir(), 'menu-');
    try {
        if (($_POST['tipo'] ?? '') === 'completo') {
            backup_full($pdo, $tmp);
            backup_send($tmp, "$slug-respaldo_$stamp.zip", 'application/zip');
        }
        backup_database($pdo, $tmp);
        backup_send($tmp, "$slug-base-de-datos_$stamp.sql", 'application/sql; charset=utf-8');
    } catch (Throwable $exception) {
        @unlink($tmp);
        @unlink($tmp . '.sql');
        error_log('Menú respaldo: ' . $exception->getMessage());
        flash($exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo generar el respaldo. Intenta de nuevo.', 'error');
        redirect('respaldo.php');
    }
}

$count = static fn (string $table): int => (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
$zipOk = class_exists(ZipArchive::class);
$engine = ['mysql' => 'MySQL', 'pgsql' => 'PostgreSQL (Supabase)', 'sqlite' => 'SQLite (demo)'][db_driver()];

admin_header('Respaldo', 'respaldo');
?>
<div class="a-head"><h1>Respaldo</h1></div>
<p class="hint">Descarga una copia cuando quieras: se genera en el momento y no queda guardada en el servidor. Base de datos actual: <b><?= e($engine) ?></b>.</p>
<div class="a-backup">
  <form class="panel a-backup-card" method="post">
    <?= csrf_field() ?><input type="hidden" name="tipo" value="base">
    <span class="a-backup-icon" aria-hidden="true"><?= icon('escudo') ?></span>
    <h2>Base de datos (.sql)</h2>
    <p>Menú (<?= $count('dishes') ?> platos), <?= $count('orders') ?> pedidos, <?= $count('reservations') ?> reservas, usuarios y ajustes.</p>
    <p class="hint"><?= db_driver() === 'mysql' ? 'Para restaurar: phpMyAdmin → Importar.' : 'Para restaurar: ejecútalo en el editor SQL de tu base.' ?></p>
    <button class="a-btn a-btn-primary" type="submit">Descargar base de datos</button>
  </form>
  <form class="panel a-backup-card" method="post">
    <?= csrf_field() ?><input type="hidden" name="tipo" value="completo">
    <span class="a-backup-icon" aria-hidden="true"><?= icon('imprimir') ?></span>
    <h2>Base + fotos (.zip)</h2>
    <p>La base de datos y todas las fotos que subiste desde el panel.</p>
    <button class="a-btn" type="submit"<?= $zipOk ? '' : ' disabled' ?>>Descargar ZIP</button>
    <?php if (!$zipOk): ?><p class="hint">Este servidor no tiene la extensión ZIP de PHP. En XAMPP se activa en <code>php.ini</code> con <code>extension=zip</code>. Mientras tanto, copia la carpeta <code>public/uploads</code> a mano.</p><?php endif; ?>
  </form>
</div>
<?php admin_footer(); ?>
