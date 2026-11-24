<?php
require_once __DIR__ . '/database.php';
$conn = letters_game_db_connection();

if ($conn->connect_error) {
    die(json_encode(["error" => "فشل الاتصال بقاعدة البيانات"]));
}

// استقبال الحرف من اللعبة
$letter = isset($_GET['letter']) ? $_GET['letter'] : '';

// استعلام لجلب سؤال عشوائي يخص هذا الحرف
$stmt = $conn->prepare('SELECT question, answer FROM questions WHERE letter = ? ORDER BY RAND() LIMIT 1');
$stmt->bind_param('s', $letter);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo json_encode($row); // إرسال السؤال والإجابة بصيغة JSON للعبة
} else {
    echo json_encode(["question" => "لم يتم إضافة سؤال لهذا الحرف بعد في قاعدة البيانات.", "answer" => "-"]);
}

$conn->close();
?>