<?php
/**
 * xuhuong_api.php
 * Backend cho trang "Phân tích Xu hướng" (phantichxuhuong.html).
 *
 * Nguồn dữ liệu chính: OpenAlex API.
 *   - Tìm theo TÁC GIẢ: tự động tra cứu /authors?search= trước, nếu tìm thấy
 *     ứng viên hợp lý thì chuyển sang lọc theo authorships.author.id.
 *   - Tìm theo CHỦ ĐỀ (mặc định khi không khớp tác giả nào).
 *   - Có retry tự động khi bị 429 (rate limit) + delay nhỏ giữa các request.
 *
 * Nguồn dự phòng: CrossRef API.
 *   - Nếu OpenAlex vẫn bị 429 sau khi đã retry hết số lần cho phép,
 *     tự động chuyển sang gọi CrossRef để vẫn trả dữ liệu cho người dùng.
 *   - Dữ liệu từ CrossRef có 1 số chỉ số mang tính ƯỚC LƯỢNG (vd: số liệu theo
 *     năm, chủ đề con) vì CrossRef không có group_by mạnh như OpenAlex.
 *     JSON trả về sẽ có "source": "crossref" và "is_estimated": true để
 *     frontend có thể hiển thị cảnh báo phù hợp.
 *
 * Params:
 *   topic      (bắt buộc) : chủ đề hoặc tên tác giả cần phân tích
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

// ===================== HELPER: cURL chung cho OpenAlex & CrossRef =====================
function httpGetJson($url, $retries = 4, $retryOn429 = true) {
    for ($attempt = 0; $attempt <= $retries; $attempt++) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'ScholarTrendDashboard/1.0 (contact@scholartrend.example)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ];

        $localCaBundle = __DIR__ . '/cacert.pem';
        if (file_exists($localCaBundle)) {
            $opts[CURLOPT_CAINFO] = $localCaBundle;
        } else {
            // Fallback cho môi trường local/dev khi chưa có cacert.pem. KHÔNG dùng trên production.
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            if ($attempt < $retries) { usleep(300000); continue; }
            throw new Exception('Không thể kết nối: ' . $err);
        }

        if ($httpCode === 429) {
            if ($retryOn429 && $attempt < $retries) {
                usleep(min(700000 * ($attempt + 1), 2500000));
                continue;
            }
            throw new Exception('RATE_LIMIT_429');
        }

        if ($httpCode !== 200) {
            throw new Exception('Server trả về lỗi HTTP ' . $httpCode);
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new Exception('Phản hồi không hợp lệ (không phải JSON).');
        }

        usleep(350000); // delay nhỏ tránh bắn request quá nhanh
        return $data;
    }

    throw new Exception('Không thể lấy dữ liệu sau nhiều lần thử.');
}

function openAlexCall($endpoint, $params) {
    $params['mailto'] = 'contact@scholartrend.example';
    $url = 'https://api.openalex.org/' . $endpoint . '?' . http_build_query($params);
    return httpGetJson($url);
}

function openAlexGet($params) {
    return openAlexCall('works', $params);
}

// ---------- Chuẩn hoá chuỗi để so khớp tên (bỏ dấu, lowercase) ----------
function normalizeName($s) {
    $s = mb_strtolower(trim($s), 'UTF-8');
    if (function_exists('transliterator_transliterate')) {
        $t = transliterator_transliterate('Any-Latin; Latin-ASCII;', $s);
        if ($t !== false) $s = $t;
    }
    $s = preg_replace('/[^a-z0-9 ]/', '', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

function namesLooselyMatch($a, $b) {
    $na = normalizeName($a);
    $nb = normalizeName($b);
    if ($na === '' || $nb === '') return false;
    if ($na === $nb) return true;
    if (strpos($na, $nb) !== false || strpos($nb, $na) !== false) return true;
    similar_text($na, $nb, $pct);
    return $pct >= 80;
}

function buildWorksParams($isAuthorMode, $authorId, $topic, $yearFilter, $extra = []) {
    $filters = [$yearFilter];
    $params  = [];
    if ($isAuthorMode) {
        $shortId = preg_replace('#^https?://openalex\.org/#', '', $authorId);
        $filters[] = 'authorships.author.id:' . $shortId;
    } else {
        $params['search'] = $topic;
    }
    $params['filter'] = implode(',', $filters);
    return array_merge($params, $extra);
}

// ===================== NGUỒN CHÍNH: OpenAlex =====================
function fetchFromOpenAlex($topic, $yearFrom, $yearTo, $yearFilter) {
    // 0. Thử nhận diện tác giả
    $authorId   = null;
    $authorName = null;

    $authorResp = openAlexCall('authors', ['search' => $topic, 'per-page' => 5]);
    $candidates = $authorResp['results'] ?? [];

    $bestMatch = null;
    foreach ($candidates as $cand) {
        $candName = $cand['display_name'] ?? '';
        if (namesLooselyMatch($topic, $candName)) {
            if ($bestMatch === null || ($cand['works_count'] ?? 0) > ($bestMatch['works_count'] ?? 0)) {
                $bestMatch = $cand;
            }
        }
    }
    if ($bestMatch) {
        $authorId   = $bestMatch['id'] ?? null;
        $authorName = $bestMatch['display_name'] ?? null;
    }
    $isAuthorMode = $authorId !== null;

    // 1+2. Mẫu top 50 + tổng số (gộp 1 request)
    $sampleResp = openAlexGet(buildWorksParams($isAuthorMode, $authorId, $topic, $yearFilter, [
        'per-page' => 50,
        'sort'     => 'cited_by_count:desc',
    ]));
    $works      = $sampleResp['results'] ?? [];
    $totalWorks = (int)($sampleResp['meta']['count'] ?? 0);

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

    // 3. Số lượng tác phẩm theo năm
    $yearlyResp = openAlexGet(buildWorksParams($isAuthorMode, $authorId, $topic, $yearFilter, ['group_by' => 'publication_year']));
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
        if ($growthRate > 5) $trend = 'rising';
        elseif ($growthRate < -5) $trend = 'falling';
        else $trend = 'stable';
    }

    // 5. Chủ đề con liên quan
    $topicsResp = openAlexGet(buildWorksParams($isAuthorMode, $authorId, $topic, $yearFilter, ['group_by' => 'primary_topic.id']));
    $topicsRaw = $topicsResp['group_by'] ?? [];

    $topSubtopics = [];
    foreach ($topicsRaw as $g) {
        if (empty($g['key']) || empty($g['key_display_name'])) continue;
        $topSubtopics[] = ['name' => $g['key_display_name'], 'count' => (int)($g['count'] ?? 0)];
    }
    usort($topSubtopics, function ($a, $b) { return $b['count'] - $a['count']; });
    $topSubtopics = array_slice($topSubtopics, 0, 6);

    return [
        'source'               => 'openalex',
        'is_estimated'         => false,
        'mode'                 => $isAuthorMode ? 'author' : 'topic',
        'matched_author'       => $authorName,
        'total_works'          => $totalWorks,
        'total_citations'      => $totalCitations,
        'oa_percent'           => $oaPercent,
        'trend'                => $trend,
        'growth_rate_percent'  => $growthRate,
        'yearly'               => $yearly,
        'top_subtopics'        => $topSubtopics,
        'top_authors'          => $topAuthors,
        'highlights'           => $highlights,
    ];
}

// ===================== NGUỒN DỰ PHÒNG: CrossRef =====================
// Dùng khi OpenAlex bị 429. CrossRef không có group_by mạnh như OpenAlex nên
// các số liệu "yearly" và "top_subtopics" được TÍNH ƯỚC LƯỢNG từ mẫu 50 bài
// lấy được, không phải con số chính xác toàn bộ tập dữ liệu.
function fetchFromCrossref($topic, $yearFrom, $yearTo) {
    $params = [
        'query.bibliographic' => $topic,
        'filter'   => 'from-pub-date:' . $yearFrom . '-01-01,until-pub-date:' . $yearTo . '-12-31',
        'rows'     => 50,
        'sort'     => 'is-referenced-by-count',
        'order'    => 'desc',
        'mailto'   => 'contact@scholartrend.example',
    ];
    $url  = 'https://api.crossref.org/works?' . http_build_query($params);
    $resp = httpGetJson($url, 2, false); // CrossRef hiếm khi 429, không cần retry nhiều

    $message    = $resp['message'] ?? [];
    $totalWorks = (int)($message['total-results'] ?? 0);
    $items      = $message['items'] ?? [];

    $totalCitations = 0;
    $oaCount        = 0;
    $authorMap      = [];
    $yearCounts     = [];
    $subjectCounts  = [];

    foreach ($items as $it) {
        $cites = (int)($it['is-referenced-by-count'] ?? 0);
        $totalCitations += $cites;

        // CrossRef không có cờ is_oa chuẩn; coi có license mở (vd CC) là OA gần đúng
        $isOa = false;
        if (!empty($it['license']) && is_array($it['license'])) {
            foreach ($it['license'] as $lic) {
                if (!empty($lic['URL']) && stripos($lic['URL'], 'creativecommons') !== false) {
                    $isOa = true;
                    break;
                }
            }
        }
        if ($isOa) $oaCount++;

        $year = null;
        $dateParts = $it['published']['date-parts'][0]
            ?? ($it['published-print']['date-parts'][0] ?? ($it['published-online']['date-parts'][0] ?? null));
        if ($dateParts && isset($dateParts[0])) {
            $year = (int)$dateParts[0];
            if (!isset($yearCounts[$year])) $yearCounts[$year] = 0;
            $yearCounts[$year]++;
        }

        if (!empty($it['subject']) && is_array($it['subject'])) {
            foreach ($it['subject'] as $subj) {
                if (!isset($subjectCounts[$subj])) $subjectCounts[$subj] = 0;
                $subjectCounts[$subj]++;
            }
        }

        if (!empty($it['author']) && is_array($it['author'])) {
            foreach ($it['author'] as $a) {
                $name = trim(($a['given'] ?? '') . ' ' . ($a['family'] ?? ''));
                if ($name === '') continue;
                if (!isset($authorMap[$name])) $authorMap[$name] = ['works' => 0, 'citations' => 0];
                $authorMap[$name]['works']++;
                $authorMap[$name]['citations'] += $cites;
            }
        }
    }

    $oaPercent = count($items) > 0 ? round(($oaCount / count($items)) * 100) : 0;

    $topAuthors = [];
    foreach ($authorMap as $name => $info) {
        $topAuthors[] = ['name' => $name, 'works' => $info['works'], 'citations' => $info['citations']];
    }
    usort($topAuthors, function ($a, $b) { return $b['citations'] - $a['citations']; });
    $topAuthors = array_slice($topAuthors, 0, 8);

    $highlights = [];
    foreach (array_slice($items, 0, 3) as $it) {
        $title = isset($it['title'][0]) ? $it['title'][0] : 'Untitled';
        $year  = null;
        $dateParts = $it['published']['date-parts'][0] ?? null;
        if ($dateParts && isset($dateParts[0])) $year = (int)$dateParts[0];
        $highlights[] = [
            'title'     => $title,
            'year'      => $year,
            'citations' => (int)($it['is-referenced-by-count'] ?? 0),
            'is_oa'     => false,
            'doi'       => isset($it['DOI']) ? 'https://doi.org/' . $it['DOI'] : null,
        ];
    }

    ksort($yearCounts);
    $yearly = [];
    foreach ($yearCounts as $y => $c) {
        if ($y >= $yearFrom && $y <= $yearTo) {
            $yearly[] = ['year' => $y, 'count' => $c];
        }
    }

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
        if ($growthRate > 5) $trend = 'rising';
        elseif ($growthRate < -5) $trend = 'falling';
        else $trend = 'stable';
    }

    arsort($subjectCounts);
    $topSubtopics = [];
    foreach (array_slice($subjectCounts, 0, 6, true) as $name => $count) {
        $topSubtopics[] = ['name' => $name, 'count' => $count];
    }

    return [
        'source'               => 'crossref',
        'is_estimated'         => true,
        'mode'                 => 'topic',
        'matched_author'       => null,
        'total_works'          => $totalWorks,
        'total_citations'      => $totalCitations,
        'oa_percent'           => $oaPercent,
        'trend'                => $trend,
        'growth_rate_percent'  => $growthRate,
        'yearly'               => $yearly,
        'top_subtopics'        => $topSubtopics,
        'top_authors'          => $topAuthors,
        'highlights'           => $highlights,
    ];
}

// ===================== Điều phối: thử OpenAlex trước, fallback CrossRef khi 429 =====================
try {
    try {
        $result = fetchFromOpenAlex($topic, $yearFrom, $yearTo, $yearFilter);
    } catch (Exception $e) {
        if ($e->getMessage() === 'RATE_LIMIT_429') {
            // OpenAlex bị giới hạn tốc độ -> chuyển sang CrossRef
            $result = fetchFromCrossref($topic, $yearFrom, $yearTo);
        } else {
            throw $e;
        }
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()]);
}