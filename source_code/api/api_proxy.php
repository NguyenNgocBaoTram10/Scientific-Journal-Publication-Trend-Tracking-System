<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$query = trim($_GET['q'] ?? '');
if (!$query) { echo json_encode(['data' => [], 'total' => 0]); exit; }

$url = "https://api.semanticscholar.org/graph/v1/paper/search"
     . "?query=" . urlencode($query)
     . "&limit=10"
     . "&fields=title,abstract,year,authors,journal,citationCount,externalIds,openAccessPdf";

$ctx = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'timeout' => 10,
        'header'  => "Accept: application/json\r\nUser-Agent: ScholarTrend/1.0\r\n"
    ]
]);

$response = @file_get_contents($url, false, $ctx);
echo $response ?: json_encode(['data' => [], 'total' => 0, 'error' => 'API error']);