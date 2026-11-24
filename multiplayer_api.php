<?php
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/database.php';
start_admin_session();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Content-Type: application/json; charset=utf-8');

function respond($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function request_data()
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function normalize_answer($answer)
{
    $answer = trim($answer);
    if (function_exists('mb_strtolower')) {
        $answer = mb_strtolower($answer, 'UTF-8');
    } else {
        $answer = strtolower($answer);
    }
    $answer = strtr($answer, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا']);
    $answer = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $answer);
    $answer = preg_replace('/[\p{P}\p{Z}\s]+/u', '', $answer);
    return $answer;
}

function find_board_winner($board, $preferredTeam = null)
{
    if (!is_array($board) || count($board) !== 25) {
        return null;
    }

    $winners = [];
    foreach (['red', 'blue'] as $team) {
        $queue = [];
        $visited = [];
        $endReached = false;
        for ($index = 0; $index < 25; $index++) {
            $row = intdiv($index, 5);
            $column = $index % 5;
            $isStartEdge = $team === 'red' ? $column === 0 : $row === 0;
            if ($isStartEdge && ($board[$index]['team'] ?? null) === $team) {
                $queue[] = $index;
                $visited[$index] = true;
            }
        }

        while ($queue) {
            $index = array_pop($queue);
            $row = intdiv($index, 5);
            $column = $index % 5;
            if (($team === 'red' && $column === 4) || ($team === 'blue' && $row === 4)) {
                $endReached = true;
                break;
            }

            foreach ([
                [$row - 1, $column - 1], [$row - 1, $column], [$row - 1, $column + 1],
                [$row, $column - 1], [$row, $column + 1],
                [$row + 1, $column - 1], [$row + 1, $column], [$row + 1, $column + 1]
            ] as $neighbor) {
                [$nextRow, $nextColumn] = $neighbor;
                if ($nextRow < 0 || $nextRow > 4 || $nextColumn < 0 || $nextColumn > 4) {
                    continue;
                }
                $nextIndex = $nextRow * 5 + $nextColumn;
                if (!isset($visited[$nextIndex]) && ($board[$nextIndex]['team'] ?? null) === $team) {
                    $visited[$nextIndex] = true;
                    $queue[] = $nextIndex;
                }
            }
        }
        if ($endReached) {
            $winners[] = $team;
        }
    }

    if (in_array($preferredTeam, $winners, true)) {
        return $preferredTeam;
    }
    return $winners[0] ?? null;
}

function finish_room_if_board_has_winner($conn, $roomId)
{
    $conn->begin_transaction();
    $roomStmt = $conn->prepare('SELECT status, board_json, moves_json FROM game_rooms WHERE id = ? FOR UPDATE');
    $roomStmt->bind_param('i', $roomId);
    $roomStmt->execute();
    $room = $roomStmt->get_result()->fetch_assoc();
    if (!$room || $room['status'] !== 'playing') {
        $conn->commit();
        return;
    }

    $moves = json_decode($room['moves_json'], true);
    $lastMove = is_array($moves) && $moves ? $moves[count($moves) - 1] : null;
    $winner = find_board_winner(json_decode($room['board_json'], true), $lastMove['team'] ?? null);
    if ($winner !== null) {
        $finish = $conn->prepare("UPDATE game_rooms SET winner = ?, status = 'finished', timer_deadline = NULL WHERE id = ?");
        $finish->bind_param('si', $winner, $roomId);
        $finish->execute();
        $clearQuestion = $conn->prepare('DELETE FROM game_room_questions WHERE room_id = ?');
        $clearQuestion->bind_param('i', $roomId);
        $clearQuestion->execute();
    }
    $conn->commit();
}

function add_missing_column($conn, $column, $definition)
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'game_rooms\' AND COLUMN_NAME = ?');
    $stmt->bind_param('s', $column);
    $stmt->execute();
    $exists = (int)$stmt->get_result()->fetch_assoc()['total'] > 0;
    $stmt->close();
    if (!$exists) {
        $conn->query("ALTER TABLE game_rooms ADD COLUMN `$column` $definition");
    }
}

function ensure_room_columns($conn)
{
    $result = $conn->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_rooms'");
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[$row['COLUMN_NAME']] = true;
    }
    if (!$columns) {
        return;
    }
    if (!isset($columns['admin_token'])) {
        add_missing_column($conn, 'admin_token', 'CHAR(64) DEFAULT NULL');
    }
    if (!isset($columns['timer_seconds'])) {
        add_missing_column($conn, 'timer_seconds', 'INT NOT NULL DEFAULT 60');
    }
    if (!isset($columns['timer_deadline'])) {
        add_missing_column($conn, 'timer_deadline', 'BIGINT DEFAULT NULL');
    }
    if (!isset($columns['answer_mode'])) {
        add_missing_column($conn, 'answer_mode', "VARCHAR(20) NOT NULL DEFAULT 'admin_judged'");
    }
    if (!isset($columns['title'])) {
        add_missing_column($conn, 'title', "VARCHAR(160) NOT NULL DEFAULT 'مسابقة الحروف التفاعلية'");
    }
}

