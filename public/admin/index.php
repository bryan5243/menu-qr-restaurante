<?php

declare(strict_types=1);

require __DIR__ . '/../../src/admin.php';

$pdo = db();
$ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
$error = null;

if ($pdo === null) {
    require_admin(); // muestra el aviso de base de datos
}

// Hostings sin terminal: la primera visita crea las tablas con la clave de instalación.
if (!is_installed($pdo)) {
    $setupKey = (string) (config()['admin_setup_key'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_post();
        if ($setupKey !== '' && hash_equals($setupKey, (string) ($_POST['setup_key'] ?? ''))) {
            run_install($pdo);
            flash('Base de datos instalada con el menú de ejemplo. Ahora crea tu usuario administrador.');
            redirect('index.php');
        }
        sleep(2);
        $error = 'La clave de instalación no es correcta.';
    }
    admin_header('Instalar');
    ?>
    <div class="a-login">
      <span class="a-logo"><?= site_logo() ?></span>
      <form class="panel" method="post">
        <h1>Instalar la base de datos</h1>
        <?php if ($setupKey === ''): ?>
          <p class="hint">Primero escribe una clave en <code>admin_setup_key</code> dentro de <code>config/config.php</code>.</p>
        <?php else: ?>
          <p class="hint">La conexión funciona, pero faltan las tablas. Escribe la clave <code>admin_setup_key</code> de <code>config/config.php</code> para crearlas.</p>
          <?php if ($error): ?><p class="a-flash a-flash-error" role="alert"><?= e($error) ?></p><?php endif; ?>
          <?= csrf_field() ?>
          <label>Clave de instalación <input name="setup_key" type="password" required autocomplete="off"></label>
          <button class="a-btn a-btn-primary" type="submit">Instalar</button>
        <?php endif; ?>
      </form>
    </div>
    <?php
    admin_footer();
    exit;
}

$hasUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && admin_user() === null) {
    check_post();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $recent = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > ?');
    $recent->execute([$ip, date('Y-m-d H:i:s', time() - 900)]);
    $fail = static function () use ($pdo, $ip): void {
        $pdo->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)')->execute([$ip, now()]);
    };

    if ((int) $recent->fetchColumn() >= 5) {
        $error = 'Demasiados intentos. Espera 15 minutos e inténtalo de nuevo.';
    } elseif (!$hasUsers) {
        // Primer administrador: requiere la clave de instalación de config/config.php.
        $setupKey = (string) (config()['admin_setup_key'] ?? '');
        if ($setupKey === '' || !hash_equals($setupKey, (string) ($_POST['setup_key'] ?? ''))) {
            $fail();
            $error = 'La clave de instalación no es correcta.';
        } elseif (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username)) {
            $error = 'El usuario debe tener entre 3 y 60 letras, números, puntos o guiones.';
        } elseif (mb_strlen($password) < 10) {
            $error = 'La contraseña debe tener al menos 10 caracteres.';
        } else {
            $pdo->prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)')->execute([$username, password_hash($password, PASSWORD_DEFAULT), 'admin', now()]);
            session_regenerate_id(true);
            $_SESSION['admin'] = ['id' => insert_id($pdo, 'users'), 'username' => $username, 'role' => 'admin'];
            $_SESSION['admin_seen'] = time();
            flash('Administrador creado. Ya puedes quitar admin_setup_key de config/config.php.');
            redirect('index.php');
        }
    } else {
        $statement = $pdo->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ?');
        $statement->execute([$username]);
        $row = $statement->fetch();
        if ($row && password_verify($password, $row['password_hash'])) {
            $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
            session_regenerate_id(true);
            $_SESSION['admin'] = ['id' => (int) $row['id'], 'username' => $row['username'], 'role' => $row['role']];
            $_SESSION['admin_seen'] = time();
            redirect($row['role'] === 'admin' ? 'index.php' : 'cocina.php');
        }
        $fail();
        $error = 'Usuario o contraseña incorrectos.';
    }
}

