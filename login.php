<?php
require __DIR__ . '/lib/bootstrap.php';

// Jeśli logowanie wyłączone albo już zalogowany — na stronę główną.
if (auth_is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
    if (auth_attempt_login($password)) {
        header('Location: index.php');
        exit;
    }
    $error = 'Nieprawidłowe hasło.';
    usleep(400000); // drobne opóźnienie utrudniające zgadywanie
}

$title = htmlspecialchars((string) cfg('app.title', 'osTicket — Statystyki'), ENT_QUOTES);
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Logowanie — <?= $title ?></title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="login-page">
    <form class="login-card" method="post" autocomplete="off">
        <img src="https://pulpit.meritoros.pl/img/client/meritoros.png" alt="Meritoros"
             style="height:40px;margin:0 auto 6px" onerror="this.style.display='none'">

        <h1><?= $title ?></h1>
        <p class="muted">Podaj hasło dostępu do panelu.</p>
        <?php if ($error !== ''): ?>
            <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
        <?php endif; ?>
        <input type="password" name="password" placeholder="Hasło" autofocus required>
        <button type="submit">Zaloguj</button>
    </form>
</body>
</html>
