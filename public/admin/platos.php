<?php

declare(strict_types=1);

// Menú: lista de platos por categoría, con interruptores rápidos de «agotado» y «visible».
require __DIR__ . '/../../src/admin.php';

$pdo = require_admin('platos');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_post();
    $id = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $fields = ['available' => 'available', 'active' => 'active', 'featured' => 'featured'];
    if (isset($fields[$action])) {
        $pdo->prepare("UPDATE dishes SET {$fields[$action]} = 1 - {$fields[$action]} WHERE id = ?")->execute([$id]);
    } elseif ($action === 'price') {
        $price = round(max(0, (float) str_replace(',', '.', (string) ($_POST['price'] ?? '0'))), 2);
        $pdo->prepare('UPDATE dishes SET price = ? WHERE id = ?')->execute([$price, $id]);
        flash('Precio actualizado.');
    } elseif ($action === 'all_available') {
        $pdo->exec('UPDATE dishes SET available = 1');
        flash('Todos los platos están disponibles otra vez.');
    }
    if (($_POST['ajax'] ?? '') === '1') {
        json_out(['ok' => true]);
    }
    redirect('platos.php#plato-' . $id);
}

$categories = menu_categories(true);
$soldOut = 0;
foreach ($categories as $c) {
    foreach ($c['dishes'] as $d) {
        $soldOut += $d['active'] && !$d['available'] ? 1 : 0;
    }
}

admin_header('Menú', 'platos');
?>
<div class="a-head">
  <div><h1>Menú</h1><p class="hint">Toca <b>Agotado</b> cuando se termine un plato: desaparece el botón de pedir al instante.</p></div>
  <div class="a-row">
    <a class="a-btn" href="categorias.php"><?= icon('lista') ?> Categorías</a>
    <a class="a-btn a-btn-primary" href="plato.php"><?= icon('mas') ?> Nuevo plato</a>
  </div>
</div>
<?php if ($soldOut): ?>
  <form method="post" class="a-flash a-flash-warn a-row"><?= csrf_field() ?><input type="hidden" name="action" value="all_available"><span><?= $soldOut ?> <?= $soldOut === 1 ? 'plato agotado' : 'platos agotados' ?>.</span><button class="a-btn a-btn-sm" type="submit">Marcar todo disponible (nuevo día)</button></form>
<?php endif; ?>

<?php foreach ($categories as $category): ?>
  <section class="panel panel-flush">
    <div class="a-head pad"><h2><?= e($category['name']) ?> <small><?= count($category['dishes']) ?> platos<?= $category['active'] ? '' : ' · categoría oculta' ?></small></h2></div>
    <?php if (!$category['dishes']): ?><p class="hint pad">Sin platos.</p><?php endif; ?>
    <ul class="a-dishes">
      <?php foreach ($category['dishes'] as $dish): ?>
        <li class="a-dish<?= $dish['active'] ? '' : ' is-hidden' ?><?= $dish['available'] ? '' : ' is-sold' ?>" id="plato-<?= $dish['id'] ?>">
          <img src="<?= e(image_src($dish['image'], 120, 120) ?: fallback_art($category['kind'])) ?>" alt="" width="60" height="60" loading="lazy" onerror="this.onerror=null;this.src='<?= e(fallback_art($category['kind'])) ?>'">
          <div class="a-dish-main">
            <a href="plato.php?id=<?= $dish['id'] ?>"><b><?= e($dish['name']) ?></b></a>
            <small><?= $dish['badge'] !== '' ? e($dish['badge']) . ' · ' : '' ?><?= $dish['featured'] ? '★ Favorito · ' : '' ?><?php $o = dish_options($dish); ?><?= $o['remove'] || $o['extras'] ? 'Con opciones · ' : '' ?><?= $dish['active'] ? 'Visible' : 'Oculto' ?></small>
          </div>
          <form method="post" class="a-price" data-ajax><?= csrf_field() ?><input type="hidden" name="id" value="<?= $dish['id'] ?>"><input type="hidden" name="action" value="price">
            <label class="sr-only" for="p<?= $dish['id'] ?>">Precio</label><span>$</span><input id="p<?= $dish['id'] ?>" name="price" value="<?= number_format($dish['price'], 2, '.', '') ?>" inputmode="decimal">
          </form>
          <form method="post" data-ajax data-toggle><?= csrf_field() ?><input type="hidden" name="id" value="<?= $dish['id'] ?>"><input type="hidden" name="action" value="available">
            <button type="submit" class="a-switch<?= $dish['available'] ? ' is-on' : '' ?>" aria-pressed="<?= $dish['available'] ? 'true' : 'false' ?>" data-on="Disponible" data-off="Agotado"><i></i><span><?= $dish['available'] ? 'Disponible' : 'Agotado' ?></span></button>
          </form>
          <a class="a-btn a-btn-sm" href="plato.php?id=<?= $dish['id'] ?>">Editar</a>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endforeach; ?>
<script>
// Interruptores y precios sin recargar la página.
document.querySelectorAll('form[data-ajax]').forEach(f => {
  const send = () => { const d = new FormData(f); d.append('ajax', '1'); return fetch('platos.php', { method: 'POST', body: d }); };
  if (f.hasAttribute('data-toggle')) {
    f.addEventListener('submit', async e => {
      e.preventDefault();
      const b = f.querySelector('button'); const on = !b.classList.contains('is-on');
      b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on); b.querySelector('span').textContent = on ? b.dataset.on : b.dataset.off;
      f.closest('li').classList.toggle('is-sold', !on);
      try { await send(); } catch { location.reload(); }
    });
  } else {
    const input = f.querySelector('input[name=price]');
    let last = input.value;
    const save = async () => { if (input.value === last) return; last = input.value; input.classList.add('is-saving'); try { await send(); input.classList.remove('is-saving'); input.classList.add('is-saved'); setTimeout(() => input.classList.remove('is-saved'), 1200); } catch { location.reload(); } };
    input.addEventListener('change', save);
    f.addEventListener('submit', e => { e.preventDefault(); save(); });
  }
});
</script>
<?php admin_footer(); ?>
