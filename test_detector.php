<?php
require '/var/www/edvora.chat/app/Config/Database.php';
require '/var/www/edvora.chat/app/Config/Env.php';
require '/var/www/edvora.chat/app/Services/ProgramDetector.php';
\App\Config\Env::load();

$db = \App\Config\Database::getConnection();
$orgId = 52;

$tests = [
    'do you offer MBA',
    'do you offer Executive MBA',
    'tell me about computer science',
    'what is the tuition for Manderson MBA'
];

echo "=== VERIFYING PROGRAM DETECTOR LOGIC ===\n";
foreach ($tests as $q) {
    $res = \App\Services\ProgramDetector::detect($db, $orgId, $q);
    echo "Query: '{$q}'\n";
    if ($res) {
        echo "  -> MATCHED: ID {$res['id']} - {$res['course_name']}\n";
    } else {
        echo "  -> NO PREMATURE LOCK (Returned null -> Multi/Broad match)\n";
    }
}
