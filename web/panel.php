<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/migrations.php';

if (!usuario_logueado()) {
    redirigir('index.php');
}

$pdo = db();
run_migrations($pdo);

// Migraciones legacy (seguro ejecutar múltiples veces)
$cols = array_column($pdo->query("PRAGMA table_info(requests)")->fetchAll(), 'name');
if (!in_array('priority', $cols)) {
    $pdo->exec("ALTER TABLE requests ADD COLUMN priority TEXT DEFAULT 'normal'");
}
$cols = array_column($pdo->query("PRAGMA table_info(now_playing)")->fetchAll(), 'name');
if (!in_array('requested_by', $cols)) {
    $pdo->exec("ALTER TABLE now_playing ADD COLUMN requested_by TEXT DEFAULT ''");
}
$tables = array_column($pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(), 'name');
if (!in_array('song_catalog', $tables)) {
    $pdo->exec("CREATE TABLE song_catalog (
        id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, artist TEXT NOT NULL DEFAULT '',
        total_up INTEGER DEFAULT 0, total_down INTEGER DEFAULT 0, times_played INTEGER DEFAULT 0,
        times_skipped INTEGER DEFAULT 0, list_color TEXT DEFAULT 'white', requested_by TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE UNIQUE INDEX idx_catalog_unique ON song_catalog(title, artist)");
}
if (!in_array('reactions', $tables)) {
    $pdo->exec("CREATE TABLE reactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, song_id INTEGER NOT NULL,
        reaction TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id), FOREIGN KEY (song_id) REFERENCES now_playing(id))");
    $pdo->exec("CREATE UNIQUE INDEX idx_reaction_unique ON reactions(user_id, song_id, reaction)");
}
if (!in_array('ai_comments', $tables)) {
    $pdo->exec("CREATE TABLE ai_comments (
        id INTEGER PRIMARY KEY AUTOINCREMENT, song_id INTEGER NOT NULL, comment TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (song_id) REFERENCES now_playing(id))");
}
if (!in_array('bot_settings', $tables)) {
    $pdo->exec("CREATE TABLE bot_settings (
        key TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("INSERT INTO bot_settings (key, value) VALUES ('volume', '80')");
    $pdo->exec("INSERT INTO bot_settings (key, value) VALUES ('muted', '0')");
}

// Expirar solicitudes pendientes de más de 30 minutos
$pdo->exec("UPDATE requests SET processed = 1 WHERE processed = 0 AND created_at < datetime('now', '-30 minutes')");

// Flash messages (PRG pattern)
$msg = '';
$msg_type = '';
if (isset($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    $msg_type = isset($_SESSION['flash_type']) ? $_SESSION['flash_type'] : 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// Procesar acciones POST via AJAX o normal
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'request') {
        $texto = trim(isset($_POST['texto']) ? $_POST['texto'] : '');
        $priority = 'normal';
        // Detectar "!ahora" en el texto
        if (stripos($texto, '!ahora') !== false) {
            $priority = 'now';
            $texto = trim(str_ireplace('!ahora', '', $texto));
        }
        if (isset($_POST['priority']) && $_POST['priority'] === 'now') {
            $priority = 'now';
        }
        if ($texto !== '') {
            // Evitar duplicados: no insertar si el mismo usuario envió el mismo texto en los últimos 30s
            $stmt = $pdo->prepare("
                SELECT id FROM requests
                WHERE user_id = ? AND texto = ? AND created_at > datetime('now', '-30 seconds')
            ");
            $stmt->execute(array(user_id(), $texto));
            if (!$stmt->fetch()) {
                $stmt = $pdo->prepare("INSERT INTO requests (user_id, texto, priority) VALUES (?, ?, ?)");
                $stmt->execute(array(user_id(), $texto, $priority));
            }
            // PRG: redirigir para evitar reenvío al refrescar
            $_SESSION['flash_msg'] = $priority === 'now' ? 'Solicitud enviada con prioridad AHORA' : 'Tu sugerencia fue enviada';
            $_SESSION['flash_type'] = 'success';
            header('Location: panel.php');
            exit;
        }
    } elseif ($action === 'vote') {
        $song_id = (int)(isset($_POST['song_id']) ? $_POST['song_id'] : 0);
        $vote = isset($_POST['vote']) ? $_POST['vote'] : 'up';
        if ($song_id > 0 && in_array($vote, array('up', 'down'))) {
            $stmt = $pdo->prepare("
                INSERT INTO votes (user_id, song_id, vote)
                VALUES (?, ?, ?)
                ON CONFLICT(user_id, song_id) DO UPDATE SET vote = excluded.vote
            ");
            $stmt->execute(array(user_id(), $song_id, $vote));

            $stmt = $pdo->prepare("SELECT
                SUM(CASE WHEN vote='up' THEN 1 ELSE 0 END) as ups,
                SUM(CASE WHEN vote='down' THEN 1 ELSE 0 END) as downs
                FROM votes WHERE song_id = ?");
            $stmt->execute(array($song_id));
            $counts = $stmt->fetch();

            $ups = isset($counts['ups']) ? $counts['ups'] : 0;
            $downs = isset($counts['downs']) ? $counts['downs'] : 0;
            $stmt = $pdo->prepare("UPDATE now_playing SET total_up = ?, total_down = ? WHERE id = ?");
            $stmt->execute(array($ups, $downs, $song_id));

            $msg = 'Voto registrado';
            $msg_type = 'success';
        }
    } elseif ($action === 'queue_action') {
        $qa = isset($_POST['queue_action']) ? $_POST['queue_action'] : '';
        $position = (int)(isset($_POST['position']) ? $_POST['position'] : 0);
        $source = isset($_POST['source']) ? $_POST['source'] : 'queue';

        $valid_actions = array('move_up', 'move_down', 'remove', 'clear', 'refresh', 'skip', 'pause');
        if (in_array($qa, $valid_actions)) {
            $data = json_encode(array('position' => $position, 'source' => $source));
            $stmt = $pdo->prepare("INSERT INTO queue_actions (action, data) VALUES (?, ?)");
            $stmt->execute(array($qa, $data));
        }

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(array('ok' => true));
            exit;
        }
    } elseif ($action === 'react') {
        $song_id = (int)(isset($_POST['song_id']) ? $_POST['song_id'] : 0);
        $reaction = isset($_POST['reaction']) ? $_POST['reaction'] : '';
        $valid_reactions = array('fire', 'dance', 'heart', 'sleep', 'skull');

        if ($song_id > 0 && in_array($reaction, $valid_reactions)) {
            // Toggle
            $stmt = $pdo->prepare("SELECT id FROM reactions WHERE user_id = ? AND song_id = ? AND reaction = ?");
            $stmt->execute(array(user_id(), $song_id, $reaction));
            $exists = $stmt->fetch();

            if ($exists) {
                $stmt = $pdo->prepare("DELETE FROM reactions WHERE id = ?");
                $stmt->execute(array($exists['id']));
            } else {
                $stmt = $pdo->prepare("INSERT INTO reactions (user_id, song_id, reaction) VALUES (?, ?, ?)");
                $stmt->execute(array(user_id(), $song_id, $reaction));
            }
        }
        // Si es AJAX, devolver JSON
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            $stmt = $pdo->prepare("SELECT reaction, COUNT(*) as count FROM reactions WHERE song_id = ? GROUP BY reaction");
            $stmt->execute(array($song_id));
            $counts = array();
            foreach ($stmt->fetchAll() as $r) {
                $counts[$r['reaction']] = (int)$r['count'];
            }
            $stmt = $pdo->prepare("SELECT reaction FROM reactions WHERE user_id = ? AND song_id = ?");
            $stmt->execute(array(user_id(), $song_id));
            $my = array_column($stmt->fetchAll(), 'reaction');
            echo json_encode(array('counts' => $counts, 'my_reactions' => $my));
            exit;
        }
    }
}

// Endpoint AJAX para volumen
if (isset($_GET['ajax']) && $_GET['ajax'] === 'volume') {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['volume'])) {
            $vol = max(0, min(100, (int)$input['volume']));
            $pdo->prepare("INSERT INTO bot_settings (key, value, updated_at) VALUES ('volume', ?, datetime('now'))
                ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
                ->execute(array((string)$vol));
        }
        if (isset($input['muted'])) {
            $m = $input['muted'] ? '1' : '0';
            $pdo->prepare("INSERT INTO bot_settings (key, value, updated_at) VALUES ('muted', ?, datetime('now'))
                ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
                ->execute(array($m));
        }
        if (isset($input['video'])) {
            $v = $input['video'] ? '1' : '0';
            $pdo->prepare("INSERT INTO bot_settings (key, value, updated_at) VALUES ('video', ?, datetime('now'))
                ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
                ->execute(array($v));
        }
        foreach (array('preset', 'city') as $k) {
            if (isset($input[$k]) && trim($input[$k]) !== '') {
                $pdo->prepare("INSERT INTO bot_settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
                    ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
                    ->execute(array($k, trim($input[$k])));
            }
        }
        foreach (array('auto_mode', 'auto_fill') as $k) {
            if (isset($input[$k])) {
                $v = $input[$k] ? '1' : '0';
                $pdo->prepare("INSERT INTO bot_settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
                    ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
                    ->execute(array($k, $v));
            }
        }
    }
    $vol = $pdo->query("SELECT value FROM bot_settings WHERE key = 'volume'")->fetch();
    $muted = $pdo->query("SELECT value FROM bot_settings WHERE key = 'muted'")->fetch();
    $video = $pdo->query("SELECT value FROM bot_settings WHERE key = 'video'")->fetch();
    $preset = $pdo->query("SELECT value FROM bot_settings WHERE key = 'preset'")->fetch();
    $city = $pdo->query("SELECT value FROM bot_settings WHERE key = 'city'")->fetch();
    $auto_mode = $pdo->query("SELECT value FROM bot_settings WHERE key = 'auto_mode'")->fetch();
    $auto_fill = $pdo->query("SELECT value FROM bot_settings WHERE key = 'auto_fill'")->fetch();
    echo json_encode(array(
        'volume' => $vol ? (int)$vol['value'] : 80,
        'muted' => $muted ? (bool)(int)$muted['value'] : false,
        'video' => $video ? (bool)(int)$video['value'] : false,
        'preset' => $preset ? $preset['value'] : '',
        'city' => $city ? $city['value'] : '',
        'auto_mode' => $auto_mode ? (bool)(int)$auto_mode['value'] : true,
        'auto_fill' => $auto_fill ? (bool)(int)$auto_fill['value'] : true,
    ));
    exit;
}

// Endpoint AJAX para obtener estado actual sin recargar
if (isset($_GET['ajax']) && $_GET['ajax'] === 'status') {
    header('Content-Type: application/json');
    $current = $pdo->query("
        SELECT * FROM now_playing WHERE active = 1 ORDER BY id DESC LIMIT 1
    ")->fetch();

    $my_vote = null;
    $reactions = array();
    $my_reactions = array();
    $ai_comment = null;
    $song_list = 'white';
    $requested_by_display = '';

    if ($current) {
        $stmt = $pdo->prepare("SELECT vote FROM votes WHERE user_id = ? AND song_id = ?");
        $stmt->execute(array(user_id(), $current['id']));
        $row = $stmt->fetch();
        $my_vote = $row ? $row['vote'] : null;

        // Reacciones
        $stmt = $pdo->prepare("SELECT reaction, COUNT(*) as count FROM reactions WHERE song_id = ? GROUP BY reaction");
        $stmt->execute(array($current['id']));
        foreach ($stmt->fetchAll() as $r) {
            $reactions[$r['reaction']] = (int)$r['count'];
        }
        $stmt = $pdo->prepare("SELECT reaction FROM reactions WHERE user_id = ? AND song_id = ?");
        $stmt->execute(array(user_id(), $current['id']));
        $my_reactions = array_column($stmt->fetchAll(), 'reaction');

        // Comentario IA
        $stmt = $pdo->prepare("SELECT comment FROM ai_comments WHERE song_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute(array($current['id']));
        $comment_row = $stmt->fetch();
        $ai_comment = $comment_row ? $comment_row['comment'] : null;

        // Color de la canción
        $stmt = $pdo->prepare("SELECT list_color FROM song_catalog WHERE LOWER(title) = LOWER(?) AND LOWER(artist) = LOWER(?)");
        $stmt->execute(array($current['title'], $current['artist']));
        $catalog_row = $stmt->fetch();
        $song_list = $catalog_row ? $catalog_row['list_color'] : 'white';

        // Quién pidió
        $rb = isset($current['requested_by']) ? $current['requested_by'] : '';
        $requested_by_display = $rb ? ofuscar_email($rb) : '';
    }

    $mis_requests = $pdo->prepare("
        SELECT texto, processed, created_at FROM requests
        WHERE user_id = ? ORDER BY id DESC LIMIT 5
    ");
    $mis_requests->execute(array(user_id()));

    // Racha
    $songs_streak = $pdo->query("SELECT total_down FROM now_playing ORDER BY id DESC LIMIT 20")->fetchAll();
    $streak = 0;
    foreach ($songs_streak as $ss) {
        if ($ss['total_down'] > 0) break;
        $streak++;
    }

    echo json_encode(array(
        'current' => $current ? $current : null,
        'my_vote' => $my_vote,
        'reactions' => $reactions,
        'my_reactions' => $my_reactions,
        'ai_comment' => $ai_comment,
        'song_list' => $song_list,
        'requested_by' => $requested_by_display,
        'streak' => $streak,
        'requests' => $mis_requests->fetchAll(),
        'thumbnail_url' => $current ? ($current['thumbnail_url'] ?? '') : '',
    ));
    exit;
}

// Endpoint AJAX para listas por color
if (isset($_GET['ajax']) && $_GET['ajax'] === 'list') {
    header('Content-Type: application/json');
    $color = isset($_GET['color']) ? $_GET['color'] : 'all';

    if ($color === 'all') {
        $stmt = $pdo->query("SELECT title, artist, total_up, total_down, (total_up - total_down) as score, list_color, times_played FROM song_catalog WHERE list_color != 'black' ORDER BY score DESC");
    } else {
        $stmt = $pdo->prepare("SELECT title, artist, total_up, total_down, (total_up - total_down) as score, list_color, times_played FROM song_catalog WHERE list_color = ? ORDER BY score DESC");
        $stmt->execute(array($color));
    }
    echo json_encode($stmt->fetchAll());
    exit;
}

// Endpoint AJAX para ranking
if (isset($_GET['ajax']) && $_GET['ajax'] === 'ranking') {
    header('Content-Type: application/json');
    $ranking = $pdo->query("
        SELECT sc.requested_by as email,
               SUM(CASE WHEN sc.list_color = 'gold' THEN 1 ELSE 0 END) as gold,
               SUM(CASE WHEN sc.list_color = 'green' THEN 1 ELSE 0 END) as green_count,
               SUM(CASE WHEN sc.list_color = 'gold' THEN 1 ELSE 0 END) * 3 +
               SUM(CASE WHEN sc.list_color = 'green' THEN 1 ELSE 0 END) as score
        FROM song_catalog sc
        WHERE sc.requested_by != ''
          AND sc.list_color IN ('gold', 'green')
        GROUP BY sc.requested_by
        ORDER BY score DESC
        LIMIT 10
    ")->fetchAll();
    echo json_encode($ranking);
    exit;
}

// Endpoint AJAX para cola de reproducción
if (isset($_GET['ajax']) && $_GET['ajax'] === 'queue') {
    header('Content-Type: application/json');
    $queue = $pdo->query("SELECT * FROM queue_songs WHERE source = 'queue' ORDER BY position ASC")->fetchAll();
    $buffer = $pdo->query("SELECT * FROM queue_songs WHERE source = 'buffer' ORDER BY position ASC")->fetchAll();
    echo json_encode(array('queue' => $queue, 'buffer' => $buffer));
    exit;
}

// Endpoint AJAX para playlists
if (isset($_GET['ajax']) && $_GET['ajax'] === 'playlists') {
    header('Content-Type: application/json');
    $stmt = $pdo->query("
        SELECT p.*, (SELECT COUNT(*) FROM playlist_songs WHERE playlist_id = p.id) as song_count
        FROM playlists p
        ORDER BY p.created_at DESC
        LIMIT 50
    ");
    echo json_encode($stmt->fetchAll());
    exit;
}

// Endpoint AJAX para detalle de playlist
if (isset($_GET['ajax']) && $_GET['ajax'] === 'playlist') {
    header('Content-Type: application/json');
    $id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
    if (!$id) {
        echo json_encode(null);
        exit;
    }
    $stmt = $pdo->prepare("SELECT * FROM playlists WHERE id = ?");
    $stmt->execute(array($id));
    $playlist = $stmt->fetch();
    if ($playlist) {
        $stmt = $pdo->prepare("SELECT * FROM playlist_songs WHERE playlist_id = ? ORDER BY position");
        $stmt->execute(array($id));
        $playlist['songs'] = $stmt->fetchAll();
    }
    echo json_encode($playlist);
    exit;
}

// Endpoint AJAX para schedule
if (isset($_GET['ajax']) && $_GET['ajax'] === 'schedule') {
    header('Content-Type: application/json');
    // Crear tabla si no existe
    $pdo->exec("CREATE TABLE IF NOT EXISTS dj_schedule (
        id INTEGER PRIMARY KEY AUTOINCREMENT, hour_start INTEGER NOT NULL, hour_end INTEGER NOT NULL,
        preset TEXT NOT NULL, day_of_week TEXT DEFAULT 'all', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $schedule = isset($input['schedule']) ? $input['schedule'] : array();
        $pdo->exec("DELETE FROM dj_schedule");
        $stmt = $pdo->prepare("INSERT INTO dj_schedule (hour_start, hour_end, preset, day_of_week) VALUES (?, ?, ?, ?)");
        foreach ($schedule as $s) {
            $stmt->execute(array(
                (int)(isset($s['hour_start']) ? $s['hour_start'] : 0),
                (int)(isset($s['hour_end']) ? $s['hour_end'] : 23),
                isset($s['preset']) ? $s['preset'] : 'Todo vale',
                isset($s['day_of_week']) ? $s['day_of_week'] : 'all'
            ));
        }
        echo json_encode(array('ok' => true));
    } else {
        $stmt = $pdo->query("SELECT hour_start, hour_end, preset, day_of_week FROM dj_schedule ORDER BY hour_start ASC");
        echo json_encode($stmt->fetchAll());
    }
    exit;
}

// Endpoint AJAX para calendario de historial
if (isset($_GET['ajax']) && $_GET['ajax'] === 'history_calendar') {
    header('Content-Type: application/json');
    $days = isset($_GET['days']) ? min(60, max(1, (int)$_GET['days'])) : 30;
    $stmt = $pdo->prepare("
        SELECT DATE(created_at) as play_date, COUNT(*) as song_count
        FROM now_playing
        WHERE created_at >= datetime('now', '-' || ? || ' days')
        GROUP BY DATE(created_at)
        ORDER BY play_date DESC
    ");
    $stmt->execute(array($days));
    echo json_encode($stmt->fetchAll());
    exit;
}

// Endpoint AJAX para canciones de un día específico
if (isset($_GET['ajax']) && $_GET['ajax'] === 'history_day') {
    header('Content-Type: application/json');
    $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT np.title, np.artist, np.total_up, np.total_down,
               (np.total_up - np.total_down) as votes_net,
               np.requested_by, np.created_at, np.thumbnail_url,
               COALESCE(sc.list_color, 'white') as list_color
        FROM now_playing np
        LEFT JOIN song_catalog sc ON LOWER(np.title) = LOWER(sc.title) AND LOWER(np.artist) = LOWER(sc.artist)
        WHERE DATE(np.created_at) = ?
        ORDER BY np.created_at ASC
    ");
    $stmt->execute(array($date));
    echo json_encode($stmt->fetchAll());
    exit;
}

// Carga inicial
$current = $pdo->query("
    SELECT * FROM now_playing WHERE active = 1 ORDER BY id DESC LIMIT 1
")->fetch();

$my_vote = null;
$reactions = array();
$my_reactions = array();
$ai_comment = null;
$song_list = 'white';
$requested_by_display = '';

if ($current) {
    $stmt = $pdo->prepare("SELECT vote FROM votes WHERE user_id = ? AND song_id = ?");
    $stmt->execute(array(user_id(), $current['id']));
    $row = $stmt->fetch();
    $my_vote = $row ? $row['vote'] : null;

    $stmt = $pdo->prepare("SELECT reaction, COUNT(*) as count FROM reactions WHERE song_id = ? GROUP BY reaction");
    $stmt->execute(array($current['id']));
    foreach ($stmt->fetchAll() as $r) {
        $reactions[$r['reaction']] = (int)$r['count'];
    }
    $stmt = $pdo->prepare("SELECT reaction FROM reactions WHERE user_id = ? AND song_id = ?");
    $stmt->execute(array(user_id(), $current['id']));
    $my_reactions = array_column($stmt->fetchAll(), 'reaction');

    $stmt = $pdo->prepare("SELECT comment FROM ai_comments WHERE song_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute(array($current['id']));
    $comment_row = $stmt->fetch();
    $ai_comment = $comment_row ? $comment_row['comment'] : null;

    $stmt = $pdo->prepare("SELECT list_color FROM song_catalog WHERE LOWER(title) = LOWER(?) AND LOWER(artist) = LOWER(?)");
    $stmt->execute(array($current['title'], $current['artist']));
    $catalog_row = $stmt->fetch();
    $song_list = $catalog_row ? $catalog_row['list_color'] : 'white';

    $rb = isset($current['requested_by']) ? $current['requested_by'] : '';
    $requested_by_display = $rb ? ofuscar_email($rb) : '';
}

$historial = $pdo->query("
    SELECT np.title, np.artist, np.total_up, np.total_down, np.created_at,
           np.youtube_url, np.thumbnail_url,
           COALESCE(sc.list_color, 'white') as list_color
    FROM now_playing np
    LEFT JOIN song_catalog sc ON LOWER(np.title) = LOWER(sc.title) AND LOWER(np.artist) = LOWER(sc.artist)
    ORDER BY np.id DESC LIMIT 10
")->fetchAll();

$mis_requests = $pdo->prepare("
    SELECT texto, processed, created_at FROM requests
    WHERE user_id = ? ORDER BY id DESC LIMIT 5
");
$mis_requests->execute(array(user_id()));
$mis_requests = $mis_requests->fetchAll();

// Racha inicial
$songs_streak_init = $pdo->query("SELECT total_down FROM now_playing ORDER BY id DESC LIMIT 20")->fetchAll();
$streak_init = 0;
foreach ($songs_streak_init as $ss) {
    if ($ss['total_down'] > 0) break;
    $streak_init++;
}

$reaction_emojis = array(
    'fire' => '<i class="icon icon-flame"></i>',
    'dance' => '<i class="icon icon-music-4"></i>',
    'heart' => '<i class="icon icon-heart"></i>',
    'sleep' => '<i class="icon icon-moon"></i>',
    'skull' => '<i class="icon icon-skull"></i>'
);

$web_presets = array('Estudio tranquilo','Fiesta total','Rock clasico','Chill & Lo-fi','Pop actual',
    'Indie & Alternativo','Reggaeton & Urbano','Jazz & Soul','Electronica & EDM','Hip-Hop & Rap',
    'Cumbia & Latina','Metal & Heavy','K-Pop & J-Pop','Clasica & Orquestal','Acustico & Folk',
    '80s & Synthwave','R&B Contemporaneo','Mexicana & Regional','Motivacional & Epica',
    'Noche de karaoke','Todo vale');
$songId = $current ? $current['id'] : 0;

// Thumbnail de la canción actual
$init_thumb = '';
if ($current) {
    $init_thumb = $current['thumbnail_url'] ?? '';
    if (!$init_thumb && !empty($current['youtube_url'])) {
        preg_match('/[?&]v=([^&]+)/', $current['youtube_url'], $m);
        if (!empty($m[1])) $init_thumb = 'https://i.ytimg.com/vi/' . $m[1] . '/hqdefault.jpg';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Music Bot</title>
<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide-static@latest/font/lucide.min.css">
<script>
var thumbPlaceholder='data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40"><rect fill="%23282828" width="40" height="40" rx="4"/><path d="M20 12a2 2 0 0 1 2 2v6.5l4.3 2.5a1 1 0 0 1-1 1.7L20.7 22a2 2 0 0 1-1.2-.4A2 2 0 0 1 18 20v-6a2 2 0 0 1 2-2z" fill="%236a6a6a"/></svg>';
var thumbPlaceholderLg='data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 56 56"><rect fill="%23282828" width="56" height="56" rx="4"/><path d="M28 16a2.5 2.5 0 0 1 2.5 2.5v8l5.5 3.2a1.2 1.2 0 0 1-1.2 2.1l-5.5-3.2a2.5 2.5 0 0 1-1.3-.5 2.5 2.5 0 0 1-2.5-2.5v-7.1A2.5 2.5 0 0 1 28 16z" fill="%236a6a6a"/></svg>';
</script>
</head>
<body>
<div class="app">

<!-- ── SIDEBAR ──────────────────────── -->
<aside class="sidebar">
    <div class="sidebar-header">
        <span class="sidebar-logo">Music Bot</span>
        <span class="sidebar-user"><?php echo h(user_email()); ?></span>
    </div>

    <nav class="sidebar-nav">
        <button class="nav-item active" onclick="showPage('home',this)"><i class="icon icon-home nav-icon"></i> Inicio</button>
        <button class="nav-item" onclick="showPage('queue',this)"><i class="icon icon-list-music nav-icon"></i> Cola</button>
        <button class="nav-item" onclick="showPage('lists',this)"><i class="icon icon-star nav-icon"></i> Listas</button>
        <button class="nav-item" onclick="showPage('schedule',this)"><i class="icon icon-clock nav-icon"></i> Horario</button>
        <button class="nav-item" onclick="showPage('ranking',this)"><i class="icon icon-trophy nav-icon"></i> Ranking</button>
        <button class="nav-item" onclick="showPage('playlists',this)"><i class="icon icon-disc-3 nav-icon"></i> Playlists</button>
        <button class="nav-item" onclick="showPage('calendar',this)"><i class="icon icon-calendar nav-icon"></i> Calendario</button>
        <button class="nav-item" onclick="showPage('settings',this)"><i class="icon icon-settings nav-icon"></i> Ajustes</button>
    </nav>

    <div class="sidebar-divider"></div>

    <div class="sidebar-section">
        <div class="sidebar-title">Mis solicitudes</div>
        <ul class="req-list" id="mis-requests">
        <?php foreach ($mis_requests as $r): ?>
            <li class="req-item">
                <span class="req-text"><?php echo h($r['texto']); ?></span>
                <span class="req-badge <?php echo $r['processed'] ? 'done' : 'pending'; ?>"><?php echo $r['processed'] ? 'OK' : '...'; ?></span>
            </li>
        <?php endforeach; ?>
        </ul>
    </div>

    <div class="sidebar-divider"></div>
    <div class="sidebar-np" id="sidebar-np">
        <img class="sidebar-np-art" id="sidebar-np-art" src="<?php echo $init_thumb ?: ''; ?>" alt="" onerror="this.src=thumbPlaceholder">
        <div class="sidebar-np-info">
            <div class="sidebar-np-title" id="sidebar-np-title"><?php echo $current ? h($current['title']) : 'Sin reproduccion'; ?></div>
            <div class="sidebar-np-artist" id="sidebar-np-artist"><?php echo $current ? h($current['artist']) : ''; ?></div>
        </div>
    </div>
    <a href="logout.php" class="btn-logout-sidebar">Cerrar sesion</a>
</aside>

<!-- ── MAIN CONTENT ────────────────── -->
<main class="main">

    <?php if ($msg): ?>
        <div class="alert <?php echo $msg_type; ?>" id="alert-msg"><?php echo h($msg); ?></div>
    <?php endif; ?>

    <!-- PAGE: HOME -->
    <div class="page active" id="page-home">
        <h1 class="page-title">Inicio</h1>

        <!-- Pedir musica -->
        <div class="section">
            <form method="POST" id="request-form" class="request-form">
                <input type="hidden" name="action" value="request">
                <input type="hidden" name="priority" value="normal" id="priority-input">
                <textarea name="texto" id="texto-input" rows="1" maxlength="500" class="request-input"
                          placeholder="Que quieres escuchar?" required
                          onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();this.form.submit()}"></textarea>
                <button type="submit" class="btn-send">Enviar</button>
                <button type="button" class="btn-now-send" onclick="document.getElementById('priority-input').value='now';document.getElementById('request-form').submit()">Ahora</button>
            </form>
        </div>

        <!-- Historial reciente -->
        <div class="section">
            <div class="section-title">Reproducidas recientemente</div>
            <?php if ($historial): ?>
            <ul class="track-list" id="historial-list">
                <?php foreach ($historial as $i => $h2):
                    $thumb = $h2['thumbnail_url'] ?? '';
                    if (!$thumb && !empty($h2['youtube_url'])) {
                        preg_match('/[?&]v=([^&]+)/', $h2['youtube_url'], $hm);
                        if (!empty($hm[1])) $thumb = 'https://i.ytimg.com/vi/' . $hm[1] . '/hqdefault.jpg';
                    }
                ?>
                <li class="track-item row-<?php echo $h2['list_color']; ?>">
                    <span class="track-num"><?php echo $i+1; ?></span>
                    <img class="track-thumb" src="<?php echo $thumb ?: ''; ?>" alt="" onerror="this.src=thumbPlaceholder">
                    <div class="track-info">
                        <div class="track-name"><?php echo h($h2['title']); ?> <span class="badge badge-<?php echo $h2['list_color']; ?>"><?php echo $h2['list_color']; ?></span></div>
                        <div class="track-artist"><?php echo h($h2['artist']); ?></div>
                    </div>
                    <div class="track-votes"><span class="up">+<?php echo $h2['total_up']; ?></span> <span class="down">-<?php echo $h2['total_down']; ?></span></div>
                    <div class="track-actions" style="opacity:1">
                        <?php if (!empty($h2['youtube_url'])): ?>
                        <a href="<?php echo h($h2['youtube_url']); ?>" target="_blank" class="track-btn" title="Abrir en YouTube">▶</a>
                        <?php endif; ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <p class="muted">Sin historial aun</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- PAGE: QUEUE -->
    <div class="page" id="page-queue">
        <h1 class="page-title">Cola de reproduccion</h1>
        <div class="section">
            <div class="btn-group">
                <button class="btn-sm" onclick="accionCola('clear',0,'queue')">Limpiar</button>
                <button class="btn-sm accent" onclick="accionCola('refresh',0,'queue')">Refrescar</button>
            </div>
            <div class="section-title">Siguiente</div>
            <div id="queue-content"><p class="muted">Cargando...</p></div>
            <div class="section-title subdued">Buffer</div>
            <div id="buffer-content"><p class="muted">Cargando...</p></div>
        </div>
    </div>

    <!-- PAGE: LISTS -->
    <div class="page" id="page-lists">
        <h1 class="page-title">Listas de canciones</h1>
        <div class="section">
            <div class="tabs">
                <button class="tab active" onclick="cargarLista('gold',this)">Dorada</button>
                <button class="tab" onclick="cargarLista('green',this)">Verde</button>
                <button class="tab" onclick="cargarLista('white',this)">Blanca</button>
            </div>
            <div id="list-content"><p class="muted">Selecciona una lista</p></div>
        </div>
    </div>

    <!-- PAGE: SCHEDULE -->
    <div class="page" id="page-schedule">
        <h1 class="page-title">Programacion del dia</h1>
        <div class="section">
            <div id="schedule-content"></div>
            <div class="btn-group">
                <button class="btn-sm" onclick="agregarBloque()">+ Agregar bloque</button>
                <button class="btn-sm accent" onclick="guardarSchedule()">Guardar</button>
            </div>
        </div>
    </div>

    <!-- PAGE: RANKING -->
    <div class="page" id="page-ranking">
        <h1 class="page-title">Ranking de DJs</h1>
        <div class="section" id="dj-ranking"><p class="muted">Cargando...</p></div>
    </div>

    <!-- PAGE: PLAYLISTS -->
    <div class="page" id="page-playlists">
        <h1 class="page-title">Playlists</h1>
        <div class="section">
            <div id="playlists-content"><p class="muted">Cargando...</p></div>
            <div id="playlist-detail" class="hidden">
                <button class="btn-sm btn-back" onclick="cerrarPlaylist()"><i class="icon icon-arrow-left"></i> Volver</button>
                <h3 id="playlist-detail-name" class="detail-name"></h3>
                <p class="pl-desc" id="playlist-detail-desc"></p>
                <div id="playlist-detail-songs"></div>
            </div>
        </div>
    </div>

    <!-- PAGE: CALENDAR -->
    <div class="page" id="page-calendar">
        <h1 class="page-title">Calendario</h1>
        <div class="section">
            <div id="calendar-content"><p class="muted">Cargando...</p></div>
            <div id="calendar-day-detail" class="hidden">
                <button class="btn-sm btn-back" onclick="cerrarDia()"><i class="icon icon-arrow-left"></i> Volver</button>
                <h3 id="calendar-day-title" class="detail-title"></h3>
                <div id="calendar-day-songs"></div>
            </div>
        </div>
    </div>

    <!-- PAGE: SETTINGS -->
    <div class="page" id="page-settings">
        <h1 class="page-title">Ajustes</h1>
        <div class="section">
            <div class="control-group">
                <span class="control-label">Modo auto</span>
                <button class="toggle-btn" id="btn-auto-mode" onclick="toggleAutoMode()">Auto</button>
            </div>
            <div class="control-group">
                <span class="control-label">Auto-fill</span>
                <button class="toggle-btn" id="btn-auto-fill" onclick="toggleAutoFill()">Auto-fill</button>
            </div>
            <div class="control-group">
                <span class="control-label">Ambiente</span>
                <select id="web-preset" class="control-select" onchange="postSetting({preset:this.value})">
                    <option value="">-- Auto --</option>
                    <?php foreach ($web_presets as $p): ?>
                    <option value="<?php echo h($p); ?>"><?php echo h($p); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="control-group">
                <span class="control-label">Ciudad</span>
                <input type="text" id="web-city" class="control-input" placeholder="Valdivia" onchange="postSetting({city:this.value})">
            </div>
            <div class="control-group">
                <span class="control-label">Video</span>
                <button class="toggle-btn" id="btn-video" onclick="toggleVideo()"><i class="icon icon-monitor-play"></i> Video</button>
            </div>
        </div>
    </div>

</main>

<!-- ── PLAYER BAR ──────────────────── -->
<footer class="player-bar">
    <!-- Left: song info -->
    <div class="player-left">
        <img class="player-art<?php echo $current ? ' playing' : ''; ?>" id="player-art" src="<?php echo $init_thumb ?: ''; ?>" alt="" onerror="this.src=thumbPlaceholderLg">
        <div class="player-info">
            <div class="player-title" id="p-title"><?php echo $current ? h($current['title']) : 'Sin reproduccion'; ?></div>
            <div class="player-artist" id="p-artist"><?php echo $current ? h($current['artist']) : ''; ?>
                <?php if ($current): ?><span class="badge badge-<?php echo $song_list; ?>" id="p-badge"><?php echo $song_list; ?></span><?php endif; ?>
                <?php if ($requested_by_display): ?><span class="player-requested"><?php echo h($requested_by_display); ?></span><?php endif; ?>
            </div>
            <div class="ai-comment-bar<?php echo $ai_comment ? ' visible' : ''; ?>" id="p-ai"><?php echo $ai_comment ? h($ai_comment) : ''; ?></div>
        </div>
        <span class="streak-bar<?php echo $streak_init >= 10 ? ' legendary' : ($streak_init >= 5 ? ' combo' : ''); ?>" id="p-streak"><?php echo $streak_init >= 3 ? "x{$streak_init}" . ($streak_init >= 10 ? ' LEGENDARIA!' : ($streak_init >= 5 ? ' COMBO!' : '')) : ''; ?></span>
    </div>

    <!-- Center: controls + votes + reactions -->
    <div class="player-center">
        <div class="player-controls">
            <button class="player-btn" onclick="accionCola('pause',0,'queue')" title="Pause"><i class="icon icon-pause"></i></button>
            <button class="player-btn-vote<?php echo $my_vote === 'up' ? ' active-up' : ''; ?>" id="btn-up" onclick="votar(<?php echo $songId; ?>,'up')"><i class="icon icon-thumbs-up"></i> <span id="count-up"><?php echo $current ? $current['total_up'] : 0; ?></span></button>
            <button class="player-btn-vote<?php echo $my_vote === 'down' ? ' active-down' : ''; ?>" id="btn-down" onclick="votar(<?php echo $songId; ?>,'down')"><i class="icon icon-thumbs-down"></i> <span id="count-down"><?php echo $current ? $current['total_down'] : 0; ?></span></button>
            <button class="player-btn" onclick="accionCola('skip',0,'queue')" title="Skip"><i class="icon icon-skip-forward"></i></button>
        </div>
        <div class="player-reactions">
            <?php foreach ($reaction_emojis as $key => $emoji): ?>
            <button class="player-react<?php echo in_array($key, $my_reactions) ? ' reacted' : ''; ?>" id="react-<?php echo $key; ?>" onclick="reaccionar(<?php echo $songId; ?>,'<?php echo $key; ?>')"><?php echo $emoji; ?><span class="rc" id="rcount-<?php echo $key; ?>"><?php echo isset($reactions[$key]) ? $reactions[$key] : ''; ?></span></button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Right: volume -->
    <div class="player-right">
        <button class="vol-btn" id="btn-mute" onclick="toggleMute()"><i class="icon icon-volume-2" id="mute-icon"></i></button>
        <input type="range" class="vol-slider" id="vol-slider" min="0" max="100" value="80" oninput="cambiarVolumen(this.value)">
        <span class="vol-val" id="vol-val">80%</span>
    </div>
</footer>

</div>

<script>
var userId = <?php echo user_id(); ?>;
var songId = <?php echo $songId; ?>;
var volTimer = null;
var thumbPlaceholder = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 40 40%22%3E%3Crect fill=%22%23282828%22 width=%2240%22 height=%2240%22/%3E%3Ctext x=%2220%22 y=%2226%22 text-anchor=%22middle%22 fill=%22%236a6a6a%22 font-size=%2216%22%3E%E2%99%AB%3C/text%3E%3C/svg%3E';
var thumbPlaceholderLg = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 56 56%22%3E%3Crect fill=%22%23282828%22 width=%2256%22 height=%2256%22/%3E%3Ctext x=%2228%22 y=%2234%22 text-anchor=%22middle%22 fill=%22%236a6a6a%22 font-size=%2224%22%3E%E2%99%AB%3C/text%3E%3C/svg%3E';

function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

function ytThumb(url, thumbUrl) {
    if (thumbUrl) return thumbUrl;
    if (!url) return thumbPlaceholder;
    var m = url.match(/[?&]v=([^&]+)/);
    return m ? 'https://i.ytimg.com/vi/' + m[1] + '/hqdefault.jpg' : thumbPlaceholder;
}

function showPage(id, btn) {
    document.querySelectorAll('.page').forEach(function(p) { p.classList.remove('active'); });
    document.querySelectorAll('.nav-item').forEach(function(n) { n.classList.remove('active'); });
    document.getElementById('page-' + id).classList.add('active');
    if (btn) btn.classList.add('active');
    if (id === 'queue') cargarCola();
    if (id === 'lists') cargarLista('gold', document.querySelector('#page-lists .tab.active'));
    if (id === 'ranking') cargarRanking();
    if (id === 'playlists') cargarPlaylists();
    if (id === 'calendar') cargarCalendario();
    if (id === 'schedule') cargarSchedule();
    if (id === 'settings') cargarVolumen();
}

function postSetting(obj) {
    fetch('panel.php?ajax=volume', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(obj) });
}

function cambiarVolumen(v) {
    document.getElementById('vol-val').textContent = v + '%';
    clearTimeout(volTimer);
    volTimer = setTimeout(function() { postSetting({volume:parseInt(v)}); }, 300);
}

function toggleMute() {
    var b = document.getElementById('btn-mute'), s = document.getElementById('vol-slider');
    var m = b.classList.toggle('muted'); s.disabled = m;
    var ic = document.getElementById('mute-icon');
    if(ic){ic.className=m?'icon icon-volume-x':'icon icon-volume-2';}
    postSetting({muted:m});
}

function toggleVideo() {
    var b = document.getElementById('btn-video');
    postSetting({video: b.classList.toggle('on')});
}

function toggleAutoMode() {
    var b = document.getElementById('btn-auto-mode');
    postSetting({auto_mode: b.classList.toggle('on')});
}

function toggleAutoFill() {
    var b = document.getElementById('btn-auto-fill');
    postSetting({auto_fill: b.classList.toggle('on')});
}

function cargarVolumen() {
    fetch('panel.php?ajax=volume').then(function(r){return r.json()}).then(function(d) {
        var s=document.getElementById('vol-slider'), l=document.getElementById('vol-val'),
            b=document.getElementById('btn-mute'), v=document.getElementById('btn-video'),
            p=document.getElementById('web-preset'), c=document.getElementById('web-city'),
            am=document.getElementById('btn-auto-mode'), af=document.getElementById('btn-auto-fill');
        if(s)s.value=d.volume; if(l)l.textContent=d.volume+'%';
        if(b&&d.muted){b.classList.add('muted');var mi=document.getElementById('mute-icon');if(mi)mi.className='icon icon-volume-x';if(s)s.disabled=true;}
        if(v&&d.video)v.classList.add('on');
        if(p&&d.preset)p.value=d.preset;
        if(c&&d.city)c.value=d.city;
        if(am&&d.auto_mode)am.classList.add('on');
        if(af&&d.auto_fill)af.classList.add('on');
    });
}

function votar(sid, tipo) {
    if(!sid)return;
    var f=new FormData(); f.append('action','vote'); f.append('song_id',sid); f.append('vote',tipo);
    fetch('panel.php',{method:'POST',body:f}).then(function(){actualizar()});
}

function reaccionar(sid, r) {
    if(!sid)return;
    var f=new FormData(); f.append('action','react'); f.append('song_id',sid); f.append('reaction',r);
    fetch('panel.php',{method:'POST',body:f,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){return r.json()}).then(function(d) {
        var c=d.counts||{}, my=d.my_reactions||[], keys=['fire','dance','heart','sleep','skull'];
        keys.forEach(function(k) {
            var ce=document.getElementById('rcount-'+k), be=document.getElementById('react-'+k);
            if(ce) ce.textContent=c[k]||'';
            if(be) { my.indexOf(k)>=0 ? be.classList.add('reacted') : be.classList.remove('reacted'); }
        });
    });
}

function actualizar() {
    fetch('panel.php?ajax=status').then(function(r){return r.json()}).then(function(d) {
        var np=d.current;
        if(np) {
            songId=np.id;
            document.getElementById('p-title').textContent=np.title;
            document.getElementById('p-artist').innerHTML=esc(np.artist)+
                ' <span class="badge badge-'+(d.song_list||'white')+'">'+(d.song_list||'')+'</span>'+
                (d.requested_by ? ' <span class="player-requested">'+esc(d.requested_by)+'</span>' : '');
            var ai=document.getElementById('p-ai');
            if(ai){ai.textContent=d.ai_comment||'';d.ai_comment?ai.classList.add('visible'):ai.classList.remove('visible');}

            // Thumbnail en player bar
            var art=document.getElementById('player-art');
            if(art){var th=ytThumb(np.youtube_url,d.thumbnail_url);art.src=th;}

            // Sidebar now-playing
            var sart=document.getElementById('sidebar-np-art');
            if(sart){sart.src=ytThumb(np.youtube_url,d.thumbnail_url);}
            var stitle=document.getElementById('sidebar-np-title');
            if(stitle)stitle.textContent=np.title;
            var sartist=document.getElementById('sidebar-np-artist');
            if(sartist)sartist.textContent=np.artist;

            // Ecualizador
            var artEl=document.getElementById('player-art');
            if(artEl)artEl.classList.add('playing');

            var cu=document.getElementById('count-up'),cd=document.getElementById('count-down');
            if(cu)cu.textContent=np.total_up; if(cd)cd.textContent=np.total_down;

            var bu=document.getElementById('btn-up'),bd=document.getElementById('btn-down');
            if(bu){bu.className='player-btn-vote'+(d.my_vote==='up'?' active-up':'');bu.setAttribute('onclick',"votar("+np.id+",'up')");}
            if(bd){bd.className='player-btn-vote'+(d.my_vote==='down'?' active-down':'');bd.setAttribute('onclick',"votar("+np.id+",'down')");}

            var rx=d.reactions||{},my=d.my_reactions||[];
            ['fire','dance','heart','sleep','skull'].forEach(function(k){
                var ce=document.getElementById('rcount-'+k),be=document.getElementById('react-'+k);
                if(ce)ce.textContent=rx[k]||'';
                if(be){be.setAttribute('onclick',"reaccionar("+np.id+",'"+k+"')");my.indexOf(k)>=0?be.classList.add('reacted'):be.classList.remove('reacted');}
            });

            var sk=document.getElementById('p-streak'),sv=d.streak||0;
            if(sk)sk.textContent=sv>=3?'x'+sv+(sv>=10?' LEGENDARIA!':(sv>=5?' COMBO!':'')):'';
        } else {
            var artEl=document.getElementById('player-art');
            if(artEl)artEl.classList.remove('playing');
        }
        // Requests
        var reqs=d.requests,rl=document.getElementById('mis-requests');
        if(reqs&&rl){
            var h='';reqs.forEach(function(r){h+='<li class="req-item"><span class="req-text">'+esc(r.texto)+'</span><span class="req-badge '+(r.processed?'done':'pending')+'">'+(r.processed?'OK':'...')+'</span></li>';});
            rl.innerHTML=h;
        }
    });
}

function cargarLista(color, btn) {
    document.querySelectorAll('#page-lists .tab').forEach(function(t){t.classList.remove('active')});
    if(btn)btn.classList.add('active');
    var c=document.getElementById('list-content');c.innerHTML='<p class="muted">Cargando...</p>';
    fetch('panel.php?ajax=list&color='+color).then(function(r){return r.json()}).then(function(songs){
        if(!songs||!songs.length){c.innerHTML='<p class="muted">Vacia</p>';return;}
        var h='<table class="data-table"><thead><tr><th>Cancion</th><th>Artista</th><th>Score</th><th>Veces</th></tr></thead><tbody>';
        songs.forEach(function(s){h+='<tr class="row-'+(s.list_color||color)+'"><td>'+esc(s.title)+'</td><td class="col-secondary">'+esc(s.artist)+'</td><td class="votes-cell"><span class="up">+'+s.total_up+'</span><span class="down">-'+s.total_down+'</span></td><td>'+s.times_played+'</td></tr>';});
        c.innerHTML=h+'</tbody></table>';
    });
}

// ── Cola ──
function sourceBadge(s) {
    if (s.requested_by) return '<span class="track-by">' + esc(s.requested_by).split('@')[0] + '</span>';
    var r = (s.reason || '').toLowerCase();
    if (r.indexOf('dorad') >= 0) return '<span class="badge badge-gold">Dorada</span>';
    if (r.indexOf('direct') >= 0) return '<span class="badge badge-green">Directo</span>';
    return '<span class="badge badge-white">IA</span>';
}
function cargarCola() {
    fetch('panel.php?ajax=queue').then(function(r){return r.json()}).then(function(d) {
        var qd=document.getElementById('queue-content'), bd=document.getElementById('buffer-content');
        if(!d.queue||!d.queue.length){qd.innerHTML='<p class="muted">Cola vacia</p>';}else{
            var h='<ul class="track-list">';d.queue.forEach(function(s,i){
                var th=ytThumb(null,s.thumbnail_url||'');
                h+='<li class="track-item"><span class="track-num">'+(i+1)+'</span>'
                  +'<img class="track-thumb" src="'+th+'" alt="" onerror="this.src=thumbPlaceholder">'
                  +'<div class="track-info"><div class="track-name">'+esc(s.title)
                  +(s.priority==='now'?' <span class="badge-priority">AHORA</span>':'')
                  +' '+sourceBadge(s)+'</div>'
                  +'<div class="track-artist">'+esc(s.artist)+'</div></div>'
                  +'<div class="track-actions">'
                  +(i>0?'<button class="track-btn" onclick="accionCola(\'move_up\','+i+',\'queue\')"><i class=\"icon icon-chevron-up\"></i></button>':'')
                  +'<button class="track-btn danger" onclick="accionCola(\'remove\','+i+',\'queue\')"><i class=\"icon icon-x\"></i></button></div></li>';
            });qd.innerHTML=h+'</ul>';}
        if(!d.buffer||!d.buffer.length){bd.innerHTML='<p class="muted">Buffer vacio</p>';}else{
            var h='<ul class="track-list">';d.buffer.forEach(function(s,i){
                var th=ytThumb(null,s.thumbnail_url||'');
                h+='<li class="track-item"><span class="track-num">'+(i+1)+'</span>'
                  +'<img class="track-thumb faded" src="'+th+'" alt="" onerror="this.src=thumbPlaceholder">'
                  +'<div class="track-info"><div class="track-name secondary">'+esc(s.title)
                  +' '+sourceBadge(s)+'</div>'
                  +'<div class="track-artist">'+esc(s.artist)+'</div></div>'
                  +'<div class="track-actions">'
                  +'<button class="track-btn danger" onclick="accionCola(\'remove\','+i+',\'buffer\')"><i class=\"icon icon-x\"></i></button></div></li>';
            });bd.innerHTML=h+'</ul>';}
    });
}
function accionCola(a,p,s){var f=new FormData();f.append('action','queue_action');f.append('queue_action',a);f.append('position',p||0);f.append('source',s||'queue');fetch('panel.php',{method:'POST',body:f,headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(){setTimeout(cargarCola,1000)});}

// ── Schedule ──
var presetNames=<?php echo json_encode($web_presets); ?>, scheduleData=[];
function renderSchedule(){var c=document.getElementById('schedule-content');if(!scheduleData.length){c.innerHTML='<p class="muted">Sin programacion</p>';return;}var h='';scheduleData.forEach(function(s,i){var o='';presetNames.forEach(function(p){o+='<option'+(p===s.preset?' selected':'')+'>'+esc(p)+'</option>';});h+='<div class="sch-row"><select class="sch-sel hour" onchange="scheduleData['+i+'].hour_start=+this.value">';for(var x=0;x<24;x++)h+='<option value="'+x+'"'+(x===s.hour_start?' selected':'')+'>'+(x<10?'0':'')+x+':00</option>';h+='</select><span class="sch-sep">a</span><select class="sch-sel hour" onchange="scheduleData['+i+'].hour_end=+this.value">';for(var x=0;x<24;x++)h+='<option value="'+x+'"'+(x===s.hour_end?' selected':'')+'>'+(x<10?'0':'')+x+':00</option>';h+='</select><select class="sch-sel preset" onchange="scheduleData['+i+'].preset=this.value">'+o+'</select><button class="track-btn danger" onclick="scheduleData.splice('+i+',1);renderSchedule()"><i class=\"icon icon-x\"></i></button></div>';});c.innerHTML=h;}
function agregarBloque(){var l=scheduleData.length?scheduleData[scheduleData.length-1].hour_end:8;scheduleData.push({hour_start:l,hour_end:Math.min(l+2,23),preset:'Todo vale',day_of_week:'all'});renderSchedule();}
function guardarSchedule(){fetch('panel.php?ajax=schedule',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({schedule:scheduleData})});}
function cargarSchedule(){fetch('panel.php?ajax=schedule').then(function(r){return r.json()}).then(function(d){scheduleData=d||[];renderSchedule();});}

// ── Ranking ──
function cargarRanking(){fetch('panel.php?ajax=ranking').then(function(r){return r.json()}).then(function(rk){var c=document.getElementById('dj-ranking');if(!rk||!rk.length){c.innerHTML='<p class="muted">Sin datos</p>';return;}var h='<table class="data-table"><thead><tr><th></th><th>DJ</th><th><i class="icon icon-crown"></i></th><th><i class="icon icon-sprout"></i></th><th>Pts</th></tr></thead><tbody>';rk.forEach(function(r,i){var m=i===0?'<i class="icon icon-medal rank-gold"></i>':i===1?'<i class="icon icon-medal rank-silver"></i>':i===2?'<i class="icon icon-medal rank-bronze"></i>':(i+1);h+='<tr><td class="rank-medal">'+m+'</td><td>'+esc(r.email)+'</td><td>'+r.gold+'</td><td>'+(r.green_count||0)+'</td><td class="col-bold">'+r.score+'</td></tr>';});c.innerHTML=h+'</tbody></table>';});}

// ── Playlists ──
function cargarPlaylists(){fetch('panel.php?ajax=playlists').then(function(r){return r.json()}).then(function(pl){var c=document.getElementById('playlists-content');if(!pl||!pl.length){c.innerHTML='<p class="muted">Sin playlists</p>';return;}var h='';pl.forEach(function(p){h+='<div class="playlist-card" onclick="verPlaylist('+p.id+')"><span class="pl-name">'+esc(p.name)+'</span><span class="pl-meta">'+(p.song_count||0)+' canciones</span></div>';});c.innerHTML=h;});}
function verPlaylist(id){fetch('panel.php?ajax=playlist&id='+id).then(function(r){return r.json()}).then(function(d){if(!d)return;document.getElementById('playlists-content').classList.add('hidden');var det=document.getElementById('playlist-detail');det.classList.remove('hidden');document.getElementById('playlist-detail-name').textContent=d.name;document.getElementById('playlist-detail-desc').textContent=d.description||'';var ss=d.songs||[];if(!ss.length){document.getElementById('playlist-detail-songs').innerHTML='<p class="muted">Vacia</p>';return;}var h='<ul class="track-list">';ss.forEach(function(s,i){var th=ytThumb(s.youtube_url||'',s.thumbnail_url||'');h+='<li class="track-item"><span class="track-num">'+(i+1)+'</span><img class="track-thumb" src="'+th+'" alt="" onerror="this.src=thumbPlaceholder"><div class="track-info"><div class="track-name">'+esc(s.title)+'</div><div class="track-artist">'+esc(s.artist)+'</div></div><div class="track-votes">'+(s.votes_net>0?'+':'')+s.votes_net+'</div></li>';});document.getElementById('playlist-detail-songs').innerHTML=h+'</ul>';});}
function cerrarPlaylist(){document.getElementById('playlist-detail').classList.add('hidden');document.getElementById('playlists-content').classList.remove('hidden');}

// ── Calendario ──
function cargarCalendario(){fetch('panel.php?ajax=history_calendar&days=30').then(function(r){return r.json()}).then(function(dd){var c=document.getElementById('calendar-content');if(!dd||!dd.length){c.innerHTML='<p class="muted">Sin historial</p>';return;}var h='<div class="cal-grid">';dd.forEach(function(d){h+='<div class="cal-day" onclick="verDia(\''+esc(d.play_date)+'\')"><span class="cal-date">'+esc(d.play_date)+'</span><span class="cal-count">'+d.song_count+'</span></div>';});c.innerHTML=h+'</div>';});}
function verDia(dt){fetch('panel.php?ajax=history_day&date='+dt).then(function(r){return r.json()}).then(function(ss){document.getElementById('calendar-content').classList.add('hidden');var det=document.getElementById('calendar-day-detail');det.classList.remove('hidden');document.getElementById('calendar-day-title').textContent=dt;if(!ss||!ss.length){document.getElementById('calendar-day-songs').innerHTML='<p class="muted">Vacio</p>';return;}var h='<ul class="track-list">';ss.forEach(function(s,i){var th=ytThumb(null,s.thumbnail_url||'');h+='<li class="track-item row-'+(s.list_color||'white')+'"><span class="track-num">'+(i+1)+'</span><img class="track-thumb" src="'+th+'" alt="" onerror="this.src=thumbPlaceholder"><div class="track-info"><div class="track-name">'+esc(s.title)+' <span class="badge badge-'+(s.list_color||'white')+'">'+(s.list_color||'')+'</span></div><div class="track-artist">'+esc(s.artist)+'</div></div><div class="track-votes"><span class="up">+'+s.total_up+'</span> <span class="down">-'+s.total_down+'</span></div></li>';});document.getElementById('calendar-day-songs').innerHTML=h+'</ul>';});}
function cerrarDia(){document.getElementById('calendar-day-detail').classList.add('hidden');document.getElementById('calendar-content').classList.remove('hidden');}

// ── Init ──
setInterval(actualizar, 8000);
setInterval(cargarCola, 10000);
cargarVolumen();
cargarLista('gold', document.querySelector('#page-lists .tab.active'));
var al=document.getElementById('alert-msg');if(al)setTimeout(function(){al.style.display='none';},3000);
</script>
</body>
</html>
