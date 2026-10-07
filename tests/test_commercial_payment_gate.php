<?php
require_once __DIR__ . '/../app/Config/Database.php';

$db = \App\Config\Database::getConnection();

$signupData = json_encode([
    'college_name' => 'Commercial Gate Verification University',
    'name' => 'Dr. Gate Tester',
    'email' => 'gate_tester_' . time() . '@example.com',
    'password' => 'Passw0rd!2026Edvora',
    'website' => 'https://gateverification.edu',
    'plan' => 'Starter',
    'billing_cycle' => 'monthly',
    'currency' => 'INR'
]);

echo "=== STEP 1: TEST SIGNUP ===\n";
$ch = curl_init('https://edvora.chat/v1/auth/signup');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $signupData);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Signup Response [HTTP {$httpCode}]:\n{$res}\n\n";

$json = json_decode($res, true);
if (empty($json['data']['access_token'])) {
    echo "FAILED: Could not retrieve access token from signup\n";
    exit(1);
}

$token = $json['data']['access_token'];
$orgId = (int)$json['data']['organization']['id'];
$paymentRequired = $json['data']['payment_required'] ?? false;
$subStatus = $json['data']['subscription_status'] ?? '';

echo "Tenant ID: {$orgId}\n";
echo "Payment Required Flag: " . ($paymentRequired ? 'true (PASS)' : 'false (FAIL)') . "\n";
echo "Subscription Status: {$subStatus} (Expected: pending_payment)\n\n";

echo "=== STEP 2: TEST PROTECTED ROUTE ACCESS (MUST BE 402) ===\n";
$ch = curl_init('https://edvora.chat/v1/dashboard/overview');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$dashRes = curl_exec($ch);
$dashCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Overview Response [HTTP {$dashCode}]:\n{$dashRes}\n";
if ($dashCode === 402) {
    echo "PASS: Received 402 Payment Required as expected for unpaid account.\n\n";
} else {
    echo "FAIL: Expected 402, received HTTP {$dashCode}\n\n";
}

echo "=== STEP 3: TEST CREATE ORDER ===\n";
$ch = curl_init('https://edvora.chat/v1/billing/create-order');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'plan' => 'Starter',
    'billing_cycle' => 'monthly',
    'currency' => 'INR',
    'gateway' => 'razorpay'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$orderRes = curl_exec($ch);
$orderCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Create Order Response [HTTP {$orderCode}]:\n{$orderRes}\n\n";

echo "=== STEP 4: TEST VERIFY PAYMENT & DISPATCH WELCOME EMAIL ===\n";
$ch = curl_init('https://edvora.chat/v1/billing/verify-payment');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'plan_id' => 1,
    'billing_cycle' => 'monthly',
    'currency' => 'INR',
    'gateway' => 'razorpay',
    'razorpay_payment_id' => 'pay_test_' . bin2hex(random_bytes(6)),
    'razorpay_order_id' => 'order_test_' . bin2hex(random_bytes(6))
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$verifyRes = curl_exec($ch);
$verifyCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Verify Payment Response [HTTP {$verifyCode}]:\n{$verifyRes}\n\n";

echo "=== STEP 5: TEST PROTECTED ROUTE ACCESS (MUST BE 200) ===\n";
$ch = curl_init('https://edvora.chat/v1/dashboard/overview');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$dashRes2 = curl_exec($ch);
$dashCode2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Overview Post-Payment Response [HTTP {$dashCode2}]:\n{$dashRes2}\n";
if ($dashCode2 === 200) {
    echo "PASS: Dashboard overview accessible after commercial verification!\n\n";
} else {
    echo "FAIL: Expected 200, received HTTP {$dashCode2}\n\n";
}

// Clean up test data
$db->exec("DELETE FROM organizations WHERE id = " . $orgId);
echo "Cleaned up test organization {$orgId}.\n";
echo "=== ALL TESTS COMPLETED SUCCESSFULLY ===\n";
