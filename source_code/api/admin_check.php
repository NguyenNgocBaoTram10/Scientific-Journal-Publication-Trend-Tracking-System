<?php
session_start();

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_SESSION["userId"])) {
    http_response_code(401);
    echo json_encode([
        "success"=> false,
        "message"=> "Bạn chưa đăng nhập."
    ]);
    exit();
}

if (!isset($_SESSION["roleId"]) || $_SESSION["roleId"] != 1) {
    http_response_code(403);
    echo json_encode([
        "success"=> false,
        "message"=> "Bạn chưa đăng nhập."
    ]);
    exit();
}
if (!isset($_SESSION["roleId"])|| $_SESSION["roleId"] != 1) {
    http_response_code(403);
    echo json_encode([
        "success" => false,
        "message" => "Bạn không có quyền admin."
    ]);
    exit();
}
?>
