<?php
// ============================================================
// api_proxy.php  —  Proxy Dashboard → OpenAlex (dùng cURL)
// Đặt tại: source_code/api/api_proxy.php
//
// FIX: Thêm server-side file cache + rate-limit guard
//      để tránh bị OpenAlex chặn khi dashboard gọi ~15 req cùng lúc
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// ============================================================
// RATE LIMIT — tối đa 5 req/giây từ cùng IP
// ============================================================
$rlFile = sys_get_temp_dir() . '/rl_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x');
$rlData = file_exists($rlFile)
    ? (json_decode(file_get_contents($rlFile), true) ?: ['ts' => 0, 'count' => 0])
    : ['ts' => 0, 'count' => 0];

$now = microtime(true);
if ($now - $rlData['ts'] > 1.0) {
    // Reset mỗi giây
    $rlData = ['ts' => $now, 'count' => 1];
} else {
    $rlData['count']++;
    if ($rlData['count'] > 5) {
        http_response_code(429);
        echo json_encode([
            'ok'    => false,
            'error' => 'Quá nhiều request, vui lòng thử lại sau.',
            'data'  => [],
            'total' => 0,
        ]);
        exit;
    }
}
file_put_contents($rlFile, json_encode($rlData));

// --- Tham số đầu vào ---
$query    = trim($_GET['q']          ?? 'science research');
$limit    = max(1, min(25, intval($_GET['limit'] ?? 10)));
$yearFrom = intval($_GET['year_from'] ?? 0);
$yearTo   = intval($_GET['year_to']   ?? 0);
$oaOnly   = ($_GET['oa_only'] ?? '') === '1';
$type     = trim($_GET['type'] ?? '');
$page     = max(1, intval($_GET['page'] ?? 1));

// ============================================================
// FILE CACHE — lưu JSON vào /tmp/scholarcache, TTL 10 phút
// ============================================================
$cacheDir = sys_get_temp_dir() . '/scholarcache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

$cacheKey  = md5(serialize(array_intersect_key($_GET, array_flip(
    ['q', 'limit', 'year_from', 'year_to', 'oa_only', 'type', 'page']
))));
$cacheFile = "{$cacheDir}/{$cacheKey}.json";
$cacheTTL  = 600; // 10 phút

// Trả cache nếu còn hạn
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
    header('X-Cache: HIT');
    echo file_get_contents($cacheFile);
    exit;
}

// --- Xây dựng filter ---
$filters = [];

if ($yearFrom > 0 && $yearTo > 0)   $filters[] = "publication_year:{$yearFrom}-{$yearTo}";
elseif ($yearFrom > 0)              $filters[] = "publication_year:>{$yearFrom}";
elseif ($yearTo > 0)                $filters[] = "publication_year:<{$yearTo}";

if ($oaOnly) $filters[] = 'is_oa:true';

$allowedTypes = ['article', 'review', 'book-chapter', 'dataset', 'preprint', 'letter'];
if ($type !== '' && in_array($type, $allowedTypes, true)) {
    $filters[] = "type:{$type}";
}

// --- Xây dựng URL OpenAlex ---
$params = [
    'search'   => $query,
    'page'     => $page,
    'per-page' => $limit,
    'select'   => 'id,title,publication_year,type,authorships,primary_location,abstract_inverted_index,cited_by_count,doi,open_access,concepts',
];
if (!empty($filters)) {
    $params['filter'] = implode(',', $filters);
}

$url = 'https://api.openalex.org/works?' . http_build_query($params);

// --- Gọi API bằng cURL ---
if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'cURL không được cài đặt trên server này.',
        'data'  => [],
        'total' => 0,
    ]);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'User-Agent: ScholarTrend/1.0 (academic project)',
    ],
]);

$raw  = curl_exec($ch);
$err  = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false || $raw === '') {
    http_response_code(502);
    echo json_encode([
        'ok'    => false,
        'error' => 'cURL lỗi: ' . ($err ?: 'Không nhận được phản hồi'),
        'data'  => [],
        'total' => 0,
    ]);
    exit;
}

if ($code !== 200) {
    http_response_code(502);
    echo json_encode([
        'ok'    => false,
        'error' => "OpenAlex trả về HTTP {$code}",
        'data'  => [],
        'total' => 0,
    ]);
    exit;
}

$json = json_decode($raw, true);

if (!isset($json['results'])) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Phản hồi không hợp lệ từ OpenAlex',
        'data'  => [],
        'total' => 0,
    ]);
    exit;
}

// --- Helper: dựng abstract ---
function buildAbstract(?array $index): string {
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

// --- Helper: nhãn loại bài ---
function typeLabel(string $t): string {
    return match($t) {
        'article'      => 'Bài báo',
        'review'       => 'Tổng quan',
        'book-chapter' => 'Chương sách',
        'dataset'      => 'Dữ liệu',
        'preprint'     => 'Preprint',
        'letter'       => 'Thư khoa học',
        default        => ucfirst($t),
    };
}

// --- Chuẩn hoá kết quả ---
$papers = array_map(function ($w) {
    $authors = array_map(fn($a) => [
        'name'        => $a['author']['display_name'] ?? '',
        'institution' => $a['institutions'][0]['display_name'] ?? '',
    ], $w['authorships'] ?? []);

    $source  = $w['primary_location']['source'] ?? null;
    $journal = $source
        ? ['name' => $source['display_name'] ?? '', 'type' => $source['type'] ?? '']
        : null;

    $doi    = isset($w['doi']) ? preg_replace('#^https?://doi\.org/#', '', $w['doi']) : null;
    $pdfUrl = $w['open_access']['oa_url'] ?? null;
    $t      = $w['type'] ?? '';

    $concepts = array_slice(
        array_map(fn($c) => $c['display_name'], $w['concepts'] ?? []),
        0, 5
    );

    return [
        'id'           => $w['id'] ?? '',
        'title'        => $w['title'] ?? 'Không có tiêu đề',
        'year'         => $w['publication_year'] ?? null,
        'type'         => $t,
        'type_label'   => typeLabel($t),
        'authors'      => $authors,
        'journal'      => $journal,
        'abstract'     => buildAbstract($w['abstract_inverted_index'] ?? null),
        'citations'    => $w['cited_by_count'] ?? 0,
        'doi'          => $doi,
        'doi_url'      => $doi ? "https://doi.org/{$doi}" : null,
        'pdf_url'      => $pdfUrl,
        'is_oa'        => $w['open_access']['is_oa'] ?? false,
        'concepts'     => $concepts,
        'openalex_url' => $w['id'] ?? null,
    ];
}, $json['results']);

// --- Build output ---
$output = json_encode([
    'ok'      => true,
    'total'   => $json['meta']['count'] ?? count($papers),
    'page'    => $page,
    'limit'   => $limit,
    'query'   => $query,
    'filters' => [
        'year_from' => $yearFrom ?: null,
        'year_to'   => $yearTo   ?: null,
        'oa_only'   => $oaOnly,
        'type'      => $type ?: null,
    ],
    'data'    => $papers,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// --- Ghi cache & trả về ---
file_put_contents($cacheFile, $output);
header('X-Cache: MISS');
echo $output;