function auto_resolve_expired_question($conn, $roomId)
{
    $conn->begin_transaction();
    $roomStmt = $conn->prepare('SELECT status, board_json, current_team, moves_json, timer_deadline FROM game_rooms WHERE id = ? FOR UPDATE');
    $roomStmt->bind_param('i', $roomId);
    $roomStmt->execute();
    $room = $roomStmt->get_result()->fetch_assoc();
    if (!$room || $room['status'] !== 'playing' || $room['timer_deadline'] === null ||
        (int)$room['timer_deadline'] > time()) {
        $conn->commit();
        return false;
    }

    $questionStmt = $conn->prepare('SELECT cell_index, question, answer FROM game_room_questions WHERE room_id = ?');
    $questionStmt->bind_param('i', $roomId);
    $questionStmt->execute();
    $question = $questionStmt->get_result()->fetch_assoc();
    if (!$question) {
        $timer = $conn->prepare('UPDATE game_rooms SET timer_deadline = NULL WHERE id = ?');
        $timer->bind_param('i', $roomId);
        $timer->execute();
        $conn->commit();
        return false;
    }

    $results = json_decode($room['moves_json'], true);
    $results[] = [
        'cell' => (int)$question['cell_index'],
        'team' => null,
        'previous_team' => $room['current_team'],
        'question' => $question['question'],
        'answer' => $question['answer'],
        'resolved_at' => time(),
        'timed_out' => true
    ];
    $opposingTeam = $room['current_team'] === 'red' ? 'blue' : 'red';
    $opponents = $conn->prepare('SELECT COUNT(*) AS total FROM game_players WHERE room_id = ? AND team = ?');
    $opponents->bind_param('is', $roomId, $opposingTeam);
    $opponents->execute();
    $nextTeam = (int)$opponents->get_result()->fetch_assoc()['total'] > 0
        ? $opposingTeam
        : $room['current_team'];
    $resultsJson = json_encode($results, JSON_UNESCAPED_UNICODE);
    $update = $conn->prepare('UPDATE game_rooms SET moves_json = ?, current_team = ?, timer_deadline = NULL WHERE id = ?');
    $update->bind_param('ssi', $resultsJson, $nextTeam, $roomId);
    $update->execute();
    $clear = $conn->prepare('DELETE FROM game_room_questions WHERE room_id = ?');
    $clear->bind_param('i', $roomId);
    $clear->execute();
    $conn->commit();
    return true;
}

