<?php

declare(strict_types=1);

// Ajustes del restaurante: todo lo que se ve en el menú se edita aquí.
require __DIR__ . '/../../src/admin.php';

$pdo = require_admin('ajustes');

// [tipo, etiqueta, ayuda]. Tipos: text, textarea, check, number, time, money, url, phone
$sections = [
    'restaurante' => ['Restaurante', [
        'name' => ['text', 'Nombre del restaurante', ''],
        'tagline' => ['text', 'Frase corta', 'Aparece sobre el título de la portada. Ej.: Mariscos frescos de Puerto Jelí'],
        'hero_title' => ['text', 'Título de la portada', ''],
        'hero_text' => ['textarea', 'Texto de la portada', ''],
        'address' => ['text', 'Dirección', ''],
        'map_embed' => ['text', 'Mapa: dirección o enlace «Insertar un mapa» de Google Maps', 'Ej.: Puerto Jelí, Santa Rosa, El Oro  o  https://www.google.com/maps/embed?…'],
        'phone' => ['phone', 'Teléfono', 'Se muestra en el menú con botón para llamar.'],
        'schedule_text' => ['text', 'Horario (texto)', 'Ej.: Martes a domingo, 10:00 a 18:00'],
        'instagram' => ['url', 'Instagram', 'https://instagram.com/…'],
        'facebook' => ['url', 'Facebook', 'https://facebook.com/…'],
        'tiktok' => ['url', 'TikTok', 'https://tiktok.com/@…'],
        'demo_notice' => ['check', 'Mostrar el aviso «Demostración» y las herramientas de demo', 'Desmárcalo cuando el restaurante use sus datos reales.'],
    ]],
    'pedidos' => ['Pedidos', [
        'orders_enabled' => ['check', 'Recibir pedidos en línea', 'Apágalo si la cocina está saturada: el menú se sigue viendo.'],
        'mode_mesa' => ['check', 'Pedidos en mesa (QR)', ''],
        'mode_llevar' => ['check', 'Para llevar', ''],
        'mode_domicilio' => ['check', 'A domicilio', ''],
        'orders_follow_hours' => ['check', 'Recibir pedidos solo dentro del horario', ''],
        'open_from' => ['time', 'Abre a las', ''],
        'open_to' => ['time', 'Cierra a las', ''],
        'delivery_fee' => ['money', 'Costo del envío a domicilio (USD)', '0 si es gratis.'],
        'delivery_note' => ['text', 'Nota de entregas', 'Ej.: Entregas en Santa Rosa y Puerto Jelí, unos 40 minutos.'],
        'default_eta' => ['number', 'Tiempo de preparación sugerido (minutos)', 'Botón resaltado en la pantalla de cocina.'],
    ]],
    'pagos' => ['Pagos para llevar y a domicilio', [
        'pay_cash' => ['check', 'Aceptar efectivo', ''],
        'pay_transfer' => ['check', 'Aceptar transferencia', 'El cliente ve estos datos al elegir transferencia.'],
        'bank_name' => ['text', 'Banco', 'Ej.: Banco Pichincha'],
        'bank_type' => ['text', 'Tipo de cuenta', 'Ahorros o corriente'],
        'bank_number' => ['text', 'Número de cuenta', ''],
        'bank_holder' => ['text', 'Titular', ''],
        'bank_id' => ['text', 'RUC o cédula del titular', 'Opcional'],
        'pay_other' => ['textarea', 'Otro medio de pago (opcional)', 'Ej.: DeUna o Payphone: instrucciones. Vacío = no se ofrece.'],
    ]],
    'reservas' => ['Reservas', [
        'reservations_enabled' => ['check', 'Recibir reservas desde el menú', ''],
        'reservation_note' => ['text', 'Mensaje al reservar', 'Ej.: Te confirmamos por llamada o mensaje.'],
        'max_party' => ['number', 'Máximo de personas por reserva', ''],
    ]],
    'whatsapp' => ['WhatsApp (opcional)', [
        'whatsapp' => ['phone', 'Número de WhatsApp del restaurante', 'Solo si quieres que el cliente pueda enviar una copia. Ej.: 593991234567'],
        'whatsapp_orders' => ['check', 'Ofrecer «Enviar copia por WhatsApp» después de pedir', 'Los pedidos llegan igual a Cocina; esto es solo una copia.'],
        'whatsapp_reservations' => ['check', 'Ofrecer «Confirmar por WhatsApp» al reservar', ''],
    ]],
];
$days = ['1' => 'Lun', '2' => 'Mar', '3' => 'Mié', '4' => 'Jue', '5' => 'Vie', '6' => 'Sáb', '7' => 'Dom'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_post();
    $section = (string) ($_POST['section'] ?? '');
    try {
        if (isset($sections[$section])) {
            foreach ($sections[$section][1] as $key => [$type]) {
                $raw = (string) ($_POST['s'][$key] ?? '');
                $value = match ($type) {
                    'check' => isset($_POST['s'][$key]) ? '1' : '0',
                    'number' => (string) max(0, (int) $raw),
                    'money' => number_format(max(0, (float) str_replace(',', '.', $raw)), 2, '.', ''),
                    'time' => preg_match('/^\d{2}:\d{2}$/', $raw) ? $raw : '',
                    'url' => $raw !== '' && !preg_match('#^https?://#i', trim($raw)) ? 'https://' . trim($raw) : trim($raw),
                    'phone' => trim(mb_substr($raw, 0, 40)),
                    default => trim(mb_substr($raw, 0, 600)),
                };
                if ($key === 'map_embed' && preg_match('#src="(https://www\.google\.com/maps/embed[^"]+)"#', $raw, $m)) {
                    $value = html_entity_decode($m[1]); // pegaron el <iframe> completo
                }
                if ($key === 'whatsapp') {
                    $value = preg_replace('/\D/', '', $value);
                }
                if ($key === 'name' && $value === '') {
                    $value = setting('name');
                }
                save_setting($pdo, $key, $value);
            }
            if ($section === 'pedidos') {
                $picked = array_intersect(array_keys($days), array_map('strval', (array) ($_POST['days'] ?? [])));
                save_setting($pdo, 'open_days', implode(',', $picked));
            }
            flash('Ajustes guardados.');
        } elseif ($section === 'marca') {
            foreach (['logo', 'logo_dark', 'hero_image'] as $key) {
                if (isset($_POST['remove_' . $key])) {
                    save_setting($pdo, $key, '');
                } elseif (($path = save_upload('file_' . $key)) !== null) {
                    save_setting($pdo, $key, $path);
                } elseif ($key === 'hero_image' && isset($_POST['hero_url'])) {
                    $url = trim((string) $_POST['hero_url']);
                    save_setting($pdo, $key, preg_match('#^(https?://|uploads/)#', $url) ? $url : '');
                }
            }
            foreach (BRAND_DEFAULTS as $key => $default) {
                $value = isset($_POST['reset_colors']) ? '' : (valid_hex((string) ($_POST[$key] ?? '')) ?? '');
                save_setting($pdo, $key, $value === $default ? '' : $value);
            }
            flash('Logo, colores y portada guardados.');
        } elseif ($section === 'password') {
            $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
            $statement->execute([admin_user()['id']]);
            $new = (string) ($_POST['new_password'] ?? '');
            if (!password_verify((string) ($_POST['current_password'] ?? ''), (string) $statement->fetchColumn())) {
                flash('La contraseña actual no es correcta.', 'error');
            } elseif (mb_strlen($new) < 10) {
                flash('La nueva contraseña debe tener al menos 10 caracteres.', 'error');
            } else {
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), admin_user()['id']]);
                flash('Contraseña actualizada.');
            }
        }
    } catch (RuntimeException $exception) {
        flash($exception->getMessage(), 'error');
    }
    redirect('ajustes.php#' . $section);
}

