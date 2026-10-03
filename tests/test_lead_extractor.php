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

use App\Config\Database;
use App\Services\LeadExtractorService;
use App\Services\ProgramDetector;

echo "=== 1. TESTING CONTACT EXTRACTION LOGIC (EMAIL & PHONE) ===\n";

$testCases = [
    [
        'input' => "Hi, my name is John Doe, email is john.doe@gmail.com and phone is 9876543210. Tell me about B.Tech CSE.",
        'expected_email' => "john.doe@gmail.com",
        'expected_phone' => "9876543210",
    ],
    [
        'input' => "I am interested in MBA programs",
        'expected_email' => null,
        'expected_phone' => null,
    ],
    [
        'input' => "rahul.sharma@yahoo.com",
        'expected_email' => "rahul.sharma@yahoo.com",
        'expected_phone' => null,
    ],
    [
        'input' => "My number is +1 (555) 234-5678",
        'expected_email' => null,
        'expected_phone' => "+15552345678",
    ],
    [
        'input' => "Can someone call me at +91 98765 43210? I'm Rahul Verma.",
        'expected_email' => null,
        'expected_phone' => "+919876543210",
    ],
    [
        'input' => "Name: Priya Patel, Phone: 9876543210, priya@patel.com",
        'expected_email' => "priya@patel.com",
        'expected_phone' => "9876543210",
    ],
    [
        'input' => "This is David Miller. Reach me at contact@test.org or 415-555-0199",
        'expected_email' => "contact@test.org",
        'expected_phone' => "4155550199",
    ],
    [
        'input' => "What are the fees for 2024-2025 batch? My budget is $50000",
        'expected_email' => null,
        'expected_phone' => null,
    ],
    [
        'input' => "I scored 95% in 12th grade.",
        'expected_email' => null,
        'expected_phone' => null,
    ],
    [
        'input' => "Hello! My name is Emily Watson. Please send prospectus to emily@cambridge.edu",
        'expected_email' => "emily@cambridge.edu",
        'expected_phone' => null,
    ],
    [
        'input' => "Alex Johnson, alex.j@gmail.com, +44 7911 123456",
        'expected_email' => "alex.j@gmail.com",
        'expected_phone' => "+447911123456",
    ],
    [
        'input' => "I am a student looking for scholarships",
        'expected_email' => null,
        'expected_phone' => null,
    ],
];

$passedCount = 0;
foreach ($testCases as $i => $tc) {
    $result = LeadExtractorService::extract($tc['input']);
    $emailMatch = ($result['email'] === $tc['expected_email']);
    $phoneMatch = ($result['phone'] === $tc['expected_phone']);

    if ($emailMatch && $phoneMatch) {
        $passedCount++;
        echo "  [PASS] Test #" . ($i + 1) . "\n";
    } else {
        echo "  [FAIL] Test #" . ($i + 1) . " ('" . $tc['input'] . "')\n";
        echo "    Email: expected '" . ($tc['expected_email'] ?? 'NULL') . "', got '" . ($result['email'] ?? 'NULL') . "'\n";
        echo "    Phone: expected '" . ($tc['expected_phone'] ?? 'NULL') . "', got '" . ($result['phone'] ?? 'NULL') . "'\n";
    }
}

echo "Total Extraction Tests Passed: {$passedCount} / " . count($testCases) . "\n\n";

if ($passedCount !== count($testCases)) {
    exit(1);
}

echo "=== 1B. TESTING LLM STUDENT NAME SANITIZATION ===\n";
$nameTests = [
    ['input' => 'John Doe', 'expected' => 'John Doe'],
    ['input' => '  Emily Watson  ', 'expected' => 'Emily Watson'],
    ['input' => 'null', 'expected' => null],
    ['input' => 'none', 'expected' => null],
    ['input' => 'N/A', 'expected' => null],
    ['input' => '', 'expected' => null],
    ['input' => null, 'expected' => null],
    ['input' => 'Dr. Robert Oppenheimer Jr.', 'expected' => 'Dr. Robert Oppenheimer Jr.'],
    ['input' => '12345', 'expected' => null],
];

$namePassed = 0;
foreach ($nameTests as $j => $nt) {
    $sanitized = LeadExtractorService::sanitizeName($nt['input']);
    if ($sanitized === $nt['expected']) {
        $namePassed++;
        echo "  [PASS] Name Test #" . ($j + 1) . " ('" . ($nt['input'] ?? 'NULL') . "' -> '" . ($sanitized ?? 'NULL') . "')\n";
    } else {
        echo "  [FAIL] Name Test #" . ($j + 1) . ": expected '" . ($nt['expected'] ?? 'NULL') . "', got '" . ($sanitized ?? 'NULL') . "'\n";
    }
}

if ($namePassed !== count($nameTests)) {
    exit(1);
}
echo "Total Name Sanitization Tests Passed: {$namePassed} / " . count($nameTests) . "\n\n";

