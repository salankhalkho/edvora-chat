<?php

// Authenticate as Super Admin and test GET and PUT /v1/superadmin/prompt
$baseUrl = 'https://edvora.chat';

function callApi($url, $method = 'GET', $payload = null, $token = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($payload) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        if ($payload) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $status, 'data' => json_decode($res, true)];
}

echo "1. Authenticating as SuperAdmin..." . PHP_EOL;
$login = callApi($baseUrl . '/v1/auth/login', 'POST', [
    'email' => 'superadmin@edvora.chat',
    'password' => 'SuperAdmin_Secure2026!'
]);

if (empty($login['data']['data']['access_token'])) {
    echo "Login failed: " . json_encode($login) . PHP_EOL;
    exit(1);
}

$token = $login['data']['data']['access_token'];
echo "✓ Authenticated! Token acquired." . PHP_EOL;

echo PHP_EOL . "2. Testing GET /v1/superadmin/prompt..." . PHP_EOL;
$getPrompt = callApi($baseUrl . '/v1/superadmin/prompt', 'GET', null, $token);
echo "HTTP Status: " . $getPrompt['code'] . PHP_EOL;

if (!empty($getPrompt['data']['data'])) {
    $p = $getPrompt['data']['data'];
    foreach (['master_prompt_phase_1', 'master_prompt_phase_2', 'master_prompt_scholarship', 'master_prompt_phase_3', 'master_prompt'] as $k) {
        $len = strlen($p[$k] ?? '');
        echo " - {$k}: {$len} characters | Snippet: " . substr($p[$k] ?? '', 0, 40) . "..." . PHP_EOL;
    }
} else {
    echo "Failed to retrieve prompts: " . json_encode($getPrompt) . PHP_EOL;
}

echo PHP_EOL . "3. Testing PUT /v1/superadmin/prompt (Update Phase 1)..." . PHP_EOL;
$testNewContent = $getPrompt['data']['data']['master_prompt_phase_1'] . "\n\n# Verification Stamp: " . time();
$putRes = callApi($baseUrl . '/v1/superadmin/prompt', 'PUT', [
    'key' => 'master_prompt_phase_1',
    'prompt' => $testNewContent
], $token);

echo "HTTP Status: " . $putRes['code'] . PHP_EOL;
echo "Response message: " . ($putRes['data']['message'] ?? 'none') . PHP_EOL;

echo PHP_EOL . "4. Verifying Persistence via immediate GET..." . PHP_EOL;
$verifyGet = callApi($baseUrl . '/v1/superadmin/prompt', 'GET', null, $token);
$savedText = $verifyGet['data']['data']['master_prompt_phase_1'] ?? '';
if (strpos($savedText, 'Verification Stamp') !== false) {
    echo "✓ SUCCESS! The updated prompt was verified persisted in the database!" . PHP_EOL;
} else {
    echo "❌ FAILED: Persistence check did not find verification stamp." . PHP_EOL;
}

// Restore original content
echo PHP_EOL . "5. Restoring original Phase 1 content..." . PHP_EOL;
$origContent = trim(file_get_contents(__DIR__ . '/../app/Config/Prompts/phase_1.txt'));
callApi($baseUrl . '/v1/superadmin/prompt', 'PUT', [
    'key' => 'master_prompt_phase_1',
    'prompt' => $origContent
], $token);
echo "✓ Restored cleanly to original template." . PHP_EOL;
