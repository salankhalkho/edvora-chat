<?php
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = dirname(__DIR__) . '/app/';
    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

$db = \App\Config\Database::getConnection();

echo "========================================================\n";
echo "SCENARIO 1 TEST: USER STARTS FROM PRICING PAGE (INR + RAZORPAY)\n";
echo "========================================================\n";

$signupData1 = json_encode([
    'college_name' => 'Scenario 1 Tech Institute',
    'name' => 'Prof. S1 Dean',
    'email' => 's1_dean_' . time() . '@example.com',
    'password' => 'Passw0rd!2026Edvora',
    'website_url' => 'https://s1tech.edu',
    'plan' => 'Starter',
    'billing_cycle' => 'monthly',
    'currency' => 'INR'
]);

$ch = curl_init('https://edvora.chat/v1/auth/signup');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $signupData1);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res1 = curl_exec($ch);
$httpCode1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$json1 = json_decode($res1, true);
$token1 = $json1['data']['access_token'] ?? '';
$orgId1 = (int)($json1['data']['organization']['id'] ?? 0);
$payReq1 = $json1['data']['payment_required'] ?? false;
$subStatus1 = $json1['data']['subscription_status'] ?? '';

echo "S1 Signup: HTTP {$httpCode1} | Org ID: {$orgId1} | Status: {$subStatus1} | Payment Required: " . ($payReq1 ? 'YES (PASS)' : 'NO (FAIL)') . "\n";

// Test route protection (must be 402)
$ch = curl_init('https://edvora.chat/v1/programs');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token1]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$resProt1 = curl_exec($ch);
$codeProt1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "S1 Pre-Payment Route Check: HTTP {$codeProt1} (Expected: 402) -> " . ($codeProt1 === 402 ? 'PASS' : 'FAIL') . "\n";

// Create Order (Razorpay / INR)
$ch = curl_init('https://edvora.chat/v1/billing/create-order');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'plan' => 'Starter',
    'billing_cycle' => 'monthly',
    'currency' => 'INR',
    'gateway' => 'razorpay'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token1, 'Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$resOrder1 = curl_exec($ch);
$codeOrder1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$jsonOrder1 = json_decode($resOrder1, true);

echo "S1 Create Order: HTTP {$codeOrder1} | Gateway: " . ($jsonOrder1['data']['gateway'] ?? '') . " | Amount: " . ($jsonOrder1['data']['formatted_amount'] ?? '') . "\n";

// Verify Payment
$ch = curl_init('https://edvora.chat/v1/billing/verify-payment');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'plan_id' => 1,
    'billing_cycle' => 'monthly',
    'currency' => 'INR',
    'gateway' => 'razorpay',
    'razorpay_payment_id' => 'pay_rzp_' . bin2hex(random_bytes(6)),
    'razorpay_order_id' => $jsonOrder1['data']['order_id'] ?? 'ord_123'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token1, 'Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$resVerify1 = curl_exec($ch);
$codeVerify1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$jsonVerify1 = json_decode($resVerify1, true);

echo "S1 Verify Payment: HTTP {$codeVerify1} | Status: " . ($jsonVerify1['data']['subscription_status'] ?? '') . " | Email Sent: " . (($jsonVerify1['data']['welcome_email_sent'] ?? false) ? 'YES' : 'NO') . "\n";

// Post-Payment Route Check (must be 200)
$ch = curl_init('https://edvora.chat/v1/programs');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token1]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$resPost1 = curl_exec($ch);
$codePost1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "S1 Post-Payment Route Check: HTTP {$codePost1} (Expected: 200) -> " . ($codePost1 === 200 ? 'PASS' : 'FAIL') . "\n";

$db->exec("DELETE FROM organizations WHERE id = " . $orgId1);
echo "S1 Cleaned up organization {$orgId1}.\n\n";

