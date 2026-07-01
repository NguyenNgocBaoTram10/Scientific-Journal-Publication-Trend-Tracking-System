<?php
/**
 * API: Phân tích Xu hướng Nghiên cứu
 * Nguồn dữ liệu: CrossRef (primary) — không dùng OpenAlex
 * Endpoint: GET /api/phantichxuhuong.php
 *
 * Params:
 *   topic     string  Từ khóa / tên tác giả / tên tạp chí
 *   year_from int     (tuỳ chọn)
 *   year_to   int     (tuỳ chọn)
 */

// Bắt mọi output ngoài ý muốn (warning, notice, BOM...) để JSON luôn sạch
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

/* ═══════════════ INPUT ═══════════════ */
$topic    = trim($_GET['topic']    ?? '');
$yearFrom = (int)($_GET['year_from'] ?? 0);
$yearTo   = (int)($_GET['year_to']   ?? 0);

if ($topic === '') {
    http_response_code(400);
    ob_clean();
    echo json_encode(['error' => 'Thiếu tham số "topic".']);
    exit;
}

if ($yearTo   === 0) $yearTo   = (int)date('Y');
if ($yearFrom === 0) $yearFrom = $yearTo - 9;   // mặc định 10 năm

/* ═══════════════ HELPERS ═══════════════ */

/**
 * Gọi CrossRef API, trả về mảng PHP hoặc null nếu lỗi.
 * Dùng email trong User-Agent để vào "polite pool" — tốc độ cao hơn.
 */
