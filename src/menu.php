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
    $row['options'] = (string) ($row['options'] ?? '');
    return $row;
}

/** Máximo de opciones por plato. */
const DISH_OPTIONS_MAX = 12;

/**
 * Opciones de un plato, guardadas una por línea:
 *   «- Sin menestra»            algo que el cliente puede quitar (gratis)
 *   «+ Patacones extra | 1.00»  un adicional con su precio
 * Devuelve [['type' => 'sin'|'extra', 'name' => …, 'price' => float], …].
 */
function dish_options(string $text): array
{
    $out = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $type = 'extra';
        if ($line[0] === '-' || $line[0] === '+') {
            $type = $line[0] === '-' ? 'sin' : 'extra';
            $line = trim(substr($line, 1));
        } elseif (preg_match('/^sin\s/iu', $line)) {
            $type = 'sin';
        }
        $price = 0.0;
        if (str_contains($line, '|')) {
            [$line, $amount] = array_map('trim', explode('|', $line, 2));
            $price = round(max(0, (float) str_replace(',', '.', $amount)), 2);
        }
        $name = trim(mb_substr($line, 0, 60));
        if ($name === '') {
            continue;
        }
        if ($type === 'sin' && !preg_match('/^sin\s/iu', $name)) {
            $name = 'Sin ' . mb_strtolower(mb_substr($name, 0, 1)) . mb_substr($name, 1);
        }
        $out[] = ['type' => $type, 'name' => $name, 'price' => $type === 'sin' ? 0.0 : $price];
        if (count($out) === DISH_OPTIONS_MAX) {
            break;
        }
    }
    return $out;
}

/** Convierte la lista de opciones al texto que se guarda en la base. */
function dish_options_text(array $options): string
{
    return implode("\n", array_map(static fn (array $o): string => ($o['type'] === 'sin' ? '- ' : '+ ') . $o['name'] . ($o['type'] === 'extra' && $o['price'] > 0 ? ' | ' . number_format($o['price'], 2, '.', '') : ''), $options));
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
        'opts' => array_map(static fn (array $o): array => [$o['type'], $o['name'], $o['price']], dish_options($dish['options'])),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
}
