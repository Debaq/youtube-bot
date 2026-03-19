<?php
require_once __DIR__ . '/config.php';

if (usuario_logueado()) {
    redirigir('panel.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!validar_email($email)) {
        $error = 'Solo se permiten correos @uach.cl o @alumnos.uach.cl';
    } else {
        $pdo = db();

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            $stmt = $pdo->prepare("INSERT INTO users (email) VALUES (?)");
            $stmt->execute([$email]);
            $user_id = $pdo->lastInsertId();
        } else {
            $user_id = $user['id'];
        }

        $_SESSION['user_id'] = (int)$user_id;
        $_SESSION['email'] = $email;
        redirigir('panel.php');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Music Bot - Ingresar</title>
<link rel="stylesheet" href="style.css">
</head>
<body class="login-body">
<div class="login-card">
    <h1>Music Bot</h1>
    <p class="subtitle">Vota y sugiere musica para la sala</p>

    <?php if ($error): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <label for="email">Correo institucional</label>
        <input type="email" name="email" id="email"
               placeholder="tu.nombre@alumnos.uach.cl"
               pattern=".+@(uach\.cl|alumnos\.uach\.cl)"
               required autofocus>
        <small>Solo @uach.cl o @alumnos.uach.cl</small>
        <button type="submit">Entrar</button>
    </form>
</div>
</body>
</html>