function crossrefGet(string $url): ?array {
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: ScholarTrend/1.0 (mailto:admin@scholartrend.vn)\r\n",
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;

    // Kiểm tra HTTP status
    if (isset($http_response_header)) {
        $statusLine = $http_response_header[0] ?? '';
        preg_match('/\d{3}/', $statusLine, $m);
        $status = (int)($m[0] ?? 200);
        if ($status >= 400) return null;
    }

    $data = json_decode($raw, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $data : null;
}

/** Nhận diện tên tác giả (2–4 từ, chữ cái đầu hoa) */
function isAuthorQuery(string $q): bool {
    $parts = preg_split('/\s+/', trim($q));
    if (count($parts) < 2 || count($parts) > 4) return false;
    foreach ($parts as $p) {
        if (!preg_match('/^\p{Lu}/u', $p)) return false;
    }
    return true;
}

/** Tính tần suất trong mảng giá trị */
function countFreq(array $values): array {
    $freq = [];
    foreach ($values as $v) {
        if (!$v) continue;
        $freq[$v] = ($freq[$v] ?? 0) + 1;
    }
    arsort($freq);
    return $freq;
}

/**
 * Fallback khi bài báo không có trường "subject" (rất phổ biến với IEEE...):
 * trích các từ khóa "có ý nghĩa" từ tiêu đề để dùng thay cho subtopic.
 */
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

/* ═══════════════ XÂY DỰNG QUERY ═══════════════ */

$isAuthor = isAuthorQuery($topic);
$baseUrl  = 'https://api.crossref.org/works';
$mailto   = 'mailto=admin@scholartrend.vn';

// Filter năm
$yearFilter = '';
if ($yearFrom) $yearFilter .= ',from-pub-date:' . $yearFrom;
if ($yearTo)   $yearFilter .= ',until-pub-date:' . $yearTo;
if ($yearFilter) $yearFilter = 'filter=' . ltrim($yearFilter, ',') . '&';

/* ═══════════════ 1. TỔNG SỐ & FACET THEO NĂM ═══════════════ */
if ($isAuthor) {
    $queryParam = 'query.author=' . urlencode($topic);
} else {
    $queryParam = 'query=' . urlencode($topic);
}

$facetUrl = $baseUrl . '?' . $queryParam . '&' . $yearFilter
          . 'facet=published:*&rows=0&' . $mailto;

$facetData = crossrefGet($facetUrl);
if (!$facetData) {
    http_response_code(502);
    ob_clean();
    echo json_encode(['error' => 'Không kết nối được CrossRef. Vui lòng thử lại sau.']);
    exit;
}

$totalWorks = (int)($facetData['message']['total-results'] ?? 0);

// Yearly counts từ facet
$yearlyRaw = $facetData['message']['facets']['published']['values'] ?? [];
$yearly = [];
foreach ($yearlyRaw as $y => $cnt) {
    $yr = (int)$y;
    if ($yearFrom && $yr < $yearFrom) continue;
    if ($yearTo   && $yr > $yearTo)   continue;
    $yearly[$yr] = (int)$cnt;
}
ksort($yearly);

$yearlyArr = [];
foreach ($yearly as $yr => $cnt) {
    $yearlyArr[] = ['year' => $yr, 'count' => $cnt];
}

/* Nếu facet không trả dữ liệu, ước tính bằng query từng năm (tối đa 5 năm) */
if (empty($yearlyArr) && ($yearTo - $yearFrom) <= 5) {
    for ($yr = $yearFrom; $yr <= $yearTo; $yr++) {
        $yUrl = $baseUrl . '?' . $queryParam
              . '&filter=from-pub-date:' . $yr . ',until-pub-date:' . $yr
              . '&rows=0&' . $mailto;
        $yData = crossrefGet($yUrl);
        $yearlyArr[] = ['year' => $yr, 'count' => (int)($yData['message']['total-results'] ?? 0)];
    }
}

/* ═══════════════ 2. MẪU 50 BÀI — trích dẫn, OA, journal, subtopic, authors ═══════════════ */
$sampleUrl = $baseUrl . '?' . $queryParam . '&' . $yearFilter
           . 'rows=50&select=DOI,title,author,published,container-title,'
           . 'is-referenced-by-count,subject,link&sort=is-referenced-by-count&order=desc&'
           . $mailto;

$sampleData = crossrefGet($sampleUrl);
$items = $sampleData['message']['items'] ?? [];

$totalCitations = 0;
$oaCount        = 0;
$journals       = [];
$subjects       = [];
$authorCounts   = [];   // name => [works, citations]
$highlights     = [];

foreach ($items as $item) {
    $cite  = (int)($item['is-referenced-by-count'] ?? 0);
    $totalCitations += $cite;

    // Open Access: có link với content-version=vor hoặc link không null
    $links = $item['link'] ?? [];
    $isOA  = false;
    foreach ($links as $lk) {
        if (($lk['content-version'] ?? '') === 'vor' || ($lk['intended-application'] ?? '') === 'text-mining') {
            $isOA = true; break;
        }
    }
    if ($isOA) $oaCount++;

    // Journal
    $journal = $item['container-title'][0] ?? '';
    if ($journal) $journals[$journal] = ($journals[$journal] ?? 0) + 1;

    // Tiêu đề (dùng cho cả highlights lẫn fallback subject)
    $titleStr = is_array($item['title'] ?? null) ? ($item['title'][0] ?? '') : ($item['title'] ?? '');

    // Subjects — nếu CrossRef không cung cấp (rất phổ biến với IEEE),
    // fallback sang trích từ khóa từ tiêu đề bài báo.
    if (!empty($item['subject'])) {
        foreach ($item['subject'] as $s) {
            $s = trim($s);
            if (!$s) continue;
            $subjects[$s] = ($subjects[$s] ?? 0) + 1;
        }
    } else {
        foreach (extractKeywords($titleStr) as $kw) {
            $subjects[$kw] = ($subjects[$kw] ?? 0) + 1;
        }
    }

    // Authors
    foreach (($item['author'] ?? []) as $a) {
        $name = trim(($a['given'] ?? '') . ' ' . ($a['family'] ?? ''));
        if (!$name || $name === ' ') continue;
        if (!isset($authorCounts[$name])) $authorCounts[$name] = ['works' => 0, 'citations' => 0];
        $authorCounts[$name]['works']++;
        $authorCounts[$name]['citations'] += $cite;
    }

    // Highlights (top 3 được trích dẫn nhiều nhất)
    if (count($highlights) < 3) {
        $doi  = $item['DOI'] ?? '';
        $year = $item['published']['date-parts'][0][0] ?? null;
        $highlights[] = [
            'title'     => $titleStr,
            'year'      => $year,
            'citations' => $cite,
            'doi'       => $doi ? 'https://doi.org/' . $doi : '',
            'is_oa'     => $isOA,
        ];
    }
}

$oaPercent = count($items) > 0 ? round($oaCount / count($items) * 100) : 0;

// Top subtopics
arsort($subjects);
$topSubtopics = [];
foreach (array_slice($subjects, 0, 6, true) as $name => $cnt) {
    $topSubtopics[] = ['name' => $name, 'count' => $cnt];
}

// Top journals
arsort($journals);
$topJournals = [];
foreach (array_slice($journals, 0, 6, true) as $name => $cnt) {
    $topJournals[] = ['name' => $name, 'count' => $cnt];
}

// Top authors
uasort($authorCounts, fn($a, $b) => $b['citations'] <=> $a['citations']);
$topAuthors = [];
foreach (array_slice($authorCounts, 0, 8, true) as $name => $stat) {
    $topAuthors[] = ['name' => $name, 'works' => $stat['works'], 'citations' => $stat['citations']];
}

/* ═══════════════ 3. XU HƯỚNG (so sánh nửa đầu vs nửa cuối chuỗi thời gian) ═══════════════ */
$trend             = 'stable';
$growthRatePercent = 0;

if (count($yearlyArr) >= 4) {
    $half   = (int)(count($yearlyArr) / 2);
    $first  = array_slice($yearlyArr, 0, $half);
    $second = array_slice($yearlyArr, $half);
    $avgFirst  = array_sum(array_column($first,  'count')) / max(count($first),  1);
    $avgSecond = array_sum(array_column($second, 'count')) / max(count($second), 1);

    if ($avgFirst > 0) {
        $growthRatePercent = round(($avgSecond - $avgFirst) / $avgFirst * 100);
        if ($growthRatePercent >  10) $trend = 'rising';
        if ($growthRatePercent < -10) $trend = 'falling';
    }
}

/* ═══════════════ 4. NHẬN DIỆN TÁC GIẢ ═══════════════ */
$matchedAuthor = null;
if ($isAuthor && !empty($topAuthors)) {
    // Ưu tiên tên gần nhất với input
    foreach ($topAuthors as $a) {
        similar_text(strtolower($topic), strtolower($a['name']), $pct);
        if ($pct > 60) { $matchedAuthor = $a['name']; break; }
    }
}

/* ═══════════════ RESPONSE ═══════════════ */
ob_clean();
echo json_encode([
    'source'              => 'crossref',
    'mode'                => $isAuthor ? 'author' : 'topic',
    'matched_author'      => $matchedAuthor,
    'total_works'         => $totalWorks,
    'total_citations'     => $totalCitations,
    'oa_percent'          => $oaPercent,
    'trend'               => $trend,
    'growth_rate_percent' => abs($growthRatePercent),
    'yearly'              => $yearlyArr,
    'top_subtopics'       => $topSubtopics,
    'top_journals'        => $topJournals,
    'top_authors'         => $topAuthors,
    'highlights'          => $highlights,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);