<?php
session_start();

define('API_KEY', 'lab3d_rpi');
define('DB_PATH', __DIR__ . '/db/musicbot.sqlite');

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        init_db($pdo);
    }
    return $pdo;
}

function init_db($pdo) {
    // Tablas base (cada una en su propio exec para evitar fallos silenciosos)
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        texto TEXT NOT NULL,
        processed INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS votes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        song_id INTEGER NOT NULL,
        vote TEXT NOT NULL DEFAULT 'up',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (song_id) REFERENCES now_playing(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS now_playing (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        artist TEXT NOT NULL DEFAULT '',
        youtube_url TEXT DEFAULT '',
        active INTEGER DEFAULT 1,
        total_up INTEGER DEFAULT 0,
        total_down INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_vote_unique ON votes(user_id, song_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS song_catalog (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        artist TEXT NOT NULL DEFAULT '',
        total_up INTEGER DEFAULT 0,
        total_down INTEGER DEFAULT 0,
        times_played INTEGER DEFAULT 0,
        times_skipped INTEGER DEFAULT 0,
        list_color TEXT DEFAULT 'white',
        requested_by TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_catalog_unique ON song_catalog(title, artist)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        song_id INTEGER NOT NULL,
        reaction TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (song_id) REFERENCES now_playing(id)
    )");

    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_reaction_unique ON reactions(user_id, song_id, reaction)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_comments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        song_id INTEGER NOT NULL,
        comment TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (song_id) REFERENCES now_playing(id)
    )");

    // Migraciones: agregar columnas a tablas existentes
    $cols = array_column($pdo->query("PRAGMA table_info(requests)")->fetchAll(), 'name');
    if (!in_array('priority', $cols)) {
        $pdo->exec("ALTER TABLE requests ADD COLUMN priority TEXT DEFAULT 'normal'");
    }

    $cols = array_column($pdo->query("PRAGMA table_info(now_playing)")->fetchAll(), 'name');
    if (!in_array('requested_by', $cols)) {
        $pdo->exec("ALTER TABLE now_playing ADD COLUMN requested_by TEXT DEFAULT ''");
    }
    if (!in_array('thumbnail_url', $cols)) {
        $pdo->exec("ALTER TABLE now_playing ADD COLUMN thumbnail_url TEXT DEFAULT ''");
    }

    $cols = array_column($pdo->query("PRAGMA table_info(song_catalog)")->fetchAll(), 'name');
    if (!in_array('thumbnail_url', $cols)) {
        $pdo->exec("ALTER TABLE song_catalog ADD COLUMN thumbnail_url TEXT DEFAULT ''");
    }
}

function validar_email($email) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $dominio = strtolower(substr($email, strrpos($email, '@') + 1));
    return in_array($dominio, array('uach.cl', 'alumnos.uach.cl'));
}

function usuario_logueado() {
    return isset($_SESSION['user_id']);
}

function user_id() {
    return isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
}

function user_email() {
    return isset($_SESSION['email']) ? $_SESSION['email'] : '';
}

function redirigir($url) {
    header("Location: $url");
    exit;
}

function h($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function validar_api_key() {
    $key = isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : (isset($_GET['api_key']) ? $_GET['api_key'] : '');
    return $key === API_KEY;
}

function recalcular_lista($pdo, $title, $artist) {
    $stmt = $pdo->prepare("SELECT * FROM song_catalog WHERE LOWER(title) = LOWER(?) AND LOWER(artist) = LOWER(?)");
    $stmt->execute([$title, $artist]);
    $song = $stmt->fetch();
    if (!$song) return 'white';
    if ($song['list_color'] === 'black') return 'black';

    $net = $song['total_up'] - $song['total_down'];
    if ($net >= 5) $color = 'gold';
    elseif ($net >= 1) $color = 'green';
    else $color = 'white';

    $stmt = $pdo->prepare("UPDATE song_catalog SET list_color = ? WHERE id = ?");
    $stmt->execute([$color, $song['id']]);
    return $color;
}

function ofuscar_email($email) {
    if (!$email) return '';
    $parts = explode('@', $email);
    $name = $parts[0];
    $domain = isset($parts[1]) ? $parts[1] : '';
    if (strlen($name) <= 2) return $name[0] . '***@' . $domain;
    return $name[0] . '***' . $name[strlen($name) - 1] . '@' . $domain;
}
