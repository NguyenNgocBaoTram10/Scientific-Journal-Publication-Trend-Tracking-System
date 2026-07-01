<?php
/**
 * API: Theo dõi / Hủy theo dõi chủ đề
 * Database: sjptts
 *
 * POST /api/theodoichude.php
 *   { topic: "deep learning", follow: true|false }
 *   → { success: true }
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); ob_end_clean(); exit; }

require_once __DIR__ . '/../config/database.php';
// $pdo được tạo trong config/database.php


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
$userId = getCurrentUserId();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {

    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $topic  = trim($body['topic'] ?? '');
    $follow = (bool)($body['follow'] ?? true);

    if ($topic === '') {
        http_response_code(400);
        ob_clean();
        echo json_encode(['error' => 'Thiếu tham số "topic".']);
        exit;
    }

    try {
        $stmt = $conn->prepare("SELECT topicId FROM topic WHERE LOWER(topicName) = LOWER(:name) LIMIT 1");
        $stmt->execute([':name' => $topic]);
        $topicId = $stmt->fetchColumn();

     if (!$topicId) {
    $ins = $conn->prepare("INSERT INTO topic (topicName, description, journalId) VALUES (:name, NULL, NULL)");
    $ins->execute([':name' => $topic]);
    $topicId = $conn->lastInsertId();
}

      $chk = $conn->prepare("SELECT 1 FROM userfollowtopic WHERE userId = :uid AND topicId = :tid LIMIT 1");
$chk->execute([':uid' => $userId, ':tid' => $topicId]);
$exists = $chk->fetchColumn();

if ($follow) {
    if (!$exists) {
        $ins2 = $conn->prepare("INSERT INTO userfollowtopic (userId, topicId) VALUES (:uid, :tid)");
        $ins2->execute([':uid' => $userId, ':tid' => $topicId]);
    }
} else {
    if ($exists) {
        $del = $conn->prepare("DELETE FROM userfollowtopic WHERE userId = :uid AND topicId = :tid");
        $del->execute([':uid' => $userId, ':tid' => $topicId]);
    }
}

        ob_clean();
        echo json_encode(['success' => true, 'topic' => $topic, 'followed' => $follow]);

    } catch (Throwable $e) {
        http_response_code(500);
        ob_clean();
        echo json_encode(['error' => 'Lỗi khi cập nhật theo dõi: ' . $e->getMessage()]);
    }
    exit;

} else {
    http_response_code(405);
    ob_clean();
    echo json_encode(['error' => 'Method không được hỗ trợ. Dùng POST.']);
}