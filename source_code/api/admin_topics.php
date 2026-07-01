<?php
header("Content-Type: application/json; charset=UTF-8");

require_once("../config/database.php");
require_once("admin_check.php");

$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {
    $stmt = $conn->prepare("
        SELECT topicId, topicName, description, createdAt
        FROM topic
        ORDER BY topicId DESC
    ");
    $stmt->execute();

    echo json_encode([
        "success" => true,
        "topics" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
    exit();
}

if ($method === "POST") {
    $data = json_decode(file_get_contents("php://input"), true);

    $action = $data["action"] ?? "";
    $topicId = (int)($data["topicId"] ?? 0);
    $topicName = trim($data["topicName"] ?? "");
    $description = trim($data["description"] ?? "");

    if ($action === "create") {
        if ($topicName === "") {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Tên chủ đề không được để trống."
            ]);
            exit();
        }

        $stmt = $conn->prepare("
            INSERT INTO topic (topicName, description)
            VALUES (?, ?)
        ");
        $stmt->execute([$topicName, $description]);

        echo json_encode([
            "success" => true,
            "message" => "Thêm chủ đề thành công."
        ]);
        exit();
    }

    if ($action === "update") {
        if ($topicId <= 0 || $topicName === "") {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Thiếu topicId hoặc tên chủ đề."
            ]);
            exit();
        }

        $stmt = $conn->prepare("
            UPDATE topic
            SET topicName = ?, description = ?
            WHERE topicId = ?
        ");
        $stmt->execute([$topicName, $description, $topicId]);

        echo json_encode([
            "success" => true,
            "message" => "Cập nhật chủ đề thành công."
        ]);
        exit();
    }

    if ($action === "delete") {
        if ($topicId <= 0) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Thiếu topicId."
            ]);
            exit();
        }

        $stmt = $conn->prepare("DELETE FROM topic WHERE topicId = ?");
        $stmt->execute([$topicId]);

        echo json_encode([
            "success" => true,
            "message" => "Xóa chủ đề thành công."
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