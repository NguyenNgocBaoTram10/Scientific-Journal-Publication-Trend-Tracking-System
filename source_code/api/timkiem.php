<?php
// ============================================================
// timkiem.php — ScholarTrend Search API (WAMP-fixed, dùng cURL)
// Đặt tại: source_code/api/timkiem.php
// Hỗ trợ mode=keyword | author | journal
// ============================================================

$startTime = microtime(true);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respond(array $data): void
{
    global $startTime;
    header('X-Response-Time: ' . round((microtime(true) - $startTime) * 1000) . 'ms');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function err(string $msg, int $code = 400): void
{
    http_response_code($code);
    respond(['ok' => false, 'error' => $msg, 'data' => [], 'total' => 0]);
}

function buildAbstract(?array $index): string
{
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

function typeLabel(string $t): string
{
    switch ($t) {
        case 'article':      return 'Bài báo';
        case 'review':       return 'Tổng quan';
        case 'book-chapter': return 'Chương sách';
        case 'dataset':      return 'Dữ liệu';
        case 'preprint':     return 'Preprint';
        case 'letter':       return 'Thư khoa học';
        default:             return ucfirst($t);
    }
}

function normaliseWork(array $w): array
{
    $authors = array_map(function ($a) {
        return [
            'name'        => isset($a['author']['display_name']) ? $a['author']['display_name'] : '',
            'institution' => isset($a['institutions'][0]['display_name']) ? $a['institutions'][0]['display_name'] : '',
        ];
    }, isset($w['authorships']) ? $w['authorships'] : []);

    $source  = isset($w['primary_location']['source']) ? $w['primary_location']['source'] : null;
    $journal = $source ? [
        'name' => isset($source['display_name']) ? $source['display_name'] : '',
        'type' => isset($source['type']) ? $source['type'] : '',
    ] : null;

    $doi = isset($w['doi']) ? preg_replace('#^https?://doi\.org/#', '', $w['doi']) : null;
    $t   = isset($w['type']) ? $w['type'] : '';

    return [
        'id'           => isset($w['id']) ? $w['id'] : '',
        'title'        => isset($w['title']) ? $w['title'] : 'Không có tiêu đề',
        'year'         => isset($w['publication_year']) ? $w['publication_year'] : null,
        'type'         => $t,
        'type_label'   => typeLabel($t),
        'authors'      => $authors,
        'journal'      => $journal,
        'abstract'     => buildAbstract(isset($w['abstract_inverted_index']) ? $w['abstract_inverted_index'] : null),
        'citations'    => isset($w['cited_by_count']) ? $w['cited_by_count'] : 0,
        'doi'          => $doi,
        'doi_url'      => $doi ? "https://doi.org/{$doi}" : null,
        'pdf_url'      => isset($w['open_access']['oa_url']) ? $w['open_access']['oa_url'] : null,
        'is_oa'        => isset($w['open_access']['is_oa']) ? $w['open_access']['is_oa'] : false,
        'concepts'     => array_slice(
            array_map(function ($c) { return $c['display_name']; }, isset($w['concepts']) ? $w['concepts'] : []),
            0, 5
        ),
        'openalex_url' => isset($w['id']) ? $w['id'] : null,
    ];
}

// ── SSL: tắt verify khi chạy WAMP/localhost ────────────────────
function isLocalhost(): bool
{
    $h = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');
    // WAMP thường chạy ở localhost / 127.0.0.1, đôi khi không có port hoặc có :80
    $h = preg_replace('/:\d+$/', '', $h);
    return in_array($h, ['localhost', '127.0.0.1', '::1'], true);
}

// Đặt email thật của bạn vào đây để vào "polite pool" của OpenAlex
// -> được ưu tiên xử lý và giới hạn rate cao hơn hẳn, giảm hẳn lỗi 429.
const OPENALEX_MAILTO = 'your-email@example.com';

function withMailto(string $url): string
{
    $sep = (strpos($url, '?') !== false) ? '&' : '?';
    return $url . $sep . 'mailto=' . urlencode(OPENALEX_MAILTO);
}

function curlGet(string $url): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Extension cURL chưa được bật trong php.ini (php_curl).');
    }
    $url = withMailto($url);

    $maxRetries = 3;
    $attempt    = 0;
    $lastErr    = '';

    while ($attempt < $maxRetries) {
        $attempt++;
        try {
            return curlGetOnce($url);
        } catch (RuntimeException $e) {
            $lastErr = $e->getMessage();
            // Nếu bị 429 (rate limit), đợi rồi thử lại (exponential backoff)
            if (strpos($lastErr, 'HTTP 429') !== false && $attempt < $maxRetries) {
                usleep(500000 * $attempt); // 0.5s, 1s, 1.5s...
                continue;
            }
            throw $e;
        }
    }
    throw new RuntimeException($lastErr);
}

