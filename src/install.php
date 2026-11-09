<?php

declare(strict_types=1);

/** Usuarios que se crean solos en el modo demo (SQLite sin config.php). */
const DEMO_USERS = [
    ['admin', 'gaviota2026', 'admin'],
    ['cocina', 'cocina2026', 'cocina'],
];

/** Esquema común para MySQL, PostgreSQL y SQLite. {PK}, {DT} y {ENGINE} cambian según el motor. */
function schema_sql(string $driver): array
{
    $pk = ['mysql' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', 'pgsql' => 'SERIAL PRIMARY KEY', 'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT'][$driver];
    $dt = ['mysql' => 'DATETIME', 'pgsql' => 'TIMESTAMP', 'sqlite' => 'TEXT'][$driver];
    $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $fk = $driver === 'mysql' ? 'INT UNSIGNED' : 'INTEGER';

    $tables = [
        "CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
            setting_value TEXT NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS categories (
            id {PK},
            slug VARCHAR(80) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(255) NOT NULL DEFAULT '',
            kind VARCHAR(20) NOT NULL DEFAULT 'platos',
            active SMALLINT NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS dishes (
            id {PK},
            category_id {FK} NOT NULL REFERENCES categories (id),
            name VARCHAR(160) NOT NULL,
            description VARCHAR(500) NOT NULL DEFAULT '',
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            image VARCHAR(500) NOT NULL DEFAULT '',
            badge VARCHAR(40) NOT NULL DEFAULT '',
            available SMALLINT NOT NULL DEFAULT 1,
            featured SMALLINT NOT NULL DEFAULT 0,
            active SMALLINT NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            options VARCHAR(1000) NOT NULL DEFAULT '',
            created_at {DT} NULL
        )",
        "CREATE TABLE IF NOT EXISTS orders (
            id {PK},
            code VARCHAR(16) NOT NULL UNIQUE,
            kind VARCHAR(12) NOT NULL DEFAULT 'mesa',
            table_no INT NULL,
            customer_name VARCHAR(120) NOT NULL DEFAULT '',
            phone VARCHAR(40) NOT NULL DEFAULT '',
            address VARCHAR(255) NOT NULL DEFAULT '',
            payment VARCHAR(16) NOT NULL DEFAULT '',
            notes VARCHAR(500) NOT NULL DEFAULT '',
            status VARCHAR(12) NOT NULL DEFAULT 'recibido',
            eta_minutes INT NULL,
            eta_at {DT} NULL,
            rounds INT NOT NULL DEFAULT 1,
            call_flag VARCHAR(12) NOT NULL DEFAULT '',
            delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
            total DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at {DT} NULL,
            updated_at {DT} NULL,
            closed_at {DT} NULL
        )",
        "CREATE TABLE IF NOT EXISTS order_items (
            id {PK},
            order_id {FK} NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
            round INT NOT NULL DEFAULT 1,
            dish_id INT NULL,
            name VARCHAR(160) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            qty INT NOT NULL DEFAULT 1,
            note VARCHAR(160) NOT NULL DEFAULT '',
            options VARCHAR(255) NOT NULL DEFAULT '',
            created_at {DT} NULL
        )",
        "CREATE TABLE IF NOT EXISTS reservations (
            id {PK},
            code VARCHAR(16) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            phone VARCHAR(40) NOT NULL,
            res_date VARCHAR(10) NOT NULL,
            res_time VARCHAR(5) NOT NULL,
            people INT NOT NULL DEFAULT 2,
            notes VARCHAR(500) NOT NULL DEFAULT '',
            status VARCHAR(12) NOT NULL DEFAULT 'pendiente',
            created_at {DT} NULL
        )",
        "CREATE TABLE IF NOT EXISTS users (
            id {PK},
            username VARCHAR(60) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(12) NOT NULL DEFAULT 'admin',
            created_at {DT} NULL
        )",
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id {PK},
            ip VARCHAR(45) NOT NULL,
            attempted_at {DT} NOT NULL
        )",
    ];
    $out = [];
    foreach ($tables as $sql) {
        $out[] = str_replace(['{PK}', '{DT}', '{FK}'], [$pk, $dt, $fk], $sql) . $engine;
    }
    $out[] = 'CREATE INDEX idx_items_order ON order_items (order_id)';
    $out[] = 'CREATE INDEX idx_orders_status ON orders (status)';
    $out[] = 'CREATE INDEX idx_attempts_ip ON login_attempts (ip, attempted_at)';
    return $out;
}

/** ¿Ya existen las tablas? */
function is_installed(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        return true;
    } catch (PDOException) {
        return false;
    }
}

/**
 * Crea las tablas y carga el restaurante de ejemplo. Se puede repetir: no borra datos.
 * $demoUsers crea los usuarios de prueba (solo en modo demo).
 */
function run_install(PDO $pdo, bool $demoUsers = false): array
{
    $log = [];
    foreach (schema_sql(db_driver()) as $statement) {
        try {
            $pdo->exec($statement);
        } catch (PDOException $exception) {
            // Los índices ya existentes dan error al repetir la instalación: se ignoran.
            if (!str_starts_with($statement, 'CREATE INDEX')) {
                throw $exception;
            }
        }
    }
    migrate_schema($pdo);
    $log[] = 'Tablas creadas.';

    $seed = require ROOT . '/config/demo.php';
    $existing = $pdo->query('SELECT setting_key FROM settings')->fetchAll(PDO::FETCH_COLUMN);
    $insert = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ($seed['settings'] as $key => $value) {
        if (!in_array($key, $existing, true)) {
            $insert->execute([$key, (string) $value]);
        }
    }

    if ((int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 0) {
        seed_menu($pdo, $seed);
        $log[] = 'Menú de ejemplo cargado.';
    }

    if ($demoUsers && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $user = $pdo->prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)');
        foreach (DEMO_USERS as [$name, $password, $role]) {
            $user->execute([$name, password_hash($password, PASSWORD_DEFAULT), $role, now()]);
        }
        $log[] = 'Usuarios de demostración creados.';
    }
    $log[] = 'Base de datos lista.';
    return $log;
}

function seed_menu(PDO $pdo, array $seed): void
{
    $category = $pdo->prepare('INSERT INTO categories (slug, name, description, kind, sort_order) VALUES (?, ?, ?, ?, ?)');
    $ids = [];
    foreach ($seed['categories'] as $i => $c) {
        $category->execute([$c['slug'], $c['name'], $c['description'], $c['kind'], $i]);
        $ids[$c['slug']] = insert_id($pdo, 'categories');
    }
    $dish = $pdo->prepare('INSERT INTO dishes (category_id, name, description, price, image, badge, available, featured, sort_order, options, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($seed['dishes'] as $i => $d) {
        $dish->execute([$ids[$d['category']], $d['name'], $d['description'], $d['price'], $d['image'] ?? '', $d['badge'] ?? '', $d['available'] ?? 1, $d['featured'] ?? 0, $i, implode("\n", $d['options'] ?? []), now()]);
    }
}
