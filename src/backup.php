<?php

declare(strict_types=1);

/**
 * Respaldos desde el panel: la base de datos como .sql (sirve para MySQL, PostgreSQL y SQLite)
 * y, si el hosting tiene la extensión ZIP, un ZIP con la base y las fotos subidas.
 */

const BACKUP_TABLES = ['settings', 'categories', 'dishes', 'orders', 'order_items', 'reservations', 'users'];

function backup_database(PDO $pdo, string $path): void
{
    $driver = db_driver();
    $q = static fn (string $name): string => $driver === 'mysql' ? '`' . $name . '`' : '"' . $name . '"';
    $out = fopen($path, 'wb');
    if ($out === false) {
        throw new RuntimeException('No se pudo crear el archivo del respaldo.');
    }
    fwrite($out, "-- Respaldo de " . setting('name') . "\n-- Fecha: " . date('Y-m-d H:i:s') . "\n-- Motor: $driver\n");
    fwrite($out, $driver === 'mysql' ? "-- Restaurar: phpMyAdmin → Importar este archivo.\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n" : "-- Restaurar: ejecuta este archivo en el editor SQL de tu base.\n\n");

    foreach (array_reverse(BACKUP_TABLES) as $table) {
        fwrite($out, 'DROP TABLE IF EXISTS ' . $q($table) . ($driver === 'pgsql' ? ' CASCADE' : '') . ";\n");
    }
    fwrite($out, "\n");
    $creates = [];
    foreach (schema_sql($driver) as $sql) {
        if (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $m)) {
            $creates[$m[1]] = $sql;
        }
    }
    foreach (BACKUP_TABLES as $table) {
        fwrite($out, $creates[$table] . ";\n");
        $batch = [];
        $columns = null;
        foreach ($pdo->query('SELECT * FROM ' . $table . ' ORDER BY 1') as $row) {
            $columns ??= '(' . implode(', ', array_map($q, array_keys($row))) . ')';
            $batch[] = '(' . implode(', ', array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
            if (count($batch) === 100) {
                fwrite($out, 'INSERT INTO ' . $q($table) . " $columns VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) {
            fwrite($out, 'INSERT INTO ' . $q($table) . " $columns VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        if ($driver === 'pgsql' && $table !== 'settings') {
            fwrite($out, "SELECT setval('{$table}_id_seq', COALESCE((SELECT MAX(id) FROM $table), 0) + 1, false);\n");
        }
        fwrite($out, "\n");
    }
    if ($driver === 'mysql') {
        fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
    }
    fclose($out);
}

/** ZIP con database.sql y las fotos subidas (public/uploads). */
function backup_full(PDO $pdo, string $path): void
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('Este servidor no tiene la extensión ZIP de PHP. Descarga solo la base de datos.');
    }
    $sql = $path . '.sql';
    backup_database($pdo, $sql);
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('No se pudo crear el ZIP del respaldo.');
    }
    $zip->addFile($sql, 'database.sql');
    $zip->addFromString('LEEME.txt', "Respaldo de " . setting('name') . ' — ' . date('Y-m-d H:i') . "\n\ndatabase.sql  Base de datos completa.\nuploads/      Fotos subidas desde el panel: van en public/uploads/.\n");
    $dir = PUBLIC_DIR . '/uploads';
    if (is_dir($dir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getFilename() !== '.htaccess') {
                $zip->addFile($file->getPathname(), 'uploads/' . str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1)));
            }
        }
    }
    $zip->close();
    @unlink($sql);
}

function backup_send(string $path, string $name, string $type): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $type);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: private, no-store');
    readfile($path);
    @unlink($path);
    exit;
}
