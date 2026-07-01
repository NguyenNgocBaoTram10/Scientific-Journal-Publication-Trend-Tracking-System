<?php
header("Content-Type: application/json; charset=UTF-8");

require_once("../config/database.php");
require_once("admin_check.php");

$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {
    $stmt = $conn->prepare("
        SELECT sourceId, sourceName, baseUrl, description, isActive, priority, createdAt
        FROM api_source
        ORDER BY priority ASC, sourceId ASC
    ");
    $stmt->execute();

    echo json_encode([
        "success" => true,
        "sources" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
    exit();
}

if ($method === "POST") {
    $data = json_decode(file_get_contents("php://input"), true);

    $action = $data["action"] ?? "";
    $sourceId = (int)($data["sourceId"] ?? 0);
    $sourceName = trim($data["sourceName"] ?? "");
    $baseUrl = trim($data["baseUrl"] ?? "");
    $description = trim($data["description"] ?? "");
    $priority = (int)($data["priority"] ?? 1);
    $isActive = isset($data["isActive"]) ? (int)$data["isActive"] : 1;

    if ($action === "create") {
        if ($sourceName === "" || $baseUrl === "") {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Tên nguồn và Base URL không được để trống."
            ]);
            exit();
        }

        $stmt = $conn->prepare("
            INSERT INTO api_source (sourceName, baseUrl, description, isActive, priority)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$sourceName, $baseUrl, $description, $isActive, $priority]);

        echo json_encode([
            "success" => true,
            "message" => "Thêm nguồn API thành công."
        ]);
        exit();
    }

    if ($action === "update") {
        if ($sourceId <= 0 || $sourceName === "" || $baseUrl === "") {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Thiếu sourceId, tên nguồn hoặc Base URL."
            ]);
            exit();
        }

        $stmt = $conn->prepare("
            UPDATE api_source
            SET sourceName = ?, baseUrl = ?, description = ?, isActive = ?, priority = ?
            WHERE sourceId = ?
        ");
        $stmt->execute([$sourceName, $baseUrl, $description, $isActive, $priority, $sourceId]);

        echo json_encode([
            "success" => true,
            "message" => "Cập nhật nguồn API thành công."
        ]);
        exit();
    }

    if ($action === "toggle") {
        if ($sourceId <= 0) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Thiếu sourceId."
            ]);
            exit();
        }

        $stmt = $conn->prepare("
            UPDATE api_source
            SET isActive = 1 - isActive
            WHERE sourceId = ?
        ");
        $stmt->execute([$sourceId]);

        echo json_encode([
            "success" => true,
            "message" => "Đã đổi trạng thái nguồn API."
        ]);
        exit();
    }

    if ($action === "delete") {
        if ($sourceId <= 0) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Thiếu sourceId."
            ]);
            exit();
        }

        $stmt = $conn->prepare("DELETE FROM api_source WHERE sourceId = ?");
        $stmt->execute([$sourceId]);

        echo json_encode([
            "success" => true,
            "message" => "Xóa nguồn API thành công."
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