function curlGetOnce(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        // WAMP (Windows) thường thiếu CA bundle -> tắt verify khi localhost
        CURLOPT_SSL_VERIFYPEER => !isLocalhost(),
        CURLOPT_SSL_VERIFYHOST => isLocalhost() ? 0 : 2,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'User-Agent: ScholarTrend/3.0 (academic project)',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $raw === '') throw new RuntimeException("cURL lỗi: {$cerr}");
    if ($code !== 200)                 throw new RuntimeException("OpenAlex trả về HTTP {$code}");
    $json = json_decode($raw, true);
    if (!is_array($json))              throw new RuntimeException('JSON không hợp lệ từ OpenAlex');
    return $json;
}

// ── Crossref: gọi trực tiếp, không cần API key, không bị tính credit ─
function curlGetCrossref(string $url): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Extension cURL chưa được bật trong php.ini (php_curl).');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => !isLocalhost(),
        CURLOPT_SSL_VERIFYHOST => isLocalhost() ? 0 : 2,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            // Crossref khuyến nghị gắn mailto trong User-Agent để vào "polite pool" (miễn phí, không cần key)
            'User-Agent: ScholarTrend/3.0 (mailto:your-email@example.com)',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $raw === '') throw new RuntimeException("Crossref cURL lỗi: {$cerr}");
    if ($code !== 200)                 throw new RuntimeException("Crossref trả về HTTP {$code}");
    $json = json_decode($raw, true);
    if (!is_array($json))              throw new RuntimeException('JSON không hợp lệ từ Crossref');
    return $json;
}

// Map type của hệ thống mình -> type Crossref dùng
function mapTypeToCrossref(string $type): string
{
    switch ($type) {
        case 'article':      return 'journal-article';
        case 'review':       return 'journal-article'; // crossref không tách riêng review
        case 'book-chapter': return 'book-chapter';
        case 'dataset':      return 'dataset';
        case 'preprint':     return 'posted-content';
        case 'letter':       return 'journal-article';
        default:             return '';
    }
}

function typeLabelCrossref(string $t): string
{
    switch ($t) {
        case 'journal-article':     return 'Bài báo';
        case 'book-chapter':        return 'Chương sách';
        case 'proceedings-article': return 'Kỷ yếu hội nghị';
        case 'dataset':             return 'Dữ liệu';
        case 'posted-content':      return 'Preprint';
        case 'monograph':           return 'Chuyên khảo';
        default:                    return $t !== '' ? ucfirst($t) : 'Khác';
    }
}

function buildCrossrefFilter(int $yf, int $yt, string $type): string
{
    $f = [];
    if ($yf > 0) $f[] = "from-pub-date:{$yf}-01-01";
    if ($yt > 0) $f[] = "until-pub-date:{$yt}-12-31";
    $crType = mapTypeToCrossref($type);
    if ($crType !== '') $f[] = "type:{$crType}";
    return implode(',', $f);
}