echo "=== 2. TESTING DATABASE SYNC INTEGRATION ===\n";
try {
    $db = Database::getConnection();

    // Get an active chatbot
    $bot = $db->query("SELECT id, organization_id FROM chatbots WHERE is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$bot) {
        echo "No active bot found, skipping DB integration test.\n";
        exit(0);
    }
    $orgId = (int)$bot['organization_id'];
    $botId = (int)$bot['id'];

    // Create a temporary conversation for testing
    $testVisitorId = 'test_visitor_' . bin2hex(random_bytes(4));
    $stmtConv = $db->prepare("
        INSERT INTO conversations (organization_id, chatbot_id, visitor_id, is_test, started_at, last_message_at)
        VALUES (:oid, :bid, :vid, 1, NOW(), NOW())
    ");
    $stmtConv->execute([':oid' => $orgId, ':bid' => $botId, ':vid' => $testVisitorId]);
    $testConvId = (int)$db->lastInsertId();

    echo "Created test conversation #{$testConvId}\n";

    // Test turn 1: User introduces name (via LLM) and email (via extractor)
    $msg1 = "Hello! My name is Arthur Pendragon and email is arthur.p@camelot.edu";
    $extracted1 = LeadExtractorService::extract($msg1);
    $llmName = LeadExtractorService::sanitizeName("Arthur Pendragon");
    $extracted1['name'] = $llmName;

    $leadId1 = LeadExtractorService::syncConversationalLead($db, $orgId, $botId, $testConvId, $extracted1, null, null, null, $testVisitorId);
    echo "Turn 1 Sync: Lead ID = {$leadId1}\n";

    // Verify lead row
    $stmtCheck1 = $db->prepare("SELECT * FROM leads WHERE id = :id");
    $stmtCheck1->execute([':id' => $leadId1]);
    $leadRow1 = $stmtCheck1->fetch(PDO::FETCH_ASSOC);

    assert($leadRow1['name'] === 'Arthur Pendragon', "Expected name Arthur Pendragon, got: " . $leadRow1['name']);
    assert($leadRow1['email'] === 'arthur.p@camelot.edu', "Expected email arthur.p@camelot.edu, got: " . $leadRow1['email']);
    assert($leadRow1['lead_type'] === 'chat_capture', "Expected lead_type chat_capture, got: " . $leadRow1['lead_type']);
    echo "  [PASS] Lead row created with Name and Email\n";

    // Test turn 2: User provides phone number
    $msg2 = "You can also reach my mobile at +1 555-789-0123";
    $extracted2 = LeadExtractorService::extract($msg2);
    $leadId2 = LeadExtractorService::syncConversationalLead($db, $orgId, $botId, $testConvId, $extracted2, null, null, null, $testVisitorId);
    assert($leadId1 === $leadId2, "Lead ID must be unified across turns");

    $stmtCheck2 = $db->prepare("SELECT * FROM leads WHERE id = :id");
    $stmtCheck2->execute([':id' => $leadId2]);
    $leadRow2 = $stmtCheck2->fetch(PDO::FETCH_ASSOC);

    assert($leadRow2['phone'] === '+15557890123', "Expected phone +15557890123, got: " . $leadRow2['phone']);
    assert($leadRow2['email'] === 'arthur.p@camelot.edu', "Email must remain preserved");
    echo "  [PASS] Lead row updated with Phone, preserving Name and Email\n";

    // Test turn 3: Program interest detected
    $stmtProg = $db->prepare("SELECT id, course_name FROM programs WHERE organization_id = :oid LIMIT 1");
    $stmtProg->execute([':oid' => $orgId]);
    $progRow = $stmtProg->fetch(PDO::FETCH_ASSOC);
    if ($progRow) {
        $program = ['id' => (int)$progRow['id'], 'course_name' => $progRow['course_name']];
    } else {
        $db->exec("INSERT INTO programs (organization_id, course_name, program_type) VALUES ({$orgId}, 'Test Program B.A.', 'undergraduate')");
        $tmpProgId = (int)$db->lastInsertId();
        $program = ['id' => $tmpProgId, 'course_name' => 'Test Program B.A.'];
    }
    ProgramDetector::syncProgramLead($db, $orgId, $botId, $testConvId, $program);

    $stmtCheck3 = $db->prepare("SELECT * FROM leads WHERE id = :id");
    $stmtCheck3->execute([':id' => $leadId2]);
    $leadRow3 = $stmtCheck3->fetch(PDO::FETCH_ASSOC);

    assert($leadRow3['program_interest'] === $program['course_name'], "Program interest must be set on existing lead");
    assert($leadRow3['email'] === 'arthur.p@camelot.edu', "Email must remain preserved after program detection");
    assert($leadRow3['name'] === 'Arthur Pendragon', "Name must remain preserved after program detection");
    echo "  [PASS] Program interest linked to lead row without data loss\n";

    // Clean up test records
    $db->exec("DELETE FROM leads WHERE conversation_id = {$testConvId}");
    $db->exec("DELETE FROM conversations WHERE id = {$testConvId}");
    if (isset($tmpProgId)) {
        $db->exec("DELETE FROM programs WHERE id = {$tmpProgId}");
    }
    echo "Cleaned up test conversation and lead records.\n";
    echo "=== ALL INTEGRATION TESTS PASSED SUCCESSFULLY! ===\n";

} catch (Throwable $t) {
    echo "Integration test error: " . $t->getMessage() . "\n" . $t->getTraceAsString() . "\n";
    exit(1);
}