if (admin_user() === null) {
    admin_header($hasUsers ? 'Ingresar' : 'Crear administrador');
    ?>
    <div class="a-login">
      <a class="a-logo" href="../"><?= site_logo() ?></a>
      <form class="panel" method="post">
        <h1><?= $hasUsers ? 'Ingreso del personal' : 'Crear el primer administrador' ?></h1>
        <?php if (isset($_GET['caducada'])): ?><p class="a-flash a-flash-warn">Tu sesión caducó. Vuelve a ingresar.</p><?php endif; ?>
        <?php if ($error): ?><p class="a-flash a-flash-error" role="alert"><?= e($error) ?></p><?php endif; ?>
        <?php if (demo_mode() && $hasUsers): ?>
          <div class="a-demo-users">
            <p><b>Demo:</b> usa uno de estos usuarios.</p>
            <?php foreach (DEMO_USERS as [$u, $p, $r]): ?>
              <button type="button" class="a-btn a-btn-sm" data-fill="<?= e($u) ?>|<?= e($p) ?>"><?= e(ROLES[$r]) ?>: <b><?= e($u) ?></b> / <?= e($p) ?></button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if (!$hasUsers): ?>
          <p class="hint">Escribe la clave <code>admin_setup_key</code> que pusiste en <code>config/config.php</code>.</p>
          <label>Clave de instalación <input name="setup_key" type="password" required autocomplete="off"></label>
        <?php endif; ?>
        <?= csrf_field() ?>
        <label>Usuario <input name="username" required autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>"></label>
        <label>Contraseña <input name="password" type="password" required autocomplete="<?= $hasUsers ? 'current-password' : 'new-password' ?>" <?= $hasUsers ? '' : 'minlength="10"' ?>></label>
        <button class="a-btn a-btn-primary a-btn-block" type="submit"><?= $hasUsers ? 'Ingresar' : 'Crear y entrar' ?></button>
      </form>
    </div>
    <script>document.querySelectorAll('[data-fill]').forEach(b => b.addEventListener('click', () => { const [u, p] = b.dataset.fill.split('|'); document.querySelector('[name=username]').value = u; document.querySelector('[name=password]').value = p; }));</script>
    <?php
    admin_footer();
    exit;
}

$pdo = require_admin('inicio');

// Resumen del día
$today = date('Y-m-d') . ' 00:00:00';
$sales = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS total FROM orders WHERE created_at >= ? AND status <> 'cancelado'");
$sales->execute([$today]);
$sales = $sales->fetch();
$in = implode(',', array_fill(0, count(OPEN_STATUSES), '?'));
$openStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status IN ($in)");
$openStmt->execute(OPEN_STATUSES);
$openCount = (int) $openStmt->fetchColumn();
$resStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE res_date = ? AND status IN ('pendiente', 'confirmada')");
$resStmt->execute([date('Y-m-d')]);
$resToday = (int) $resStmt->fetchColumn();
$pending = (int) $pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'pendiente'")->fetchColumn();
$top = $pdo->prepare("SELECT i.name, SUM(i.qty) AS qty, SUM(i.qty * i.price) AS amount FROM order_items i JOIN orders o ON o.id = i.order_id WHERE o.created_at >= ? AND o.status <> 'cancelado' GROUP BY i.name ORDER BY qty DESC LIMIT 6");
$top->execute([date('Y-m-d', strtotime('-6 days')) . ' 00:00:00']);
$top = $top->fetchAll();
$byKind = $pdo->prepare("SELECT kind, COUNT(*) AS n, COALESCE(SUM(total), 0) AS total FROM orders WHERE created_at >= ? AND status <> 'cancelado' GROUP BY kind");
$byKind->execute([$today]);
$byKind = array_column($byKind->fetchAll(), null, 'kind');
$soldOut = (int) $pdo->query('SELECT COUNT(*) FROM dishes WHERE active = 1 AND available = 0')->fetchColumn();
$maxQty = max(1, ...array_map(static fn ($r) => (int) $r['qty'], $top ?: [['qty' => 1]]));

