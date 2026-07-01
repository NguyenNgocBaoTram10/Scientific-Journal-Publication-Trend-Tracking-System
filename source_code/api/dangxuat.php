<?php
// api/dangxuat.php
session_start();

// ⚠️ Đổi lại đúng domain frontend thật của bạn
header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

// Xoá toàn bộ dữ liệu session
$_SESSION = [];

// Xoá cookie session phía trình duyệt (nếu có)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        "",
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();

echo json_encode([
    "success" => true,
    "message" => "Đăng xuất thành công."
]);