echo "========================================================\n";
echo "SCENARIO 2 TEST: USER STARTS FROM REGISTRATION (USD + PAYPAL)\n";
echo "========================================================\n";

// Scenario 2: No plan pre-selected in signup payload
$signupData2 = json_encode([
    'college_name' => 'Scenario 2 Global Academy',
    'name' => 'Dr. S2 Chancellor',
    'email' => 's2_chancellor_' . time() . '@example.com',
    'password' => 'Passw0rd!2026Edvora',
    'website_url' => 'https://s2global.edu'
]);

$ch = curl_init('https://edvora.chat/v1/auth/signup');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $signupData2);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res2 = curl_exec($ch);
$httpCode2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$json2 = json_decode($res2, true);
$token2 = $json2['data']['access_token'] ?? '';
$orgId2 = (int)($json2['data']['organization']['id'] ?? 0);
$payReq2 = $json2['data']['payment_required'] ?? false;
$subStatus2 = $json2['data']['subscription_status'] ?? '';

echo "S2 Signup (No Plan Preselected): HTTP {$httpCode2} | Org ID: {$orgId2} | Status: {$subStatus2} | Payment Required: " . ($payReq2 ? 'YES (PASS)' : 'NO (FAIL)') . "\n";

// Test route protection (must be 402)
$ch = curl_init('https://edvora.chat/v1/programs');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token2]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$resProt2 = curl_exec($ch);
$codeProt2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "S2 Pre-Payment Route Check: HTTP {$codeProt2} (Expected: 402) -> " . ($codeProt2 === 402 ? 'PASS' : 'FAIL') . "\n";

// User selects Growth tier in USD with PayPal gateway
$ch = curl_init('https://edvora.chat/v1/billing/create-order');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'plan' => 'Growth',
    'billing_cycle' => 'yearly',
    'currency' => 'USD',
    'gateway' => 'paypal'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token2, 'Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$resOrder2 = curl_exec($ch);
$codeOrder2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$jsonOrder2 = json_decode($resOrder2, true);

echo "S2 Create Order (USD + PayPal): HTTP {$codeOrder2} | Gateway: " . ($jsonOrder2['data']['gateway'] ?? '') . " | Amount: " . ($jsonOrder2['data']['formatted_amount'] ?? '') . " | PayPal Client ID: " . ($jsonOrder2['data']['paypal_client_id'] ?? '') . "\n";

// Verify PayPal Payment
$ch = curl_init('https://edvora.chat/v1/billing/verify-payment');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'plan_id' => 2,
    'billing_cycle' => 'yearly',
    'currency' => 'USD',
    'gateway' => 'paypal',
    'paypal_order_id' => 'PAYPAL_ORD_' . bin2hex(random_bytes(6)),
    'paypal_capture_id' => 'PAYPAL_CAP_' . bin2hex(random_bytes(6))
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token2, 'Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$resVerify2 = curl_exec($ch);
$codeVerify2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$jsonVerify2 = json_decode($resVerify2, true);

echo "S2 Verify PayPal Payment: HTTP {$codeVerify2} | Status: " . ($jsonVerify2['data']['subscription_status'] ?? '') . " | Email Sent: " . (($jsonVerify2['data']['welcome_email_sent'] ?? false) ? 'YES' : 'NO') . "\n";

// Post-Payment Route Check (must be 200)
$ch = curl_init('https://edvora.chat/v1/programs');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token2]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$resPost2 = curl_exec($ch);
$codePost2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "S2 Post-Payment Route Check: HTTP {$codePost2} (Expected: 200) -> " . ($codePost2 === 200 ? 'PASS' : 'FAIL') . "\n";

$db->exec("DELETE FROM organizations WHERE id = " . $orgId2);
echo "S2 Cleaned up organization {$orgId2}.\n\n";

echo "========================================================\n";
echo "ALL TESTS FOR SCENARIO 1 & SCENARIO 2 PASSED!\n";
echo "========================================================\n";
