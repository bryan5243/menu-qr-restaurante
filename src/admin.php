<?php

declare(strict_types=1);

// Panel: sesión, permisos, seguridad y plantilla común.
define('BASE', '../');
define('PUBLIC_DIR', dirname(__DIR__) . '/public');
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/menu.php';
require __DIR__ . '/orders.php';
require_once __DIR__ . '/install.php';

start_session();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

/** Roles: el administrador ve todo; el personal de cocina solo Cocina y Reservas. */
const ROLES = ['admin' => 'Administrador', 'cocina' => 'Cocina y meseros'];
const STAFF_PAGES = ['cocina', 'reservas'];

function admin_user(): ?array
{
    return $_SESSION['admin'] ?? null;
}

function is_owner(): bool
{
    return (admin_user()['role'] ?? '') === 'admin';
}

/** Exige sesión. $page es la sección; el personal de cocina solo entra a STAFF_PAGES. */
function require_admin(string $page = ''): PDO
{
    $pdo = db();
    if ($pdo === null) {
        admin_header('Base de datos');
        echo '<div class="panel"><h1>No hay conexión con la base de datos</h1><p>Revisa los datos de <code>config/config.php</code>. Si quieres usar la demo sin configurar nada, borra ese archivo y se usará SQLite.</p></div>';
        admin_footer();
        exit;
    }
    if (admin_user() === null) {
        if (isset($_GET['json']) || str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) {
            json_out(['ok' => false, 'login' => true], 401);
        }
        header('Location: index.php');
        exit;
    }
    // La sesión caduca tras 12 horas sin actividad (la pantalla de cocina queda abierta todo el turno).
    if (time() - ($_SESSION['admin_seen'] ?? 0) > 43200) {
        unset($_SESSION['admin']);
        header('Location: index.php?caducada=1');
        exit;
    }
    $_SESSION['admin_seen'] = time();
    if ($page !== '' && !is_owner() && !in_array($page, STAFF_PAGES, true)) {
        header('Location: cocina.php');
        exit;
    }
    return $pdo;
}

/** Verifica el token CSRF de un POST de formulario; si falla, corta la petición. */
function check_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid($_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('Solicitud no válida. Vuelve atrás y recarga la página.');
    }
}

function flash(?string $message = null, string $type = 'ok'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function slugify(string $text): string
{
    $text = mb_strtolower(trim($text));
    $text = strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim((string) $text, '-') ?: 'item-' . bin2hex(random_bytes(3));
}

/**
 * Guarda una imagen subida en public/uploads y devuelve su ruta (uploads/...), o null si no se subió.
 * Lanza RuntimeException si el archivo no es una imagen válida.
 */
function save_upload(string $field): ?string
{
    $file = $_FILES[$field] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo subir la imagen. Prueba con un archivo más liviano (máximo 4 MB).');
    }
    if ($file['size'] > 4 * 1024 * 1024) {
        throw new RuntimeException('La imagen supera los 4 MB.');
    }
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($types[$info[2]])) {
        throw new RuntimeException('Sube una imagen JPG, PNG, WEBP o GIF.');
    }
    $dir = PUBLIC_DIR . '/uploads/' . date('Ym');
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('No se pudo crear la carpeta de imágenes.');
    }
    $name = bin2hex(random_bytes(8)) . '.' . $types[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('No se pudo guardar la imagen.');
    }
    return 'uploads/' . date('Ym') . '/' . $name;
}

function admin_header(string $title, string $active = '', string $bodyClass = ''): void
{
    $user = admin_user();
    $links = [
        'inicio' => ['index.php', 'Resumen', 'grafico'],
        'cocina' => ['cocina.php', 'Cocina', 'cocina'],
        'pedidos' => ['pedidos.php', 'Pedidos', 'lista'],
        'reservas' => ['reservas.php', 'Reservas', 'calendario'],
        'platos' => ['platos.php', 'Menú', 'plato'],
        'mesas' => ['mesas.php', 'Mesas y QR', 'qr'],
        'ajustes' => ['ajustes.php', 'Ajustes', 'ajustes'],
        'personal' => ['personal.php', 'Personal', 'usuarios'],
        'respaldo' => ['respaldo.php', 'Respaldo', 'escudo'],
    ];
    if ($user && !is_owner()) {
        $links = array_intersect_key($links, array_flip(STAFF_PAGES));
    }
    ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title) ?> · Panel <?= e(setting('name')) ?></title>
  <link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="../assets/admin.css?v=<?= ASSET_VERSION_ADMIN ?>">
  <?= brand_style() ?>
</head>
<body class="<?= $user ? 'is-auth' : 'is-guest' ?> <?= e($bodyClass) ?>">
<?php if ($user): ?>
  <header class="a-top">
    <a class="a-logo" href="<?= is_owner() ? 'index.php' : 'cocina.php' ?>"><?= site_logo() ?> <small>Panel</small></a>
    <nav class="a-nav" aria-label="Panel">
      <?php foreach ($links as $key => [$href, $label, $ico]): ?>
        <a href="<?= $href ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= icon($ico) ?><span><?= $label ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="a-user">
      <a href="../" target="_blank" rel="noopener">Ver menú</a>
      <form method="post" action="salir.php"><?= csrf_field() ?><button type="submit">Salir (<?= e($user['username']) ?>)</button></form>
    </div>
  </header>
<?php endif; ?>
<main class="a-main">
<?php if ($flash = flash()): ?>
  <p class="a-flash a-flash-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></p>
<?php endif;
}

const ASSET_VERSION_ADMIN = '2';

function admin_footer(array $scripts = []): void
{
    ?>
</main>
<script>
document.querySelectorAll('[data-confirm]').forEach(f => f.addEventListener('submit', e => { if (!confirm(f.dataset.confirm)) e.preventDefault(); }));
document.querySelectorAll('input[type=file][data-preview]').forEach(i => i.addEventListener('change', () => {
  const img = document.querySelector(i.dataset.preview); if (img && i.files[0]) { img.src = URL.createObjectURL(i.files[0]); img.hidden = false; }
}));
// Adicionales del plato: el precio solo aplica a «Adicional»; «+ Otra fila» copia una fila vacía.
const optList = document.querySelector('[data-opt-list]');
if (optList) {
  const sync = row => { row.querySelector('.a-opt-price').hidden = row.querySelector('select').value === 'sin'; };
  optList.addEventListener('change', e => { if (e.target.matches('select')) sync(e.target.closest('[data-opt]')); });
  document.querySelector('[data-opt-add]').addEventListener('click', () => {
    const rows = optList.querySelectorAll('[data-opt]');
    if (rows.length >= 12) return;
    const row = rows[rows.length - 1].cloneNode(true);
    row.querySelectorAll('input').forEach(i => { i.value = ''; });
    row.querySelector('select').value = 'extra'; sync(row);
    optList.append(row); row.querySelector('input').focus();
  });
}
</script>
<?php foreach ($scripts as $script): ?><script src="<?= e($script) ?>?v=<?= ASSET_VERSION_ADMIN ?>" defer></script><?php endforeach; ?>
</body>
</html>
<?php
}

/** Fecha y hora cortas para el panel. */
function short_dt(?string $value): string
{
    return $value ? date('d/m H:i', strtotime($value)) : '';
}
