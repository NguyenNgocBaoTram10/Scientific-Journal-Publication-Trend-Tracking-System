<?php
/**
 * API: Thông báo in-app
 * Database: sjptts  |  Table: notification
 *
 * Columns:
 *   notificationId  INT  AUTO_INCREMENT PK
 *   title           VARCHAR(300)
 *   content         TEXT  nullable
 *   isRead          TINYINT(1)  default 0
 *   createdAt       DATETIME    default CURRENT_TIMESTAMP
 *   userId          INT
 *
 * GET  /api/thongbao.php
 *   → { notifications: [...], unread_count: N }
 *
 * POST /api/thongbao.php
 *   { action: "mark_read",    id: 5 }
 *   { action: "mark_all_read" }
 */

// Bắt mọi output ngoài ý muốn để JSON luôn sạch
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); ob_end_clean(); exit; }

require_once __DIR__ . '/../config/database.php';
// $pdo được tạo trong config/database.php, kết nối database "sjptts"

/* ── Helper: user hiện tại ── */
function getCurrentUserId(): int {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return (int)($_SESSION['userId'] ?? 0);
}
$userId = getCurrentUserId();
if ($userId <= 0) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['error' => 'Bạn cần đăng nhập để sử dụng tính năng này.']);
    exit;
}
/* ── Helper: thời gian tương đối ── */
function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'Vừa xong';
    if ($diff < 3600)   return floor($diff / 60)   . ' phút trước';
    if ($diff < 86400)  return floor($diff / 3600)  . ' giờ trước';
    if ($diff < 604800) return floor($diff / 86400) . ' ngày trước';
    return date('d/m/Y', strtotime($datetime));
}


$method = $_SERVER['REQUEST_METHOD'];

try {

    /* ══════════════════════════════════════
       GET — Lấy danh sách thông báo
    ══════════════════════════════════════ */
    if ($method === 'GET') {

        $stmt = $conn->prepare("
            SELECT notificationId, title, content, isRead, createdAt
            FROM   notification
            WHERE  userId = :uid
            ORDER  BY createdAt DESC
            LIMIT  50
        ");
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $notifications = array_map(function ($row) {
            return [
                'id'       => (int)  $row['notificationId'],
                'title'    =>        $row['title'],
                'message'  =>        $row['content'] ?? '',
                'is_read'  => (bool) $row['isRead'],
                'time_ago' => timeAgo($row['createdAt']),
            ];
        }, $rows);

        // Đếm chưa đọc
        $cntStmt = $conn->prepare("
            SELECT COUNT(*) FROM notification
            WHERE userId = :uid AND isRead = 0
        ");
        $cntStmt->execute([':uid' => $userId]);
        $unread = (int) $cntStmt->fetchColumn();

        ob_clean();
        echo json_encode([
            'notifications' => $notifications,
            'unread_count'  => $unread,
        ]);

    /* ══════════════════════════════════════
       POST — Đánh dấu đã đọc
    ══════════════════════════════════════ */
    } elseif ($method === 'POST') {

        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $body['action'] ?? '';

        if ($action === 'mark_read') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                ob_clean();
                echo json_encode(['error' => 'Thiếu id.']);
                exit;
            }
            $stmt = $conn->prepare("
                UPDATE notification
                SET    isRead = 1
                WHERE  notificationId = :id AND userId = :uid
            ");
            $stmt->execute([':id' => $id, ':uid' => $userId]);
            ob_clean();
            echo json_encode(['success' => true]);

        } elseif ($action === 'mark_all_read') {
            $stmt = $conn->prepare("
                UPDATE notification
                SET    isRead = 1
                WHERE  userId = :uid AND isRead = 0
            ");
            $stmt->execute([':uid' => $userId]);
            ob_clean();
            echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);

        } else {
            http_response_code(400);
            ob_clean();
            echo json_encode(['error' => 'action không hợp lệ. Dùng "mark_read" hoặc "mark_all_read".']);
        }

    } else {
        http_response_code(405);
        ob_clean();
        echo json_encode(['error' => 'Method không được hỗ trợ.']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    ob_clean();
    echo json_encode(['error' => 'Lỗi cơ sở dữ liệu: ' . $e->getMessage()]);
}