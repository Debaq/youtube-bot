<?php
/**
 * Migraciones de base de datos.
 * Se incluye desde api.php y panel.php para crear tablas nuevas
 * sin modificar config.php.
 */

function run_migrations($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL,
        applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $migrations = array(
        '001_queue_songs' => "CREATE TABLE IF NOT EXISTS queue_songs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            artist TEXT NOT NULL DEFAULT '',
            reason TEXT DEFAULT '',
            position INTEGER NOT NULL DEFAULT 0,
            priority TEXT DEFAULT 'normal',
            requested_by TEXT DEFAULT '',
            source TEXT DEFAULT 'queue',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        '002_queue_actions' => "CREATE TABLE IF NOT EXISTS queue_actions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            action TEXT NOT NULL,
            data TEXT DEFAULT '',
            processed INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        '003_playlists' => "CREATE TABLE IF NOT EXISTS playlists (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT DEFAULT '',
            play_date DATE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        '004_playlist_songs' => "CREATE TABLE IF NOT EXISTS playlist_songs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            playlist_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            artist TEXT NOT NULL DEFAULT '',
            youtube_url TEXT DEFAULT '',
            position INTEGER NOT NULL DEFAULT 0,
            source TEXT DEFAULT '',
            votes_net INTEGER DEFAULT 0,
            FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE
        )",
    );

    foreach ($migrations as $name => $sql) {
        $stmt = $pdo->prepare("SELECT 1 FROM migrations WHERE name = ?");
        $stmt->execute(array($name));
        if (!$stmt->fetch()) {
            $pdo->exec($sql);
            $ins = $pdo->prepare("INSERT INTO migrations (name) VALUES (?)");
            $ins->execute(array($name));
        }
    }

    // Columnas nuevas via PRAGMA check (seguro ejecutar múltiples veces)
    $table_columns = array(
        'queue_songs' => array('thumbnail_url' => "TEXT DEFAULT ''"),
        'playlist_songs' => array('thumbnail_url' => "TEXT DEFAULT ''"),
        'playlists' => array('playlist_type' => "TEXT DEFAULT ''"),
    );
    foreach ($table_columns as $table => $columns) {
        $cols = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        foreach ($columns as $col => $type) {
            if (!in_array($col, $cols)) {
                $pdo->exec("ALTER TABLE $table ADD COLUMN $col $type");
            }
        }
    }

    // Default schedule: insert if dj_schedule is empty
    $pdo->exec("CREATE TABLE IF NOT EXISTS dj_schedule (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        hour_start INTEGER NOT NULL,
        hour_end INTEGER NOT NULL,
        preset TEXT NOT NULL,
        day_of_week TEXT DEFAULT 'all',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $count = $pdo->query("SELECT COUNT(*) as n FROM dj_schedule")->fetch();
    if ($count && (int)$count['n'] === 0) {
        $defaults = array(
            array(8, 10, 'Chill & Lo-fi'),
            array(10, 12, 'Estudio tranquilo'),
            array(12, 13, 'Pop actual'),
            array(13, 14, 'Cumbia & Latina'),
            array(14, 16, 'Indie & Alternativo'),
            array(16, 17, 'Rock clasico'),
            array(17, 18, 'Reggaeton & Urbano'),
            array(18, 20, 'Fiesta total'),
        );
        $stmt = $pdo->prepare("INSERT INTO dj_schedule (hour_start, hour_end, preset) VALUES (?, ?, ?)");
        foreach ($defaults as $d) {
            $stmt->execute($d);
        }
    }
}
