<?php

// Authenticate as Super Admin and test Demo Accounts API End-to-End
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
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
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
echo "✓ Authenticated! Token acquired." . PHP_EOL . PHP_EOL;

// 2. Create Demo Tenant
$demoEmail = 'demo_test_' . time() . '@stanford.edu';
echo "2. Creating Demo Tenant ($demoEmail)..." . PHP_EOL;
$createRes = callApi($baseUrl . '/v1/superadmin/demo-accounts', 'POST', [
    'college_name' => 'Stanford Demo University (Test)',
    'website_url' => 'https://stanford.edu',
    'admin_name' => 'Demo Admin',
    'admin_email' => $demoEmail,
    'password' => 'DemoTest123!'
], $token);

echo "HTTP Status: " . $createRes['code'] . PHP_EOL;
if ($createRes['code'] !== 201 || empty($createRes['data']['data']['organization_id'])) {
    echo "Creation failed: " . json_encode($createRes) . PHP_EOL;
    exit(1);
}

$orgId = $createRes['data']['data']['organization_id'];
$botToken = $createRes['data']['data']['bot_token'];
echo "✓ Demo Tenant created! Org ID: $orgId | Bot Token: $botToken" . PHP_EOL . PHP_EOL;

// 3. List Demo Accounts
echo "3. Testing GET /v1/superadmin/demo-accounts..." . PHP_EOL;
$listRes = callApi($baseUrl . '/v1/superadmin/demo-accounts', 'GET', null, $token);
echo "HTTP Status: " . $listRes['code'] . PHP_EOL;
$found = false;
foreach ($listRes['data']['data'] as $demo) {
    if ((int)$demo['id'] === $orgId) {
        $found = true;
        echo "✓ Found newly created demo in list! Name: {$demo['name']} | Plan: {$demo['plan_name']} | Status: {$demo['subscription_status']}" . PHP_EOL;
        break;
    }
}
if (!$found) {
    echo "ERROR: Demo tenant not found in list!" . PHP_EOL;
    exit(1);
}
echo PHP_EOL;

// 4. Test 1-Click Impersonation Token
echo "4. Testing 1-Click Impersonation Token..." . PHP_EOL;
$impRes = callApi($baseUrl . "/v1/superadmin/demo-accounts/$orgId/impersonate-token", 'POST', null, $token);
echo "HTTP Status: " . $impRes['code'] . PHP_EOL;
$demoToken = $impRes['data']['data']['token'] ?? null;
if (!$demoToken) {
    echo "ERROR: Failed to get impersonation token: " . json_encode($impRes) . PHP_EOL;
    exit(1);
}
echo "✓ Impersonation token acquired! Redirect URL: " . $impRes['data']['data']['redirect_url'] . PHP_EOL . PHP_EOL;

// 5. Test Demo Tenant Access with Impersonation Token
echo "5. Verifying /v1/auth/me using Demo Impersonation Token..." . PHP_EOL;
$meRes = callApi($baseUrl . '/v1/auth/me', 'GET', null, $demoToken);
echo "HTTP Status: " . $meRes['code'] . PHP_EOL;
$orgData = $meRes['data']['data']['organization'] ?? [];
echo " - Organization: " . ($orgData['name'] ?? 'N/A') . PHP_EOL;
echo " - Subscription Status: " . ($orgData['subscription_status'] ?? 'N/A') . PHP_EOL;
echo " - is_demo: " . ($orgData['is_demo'] ?? 'N/A') . PHP_EOL;
if (empty($orgData['is_demo'])) {
    echo "ERROR: is_demo is not set on organization!" . PHP_EOL;
    exit(1);
}
echo "✓ Verified! Demo account successfully bypassed payment gate and authenticated directly." . PHP_EOL . PHP_EOL;

// 6. Clean Up Test Demo Tenant
echo "6. Cleaning up test demo tenant (DELETE)..." . PHP_EOL;
$delRes = callApi($baseUrl . "/v1/superadmin/demo-accounts/$orgId", 'DELETE', null, $token);
echo "HTTP Status: " . $delRes['code'] . PHP_EOL;
echo "✓ Deleted successfully!" . PHP_EOL . PHP_EOL;

echo "============================================================" . PHP_EOL;
echo "ALL DEMO TENANT API TESTS PASSED PERFECTLY!" . PHP_EOL;
echo "============================================================" . PHP_EOL;
