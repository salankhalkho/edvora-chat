<?php

// Test script for live chat phased pipeline
$url = 'https://edvora.chat/v1/chat/message';
$botToken = 'b1cf1f32824104b382e8a2f89444e33f'; // Org 52 bot
$visitorId = 'test_phase_visitor_' . time();

function sendChatMessage($url, $botToken, $visitorId, $message) {
    $payload = json_encode([
        'bot_token'  => $botToken,
        'visitor_id' => $visitorId,
        'message'    => $message,
        'is_test'    => 1
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'Origin: https://edvora.chat'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HEADER, true);

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);

    return ['headers' => $headers, 'body' => json_decode($body, true), 'raw_body' => $body];
}

echo "=== TEST 1: Phase 1 (Course Catalog Request) ===" . PHP_EOL;
$res1 = sendChatMessage($url, $botToken, $visitorId, "Hi, what courses do you offer?");
echo "HTTP Status: " . ($res1['body']['status'] ?? 'err') . PHP_EOL;
echo "Active Phase: " . ($res1['body']['data']['active_phase'] ?? 'NOT RETURNED') . PHP_EOL;
echo "Active Phase Key: " . ($res1['body']['data']['active_phase_key'] ?? 'NOT RETURNED') . PHP_EOL;
echo "Intent: " . ($res1['body']['data']['intent_tier'] ?? 'none') . PHP_EOL;
echo "Program Catalog Present: " . (!empty($res1['body']['data']['program_catalog']) ? 'YES' : 'NO') . PHP_EOL;
echo "Bubble 1: " . ($res1['body']['data']['response'] ?? 'null') . PHP_EOL;
echo "Bubble 2 (Follow up): " . ($res1['body']['data']['follow_up_message'] ?? 'null') . PHP_EOL;

echo PHP_EOL . "=== TEST 2: Phase 2 (Program Specific Inquiry - Executive MBA) ===" . PHP_EOL;
$res2 = sendChatMessage($url, $botToken, $visitorId, "Tell me about the Executive MBA program");
echo "Active Phase: " . ($res2['body']['data']['active_phase'] ?? 'NOT RETURNED') . PHP_EOL;
echo "Active Program: " . ($res2['body']['data']['active_program'] ?? 'null') . PHP_EOL;
echo "Bubble 1: " . substr($res2['body']['data']['response'] ?? '', 0, 100) . "..." . PHP_EOL;
echo "Bubble 2 (Rotating Offer): " . ($res2['body']['data']['follow_up_message'] ?? 'null') . PHP_EOL;

echo PHP_EOL . "=== TEST 3: Scholarship Phase (Fee / Aid Inquiry) ===" . PHP_EOL;
$res3 = sendChatMessage($url, $botToken, $visitorId, "What is the tuition fee for the Executive MBA?");
echo "Active Phase: " . ($res3['body']['data']['active_phase'] ?? 'NOT RETURNED') . PHP_EOL;
echo "Bubble 1: " . substr($res3['body']['data']['response'] ?? '', 0, 100) . "..." . PHP_EOL;
echo "Bubble 2 (Scholarship Eval Offer): " . ($res3['body']['data']['follow_up_message'] ?? 'null') . PHP_EOL;

echo PHP_EOL . "=== TEST 4: Accepting Scholarship Offer ===" . PHP_EOL;
$res4 = sendChatMessage($url, $botToken, $visitorId, "Sure, evaluate my eligibility");
echo "Active Phase: " . ($res4['body']['data']['active_phase'] ?? 'NOT RETURNED') . PHP_EOL;
echo "Intent: " . ($res4['body']['data']['intent_tier'] ?? 'none') . PHP_EOL;
echo "Lead Capture Trigger Type: " . ($res4['body']['data']['lead_capture_trigger']['type'] ?? 'none') . PHP_EOL;
echo "Bubble 1: " . ($res4['body']['data']['response'] ?? 'null') . PHP_EOL;
echo "Bubble 2: " . ($res4['body']['data']['follow_up_message'] ?? 'null') . PHP_EOL;

