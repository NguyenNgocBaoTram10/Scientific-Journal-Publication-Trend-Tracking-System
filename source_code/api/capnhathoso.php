<?php
session_start();

header("Content-Type: application/json; charset=UTF-8");
require_once("../config/database.php");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Chỉ hỗ trợ phương thức POST."]);
    exit();
}

if (!isset($_SESSION["userId"])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Bạn chưa đăng nhập."]);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Dữ liệu JSON không hợp lệ."]);
    exit();
}

$userId = $_SESSION["userId"];
$fullName = preg_replace('/\s+/', ' ', trim($data["fullName"] ?? ""));
$username = trim($data["username"] ?? "");
$email = trim($data["email"] ?? "");

if ($fullName === "" || $username === "" || $email === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Vui lòng nhập đầy đủ thông tin."]);
    exit();
}

if (!preg_match("/^[\p{L}]+(\s+[\p{L}]+)+$/u", $fullName)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Họ và tên phải có ít nhất 2 từ và chỉ chứa chữ cái."]);
    exit();
}

if (!preg_match("/^[a-zA-Z0-9_]{4,20}$/", $username)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Tên đăng nhập phải từ 4 đến 20 ký tự, chỉ gồm chữ, số hoặc dấu gạch dưới."]);
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Email không hợp lệ."]);
    exit();
}

try {
    $stmt = $conn->prepare("SELECT userId FROM user WHERE username = ? AND userId <> ?");
    $stmt->execute([$username, $userId]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "Tên đăng nhập đã tồn tại."]);
        exit();
    }

    $stmt = $conn->prepare("SELECT userId FROM user WHERE email = ? AND userId <> ?");
    $stmt->execute([$email, $userId]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "Email đã được sử dụng."]);
        exit();
    }

    $stmt = $conn->prepare("UPDATE user SET fullName = ?, username = ?, email = ? WHERE userId = ?");
    $stmt->execute([$fullName, $username, $email, $userId]);

    $stmt = $conn->prepare("SELECT userId, fullName, username, email, status, roleId, createdAt FROM user WHERE userId = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $_SESSION["fullName"] = $user["fullName"];
    $_SESSION["username"] = $user["username"];
    $_SESSION["email"] = $user["email"];

    echo json_encode([
        "success" => true,
        "message" => "Cập nhật thông tin thành công.",
        "user" => $user
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Lỗi cơ sở dữ liệu.",
        "error" => $e->getMessage()
    ]);
}
?>