try {
    $conn = letters_game_db_connection();
    ensure_room_columns($conn);
    $action = $_GET['action'] ?? '';
    $input = request_data();

    if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_admin_authenticated()) {
            respond(['error' => 'سجّل الدخول إلى صفحة المشرف أولاً.'], 401);
        }
        if (!valid_admin_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            respond(['error' => 'انتهت صلاحية الطلب. حدّث صفحة المشرف وحاول مجدداً.'], 403);
        }
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
        ensure_room_columns($conn);
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
            room_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            cell_index TINYINT UNSIGNED NOT NULL,
            question TEXT NOT NULL,
            answer TEXT NOT NULL,
            answer_revealed TINYINT(1) NOT NULL DEFAULT 0,
            CONSTRAINT fk_game_room_questions_room FOREIGN KEY (room_id) REFERENCES game_rooms(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $maxPlayers = filter_var($input['max_players'] ?? null, FILTER_VALIDATE_INT);
        if ($maxPlayers === false || $maxPlayers < 2 || $maxPlayers > 50) {
            respond(['error' => 'حدد عدد لاعبين بين 2 و50.'], 400);
        }
        $timerSeconds = filter_var($input['timer_seconds'] ?? 60, FILTER_VALIDATE_INT);
        if ($timerSeconds === false || $timerSeconds < 5 || $timerSeconds > 600) {
            respond(['error' => 'حدد وقتاً بين 5 و600 ثانية.'], 400);
        }
        $answerMode = $input['answer_mode'] ?? 'admin_judged';
        if (!in_array($answerMode, ['admin_judged', 'fastest_player'], true)) {
            respond(['error' => 'حدد طريقة إجابة صحيحة للغرفة.'], 400);
        }
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') {
            $title = 'مسابقة الحروف التفاعلية';
        }
        if (strlen($title) > 160) {
            respond(['error' => 'عنوان المسابقة طويل جداً.'], 400);
        }
        $letters = ['أ','ب','ت','ث','ج','ح','خ','د','ذ','ر','ز','س','ش','ص','ض','ط','ظ','ع','غ','ف','ق','ك','ل','م','ن'];
        shuffle($letters);
        $board = array_map(function ($letter) {
            return ['letter' => $letter, 'team' => null];
        }, array_slice($letters, 0, 25));
        $boardJson = json_encode($board, JSON_UNESCAPED_UNICODE);
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            $check = $conn->prepare('SELECT id FROM game_rooms WHERE room_code = ?');
            $check->bind_param('s', $code);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
            $check->close();
        } while ($exists);
        $adminToken = bin2hex(random_bytes(32));
        $stmt = $conn->prepare("INSERT INTO game_rooms (room_code, max_players, board_json, moves_json, admin_token, timer_seconds, answer_mode, title) VALUES (?, ?, ?, '[]', ?, ?, ?, ?)");
        $stmt->bind_param('sississ', $code, $maxPlayers, $boardJson, $adminToken, $timerSeconds, $answerMode, $title);
        $stmt->execute();
        respond(['room_code' => $code, 'max_players' => $maxPlayers, 'admin_token' => $adminToken, 'answer_mode' => $answerMode, 'title' => $title]);
    }

    if ($action === 'join' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $code = strtoupper(trim((string)($input['room_code'] ?? '')));
        $name = trim((string)($input['name'] ?? ''));
        $team = $input['team'] ?? '';
        if (!preg_match('/^[A-Z0-9]{6}$/', $code) || $name === '' || strlen($name) > 120 ||
            preg_match('/[\x00-\x1F\x7F]/', $name) || !in_array($team, ['red', 'blue'], true)) {
            respond(['error' => 'تحقق من رمز الغرفة والاسم والفريق.'], 400);
        }

        $conn->begin_transaction();
        $stmt = $conn->prepare('SELECT id, max_players, status FROM game_rooms WHERE room_code = ? FOR UPDATE');
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $room = $stmt->get_result()->fetch_assoc();
        if (!$room) {
            $conn->rollback();
            respond(['error' => 'رمز الغرفة غير صحيح.'], 404);
        }
        if ($room['status'] !== 'lobby') {
            $conn->rollback();
            respond(['error' => 'بدأت اللعبة أو أُغلقت هذه الغرفة ولا يمكن الانضمام إليها.'], 409);
        }
        $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM game_players WHERE room_id = ?');
        $countStmt->bind_param('i', $room['id']);
        $countStmt->execute();
        $count = (int)$countStmt->get_result()->fetch_assoc()['total'];
        if ($count >= (int)$room['max_players']) {
            $conn->rollback();
            respond(['error' => 'اكتمل عدد اللاعبين في هذه الغرفة.'], 409);
        }
        $token = bin2hex(random_bytes(32));
        $insert = $conn->prepare('INSERT INTO game_players (token, room_id, player_name, team) VALUES (?, ?, ?, ?)');
        $insert->bind_param('siss', $token, $room['id'], $name, $team);
        $insert->execute();
        $count++;
        if ($count >= (int)$room['max_players']) {
            $teamsStmt = $conn->prepare('SELECT team, COUNT(*) AS total FROM game_players WHERE room_id = ? GROUP BY team');
            $teamsStmt->bind_param('i', $room['id']);
            $teamsStmt->execute();
            $teams = ['red' => 0, 'blue' => 0];
            foreach ($teamsStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $teamCount) {
                $teams[$teamCount['team']] = (int)$teamCount['total'];
            }
            $firstTeam = $teams['red'] > 0 ? 'red' : 'blue';
            $start = $conn->prepare("UPDATE game_rooms SET status = 'playing', current_team = ? WHERE id = ?");
            $start->bind_param('si', $firstTeam, $room['id']);
            $start->execute();
        }
        $conn->commit();
        respond(['token' => $token, 'room_code' => $code]);
    }

    if (in_array($action, ['admin_state', 'admin_resolve', 'admin_timer', 'admin_undo', 'admin_finish'], true)) {
        if (!is_admin_authenticated()) {
            respond(['error' => 'سجّل الدخول إلى صفحة المشرف أولاً.'], 401);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
            !valid_admin_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            respond(['error' => 'انتهت صلاحية الطلب. حدّث صفحة المشرف وحاول مجدداً.'], 403);
        }
        $roomCode = strtoupper(trim((string)($_GET['room'] ?? $input['room_code'] ?? '')));
        $adminToken = trim((string)($_GET['key'] ?? $input['admin_token'] ?? ''));
        if (!preg_match('/^[A-Z0-9]{6}$/', $roomCode) ||
            ($adminToken !== '' && !preg_match('/^[a-f0-9]{64}$/', $adminToken))) {
            respond(['error' => 'رابط لوحة الإدارة غير صالح.'], 401);
        }
        $adminStmt = $adminToken === ''
            ? $conn->prepare('SELECT id, room_code, max_players, status, board_json, current_team, winner, moves_json, timer_seconds, timer_deadline, answer_mode, title FROM game_rooms WHERE room_code = ?')
            : $conn->prepare('SELECT id, room_code, max_players, status, board_json, current_team, winner, moves_json, timer_seconds, timer_deadline, answer_mode, title FROM game_rooms WHERE room_code = ? AND admin_token = ?');
        if ($adminToken === '') {
            $adminStmt->bind_param('s', $roomCode);
        } else {
            $adminStmt->bind_param('ss', $roomCode, $adminToken);
        }
        $adminStmt->execute();
        $room = $adminStmt->get_result()->fetch_assoc();
        if (!$room) {
            respond(['error' => 'رمز الإدارة غير صحيح أو الغرفة غير موجودة.'], 403);
        }

        if ($action === 'admin_state' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            finish_room_if_board_has_winner($conn, (int)$room['id']);
            auto_resolve_expired_question($conn, (int)$room['id']);
            finish_room_if_board_has_winner($conn, (int)$room['id']);
            $refreshRoom = $conn->prepare('SELECT id, room_code, max_players, status, board_json, current_team, winner, moves_json, timer_seconds, timer_deadline, answer_mode, title FROM game_rooms WHERE id = ?');
            $refreshRoom->bind_param('i', $room['id']);
            $refreshRoom->execute();
            $room = $refreshRoom->get_result()->fetch_assoc();
            $playersStmt = $conn->prepare('SELECT player_name, team FROM game_players WHERE room_id = ? ORDER BY joined_at, token');
            $playersStmt->bind_param('i', $room['id']);
            $playersStmt->execute();
            $players = $playersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $questionStmt = $conn->prepare('SELECT cell_index, question, answer, answer_revealed FROM game_room_questions WHERE room_id = ?');
            $questionStmt->bind_param('i', $room['id']);
            $questionStmt->execute();
            $question = $questionStmt->get_result()->fetch_assoc();
            $results = json_decode($room['moves_json'], true);
            respond([
                'room_code' => $room['room_code'],
                'title' => $room['title'] ?? 'مسابقة الحروف التفاعلية',
                'status' => $room['status'],
                'max_players' => (int)$room['max_players'],
                'players' => $players,
                'board' => json_decode($room['board_json'], true),
                'current_team' => $room['current_team'],
                'winner' => $room['winner'],
                'answer_mode' => $room['answer_mode'],
                'timer_seconds' => (int)$room['timer_seconds'],
                'timer_deadline' => $room['timer_deadline'] === null ? null : (int)$room['timer_deadline'],
                'question' => $question ? [
                    'cell' => (int)$question['cell_index'],
                    'letter' => json_decode($room['board_json'], true)[$question['cell_index']]['letter'],
                    'question' => $question['question'],
                    'answer' => $question['answer']
                ] : null,
                'results' => $results,
                'scores' => [
                    'red' => count(array_filter($results, function ($result) { return ($result['team'] ?? null) === 'red'; })),
                    'blue' => count(array_filter($results, function ($result) { return ($result['team'] ?? null) === 'blue'; }))
                ]
            ]);
        }

        if ($action === 'admin_resolve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($room['answer_mode'] !== 'admin_judged') {
                respond(['error' => 'هذه الغرفة تستخدم سباق الإجابات، والنتيجة تسجل تلقائياً.'], 409);
            }
            $team = $input['team'] ?? null;
            $playerName = trim((string)($input['player_name'] ?? ''));
            if (!in_array($team, ['red', 'blue', 'none'], true)) {
                respond(['error' => 'حدد الفريق الفائز أو اختر عدم احتساب نقطة.'], 400);
            }
            if ($playerName !== '' && strlen($playerName) > 120) {
                respond(['error' => 'اسم اللاعب غير صالح.'], 400);
            }
            if ($team !== 'none' && $playerName === '') {
                respond(['error' => 'اختر اسم اللاعب الذي أجاب إجابة صحيحة.'], 400);
            }
            $conn->begin_transaction();
            $lock = $conn->prepare('SELECT id, status, board_json, current_team, moves_json FROM game_rooms WHERE id = ? FOR UPDATE');
            $lock->bind_param('i', $room['id']);
            $lock->execute();
            $lockedRoom = $lock->get_result()->fetch_assoc();
            $questionStmt = $conn->prepare('SELECT cell_index, question, answer FROM game_room_questions WHERE room_id = ?');
            $questionStmt->bind_param('i', $room['id']);
            $questionStmt->execute();
            $question = $questionStmt->get_result()->fetch_assoc();
            if (!$lockedRoom || $lockedRoom['status'] !== 'playing' || !$question) {
                $conn->rollback();
                respond(['error' => 'لا يوجد سؤال نشط لتسجيل نتيجته.'], 409);
            }
            $awardedTeam = $team === 'none' ? null : $team;
            if ($playerName !== '' && $awardedTeam !== null) {
                $playerCheck = $conn->prepare('SELECT token FROM game_players WHERE room_id = ? AND player_name = ? AND team = ? LIMIT 1');
                $playerCheck->bind_param('iss', $room['id'], $playerName, $awardedTeam);
                $playerCheck->execute();
                if ($playerCheck->get_result()->num_rows === 0) {
                    $conn->rollback();
                    respond(['error' => 'اختر لاعباً من الفريق الذي فاز بالسؤال.'], 400);
                }
            }
            $board = json_decode($lockedRoom['board_json'], true);
            $cell = (int)$question['cell_index'];
            $board[$cell]['team'] = $awardedTeam;
            $winner = find_board_winner($board, $awardedTeam);
            $results = json_decode($lockedRoom['moves_json'], true);
            $results[] = [
                'cell' => $cell,
                'team' => $awardedTeam,
                'previous_team' => $lockedRoom['current_team'],
                'question' => $question['question'],
                'answer' => $question['answer'],
                'player_name' => $playerName !== '' ? $playerName : null,
                'resolved_at' => time()
            ];
            $turnTeam = $awardedTeam ?? $lockedRoom['current_team'];
            $opposingTeam = $turnTeam === 'red' ? 'blue' : 'red';
            $opponents = $conn->prepare('SELECT COUNT(*) AS total FROM game_players WHERE room_id = ? AND team = ?');
            $opponents->bind_param('is', $room['id'], $opposingTeam);
            $opponents->execute();
            $nextTeam = (int)$opponents->get_result()->fetch_assoc()['total'] > 0 ? $opposingTeam : $turnTeam;
            $boardJson = json_encode($board, JSON_UNESCAPED_UNICODE);
            $resultsJson = json_encode($results, JSON_UNESCAPED_UNICODE);
            $status = $winner === null ? 'playing' : 'finished';
            $update = $conn->prepare('UPDATE game_rooms SET board_json = ?, moves_json = ?, current_team = ?, winner = ?, status = ?, timer_deadline = NULL WHERE id = ?');
            $update->bind_param('sssssi', $boardJson, $resultsJson, $nextTeam, $winner, $status, $room['id']);
            $update->execute();
            $clear = $conn->prepare('DELETE FROM game_room_questions WHERE room_id = ?');
            $clear->bind_param('i', $room['id']);
            $clear->execute();
            $conn->commit();
            respond(['ok' => true]);
        }

        if ($action === 'admin_timer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $seconds = filter_var($input['seconds'] ?? null, FILTER_VALIDATE_INT);
            if ($seconds === false || $seconds < 5 || $seconds > 600) {
                respond(['error' => 'حدد وقتاً بين 5 و600 ثانية.'], 400);
            }
            $conn->begin_transaction();
            $lock = $conn->prepare('SELECT id, status FROM game_rooms WHERE id = ? FOR UPDATE');
            $lock->bind_param('i', $room['id']);
            $lock->execute();
            $lockedRoom = $lock->get_result()->fetch_assoc();
            if (!$lockedRoom || $lockedRoom['status'] !== 'playing') {
                $conn->rollback();
                respond(['error' => 'لا يمكن تشغيل المؤقت لأن المباراة ليست جارية.'], 409);
            }
            $activeStmt = $conn->prepare('SELECT room_id FROM game_room_questions WHERE room_id = ?');
            $activeStmt->bind_param('i', $room['id']);
            $activeStmt->execute();
            if ($activeStmt->get_result()->num_rows === 0) {
                $conn->rollback();
                respond(['error' => 'يبدأ المؤقت عند اختيار مربع وظهور السؤال.'], 409);
            }
            $deadline = time() + $seconds;
            $timerStmt = $conn->prepare('UPDATE game_rooms SET timer_seconds = ?, timer_deadline = ? WHERE id = ?');
            $timerStmt->bind_param('iii', $seconds, $deadline, $room['id']);
            $timerStmt->execute();
            $conn->commit();
            respond(['ok' => true]);
        }

        if ($action === 'admin_undo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $conn->begin_transaction();
            $lock = $conn->prepare('SELECT id, board_json, moves_json, timer_seconds FROM game_rooms WHERE id = ? FOR UPDATE');
            $lock->bind_param('i', $room['id']);
            $lock->execute();
            $lockedRoom = $lock->get_result()->fetch_assoc();
            $results = json_decode($lockedRoom['moves_json'], true);
            if (!$results) {
                $conn->rollback();
                respond(['error' => 'لا توجد نتيجة للتراجع عنها.'], 409);
            }
            $lastResult = array_pop($results);
            $board = json_decode($lockedRoom['board_json'], true);
            $board[$lastResult['cell']]['team'] = null;
            $boardJson = json_encode($board, JSON_UNESCAPED_UNICODE);
            $resultsJson = json_encode($results, JSON_UNESCAPED_UNICODE);
            $currentTeam = $lastResult['previous_team'];
            $update = $conn->prepare("UPDATE game_rooms SET board_json = ?, moves_json = ?, current_team = ?, winner = NULL, status = 'playing', timer_deadline = NULL WHERE id = ?");
            $update->bind_param('sssi', $boardJson, $resultsJson, $currentTeam, $room['id']);
            $update->execute();
            $conn->commit();
            respond(['ok' => true]);
        }

        if ($action === 'admin_finish' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $team = $input['team'] ?? '';
            if (!in_array($team, ['red', 'blue'], true)) {
                respond(['error' => 'حدد الفريق الفائز بالمباراة.'], 400);
            }
            $conn->begin_transaction();
            $lock = $conn->prepare('SELECT id FROM game_rooms WHERE id = ? FOR UPDATE');
            $lock->bind_param('i', $room['id']);
            $lock->execute();
            $lock->get_result()->fetch_assoc();
            $finish = $conn->prepare("UPDATE game_rooms SET winner = ?, status = 'finished', timer_deadline = NULL WHERE id = ?");
            $finish->bind_param('si', $team, $room['id']);
            $finish->execute();
            $clear = $conn->prepare('DELETE FROM game_room_questions WHERE room_id = ?');
            $clear->bind_param('i', $room['id']);
            $clear->execute();
            $conn->commit();
            respond(['ok' => true]);
        }
    }

    if ($action === 'display_state' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $roomCode = strtoupper(trim((string)($_GET['room'] ?? '')));
        if (!preg_match('/^[A-Z0-9]{6}$/', $roomCode)) {
            respond(['error' => 'رمز الغرفة غير صالح.'], 400);
        }
        $roomStmt = $conn->prepare('SELECT id, max_players FROM game_rooms WHERE room_code = ?');
        $roomStmt->bind_param('s', $roomCode);
        $roomStmt->execute();
        $room = $roomStmt->get_result()->fetch_assoc();
        if (!$room) {
            respond(['error' => 'الغرفة غير موجودة.'], 404);
        }
        $playersStmt = $conn->prepare('SELECT COUNT(*) AS total FROM game_players WHERE room_id = ?');
        $playersStmt->bind_param('i', $room['id']);
        $playersStmt->execute();
        $playersCount = (int)$playersStmt->get_result()->fetch_assoc()['total'];

        auto_resolve_expired_question($conn, (int)$room['id']);
        finish_room_if_board_has_winner($conn, (int)$room['id']);

        $stateStmt = $conn->prepare('SELECT room_code, title, status, board_json, winner, timer_seconds, timer_deadline, moves_json FROM game_rooms WHERE id = ?');
        $stateStmt->bind_param('i', $room['id']);
        $stateStmt->execute();
        $state = $stateStmt->get_result()->fetch_assoc();
        $questionStmt = $conn->prepare('SELECT cell_index, question, answer, answer_revealed FROM game_room_questions WHERE room_id = ?');
        $questionStmt->bind_param('i', $room['id']);
        $questionStmt->execute();
        $question = $questionStmt->get_result()->fetch_assoc();
        $results = json_decode($state['moves_json'], true);
        $board = json_decode($state['board_json'], true);

        respond([
            'room_code' => $state['room_code'],
            'title' => $state['title'] ?? 'مسابقة الحروف التفاعلية',
            'status' => $state['status'],
            'players_count' => $playersCount,
            'max_players' => (int)$room['max_players'],
            'board' => $board,
            'winner' => $state['winner'],
            'timer_seconds' => (int)$state['timer_seconds'],
            'timer_deadline' => $state['timer_deadline'] === null ? null : (int)$state['timer_deadline'],
            'question' => $question ? [
                'cell' => (int)$question['cell_index'],
                'letter' => $board[$question['cell_index']]['letter'],
                'question' => $question['question'],
                'answer' => (int)$question['answer_revealed'] === 1 ? $question['answer'] : null,
                'answer_visible' => (int)$question['answer_revealed'] === 1
            ] : null,
            'results' => $results,
            'scores' => [
                'red' => count(array_filter($results, function ($result) { return ($result['team'] ?? null) === 'red'; })),
                'blue' => count(array_filter($results, function ($result) { return ($result['team'] ?? null) === 'blue'; }))
            ]
        ]);
    }

    $token = trim((string)($_GET['token'] ?? $input['token'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        respond(['error' => 'انضم إلى غرفة أولاً.'], 401);
    }
    $playerStmt = $conn->prepare('SELECT p.room_id, p.player_name, p.team, r.room_code, r.max_players, r.status, r.board_json, r.current_team, r.winner, r.moves_json, r.timer_seconds, r.timer_deadline, r.answer_mode, r.title FROM game_players p JOIN game_rooms r ON r.id = p.room_id WHERE p.token = ?');
    $playerStmt->bind_param('s', $token);
    $playerStmt->execute();
    $state = $playerStmt->get_result()->fetch_assoc();
    if (!$state) {
        respond(['error' => 'تعذر العثور على اللاعب أو الغرفة.'], 404);
    }

    if ($action === 'submit_answer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $submittedAnswer = trim((string)($input['answer'] ?? ''));
        $expectedCell = filter_var($input['cell'] ?? null, FILTER_VALIDATE_INT);
        if ($submittedAnswer === '' || strlen($submittedAnswer) > 600) {
            respond(['error' => 'اكتب إجابتك أولاً.'], 400);
        }
        if ($expectedCell === false || $expectedCell < 0 || $expectedCell > 24) {
            respond(['error' => 'السؤال المحدد غير صالح. حدّث الصفحة وحاول مجدداً.'], 400);
        }
        auto_resolve_expired_question($conn, (int)$state['room_id']);
        finish_room_if_board_has_winner($conn, (int)$state['room_id']);
        $conn->begin_transaction();
        $roomStmt = $conn->prepare('SELECT r.id, r.status, r.answer_mode, r.board_json, r.current_team, r.moves_json, r.timer_deadline, p.player_name, p.team FROM game_rooms r JOIN game_players p ON p.room_id = r.id WHERE p.token = ? FOR UPDATE');
        $roomStmt->bind_param('s', $token);
        $roomStmt->execute();
        $room = $roomStmt->get_result()->fetch_assoc();
        if (!$room || $room['status'] !== 'playing' || $room['answer_mode'] !== 'fastest_player') {
            $conn->rollback();
            respond(['error' => 'الإجابة السريعة غير مفعلة في هذه الغرفة.'], 409);
        }
        if ($room['timer_deadline'] === null || (int)$room['timer_deadline'] <= time()) {
            $conn->rollback();
            auto_resolve_expired_question($conn, (int)$room['id']);
            respond(['error' => 'انتهى وقت الإجابة.'], 409);
        }
        $questionStmt = $conn->prepare('SELECT cell_index, question, answer FROM game_room_questions WHERE room_id = ? FOR UPDATE');
        $questionStmt->bind_param('i', $room['id']);
        $questionStmt->execute();
        $question = $questionStmt->get_result()->fetch_assoc();
        if (!$question || (int)$question['cell_index'] !== $expectedCell) {
            $conn->rollback();
            respond(['error' => 'تم تسجيل إجابة لاعب آخر بالفعل.'], 409);
        }
        if (normalize_answer($submittedAnswer) !== normalize_answer($question['answer'])) {
            $conn->rollback();
            respond(['correct' => false, 'message' => 'إجابة غير صحيحة، يمكنك المحاولة مرة أخرى.']);
        }

        $board = json_decode($room['board_json'], true);
        $cell = (int)$question['cell_index'];
        $board[$cell]['team'] = $room['team'];
        $winner = find_board_winner($board, $room['team']);
        $results = json_decode($room['moves_json'], true);
        $results[] = [
            'cell' => $cell,
            'team' => $room['team'],
            'player_name' => $room['player_name'],
            'previous_team' => $room['current_team'],
            'question' => $question['question'],
            'answer' => $question['answer'],
            'submitted_answer' => $submittedAnswer,
            'resolved_at' => time(),
            'mode' => 'fastest_player'
        ];
        $opposingTeam = $room['team'] === 'red' ? 'blue' : 'red';
        $opponents = $conn->prepare('SELECT COUNT(*) AS total FROM game_players WHERE room_id = ? AND team = ?');
        $opponents->bind_param('is', $room['id'], $opposingTeam);
        $opponents->execute();
        $nextTeam = (int)$opponents->get_result()->fetch_assoc()['total'] > 0
            ? $opposingTeam
            : $room['team'];
        $boardJson = json_encode($board, JSON_UNESCAPED_UNICODE);
        $resultsJson = json_encode($results, JSON_UNESCAPED_UNICODE);
        $status = $winner === null ? 'playing' : 'finished';
        $update = $conn->prepare('UPDATE game_rooms SET board_json = ?, moves_json = ?, current_team = ?, winner = ?, status = ?, timer_deadline = NULL WHERE id = ?');
        $update->bind_param('sssssi', $boardJson, $resultsJson, $nextTeam, $winner, $status, $room['id']);
        $update->execute();
        $clearQuestion = $conn->prepare('DELETE FROM game_room_questions WHERE room_id = ?');
        $clearQuestion->bind_param('i', $room['id']);
        $clearQuestion->execute();
        $conn->commit();
        respond(['correct' => true, 'message' => 'إجابة صحيحة! فزت بالسؤال لفريقك.']);
    }

    if ($action === 'state' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        auto_resolve_expired_question($conn, (int)$state['room_id']);
        finish_room_if_board_has_winner($conn, (int)$state['room_id']);
        $playerStmt = $conn->prepare('SELECT p.room_id, p.player_name, p.team, r.room_code, r.max_players, r.status, r.board_json, r.current_team, r.winner, r.moves_json, r.timer_seconds, r.timer_deadline, r.answer_mode, r.title FROM game_players p JOIN game_rooms r ON r.id = p.room_id WHERE p.token = ?');
        $playerStmt->bind_param('s', $token);
        $playerStmt->execute();
        $state = $playerStmt->get_result()->fetch_assoc();
        $playersStmt = $conn->prepare('SELECT player_name, team FROM game_players WHERE room_id = ? ORDER BY joined_at, token');
        $playersStmt->bind_param('i', $state['room_id']);
        $playersStmt->execute();
        $players = $playersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $questionStmt = $conn->prepare('SELECT cell_index, question, answer, answer_revealed FROM game_room_questions WHERE room_id = ?');
        $questionStmt->bind_param('i', $state['room_id']);
        $questionStmt->execute();
        $question = $questionStmt->get_result()->fetch_assoc();
        respond([
            'room_code' => $state['room_code'],
            'title' => $state['title'] ?? 'مسابقة الحروف التفاعلية',
            'status' => $state['status'],
            'max_players' => (int)$state['max_players'],
            'players' => $players,
            'player' => ['name' => $state['player_name'], 'team' => $state['team']],
            'board' => json_decode($state['board_json'], true),
            'current_team' => $state['current_team'],
            'winner' => $state['winner'],
            'answer_mode' => $state['answer_mode'],
            'moves' => count(json_decode($state['moves_json'], true)),
            'timer_seconds' => (int)$state['timer_seconds'],
            'timer_deadline' => $state['timer_deadline'] === null ? null : (int)$state['timer_deadline'],
            'results' => json_decode($state['moves_json'], true),
            'scores' => [
                'red' => count(array_filter(json_decode($state['moves_json'], true), function ($result) { return ($result['team'] ?? null) === 'red'; })),
                'blue' => count(array_filter(json_decode($state['moves_json'], true), function ($result) { return ($result['team'] ?? null) === 'blue'; }))
            ],
            'question' => $question ? [
                'cell' => (int)$question['cell_index'],
                'question' => $question['question'],
                'answer' => (int)$question['answer_revealed'] === 1 ? $question['answer'] : null,
                'answer_visible' => (int)$question['answer_revealed'] === 1
            ] : null
        ]);
    }

    if (($action === 'select' || $action === 'reveal' || $action === 'cancel_question') &&
        $_SERVER['REQUEST_METHOD'] === 'POST') {
        $conn->begin_transaction();
        $roomStmt = $conn->prepare('SELECT r.id, r.status, r.board_json, r.current_team, r.timer_seconds, r.answer_mode, p.team FROM game_rooms r JOIN game_players p ON p.room_id = r.id WHERE p.token = ? FOR UPDATE');
        $roomStmt->bind_param('s', $token);
        $roomStmt->execute();
        $room = $roomStmt->get_result()->fetch_assoc();
        if (!$room || $room['status'] !== 'playing') {
            $conn->rollback();
            respond(['error' => 'اللعبة ليست قيد اللعب حالياً.'], 409);
        }
        if ($room['team'] !== $room['current_team']) {
            $conn->rollback();
            respond(['error' => 'انتظر دور فريقك.'], 409);
        }

        if ($action === 'select') {
            $cellIndex = filter_var($input['cell'] ?? null, FILTER_VALIDATE_INT);
            if ($cellIndex === false || $cellIndex < 0 || $cellIndex > 24) {
                $conn->rollback();
                respond(['error' => 'المربع المحدد غير صالح.'], 400);
            }
            $activeStmt = $conn->prepare('SELECT room_id FROM game_room_questions WHERE room_id = ?');
            $activeStmt->bind_param('i', $room['id']);
            $activeStmt->execute();
            if ($activeStmt->get_result()->num_rows > 0) {
                $conn->rollback();
                respond(['error' => 'يوجد سؤال قيد الإجابة حالياً.'], 409);
            }
            $board = json_decode($room['board_json'], true);
            if (!isset($board[$cellIndex]) || $board[$cellIndex]['team'] !== null) {
                $conn->rollback();
                respond(['error' => 'هذا المربع تم اختياره مسبقاً.'], 409);
            }
            $questionStmt = $conn->prepare('SELECT question, answer FROM questions WHERE letter = ? ORDER BY RAND() LIMIT 1');
            $letter = $board[$cellIndex]['letter'];
            $questionStmt->bind_param('s', $letter);
            $questionStmt->execute();
            $question = $questionStmt->get_result()->fetch_assoc();
            if (!$question) {
                if ($room['answer_mode'] === 'fastest_player') {
                    $conn->rollback();
                    respond(['error' => 'لا يوجد سؤال لهذا الحرف، اختر مربعاً آخر.'], 404);
                }
                $question = [
                    'question' => 'لم يتم إضافة سؤال لهذا الحرف بعد في قاعدة البيانات.',
                    'answer' => '-'
                ];
            }
            $insertQuestion = $conn->prepare('INSERT INTO game_room_questions (room_id, cell_index, question, answer) VALUES (?, ?, ?, ?)');
            $insertQuestion->bind_param('iiss', $room['id'], $cellIndex, $question['question'], $question['answer']);
            $insertQuestion->execute();
            $deadline = time() + (int)$room['timer_seconds'];
            $timer = $conn->prepare('UPDATE game_rooms SET timer_deadline = ? WHERE id = ?');
            $timer->bind_param('ii', $deadline, $room['id']);
            $timer->execute();
        } else        if ($action === 'reveal') {
            $conn->rollback();
            respond(['error' => 'عرض الإجابة متاح للمشرف فقط.'], 403);
        } else {
            $cancel = $conn->prepare('DELETE FROM game_room_questions WHERE room_id = ?');
            $cancel->bind_param('i', $room['id']);
            $cancel->execute();
            if ($cancel->affected_rows === 0) {
                $conn->rollback();
                respond(['error' => 'لا يوجد سؤال نشط لإلغائه.'], 409);
            }
            $timer = $conn->prepare('UPDATE game_rooms SET timer_deadline = NULL WHERE id = ?');
            $timer->bind_param('i', $room['id']);
            $timer->execute();
        }
        $conn->commit();
        respond(['ok' => true]);
    }

    if ($action === 'move' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        respond(['error' => 'نتيجة السؤال يحددها المشرف من لوحة الغرفة.'], 403);
    }

    if ($action === 'undo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        respond(['error' => 'التراجع عن النتائج متاح للمشرف فقط.'], 403);
    }

    if ($action === 'cancel_question' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        respond(['error' => 'إلغاء السؤال متاح للمشرف من لوحة الغرفة.'], 403);
    }

    respond(['error' => 'الطلب غير معروف.'], 404);
} catch (mysqli_sql_exception $error) {
    error_log('Multiplayer database error: ' . $error->getMessage());
    respond(['error' => 'حدث خطأ في قاعدة البيانات. تحقق من تشغيل MySQL وإعداد قاعدة game_db.'], 500);
}
