<?php
function start_admin_session()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'httponly' => true,
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'samesite' => 'Strict'
        ]);
        session_start();
    }
}

function ensure_admin_auth_table($conn)
{
    $conn->query("CREATE TABLE IF NOT EXISTS admin_credentials (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        password_hash VARCHAR(255) DEFAULT NULL,
        failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        locked_until BIGINT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query('INSERT IGNORE INTO admin_credentials (id) VALUES (1)');

    // استعادة كلمة مرور المشرف من متغير بيئة (hash فقط) عندما لا تكون معيّنة بعد
    $seedHash = getenv('LETTERS_ADMIN_PASSWORD_HASH');
    if ($seedHash !== false && $seedHash !== '' && password_get_info($seedHash)['algo'] !== null) {
        $seed = $conn->prepare('UPDATE admin_credentials SET password_hash = ? WHERE id = 1 AND password_hash IS NULL');
        $seed->bind_param('s', $seedHash);
        $seed->execute();
    }
}

function is_admin_authenticated()
{
    return isset($_SESSION['letters_game_admin']) && $_SESSION['letters_game_admin'] === true;
}

function is_local_admin_setup()
{
    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $serverAddress = $_SERVER['SERVER_ADDR'] ?? '';
    return in_array($remoteAddress, ['127.0.0.1', '::1'], true) ||
        ($serverAddress !== '' && hash_equals($serverAddress, $remoteAddress));
}

function admin_csrf_token()
{
    if (empty($_SESSION['admin_csrf_token'])) {
        $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_csrf_token'];
}

function valid_admin_csrf($token)
{
    return is_string($token) && isset($_SESSION['admin_csrf_token']) &&
        hash_equals($_SESSION['admin_csrf_token'], $token);
}
