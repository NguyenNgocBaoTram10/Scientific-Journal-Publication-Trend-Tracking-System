<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$q        = trim($_GET['q']           ?? '');
$limit    = min(intval($_GET['limit'] ?? 10), 100);
$page     = max(1, intval($_GET['page']     ?? 1));
$oaOnly   = ($_GET['oa_only']         ?? '') === '1';
$type     = trim($_GET['type']        ?? '');
$yearFrom = intval($_GET['year_from'] ?? 0);
$yearTo   = intval($_GET['year_to']   ?? 0);

if (!$q) { echo json_encode(['ok'=>false,'data'=>[],'total'=>0]); exit; }

// Xây filter
$filters = [];
if ($yearFrom > 0 && $yearTo > 0) $filters[] = "publication_year:{$yearFrom}-{$yearTo}";
elseif ($yearFrom > 0)             $filters[] = "publication_year:>{$yearFrom}";
elseif ($yearTo > 0)               $filters[] = "publication_year:<{$yearTo}";
if ($oaOnly)                       $filters[] = 'is_oa:true';
if ($type !== '')                  $filters[] = "type:{$type}";

$params = [
    'search'   => $q,
    'page'     => $page,
    'per-page' => $limit,
    'select'   => implode(',', [
        'id','title','publication_year','type',
        'authorships','primary_location',
        'abstract_inverted_index',
        'cited_by_count','doi','open_access','concepts',
    ]),
];
if (!empty($filters)) $params['filter'] = implode(',', $filters);

// ── Gọi OpenAlex y chang timkiem.php ──
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
    echo json_encode(['ok'=>false,'error'=>'Không thể kết nối OpenAlex','data'=>[],'total'=>0]);
    exit;
}

$json = json_decode($raw, true);

if (!isset($json['results'])) {
    echo json_encode(['ok'=>false,'error'=>'Dữ liệu không hợp lệ','data'=>[],'total'=>0]);
    exit;
}

// ── Build abstract từ inverted index (copy từ timkiem.php) ──
function buildAbstract(?array $index): string {
    if (empty($index)) return '';
    $words = [];
    foreach ($index as $word => $positions)
        foreach ($positions as $pos) $words[$pos] = $word;
    ksort($words);
    return implode(' ', $words);
}

$papers = array_map(function($w) {
    $authors = array_map(fn($a) => [
        'name'        => $a['author']['display_name'] ?? '',
        'institution' => $a['institutions'][0]['display_name'] ?? '',
    ], $w['authorships'] ?? []);

    $source = $w['primary_location']['source'] ?? null;
    $doi    = isset($w['doi']) ? preg_replace('#^https?://doi\.org/#','', $w['doi']) : null;

    return [
        'id'        => $w['id']               ?? '',
        'title'     => $w['title']            ?? 'Không có tiêu đề',
        'year'      => $w['publication_year'] ?? null,
        'type'      => $w['type']             ?? '',
        'authors'   => $authors,
        'journal'   => $source ? ['name' => $source['display_name'] ?? ''] : null,
        'abstract'  => buildAbstract($w['abstract_inverted_index'] ?? null),
        'citations' => $w['cited_by_count']   ?? 0,
        'doi'       => $doi,
        'doi_url'   => $doi ? "https://doi.org/{$doi}" : null,
        'pdf_url'   => $w['open_access']['oa_url'] ?? null,
        'is_oa'     => $w['open_access']['is_oa']  ?? false,
        'concepts'  => array_slice(array_map(
            fn($c) => $c['display_name'], $w['concepts'] ?? []
        ), 0, 5),
    ];
}, $json['results']);

echo json_encode([
    'ok'    => true,
    'total' => $json['meta']['count'] ?? count($papers),
    'page'  => $page,
    'data'  => $papers,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);