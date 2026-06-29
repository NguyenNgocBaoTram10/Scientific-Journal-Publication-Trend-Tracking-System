<?php
// timkiem.php - Tìm kiếm bài báo khoa học qua Semantic Scholar API

$query = trim($_GET['q'] ?? '');
$page  = max(1, intval($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$results   = [];
$total     = 0;
$error     = '';

if ($query !== '') {
    $encoded = urlencode($query);
    $url = "https://api.semanticscholar.org/graph/v1/paper/search"
         . "?query={$encoded}"
         . "&offset={$offset}"
         . "&limit={$limit}"
         . "&fields=title,abstract,year,authors,journal,citationCount,externalIds,url,openAccessPdf";

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 10,
            'header'  => "Accept: application/json\r\nUser-Agent: ScholarTrend/1.0\r\n",
        ]
    ]);

    $response = @file_get_contents($url, false, $ctx);

    if ($response === false) {
        $error = 'Không thể kết nối đến API. Vui lòng thử lại sau.';
    } else {
        $data = json_decode($response, true);
        if (isset($data['data'])) {
            $results = $data['data'];
            $total   = min($data['total'] ?? 0, 1000); // Semantic Scholar caps at 1000
        } else {
            $error = 'Không có kết quả hoặc API trả về lỗi.';
        }
    }
}

$totalPages = $total > 0 ? ceil($total / $limit) : 0;

function highlight(string $text, string $query): string {
    if ($query === '') return htmlspecialchars($text);
    $safe  = htmlspecialchars($text);
    $words = array_filter(explode(' ', preg_quote(htmlspecialchars($query), '/')));
    foreach ($words as $w) {
        $safe = preg_replace('/(' . $w . ')/iu', '<mark class="bg-yellow-100 text-yellow-900 rounded px-0.5">$1</mark>', $safe);
    }
    return $safe;
}

