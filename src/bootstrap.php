<?php

declare(strict_types=1);

const ROOT = __DIR__ . '/..';
const DEMO_DB = ROOT . '/storage/demo.sqlite';

date_default_timezone_set('America/Guayaquil');

// Prefijo para enlaces y recursos: '' en las páginas públicas, '../' en /admin.
if (!defined('BASE')) {
    define('BASE', '');
}

function config(): array
{
    static $config = null;
    if ($config === null) {
        $path = ROOT . '/config/config.php';
        $config = is_file($path) ? require $path : [];
    }
    return $config;
}

/**
 * Motor de base de datos: 'mysql' (hosting con cPanel o Hostinger), 'pgsql' (Supabase)
 * o 'sqlite' (modo demo: sin config/config.php se usa storage/demo.sqlite y no hay que configurar nada).
 */
function db_driver(): string
{
    $db = config()['db'] ?? null;
    if ($db === null) {
        return 'sqlite';
    }
    return in_array($db['driver'] ?? 'mysql', ['mysql', 'pgsql', 'sqlite'], true) ? ($db['driver'] ?? 'mysql') : 'mysql';
}

/** ¿Se está usando la base de demostración automática (sin config.php)? */
function demo_mode(): bool
{
    return (config()['db'] ?? null) === null;
}

function db(): ?PDO
{
    static $pdo = null;
    static $attempted = false;
    if ($attempted) {
        return $pdo;
    }
    $attempted = true;

    $db = config()['db'] ?? [];
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    try {
        switch (db_driver()) {
            case 'sqlite':
                $path = $db['path'] ?? DEMO_DB;
                if (!is_dir(dirname($path))) {
                    @mkdir(dirname($path), 0755, true);
                }
                $pdo = new PDO('sqlite:' . $path, null, null, $options);
                $pdo->exec('PRAGMA journal_mode = WAL');
                $pdo->exec('PRAGMA busy_timeout = 5000');
                $pdo->exec('PRAGMA foreign_keys = ON');
                break;
            case 'pgsql':
                $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $db['host'], $db['port'] ?? 5432, $db['name'] ?? 'postgres', $db['sslmode'] ?? 'require');
                $pdo = new PDO($dsn, $db['user'], $db['pass'], $options);
                $pdo->exec("SET TIME ZONE 'America/Guayaquil'");
                break;
            default:
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'] ?? 3306, $db['name']);
                $pdo = new PDO($dsn, $db['user'], $db['pass'], $options);
        }
    } catch (PDOException $exception) {
        error_log('Menú DB: ' . $exception->getMessage());
        $pdo = null;
        return null;
    }

    require_once __DIR__ . '/install.php';
    // Modo demo: la primera visita crea las tablas y carga el restaurante de ejemplo.
    if (demo_mode() && !is_installed($pdo)) {
        run_install($pdo, true);
    }
    migrate_dish_options($pdo);
    return $pdo;
}

/* ---------- Opciones por plato: ingredientes que se pueden quitar y adicionales con precio ---------- */

const OPTIONS_MAX = 12;

/** Convierte listas de «se puede quitar» y adicionales al JSON que se guarda en dishes.options ('' si no hay). */
function options_json(array $remove, array $extras): string
{
    $clean = static fn ($name): string => trim(mb_substr(preg_replace('/\s+/u', ' ', (string) $name), 0, 40));
    $outRemove = [];
    foreach ($remove as $name) {
        $name = $clean($name);
        if ($name !== '' && !in_array($name, $outRemove, true) && count($outRemove) < OPTIONS_MAX) {
            $outRemove[] = $name;
        }
    }
    $outExtras = [];
    foreach ($extras as $extra) {
        $name = $clean($extra['name'] ?? '');
        if ($name !== '' && !in_array($name, array_column($outExtras, 'name'), true) && count($outExtras) < OPTIONS_MAX) {
            $outExtras[] = ['name' => $name, 'price' => round(min(500, max(0, (float) ($extra['price'] ?? 0))), 2)];
        }
    }
    if ($outRemove === [] && $outExtras === []) {
        return '';
    }
    return json_encode(['remove' => $outRemove, 'extras' => $outExtras], JSON_UNESCAPED_UNICODE);
}

