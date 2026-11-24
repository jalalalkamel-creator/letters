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
    return $conn;
}
