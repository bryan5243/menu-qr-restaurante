<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/menu.php';
require __DIR__ . '/../src/orders.php';
require __DIR__ . '/../src/layout.php';

start_session();

if (db() === null) {
    http_response_code(503);
    exit('El menú no está disponible en este momento: no se pudo conectar a la base de datos. Revisa config/config.php.');
}

$categories = menu_categories();
$tables = max(1, (int) setting('tables'));
$mesa = (int) ($_GET['mesa'] ?? 0);
$mesa = setting_on('mode_mesa') && $mesa >= 1 && $mesa <= $tables ? $mesa : 0;
$featured = [];
foreach ($categories as $category) {
    foreach ($category['dishes'] as $dish) {
        if ($dish['featured'] === 1) {
            $featured[] = [$dish, $category['kind']];
        }
    }
}
$open = open_now();
$accepting = accepting_orders();
$kinds = enabled_kinds();
$payments = enabled_payments();
$hero = setting('hero_image');

// Configuración que usa el carrito en el navegador.
$appConfig = [
    'csrf' => csrf_token(),
    'mesa' => $mesa,
    'tables' => $tables,
    'kinds' => $kinds,
    'payments' => $payments,
    'fee' => (float) setting('delivery_fee'),
    'accepting' => $accepting,
    'closedText' => setting_on('orders_enabled') ? 'Ahora estamos cerrados. Horario: ' . setting('schedule_text') : 'Por ahora no estamos recibiendo pedidos en línea.',
    'store' => 'menu:' . substr(md5(setting('name')), 0, 6),
    'whatsapp' => setting_on('whatsapp_orders') ? whatsapp_number() : '',
    'name' => setting('name'),
];

page_header('', 'page-menu' . ($mesa ? ' is-table' : ''));
?>
<header class="top" data-top>
  <div class="wrap top-in">
    <a class="logo" href="<?= url('./') . ($mesa ? '?mesa=' . $mesa : '') ?>"><?= site_logo() ?></a>
    <div class="top-actions">
      <?php if ($mesa): ?><span class="chip chip-table"><?= icon('mesa') ?> Mesa <?= $mesa ?></span><?php endif; ?>
      <?php if (setting_on('reservations_enabled')): ?><a class="btn btn-ghost btn-sm" href="<?= url('reservar.php') ?>"><?= icon('calendario') ?><span>Reservar</span></a><?php endif; ?>
    </div>
  </div>
</header>

<section class="hero<?= $hero !== '' ? ' has-photo' : '' ?>">
  <?php if ($hero !== ''): ?>
    <img class="hero-bg" src="<?= e(image_src($hero, 1600, 1000)) ?>" alt="" fetchpriority="high" onerror="this.remove()">
  <?php endif; ?>
  <div class="hero-waves" aria-hidden="true"><i></i><i></i><i></i></div>
  <div class="wrap hero-in">
    <p class="hero-kicker reveal"><?= icon('ubicacion') ?> <?= e(setting('tagline')) ?></p>
    <h1 class="reveal"><?= e(setting('hero_title')) ?></h1>
    <p class="hero-text reveal"><?= e(setting('hero_text')) ?></p>
    <div class="hero-chips reveal">
      <span class="chip <?= $open ? 'chip-open' : 'chip-closed' ?>"><i class="dot"></i><?= $open ? 'Abierto ahora' : 'Cerrado ahora' ?></span>
      <?php if (setting('schedule_text') !== ''): ?><span class="chip chip-glass"><?= icon('reloj') ?> <?= e(setting('schedule_text')) ?></span><?php endif; ?>
    </div>
    <?php if ($mesa): ?>
      <div class="hero-table reveal">
        <span class="hero-table-num"><small>Mesa</small><?= $mesa ?></span>
        <p><b>Pide desde tu celular.</b> Tu pedido llega directo a la cocina y aquí verás cuándo está listo.</p>
      </div>
    <?php else: ?>
      <div class="hero-cta reveal">
        <a class="btn btn-primary" href="#menu"><?= icon('plato') ?> Ver el menú</a>
        <?php if (setting_on('reservations_enabled')): ?><a class="btn btn-light" href="<?= url('reservar.php') ?>"><?= icon('calendario') ?> Reservar mesa</a><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php if (setting_on('demo_notice')): ?>
  <p class="demo-ribbon"><b>Demostración:</b> restaurante, platos y precios de ejemplo.</p>
<?php endif; ?>

