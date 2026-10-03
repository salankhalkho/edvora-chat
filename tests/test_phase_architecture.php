<?php

require_once __DIR__ . '/../app/Config/Database.php';
require_once __DIR__ . '/../app/Services/PromptBuilder.php';

use App\Config\Database;
use App\Services\PromptBuilder;

echo "=== PHASE DETERMINATION TESTS ===" . PHP_EOL;

// 1. Phase 1 (No program)
$p1 = PromptBuilder::determinePhase('Hello, what can I study here?', false, null);
echo "1. Query without program: {$p1} (Expected: master_prompt_phase_1)" . PHP_EOL;

// 2. Phase 2 (Program known)
$prog = ['id' => 1, 'course_name' => 'Executive MBA'];
$p2 = PromptBuilder::determinePhase('Tell me about the curriculum', false, $prog);
echo "2. Query with program: {$p2} (Expected: master_prompt_phase_2)" . PHP_EOL;

// 3. Scholarship Phase (Fee query with program)
$pScholarship = PromptBuilder::determinePhase('How much is the tuition fee?', false, $prog);
echo "3. Fee query with program: {$pScholarship} (Expected: master_prompt_scholarship)" . PHP_EOL;

// 4. Phase 3 (Lead captured)
$p3 = PromptBuilder::determinePhase('What are the deadlines?', true, $prog);
echo "4. Lead captured query: {$p3} (Expected: master_prompt_phase_3)" . PHP_EOL;

echo PHP_EOL . "=== PHASE 2 ALTERNATE CADENCE ROTATION TESTS ===" . PHP_EOL;

// Turn 1
$history1 = [];
$t1 = PromptBuilder::computePhase2Offer($history1, [], 'Tell me about Executive MBA');
echo "Turn 1 (First inquiry): offer_turn={$t1['offer_turn']}, assigned={$t1['assigned_offer']} (Expected: Yes, brochure)" . PHP_EOL;

// Turn 2: User asks follow-up question
$history2 = [
    ['role' => 'user', 'content' => 'Tell me about Executive MBA'],
    ['role' => 'assistant', 'content' => 'Executive MBA is great. Would you like me to share our Executive MBA brochure with you?']
];
$t2 = PromptBuilder::computePhase2Offer($history2, ['brochure'], 'What is the schedule?');
echo "Turn 2 (Pure value turn): offer_turn={$t2['offer_turn']}, assigned={$t2['assigned_offer']} (Expected: No, none)" . PHP_EOL;

// Turn 3: Alternate offer turn
$history3 = array_merge($history2, [
    ['role' => 'user', 'content' => 'What is the schedule?'],
    ['role' => 'assistant', 'content' => 'Classes are held on alternate weekends.']
]);
$t3 = PromptBuilder::computePhase2Offer($history3, ['brochure'], 'Where are classes held?');
echo "Turn 3 (Rotate to campus_tour): offer_turn={$t3['offer_turn']}, assigned={$t3['assigned_offer']} (Expected: Yes, campus_tour)" . PHP_EOL;

$history4 = array_merge($history3, [
    ['role' => 'user', 'content' => 'Where are classes held?'],
    ['role' => 'assistant', 'content' => 'Classes are held at our downtown facility. Would you like to schedule a campus tour to explore our facilities?']
]);
$t4 = PromptBuilder::computePhase2Offer($history4, ['brochure', 'campus_tour'], 'Yes, I would love to visit');
echo "Turn 4 (Affirmation): is_accepting=" . ($t4['is_accepting'] ? 'true' : 'false') . " (Expected: true)" . PHP_EOL;
