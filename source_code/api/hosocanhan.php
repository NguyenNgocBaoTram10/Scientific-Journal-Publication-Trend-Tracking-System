<?php
session_start();

header("Content-Type: application/json; charset=UTF-8");

require_once("../config/database.php");

if (!isset($_SESSION["userId"])) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Bạn chưa đăng nhập."
    ]);
    exit();
}

$stmt = $conn->prepare("
    SELECT userId, fullName, username, email, status, roleId, createdAt
    FROM user
    WHERE userId = ?
");

$stmt->execute([$_SESSION["userId"]]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    "success" => true,
    "user" => $user
]);
?>