function truncate(string $text, int $len = 220): string {
    return mb_strlen($text) > $len ? mb_substr($text, 0, $len) . '…' : $text;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tìm kiếm - ScholarTrend</title>
  <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', sans-serif; }
    .spinner { animation: spin 1s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }
  </style>
</head>
<body class="bg-[#f7f9fb] min-h-screen">

<!-- ── NAV ── -->
<nav class="bg-white border-b border-gray-200 sticky top-0 z-30">
  <div class="max-w-6xl mx-auto px-4 h-14 flex items-center justify-between">
    <a href="trangchu.html" class="text-xl font-bold text-gray-900">ScholarTrend</a>
    <div class="hidden md:flex gap-6 text-sm font-medium text-gray-600">
      <a href="trangchu.html" class="hover:text-gray-900">Trang chủ</a>
      <a href="phantichxuhuong.html" class="hover:text-gray-900">Xu hướng</a>
    </div>
    <a href="dangnhap.html" class="text-sm text-blue-600 hover:underline">Đăng nhập</a>
  </div>
</nav>

<!-- ── SEARCH BAR ── -->
<div class="bg-white border-b border-gray-200 py-6">
  <div class="max-w-3xl mx-auto px-4">
    <form method="GET" action="timkiem.php" id="searchForm">
      <div class="flex gap-2">
        <div class="relative flex-1">
          <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
          </svg>
          <input
            type="text"
            name="q"
            id="q"
            value="<?= htmlspecialchars($query) ?>"
            placeholder="Tìm kiếm bài báo, tác giả, từ khóa..."
            autofocus
            class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
          >
        </div>
        <button
          type="submit"
          class="bg-gray-900 text-white px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-800 transition flex items-center gap-2"
          onclick="showLoader()"
        >
          <span>Tìm kiếm</span>
          <svg id="loader" class="spinner w-4 h-4 hidden" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
          </svg>
        </button>
      </div>

      <!-- Quick filters -->
      <?php if ($query): ?>
      <div class="mt-3 flex flex-wrap gap-2 text-xs text-gray-500">
        <span>Tìm nhanh:</span>
        <?php foreach (['AI Y tế', 'Deep Learning', 'ECG', 'Biến đổi khí hậu'] as $tag): ?>
          <a href="?q=<?= urlencode($tag) ?>"
             class="px-2 py-1 bg-gray-100 rounded-full hover:bg-blue-50 hover:text-blue-600 transition cursor-pointer">
            <?= htmlspecialchars($tag) ?>
          </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- ── MAIN ── -->
<div class="max-w-6xl mx-auto px-4 py-8">

  <?php if ($query === ''): ?>
  <!-- Empty state -->
  <div class="text-center py-20 text-gray-400">
    <svg class="mx-auto mb-4 w-12 h-12 opacity-30" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
      <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
    </svg>
    <p class="text-lg font-medium text-gray-500">Nhập từ khóa để tìm kiếm bài báo</p>
    <p class="text-sm mt-1">Tìm theo tên bài báo, tác giả, lĩnh vực nghiên cứu...</p>
    <div class="mt-6 flex flex-wrap justify-center gap-2">
      <?php foreach (['#AI_Y_tế', '#Biến_đổi_khí_hậu', '#Vật_lý_lượng_tử', '#Machine Learning', '#ECG'] as $tag): ?>
        <a href="?q=<?= urlencode(ltrim(str_replace('_', ' ', $tag), '#')) ?>"
           class="px-3 py-1.5 bg-blue-50 text-blue-600 rounded-full text-sm hover:bg-blue-100 transition">
          <?= htmlspecialchars($tag) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php elseif ($error): ?>
  <!-- Error state -->
  <div class="bg-red-50 border border-red-200 rounded-lg p-4 text-red-700 text-sm flex gap-3 items-start">
    <svg class="w-5 h-5 mt-0.5 shrink-0" fill="currentColor" viewBox="0 0 20 20">
      <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd"/>
    </svg>
    <div>
      <strong>Lỗi:</strong> <?= htmlspecialchars($error) ?>
      <br><a href="?q=<?= urlencode($query) ?>" class="underline mt-1 inline-block">Thử lại</a>
    </div>
  </div>

  <?php elseif (empty($results)): ?>
  <!-- No results -->
  <div class="text-center py-16 text-gray-400">
    <p class="text-lg font-medium text-gray-500">Không tìm thấy kết quả cho "<strong><?= htmlspecialchars($query) ?></strong>"</p>
    <p class="text-sm mt-1">Thử từ khóa khác hoặc kiểm tra lại chính tả.</p>
  </div>

  <?php else: ?>
  <!-- Results header -->
  <div class="flex items-center justify-between mb-5">
    <p class="text-sm text-gray-500">
      Tìm thấy <strong class="text-gray-800"><?= number_format($total) ?></strong> kết quả cho
      "<strong class="text-gray-800"><?= htmlspecialchars($query) ?></strong>"
      — Trang <?= $page ?>/<?= $totalPages ?>
    </p>
    <span class="text-xs text-gray-400">Nguồn: Semantic Scholar</span>
  </div>

  <!-- Result list -->
  <div class="space-y-4">
    <?php foreach ($results as $paper): ?>
    <?php
      $title     = $paper['title']       ?? 'Không có tiêu đề';
      $abstract  = $paper['abstract']    ?? '';
      $year      = $paper['year']        ?? '';
      $cite      = $paper['citationCount'] ?? 0;
      $journal   = $paper['journal']['name'] ?? '';
      $authors   = array_slice($paper['authors'] ?? [], 0, 4);
      $doi       = $paper['externalIds']['DOI'] ?? '';
      $paperId   = $paper['paperId']     ?? '';
      $pdfUrl    = $paper['openAccessPdf']['url'] ?? '';
      $detailUrl = $paperId ? "xemchitietbaibao.html?id={$paperId}" : '#';
    ?>
    <article class="bg-white border border-gray-200 rounded-xl p-5 hover:border-blue-300 hover:shadow-sm transition group">
      <div class="flex items-start justify-between gap-4">
        <div class="flex-1 min-w-0">

          <!-- Category badge + year -->
          <div class="flex items-center gap-2 mb-2 flex-wrap">
            <?php if ($journal): ?>
            <span class="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded-full font-medium">
              <?= htmlspecialchars(truncate($journal, 50)) ?>
            </span>
            <?php endif; ?>
            <?php if ($year): ?>
            <span class="text-xs text-gray-400"><?= htmlspecialchars($year) ?></span>
            <?php endif; ?>
          </div>

          <!-- Title -->
          <h2 class="text-base font-semibold text-gray-900 leading-snug mb-1 group-hover:text-blue-700 transition">
            <a href="<?= htmlspecialchars($detailUrl) ?>" class="hover:underline">
              <?= highlight($title, $query) ?>
            </a>
          </h2>

          <!-- Authors -->
          <?php if ($authors): ?>
          <p class="text-sm text-gray-500 mb-2">
            <?= implode(', ', array_map(fn($a) => htmlspecialchars($a['name'] ?? ''), $authors)) ?>
            <?= count($paper['authors'] ?? []) > 4 ? ' <em>et al.</em>' : '' ?>
          </p>
          <?php endif; ?>

          <!-- Abstract -->
          <?php if ($abstract): ?>
          <p class="text-sm text-gray-600 leading-relaxed">
            <?= highlight(truncate($abstract), $query) ?>
          </p>
          <?php endif; ?>

          <!-- Footer row -->
          <div class="mt-3 flex flex-wrap items-center gap-3 text-xs text-gray-400">
            <?php if ($cite > 0): ?>
            <span class="flex items-center gap-1">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
              </svg>
              <?= number_format($cite) ?> trích dẫn
            </span>
            <?php endif; ?>
            <?php if ($doi): ?>
            <a href="https://doi.org/<?= urlencode($doi) ?>" target="_blank" rel="noopener"
               class="flex items-center gap-1 hover:text-blue-600 transition">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
              </svg>
              DOI
            </a>
            <?php endif; ?>
            <?php if ($pdfUrl): ?>
            <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank" rel="noopener"
               class="flex items-center gap-1 text-green-600 hover:text-green-700 transition font-medium">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
              </svg>
              PDF miễn phí
            </a>
            <?php endif; ?>
          </div>
        </div>

        <!-- Save button -->
        <button
          title="Lưu bài báo"
          onclick="savePaper('<?= htmlspecialchars($paperId) ?>', '<?= htmlspecialchars(addslashes($title)) ?>', this)"
          class="shrink-0 p-2 rounded-lg border border-gray-200 hover:border-blue-300 hover:bg-blue-50 transition text-gray-400 hover:text-blue-600"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"/>
          </svg>
        </button>
      </div>
    </article>
    <?php endforeach; ?>
  </div>

  <!-- ── PAGINATION ── -->
  <?php if ($totalPages > 1): ?>
  <nav class="mt-8 flex justify-center items-center gap-2 text-sm" aria-label="Phân trang">
    <?php if ($page > 1): ?>
    <a href="?q=<?= urlencode($query) ?>&page=<?= $page - 1 ?>"
       class="px-3 py-1.5 border border-gray-300 rounded-lg hover:bg-gray-100 transition text-gray-600">
      ← Trước
    </a>
    <?php endif; ?>

    <?php
    $start = max(1, $page - 2);
    $end   = min($totalPages, $page + 2);
    if ($start > 1): ?><span class="px-2 text-gray-400">1 …</span><?php endif;
    for ($i = $start; $i <= $end; $i++):
      $active = $i === $page;
    ?>
    <a href="?q=<?= urlencode($query) ?>&page=<?= $i ?>"
       class="px-3 py-1.5 rounded-lg border transition
              <?= $active ? 'bg-gray-900 text-white border-gray-900' : 'border-gray-300 hover:bg-gray-100 text-gray-600' ?>">
      <?= $i ?>
    </a>
    <?php endfor;
    if ($end < $totalPages): ?><span class="px-2 text-gray-400">… <?= $totalPages ?></span><?php endif; ?>

    <?php if ($page < $totalPages): ?>
    <a href="?q=<?= urlencode($query) ?>&page=<?= $page + 1 ?>"
       class="px-3 py-1.5 border border-gray-300 rounded-lg hover:bg-gray-100 transition text-gray-600">
      Tiếp →
    </a>
    <?php endif; ?>
  </nav>
  <?php endif; ?>

  <?php endif; ?>
</div>

<!-- Toast notification -->
<div id="toast" class="fixed bottom-6 right-6 bg-gray-900 text-white text-sm px-4 py-2.5 rounded-lg shadow-lg
                        opacity-0 translate-y-2 transition-all duration-300 pointer-events-none z-50">
</div>

<script>
function showLoader() {
  document.getElementById('loader').classList.remove('hidden');
}

function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.remove('opacity-0', 'translate-y-2');
  t.classList.add('opacity-100', 'translate-y-0');
  setTimeout(() => {
    t.classList.add('opacity-0', 'translate-y-2');
    t.classList.remove('opacity-100', 'translate-y-0');
  }, 2500);
}

