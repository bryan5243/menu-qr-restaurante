<?php

declare(strict_types=1);

// Usuarios del panel. El personal de cocina y meseros solo ve Cocina y Reservas:
// no puede cambiar precios, ajustes ni ver las ventas.
require __DIR__ . '/../../src/admin.php';

$pdo = require_admin('personal');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_post();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'create') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = isset(ROLES[$_POST['role'] ?? '']) ? $_POST['role'] : 'cocina';
        $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $exists->execute([$username]);
        if (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username)) {
            flash('El usuario debe tener entre 3 y 60 letras, números, puntos o guiones.', 'error');
        } elseif ((int) $exists->fetchColumn() > 0) {
            flash('Ese usuario ya existe.', 'error');
        } elseif (mb_strlen($password) < 8) {
            flash('La contraseña debe tener al menos 8 caracteres.', 'error');
        } else {
            $pdo->prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)')->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, now()]);
            flash('Usuario «' . $username . '» creado.');
        }
    } elseif ($action === 'password') {
        $password = (string) ($_POST['password'] ?? '');
        if (mb_strlen($password) < 8) {
            flash('La contraseña debe tener al menos 8 caracteres.', 'error');
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            flash('Contraseña cambiada.');
        }
    } elseif ($action === 'delete') {
        $admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        $target = $pdo->prepare('SELECT role FROM users WHERE id = ?');
        $target->execute([$id]);
        $role = $target->fetchColumn();
        if ($id === (int) admin_user()['id']) {
            flash('No puedes borrar tu propio usuario.', 'error');
        } elseif ($role === 'admin' && $admins <= 1) {
            flash('Debe quedar al menos un administrador.', 'error');
        } else {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            flash('Usuario eliminado.');
        }
    }
    redirect('personal.php');
}

$users = $pdo->query('SELECT id, username, role, created_at FROM users ORDER BY role, username')->fetchAll();

admin_header('Personal', 'personal');
?>
<div class="a-head"><div><h1>Personal</h1><p class="hint">Crea un usuario para la cocina o los meseros. Solo verán la pantalla de Cocina y las Reservas, sin precios ni ventas.</p></div></div>
<section class="panel">
  <table class="a-table">
    <thead><tr><th>Usuario</th><th>Permiso</th><th>Cambiar contraseña</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td data-label="Usuario"><b><?= e($u['username']) ?></b><?= (int) $u['id'] === (int) admin_user()['id'] ? ' <small>(tú)</small>' : '' ?></td>
          <td data-label="Permiso"><span class="tag <?= $u['role'] === 'admin' ? 'tag-new' : 'tag-muted' ?>"><?= e(ROLES[$u['role']] ?? $u['role']) ?></span></td>
          <td data-label="Contraseña"><form method="post" class="a-row"><?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input type="password" name="password" minlength="8" placeholder="Nueva contraseña" aria-label="Nueva contraseña" autocomplete="new-password"><button class="a-btn a-btn-sm" type="submit">Cambiar</button></form></td>
          <td><?php if ((int) $u['id'] !== (int) admin_user()['id']): ?><form method="post" data-confirm="¿Eliminar a <?= e($u['username']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="a-link-danger" type="submit">Eliminar</button></form><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<form class="panel" method="post">
  <h2>Nuevo usuario</h2>
  <?= csrf_field() ?><input type="hidden" name="action" value="create">
  <div class="a-grid-3">
    <label>Usuario <input name="username" required pattern="[a-zA-Z0-9._\-]{3,60}" placeholder="mesero1" autocomplete="off"></label>
    <label>Contraseña <input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
    <label>Permiso <select name="role"><?php foreach (ROLES as $k => $label): ?><option value="<?= $k ?>"<?= $k === 'cocina' ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
  </div>
  <button class="a-btn a-btn-primary" type="submit">Crear usuario</button>
</form>
<?php admin_footer(); ?>