/** Opciones de un plato: ['remove' => [nombre…], 'extras' => [['name' => …, 'price' => …]…]]. */
function dish_options(array $dish): array
{
    $data = json_decode((string) ($dish['options'] ?? ''), true);
    $data = is_array($data) ? $data : [];
    $decoded = json_decode(options_json((array) ($data['remove'] ?? []), (array) ($data['extras'] ?? [])), true);
    return is_array($decoded) ? $decoded : ['remove' => [], 'extras' => []];
}

/** Texto para cocina: «Sin menestra, sin chimichurri · + Huevo frito». */
function options_summary(array $remove, array $extras): string
{
    $parts = [];
    if ($remove !== []) {
        $parts[] = 'Sin ' . implode(', sin ', array_map(static fn ($n) => mb_strtolower(mb_substr($n, 0, 1)) . mb_substr($n, 1), $remove));
    }
    if ($extras !== []) {
        $parts[] = '+ ' . implode(', + ', $extras);
    }
    return implode(' · ', $parts);
}

/** Id del último registro insertado (PostgreSQL necesita el nombre de la secuencia). */
function insert_id(PDO $pdo, string $table): int
{
    return (int) (db_driver() === 'pgsql' ? $pdo->lastInsertId($table . '_id_seq') : $pdo->lastInsertId());
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'path' => '/']);
    session_start();
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE . ltrim($path, '/');
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Ajustes del restaurante: los de la base de datos sobre los valores iniciales de config/demo.php. */
function settings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }
    $settings = (require ROOT . '/config/demo.php')['settings'];
    $pdo = db();
    if ($pdo === null) {
        return $settings;
    }
    try {
        foreach ($pdo->query('SELECT setting_key, setting_value FROM settings') as $row) {
            $settings[$row['setting_key']] = (string) $row['setting_value'];
        }
    } catch (PDOException $exception) {
        error_log('Menú DB: ' . $exception->getMessage());
    }
    return $settings;
}

function setting(string $key): string
{
    return (string) (settings()[$key] ?? '');
}

function setting_on(string $key): bool
{
    return setting($key) === '1';
}

/** Guarda un ajuste (funciona igual en MySQL, PostgreSQL y SQLite). */
function save_setting(PDO $pdo, string $key, string $value): void
{
    $exists = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE setting_key = ?');
    $exists->execute([$key]);
    if ((int) $exists->fetchColumn() > 0) {
        $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?')->execute([$value, $key]);
    } else {
        $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)')->execute([$key, $value]);
    }
}

function whatsapp_number(): string
{
    return preg_replace('/\D/', '', setting('whatsapp'));
}

/** Enlace wa.me a un número (el del restaurante si no se indica otro). */
function whatsapp_link(string $message = '', ?string $number = null): string
{
    $number = preg_replace('/\D/', '', $number ?? whatsapp_number());
    if ($number === '') {
        return '';
    }
    // Celulares de Ecuador escritos como 09XXXXXXXX: se agrega el código de país.
    if (strlen($number) === 10 && str_starts_with($number, '09')) {
        $number = '593' . substr($number, 1);
    }
    return 'https://wa.me/' . $number . ($message !== '' ? '?text=' . rawurlencode($message) : '');
}

function map_embed_src(): string
{
    $value = trim(setting('map_embed'));
    if (str_starts_with($value, 'https://www.google.com/maps/embed')) {
        return $value;
    }
    $query = $value !== '' ? $value : setting('address');
    return $query === '' ? '' : 'https://www.google.com/maps?q=' . rawurlencode($query) . '&z=15&output=embed';
}

