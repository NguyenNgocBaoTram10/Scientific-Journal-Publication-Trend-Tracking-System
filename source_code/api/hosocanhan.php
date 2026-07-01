<?php
// api/hosocanhan.php - Trả về thông tin của người dùng ĐANG đăng nhập (theo session)
// Frontend gọi GET tới file này để đổ dữ liệu vào avatar/dropdown/trang hồ sơ

require_once("_cors.php");
require_once("../config/database.php");

if (empty($_SESSION["userId"])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Bạn chưa đăng nhập."]);
    exit();
}

try {
    // Luôn truy vấn lại CSDL theo userId trong session để đảm bảo
    // dữ liệu mới nhất và đúng đúng user đang đăng nhập.
    $stmt = $conn->prepare("
        SELECT userId, fullName, username, email, roleId, status
        FROM user
        WHERE userId = ?
    ");
    $stmt->execute([$_SESSION["userId"]]);
    $user = $stmt->fetch();

    if (!$user || $user["status"] != "active") {
        session_unset();
        session_destroy();
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Phiên đăng nhập không hợp lệ."]);
        exit();
    }

    echo json_encode([
        "success" => true,
        "user" => [
            "userId"    => $user["userId"],
            "fullName"  => $user["fullName"],
            "username"  => $user["username"],
            "email"     => $user["email"],
            "roleId"    => $user["roleId"]
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Lỗi cơ sở dữ liệu.", "error" => $e->getMessage()]);
}