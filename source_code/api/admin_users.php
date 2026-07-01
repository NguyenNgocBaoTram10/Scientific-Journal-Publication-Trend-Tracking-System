<?php
header("Content-Type: application/json; charset=UTF-8");

require_once("../config/database.php");
require_once("admin_check.php");

$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {
    $stmt = $conn->prepare("
        SELECT userId, fullName, username, email, status, roleId, createdAt
        FROM user
        ORDER BY userId DESC
    ");
    $stmt->execute();

    echo json_encode([
        "success" => true,
        "users" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
    exit();
}

if ($method === "POST") {
    $data = json_decode(file_get_contents("php://input"), true);

    $userId = (int)($data["userId"] ?? 0);
    $action = $data["action"] ?? "";

    if ($userId <= 0 || $action === "") {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Thiếu userId hoặc action."
        ]);
        exit();
    }

    if ($userId == $_SESSION["userId"] && in_array($action, ["lock", "make_user"])) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Không thể tự khóa hoặc hạ quyền chính mình."
        ]);
        exit();
    }

    if ($action === "lock") {
        $stmt = $conn->prepare("UPDATE user SET status = 'locked' WHERE userId = ?");
        $stmt->execute([$userId]);
    } elseif ($action === "unlock") {
        $stmt = $conn->prepare("UPDATE user SET status = 'active' WHERE userId = ?");
        $stmt->execute([$userId]);
    } elseif ($action === "make_admin") {
        $stmt = $conn->prepare("UPDATE user SET roleId = 1 WHERE userId = ?");
        $stmt->execute([$userId]);
    } elseif ($action === "make_user") {
        $stmt = $conn->prepare("UPDATE user SET roleId = 2 WHERE userId = ?");
        $stmt->execute([$userId]);
    } else {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Action không hợp lệ."
        ]);
        exit();
    }

    echo json_encode([
        "success" => true,
        "message" => "Cập nhật user thành công."
    ]);
    exit();
}

http_response_code(405);
echo json_encode([
    "success" => false,
    "message" => "Phương thức không được hỗ trợ."
]);
?>