function savePaper(id, title, btn) {
  let saved = JSON.parse(localStorage.getItem('scholartrend_bookmarks') || '[]');
  const exists = saved.find(p => p.id === id);
  if (exists) {
    saved = saved.filter(p => p.id !== id);
    localStorage.setItem('scholartrend_bookmarks', JSON.stringify(saved));
    btn.classList.remove('text-blue-600', 'bg-blue-50', 'border-blue-300');
    btn.classList.add('text-gray-400');
    showToast('Đã xoá khỏi danh sách lưu');
  } else {
    saved.push({ id, title, savedAt: new Date().toISOString() });
    localStorage.setItem('scholartrend_bookmarks', JSON.stringify(saved));
    btn.classList.add('text-blue-600', 'bg-blue-50', 'border-blue-300');
    btn.classList.remove('text-gray-400');
    showToast('✓ Đã lưu bài báo');
  }
}

// Highlight saved papers on load
document.addEventListener('DOMContentLoaded', () => {
  const saved = JSON.parse(localStorage.getItem('scholartrend_bookmarks') || '[]');
  const ids = new Set(saved.map(p => p.id));
  document.querySelectorAll('[onclick^="savePaper"]').forEach(btn => {
    const match = btn.getAttribute('onclick').match(/savePaper\('([^']+)'/);
    if (match && ids.has(match[1])) {
      btn.classList.add('text-blue-600', 'bg-blue-50', 'border-blue-300');
      btn.classList.remove('text-gray-400');
    }
  });
});
</script>
</body>
</html>