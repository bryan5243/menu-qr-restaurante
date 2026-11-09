<?php

declare(strict_types=1);

/** Menú: categorías activas con sus platos activos. */
function menu_categories(bool $all = false): array
{
    $pdo = db();
    if ($pdo === null) {
        return [];
    }
    $categories = $pdo->query('SELECT * FROM categories' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY sort_order, id')->fetchAll();
    $dishes = $pdo->query('SELECT * FROM dishes' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY sort_order, id')->fetchAll();
    $byCategory = [];
    foreach ($dishes as $dish) {
        $byCategory[(int) $dish['category_id']][] = normalize_dish($dish);
    }
    foreach ($categories as &$category) {
        $category['dishes'] = $byCategory[(int) $category['id']] ?? [];
    }
    unset($category);
    return $all ? $categories : array_values(array_filter($categories, static fn ($c) => $c['dishes'] !== []));
}

function normalize_dish(array $row): array
{
    $row['id'] = (int) $row['id'];
    $row['category_id'] = (int) $row['category_id'];
    $row['price'] = (float) $row['price'];
    foreach (['available', 'featured', 'active', 'sort_order'] as $key) {
        $row[$key] = (int) $row[$key];
    }
    return $row;
}

/** Datos de un plato que usa el carrito del navegador. */
function dish_payload(array $dish, string $kind): string
{
    return json_encode([
        'id' => $dish['id'],
        'name' => $dish['name'],
        'price' => $dish['price'],
        'desc' => $dish['description'],
        'badge' => $dish['badge'],
        'img' => image_src($dish['image'], 640, 480) ?: fallback_art($kind),
        'art' => fallback_art($kind),
        'ok' => $dish['available'] === 1,
        'opts' => dish_options($dish),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
}