function map_directions_url(): string
{
    $value = trim(setting('map_embed'));
    $query = $value !== '' && !str_starts_with($value, 'http') ? $value : setting('address');
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valid(?string $token): bool
{
    return hash_equals($_SESSION['csrf'] ?? '', (string) $token);
}

function money(?float $price): string
{
    return $price === null ? '—' : '$' . number_format($price, 2, '.', ',');
}

/** URL de una imagen: Unsplash se pide recortada; las rutas locales se resuelven desde la raíz pública. */
function image_src(string $url, int $width, int $height): string
{
    if ($url === '') {
        return '';
    }
    if (str_starts_with($url, 'https://images.unsplash.com/')) {
        return $url . '?auto=format&fit=crop&q=70&w=' . $width . '&h=' . $height;
    }
    if (preg_match('#^https?://#', $url)) {
        return $url;
    }
    return url($url);
}

/** Dibujo de respaldo por categoría, si un plato no tiene foto o la foto no carga. */
function fallback_art(string $kind): string
{
    $kind = in_array($kind, ['entradas', 'ceviches', 'platos', 'bebidas', 'postres'], true) ? $kind : 'platos';
    return url('assets/img/plato-' . $kind . '.svg');
}

/** <img> de un plato con dibujo de respaldo si la foto no carga (por ejemplo, sin internet). */
function dish_img(string $src, string $alt, int $w, int $h, string $kind, bool $eager = false, string $class = ''): string
{
    $fallback = fallback_art($kind);
    $real = image_src($src, $w * 2, $h * 2);
    return '<img src="' . e($real !== '' ? $real : $fallback) . '" alt="' . e($alt) . '" width="' . $w . '" height="' . $h . '"'
        . ($class !== '' ? ' class="' . e($class) . '"' : '')
        . ($eager ? ' fetchpriority="high"' : ' loading="lazy"') . ' decoding="async"'
        . ' onerror="this.onerror=null;this.src=\'' . e($fallback) . '\'">';
}

function icon(string $name, string $class = 'icon'): string
{
    $paths = [
        'plato' => '<path d="M6 30h36"/><path d="M9 30a15 15 0 0 1 30 0"/><path d="M24 15v-3M20 12h8"/><path d="M4 36h40"/>',
        'pez' => '<path d="M6 24c6-8 14-11 22-11 6 0 10 4 13 11-3 7-7 11-13 11-8 0-16-3-22-11z"/><path d="M6 24l-3-6v12z"/><circle cx="33" cy="22" r="1.6"/>',
        'carrito' => '<path d="M4 6h6l5 24h22l5-17H13"/><circle cx="18" cy="38" r="3"/><circle cx="34" cy="38" r="3"/>',
        'bolsa' => '<path d="M9 16h30l3 26H6z"/><path d="M17 21v-8a7 7 0 0 1 14 0v8"/>',
        'moto' => '<circle cx="11" cy="34" r="6"/><circle cx="37" cy="34" r="6"/><path d="M11 34h14l6-12h6M25 34l-5-12h-7M31 22l-3-8h-5"/>',
        'mesa' => '<path d="M6 18h36M10 18v20M38 18v20M14 26h20"/>',
        'calendario' => '<rect x="6" y="9" width="36" height="33" rx="4"/><path d="M6 19h36M16 5v8M32 5v8"/>',
        'reloj' => '<circle cx="24" cy="24" r="18"/><path d="M24 13v12l8 5"/>',
        'ubicacion' => '<path d="M24 44S9 29 9 19a15 15 0 0 1 30 0c0 10-15 25-15 25z"/><circle cx="24" cy="19" r="5"/>',
        'telefono' => '<path d="M14 5l6 9-4 4a24 24 0 0 0 14 14l4-4 9 6-3 7C22 41 7 26 7 8z"/>',
        'whatsapp' => '<path d="M7 41l2.6-9.4A17 17 0 1 1 16 38z"/><path d="M18 15c-1 0-2.5 1.2-2.5 3.5 0 4 5.5 11 12 12.5 2 .5 4-1 4.5-2.5l-4-2.2-2 2c-2.5-1-5.4-3.8-6.3-6.3l2-2L19.5 15z"/>',
        'instagram' => '<rect x="6" y="6" width="36" height="36" rx="10"/><circle cx="24" cy="24" r="8"/><circle cx="35" cy="13" r="1.5"/>',
        'facebook' => '<path d="M28 44V27h6l1-7h-7v-4c0-2 1-3 3-3h4V7a40 40 0 0 0-6-.5c-6 0-9 3.5-9 9.5v4h-6v7h6v17"/>',
        'tiktok' => '<path d="M28 6v24a7 7 0 1 1-7-7M28 6c1 5 5 9 11 9"/>',
        'mas' => '<path d="M24 10v28M10 24h28"/>',
        'menos' => '<path d="M10 24h28"/>',
        'cerrar' => '<path d="M12 12l24 24M36 12L12 36"/>',
        'check' => '<path d="M10 25l9 9 19-20"/>',
        'flecha' => '<path d="M6 24h34M28 11l13 13-13 13"/>',
        'atras' => '<path d="M42 24H8M20 11L7 24l13 13"/>',
        'basura' => '<path d="M8 13h32M19 13V7h10v6M12 13l2 28h20l2-28"/>',
        'fuego' => '<path d="M24 44c-8 0-13-6-13-13 0-9 8-12 8-21 6 3 9 8 9 13 2-1 3-3 3-6 5 4 7 9 7 14 0 7-6 13-14 13z"/>',
        'estrella' => '<path d="M24 5l5.9 12 13.1 1.9-9.5 9.2 2.3 13L24 35l-11.8 6.1 2.3-13L5 18.9 18.1 17z"/>',
        'campana' => '<path d="M12 34V22a12 12 0 0 1 24 0v12l4 4H8z"/><path d="M20 42a4 4 0 0 0 8 0"/>',
        'cuenta' => '<path d="M12 4h24v40l-4-3-4 3-4-3-4 3-4-3-4 3z"/><path d="M18 14h12M18 21h12M18 28h7"/>',
        'qr' => '<rect x="6" y="6" width="14" height="14" rx="2"/><rect x="28" y="6" width="14" height="14" rx="2"/><rect x="6" y="28" width="14" height="14" rx="2"/><path d="M28 28h5v5h-5zM37 37h5v5h-5zM37 28h5M28 42h5"/>',
        'cocina' => '<path d="M14 20a8 8 0 1 1 6-13 8 8 0 0 1 14 2 7 7 0 0 1 0 11v4H14z"/><path d="M14 24h20v16H14z"/><path d="M20 30v4M28 30v4"/>',
        'lista' => '<path d="M17 12h25M17 24h25M17 36h25"/><circle cx="8" cy="12" r="2"/><circle cx="8" cy="24" r="2"/><circle cx="8" cy="36" r="2"/>',
        'grafico' => '<path d="M6 42h36"/><path d="M12 36V24M22 36V14M32 36V20M42 36V8"/>',
        'ajustes' => '<circle cx="24" cy="24" r="6"/><path d="M24 4v6M24 38v6M4 24h6M38 24h6M10 10l4 4M34 34l4 4M38 10l-4 4M14 34l-4 4"/>',
        'usuarios' => '<circle cx="18" cy="16" r="7"/><path d="M5 40a13 13 0 0 1 26 0"/><circle cx="34" cy="18" r="5"/><path d="M33 29a10 10 0 0 1 11 11"/>',
        'escudo' => '<path d="M24 4l16 6v12c0 10-7 18-16 22C15 40 8 32 8 22V10z"/><path d="M17 24l5 5 9-10"/>',
        'imprimir' => '<path d="M14 18V6h20v12"/><rect x="6" y="18" width="36" height="16" rx="3"/><path d="M14 28h20v14H14z"/>',
        'dinero' => '<rect x="4" y="12" width="40" height="24" rx="3"/><circle cx="24" cy="24" r="5"/><path d="M10 18v12M38 18v12"/>',
        'banco' => '<path d="M4 18L24 6l20 12z"/><path d="M8 22v14M16 22v14M24 22v14M32 22v14M40 22v14M4 40h40"/>',
        'sonido' => '<path d="M6 18h8l10-8v28l-10-8H6z"/><path d="M31 17a9 9 0 0 1 0 14M36 12a16 16 0 0 1 0 24"/>',
        'copiar' => '<rect x="14" y="14" width="26" height="28" rx="3"/><path d="M8 34V8a2 2 0 0 1 2-2h22"/>',
        'ojo' => '<path d="M3 24s8-14 21-14 21 14 21 14-8 14-21 14S3 24 3 24z"/><circle cx="24" cy="24" r="6"/>',
        'personas' => '<circle cx="16" cy="15" r="6"/><circle cx="32" cy="15" r="6"/><path d="M5 40a11 11 0 0 1 22 0M21 40a11 11 0 0 1 22 0"/>',
    ];
    return '<svg class="' . e($class) . '" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/** Colores de marca por defecto. */
const BRAND_DEFAULTS = ['color_primary' => '#f2643a', 'color_accent' => '#14b3a6', 'color_dark' => '#0b2f3a'];

function valid_hex(string $value): ?string
{
    $value = strtolower(trim($value));
    return preg_match('/^#[0-9a-f]{6}$/', $value) ? $value : null;
}

/** Mezcla dos colores #rrggbb; $amount es la proporción de $b (0 a 1). */
function mix_hex(string $a, string $b, float $amount): string
{
    $out = '#';
    for ($i = 1; $i < 7; $i += 2) {
        $x = hexdec(substr($a, $i, 2));
        $y = hexdec(substr($b, $i, 2));
        $out .= str_pad(dechex((int) round($x + ($y - $x) * $amount)), 2, '0', STR_PAD_LEFT);
    }
    return $out;
}

function brand_colors(): array
{
    $colors = [];
    foreach (BRAND_DEFAULTS as $key => $default) {
        $colors[$key] = valid_hex(setting($key)) ?? $default;
    }
    return $colors;
}

/** <style> con los colores de la marca (los del panel o los originales). */
function brand_style(): string
{
    $c = brand_colors();
    $vars = [
        '--primary' => $c['color_primary'],
        '--primary-600' => mix_hex($c['color_primary'], '#000000', .16),
        '--primary-50' => mix_hex($c['color_primary'], '#ffffff', .9),
        '--accent' => $c['color_accent'],
        '--accent-50' => mix_hex($c['color_accent'], '#ffffff', .88),
        '--dark' => $c['color_dark'],
        '--dark-800' => mix_hex($c['color_dark'], '#ffffff', .1),
        '--dark-700' => mix_hex($c['color_dark'], '#ffffff', .2),
    ];
    $css = '';
    foreach ($vars as $name => $value) {
        $css .= $name . ':' . $value . ';';
    }
    return '<style>:root{' . $css . '}</style>';
}

/** Logo: la imagen subida en el panel o el nombre del restaurante con su ícono. */
function site_logo(bool $light = false): string
{
    $logo = setting($light ? 'logo_dark' : 'logo') ?: setting('logo');
    $name = setting('name');
    if ($logo !== '') {
        return '<img class="logo-img" src="' . e(url($logo)) . '" alt="' . e($name) . '">';
    }
    [$first, $rest] = array_pad(explode(' ', $name, 2), 2, '');
    return '<span class="logo-mark" aria-hidden="true">' . icon('pez') . '</span><span class="logo-text">'
        . ($rest !== '' ? '<small>' . e($first) . '</small>' . e($rest) : e($name)) . '</span>';
}

/**
 * Dirección pública del sitio (para los QR de las mesas). Usa el ajuste site_url si existe;
 * si no, la calcula desde la petición actual (quitando /admin).
 */
function public_base_url(): string
{
    $custom = rtrim(trim(setting('site_url')), '/');
    if ($custom !== '') {
        return $custom . '/';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir = rtrim(str_replace('\\', '/', dirname((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) . 'x')), '/');
    $dir = preg_replace('#/admin$#', '', $dir);
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

/** Horario de hoy y si el restaurante está abierto ahora (según los ajustes open_from / open_to). */
function open_now(): bool
{
    $from = setting('open_from');
    $to = setting('open_to');
    if (!preg_match('/^\d{2}:\d{2}$/', $from) || !preg_match('/^\d{2}:\d{2}$/', $to)) {
        return true;
    }
    $days = array_filter(explode(',', setting('open_days')), 'strlen');
    if ($days && !in_array(date('N'), $days, true)) {
        return false;
    }
    $now = date('H:i');
    return $from <= $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);
}

/** ¿Se reciben pedidos ahora? Interruptor del panel + horario (si «respetar horario» está activo). */
function accepting_orders(): bool
{
    if (!setting_on('orders_enabled')) {
        return false;
    }
    return !setting_on('orders_follow_hours') || open_now();
}
