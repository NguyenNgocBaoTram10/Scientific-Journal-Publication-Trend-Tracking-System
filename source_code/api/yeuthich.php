<?php
// ============================================================
// yeuthich.php — ScholarTrend Bookmark API (khớp schema thật: researchpaper + bookmark)
// Đặt tại: source_code/api/yeuthich.php
//
// Hành động (param "action"):
//   action=save    (POST) — lưu 1 bài báo: tìm/tạo researchpaper rồi tạo bookmark
//   action=list    (GET)  — lấy danh sách bookmark (join với researchpaper) của user
//   action=delete  (POST) — xoá 1 bookmark theo bookmarkId
// ============================================================

ini_set('display_errors', '0');
error_reporting(E_ALL);

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok'    => false,
            'error' => 'Lỗi server nội bộ: ' . $e['message'] . ' (dòng ' . $e['line'] . ' trong ' . basename($e['file']) . ')',
        ], JSON_UNESCAPED_UNICODE);
    }
});

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$dbPath = __DIR__ . '/../config/database.php';
if (!file_exists($dbPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Không tìm thấy file config/database.php.'], JSON_UNESCAPED_UNICODE);
    exit;
}
require $dbPath; // tạo sẵn biến $conn (PDO)

if (!isset($conn) || !($conn instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Kết nối CSDL ($conn) chưa khởi tạo đúng.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$pdo = $conn;
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function err(string $msg, int $code = 400): void
{
    respond(['ok' => false, 'error' => $msg], $code);
}

// ⚠️ Khớp đúng theo dangnhap.php: $_SESSION["userId"]
$userId = isset($_SESSION['userId']) ? (int)$_SESSION['userId'] : 0;

if ($userId <= 0) {
    err('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.', 401);
}

$action = trim(isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : 'save'));

// Map nguồn dữ liệu -> sourceId trong bảng apisource
// (chạy seed_apisource.sql trước: OpenAlex thường là id 1, Crossref là id 2 — kiểm tra lại nếu khác)
function getSourceId(PDO $pdo, ?string $sourceName): int
{
    $name = $sourceName === 'crossref' ? 'Crossref' : 'OpenAlex'; // mặc định OpenAlex
    $stmt = $pdo->prepare('SELECT sourceId FROM apisource WHERE sourceName = :n LIMIT 1');
    $stmt->execute(['n' => $name]);
    $row = $stmt->fetch();
    if ($row) return (int)$row['sourceId'];

    // Chưa có thì tự tạo luôn để không bị lỗi (phòng trường hợp quên chạy seed_apisource.sql)
    $ins = $pdo->prepare('INSERT INTO apisource (sourceName, endpointUrl) VALUES (:n, :u)');
    $ins->execute([
        'n' => $name,
        'u' => $name === 'Crossref' ? 'https://api.crossref.org' : 'https://api.openalex.org',
    ]);
    return (int)$pdo->lastInsertId();
}

// ════════════════════════════════════════════════════════════
// ACTION: save
// ════════════════════════════════════════════════════════════
if ($action === 'save') {

    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) $input = $_POST;

    $title = trim(isset($input['title']) ? (string)$input['title'] : '');
    $doi   = trim(isset($input['doi']) ? (string)$input['doi'] : '');
    $abstract = isset($input['abstract']) ? (string)$input['abstract'] : null;
    $year  = isset($input['year']) && $input['year'] !== null ? (int)$input['year'] : null;
    $sourceName = isset($input['source']) ? (string)$input['source'] : 'openalex';

    if ($title === '') {
        err('Thiếu thông tin bài báo (title). Vui lòng kiểm tra lại dữ liệu gửi lên.');
    }

    try {
        $sourceId = getSourceId($pdo, $sourceName);

        // 1) Tìm bài báo đã tồn tại trong researchpaper chưa (ưu tiên theo DOI, fallback theo title)
        $articleId = null;
        if ($doi !== '') {
            $find = $pdo->prepare('SELECT articleId FROM researchpaper WHERE doi = :doi LIMIT 1');
            $find->execute(['doi' => $doi]);
            $r = $find->fetch();
            if ($r) $articleId = (int)$r['articleId'];
        }
        if ($articleId === null) {
            $find = $pdo->prepare('SELECT articleId FROM researchpaper WHERE title = :title LIMIT 1');
            $find->execute(['title' => $title]);
            $r = $find->fetch();
            if ($r) $articleId = (int)$r['articleId'];
        }

        // 2) Chưa có thì tạo mới researchpaper
        if ($articleId === null) {
            $insPaper = $pdo->prepare(
                'INSERT INTO researchpaper (title, abstract, doi, publicationYear, sourceId)
                 VALUES (:title, :abstract, :doi, :year, :sourceId)'
            );
            $insPaper->execute([
                'title'    => mb_substr($title, 0, 500),
                'abstract' => $abstract,
                'doi'      => $doi !== '' ? mb_substr($doi, 0, 200) : null,
                'year'     => $year,
                'sourceId' => $sourceId,
            ]);
            $articleId = (int)$pdo->lastInsertId();
        }

        // 3) Use case: Paper Already Bookmarked -> kiểm tra trùng trước khi tạo bookmark
        $check = $pdo->prepare('SELECT bookmarkId FROM bookmark WHERE userId = :uid AND articleId = :aid LIMIT 1');
        $check->execute(['uid' => $userId, 'aid' => $articleId]);
        $existing = $check->fetch();

        if ($existing) {
            respond([
                'ok'      => true,
                'already' => true,
                'message' => 'Bài báo này đã có trong danh sách bookmark của bạn.',
                'data'    => ['bookmarkId' => $existing['bookmarkId']],
            ]);
        }

        $insBookmark = $pdo->prepare(
            'INSERT INTO bookmark (userId, articleId) VALUES (:uid, :aid)'
        );
        $insBookmark->execute(['uid' => $userId, 'aid' => $articleId]);

        respond([
            'ok'      => true,
            'already' => false,
            'message' => 'Đã lưu bài báo vào bookmark thành công.',
            'data'    => ['bookmarkId' => $pdo->lastInsertId(), 'articleId' => $articleId],
        ]);

    } catch (PDOException $e) {
        err('Không thể lưu bookmark do lỗi cơ sở dữ liệu: ' . $e->getMessage(), 500);
    }
}

// ════════════════════════════════════════════════════════════
// ACTION: list
// ════════════════════════════════════════════════════════════
if ($action === 'list') {
    try {
        $stmt = $pdo->prepare(
            'SELECT
                b.bookmarkId, b.bookmarkedAt,
                p.articleId, p.title, p.abstract, p.doi, p.publicationYear,
                s.sourceName
             FROM bookmark b
             JOIN researchpaper p ON p.articleId = b.articleId
             LEFT JOIN apisource s ON s.sourceId = p.sourceId
             WHERE b.userId = :uid
             ORDER BY b.bookmarkedAt DESC'
        );
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll();

        // Chuẩn hoá để khớp với field mà frontend (yeuthich.html) đang đọc
        $data = array_map(function ($r) {
            $doi = $r['doi'];
            return [
                'id'           => $r['bookmarkId'],
                'title'        => $r['title'],
                'authors'      => null, // chưa liên kết bảng author trong bản này
                'journal_name' => null, // chưa liên kết bảng journal trong bản này
                'year'         => $r['publicationYear'],
                'pdf_url'      => null,
                'doi_url'      => $doi ? "https://doi.org/{$doi}" : null,
                'source'       => $r['sourceName'],
                'created_at'   => $r['bookmarkedAt'],
            ];
        }, $rows);

        respond(['ok' => true, 'total' => count($data), 'data' => $data]);
    } catch (PDOException $e) {
        err('Không thể tải danh sách bookmark: ' . $e->getMessage(), 500);
    }
}

// ════════════════════════════════════════════════════════════
// ACTION: delete
// ════════════════════════════════════════════════════════════
if ($action === 'delete') {
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) $input = $_POST;

    $bookmarkId = (int)(isset($input['id']) ? $input['id'] : (isset($_GET['id']) ? $_GET['id'] : 0));
    if ($bookmarkId <= 0) err('Thiếu id bookmark cần xoá.');

    try {
        $stmt = $pdo->prepare('DELETE FROM bookmark WHERE bookmarkId = :id AND userId = :uid');
        $stmt->execute(['id' => $bookmarkId, 'uid' => $userId]);

        if ($stmt->rowCount() === 0) {
            err('Không tìm thấy bookmark hoặc bạn không có quyền xoá.', 404);
        }

        respond(['ok' => true, 'message' => 'Đã xoá bookmark.']);
    } catch (PDOException $e) {
        err('Không thể xoá bookmark: ' . $e->getMessage(), 500);
    }
}

err('Action không hợp lệ. Dùng: save | list | delete');