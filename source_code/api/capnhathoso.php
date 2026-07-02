<?php
session_start();
require_once("../config/database.php");

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_SESSION["userId"])) {
    echo json_encode(["success" => false, "message" => "Chưa đăng nhập"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$fullName = trim($data["fullName"] ?? "");
$username = trim($data["username"] ?? "");
$email = trim($data["email"] ?? "");
$userId = $_SESSION["userId"];

if ($fullName === "" || $username === "" || $email === "") {
    echo json_encode(["success" => false, "message" => "Vui lòng nhập đầy đủ thông tin"]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["success" => false, "message" => "Email không hợp lệ"]);
    exit;
}

try {
    $checkUsername = $conn->prepare("SELECT userId FROM user WHERE username = ? AND userId <> ?");
    $checkUsername->execute([$username, $userId]);
    if ($checkUsername->fetch()) {
        echo json_encode(["success" => false, "message" => "Tên đăng nhập đã tồn tại"]);
        exit;
    }

    $checkEmail = $conn->prepare("SELECT userId FROM user WHERE email = ? AND userId <> ?");
    $checkEmail->execute([$email, $userId]);
    if ($checkEmail->fetch()) {
        echo json_encode(["success" => false, "message" => "Email đã tồn tại"]);
        exit;
    }

    $stmt = $conn->prepare("UPDATE user SET fullName = ?, username = ?, email = ? WHERE userId = ?");
    $stmt->execute([$fullName, $username, $email, $userId]);

    $_SESSION["fullName"] = $fullName;
    $_SESSION["username"] = $username;
    $_SESSION["email"] = $email;

    echo json_encode(["success" => true, "message" => "Cập nhật thành công"]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Lỗi cập nhật", "error" => $e->getMessage()]);
}