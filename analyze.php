<?php
// ================================================
//  DermBot — Secure AI Analysis Backend
//  Powered by OpenAI GPT-4o
//  API key never exposed to frontend
// ================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'Method not allowed']); exit(); }

require_once '../config.php';

// ---- Rate limiting by IP (simple file-based) ----
$ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$logDir  = '../logs';
$logFile = $logDir . '/scans_' . date('Y-m-d') . '.json';

if (!is_dir($logDir)) mkdir($logDir, 0755, true);

$logs = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : [];
$ipScans = $logs[$ip] ?? 0;

if ($ipScans >= FREE_SCAN_LIMIT) {
    echo json_encode([
        'error'   => 'rate_limit',
        'message' => 'Free limit reached. Upgrade to Pro for unlimited scans.',
        'limit'   => FREE_SCAN_LIMIT
    ]);
    exit();
}

// ---- Read POST data ----
$body = json_decode(file_get_contents('php://input'), true);

if (empty($body['image'])) {
    echo json_encode(['error' => 'No image provided']); exit();
}

$imageData  = $body['image'];
$mimeType   = $body['mime'] ?? 'image/jpeg';
$symptoms   = trim($body['symptoms'] ?? '');

// Validate mime type
$allowed = ['image/jpeg','image/png','image/webp','image/gif'];
if (!in_array($mimeType, $allowed)) {
    echo json_encode(['error' => 'Invalid image type']); exit();
}

// ---- Build prompt ----
$symptomsText = $symptoms ?: 'No description — analyze from image only.';

$prompt = <<<PROMPT
You are DermBot, a skincare education assistant for South Asian users.

FIRST — check if the uploaded image shows human skin (face, neck, back, chest, arms etc.).
If it does NOT show human skin (book, food, object, animal, text, screenshot), respond ONLY with:
{"not_skin":true,"message":"This doesn't look like a skin photo. Please upload a clear photo of the skin area you want to analyze."}

If it IS a skin photo, respond ONLY with this JSON — no markdown, no extra text:
{"condition":"Short name e.g. Mild inflammatory acne","severity":"mild or moderate or severe","explanation":"2-3 sentences starting with Your skin shows signs of... Never definitively diagnose.","triggers":"2-3 sentences about likely causes: diet, stress, climate, products","routine_am":["Step 1: ...","Step 2: ...","Step 3: ..."],"routine_pm":["Step 1: ...","Step 2: ...","Step 3: ..."],"use":["ingredient 1","ingredient 2","ingredient 3","ingredient 4"],"avoid":["ingredient 1","ingredient 2","ingredient 3"],"doctor":"One sentence: see a dermatologist if..."}

Rules: Only OTC-safe ingredients (niacinamide, salicylic acid max 2%, benzoyl peroxide max 2.5%, hyaluronic acid, ceramides, gentle cleansers, SPF 30+, azelaic acid 10%). Never prescriptions. Consider South Asian hyperpigmentation tendency.

User description: "$symptomsText"
PROMPT;

// ---- Call OpenAI GPT-4o API ----
$payload = json_encode([
    'model'      => 'gpt-4o',
    'max_tokens' => 1000,
    'messages'   => [[
        'role'    => 'user',
        'content' => [
            [
                'type'      => 'image_url',
                'image_url' => [
                    'url'    => 'data:' . $mimeType . ';base64,' . $imageData,
                    'detail' => 'high'
                ]
            ],
            [
                'type' => 'text',
                'text' => $prompt
            ]
        ]
    ]]
]);

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY
    ],
    CURLOPT_TIMEOUT        => 30
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$response) {
    echo json_encode(['error' => 'Network error. Please try again.']); exit();
}

$data = json_decode($response, true);

if ($httpCode !== 200 || !empty($data['error'])) {
    $msg = $data['error']['message'] ?? 'API error. Please try again.';
    echo json_encode(['error' => $msg]); exit();
}

$raw = trim($data['choices'][0]['message']['content'] ?? '');
$raw = preg_replace('/```json|```/', '', $raw);
$result = json_decode(trim($raw), true);

if (!$result) {
    echo json_encode(['error' => 'AI response unclear. Please try again.']); exit();
}

// ---- Log scan ----
$logs[$ip] = $ipScans + 1;
file_put_contents($logFile, json_encode($logs));

// ---- Return result + scans remaining ----
$result['scans_used']      = $ipScans + 1;
$result['scans_remaining'] = max(0, FREE_SCAN_LIMIT - ($ipScans + 1));

echo json_encode($result);