$openDays = explode(',', setting('open_days'));
$brand = brand_colors();

admin_header('Ajustes', 'ajustes');
?>
<div class="a-head"><h1>Ajustes</h1></div>
<nav class="a-jump" aria-label="Secciones">
  <?php foreach ($sections as $key => [$title]): ?><a href="#<?= $key ?>"><?= e($title) ?></a><?php endforeach; ?>
  <a href="#marca">Logo y colores</a><a href="#password">Contraseña</a>
</nav>

<?php foreach ($sections as $key => [$title, $fields]): ?>
  <form id="<?= $key ?>" class="panel" method="post">
    <h2><?= e($title) ?></h2>
    <?= csrf_field() ?><input type="hidden" name="section" value="<?= $key ?>">
    <div class="a-grid-2">
      <?php foreach ($fields as $field => [$type, $label, $help]): $value = setting($field); ?>
        <?php if ($type === 'check'): ?>
          <label class="a-check a-span-2"><input type="checkbox" name="s[<?= $field ?>]" <?= $value === '1' ? 'checked' : '' ?>> <span><?= e($label) ?><?= $help ? '<small>' . e($help) . '</small>' : '' ?></span></label>
        <?php elseif ($type === 'textarea'): ?>
          <label class="a-span-2"><?= e($label) ?><?= $help ? ' <small>' . e($help) . '</small>' : '' ?><textarea name="s[<?= $field ?>]" rows="3"><?= e($value) ?></textarea></label>
        <?php else: ?>
          <label><?= e($label) ?><?= $help ? ' <small>' . e($help) . '</small>' : '' ?>
            <input name="s[<?= $field ?>]" value="<?= e($value) ?>"<?= ['number' => ' type="number" min="0"', 'time' => ' type="time"', 'money' => ' inputmode="decimal"', 'phone' => ' type="tel"', 'url' => ' inputmode="url"'][$type] ?? '' ?>>
          </label>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if ($key === 'pedidos'): ?>
        <fieldset class="a-days a-span-2"><legend>Días que abre</legend>
          <?php foreach ($days as $n => $label): ?><label class="a-check"><input type="checkbox" name="days[]" value="<?= $n ?>" <?= in_array($n, $openDays, true) ? 'checked' : '' ?>> <?= $label ?></label><?php endforeach; ?>
        </fieldset>
      <?php endif; ?>
    </div>
    <button class="a-btn a-btn-primary" type="submit">Guardar</button>
  </form>