admin_header('Resumen', 'inicio');
?>
<div class="a-head">
  <div><h1>Hola, <?= e(admin_user()['username']) ?></h1><p class="hint"><?= e(ucfirst(strftime_es())) ?> · <?= accepting_orders() ? '<span class="tag tag-ok">Recibiendo pedidos</span>' : '<span class="tag tag-off">Pedidos en línea apagados</span>' ?></p></div>
  <a class="a-btn a-btn-primary" href="cocina.php"><?= icon('cocina') ?> Abrir pantalla de cocina</a>
</div>

<?php if (demo_mode() || setting_on('demo_notice')): ?>
  <section class="panel a-demo">
    <div>
      <h2>Modo demostración</h2>
      <p class="hint"><?= demo_mode() ? 'Estás usando la base de prueba (SQLite), sin configurar nada. ' : '' ?>Para mostrarla en una visita: abre el menú en tu celular con el QR de una mesa, haz un pedido y mira cómo llega aquí a Cocina.</p>
    </div>
    <form method="post" action="demo.php" class="a-row">
      <?= csrf_field() ?>
      <button class="a-btn" name="action" value="sample" type="submit"><?= icon('mas') ?> Crear pedidos de ejemplo</button>
      <button class="a-btn a-btn-danger" name="action" value="reset" type="submit" data-confirm="¿Borrar todos los pedidos y reservas de la demo?"><?= icon('basura') ?> Vaciar pedidos y reservas</button>
    </form>
  </section>
<?php endif; ?>

<div class="a-cards">
  <a class="a-card" href="pedidos.php"><span><?= icon('dinero') ?></span><b><?= e(money((float) $sales['total'])) ?></b>Ventas de hoy</a>
  <a class="a-card" href="pedidos.php"><span><?= icon('lista') ?></span><b><?= (int) $sales['n'] ?></b>Pedidos de hoy</a>
  <a class="a-card" href="cocina.php"><span><?= icon('cocina') ?></span><b><?= $openCount ?></b>Pedidos abiertos</a>
  <a class="a-card" href="reservas.php"><span><?= icon('calendario') ?></span><b><?= $resToday ?></b>Reservas hoy<?= $pending ? ' · ' . $pending . ' por confirmar' : '' ?></a>
</div>

<div class="a-two">
  <section class="panel">
    <h2>Lo más pedido <small>últimos 7 días</small></h2>
    <?php if (!$top): ?><p class="hint">Aún no hay pedidos.</p><?php else: ?>
      <ul class="a-bars">
        <?php foreach ($top as $row): ?>
          <li><span><?= e($row['name']) ?></span><i style="--w:<?= round((int) $row['qty'] / $maxQty * 100) ?>%"></i><b><?= (int) $row['qty'] ?></b></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
  <section class="panel">
    <h2>Hoy por tipo de pedido</h2>
    <table class="a-table"><tbody>
      <?php foreach (ORDER_KINDS as $kind => $label): $row = $byKind[$kind] ?? ['n' => 0, 'total' => 0]; ?>
        <tr><td><?= e($label) ?></td><td><?= (int) $row['n'] ?> pedidos</td><td><b><?= e(money((float) $row['total'])) ?></b></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php if ($soldOut): ?><p class="hint"><a href="platos.php"><?= $soldOut ?> <?= $soldOut === 1 ? 'plato marcado' : 'platos marcados' ?> como agotado</a>.</p><?php endif; ?>
  </section>
</div>
<?php
admin_footer();

function strftime_es(): string
{
    $days = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $months = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return $days[(int) date('w')] . ' ' . (int) date('j') . ' de ' . $months[(int) date('n')];
}
