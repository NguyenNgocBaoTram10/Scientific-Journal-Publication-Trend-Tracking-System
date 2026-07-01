<?php
// api/check_session.php
// Dùng để trang chủ / trang thông tin tài khoản gọi tới, lấy thông tin người đang đăng nhập
session_start();

// ⚠️ Đổi lại đúng domain frontend thật của bạn
header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

if (!isset($_SESSION["userId"])) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Chưa đăng nhập hoặc phiên đã hết hạn."
    ]);
    exit();
}

echo json_encode([
    "success" => true,
    "user" => [
        "userId"   => $_SESSION["userId"],
        "fullName" => $_SESSION["fullName"],
        "username" => $_SESSION["username"],
        "email"    => $_SESSION["email"],
        "roleId"   => $_SESSION["roleId"]
        // Không có avatarUrl vì bảng `user` chưa có cột này.
        // Nếu sau này thêm cột avatarUrl vào DB, chỉ cần thêm dòng:
        // "avatarUrl" => $_SESSION["avatarUrl"] ?? null
    ]
]);