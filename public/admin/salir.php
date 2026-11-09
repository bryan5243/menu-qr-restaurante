<?php

declare(strict_types=1);

require __DIR__ . '/../../src/admin.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valid($_POST['csrf'] ?? null)) {
    unset($_SESSION['admin'], $_SESSION['admin_seen']);
    session_regenerate_id(true);
}
header('Location: index.php', true, 303);