function normaliseCrossrefWork(array $item): array
{
    $titleArr = isset($item['title']) ? $item['title'] : [];
    $title    = !empty($titleArr) ? $titleArr[0] : 'Không có tiêu đề';

    $authors = array_map(function ($a) {
        $given  = isset($a['given']) ? $a['given'] : '';
        $family = isset($a['family']) ? $a['family'] : '';
        $name   = trim("{$given} {$family}");
        $aff    = isset($a['affiliation'][0]['name']) ? $a['affiliation'][0]['name'] : '';
        return ['name' => $name !== '' ? $name : '(Không rõ tên)', 'institution' => $aff];
    }, isset($item['author']) ? $item['author'] : []);

    $journalArr = isset($item['container-title']) ? $item['container-title'] : [];
    $journal    = !empty($journalArr) ? ['name' => $journalArr[0], 'type' => 'journal'] : null;

    $year = null;
    foreach (['published', 'published-print', 'published-online', 'issued'] as $key) {
        if (isset($item[$key]['date-parts'][0][0])) {
            $year = (int)$item[$key]['date-parts'][0][0];
            break;
        }
    }

    $doi    = isset($item['DOI']) ? $item['DOI'] : null;
    $t      = isset($item['type']) ? $item['type'] : '';
    $abstract = isset($item['abstract']) ? trim(strip_tags($item['abstract'])) : '';

    $pdfUrl = null;
    $isOa   = false;
    if (!empty($item['link']) && is_array($item['link'])) {
        foreach ($item['link'] as $lnk) {
            if (isset($lnk['content-type']) && $lnk['content-type'] === 'application/pdf') {
                $pdfUrl = isset($lnk['URL']) ? $lnk['URL'] : null;
                break;
            }
        }
    }
    if (!empty($item['license'])) $isOa = true; // có license công khai -> coi như có khả năng OA

    $concepts = array_slice(isset($item['subject']) ? $item['subject'] : [], 0, 5);

    return [
        'id'           => $doi ? "https://doi.org/{$doi}" : '',
        'title'        => $title,
        'year'         => $year,
        'type'         => $t,
        'type_label'   => typeLabelCrossref($t),
        'authors'      => $authors,
        'journal'      => $journal,
        'abstract'     => $abstract,
        'citations'    => isset($item['is-referenced-by-count']) ? $item['is-referenced-by-count'] : 0,
        'doi'          => $doi,
        'doi_url'      => $doi ? "https://doi.org/{$doi}" : null,
        'pdf_url'      => $pdfUrl,
        'is_oa'        => $isOa,
        'concepts'     => $concepts,
        'openalex_url' => null,
    ];
}

// Tìm theo từ khóa trên Crossref
function crossrefSearchKeyword(string $query, int $page, int $limit, int $yf, int $yt, string $type): array
{
    $offset = ($page - 1) * $limit;
    $params = [
        'query' => $query,
        'rows'  => $limit,
        'offset'=> $offset,
    ];
    $filterStr = buildCrossrefFilter($yf, $yt, $type);
    if ($filterStr) $params['filter'] = $filterStr;

    $url  = 'https://api.crossref.org/works?' . http_build_query($params);
    $json = curlGetCrossref($url);

    $items  = isset($json['message']['items']) ? $json['message']['items'] : [];
    $papers = array_map('normaliseCrossrefWork', $items);
    $total  = isset($json['message']['total-results']) ? $json['message']['total-results'] : count($papers);

    return ['papers' => $papers, 'total' => $total];
}

// Tìm theo tác giả trên Crossref (query.author)
function crossrefSearchAuthor(string $query, int $page, int $limit, int $yf, int $yt, string $type): array
{
    $offset = ($page - 1) * $limit;
    $params = [
        'query.author' => $query,
        'rows'         => $limit,
        'offset'       => $offset,
        'sort'         => 'is-referenced-by-count',
        'order'        => 'desc',
    ];
    $filterStr = buildCrossrefFilter($yf, $yt, $type);
    if ($filterStr) $params['filter'] = $filterStr;

    $url  = 'https://api.crossref.org/works?' . http_build_query($params);
    $json = curlGetCrossref($url);

    $items  = isset($json['message']['items']) ? $json['message']['items'] : [];
    $papers = array_map('normaliseCrossrefWork', $items);
    $total  = isset($json['message']['total-results']) ? $json['message']['total-results'] : count($papers);

    return ['papers' => $papers, 'total' => $total];
}

