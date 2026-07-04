<?php
header("Content-Type: application/json; charset=UTF-8");

require_once("../config/database.php");
require_once("admin_check.php");

$method = $_SERVER["REQUEST_METHOD"];

try {
    if ($method === "GET") {
        $stmt = $conn->prepare("
            SELECT
                rp.articleId,
                rp.title,
                rp.authors,
                rp.journal,
                rp.publishedYear,
                rp.doi,
                rp.abstract,
                rp.url,
                rp.topicId,
                t.topicName
            FROM researchpaper rp
            LEFT JOIN topic t ON rp.topicId = t.topicId
            ORDER BY rp.articleId DESC
        ");
        $stmt->execute();

        echo json_encode([
            "success" => true,
            "papers" => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($method === "POST") {
        $data = json_decode(file_get_contents("php://input"), true) ?: [];

        $action = $data["action"] ?? "";
        $articleId = (int)($data["articleId"] ?? 0);
        $title = trim($data["title"] ?? "");
        $authors = trim($data["authors"] ?? "");
        $journal = trim($data["journal"] ?? "");
        $publishedYear = ($data["publishedYear"] ?? "") === "" ? null : (int)$data["publishedYear"];
        $doi = trim($data["doi"] ?? "");
        $abstract = trim($data["abstract"] ?? "");
        $url = trim($data["url"] ?? "");
        $topicId = (int)($data["topicId"] ?? 0);
        $topicId = $topicId > 0 ? $topicId : null;

        if ($action === "create") {
            if ($title === "") {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Tiêu đề không được để trống."], JSON_UNESCAPED_UNICODE);
                exit();
            }

            if ($doi !== "") {
                $check = $conn->prepare("SELECT articleId FROM researchpaper WHERE doi = ? LIMIT 1");
                $check->execute([$doi]);
                if ($check->fetch()) {
                    http_response_code(400);
                    echo json_encode(["success" => false, "message" => "DOI đã tồn tại trong hệ thống."], JSON_UNESCAPED_UNICODE);
                    exit();
                }
            }

            $stmt = $conn->prepare("
                INSERT INTO researchpaper
                    (title, authors, journal, publishedYear, doi, abstract, url, topicId)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$title, $authors, $journal, $publishedYear, $doi, $abstract, $url, $topicId]);

            echo json_encode(["success" => true, "message" => "Thêm bài báo thành công."], JSON_UNESCAPED_UNICODE);
            exit();
        }

        if ($action === "update") {
            if ($articleId <= 0 || $title === "") {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Thiếu articleId hoặc tiêu đề."], JSON_UNESCAPED_UNICODE);
                exit();
            }

            if ($doi !== "") {
                $check = $conn->prepare("SELECT articleId FROM researchpaper WHERE doi = ? AND articleId <> ? LIMIT 1");
                $check->execute([$doi, $articleId]);
                if ($check->fetch()) {
                    http_response_code(400);
                    echo json_encode(["success" => false, "message" => "DOI đã tồn tại trong hệ thống."], JSON_UNESCAPED_UNICODE);
                    exit();
                }
            }

            $stmt = $conn->prepare("
                UPDATE researchpaper
                SET title = ?, authors = ?, journal = ?, publishedYear = ?, doi = ?, abstract = ?, url = ?, topicId = ?
                WHERE articleId = ?
            ");
            $stmt->execute([$title, $authors, $journal, $publishedYear, $doi, $abstract, $url, $topicId, $articleId]);

            echo json_encode(["success" => true, "message" => "Cập nhật bài báo thành công."], JSON_UNESCAPED_UNICODE);
            exit();
        }

        if ($action === "delete") {
            if ($articleId <= 0) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Thiếu articleId."], JSON_UNESCAPED_UNICODE);
                exit();
            }

            $stmt = $conn->prepare("DELETE FROM researchpaper WHERE articleId = ?");
            $stmt->execute([$articleId]);

            echo json_encode(["success" => true, "message" => "Xóa bài báo thành công."], JSON_UNESCAPED_UNICODE);
            exit();
        }

        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Action không hợp lệ."], JSON_UNESCAPED_UNICODE);
        exit();
    }

    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Phương thức không được hỗ trợ."], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Lỗi cơ sở dữ liệu.",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>