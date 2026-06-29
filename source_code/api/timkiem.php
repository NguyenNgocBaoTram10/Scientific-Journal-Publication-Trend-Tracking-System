<?php
// ============================================================
// timkiem.php  —  OpenAlex Search Proxy API (v2 + filters)
// Đặt tại: source_code/api/timkiem.php
// Tham số: q, page, year_from, year_to, oa_only, type
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// --- Tham số đầu vào ---
$query     = trim($_GET['q'] ?? '');
$page      = max(1, intval($_GET['page'] ?? 1));
$limit     = 10;
$yearFrom  = intval($_GET['year_from'] ?? 0);
$yearTo    = intval($_GET['year_to']   ?? 0);
$oaOnly    = ($_GET['oa_only'] ?? '') === '1';
$typeParam = trim($_GET['type'] ?? '');   // article | review | book-chapter | dataset | ...

if ($query === '') {
    echo json_encode(['ok' => false, 'error' => 'Thiếu tham số q', 'data' => [], 'total' => 0]);
    exit;
}

// --- Xây dựng filter string cho OpenAlex ---
// Docs: https://docs.openalex.org/api-entities/works/filter-works
$filters = [];

if ($yearFrom > 0 && $yearTo > 0) {
    $filters[] = "publication_year:{$yearFrom}-{$yearTo}";
} elseif ($yearFrom > 0) {
    $filters[] = "publication_year:>{$yearFrom}";
} elseif ($yearTo > 0) {
    $filters[] = "publication_year:<{$yearTo}";
}

if ($oaOnly) {
    $filters[] = 'is_oa:true';
}

// type: article, review, book-chapter, dataset, preprint, paratext, letter, erratum, grant, supplementary-materials
$allowedTypes = ['article','review','book-chapter','dataset','preprint','letter'];
if ($typeParam !== '' && in_array($typeParam, $allowedTypes, true)) {
    $filters[] = "type:{$typeParam}";
}

// --- Gọi OpenAlex ---
$params = [
    'search'   => $query,
    'page'     => $page,
    'per-page' => $limit,
    'select'   => implode(',', [
        'id', 'title', 'publication_year', 'type',
        'authorships', 'primary_location',
        'abstract_inverted_index',
        'cited_by_count', 'doi',
        'open_access', 'concepts',
    ]),
];

if (!empty($filters)) {
    $params['filter'] = implode(',', $filters);
}

$url = 'https://api.openalex.org/works?' . http_build_query($params);

$ctx = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'timeout' => 20,
        'header'  =>
            "Accept: application/json\r\n" .
            "User-Agent: ScholarTrend/1.0 (academic project)\r\n",
    ],
]);

$raw = @file_get_contents($url, false, $ctx);

if ($raw === false) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Không thể kết nối OpenAlex', 'data' => [], 'total' => 0]);
    exit;
}

$json = json_decode($raw, true);

if (!isset($json['results'])) {
    echo json_encode(['ok' => false, 'error' => 'Dữ liệu không hợp lệ từ OpenAlex', 'data' => [], 'total' => 0]);
    exit;
}

// --- Dựng abstract từ inverted index ---
function buildAbstract(?array $index): string {
    if (empty($index)) return '';
    $words = [];
    foreach ($index as $word => $positions) {
        foreach ($positions as $pos) {
            $words[$pos] = $word;
        }
    }
    ksort($words);
    return implode(' ', $words);
}

// --- Nhãn hiển thị cho loại bài ---
function typeLabel(string $type): string {
    return match($type) {
        'article'       => 'Bài báo',
        'review'        => 'Tổng quan',
        'book-chapter'  => 'Chương sách',
        'dataset'       => 'Dữ liệu',
        'preprint'      => 'Preprint',
        'letter'        => 'Thư khoa học',
        default         => ucfirst($type),
    };
}

// --- Chuẩn hoá kết quả ---
$papers = array_map(function ($w) {
    $authors = array_map(fn($a) => [
        'name'        => $a['author']['display_name'] ?? '',
        'institution' => $a['institutions'][0]['display_name'] ?? '',
    ], $w['authorships'] ?? []);

    $source  = $w['primary_location']['source'] ?? null;
    $journal = $source ? [
        'name' => $source['display_name'] ?? '',
        'type' => $source['type'] ?? '',
    ] : null;

    $doi    = isset($w['doi']) ? preg_replace('#^https?://doi\.org/#', '', $w['doi']) : null;
    $pdfUrl = $w['open_access']['oa_url'] ?? null;
    $type   = $w['type'] ?? '';

    $concepts = array_slice(
        array_map(fn($c) => $c['display_name'], $w['concepts'] ?? []),
        0, 5
    );

    return [
        'id'           => $w['id'] ?? '',
        'title'        => $w['title'] ?? 'Không có tiêu đề',
        'year'         => $w['publication_year'] ?? null,
        'type'         => $type,
        'type_label'   => typeLabel($type),
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

echo json_encode([
    'ok'       => true,
    'total'    => $json['meta']['count'] ?? count($papers),
    'page'     => $page,
    'limit'    => $limit,
    'query'    => $query,
    'filters'  => [
        'year_from' => $yearFrom ?: null,
        'year_to'   => $yearTo   ?: null,
        'oa_only'   => $oaOnly,
        'type'      => $typeParam ?: null,
    ],
    'data'     => $papers,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);