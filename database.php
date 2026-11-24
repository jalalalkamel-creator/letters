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