<div class="wrap">
  <a class="active-order" href="#" data-active-order hidden>
    <span class="pulse" aria-hidden="true"></span>
    <span><b data-ao-title>Tu pedido</b><small data-ao-text>Toca para ver cómo va</small></span>
    <?= icon('flecha') ?>
  </a>
</div>

<nav class="cats" aria-label="Categorías del menú" data-cats>
  <div class="wrap cats-in">
    <?php if ($featured): ?><a href="#favoritos" class="cat-pill"><?= icon('estrella') ?> Favoritos</a><?php endif; ?>
    <?php foreach ($categories as $category): ?>
      <a href="#<?= e($category['slug']) ?>" class="cat-pill"><?= e($category['name']) ?></a>
    <?php endforeach; ?>
  </div>
</nav>

<main id="menu" class="menu">
  <?php if ($featured): ?>
    <section class="wrap menu-sec favs" id="favoritos" data-section>
      <header class="sec-head">
        <p class="sec-kicker"><?= icon('estrella') ?> Recomendados</p>
        <h2>Los favoritos de la casa</h2>
        <p>Lo que más piden nuestros clientes.</p>
      </header>
      <div class="feat-grid">
        <?php foreach ($featured as $i => [$dish, $kind]): ?>
          <article class="feat reveal<?= $dish['available'] ? '' : ' is-sold' ?>" style="--i:<?= $i ?>" data-dish='<?= dish_payload($dish, $kind) ?>'>
            <button class="feat-open" type="button" data-open aria-label="Ver <?= e($dish['name']) ?>">
              <span class="feat-img"><?= dish_img($dish['image'], $dish['name'], 480, 360, $kind) ?></span>
              <?php if ($dish['badge'] !== ''): ?><span class="badge"><?= e($dish['badge']) ?></span><?php endif; ?>
              <span class="feat-body">
                <span class="feat-name"><?= e($dish['name']) ?></span>
                <span class="feat-desc"><?= e($dish['description']) ?></span>
                <b class="price"><?= e(money($dish['price'])) ?></b>
              </span>
            </button>
            <?php if ($dish['available']): ?>
              <button class="add add-feat" type="button" data-add aria-label="Agregar <?= e($dish['name']) ?>"><?= icon('mas') ?></button>
            <?php else: ?><span class="sold sold-feat">Agotado</span><?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <div class="wrap">
    <div class="carta">
      <p class="carta-brand"><span><?= e(setting('name')) ?></span></p>
      <?php foreach ($categories as $ci => $category): ?>
        <section class="carta-sec" id="<?= e($category['slug']) ?>" data-section>
          <header class="carta-head">
            <span class="carta-num" aria-hidden="true"><?= str_pad((string) ($ci + 1), 2, '0', STR_PAD_LEFT) ?></span>
            <h2><?= e($category['name']) ?></h2>
            <?php if ($category['description'] !== ''): ?><p><?= e($category['description']) ?></p><?php endif; ?>
          </header>
          <div class="carta-list">
            <?php foreach ($category['dishes'] as $dish): $hasOpts = dish_options($dish['options']) !== []; ?>
              <article class="dish reveal<?= $dish['available'] ? '' : ' is-sold' ?>" data-dish='<?= dish_payload($dish, $category['kind']) ?>'>
                <button class="dish-open" type="button" data-open>
                  <span class="dish-img"><?= dish_img($dish['image'], $dish['name'], 120, 120, $category['kind']) ?></span>
                  <span class="dish-text">
                    <span class="dish-line"><span class="dish-name"><?= e($dish['name']) ?></span><span class="dish-dots" aria-hidden="true"></span><b class="price"><?= e(money($dish['price'])) ?></b></span>
                    <?php if ($dish['description'] !== ''): ?><span class="dish-desc"><?= e($dish['description']) ?></span><?php endif; ?>
                    <?php if ($dish['badge'] !== '' || $hasOpts || !$dish['available']): ?>
                      <span class="dish-tags">
                        <?php if (!$dish['available']): ?><span class="sold">Agotado hoy</span><?php endif; ?>
                        <?php if ($dish['badge'] !== ''): ?><span class="badge badge-soft"><?= e($dish['badge']) ?></span><?php endif; ?>
                        <?php if ($hasOpts && $dish['available']): ?><span class="tag-opts"><?= icon('opciones') ?> Personalízalo</span><?php endif; ?>
                      </span>
                    <?php endif; ?>
                  </span>
                </button>
                <?php if ($dish['available']): ?>
                  <button class="add add-row" type="button" data-add aria-label="Agregar <?= e($dish['name']) ?>"><?= icon('mas') ?></button>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
      <p class="carta-end" aria-hidden="true">~ ✦ ~</p>
    </div>
  </div>

  <section class="wrap info" id="visitanos">
    <div class="info-card reveal">
      <h2>Visítanos</h2>
      <ul class="info-list">
        <li><?= icon('ubicacion') ?><span><?= e(setting('address')) ?><br><a href="<?= e(map_directions_url()) ?>" target="_blank" rel="noopener">Cómo llegar</a></span></li>
        <?php if (setting('schedule_text') !== ''): ?><li><?= icon('reloj') ?><span><?= e(setting('schedule_text')) ?></span></li><?php endif; ?>
        <?php if (setting('phone') !== ''): ?><li><?= icon('telefono') ?><span><a href="tel:<?= e(preg_replace('/[^\d+]/', '', setting('phone'))) ?>"><?= e(setting('phone')) ?></a></span></li><?php endif; ?>
        <?php if (setting_on('mode_domicilio') && setting('delivery_note') !== ''): ?><li><?= icon('moto') ?><span><?= e(setting('delivery_note')) ?></span></li><?php endif; ?>
      </ul>
      <div class="info-actions">
        <?php if (setting_on('reservations_enabled')): ?><a class="btn btn-primary" href="<?= url('reservar.php') ?>"><?= icon('calendario') ?> Reservar mesa</a><?php endif; ?>
        <?= social_links() ?>
      </div>
    </div>
    <?php if (map_embed_src() !== ''): ?>
      <div class="info-map reveal" data-map="<?= e(map_embed_src()) ?>"><span>Cargando mapa…</span></div>
    <?php endif; ?>
  </section>
