<?php
// api/dangnhap.php
session_start(); // Luôn gọi đầu tiên, trước mọi output/header khác

// ⚠️ Đổi lại đúng domain frontend thật của bạn khi lên hosting thật
// Khi test trên localhost XAMPP, để "*" tạm được NHƯNG sẽ không gửi được cookie session
// => bắt buộc phải ghi rõ origin (ví dụ http://localhost hoặc http://127.0.0.1:5500)
header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] == "OPTIONS") {
    exit();
}
header("Content-Type: application/json; charset=UTF-8");

require_once("../config/database.php");

// Chỉ cho phép POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Chỉ hỗ trợ phương thức POST."
    ]);
    exit();
}

// Đọc dữ liệu JSON
$data = json_decode(file_get_contents("php://input"), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Dữ liệu JSON không hợp lệ."
    ]);
    exit();
}

// Lấy dữ liệu
$username = trim($data["username"] ?? "");
$password = trim($data["password"] ?? "");

// Kiểm tra rỗng
if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Vui lòng nhập tên đăng nhập và mật khẩu."
    ]);
    exit();
}

try {

    // Tìm tài khoản theo username
    // Chỉ SELECT đúng 8 cột có thật trong bảng `user`
    $stmt = $conn->prepare("
        SELECT
            userId,
            fullName,
            username,
            email,
            password,
            status,
            roleId
        FROM user
        WHERE username = ?
    ");

    $stmt->execute([$username]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Không tìm thấy tài khoản
    if (!$user) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Tên đăng nhập hoặc mật khẩu không đúng."
        ]);
        exit();
    }

    // Kiểm tra trạng thái
    if ($user["status"] != "active") {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Tài khoản đã bị khóa."
        ]);
        exit();
    }

    // Kiểm tra mật khẩu
    if (!password_verify($password, $user["password"])) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Tên đăng nhập hoặc mật khẩu không đúng."
        ]);
        exit();
    }

    // Đăng nhập thành công -> chống session fixation
    session_regenerate_id(true);

    $_SESSION["userId"]   = $user["userId"];
    $_SESSION["fullName"] = $user["fullName"];
    $_SESSION["username"] = $user["username"];
    $_SESSION["email"]    = $user["email"];
    $_SESSION["roleId"]   = $user["roleId"];

    echo json_encode([
        "success" => true,
        "message" => "Đăng nhập thành công.",
        "user" => [
            "userId"   => $user["userId"],
            "fullName" => $user["fullName"],
            "username" => $user["username"],
            "email"    => $user["email"],
            "roleId"   => $user["roleId"]
        ]
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Lỗi cơ sở dữ liệu.",
        "error" => $e->getMessage()
    ]);
}