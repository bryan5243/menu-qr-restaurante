<?php

declare(strict_types=1);

// Crear o editar un plato.
require __DIR__ . '/../../src/admin.php';
require_once __DIR__ . '/../../src/menu.php';

$pdo = require_admin('platos');
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$dish = ['id' => 0, 'category_id' => 0, 'name' => '', 'description' => '', 'price' => '', 'image' => '', 'badge' => '', 'available' => 1, 'featured' => 0, 'active' => 1, 'sort_order' => 0, 'options' => ''];
if ($id) {
    $statement = $pdo->prepare('SELECT * FROM dishes WHERE id = ?');
    $statement->execute([$id]);
    $dish = $statement->fetch() ?: redirect('platos.php');
}
$categories = $pdo->query('SELECT id, name, kind FROM categories ORDER BY sort_order, id')->fetchAll();
if (!$categories) {
    flash('Primero crea una categoría.', 'error');
    redirect('categorias.php');
}
$error = null;

/** Filas de opciones que llegan del formulario: tipo, nombre y precio. */
function posted_options(): array
{
    $out = [];
    $types = (array) ($_POST['opt_type'] ?? []);
    $names = (array) ($_POST['opt_name'] ?? []);
    $prices = (array) ($_POST['opt_price'] ?? []);
    foreach ($names as $i => $name) {
        $name = trim(str_replace(['|', "\n", "\r"], ' ', (string) $name));
        if ($name === '') {
            continue;
        }
        $type = ($types[$i] ?? '') === 'sin' ? 'sin' : 'extra';
        $line = ($type === 'sin' ? '- ' : '+ ') . $name . ($type === 'extra' ? ' | ' . str_replace(',', '.', (string) ($prices[$i] ?? '0')) : '');
        $out[] = $line;
    }
    return dish_options(implode("\n", $out));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_post();
    if (isset($_POST['delete']) && $id) {
        $pdo->prepare('DELETE FROM dishes WHERE id = ?')->execute([$id]);
        flash('Plato eliminado. Los pedidos anteriores conservan su nombre y precio.');
        redirect('platos.php');
    }
    $dish = array_merge($dish, [
        'category_id' => (int) ($_POST['category_id'] ?? 0),
        'name' => trim(mb_substr((string) ($_POST['name'] ?? ''), 0, 160)),
        'description' => trim(mb_substr((string) ($_POST['description'] ?? ''), 0, 500)),
        'price' => round(max(0, (float) str_replace(',', '.', (string) ($_POST['price'] ?? '0'))), 2),
        'badge' => trim(mb_substr((string) ($_POST['badge'] ?? ''), 0, 40)),
        'available' => isset($_POST['available']) ? 1 : 0,
        'featured' => isset($_POST['featured']) ? 1 : 0,
        'active' => isset($_POST['active']) ? 1 : 0,
        'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        'options' => dish_options_text(posted_options()),
        'image' => isset($_POST['remove_image']) ? '' : trim((string) ($_POST['image_url'] ?? $dish['image'])),
    ]);
    try {
        $dish['image'] = save_upload('photo') ?? $dish['image'];
        if ($dish['name'] === '' || !in_array($dish['category_id'], array_map('intval', array_column($categories, 'id')), true)) {
            throw new RuntimeException('Escribe el nombre y elige la categoría.');
        }
        $values = [$dish['category_id'], $dish['name'], $dish['description'], $dish['price'], $dish['image'], $dish['badge'], $dish['available'], $dish['featured'], $dish['active'], $dish['sort_order'], $dish['options']];
        if ($id) {
            $pdo->prepare('UPDATE dishes SET category_id = ?, name = ?, description = ?, price = ?, image = ?, badge = ?, available = ?, featured = ?, active = ?, sort_order = ?, options = ? WHERE id = ?')->execute([...$values, $id]);
        } else {
            $pdo->prepare('INSERT INTO dishes (category_id, name, description, price, image, badge, available, featured, active, sort_order, options, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$values, now()]);
            $id = insert_id($pdo, 'dishes');
        }
        flash('Plato guardado.');
        redirect('platos.php#plato-' . $id);
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}

$kind = 'platos';
foreach ($categories as $c) {
    if ((int) $c['id'] === (int) $dish['category_id']) {
        $kind = $c['kind'];
    }
}
admin_header($id ? 'Editar plato' : 'Nuevo plato', 'platos');
?>
<div class="a-head"><h1><?= $id ? 'Editar plato' : 'Nuevo plato' ?></h1><a href="platos.php"><?= icon('atras') ?> Volver al menú</a></div>
<?php if ($error): ?><p class="a-flash a-flash-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<form class="a-form-grid" method="post" enctype="multipart/form-data">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $id ?>">
  <section class="panel">
    <label>Nombre <input name="name" value="<?= e($dish['name']) ?>" required maxlength="160" placeholder="Ej.: Ceviche de camarón"></label>
    <label>Descripción <small>qué lleva y con qué se sirve</small><textarea name="description" rows="3" maxlength="500"><?= e($dish['description']) ?></textarea></label>
    <div class="a-grid-2">
      <label>Categoría
        <select name="category_id"><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === (int) $dish['category_id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
      </label>
      <label>Precio (USD) <input name="price" value="<?= e($dish['price'] === '' ? '' : number_format((float) $dish['price'], 2, '.', '')) ?>" inputmode="decimal" required placeholder="8.00"></label>
      <label>Etiqueta <small>opcional: Nuevo, De la casa, Picante…</small><input name="badge" value="<?= e($dish['badge']) ?>" maxlength="40"></label>
      <label>Orden <small>los números menores van primero</small><input type="number" name="sort_order" value="<?= (int) $dish['sort_order'] ?>"></label>
    </div>
    <label class="a-check"><input type="checkbox" name="available" <?= (int) $dish['available'] ? 'checked' : '' ?>> Disponible hoy (desmárcalo si se agotó)</label>
    <label class="a-check"><input type="checkbox" name="featured" <?= (int) $dish['featured'] ? 'checked' : '' ?>> Mostrar en «Los favoritos de la casa»</label>
    <label class="a-check"><input type="checkbox" name="active" <?= (int) $dish['active'] ? 'checked' : '' ?>> Visible en el menú</label>
  </section>
  <section class="panel a-options">
    <h2>Adicionales y «sin…»</h2>
    <p class="hint">Lo que el cliente puede quitar (gratis), como <i>Sin menestra</i> o <i>Sin chimichurri</i>, y lo que puede agregar con su precio, como <i>Patacones extra $1.50</i>. Pon $0 si el cambio no cuesta. Deja vacío lo que no uses.</p>
    <div class="a-opt-list" data-opt-list>
      <?php $rows = dish_options((string) $dish['options']); for ($i = 0, $n = max(count($rows) + 2, 4); $i < min($n, DISH_OPTIONS_MAX); $i++): $o = $rows[$i] ?? ['type' => 'extra', 'name' => '', 'price' => 0]; ?>
        <div class="a-opt" data-opt>
          <select name="opt_type[]" aria-label="Tipo"><option value="sin"<?= $o['type'] === 'sin' ? ' selected' : '' ?>>Quitar</option><option value="extra"<?= $o['type'] === 'extra' ? ' selected' : '' ?>>Adicional</option></select>
          <input name="opt_name[]" value="<?= e($o['name']) ?>" maxlength="60" placeholder="Ej.: Sin menestra o Patacones extra" aria-label="Nombre">
          <label class="a-opt-price"<?= $o['type'] === 'sin' ? ' hidden' : '' ?>>$ <input name="opt_price[]" value="<?= $o['price'] > 0 ? e(number_format($o['price'], 2, '.', '')) : '' ?>" inputmode="decimal" placeholder="0.00" aria-label="Precio"></label>
        </div>
      <?php endfor; ?>
    </div>
    <button class="a-btn a-btn-sm" type="button" data-opt-add>+ Otra fila</button>
  </section>
  <section class="panel">
    <h2>Foto</h2>
    <div class="a-photo"><img id="prev" src="<?= e(image_src((string) $dish['image'], 480, 360) ?: fallback_art($kind)) ?>" alt="" onerror="this.onerror=null;this.src='<?= e(fallback_art($kind)) ?>'"></div>
    <label>Subir foto <small>JPG, PNG o WEBP de hasta 4 MB. Mejor horizontal y con buena luz.</small><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" data-preview="#prev"></label>
    <label>O pega la dirección de una imagen <input name="image_url" value="<?= e((string) $dish['image']) ?>" placeholder="https://…"></label>
    <?php if ($dish['image'] !== ''): ?><label class="a-check"><input type="checkbox" name="remove_image"> Quitar la foto (se usa el dibujo de la categoría)</label><?php endif; ?>
  </section>
  <div class="a-actions">
    <button class="a-btn a-btn-primary" type="submit">Guardar plato</button>
    <?php if ($id): ?><button class="a-btn a-btn-danger" type="submit" name="delete" value="1" formnovalidate onclick="return confirm('¿Eliminar este plato?')">Eliminar</button><?php endif; ?>
  </div>
</form>
<?php admin_footer(); ?>
