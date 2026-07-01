<?php
header("Content-Type: application/json; charset=UTF-8");

require_once("../config/database.php");
require_once("admin_check.php");

$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {
    $stmt = $conn->prepare("
        SELECT 
            n.notificationId,
            n.title,
            n.content,
            n.type,
            n.link,
            n.isRead,
            n.createdAt,
            n.userId,
            u.fullName,
            u.username
        FROM notification n
        LEFT JOIN user u ON n.userId = u.userId
        ORDER BY n.notificationId DESC
    ");
    $stmt->execute();

    echo json_encode([
        "success" => true,
        "notifications" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
    exit();
}

if ($method === "POST") {
    $data = json_decode(file_get_contents("php://input"), true);

    $action = $data["action"] ?? "";
    $notificationId = (int)($data["notificationId"] ?? 0);
    $title = trim($data["title"] ?? "");
    $content = trim($data["content"] ?? "");
    $type = trim($data["type"] ?? "system");
    $link = trim($data["link"] ?? "");
    $userId = (int)($data["userId"] ?? 0);
    $sendAll = (int)($data["sendAll"] ?? 0);

    if ($action === "create") {
        if ($title === "" || $content === "") {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Tiêu đề và nội dung không được để trống."
            ]);
            exit();
        }

        if ($sendAll === 1) {
            $users = $conn->prepare("SELECT userId FROM user WHERE status = 'active'");
            $users->execute();
            $userList = $users->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $conn->prepare("
                INSERT INTO notification (title, content, type, link, isRead, userId)
                VALUES (?, ?, ?, ?, 0, ?)
            ");

            foreach ($userList as $user) {
                $stmt->execute([$title, $content, $type, $link, $user["userId"]]);
            }

            echo json_encode([
                "success" => true,
                "message" => "Đã gửi thông báo cho tất cả user."
            ]);
            exit();
        }

        if ($userId <= 0) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Vui lòng chọn user nhận thông báo."
            ]);
            exit();
        }

        $stmt = $conn->prepare("
            INSERT INTO notification (title, content, type, link, isRead, userId)
            VALUES (?, ?, ?, ?, 0, ?)
        ");
        $stmt->execute([$title, $content, $type, $link, $userId]);

        echo json_encode([
            "success" => true,
            "message" => "Tạo thông báo thành công."
        ]);
        exit();
    }

    if ($action === "delete") {
        if ($notificationId <= 0) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Thiếu notificationId."
            ]);
            exit();
        }

        $stmt = $conn->prepare("DELETE FROM notification WHERE notificationId = ?");
        $stmt->execute([$notificationId]);

        echo json_encode([
            "success" => true,
            "message" => "Xóa thông báo thành công."
        ]);
        exit();
    }

    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Action không hợp lệ."
    ]);
    exit();
}

http_response_code(405);
echo json_encode([
    "success" => false,
    "message" => "Phương thức không được hỗ trợ."
]);
?>