<?php

declare(strict_types=1);

const ASSET_VERSION = '2';

function page_header(string $title, string $bodyClass = '', string $description = ''): void
{
    $name = setting('name');
    $fullTitle = $title === '' ? $name . ' · ' . setting('tagline') : $title . ' · ' . $name;
    ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= e($fullTitle) ?></title>
  <meta name="description" content="<?= e($description !== '' ? $description : setting('hero_text')) ?>">
  <meta name="theme-color" content="<?= e(brand_colors()['color_dark']) ?>">
  <meta property="og:title" content="<?= e($fullTitle) ?>">
  <meta property="og:description" content="<?= e(setting('tagline')) ?>">
  <link rel="icon" href="<?= url('assets/img/favicon.svg') ?>" type="image/svg+xml">
  <link rel="apple-touch-icon" href="<?= url('assets/img/apple-touch-icon.png') ?>">
  <link rel="preload" href="<?= url('assets/fonts/fraunces-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= url('assets/app.css?v=' . ASSET_VERSION) ?>">
  <?= brand_style() ?>
</head>
<body class="<?= e($bodyClass) ?>">
<?php
}

function page_footer(array $scripts = ['assets/app.js']): void
{
    ?>
<footer class="foot">
  <div class="wrap foot-in">
    <a class="logo logo--light" href="<?= url('./') ?>"><?= site_logo(true) ?></a>
    <p><?= e(setting('address')) ?><?= setting('schedule_text') !== '' ? ' · ' . e(setting('schedule_text')) : '' ?></p>
    <p class="foot-credit">Menú digital y pedidos con QR · <a href="<?= url('admin/') ?>">Ingreso del personal</a></p>
  </div>
</footer>
<?php foreach ($scripts as $script): ?>
<script src="<?= url($script . '?v=' . ASSET_VERSION) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

/** Enlaces a redes sociales configuradas. */
function social_links(): string
{
    $html = '';
    foreach (['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok'] as $key => $label) {
        $href = setting($key);
        if ($href !== '') {
            $html .= '<a class="social" href="' . e($href) . '" target="_blank" rel="noopener" aria-label="' . $label . '">' . icon($key) . '</a>';
        }
    }
    return $html;
}