</main>

<button class="cart-bar" type="button" data-cart-bar hidden>
  <span class="cart-count" data-cart-count>0</span>
  <span class="cart-bar-text">Ver mi pedido</span>
  <b data-cart-total>$0.00</b>
</button>

<div class="sheet" id="dish-sheet" role="dialog" aria-modal="true" aria-labelledby="ds-name" hidden>
  <div class="sheet-backdrop" data-close></div>
  <div class="sheet-panel sheet-dish">
    <button class="sheet-x" type="button" data-close aria-label="Cerrar"><?= icon('cerrar') ?></button>
    <div class="ds-img"><img alt="" data-ds-img></div>
    <div class="ds-body">
      <span class="badge" data-ds-badge hidden></span>
      <h2 id="ds-name" data-ds-name></h2>
      <p data-ds-desc></p>
      <div class="ds-opts" data-ds-opts hidden>
        <fieldset class="ds-group" data-ds-group="sin"><legend>¿Le quitamos algo? <small>Gratis</small></legend><div class="ds-choices"></div></fieldset>
        <fieldset class="ds-group" data-ds-group="extra"><legend>Adicionales <small>Opcional</small></legend><div class="ds-choices"></div></fieldset>
      </div>
      <label class="field">Indicaciones para cocina (opcional)
        <input type="text" maxlength="120" placeholder="Ej.: bien picante, término medio" data-ds-note>
      </label>
      <div class="ds-foot">
        <div class="qty" data-ds-qty>
          <button type="button" data-qty="-1" aria-label="Quitar uno"><?= icon('menos') ?></button>
          <output>1</output>
          <button type="button" data-qty="1" aria-label="Agregar uno"><?= icon('mas') ?></button>
        </div>
        <button class="btn btn-primary btn-grow" type="button" data-ds-add>Agregar <b data-ds-price></b></button>
      </div>
      <p class="sold-note" data-ds-sold hidden>Este plato está agotado por hoy.</p>
    </div>
  </div>
</div>

