<?php
header("Content-Type: application/json; charset=UTF-8");

require_once("../config/database.php");
require_once("admin_check.php");

function ensureSyncLogTable(PDO $conn): void {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS sync_log (
            syncLogId INT AUTO_INCREMENT PRIMARY KEY,
            sourceId INT NULL,
            status VARCHAR(30) NOT NULL,
            message TEXT NULL,
            fetchedCount INT NOT NULL DEFAULT 0,
            insertedCount INT NOT NULL DEFAULT 0,
            updatedCount INT NOT NULL DEFAULT 0,
            createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (sourceId) REFERENCES api_source(sourceId) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function writeSyncLog(PDO $conn, ?int $sourceId, string $status, string $message, int $fetched = 0, int $inserted = 0, int $updated = 0): void {
    $stmt = $conn->prepare("
        INSERT INTO sync_log (sourceId, status, message, fetchedCount, insertedCount, updatedCount)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$sourceId, $status, $message, $fetched, $inserted, $updated]);
}

function fetchJson(string $url): array {
    if (!function_exists('curl_init')) {
        throw new Exception('cURL chưa được bật trên PHP.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: ScholarTrend/1.0 (academic project)'
        ]
    ]);

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $raw === '') {
        throw new Exception('Không nhận được phản hồi API: ' . ($error ?: 'unknown error'));
    }

    if ($code < 200 || $code >= 300) {
        throw new Exception("API trả về HTTP {$code}");
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new Exception('Dữ liệu API không phải JSON hợp lệ.');
    }

    return $json;
}

function buildOpenAlexAbstract(?array $index): string {
    if (empty($index)) return '';
    $words = [];
    foreach ($index as $word => $positions) {
        foreach ($positions as $pos) {
            $words[$pos] = $word;
        }
    }
    ksort($words);
    return implode(' ', array_values($words));
}

function normalizeOpenAlexWorks(array $json): array {
    $papers = [];
    foreach (($json['results'] ?? []) as $work) {
        $authors = array_map(
            fn($item) => $item['author']['display_name'] ?? '',
            $work['authorships'] ?? []
        );

        $source = $work['primary_location']['source'] ?? [];
        $doi = $work['doi'] ?? '';
        $doi = $doi ? preg_replace('#^https?://doi\.org/#', '', $doi) : '';

        $papers[] = [
            'title' => $work['title'] ?? '',
            'authors' => implode(', ', array_filter($authors)),
            'journal' => $source['display_name'] ?? '',
            'publishedYear' => (int)($work['publication_year'] ?? 0),
            'doi' => $doi,
            'abstract' => buildOpenAlexAbstract($work['abstract_inverted_index'] ?? null),
            'url' => $work['id'] ?? ''
        ];
    }
    return $papers;
}

function syncOpenAlex(PDO $conn, array $source, string $query, int $limit): array {
    $baseUrl = rtrim($source['baseUrl'], '/');
    $params = http_build_query([
        'search' => $query,
        'per-page' => $limit,
        'select' => 'id,title,publication_year,authorships,primary_location,abstract_inverted_index,doi'
    ]);

    $json = fetchJson($baseUrl . '/works?' . $params);
    $papers = normalizeOpenAlexWorks($json);

    $topicId = null;
    $topicStmt = $conn->query("SELECT topicId FROM topic ORDER BY topicId ASC LIMIT 1");
    $firstTopic = $topicStmt->fetch(PDO::FETCH_ASSOC);
    if ($firstTopic) {
        $topicId = (int)$firstTopic['topicId'];
    }

    $inserted = 0;
    $updated = 0;

    foreach ($papers as $paper) {
        $title = trim($paper['title']);
        if ($title === '') continue;

        if ($paper['doi'] !== '') {
            $find = $conn->prepare("SELECT articleId FROM researchpaper WHERE doi = ? LIMIT 1");
            $find->execute([$paper['doi']]);
        } else {
            $find = $conn->prepare("SELECT articleId FROM researchpaper WHERE title = ? AND publishedYear = ? LIMIT 1");
            $find->execute([$title, $paper['publishedYear'] ?: null]);
        }

        $existing = $find->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $conn->prepare("
                UPDATE researchpaper
                SET title = ?, authors = ?, journal = ?, publishedYear = ?, doi = ?, abstract = ?, url = ?, topicId = COALESCE(topicId, ?)
                WHERE articleId = ?
            ");
            $stmt->execute([
                $title,
                $paper['authors'],
                $paper['journal'],
                $paper['publishedYear'] ?: null,
                $paper['doi'],
                $paper['abstract'],
                $paper['url'],
                $topicId,
                $existing['articleId']
            ]);
            $updated++;
        } else {
            $stmt = $conn->prepare("
                INSERT INTO researchpaper (title, authors, journal, publishedYear, doi, abstract, url, topicId)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $title,
                $paper['authors'],
                $paper['journal'],
                $paper['publishedYear'] ?: null,
                $paper['doi'],
                $paper['abstract'],
                $paper['url'],
                $topicId
            ]);
            $inserted++;
        }
    }

    return [
        'fetched' => count($papers),
        'inserted' => $inserted,
        'updated' => $updated
    ];
}

function syncSource(PDO $conn, array $source, string $query, int $limit): array {
    $sourceName = strtolower($source['sourceName'] ?? '');
    $baseUrl = strtolower($source['baseUrl'] ?? '');

    if (str_contains($sourceName, 'openalex') || str_contains($baseUrl, 'openalex')) {
        return syncOpenAlex($conn, $source, $query, $limit);
    }

    throw new Exception('Nguồn API này chưa có bộ chuyển đổi dữ liệu. Demo hiện hỗ trợ OpenAlex.');
}

ensureSyncLogTable($conn);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $sources = $conn->query("
        SELECT sourceId, sourceName, baseUrl, isActive, priority
        FROM api_source
        ORDER BY priority ASC, sourceId ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $logs = $conn->query("
        SELECT l.syncLogId, l.sourceId, s.sourceName, l.status, l.message,
               l.fetchedCount, l.insertedCount, l.updatedCount, l.createdAt
        FROM sync_log l
        LEFT JOIN api_source s ON l.sourceId = s.sourceId
        ORDER BY l.syncLogId DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'sources' => $sources,
        'logs' => $logs
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $sourceId = (int)($data['sourceId'] ?? 0);
    $query = trim($data['query'] ?? 'science research');
    $limit = max(1, min(25, (int)($data['limit'] ?? 10)));

    if ($sourceId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Vui lòng chọn nguồn API cần đồng bộ.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $stmt = $conn->prepare("SELECT * FROM api_source WHERE sourceId = ? LIMIT 1");
    $stmt->execute([$sourceId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$source) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy nguồn API.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ((int)$source['isActive'] !== 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Nguồn API đang bị tắt.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        $result = syncSource($conn, $source, $query, $limit);
        $message = "Đồng bộ {$source['sourceName']} thành công.";
        writeSyncLog($conn, $sourceId, 'success', $message, $result['fetched'], $result['inserted'], $result['updated']);

        echo json_encode([
            'success' => true,
            'message' => $message,
            'result' => $result
        ], JSON_UNESCAPED_UNICODE);
        exit();
    } catch (Exception $e) {
        writeSyncLog($conn, $sourceId, 'failed', $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Phương thức không được hỗ trợ.'], JSON_UNESCAPED_UNICODE);