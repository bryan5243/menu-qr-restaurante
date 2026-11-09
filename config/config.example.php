<?php
// Copia este archivo como config/config.php SOLO cuando el restaurante use su propia base de datos.
// Sin config/config.php el sistema funciona en modo demo con SQLite (storage/demo.sqlite).
// config/config.php no se sube al repositorio.

return [
    'db' => [
        // 'mysql' (cPanel, Hostinger, XAMPP) o 'pgsql' (Supabase)
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,          // Supabase: 5432 (o 6543 con el "pooler")
        'name' => 'menu',        // Supabase: postgres
        'user' => 'menu_user',   // Supabase: postgres.xxxxxxxx (el del "pooler")
        'pass' => '',
        // 'sslmode' => 'require', // solo PostgreSQL / Supabase
    ],
    // Clave para instalar las tablas y crear el primer administrador en /admin.
    // Usa una frase larga y bórrala de aquí cuando ya tengas tu usuario.
    'admin_setup_key' => '',
];
