<?php
/**
 * API: Chủ đề xu hướng — CrossRef (primary)
 * GET /api/xuhuongchude.php?topic=...&year_from=...&year_to=...
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$topic    = trim($_GET['topic']    ?? '');
$yearTo   = (int)($_GET['year_to']   ?? date('Y'));
$yearFrom = (int)($_GET['year_from'] ?? $yearTo - 9);

if ($topic === '') {
    http_response_code(400);
    ob_clean();
    echo json_encode(['error' => 'Thiếu tham số "topic".']);
    exit;
}

function crossrefGet(string $url): ?array {
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => "User-Agent: ScholarTrend/1.0 (mailto:admin@scholartrend.vn)\r\n",
        'timeout'       => 15,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    if (isset($http_response_header)) {
        preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m);
        if ((int)($m[1] ?? 200) >= 400) return null;
    }
    $d = json_decode($raw, true);
    return json_last_error() === JSON_ERROR_NONE ? $d : null;
}

function extractKeywords(string $title, int $max = 4): array {
    static $stop = ['the','a','an','of','and','for','in','on','with','to','is','are',
        'using','based','study','analysis','review','approach','via','toward','towards',
        'vs','this','that','its','from','by','as','into','at','or','new','novel','can',
        'we','their','our','how','what','when','where'];
    $title = strtolower(preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $title));
    $words = array_filter(preg_split('/\s+/', trim($title)), function ($w) use ($stop) {
        return mb_strlen($w) > 3 && !in_array($w, $stop, true);
    });
    return array_slice(array_values($words), 0, $max);
}

$url = 'https://api.crossref.org/works'
     . '?query=' . urlencode($topic)
     . '&filter=from-pub-date:' . $yearFrom . ',until-pub-date:' . $yearTo
     . '&rows=80&select=subject,published,title'
     . '&mailto=admin@scholartrend.vn';

$data  = crossrefGet($url);
$items = $data['message']['items'] ?? [];

if (empty($items)) {
    ob_clean();
    echo json_encode(['topics' => []]);
    exit;
}

$mid    = (int)(($yearFrom + $yearTo) / 2);
$first  = [];
$second = [];

foreach ($items as $item) {
    $year = (int)($item['published']['date-parts'][0][0] ?? 0);

    $subjList = $item['subject'] ?? [];
    if (empty($subjList)) {
        $titleStr = is_array($item['title'] ?? null) ? ($item['title'][0] ?? '') : ($item['title'] ?? '');
        $subjList = extractKeywords($titleStr);
    }

    foreach ($subjList as $s) {
        $s = trim($s);
        if (!$s) continue;
        if ($year && $year <= $mid) $first[$s]  = ($first[$s]  ?? 0) + 1;
        else                        $second[$s] = ($second[$s] ?? 0) + 1;
    }
}

$all = [];
foreach (array_unique(array_merge(array_keys($first), array_keys($second))) as $s) {
    $all[$s] = ($first[$s] ?? 0) + ($second[$s] ?? 0);
}
arsort($all);
$topSubjects = array_slice($all, 0, 10, true);

$followed = [];
try {
    $dbFile = __DIR__ . '/../config/database.php';
    if (file_exists($dbFile)) {
        require_once $dbFile;
$stmt = $conn->prepare(
    "SELECT t.topicName
     FROM userfollowtopic uft
     JOIN topic t ON t.topicId = uft.topicId
     WHERE uft.userId = :uid"
);
$stmt->execute([':uid' => $userId]);
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $followed[strtolower(trim($t))] = true;
        }
    }
} catch (Throwable $e) {
    // Bỏ qua
}

$topics = [];
foreach ($topSubjects as $name => $total) {
    $f      = $first[$name]  ?? 0;
    $s      = $second[$name] ?? 0;
    $change = 0;
    $dir    = 'stable';
    if ($f > 0) {
        $change = round(($s - $f) / $f * 100);

        if ($change >  15) $dir = 'rising';
        if ($change < -15) $dir = 'falling';
    } elseif ($s > 0) {
        $dir = 'rising'; $change = 100;
    }
    $topics[] = [
        'name'            => $name,
        'article_count'   => $total,
        'trend_direction' => $dir,
        'change_percent'  => abs($change),
        'is_followed'     => isset($followed[strtolower($name)]),
    ];
}

ob_clean();
echo json_encode(['topics' => $topics], JSON_UNESCAPED_UNICODE);