<?php endforeach; ?>

<form id="marca" class="panel" method="post" enctype="multipart/form-data">
  <h2>Logo, colores y foto de portada</h2>
  <?= csrf_field() ?><input type="hidden" name="section" value="marca">
  <div class="a-images">
    <?php foreach (['logo' => 'Logo (para fondo claro)', 'logo_dark' => 'Logo para fondo oscuro (opcional)', 'hero_image' => 'Foto de portada'] as $key => $label): $current = setting($key); ?>
      <fieldset class="a-image">
        <legend><?= e($label) ?></legend>
        <div class="a-logo-preview<?= $key !== 'logo' ? ' is-dark' : '' ?>">
          <img id="prev-<?= $key ?>" src="<?= $current !== '' ? e(image_src($current, 600, 340)) : '' ?>" alt=""<?= $current === '' ? ' hidden' : '' ?>>
        </div>
        <label>Subir <input type="file" name="file_<?= $key ?>" accept="image/png,image/webp,image/jpeg" data-preview="#prev-<?= $key ?>"></label>
        <?php if ($key === 'hero_image'): ?><label>O dirección de la imagen <input name="hero_url" value="<?= e($current) ?>" placeholder="https://…"></label><?php endif; ?>
        <?php if ($current !== ''): ?><label class="a-check"><input type="checkbox" name="remove_<?= $key ?>"> Quitar</label><?php endif; ?>
      </fieldset>
    <?php endforeach; ?>
  </div>
  <p class="hint">Sin logo, se muestra el nombre del restaurante con un pez. Sin foto de portada, se usa el fondo de color con olas.</p>
  <div class="a-colors">
    <label>Color principal <small>botones y detalles</small><input type="color" name="color_primary" value="<?= e($brand['color_primary']) ?>"></label>
    <label>Color de acento <small>estados y resaltados</small><input type="color" name="color_accent" value="<?= e($brand['color_accent']) ?>"></label>
    <label>Color oscuro <small>portada y pie</small><input type="color" name="color_dark" value="<?= e($brand['color_dark']) ?>"></label>
  </div>
  <label class="a-check"><input type="checkbox" name="reset_colors"> Volver a los colores originales</label>
  <button class="a-btn a-btn-primary" type="submit">Guardar</button>
</form>

<form id="password" class="panel" method="post">
  <h2>Cambiar mi contraseña</h2>
  <?= csrf_field() ?><input type="hidden" name="section" value="password">
  <div class="a-grid-2">
    <label>Contraseña actual <input type="password" name="current_password" required autocomplete="current-password"></label>
    <label>Nueva contraseña <input type="password" name="new_password" required minlength="10" autocomplete="new-password"></label>
  </div>
  <button class="a-btn" type="submit">Cambiar contraseña</button>
</form>
<?php admin_footer(); ?>
