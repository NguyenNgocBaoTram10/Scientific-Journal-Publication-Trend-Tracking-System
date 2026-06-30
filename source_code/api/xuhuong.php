<?php
/**
 * xuhuong_api.php
 * Backend cho trang "Phân tích Xu hướng" (phantichxuhuong.html).
 * Gọi OpenAlex API, tổng hợp dữ liệu và trả JSON đúng cấu trúc mà frontend cần.
 *
 * Params:
 *   topic      (bắt buộc) : chủ đề cần phân tích
 *   year_from  (tuỳ chọn) : năm bắt đầu, mặc định = năm hiện tại - 9
 *   year_to    (tuỳ chọn) : năm kết thúc, mặc định = năm hiện tại
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$topic    = isset($_GET['topic']) ? trim($_GET['topic']) : '';
$yearFrom = isset($_GET['year_from']) ? preg_replace('/[^0-9]/', '', $_GET['year_from']) : '';
$yearTo   = isset($_GET['year_to'])   ? preg_replace('/[^0-9]/', '', $_GET['year_to'])   : '';

if ($topic === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Thiếu tham số topic.']);
    exit;
}

$currentYear = (int)date('Y');
if ($yearFrom === '') $yearFrom = $currentYear - 9;
if ($yearTo === '')   $yearTo   = $currentYear;
$yearFrom = (int)$yearFrom;
$yearTo   = (int)$yearTo;
if ($yearFrom > $yearTo) { $tmp = $yearFrom; $yearFrom = $yearTo; $yearTo = $tmp; }

$yearFilter = 'publication_year:' . $yearFrom . '-' . $yearTo;

function openAlexGet($params) {
    $params['mailto'] = 'contact@scholartrend.example';
    $url = 'https://api.openalex.org/works?' . http_build_query($params);

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'ScholarTrendDashboard/1.0 (contact@scholartrend.example)',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ];

    // Nếu có sẵn file cacert.pem trong cùng thư mục api/, dùng nó để xác thực SSL
    // (giải pháp không cần sửa php.ini của WAMPP).
    $localCaBundle = __DIR__ . '/cacert.pem';
    if (file_exists($localCaBundle)) {
        $opts[CURLOPT_CAINFO] = $localCaBundle;
    } else {
        // Fallback cho môi trường local/dev khi chưa có cacert.pem:
        // tắt xác thực SSL để tránh lỗi "unable to get local issuer certificate".
        // KHÔNG dùng trên production.
        $opts[CURLOPT_SSL_VERIFYPEER] = false;
        $opts[CURLOPT_SSL_VERIFYHOST] = 0;
    }

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('Không thể kết nối tới OpenAlex: ' . $err);
    }
    if ($httpCode !== 200) {
        throw new Exception('OpenAlex trả về lỗi HTTP ' . $httpCode);
    }
    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new Exception('Phản hồi từ OpenAlex không hợp lệ.');
    }
    return $data;
}

try {
    // 1. Tổng số công trình
    $countResp  = openAlexGet([
        'search'   => $topic,
        'filter'   => $yearFilter,
        'per-page' => 1,
    ]);
    $totalWorks = (int)($countResp['meta']['count'] ?? 0);

    // 2. Mẫu top 50 theo trích dẫn
    $sampleResp = openAlexGet([
        'search'   => $topic,
        'filter'   => $yearFilter,
        'per-page' => 50,
        'sort'     => 'cited_by_count:desc',
    ]);
    $works = $sampleResp['results'] ?? [];

    $totalCitations = 0;
    $oaCount        = 0;
    $authorMap      = [];

    foreach ($works as $w) {
        $cites = $w['cited_by_count'] ?? 0;
        $totalCitations += $cites;
        if (!empty($w['open_access']['is_oa'])) $oaCount++;

        if (!empty($w['authorships']) && is_array($w['authorships'])) {
            foreach ($w['authorships'] as $a) {
                $name = $a['author']['display_name'] ?? null;
                if (!$name) continue;
                if (!isset($authorMap[$name])) $authorMap[$name] = ['works' => 0, 'citations' => 0];
                $authorMap[$name]['works']++;
                $authorMap[$name]['citations'] += $cites;
            }
        }
    }

    $oaPercent = count($works) > 0 ? round(($oaCount / count($works)) * 100) : 0;

    $topAuthors = [];
    foreach ($authorMap as $name => $info) {
        $topAuthors[] = ['name' => $name, 'works' => $info['works'], 'citations' => $info['citations']];
    }
    usort($topAuthors, function ($a, $b) { return $b['citations'] - $a['citations']; });
    $topAuthors = array_slice($topAuthors, 0, 8);

    $highlights = [];
    foreach (array_slice($works, 0, 3) as $w) {
        $highlights[] = [
            'title'     => $w['display_name'] ?? ($w['title'] ?? 'Untitled'),
            'year'      => $w['publication_year'] ?? null,
            'citations' => $w['cited_by_count'] ?? 0,
            'is_oa'     => !empty($w['open_access']['is_oa']),
            'doi'       => $w['doi'] ?? null,
        ];
    }

    // 3. Số lượng công trình theo năm
    $yearlyResp = openAlexGet([
        'search'   => $topic,
        'filter'   => $yearFilter,
        'group_by' => 'publication_year',
    ]);
    $yearlyRaw = $yearlyResp['group_by'] ?? [];

    $yearly = [];
    foreach ($yearlyRaw as $g) {
        $y = (int)($g['key'] ?? 0);
        if ($y >= $yearFrom && $y <= $yearTo) {
            $yearly[] = ['year' => $y, 'count' => (int)($g['count'] ?? 0)];
        }
    }
    usort($yearly, function ($a, $b) { return $a['year'] - $b['year']; });

    // 4. Xu hướng
    $trend = 'stable';
    $growthRate = 0;
    if (count($yearly) >= 2) {
        $half = (int)floor(count($yearly) / 2);
        $firstHalf  = array_slice($yearly, 0, max($half, 1));
        $secondHalf = array_slice($yearly, -max($half, 1));

        $sumFirst  = array_sum(array_column($firstHalf, 'count'));
        $sumSecond = array_sum(array_column($secondHalf, 'count'));

        if ($sumFirst > 0) {
            $growthRate = round((($sumSecond - $sumFirst) / $sumFirst) * 100);
        } elseif ($sumSecond > 0) {
            $growthRate = 100;
        }

        if ($growthRate > 5) {
            $trend = 'rising';
        } elseif ($growthRate < -5) {
            $trend = 'falling';
        } else {
            $trend = 'stable';
        }
    }

    // 5. Chủ đề con liên quan
    $topicsResp = openAlexGet([
        'search'   => $topic,
        'filter'   => $yearFilter,
        'group_by' => 'primary_topic.id',
    ]);
    $topicsRaw = $topicsResp['group_by'] ?? [];

    $topSubtopics = [];
    foreach ($topicsRaw as $g) {
        if (empty($g['key']) || empty($g['key_display_name'])) continue;
        $topSubtopics[] = ['name' => $g['key_display_name'], 'count' => (int)($g['count'] ?? 0)];
    }
    usort($topSubtopics, function ($a, $b) { return $b['count'] - $a['count']; });
    $topSubtopics = array_slice($topSubtopics, 0, 6);

    echo json_encode([
        'total_works'          => $totalWorks,
        'total_citations'      => $totalCitations,
        'oa_percent'           => $oaPercent,
        'trend'                => $trend,
        'growth_rate_percent'  => $growthRate,
        'yearly'               => $yearly,
        'top_subtopics'        => $topSubtopics,
        'top_authors'          => $topAuthors,
        'highlights'           => $highlights,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()]);
}