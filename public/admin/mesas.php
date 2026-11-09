<?php

declare(strict_types=1);

// Códigos QR para imprimir: uno por mesa y uno para pedidos para llevar o a domicilio.
require __DIR__ . '/../../src/admin.php';

$pdo = require_admin('mesas');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_post();
    save_setting($pdo, 'tables', (string) max(1, min(200, (int) ($_POST['tables'] ?? 1))));
    $site = trim((string) ($_POST['site_url'] ?? ''));
    if ($site !== '' && !preg_match('#^https?://#i', $site)) {
        $site = 'https://' . $site;
    }
    save_setting($pdo, 'site_url', filter_var($site, FILTER_VALIDATE_URL) ? rtrim($site, '/') : '');
    flash('Mesas guardadas.');
    redirect('mesas.php');
}

$tables = max(1, (int) setting('tables'));
$base = public_base_url();
$host = (string) parse_url($base, PHP_URL_HOST);
$local = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.local');
$codes = [['Para llevar o domicilio', $base, 'Escanea y pide para llevar']];
for ($t = 1; $t <= $tables; $t++) {
    $codes[] = ['Mesa ' . $t, $base . '?mesa=' . $t, 'Escanea, pide y paga en caja'];
}

admin_header('Mesas y QR', 'mesas', 'page-qr');
?>
<div class="a-head no-print">
  <div><h1>Mesas y códigos QR</h1><p class="hint">Imprime estos códigos y pégalos en cada mesa. Al escanearlo, el cliente ve el menú y su pedido llega a Cocina con el número de mesa.</p></div>
  <button class="a-btn a-btn-primary" type="button" onclick="window.print()"><?= icon('imprimir') ?> Imprimir</button>
</div>

<?php if ($local): ?>
  <p class="a-flash a-flash-warn no-print">Estás en <b><?= e($host) ?></b>: los celulares no pueden abrir esa dirección. Para probar en el local usa la IP de esta computadora (ej. <code>http://192.168.1.10/…</code>) o escribe abajo la dirección pública del sitio antes de imprimir.</p>
<?php endif; ?>

<form class="panel a-row no-print" method="post">
  <?= csrf_field() ?>
  <label>Número de mesas <input type="number" name="tables" min="1" max="200" value="<?= $tables ?>" class="a-num"></label>
  <label class="a-grow">Dirección pública del menú <small>vacío = la dirección actual (<?= e($base) ?>)</small><input name="site_url" value="<?= e(setting('site_url')) ?>" placeholder="https://lagaviota.com"></label>
  <button class="a-btn" type="submit">Guardar</button>
</form>

<div class="a-qr-grid">
  <?php foreach ($codes as [$label, $link, $sub]): ?>
    <figure class="a-qr">
      <div class="a-qr-brand"><?= site_logo() ?></div>
      <div class="a-qr-code" data-qr="<?= e($link) ?>" role="img" aria-label="Código QR de <?= e($label) ?>"></div>
      <figcaption><b><?= e($label) ?></b><span><?= e($sub) ?></span></figcaption>
      <a class="a-qr-link no-print" href="<?= e($link) ?>" target="_blank" rel="noopener"><?= e(preg_replace('#^https?://#', '', $link)) ?></a>
    </figure>
  <?php endforeach; ?>
</div>
<script src="../assets/vendor/qrcode.js"></script>
<script>
document.querySelectorAll('[data-qr]').forEach(el => {
  const qr = qrcode(0, 'M');
  qr.addData(el.dataset.qr);
  qr.make();
  el.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
});
</script>
<?php admin_footer(); ?>
