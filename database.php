<?php
$requestHost = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
$isLocalRequest = in_array($requestHost, ['localhost', '127.0.0.1', '::1'], true);

define('LETTERS_DB_HOST', getenv('LETTERS_DB_HOST') ?: ($isLocalRequest ? '127.0.0.1' : ''));
define('LETTERS_DB_USER', getenv('LETTERS_DB_USER') ?: ($isLocalRequest ? 'root' : ''));
define('LETTERS_DB_PASSWORD', getenv('LETTERS_DB_PASSWORD') ?: '');
define('LETTERS_DB_NAME', getenv('LETTERS_DB_NAME') ?: ($isLocalRequest ? 'game_db' : ''));

function letters_game_db_connection()
{
    if (LETTERS_DB_HOST === '' || LETTERS_DB_USER === '' || LETTERS_DB_NAME === '') {
        error_log('Letters game database configuration is missing. Set LETTERS_DB_HOST, LETTERS_DB_USER, LETTERS_DB_PASSWORD, and LETTERS_DB_NAME.');
        throw new RuntimeException('إعدادات قاعدة البيانات غير مكتملة. راجع إعدادات اتصال قاعدة بيانات الموقع في الاستضافة.');
    }

    $conn = new mysqli(LETTERS_DB_HOST, LETTERS_DB_USER, LETTERS_DB_PASSWORD, LETTERS_DB_NAME);
    $conn->set_charset('utf8mb4');
    letters_seed_questions($conn);
    letters_ensure_multiplayer_tables($conn);
    return $conn;
}

// ينشئ جدول الأسئلة ويملأه من questions_data.csv عند أول تشغيل إذا كان فارغاً
function letters_seed_questions($conn)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $conn->query("CREATE TABLE IF NOT EXISTS questions (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        letter VARCHAR(5) NOT NULL,
        question TEXT NOT NULL,
        answer TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $count = $conn->query('SELECT COUNT(*) AS c FROM questions');
    if (!$count || (int)$count->fetch_assoc()['c'] > 0) {
        return;
    }

    $file = __DIR__ . '/questions_data.csv';
    if (!is_readable($file) || ($handle = fopen($file, 'r')) === false) {
        return;
    }
    $stmt = $conn->prepare('INSERT INTO questions (letter, question, answer) VALUES (?, ?, ?)');
    $first = true;
    while (($row = fgetcsv($handle)) !== false) {
        if ($first) {
            $first = false;
            continue; // سطر العناوين
        }
        if (count($row) < 3 || trim($row[0]) === '') {
            continue;
        }
        $stmt->bind_param('sss', $row[0], $row[1], $row[2]);
        $stmt->execute();
    }
    fclose($handle);
}

function letters_ensure_multiplayer_tables($conn)
{
    $conn->query("CREATE TABLE IF NOT EXISTS game_rooms (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        room_code CHAR(6) NOT NULL UNIQUE,
        max_players INT NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'lobby',
        board_json LONGTEXT NOT NULL,
        current_team VARCHAR(4) NOT NULL DEFAULT 'red',
        winner VARCHAR(4) DEFAULT NULL,
        moves_json LONGTEXT NOT NULL,
        admin_token CHAR(64) DEFAULT NULL,
        timer_seconds INT NOT NULL DEFAULT 60,
        timer_deadline BIGINT DEFAULT NULL,
        answer_mode VARCHAR(20) NOT NULL DEFAULT 'admin_judged',
        title VARCHAR(160) NOT NULL DEFAULT 'مسابقة الحروف التفاعلية',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS game_players (
        token CHAR(64) NOT NULL PRIMARY KEY,
        room_id BIGINT UNSIGNED NOT NULL,
        player_name VARCHAR(120) NOT NULL,
        team VARCHAR(4) NOT NULL,
        joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (room_id),
        CONSTRAINT fk_game_players_room FOREIGN KEY (room_id) REFERENCES game_rooms(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS game_room_questions (
        room_id BIGINT UNSIGNED NOT NULL,
        cell_index TINYINT UNSIGNED NOT NULL,
        question TEXT NOT NULL,
        answer TEXT NOT NULL,
        answer_revealed TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (room_id),
        CONSTRAINT fk_game_room_questions_room FOREIGN KEY (room_id) REFERENCES game_rooms(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