// Tìm theo tạp chí trên Crossref (query.container-title)
function crossrefSearchJournal(string $query, int $page, int $limit, int $yf, int $yt, string $type): array
{
    $offset = ($page - 1) * $limit;
    $params = [
        'query.container-title' => $query,
        'rows'                  => $limit,
        'offset'                => $offset,
        'sort'                  => 'is-referenced-by-count',
        'order'                 => 'desc',
    ];
    $filterStr = buildCrossrefFilter($yf, $yt, $type);
    if ($filterStr) $params['filter'] = $filterStr;

    $url  = 'https://api.crossref.org/works?' . http_build_query($params);
    $json = curlGetCrossref($url);

    $items  = isset($json['message']['items']) ? $json['message']['items'] : [];
    $papers = array_map('normaliseCrossrefWork', $items);
    $total  = isset($json['message']['total-results']) ? $json['message']['total-results'] : count($papers);

    return ['papers' => $papers, 'total' => $total];
}

function buildFilter(int $yf, int $yt, bool $oa, string $type): string
{
    $f = [];
    if ($yf > 0 && $yt > 0) $f[] = "publication_year:{$yf}-{$yt}";
    elseif ($yf > 0)         $f[] = "publication_year:>{$yf}";
    elseif ($yt > 0)         $f[] = "publication_year:<{$yt}";
    if ($oa)                 $f[] = 'is_oa:true';
    if ($type !== '')        $f[] = "type:{$type}";
    return implode(',', $f);
}

// ── Cache đơn giản (giảm gọi OpenAlex lặp lại) ─────────────────
$cacheDir = sys_get_temp_dir() . '/scholarcache_ts';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
$cacheTTL  = 3600; // 1 giờ — giảm số lần gọi OpenAlex thật, tránh bị rate-limit (429)
$cacheKey  = md5(serialize($_GET));
$cacheFile = "{$cacheDir}/{$cacheKey}.json";

if (is_dir($cacheDir) && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
    header('X-Cache: HIT');
    header('X-Response-Time: ' . round((microtime(true) - $startTime) * 1000) . 'ms');
    echo file_get_contents($cacheFile);
    exit;
}
header('X-Cache: MISS');

// ── Input ───────────────────────────────────────────────────────
$mode      = trim(isset($_GET['mode']) ? $_GET['mode'] : 'keyword');
$query     = trim(isset($_GET['q']) ? $_GET['q'] : '');
$page      = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
$limit     = max(1, min(25, (int)(isset($_GET['limit']) ? $_GET['limit'] : 10)));
$yearFrom  = (int)(isset($_GET['year_from']) ? $_GET['year_from'] : 0);
$yearTo    = (int)(isset($_GET['year_to']) ? $_GET['year_to'] : 0);
$oaOnly    = (isset($_GET['oa_only']) ? $_GET['oa_only'] : '') === '1';
$typeParam = trim(isset($_GET['type']) ? $_GET['type'] : '');

if (!in_array($mode, ['keyword', 'author', 'journal'], true)) $mode = 'keyword';

$allowedTypes = ['article', 'review', 'book-chapter', 'dataset', 'preprint', 'letter'];
if (!in_array($typeParam, $allowedTypes, true)) $typeParam = '';

if ($query === '') err('Vui lòng nhập từ khóa tìm kiếm.');

$SELECT_WORKS = 'id,title,publication_year,type,authorships,primary_location,abstract_inverted_index,cited_by_count,doi,open_access,concepts';

