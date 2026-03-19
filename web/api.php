<?php
/**
 * API REST para la app Python.
 *
 * Endpoints:
 *   GET  ?action=pending_requests  - Solicitudes no procesadas (incluye priority)
 *   POST ?action=mark_processed    - Marcar solicitudes como procesadas
 *   POST ?action=now_playing       - Setear canción actual (guarda anterior en catálogo)
 *   GET  ?action=current           - Obtener canción actual + votos
 *   GET  ?action=stats             - Estadísticas generales + conteos por lista
 *   POST ?action=skip              - Registrar skip de canción
 *   GET  ?action=check_blacklist   - Verificar si canción está en lista negra
 *   GET  ?action=list              - Canciones del catálogo por color
 *   GET  ?action=dj_ranking        - Top usuarios por canciones doradas/verdes
 *   POST ?action=react             - Registrar reacción rápida
 *   POST ?action=ai_comment        - Guardar comentario de IA
 *   GET  ?action=gold_songs        - Canciones de lista dorada (auto-relleno)
 *
 * Autenticación: Header X-API-Key o parámetro api_key
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/migrations.php';

header('Content-Type: application/json; charset=utf-8');

if (!validar_api_key()) {
    http_response_code(401);
    echo json_encode(['error' => 'API key invalida']);
    exit;
}

$pdo = db();
run_migrations($pdo);
$action = $_GET['action'] ?? '';

switch ($action) {

    // Obtener solicitudes pendientes de los usuarios
    case 'pending_requests':
        $stmt = $pdo->query("
            SELECT r.id, r.texto, r.priority, r.created_at, u.email
            FROM requests r
            JOIN users u ON u.id = r.user_id
            WHERE r.processed = 0
            ORDER BY
                CASE WHEN r.priority = 'now' THEN 0 ELSE 1 END,
                r.created_at ASC
        ");
        echo json_encode($stmt->fetchAll());
        break;

    // Marcar solicitudes como procesadas
    case 'mark_processed':
        $input = json_decode(file_get_contents('php://input'), true);
        $ids = $input['ids'] ?? [];
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE requests SET processed = 1 WHERE id IN ($placeholders)");
            $stmt->execute(array_map('intval', $ids));
        }
        echo json_encode(['ok' => true, 'updated' => count($ids)]);
        break;

    // Setear canción actual (la app Python informa qué está sonando)
    case 'now_playing':
        $input = json_decode(file_get_contents('php://input'), true);
        $title = trim($input['title'] ?? '');
        $artist = trim($input['artist'] ?? '');
        $url = trim($input['url'] ?? '');
        $requested_by = trim($input['requested_by'] ?? '');
        $thumbnail_url = trim($input['thumbnail_url'] ?? '');

        if (!$title) {
            http_response_code(400);
            echo json_encode(['error' => 'title requerido']);
            break;
        }

        // Guardar canción anterior en catálogo antes de desactivarla
        $prev = $pdo->query("SELECT * FROM now_playing WHERE active = 1 ORDER BY id DESC LIMIT 1")->fetch();
        if ($prev) {
            $prev_thumb = $prev['thumbnail_url'] ?? '';
            $stmt = $pdo->prepare("
                INSERT INTO song_catalog (title, artist, total_up, total_down, times_played, requested_by, thumbnail_url)
                VALUES (?, ?, ?, ?, 1, ?, ?)
                ON CONFLICT(title, artist) DO UPDATE SET
                    total_up = song_catalog.total_up + excluded.total_up,
                    total_down = song_catalog.total_down + excluded.total_down,
                    times_played = song_catalog.times_played + 1,
                    requested_by = CASE WHEN excluded.requested_by != '' THEN excluded.requested_by ELSE song_catalog.requested_by END,
                    thumbnail_url = CASE WHEN excluded.thumbnail_url != '' THEN excluded.thumbnail_url ELSE song_catalog.thumbnail_url END
            ");
            $stmt->execute([
                $prev['title'], $prev['artist'],
                $prev['total_up'], $prev['total_down'],
                $prev['requested_by'] ?? '',
                $prev_thumb
            ]);
            recalcular_lista($pdo, $prev['title'], $prev['artist']);
        }

        // Desactivar canciones previas
        $pdo->exec("UPDATE now_playing SET active = 0 WHERE active = 1");

        $stmt = $pdo->prepare("
            INSERT INTO now_playing (title, artist, youtube_url, active, requested_by, thumbnail_url)
            VALUES (?, ?, ?, 1, ?, ?)
        ");
        $stmt->execute([$title, $artist, $url, $requested_by, $thumbnail_url]);

        echo json_encode([
            'ok' => true,
            'id' => $pdo->lastInsertId(),
            'title' => $title,
            'artist' => $artist
        ]);
        break;

    // Obtener canción actual con votos
    case 'current':
        $current = $pdo->query("
            SELECT * FROM now_playing WHERE active = 1 ORDER BY id DESC LIMIT 1
        ")->fetch();

        if ($current) {
            echo json_encode($current);
        } else {
            echo json_encode(null);
        }
        break;

    // Estadísticas: votos, solicitudes, usuarios + conteos por lista
    case 'stats':
        $users = $pdo->query("SELECT COUNT(*) as n FROM users")->fetch()['n'];
        $requests_total = $pdo->query("SELECT COUNT(*) as n FROM requests")->fetch()['n'];
        $requests_pending = $pdo->query("SELECT COUNT(*) as n FROM requests WHERE processed = 0")->fetch()['n'];
        $songs_played = $pdo->query("SELECT COUNT(*) as n FROM now_playing")->fetch()['n'];

        // Conteos por lista de color
        $gold_count = $pdo->query("SELECT COUNT(*) as n FROM song_catalog WHERE list_color = 'gold'")->fetch()['n'];
        $green_count = $pdo->query("SELECT COUNT(*) as n FROM song_catalog WHERE list_color = 'green'")->fetch()['n'];
        $black_count = $pdo->query("SELECT COUNT(*) as n FROM song_catalog WHERE list_color = 'black'")->fetch()['n'];

        // Top canciones por votos netos
        $top = $pdo->query("
            SELECT title, artist, (total_up - total_down) as score, total_up, total_down
            FROM now_playing
            ORDER BY score DESC
            LIMIT 10
        ")->fetchAll();

        echo json_encode([
            'users' => $users,
            'requests_total' => $requests_total,
            'requests_pending' => $requests_pending,
            'songs_played' => $songs_played,
            'gold_count' => $gold_count,
            'green_count' => $green_count,
            'black_count' => $black_count,
            'top_songs' => $top
        ]);
        break;

    // Registrar skip de canción
    case 'skip':
        $input = json_decode(file_get_contents('php://input'), true);
        $song_id = (int)($input['song_id'] ?? 0);
        $reason = trim($input['reason'] ?? '');

        if (!$song_id) {
            http_response_code(400);
            echo json_encode(['error' => 'song_id requerido']);
            break;
        }

        // Obtener datos de la canción
        $stmt = $pdo->prepare("SELECT * FROM now_playing WHERE id = ?");
        $stmt->execute([$song_id]);
        $song = $stmt->fetch();

        if (!$song) {
            http_response_code(404);
            echo json_encode(['error' => 'Cancion no encontrada']);
            break;
        }

        // Guardar/actualizar en catálogo e incrementar times_skipped
        $stmt = $pdo->prepare("
            INSERT INTO song_catalog (title, artist, total_up, total_down, times_played, times_skipped, requested_by)
            VALUES (?, ?, ?, ?, 1, 1, '')
            ON CONFLICT(title, artist) DO UPDATE SET
                total_up = song_catalog.total_up + excluded.total_up,
                total_down = song_catalog.total_down + excluded.total_down,
                times_skipped = song_catalog.times_skipped + 1
        ");
        $stmt->execute([$song['title'], $song['artist'], $song['total_up'], $song['total_down']]);

        // Verificar si debe ir a blacklist
        $blacklisted = false;
        if ($reason === 'downvotes') {
            $stmt = $pdo->prepare("SELECT times_skipped, list_color FROM song_catalog WHERE LOWER(title) = LOWER(?) AND LOWER(artist) = LOWER(?)");
            $stmt->execute([$song['title'], $song['artist']]);
            $catalog = $stmt->fetch();

            if ($catalog && $catalog['times_skipped'] >= 2 && $catalog['list_color'] !== 'black') {
                $stmt = $pdo->prepare("UPDATE song_catalog SET list_color = 'black' WHERE LOWER(title) = LOWER(?) AND LOWER(artist) = LOWER(?)");
                $stmt->execute([$song['title'], $song['artist']]);
                $blacklisted = true;
            }
        }

        echo json_encode([
            'ok' => true,
            'blacklisted' => $blacklisted,
            'title' => $song['title'],
            'artist' => $song['artist']
        ]);
        break;

    // Verificar si una canción está en lista negra
    case 'check_blacklist':
        $title = trim($_GET['title'] ?? '');
        $artist = trim($_GET['artist'] ?? '');

        $stmt = $pdo->prepare("SELECT list_color FROM song_catalog WHERE LOWER(title) = LOWER(?) AND LOWER(artist) = LOWER(?)");
        $stmt->execute([$title, $artist]);
        $row = $stmt->fetch();

        $is_blacklisted = $row && $row['list_color'] === 'black';
        echo json_encode([
            'blacklisted' => $is_blacklisted,
            'list_color' => $row ? $row['list_color'] : 'white'
        ]);
        break;

    // Canciones del catálogo por color
    case 'list':
        $color = trim($_GET['color'] ?? 'all');
        if ($color === 'all') {
            $stmt = $pdo->query("
                SELECT title, artist, total_up, total_down, (total_up - total_down) as score,
                       times_played, times_skipped, list_color
                FROM song_catalog
                WHERE list_color != 'black'
                ORDER BY score DESC
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT title, artist, total_up, total_down, (total_up - total_down) as score,
                       times_played, times_skipped, list_color
                FROM song_catalog
                WHERE list_color = ?
                ORDER BY score DESC
            ");
            $stmt->execute([$color]);
        }
        echo json_encode($stmt->fetchAll());
        break;

    // Ranking de DJs: top usuarios por canciones doradas/verdes
    case 'dj_ranking':
        $ranking = $pdo->query("
            SELECT sc.requested_by as email,
                   SUM(CASE WHEN sc.list_color = 'gold' THEN 1 ELSE 0 END) as gold,
                   SUM(CASE WHEN sc.list_color = 'green' THEN 1 ELSE 0 END) as green,
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
        break;

    // Registrar reacción rápida
    case 'react':
        $input = json_decode(file_get_contents('php://input'), true);
        $user_id = (int)($input['user_id'] ?? 0);
        $song_id = (int)($input['song_id'] ?? 0);
        $reaction = trim($input['reaction'] ?? '');

        $valid_reactions = ['fire', 'dance', 'heart', 'sleep', 'skull'];
        if (!$song_id || !$user_id || !in_array($reaction, $valid_reactions)) {
            http_response_code(400);
            echo json_encode(['error' => 'Parametros invalidos']);
            break;
        }

        // Toggle: si ya existe, eliminar; si no, insertar
        $stmt = $pdo->prepare("SELECT id FROM reactions WHERE user_id = ? AND song_id = ? AND reaction = ?");
        $stmt->execute([$user_id, $song_id, $reaction]);
        $exists = $stmt->fetch();

        if ($exists) {
            $stmt = $pdo->prepare("DELETE FROM reactions WHERE id = ?");
            $stmt->execute([$exists['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO reactions (user_id, song_id, reaction) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $song_id, $reaction]);
        }

        // Devolver conteos actualizados
        $stmt = $pdo->prepare("
            SELECT reaction, COUNT(*) as count
            FROM reactions WHERE song_id = ?
            GROUP BY reaction
        ");
        $stmt->execute([$song_id]);
        $counts = [];
        foreach ($stmt->fetchAll() as $r) {
            $counts[$r['reaction']] = (int)$r['count'];
        }

        // Reacciones del usuario actual
        $stmt = $pdo->prepare("SELECT reaction FROM reactions WHERE user_id = ? AND song_id = ?");
        $stmt->execute([$user_id, $song_id]);
        $my_reactions = array_column($stmt->fetchAll(), 'reaction');

        echo json_encode([
            'ok' => true,
            'counts' => $counts,
            'my_reactions' => $my_reactions
        ]);
        break;

    // Guardar comentario de IA sobre canción actual
    case 'ai_comment':
        $input = json_decode(file_get_contents('php://input'), true);
        $song_id = (int)($input['song_id'] ?? 0);
        $comment = trim($input['comment'] ?? '');

        if (!$song_id || !$comment) {
            http_response_code(400);
            echo json_encode(['error' => 'song_id y comment requeridos']);
            break;
        }

        // Eliminar comentarios anteriores de esta canción
        $stmt = $pdo->prepare("DELETE FROM ai_comments WHERE song_id = ?");
        $stmt->execute([$song_id]);

        $stmt = $pdo->prepare("INSERT INTO ai_comments (song_id, comment) VALUES (?, ?)");
        $stmt->execute([$song_id, $comment]);

        echo json_encode(['ok' => true]);
        break;

    // Canciones de lista dorada (para auto-relleno)
    case 'gold_songs':
        $stmt = $pdo->query("
            SELECT title, artist, (total_up - total_down) as score, youtube_url, thumbnail_url
            FROM song_catalog
            WHERE list_color = 'gold'
            ORDER BY score DESC
        ");
        echo json_encode($stmt->fetchAll());
        break;

    // Leer/escribir volumen y mute
    case 'volume':
        // Crear tabla si no existe (para api.php independiente de panel.php)
        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_settings (
            key TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            if (isset($input['volume'])) {
                $vol = max(0, min(100, (int)$input['volume']));
                $pdo->prepare("INSERT INTO bot_settings (key, value, updated_at) VALUES ('volume', ?, datetime('now'))
                    ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
                    ->execute([$vol]);
            }
            if (isset($input['muted'])) {
                $m = $input['muted'] ? '1' : '0';
                $pdo->prepare("INSERT INTO bot_settings (key, value, updated_at) VALUES ('muted', ?, datetime('now'))
                    ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
                    ->execute([$m]);
            }
            if (isset($input['video'])) {
                $v = $input['video'] ? '1' : '0';
                $pdo->prepare("INSERT INTO bot_settings (key, value, updated_at) VALUES ('video', ?, datetime('now'))
                    ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
                    ->execute([$v]);
            }
            foreach (['preset', 'city'] as $k) {
                if (isset($input[$k]) && trim($input[$k]) !== '') {
                    $pdo->prepare("INSERT INTO bot_settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
                        ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
                        ->execute([$k, trim($input[$k])]);
                }
            }
            foreach (['auto_mode', 'auto_fill'] as $k) {
                if (isset($input[$k])) {
                    $v = $input[$k] ? '1' : '0';
                    $pdo->prepare("INSERT INTO bot_settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
                        ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
                        ->execute([$k, $v]);
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
        echo json_encode([
            'volume' => $vol ? (int)$vol['value'] : 80,
            'muted' => $muted ? (bool)(int)$muted['value'] : false,
            'video' => $video ? (bool)(int)$video['value'] : false,
            'preset' => $preset ? $preset['value'] : '',
            'city' => $city ? $city['value'] : '',
            'auto_mode' => $auto_mode ? (bool)(int)$auto_mode['value'] : true,
            'auto_fill' => $auto_fill ? (bool)(int)$auto_fill['value'] : true,
        ]);
        break;

    // Python sincroniza su cola + buffer al DB
    case 'sync_queue':
        $input = json_decode(file_get_contents('php://input'), true);
        $queue = $input['queue'] ?? [];
        $buffer = $input['buffer'] ?? [];

        $pdo->exec("DELETE FROM queue_songs");

        $stmt = $pdo->prepare("INSERT INTO queue_songs (title, artist, reason, position, priority, requested_by, source, thumbnail_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($queue as $i => $s) {
            $stmt->execute([
                $s['titulo'] ?? $s['title'] ?? '?',
                $s['artista'] ?? $s['artist'] ?? '?',
                $s['razon'] ?? $s['reason'] ?? '',
                $i,
                $s['priority'] ?? 'normal',
                $s['solicitado_por'] ?? $s['requested_by'] ?? '',
                'queue',
                $s['thumbnail'] ?? $s['thumbnail_url'] ?? ''
            ]);
        }
        foreach ($buffer as $i => $s) {
            $stmt->execute([
                $s['titulo'] ?? $s['title'] ?? '?',
                $s['artista'] ?? $s['artist'] ?? '?',
                $s['razon'] ?? $s['reason'] ?? '',
                $i,
                $s['priority'] ?? 'normal',
                $s['solicitado_por'] ?? $s['requested_by'] ?? '',
                'buffer',
                $s['thumbnail'] ?? $s['thumbnail_url'] ?? ''
            ]);
        }

        echo json_encode(['ok' => true, 'queue' => count($queue), 'buffer' => count($buffer)]);
        break;

    // Python lee acciones pendientes de la web
    case 'pending_queue_actions':
        $stmt = $pdo->query("SELECT * FROM queue_actions WHERE processed = 0 ORDER BY id ASC");
        echo json_encode($stmt->fetchAll());
        break;

    // Python marca acciones como procesadas
    case 'mark_queue_actions':
        $input = json_decode(file_get_contents('php://input'), true);
        $ids = $input['ids'] ?? [];
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE queue_actions SET processed = 1 WHERE id IN ($placeholders)");
            $stmt->execute(array_map('intval', $ids));
        }
        echo json_encode(['ok' => true]);
        break;

    // Python guarda una playlist generada
    case 'save_playlist':
        $input = json_decode(file_get_contents('php://input'), true);
        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        $play_date = trim($input['play_date'] ?? '');
        $songs = $input['songs'] ?? [];
        $playlist_type = trim($input['playlist_type'] ?? '');

        if (!$name || !$songs) {
            http_response_code(400);
            echo json_encode(['error' => 'name y songs requeridos']);
            break;
        }

        // Si tiene playlist_type, actualizar en vez de duplicar
        if ($playlist_type) {
            $stmt = $pdo->prepare("SELECT id FROM playlists WHERE playlist_type = ?");
            $stmt->execute([$playlist_type]);
            $existing = $stmt->fetch();
            if ($existing) {
                $playlist_id = $existing['id'];
                $stmt = $pdo->prepare("UPDATE playlists SET name = ?, description = ?, play_date = ? WHERE id = ?");
                $stmt->execute([$name, $description, $play_date ?: null, $playlist_id]);
                $pdo->prepare("DELETE FROM playlist_songs WHERE playlist_id = ?")->execute([$playlist_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO playlists (name, description, play_date, playlist_type) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $description, $play_date ?: null, $playlist_type]);
                $playlist_id = $pdo->lastInsertId();
            }
        } else {
            $stmt = $pdo->prepare("INSERT INTO playlists (name, description, play_date, playlist_type) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $description, $play_date ?: null, '']);
            $playlist_id = $pdo->lastInsertId();
        }

        $stmt = $pdo->prepare("INSERT INTO playlist_songs (playlist_id, title, artist, youtube_url, position, source, votes_net, thumbnail_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($songs as $i => $s) {
            $stmt->execute([
                $playlist_id,
                $s['title'] ?? $s['titulo'] ?? '?',
                $s['artist'] ?? $s['artista'] ?? '?',
                $s['youtube_url'] ?? '',
                $i,
                $s['source'] ?? '',
                (int)($s['votes_net'] ?? 0),
                $s['thumbnail_url'] ?? ''
            ]);
        }

        echo json_encode(['ok' => true, 'id' => $playlist_id]);
        break;

    // Historial de canciones por fecha
    case 'history_day':
        $date = trim($_GET['date'] ?? date('Y-m-d'));
        $stmt = $pdo->prepare("
            SELECT np.title, np.artist, np.total_up, np.total_down,
                   (np.total_up - np.total_down) as votes_net,
                   np.youtube_url, np.requested_by, np.created_at,
                   np.thumbnail_url,
                   COALESCE(sc.list_color, 'white') as list_color
            FROM now_playing np
            LEFT JOIN song_catalog sc ON LOWER(np.title) = LOWER(sc.title) AND LOWER(np.artist) = LOWER(sc.artist)
            WHERE DATE(np.created_at) = ?
            ORDER BY np.created_at ASC
        ");
        $stmt->execute([$date]);
        echo json_encode($stmt->fetchAll());
        break;

    // Calendario: fechas con canciones
    case 'history_calendar':
        $days = isset($_GET['days']) ? min(60, max(1, (int)$_GET['days'])) : 30;
        $stmt = $pdo->prepare("
            SELECT DATE(created_at) as play_date, COUNT(*) as song_count
            FROM now_playing
            WHERE created_at >= datetime('now', '-' || ? || ' days')
            GROUP BY DATE(created_at)
            ORDER BY play_date DESC
        ");
        $stmt->execute([$days]);
        echo json_encode($stmt->fetchAll());
        break;

    // Listar playlists
    case 'playlists':
        $stmt = $pdo->query("
            SELECT p.*, (SELECT COUNT(*) FROM playlist_songs WHERE playlist_id = p.id) as song_count
            FROM playlists p
            ORDER BY p.created_at DESC
            LIMIT 50
        ");
        echo json_encode($stmt->fetchAll());
        break;

    // Detalle de una playlist
    case 'playlist':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            echo json_encode(null);
            break;
        }
        $stmt = $pdo->prepare("SELECT * FROM playlists WHERE id = ?");
        $stmt->execute([$id]);
        $playlist = $stmt->fetch();
        if ($playlist) {
            $stmt = $pdo->prepare("SELECT * FROM playlist_songs WHERE playlist_id = ? ORDER BY position");
            $stmt->execute([$id]);
            $playlist['songs'] = $stmt->fetchAll();
        }
        echo json_encode($playlist);
        break;

    // Resumen de reacciones de la canción actual
    case 'reactions_summary':
        $current = $pdo->query("SELECT id FROM now_playing WHERE active = 1 ORDER BY id DESC LIMIT 1")->fetch();
        if ($current) {
            $stmt = $pdo->prepare("SELECT reaction, COUNT(*) as count FROM reactions WHERE song_id = ? GROUP BY reaction");
            $stmt->execute([$current['id']]);
            $counts = [];
            foreach ($stmt->fetchAll() as $r) {
                $counts[$r['reaction']] = (int)$r['count'];
            }
            echo json_encode($counts);
        } else {
            echo json_encode([]);
        }
        break;

    // Schedule de presets programados
    case 'schedule':
        $pdo->exec("CREATE TABLE IF NOT EXISTS dj_schedule (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hour_start INTEGER NOT NULL,
            hour_end INTEGER NOT NULL,
            preset TEXT NOT NULL,
            day_of_week TEXT DEFAULT 'all',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $schedule = $input['schedule'] ?? [];
            $pdo->exec("DELETE FROM dj_schedule");
            $stmt = $pdo->prepare("INSERT INTO dj_schedule (hour_start, hour_end, preset, day_of_week) VALUES (?, ?, ?, ?)");
            foreach ($schedule as $s) {
                $stmt->execute([
                    (int)($s['hour_start'] ?? 0),
                    (int)($s['hour_end'] ?? 23),
                    $s['preset'] ?? 'Todo vale',
                    $s['day_of_week'] ?? 'all'
                ]);
            }
            echo json_encode(['ok' => true]);
        } else {
            $stmt = $pdo->query("SELECT * FROM dj_schedule ORDER BY hour_start ASC");
            echo json_encode($stmt->fetchAll());
        }
        break;

    // Canciones bien votadas a una hora específica (auto-aprendizaje)
    case 'history_hour':
        $hour = (int)($_GET['hour'] ?? date('G'));
        $stmt = $pdo->prepare("
            SELECT title, artist, AVG(total_up - total_down) as avg_score, COUNT(*) as times
            FROM now_playing
            WHERE CAST(strftime('%H', created_at) AS INTEGER) = ?
            GROUP BY LOWER(title), LOWER(artist)
            HAVING avg_score > 0
            ORDER BY avg_score DESC
            LIMIT 10
        ");
        $stmt->execute([$hour]);
        echo json_encode($stmt->fetchAll());
        break;

    // Top canciones de la semana
    case 'history_week':
        $stmt = $pdo->query("
            SELECT title, artist, SUM(total_up - total_down) as votes_net,
                   COUNT(*) as times_played, youtube_url, thumbnail_url
            FROM now_playing
            WHERE created_at >= datetime('now', '-7 days')
            GROUP BY LOWER(title), LOWER(artist)
            ORDER BY votes_net DESC
            LIMIT 20
        ");
        echo json_encode($stmt->fetchAll());
        break;

    // Racha actual: canciones seguidas sin downvotes
    case 'streak':
        $songs = $pdo->query("
            SELECT id, title, total_up, total_down
            FROM now_playing
            ORDER BY id DESC
            LIMIT 20
        ")->fetchAll();

        $streak = 0;
        foreach ($songs as $s) {
            if ($s['total_down'] > 0) break;
            $streak++;
        }
        echo json_encode(['streak' => $streak]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Accion no valida']);
}
