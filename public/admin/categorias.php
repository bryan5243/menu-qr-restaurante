<?php

declare(strict_types=1);

require __DIR__ . '/../../src/admin.php';

$pdo = require_admin('platos');
$kinds = ['entradas' => 'Entradas', 'ceviches' => 'Ceviches / mariscos fríos', 'platos' => 'Platos fuertes', 'bebidas' => 'Bebidas', 'postres' => 'Postres'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_post();
    foreach ((array) ($_POST['cat'] ?? []) as $cid => $row) {
        $cid = (int) $cid;
        if (isset($row['delete'])) {
            $count = $pdo->prepare('SELECT COUNT(*) FROM dishes WHERE category_id = ?');
            $count->execute([$cid]);
            if ((int) $count->fetchColumn() > 0) {
                flash('No se puede borrar una categoría que tiene platos. Muévelos u ocúltala.', 'error');
                redirect('categorias.php');
            }
            $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$cid]);
            continue;
        }
        $name = trim(mb_substr((string) ($row['name'] ?? ''), 0, 120));
        if ($name === '') {
            continue;
        }
        $pdo->prepare('UPDATE categories SET name = ?, description = ?, kind = ?, sort_order = ?, active = ? WHERE id = ?')
            ->execute([$name, trim(mb_substr((string) ($row['description'] ?? ''), 0, 255)), isset($kinds[$row['kind'] ?? '']) ? $row['kind'] : 'platos', (int) ($row['sort_order'] ?? 0), isset($row['active']) ? 1 : 0, $cid]);
    }
    $new = $_POST['new'] ?? [];
    $name = trim(mb_substr((string) ($new['name'] ?? ''), 0, 120));
    if ($name !== '') {
        $slug = slugify($name);
        $exists = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE slug = ?');
        $exists->execute([$slug]);
        if ((int) $exists->fetchColumn() > 0) {
            $slug .= '-' . bin2hex(random_bytes(2));
        }
        $pdo->prepare('INSERT INTO categories (slug, name, description, kind, sort_order) VALUES (?, ?, ?, ?, 99)')
            ->execute([$slug, $name, trim(mb_substr((string) ($new['description'] ?? ''), 0, 255)), isset($kinds[$new['kind'] ?? '']) ? $new['kind'] : 'platos']);
    }
    flash('Categorías guardadas.');
    redirect('categorias.php');
}

$categories = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM dishes d WHERE d.category_id = c.id) AS n FROM categories c ORDER BY sort_order, id')->fetchAll();
$kindSelect = static function (string $name, string $current) use ($kinds): string {
    $html = '<select name="' . $name . '" aria-label="Dibujo de respaldo">';
    foreach ($kinds as $k => $label) {
        $html .= '<option value="' . $k . '"' . ($k === $current ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html . '</select>';
};

admin_header('Categorías', 'platos');
?>
<div class="a-head"><h1>Categorías del menú</h1><a href="platos.php"><?= icon('atras') ?> Volver al menú</a></div>
<p class="hint">El orden define cómo aparecen en el menú. El «tipo» elige el dibujo que se muestra cuando un plato no tiene foto.</p>
<form class="panel" method="post">
  <?= csrf_field() ?>
  <table class="a-table a-table-edit">
    <thead><tr><th>Nombre</th><th>Descripción</th><th>Tipo</th><th>Orden</th><th>Visible</th><th>Borrar</th></tr></thead>
    <tbody>
      <?php foreach ($categories as $c): $cid = (int) $c['id']; ?>
        <tr>
          <td data-label="Nombre"><input name="cat[<?= $cid ?>][name]" value="<?= e($c['name']) ?>" aria-label="Nombre"><small><?= (int) $c['n'] ?> platos</small></td>
          <td data-label="Descripción"><input name="cat[<?= $cid ?>][description]" value="<?= e($c['description']) ?>" aria-label="Descripción"></td>
          <td data-label="Tipo"><?= $kindSelect("cat[$cid][kind]", $c['kind']) ?></td>
          <td data-label="Orden"><input type="number" class="a-num" name="cat[<?= $cid ?>][sort_order]" value="<?= (int) $c['sort_order'] ?>" aria-label="Orden"></td>
          <td data-label="Visible"><input type="checkbox" name="cat[<?= $cid ?>][active]" <?= (int) $c['active'] ? 'checked' : '' ?> aria-label="Visible"></td>
          <td data-label="Borrar"><?php if ((int) $c['n'] === 0): ?><input type="checkbox" name="cat[<?= $cid ?>][delete]" aria-label="Borrar"><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <tr class="a-new">
        <td data-label="Nueva"><input name="new[name]" placeholder="Nueva categoría" aria-label="Nombre de la nueva categoría"></td>
        <td data-label="Descripción"><input name="new[description]" placeholder="Opcional" aria-label="Descripción nueva"></td>
        <td data-label="Tipo"><?= $kindSelect('new[kind]', 'platos') ?></td>
        <td></td><td></td><td></td>
      </tr>
    </tbody>
  </table>
  <button class="a-btn a-btn-primary" type="submit">Guardar categorías</button>
</form>
<?php admin_footer(); ?>