<div class="sheet" id="cart-sheet" role="dialog" aria-modal="true" aria-labelledby="cart-title" hidden>
  <div class="sheet-backdrop" data-close></div>
  <form class="sheet-panel sheet-cart" data-cart-form novalidate>
    <header class="cart-head">
      <h2 id="cart-title">Tu pedido</h2>
      <button class="sheet-x" type="button" data-close aria-label="Cerrar"><?= icon('cerrar') ?></button>
    </header>
    <div class="cart-scroll">
      <p class="cart-adding" data-cart-adding hidden><?= icon('check') ?> <span></span></p>
      <ul class="cart-items" data-cart-items></ul>
      <p class="cart-empty" data-cart-empty>Todavía no agregas platos.</p>

      <fieldset class="cart-mode" data-cart-mode>
        <legend>¿Cómo lo quieres?</legend>
        <div class="seg">
          <?php foreach ($kinds as $kind => $label): ?>
            <label class="seg-opt"><input type="radio" name="kind" value="<?= $kind ?>"><span><?= icon(['mesa' => 'mesa', 'llevar' => 'bolsa', 'domicilio' => 'moto'][$kind]) ?><?= e($label) ?></span></label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <div class="cart-fields">
        <div data-for="mesa">
          <p class="mesa-fixed" data-mesa-fixed hidden><?= icon('mesa') ?> <span>Mesa <b></b>: te lo llevamos a la mesa.</span></p>
          <label class="field" data-mesa-pick>Número de mesa
            <select name="table">
              <option value="">Elige tu mesa</option>
              <?php for ($t = 1; $t <= $tables; $t++): ?><option value="<?= $t ?>">Mesa <?= $t ?></option><?php endfor; ?>
            </select>
          </label>
        </div>
        <div data-for="llevar domicilio">
          <div class="grid-2">
            <label class="field">Tu nombre <input name="name" autocomplete="name" maxlength="120"></label>
            <label class="field">Celular <input name="phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="20" placeholder="09…"></label>
          </div>
        </div>
        <div data-for="domicilio">
          <label class="field">Dirección y referencia <textarea name="address" rows="2" maxlength="255" placeholder="Calle, número y una referencia (ej.: frente al parque)"></textarea></label>
          <?php if (setting('delivery_note') !== ''): ?><p class="hint"><?= icon('moto') ?> <?= e(setting('delivery_note')) ?></p><?php endif; ?>
        </div>
        <fieldset class="pay" data-for="llevar domicilio">
          <legend>¿Cómo pagas?</legend>
          <div class="seg">
            <?php foreach ($payments as $key => $label): ?>
              <label class="seg-opt"><input type="radio" name="payment" value="<?= $key ?>"><span><?= icon(['efectivo' => 'dinero', 'transferencia' => 'banco', 'otro' => 'dinero'][$key]) ?><?= e($label) ?></span></label>
            <?php endforeach; ?>
          </div>
          <?php if (isset($payments['transferencia'])): ?>
            <div class="bank" data-pay="transferencia" hidden>
              <p class="bank-title"><?= icon('banco') ?> Datos para la transferencia</p>
              <dl>
                <dt>Banco</dt><dd><?= e(setting('bank_name')) ?></dd>
                <dt>Tipo</dt><dd><?= e(setting('bank_type')) ?></dd>
                <dt>Número</dt><dd><b data-copy-src><?= e(setting('bank_number')) ?></b> <button type="button" class="copy" data-copy><?= icon('copiar') ?> Copiar</button></dd>
                <dt>Titular</dt><dd><?= e(setting('bank_holder')) ?></dd>
                <?php if (setting('bank_id') !== ''): ?><dt>RUC / C.I.</dt><dd><?= e(setting('bank_id')) ?></dd><?php endif; ?>
              </dl>
              <p class="hint">Muestra el comprobante al recibir tu pedido.</p>
            </div>
          <?php endif; ?>
          <?php if (isset($payments['otro'])): ?>
            <div class="bank" data-pay="otro" hidden><p><?= nl2br(e(setting('pay_other'))) ?></p></div>
          <?php endif; ?>
        </fieldset>
        <label class="field">Comentarios (opcional) <input name="notes" maxlength="300" placeholder="Ej.: traer cubiertos para niños"></label>
      </div>
    </div>
    <footer class="cart-foot">
      <p class="cart-line" data-fee-line hidden><span>Envío</span><span data-fee></span></p>
      <p class="cart-line cart-total"><span>Total</span><b data-cart-sum>$0.00</b></p>
      <p class="form-error" data-cart-error role="alert" hidden></p>
      <button class="btn btn-primary btn-block" type="submit" data-cart-submit>Enviar pedido a cocina</button>
      <?php if (!$accepting): ?><p class="hint center"><?= e($appConfig['closedText']) ?></p><?php endif; ?>
    </footer>
  </form>
</div>

<div class="toast" data-toast role="status" aria-live="polite"></div>
<script>window.MENU = <?= json_encode($appConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<?php page_footer(); ?>