// ════════════════════════════════════════════════════════════
// MODE: keyword
// ════════════════════════════════════════════════════════════
if ($mode === 'keyword') {
    $filterStr = buildFilter($yearFrom, $yearTo, $oaOnly, $typeParam);

    $params = [
        'search'   => $query,
        'page'     => $page,
        'per-page' => $limit,
        'select'   => $SELECT_WORKS,
    ];
    if ($filterStr) $params['filter'] = $filterStr;

    $url = 'https://api.openalex.org/works?' . http_build_query($params);

    $source = 'openalex';
    $papers = null;
    $total  = 0;
    $openAlexErr = null;

    try {
        $json = curlGet($url);
        if (!isset($json['results'])) throw new RuntimeException('Phản hồi không hợp lệ từ OpenAlex');
        $papers = array_map('normaliseWork', $json['results']);
        $total  = isset($json['meta']['count']) ? $json['meta']['count'] : count($papers);
    } catch (Throwable $e) {
        $openAlexErr = $e->getMessage();
    }

    // Nếu OpenAlex lỗi (429 / hết credit / timeout...) -> fallback sang Crossref
    if ($papers === null) {
        try {
            $cr     = crossrefSearchKeyword($query, $page, $limit, $yearFrom, $yearTo, $typeParam);
            $papers = $cr['papers'];
            $total  = $cr['total'];
            $source = 'crossref';
        } catch (Throwable $e2) {
            err("OpenAlex lỗi ({$openAlexErr}) và Crossref cũng lỗi ({$e2->getMessage()})", 502);
        }
    }

    $out = json_encode([
        'ok'      => true,
        'mode'    => 'keyword',
        'source'  => $source,
        'fallback_reason' => $source === 'crossref' ? $openAlexErr : null,
        'total'   => $total,
        'page'    => $page,
        'limit'   => $limit,
        'query'   => $query,
        'filters' => [
            'year_from' => $yearFrom ?: null,
            'year_to'   => $yearTo   ?: null,
            'oa_only'   => $oaOnly,
            'type'      => $typeParam ?: null,
        ],
        'data'    => $papers,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    @file_put_contents($cacheFile, $out);
    echo $out;
    exit;
}

// ════════════════════════════════════════════════════════════
// MODE: author
// ════════════════════════════════════════════════════════════
if ($mode === 'author') {

    $authorId   = trim(isset($_GET['author_id']) ? $_GET['author_id'] : '');
    $authorName = $query;
    $authorMeta = null;
    $source     = 'openalex';
    $openAlexErr = null;
    $papers = null;
    $total  = 0;

    if ($authorId === '') {
        $lookupUrl = 'https://api.openalex.org/authors?' . http_build_query([
            'search'   => $query,
            'per-page' => 1,
            'select'   => 'id,display_name,hint,works_count,cited_by_count,last_known_institution',
        ]);

        try {
            $aJson = curlGet($lookupUrl);
            $firstAuthor = isset($aJson['results'][0]) ? $aJson['results'][0] : null;

            if ($firstAuthor) {
                $authorId   = preg_replace('#^https?://openalex\.org/#', '', isset($firstAuthor['id']) ? $firstAuthor['id'] : '');
                $authorName = isset($firstAuthor['display_name']) ? $firstAuthor['display_name'] : $query;
                $authorMeta = [
                    'id'          => $authorId,
                    'name'        => $authorName,
                    'hint'        => isset($firstAuthor['hint']) ? $firstAuthor['hint'] : '',
                    'works_count' => isset($firstAuthor['works_count']) ? $firstAuthor['works_count'] : 0,
                    'citations'   => isset($firstAuthor['cited_by_count']) ? $firstAuthor['cited_by_count'] : 0,
                    'institution' => isset($firstAuthor['last_known_institution']['display_name']) ? $firstAuthor['last_known_institution']['display_name'] : '',
                ];
            } else {
                respond([
                    'ok' => true, 'mode' => 'author', 'source' => 'openalex', 'total' => 0, 'page' => 1,
                    'limit' => $limit, 'query' => $query, 'author' => null,
                    'message' => 'Không tìm thấy tác giả phù hợp.', 'data' => [],
                ]);
            }
        } catch (Throwable $e) {
            $openAlexErr = $e->getMessage();
            // không tìm được qua OpenAlex -> sẽ fallback Crossref bằng chính tên đã nhập
        }
    } else {
        $authorId   = preg_replace('#^https?://openalex\.org/#', '', $authorId);
    }

    // Nếu OpenAlex lookup thành công, thử lấy works qua OpenAlex trước
    if ($openAlexErr === null) {
        $filterStr = "authorships.author.id:{$authorId}";
        $extra     = buildFilter($yearFrom, $yearTo, $oaOnly, $typeParam);
        if ($extra) $filterStr .= ',' . $extra;

        $params = [
            'filter'   => $filterStr,
            'page'     => $page,
            'per-page' => $limit,
            'sort'     => 'cited_by_count:desc',
            'select'   => $SELECT_WORKS,
        ];

        try {
            $wJson  = curlGet('https://api.openalex.org/works?' . http_build_query($params));
            $papers = array_map('normaliseWork', isset($wJson['results']) ? $wJson['results'] : []);
            $total  = isset($wJson['meta']['count']) ? $wJson['meta']['count'] : count($papers);
        } catch (Throwable $e) {
            $openAlexErr = $e->getMessage();
        }
    }

    // Fallback Crossref nếu OpenAlex lỗi ở bất kỳ bước nào trên
    if ($papers === null) {
        try {
            $cr     = crossrefSearchAuthor($authorName, $page, $limit, $yearFrom, $yearTo, $typeParam);
            $papers = $cr['papers'];
            $total  = $cr['total'];
            $source = 'crossref';
            if ($authorMeta === null) {
                $authorMeta = ['id' => null, 'name' => $authorName, 'note' => 'Thông tin tác giả lấy gần đúng từ Crossref'];
            }
        } catch (Throwable $e2) {
            err("OpenAlex lỗi ({$openAlexErr}) và Crossref cũng lỗi ({$e2->getMessage()})", 502);
        }
    }

    $totalCite = array_sum(array_column($papers, 'citations'));
    $journals  = array_unique(array_filter(array_map(function ($p) {
        return isset($p['journal']['name']) ? $p['journal']['name'] : null;
    }, $papers)));

    $out = json_encode([
        'ok'      => true,
        'mode'    => 'author',
        'source'  => $source,
        'fallback_reason' => $source === 'crossref' ? $openAlexErr : null,
        'total'   => $total,
        'page'    => $page,
        'limit'   => $limit,
        'query'   => $query,
        'author'  => $authorMeta ?: ['id' => $authorId, 'name' => $authorName],
        'filters' => [
            'year_from' => $yearFrom ?: null,
            'year_to'   => $yearTo   ?: null,
            'oa_only'   => $oaOnly,
            'type'      => $typeParam ?: null,
        ],
        'stats'   => [
            'sample_citations' => $totalCite,
            'sample_journals'  => count($journals),
        ],
        'data'    => $papers,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    @file_put_contents($cacheFile, $out);
    echo $out;
    exit;
}

// ════════════════════════════════════════════════════════════
// MODE: journal
// ════════════════════════════════════════════════════════════
if ($mode === 'journal') {

    $sourceId    = trim(isset($_GET['source_id']) ? $_GET['source_id'] : '');
    $journalName = $query;
    $journalMeta = null;
    $source      = 'openalex';
    $openAlexErr = null;
    $papers = null;
    $total  = 0;

    if ($sourceId === '') {
        $lookupUrl = 'https://api.openalex.org/sources?' . http_build_query([
            'search'   => $query,
            'per-page' => 1,
            'filter'   => 'type:journal',
            'select'   => 'id,display_name,issn_l,host_organization_name,works_count,cited_by_count,is_oa,homepage_url',
        ]);

        try {
            $sJson = curlGet($lookupUrl);
            $firstSource = isset($sJson['results'][0]) ? $sJson['results'][0] : null;

            if ($firstSource) {
                $sourceId    = preg_replace('#^https?://openalex\.org/#', '', isset($firstSource['id']) ? $firstSource['id'] : '');
                $journalName = isset($firstSource['display_name']) ? $firstSource['display_name'] : $query;
                $journalMeta = [
                    'id'          => $sourceId,
                    'name'        => $journalName,
                    'issn'        => isset($firstSource['issn_l']) ? $firstSource['issn_l'] : '',
                    'publisher'   => isset($firstSource['host_organization_name']) ? $firstSource['host_organization_name'] : '',
                    'works_count' => isset($firstSource['works_count']) ? $firstSource['works_count'] : 0,
                    'citations'   => isset($firstSource['cited_by_count']) ? $firstSource['cited_by_count'] : 0,
                    'is_oa'       => isset($firstSource['is_oa']) ? $firstSource['is_oa'] : false,
                    'homepage'    => isset($firstSource['homepage_url']) ? $firstSource['homepage_url'] : null,
                ];
            } else {
                respond([
                    'ok' => true, 'mode' => 'journal', 'source' => 'openalex', 'total' => 0, 'page' => 1,
                    'limit' => $limit, 'query' => $query, 'journal' => null,
                    'message' => 'Không tìm thấy tạp chí phù hợp.', 'data' => [],
                ]);
            }
        } catch (Throwable $e) {
            $openAlexErr = $e->getMessage();
        }
    } else {
        $sourceId = preg_replace('#^https?://openalex\.org/#', '', $sourceId);
    }

    if ($openAlexErr === null) {
        $filterStr = "primary_location.source.id:{$sourceId}";
        $extra     = buildFilter($yearFrom, $yearTo, $oaOnly, $typeParam);
        if ($extra) $filterStr .= ',' . $extra;

        $params = [
            'filter'   => $filterStr,
            'page'     => $page,
            'per-page' => $limit,
            'sort'     => 'cited_by_count:desc',
            'select'   => $SELECT_WORKS,
        ];

        try {
            $wJson  = curlGet('https://api.openalex.org/works?' . http_build_query($params));
            $papers = array_map('normaliseWork', isset($wJson['results']) ? $wJson['results'] : []);
            $total  = isset($wJson['meta']['count']) ? $wJson['meta']['count'] : count($papers);
        } catch (Throwable $e) {
            $openAlexErr = $e->getMessage();
        }
    }

    if ($papers === null) {
        try {
            $cr     = crossrefSearchJournal($journalName, $page, $limit, $yearFrom, $yearTo, $typeParam);
            $papers = $cr['papers'];
            $total  = $cr['total'];
            $source = 'crossref';
            if ($journalMeta === null) {
                $journalMeta = ['id' => null, 'name' => $journalName, 'note' => 'Thông tin tạp chí lấy gần đúng từ Crossref'];
            }
        } catch (Throwable $e2) {
            err("OpenAlex lỗi ({$openAlexErr}) và Crossref cũng lỗi ({$e2->getMessage()})", 502);
        }
    }

    $totalCite = array_sum(array_column($papers, 'citations'));

    $out = json_encode([
        'ok'      => true,
        'mode'    => 'journal',
        'source'  => $source,
        'fallback_reason' => $source === 'crossref' ? $openAlexErr : null,
        'total'   => $total,
        'page'    => $page,
        'limit'   => $limit,
        'query'   => $query,
        'journal' => $journalMeta ?: ['id' => $sourceId, 'name' => $journalName],
        'filters' => [
            'year_from' => $yearFrom ?: null,
            'year_to'   => $yearTo   ?: null,
            'oa_only'   => $oaOnly,
            'type'      => $typeParam ?: null,
        ],
        'stats' => ['sample_citations' => $totalCite],
        'data'  => $papers,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    @file_put_contents($cacheFile, $out);
    echo $out;
    exit;
}

err('Mode không hợp lệ. Dùng: keyword | author | journal');