<?php

// Autoload class helper
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/app/';
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

echo "============================================================\n";
echo "SEEDING 25 REALISTIC CONVERSATIONS & BOOKINGS (ORG ID 52)\n";
echo "The University of Alabama — Chatbot Testing & Training Data\n";
echo "============================================================\n\n";

try {
    $db = Database::getConnection();

    // Verify Organization 52
    $stmtOrg = $db->prepare("SELECT id, name, slug FROM organizations WHERE id = 52");
    $stmtOrg->execute();
    $org = $stmtOrg->fetch(PDO::FETCH_ASSOC);
    if (!$org) {
        die("❌ Organization with ID 52 not found.\n");
    }
    echo "✓ Found Organization: {$org['name']} (ID: {$org['id']})\n";

    // Verify Chatbot for Org 52
    $stmtBot = $db->prepare("SELECT id, name, bot_token FROM chatbots WHERE organization_id = 52 LIMIT 1");
    $stmtBot->execute();
    $bot = $stmtBot->fetch(PDO::FETCH_ASSOC);
    if (!$bot) {
        die("❌ Chatbot for Organization 52 not found.\n");
    }
    $botId = (int)$bot['id'];
    echo "✓ Found Chatbot: {$bot['name']} (ID: {$botId})\n";

    // Verify Counselor User (Dr. Dhingra, id 73)
    $stmtUser = $db->prepare("SELECT id, name, email FROM users WHERE organization_id = 52 AND role IN ('owner', 'admin', 'counselor') LIMIT 1");
    $stmtUser->execute();
    $counselorUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
    $counselorId = $counselorUser ? (int)$counselorUser['id'] : null;
    echo "✓ Assigned Counselor: " . ($counselorUser ? "{$counselorUser['name']} (ID: {$counselorId})" : "Unassigned") . "\n";

    // Active Tour Slot (ID 4)
    $stmtSlot = $db->prepare("SELECT id, title, tour_date FROM campus_tour_slots WHERE organization_id = 52 AND status = 'active' LIMIT 1");
    $stmtSlot->execute();
    $slotRow = $stmtSlot->fetch(PDO::FETCH_ASSOC);
    $activeSlotId = $slotRow ? (int)$slotRow['id'] : null;

    $scenarios = [
        // -------------------------------------------------------------
        // 13 COUNSELOR CALLBACK SCENARIOS
        // -------------------------------------------------------------
        [
            'type' => 'callback',
            'name' => 'Marcus Vance',
            'email' => 'marcus.vance@gmail.com',
            'phone' => '+1 (205) 555-0142',
            'program_name' => 'Accelerated Master’s Program (AMP)',
            'program_id' => 138,
            'topic' => 'AMP dual-credit eligibility criteria & minimum undergraduate GPA requirements',
            'slot' => 'Morning (9 AM - 12 PM)',
            'status' => 'pending',
            'counselor_notes' => null,
            'call_attempts' => 0,
            'created_at' => '2026-09-28 09:15:00',
            'turns' => [
                [
                    'u' => "Hi, I'm currently a junior majoring in mechanical engineering at Alabama. Can I start taking graduate classes through the Accelerated Master's Program (AMP) next semester?",
                    'a' => "Hello Marcus! Yes, absolutely. Under The University of Alabama's Accelerated Master’s Program (AMP), qualified undergraduate students can take up to 15 graduate credit hours that count toward both their bachelor’s and master’s degrees simultaneously.\n\nTo be eligible, you need at least 90 earned undergraduate credit hours and a cumulative GPA of 3.3 or higher. Would you like to connect directly with an admissions advisor to evaluate your course credits?"
                ],
                [
                    'u' => "Yes, that would be really great! My GPA is 3.52 and I want to confirm which graduate credits will count. Can someone call me?",
                    'a' => "That's wonderful news! With a 3.52 GPA, you easily satisfy the GPA threshold. I'd be happy to schedule a callback for you. Could you share your preferred contact number, email, and the best time slot for a counselor to reach you?"
                ],
                [
                    'u' => "Sure! My name is Marcus Vance. Phone is +1 (205) 555-0142, email is marcus.vance@gmail.com. A morning call between 9 AM and 12 PM works best for me.",
                    'a' => "Perfect, Marcus! Your priority counselor callback is officially confirmed:\n\n• Student: Marcus Vance\n• Phone: +1 (205) 555-0142\n• Preferred Window: Morning (9 AM - 12 PM)\n• Topic: AMP dual-credit eligibility & course transfer\n\nOur graduate admissions counseling team has been notified and will call you during your requested window. Roll Tide!"
                ]
            ]
        ],
        [
            'type' => 'callback',
            'name' => 'Elena Rostova',
            'email' => 'elena.rostova@outlook.com',
            'phone' => '+1 (334) 555-0189',
            'program_name' => 'Interdisciplinary Studies MA and MS',
            'program_id' => 139,
            'topic' => 'Custom curriculum proposal combining digital media strategy and cognitive psychology',
            'slot' => 'Afternoon (12 PM - 3 PM)',
            'status' => 'pending',
            'counselor_notes' => null,
            'call_attempts' => 0,
            'created_at' => '2026-09-28 11:30:00',
            'turns' => [
                [
                    'u' => "Does the Interdisciplinary Studies MA program allow me to design my own curriculum combining digital media and behavioral psychology?",
                    'a' => "Hello Elena! Yes, that is exactly what the Interdisciplinary Studies MA/MS at The University of Alabama is built for. The program provides the flexibility to craft a personalized degree plan drawing from two or more academic departments across UA’s 12 colleges.\n\nYou will assemble a faculty committee of three graduate professors who will help approve your tailored plan of study. Would you like to speak with our graduate program coordinator to review how to formulate your proposal?"
                ],
                [
                    'u' => "Yes please, I'd like to understand the proposal requirements and committee formation process before I apply.",
                    'a' => "We would love to help you get that underway! Please provide your phone number, email address, and a convenient time for an admissions counselor to call you."
                ],
                [
                    'u' => "My name is Elena Rostova. Phone: +1 (334) 555-0189, email: elena.rostova@outlook.com. An afternoon slot between 12 PM and 3 PM is ideal.",
                    'a' => "Thank you, Elena! Your callback request has been scheduled:\n\n• Student: Elena Rostova\n• Phone: +1 (334) 555-0189\n• Preferred Window: Afternoon (12 PM - 3 PM)\n• Subject: Interdisciplinary Studies Curriculum & Committee Approval\n\nAn academic counselor will reach out to discuss your specific focus areas and guide you through the proposal steps."
                ]
            ]
        ],
        [
            'type' => 'callback',
            'name' => 'Priya Sharma',
            'email' => 'priya.sharma99@gmail.com',
            'phone' => '+91 98201 44521',
            'program_name' => 'Materials Science PhD',
            'program_id' => null,
            'topic' => 'Doctoral research assistantships, full tuition waivers, and nanomaterials lab placement',
            'slot' => 'Evening (3 PM - 6 PM)',
            'status' => 'pending',
            'counselor_notes' => null,
            'call_attempts' => 0,
            'created_at' => '2026-09-28 14:10:00',
            'turns' => [
                [
                    'u' => "I am an international student applying for the Materials Science PhD program. Are doctoral students provided with graduate research assistantships and tuition waivers?",
                    'a' => "Hello Priya! Yes, at The University of Alabama, admitted PhD students in Materials Science and engineering disciplines are typically awarded competitive Graduate Research Assistantships (GRA) or Graduate Teaching Assistantships (GTA).\n\nThese packages provide a full tuition waiver, comprehensive health insurance subsidy, and a competitive living stipend. Would you like an admissions advisor to contact you regarding department funding deadlines and research faculty matches?"
                ],
                [
                    'u' => "Yes, I would greatly appreciate a phone call to discuss lab openings in nanomaterials synthesis and the funding package.",
                    'a' => "Certainly! Please provide your full name, phone number including country code, email, and preferred call window."
                ],
                [
                    'u' => "Priya Sharma, +91 98201 44521, priya.sharma99@gmail.com. Please call during the evening slot (3 PM - 6 PM US Central).",
                    'a' => "Registered successfully, Priya!\n\n• Student: Priya Sharma\n• Contact: +91 98201 44521 | priya.sharma99@gmail.com\n• Preferred Slot: Evening (3 PM - 6 PM)\n• Focus: Materials Science PhD Assistantships & Lab Placement\n\nOur international graduate counselor will contact you at your designated time."
                ]
            ]
        ],
        [
            'type' => 'callback',
            'name' => 'David K. Miller',
            'email' => 'dmiller.bama@yahoo.com',
            'phone' => '+1 (256) 555-0123',
            'program_name' => 'Accelerated Master’s Program (AMP)',
            'program_id' => 138,
            'topic' => 'GRE waiver eligibility for current UA undergraduates and Spring semester admission timeline',
            'slot' => 'Immediate (ASAP)',
            'status' => 'pending',
            'counselor_notes' => null,
            'call_attempts' => 0,
            'created_at' => '2026-09-28 16:45:00',
            'turns' => [
                [
                    'u' => "Is the GRE test required for applying to the Accelerated Master's Program if I am already enrolled as a UA student?",
                    'a' => "Hello David! For current University of Alabama undergraduates applying to the AMP track, the GRE requirement is generally waived by most participating academic departments, provided you meet the required minimum GPA (3.3+).\n\nWould you like an immediate callback from our graduate admissions office to verify the exact waiver criteria for your department?"
                ],
                [
                    'u' => "Yes, can you have someone call me as soon as possible? I need to finalize my class schedule for the upcoming semester.",
                    'a' => "Of course! Please provide your name, phone number, and email address."
                ],
                [
                    'u' => "David K. Miller, +1 (256) 555-0123, dmiller.bama@yahoo.com. ASAP please!",
                    'a' => "Priority callback queued, David!\n\n• Student: David K. Miller\n• Phone: +1 (256) 555-0123\n• Preference: Immediate (ASAP)\n• Topic: AMP GRE Waiver & Spring Enrollment Deadlines\n\nAn admissions counselor on duty will contact you directly."
                ]
            ]
        ],
        [
            'type' => 'callback',
            'name' => 'Aisha Al-Mansoor',
            'email' => 'aisha.mansoor@uaeu.ac.ae',
            'phone' => '+971 50 123 4567',
            'program_name' => 'Interdisciplinary Studies PhD (Advanced Drug Delivery)',
            'program_id' => null,
            'topic' => 'Alabama Life Research Institute lab collaborations and bioengineering prerequisites',
            'slot' => 'Afternoon (12 PM - 3 PM)',
            'status' => 'scheduled',
            'counselor_notes' => 'Call scheduled with Dr. Dhingra. Research focus in biomaterials and targeted nano-carriers.',
            'call_attempts' => 0,
            'created_at' => '2026-09-27 10:20:00',
            'turns' => [
                [
                    'u' => "I am inquiring about the PhD in Interdisciplinary Studies with concentration in Advanced Drug Delivery. How are faculty co-advisors selected?",
                    'a' => "Greetings Aisha! In the Advanced Drug Delivery doctoral concentration at UA, students conduct interdisciplinary research collaborating across the Department of Chemistry and Biochemistry, Chemical & Biological Engineering, and the Alabama Life Research Institute (ALRI).\n\nStudents select a primary research advisor in their home department and a co-advisor from a partnering discipline. Would you like a consultation call to discuss lab specializations?"
                ],
                [
                    'u' => "Yes, a scheduled callback would be excellent so I can prepare questions about faculty research grants.",
                    'a' => "Certainly! Please provide your contact phone, email, and preferred time window."
                ],
                [
                    'u' => "Aisha Al-Mansoor, +971 50 123 4567, aisha.mansoor@uaeu.ac.ae, Afternoon (12 PM - 3 PM).",
                    'a' => "Confirmed, Aisha! Your consultation has been scheduled with our graduate research coordinator for Afternoon (12 PM - 3 PM). We look forward to speaking with you!"
                ]
            ]
        ],
        [
            'type' => 'callback',
            'name' => 'Cameron Bradley',
            'email' => 'cbradley22@gmail.com',
            'phone' => '+1 (205) 555-0198',
            'program_name' => 'Accelerated Master’s Program (AMP)',
            'program_id' => 138,
            'topic' => 'Tuition billing transition from undergraduate to graduate rates under AMP',
            'slot' => 'Morning (9 AM - 12 PM)',
            'status' => 'scheduled',
            'counselor_notes' => 'Scheduled consultation with student accounts advisor regarding out-of-state rate parity.',
            'call_attempts' => 0,
            'created_at' => '2026-09-27 13:00:00',
            'turns' => [
                [
                    'u' => "How is tuition calculated when an out-of-state student takes graduate level courses through the Accelerated Master's Program?",
                    'a' => "Hello Cameron! One of the biggest financial benefits of UA's AMP is that while you are classified as an undergraduate student, your graduate courses (up to 15 credit hours) are billed at the standard undergraduate tuition rate! You only transition to graduate tuition rates once your bachelor's degree is formally conferred.\n\nWould you like a counselor to call you with a detailed cost sheet comparison?"
                ],
                [
                    'u' => "Yes, that sounds like a huge savings! Please have someone call me to explain the exact credit caps and billing schedule.",
                    'a' => "I'd be glad to arrange that! What is your full name, phone number, email, and best time to speak?"
                ],
                [
                    'u' => "Cameron Bradley, +1 (205) 555-0198, cbradley22@gmail.com. Morning (9 AM - 12 PM) is best.",
                    'a' => "Booked, Cameron! Our financial counseling team will call you during the morning slot (9 AM - 12 PM) to review your personalized AMP tuition projections."
                ]
            ]
        ],
        [
            'type' => 'callback',
            'name' => 'Sofia Hernandez',
            'email' => 'sofia.hernandez@icloud.com',
            'phone' => '+1 (404) 555-0177',
            'program_name' => 'Interdisciplinary Studies MA and MS',
            'program_id' => 139,
            'topic' => 'Online and hybrid class flexibility for working professionals in Birmingham area',
            'slot' => 'Evening (3 PM - 6 PM)',
            'status' => 'scheduled',
            'counselor_notes' => 'Working professional in healthcare administration. Follow-up call booked.',
            'call_attempts' => 0,
            'created_at' => '2026-09-27 15:40:00',
            'turns' => [
                [
                    'u' => "I work full time in Birmingham and want to pursue the Master's in Interdisciplinary Studies. Can this degree be completed via hybrid or evening classes?",
                    'a' => "Hello Sofia! Yes, The University of Alabama provides flexible learning options for working professionals. Many departments participating in the Interdisciplinary Studies MA/MS offer evening seminars, hybrid models, and online courses through Bama By Distance.\n\nDepending on your chosen concentrations, we can build a schedule tailored to your professional commitments. Would you like an advisor to call you to discuss course formats?"
                ],
                [
                    'u' => "Yes, an evening phone call would be great so I can review course delivery options without missing work.",
                    'a' => "We can certainly accommodate that! Please share your contact details and preferred time."
                ],
                [
                    'u' => "Sofia Hernandez, phone: +1 (404) 555-0177, email: sofia.hernandez@icloud.com. Evening (3 PM - 6 PM).",
                    'a' => "All set, Sofia! We have scheduled your callback for Evening (3 PM - 6 PM). Our continuing education advisor will walk you through the hybrid curriculum options."
                ]
            ]
        ],
        [
            'type' => 'callback',
            'name' => 'Liam O\'Connor',
            'email' => 'liam.oconnor@proton.me',
            'phone' => '+1 (312) 555-0155',
            'program_name' => 'Accelerated Master’s Program (AMP)',
            'program_id' => 138,
            'topic' => 'Transferring AP and community college credits toward the 90 earned undergraduate hours requirement',
            'slot' => 'Afternoon (12 PM - 3 PM)',
            'status' => 'scheduled',
            'counselor_notes' => 'Reviewing AP credit transfer transcript for sophomore standing.',
            'call_attempts' => 0,
            'created_at' => '2026-09-26 10:15:00',
            'turns' => [
                [
                    'u' => "Do college credits earned through AP exams and community college dual enrollment count toward the 90-credit requirement for AMP?",
                    'a' => "Hello Liam! Yes, accepted transfer credits and AP exam credits that have been formally evaluated and posted to your official University of Alabama undergraduate transcript count toward the 90 earned credit hours required to apply for AMP.\n\nWould you like an academic advisor to check your degree audit and confirm your eligible credits?"
                ],
                [
                    'u' => "Yes, please! I have about 24 AP credits and want to verify if I can apply for AMP a semester early.",
                    'a' => "That's an ideal situation for AMP acceleration! Please provide your phone number, email, and preferred call window so an advisor can pull up your academic record."
                ],
                [
                    'u' => "Liam O'Connor, +1 (312) 555-0155, liam.oconnor@proton.me. Afternoon (12 PM - 3 PM) works best.",
                    'a' => "Scheduled! An admissions counselor will call you during the Afternoon (12 PM - 3 PM) window to verify your transfer credits against the AMP eligibility checklist."
                ]
            ]
        ],
        [
            'type' => 'callback',
            'name' => 'Jordan Washington',
            'email' => 'jwashington.ua@gmail.com',
            'phone' => '+1 (205) 555-0164',
            'program_name' => 'Interdisciplinary Studies MA and MS',
            'program_id' => 139,
            'topic' => 'Combining public health analytics with geographic information systems (GIS) for rural epidemiology',
            'slot' => 'Immediate (ASAP)',
            'status' => 'pending',
            'counselor_notes' => null,
            'call_attempts' => 0,
            'created_at' => '2026-09-26 14:00:00',
            'turns' => [
                [
                    'u' => "Can I combine public health analytics from the College of Community Health Sciences with GIS mapping from Geography in an Interdisciplinary MS?",
                    'a' => "Hi Jordan! Yes! Combining spatial GIS analysis with rural epidemiological data is an outstanding interdisciplinary application. UA’s Institute for Rural Health Research frequently partners with geography and computing faculty on these exact initiatives.\n\nWould you like an immediate callback to discuss faculty mentors in spatial health informatics?"
                ],
                [
                    'u' => "Yes please! That is my dream research track. I'd love to speak with someone right now if possible.",
                    'a' => "Wonderful! Please share your contact details and we will route your request directly to our on-duty counselor."
                ],
                [
                    'u' => "Jordan Washington, +1 (205) 555-0164, jwashington.ua@gmail.com. Please call ASAP!",
                    'a' => "Registered for Immediate Callback, Jordan! An admissions counselor has received your high-priority request and will connect with you shortly."
                ]
            ]
        ],
        [
            'type' => 'callback',
            'name' => 'Brandon Hayes',
            'email' => 'bhayes_tn@gmail.com',
            'phone' => '+1 (615) 555-0138',
            'program_name' => 'Accelerated Master’s Program (AMP)',
            'program_id' => 138,
            'topic' => 'Letters of recommendation format and department faculty sponsor requirements for AMP',
            'slot' => 'Morning (9 AM - 12 PM)',
            'status' => 'pending',
            'counselor_notes' => null,
            'call_attempts' => 0,
            'created_at' => '2026-09-26 16:30:00',
            'turns' => [
                [
                    'u' => "How many letters of recommendation do I need for the Accelerated Master's Program, and do they have to come from UA professors?",
                    'a' => "Hello Brandon! Most AMP programs at UA require two to three letters of recommendation. It is strongly recommended that at least two letters come from UA faculty members who have taught you in upper-division coursework in your major department, as they also endorse your readiness for graduate-level rigor.\n\nWould you like our admissions team to call you with specific submission instructions for your department?"
                ],
                [
                    'u' => "Yes, that would be very useful. I want to make sure I approach the right professors with the correct evaluation forms.",
                    'a' => "Understood! Please provide your name, phone number, email, and preferred call window."
                ],
                [
                    'u' => "Brandon Hayes, +1 (615) 555-0138, bhayes_tn@gmail.com. Morning (9 AM - 12 PM).",
                    'a' => "Confirmed, Brandon! Your callback request is logged for Morning (9 AM - 12 PM). We look forward to assisting your AMP application journey."
                ]
            ]
        ],
        [
            'type' => 'callback',
            'name' => 'Mei-Ling Chen',
            'email' => 'meiling.chen@ntu.edu.tw',
            'phone' => '+886 912 345 678',
            'program_name' => 'Materials Science PhD',
            'program_id' => null,
            'topic' => 'English language proficiency waiver policies for applicants with degrees taught in English',
            'slot' => 'Evening (3 PM - 6 PM)',
            'status' => 'pending',
            'counselor_notes' => null,
            'call_attempts' => 0,
            'created_at' => '2026-09-25 11:00:00',
            'turns' => [
                [
                    'u' => "Hello, I graduated from an undergraduate university where the official language of instruction was English. Am I exempt from submitting TOEFL or IELTS for the Materials Science PhD?",
                    'a' => "Hello Mei-Ling! The University of Alabama Graduate School may waive the English language proficiency test if you have earned a bachelor’s or master’s degree from an accredited institution where English is the primary medium of instruction, verified by an official letter from the registrar.\n\nWould you like to connect with our international credentials counselor to review your transcripts?"
                ],
                [
                    'u' => "Yes, please schedule a call so I can verify the exact documentation required before the application deadline.",
                    'a' => "Gladly! Please provide your full name, international phone number, email, and preferred call slot."
                ],
                [
                    'u' => "Mei-Ling Chen, +886 912 345 678, meiling.chen@ntu.edu.tw. Evening (3 PM - 6 PM US Central).",
                    'a' => "Callback registered, Mei-Ling! Our international graduate admissions officer will call you during Evening (3 PM - 6 PM) to assist with your waiver documentation."
                ]
            ]
        ],
        [
            'type' => 'callback',
            'name' => 'Tyler James Scott',
            'email' => 'tjscott_bama@gmail.com',
            'phone' => '+1 (205) 555-0112',
            'program_name' => 'Accelerated Master’s Program (AMP)',
            'program_id' => 138,
            'topic' => 'Pathway into Manderson Graduate School of Business MBA via AMP track',
            'slot' => 'Morning (9 AM - 12 PM)',
            'status' => 'completed',
            'counselor_notes' => 'Spoke with Tyler regarding Manderson MBA AMP prerequisites. Student holds 3.65 GPA in Finance. Application guide emailed.',
            'call_attempts' => 1,
            'created_at' => '2026-09-25 13:45:00',
            'turns' => [
                [
                    'u' => "Can business undergraduates take courses toward the Manderson MBA through the AMP program?",
                    'a' => "Hello Tyler! Yes, the Manderson Graduate School of Business at UA offers specialized accelerated pathways for high-achieving undergraduates. Students can begin MBA foundational and elective coursework during their senior year, shaving up to a year off their graduate business degree.\n\nWould you like a callback from a Manderson MBA admissions advisor?"
                ],
                [
                    'u' => "Yes, I would really like that. A morning call would be great to walk through the prerequisite courses.",
                    'a' => "I would be happy to set that up! What is your full name, phone number, and email?"
                ],
                [
                    'u' => "Tyler James Scott, phone is +1 (205) 555-0112, email is tjscott_bama@gmail.com. Morning (9 AM - 12 PM).",
                    'a' => "Confirmed, Tyler! Your counselor callback is scheduled for Morning (9 AM - 12 PM). We look forward to connecting with you!"
                ]
            ]
        ],
        [
            'type' => 'callback',
            'name' => 'Hannah Reynolds',
            'email' => 'hannah.reynolds@gmail.com',
            'phone' => '+1 (256) 555-0184',
            'program_name' => 'Interdisciplinary Studies MA and MS',
            'program_id' => 139,
            'topic' => 'Stacking graduate certificate credits into a full Interdisciplinary Studies MA degree',
            'slot' => 'Afternoon (12 PM - 3 PM)',
            'status' => 'completed',
            'counselor_notes' => 'Consultation completed. Explained 12-credit certificate transfer policy. Student preparing degree proposal.',
            'call_attempts' => 1,
            'created_at' => '2026-09-25 15:10:00',
            'turns' => [
                [
                    'u' => "I recently completed a graduate certificate in conflict management at UA. Can those 12 credits be applied toward a Master of Arts in Interdisciplinary Studies?",
                    'a' => "Hello Hannah! Yes! Under University of Alabama Graduate School guidelines, up to 12 credit hours earned from an approved graduate certificate can be applied directly toward an Interdisciplinary Studies MA or MS, subject to your faculty committee's approval.\n\nWould you like an advisor to call you to review how your certificate credits map into the degree plan?"
                ],
                [
                    'u' => "Yes, please! That would save me so much time and money. Can an advisor call me in the afternoon?",
                    'a' => "Absolutely! Please provide your name, phone number, and email address."
                ],
                [
                    'u' => "Hannah Reynolds, +1 (256) 555-0184, hannah.reynolds@gmail.com. Afternoon (12 PM - 3 PM).",
                    'a' => "Booked, Hannah! Your callback has been registered for Afternoon (12 PM - 3 PM). Our graduate academic advisor will be in touch to finalize your course transfer plan."
                ]
            ]
        ],

        // -------------------------------------------------------------
        // 12 CAMPUS TOUR BOOKING SCENARIOS
        // -------------------------------------------------------------
        [
            'type' => 'campus_tour',
            'name' => 'Ethan Parker',
            'email' => 'ethan.parker9@gmail.com',
            'phone' => '+1 (205) 555-0211',
            'program_name' => 'Accelerated Master’s Program (AMP)',
            'program_id' => 138,
            'preferred_date' => '2026-10-09',
            'preferred_time' => 'Morning (10:00 AM)',
            'group_size' => 2,
            'notes' => 'Visiting with father; interested in touring Shelby Hall, engineering research complex, and meeting AMP advisor.',
            'status' => 'pending',
            'confirmed_date' => null,
            'counselor_notes' => null,
            'created_at' => '2026-09-28 10:00:00',
            'turns' => [
                [
                    'u' => "Can prospective students tour the engineering research facilities and Shelby Hall to see the AMP labs in person?",
                    'a' => "Hello Ethan! Yes, absolutely! The University of Alabama offers student-led guided walking tours that visit Shelby Hall, the Science and Engineering Complex, modern maker spaces, and student research institutes.\n\nOur next available Guided Campus & Lab Discovery Tour is scheduled for Friday, October 9th at 10:00 AM. Would you like to reserve a visit for yourself and any guests?"
                ],
                [
                    'u' => "Yes! My father and I are planning to drive up to Tuscaloosa that morning. We'd love to attend the 10:00 AM tour on October 9th.",
                    'a' => "That will be an exciting visit! To reserve your passes, please provide your full name, phone number, email, and total group size."
                ],
                [
                    'u' => "Ethan Parker, ethan.parker9@gmail.com, +1 (205) 555-0211. Group size of 2 (myself and my dad).",
                    'a' => "Your campus tour is reserved, Ethan!\n\n• Student: Ethan Parker\n• Tour: Guided Campus & Lab Discovery Tour\n• Date: Friday, October 9, 2026 at 10:00 AM\n• Party Size: 2 Persons\n• Contact: +1 (205) 555-0211 | ethan.parker9@gmail.com\n\nOur campus visit coordinator has received your booking. Parking passes and check-in details for the Campus Welcome Center will be emailed to you. See you on campus!"
                ]
            ]
        ],
        [
            'type' => 'campus_tour',
            'name' => 'Chloe Zimmerman',
            'email' => 'chloe.zimmerman@gmail.com',
            'phone' => '+1 (404) 555-0233',
            'program_name' => 'Interdisciplinary Studies MA and MS',
            'program_id' => 139,
            'preferred_date' => '2026-10-09',
            'preferred_time' => 'Morning (10:00 AM)',
            'group_size' => 1,
            'notes' => 'Interested in seeing Digital Media Center inside Bryant-Denny Stadium and graduate study spaces in Gorgas Library.',
            'status' => 'pending',
            'confirmed_date' => null,
            'counselor_notes' => null,
            'created_at' => '2026-09-28 12:15:00',
            'turns' => [
                [
                    'u' => "I am looking into the Interdisciplinary Studies MA in media communication. Are there tours that showcase the Digital Media Center?",
                    'a' => "Hi Chloe! Yes! Our campus tour introduces you to the state-of-the-art Digital Media Center—a 40,000-square-foot facility housing commercial television, radio, and digital newsrooms—as well as the newly renovated Gorgas Library graduate research floors.\n\nWe have slots open for Friday, October 9th at 10:00 AM. Would you like to book a spot?"
                ],
                [
                    'u' => "Yes please, that sounds fantastic! I will be coming alone for the morning tour on Oct 9.",
                    'a' => "Wonderful! Please share your contact phone number, email address, and name so we can secure your booking."
                ],
                [
                    'u' => "Chloe Zimmerman, +1 (404) 555-0233, chloe.zimmerman@gmail.com. Group size: 1.",
                    'a' => "You're all booked, Chloe!\n\n• Student: Chloe Zimmerman\n• Date: Friday, October 9, 2026 at 10:00 AM\n• Program Focus: Interdisciplinary Studies MA (Media Arts)\n• Group: 1 Attendee\n\nWe look forward to welcoming you to Tuscaloosa!"
                ]
            ]
        ],
        [
            'type' => 'campus_tour',
            'name' => 'Lucas Wright',
            'email' => 'lucas.wright@outlook.com',
            'phone' => '+1 (770) 555-0245',
            'program_name' => 'Materials Science PhD',
            'program_id' => null,
            'preferred_date' => '2026-10-12',
            'preferred_time' => 'Morning (10:00 AM)',
            'group_size' => 1,
            'notes' => 'Prospective PhD student requesting tour of Central Analytical Facility (electron microscopy and XRD).',
            'status' => 'confirmed',
            'confirmed_date' => '2026-10-12',
            'counselor_notes' => 'Confirmed tour and arranged 30-min meeting with Dr. Dhingra in Bevill Building.',
            'call_attempts' => 0,
            'created_at' => '2026-09-27 11:20:00',
            'turns' => [
                [
                    'u' => "I'm applying to the Materials Science PhD program. Can I schedule a visit to inspect the Central Analytical Facility and electron microscopy labs?",
                    'a' => "Hello Lucas! Yes, prospective doctoral candidates are welcome to visit our research facilities. UA’s Central Analytical Facility (CAF) in the Bevill Building houses high-resolution TEM, SEM, FIB, and XRD instrumentation.\n\nWe can arrange a specialized research tour on Monday, October 12th at 10:00 AM. Would you like to schedule that visit?"
                ],
                [
                    'u' => "Yes, Monday October 12 at 10 AM is perfect for me. I will be traveling from Atlanta.",
                    'a' => "Excellent! Please provide your contact details (name, phone, email) so we can notify our faculty guides."
                ],
                [
                    'u' => "Lucas Wright, phone: +1 (770) 555-0245, email: lucas.wright@outlook.com. Just myself.",
                    'a' => "Confirmed, Lucas! Your tour of the Materials Science facilities on Monday, October 12, 2026 at 10:00 AM is scheduled. A confirmation email with parking passes for the Ferguson Center Deck has been sent."
                ]
            ]
        ],
        [
            'type' => 'campus_tour',
            'name' => 'Isabella Rossi',
            'email' => 'isabella.rossi@gmail.com',
            'phone' => '+1 (305) 555-0267',
            'program_name' => 'Accelerated Master’s Program (AMP)',
            'program_id' => 138,
            'preferred_date' => '2026-10-12',
            'preferred_time' => 'Afternoon (2:00 PM)',
            'group_size' => 3,
            'notes' => 'Family visit from Florida; looking at undergraduate-to-graduate housing and campus recreation center.',
            'status' => 'confirmed',
            'confirmed_date' => '2026-10-12',
            'counselor_notes' => 'Confirmed afternoon tour with family. Guide assigned.',
            'call_attempts' => 0,
            'created_at' => '2026-09-27 14:15:00',
            'turns' => [
                [
                    'u' => "My parents and I are driving up from Florida. Can we schedule an afternoon walking tour on October 12th that includes student housing and campus dining?",
                    'a' => "Hello Isabella! We would love to host your family! Our 2:00 PM afternoon walking tour on Monday, October 12th includes a visit to the historic Quad, residence halls, student recreation center, and campus dining halls.\n\nWould you like to reserve a visit for your group of 3?"
                ],
                [
                    'u' => "Yes, please! October 12 at 2:00 PM for 3 people would be wonderful.",
                    'a' => "Great! Could you please share your full name, phone number, and email address to finalize the reservation?"
                ],
                [
                    'u' => "Isabella Rossi, +1 (305) 555-0267, isabella.rossi@gmail.com, group size of 3.",
                    'a' => "Tour confirmed, Isabella!\n\n• Student: Isabella Rossi\n• Date: Monday, October 12, 2026 at 2:00 PM\n• Group Size: 3 Attendees\n• Highlights: Quad, Residence Halls, Student Rec Center, Dining Facilities\n\nWe look forward to giving you and your parents a warm Crimson Tide welcome!"
                ]
            ]
        ],
        [
            'type' => 'campus_tour',
            'name' => 'Noah Campbell',
            'email' => 'noah.campbell@yahoo.com',
            'phone' => '+1 (615) 555-0289',
            'program_name' => 'Interdisciplinary Studies MA and MS',
            'program_id' => 139,
            'preferred_date' => '2026-10-14',
            'preferred_time' => 'Morning (10:00 AM)',
            'group_size' => 2,
            'notes' => 'Married graduate student touring campus amenities and family housing accommodations.',
            'status' => 'pending',
            'confirmed_date' => null,
            'counselor_notes' => null,
            'created_at' => '2026-09-26 09:30:00',
            'turns' => [
                [
                    'u' => "My spouse and I are moving to Tuscaloosa for graduate school. Can we take a tour that covers graduate student support services and campus recreation?",
                    'a' => "Hello Noah! Congratulations on your upcoming graduate journey! Yes, our guided campus tours can highlight graduate amenities, including the Graduate Student Association lounge, recreation center, and campus shuttle networks.\n\nWe have slots open on Wednesday, October 14th at 10:00 AM. Would that work for you both?"
                ],
                [
                    'u' => "Yes, Wednesday, October 14 at 10 AM works great for both of us.",
                    'a' => "Terrific! Please provide your name, phone number, and email to confirm the booking."
                ],
                [
                    'u' => "Noah Campbell, +1 (615) 555-0289, noah.campbell@yahoo.com. Group size: 2.",
                    'a' => "Booking registered, Noah! We have reserved 2 spots for you and your spouse on Wednesday, October 14, 2026 at 10:00 AM. Check-in information has been logged."
                ]
            ]
        ],
        [
            'type' => 'campus_tour',
            'name' => 'Olivia Martinez',
            'email' => 'olivia.martinez@gmail.com',
            'phone' => '+1 (210) 555-0278',
            'program_name' => 'Accelerated Master’s Program (AMP)',
            'program_id' => 138,
            'preferred_date' => '2026-10-14',
            'preferred_time' => 'Afternoon (2:00 PM)',
            'group_size' => 1,
            'notes' => 'Prospective transfer student exploring campus transit, dining halls, and academic advising centers.',
            'status' => 'pending',
            'confirmed_date' => null,
            'counselor_notes' => null,
            'created_at' => '2026-09-26 12:45:00',
            'turns' => [
                [
                    'u' => "Do campus tours explain how the Crimson Ride transit system works and where transfer student advising is located?",
                    'a' => "Hi Olivia! Yes, absolutely. Our student tour ambassadors show you all major transit stops for the Crimson Ride bus system, the Ferguson Student Center, and academic advising suites across campus.\n\nWe have an afternoon tour on Wednesday, October 14th at 2:00 PM. Would you like to attend?"
                ],
                [
                    'u' => "Yes, please reserve a spot for me on Wednesday, October 14th at 2:00 PM.",
                    'a' => "We'd love to have you! Please provide your full name, phone number, and email address."
                ],
                [
                    'u' => "Olivia Martinez, phone is +1 (210) 555-0278, email is olivia.martinez@gmail.com. Group size: 1.",
                    'a' => "You're registered, Olivia! Your guided campus tour is set for Wednesday, October 14, 2026 at 2:00 PM. We look forward to meeting you!"
                ]
            ]
        ],
        [
            'type' => 'campus_tour',
            'name' => 'Alexander Davies',
            'email' => 'alex.davies@oxon.org',
            'phone' => '+44 7700 900123',
            'program_name' => 'Materials Science PhD',
            'program_id' => null,
            'preferred_date' => '2026-10-16',
            'preferred_time' => 'Morning (10:00 AM)',
            'group_size' => 1,
            'notes' => 'International visiting scholar inspecting research infrastructure and cleanroom facilities.',
            'status' => 'confirmed',
            'confirmed_date' => '2026-10-16',
            'counselor_notes' => 'International scholar visit confirmed. Cleanroom access pre-authorized.',
            'call_attempts' => 0,
            'created_at' => '2026-09-26 15:20:00',
            'turns' => [
                [
                    'u' => "I am an international researcher visiting the US. Can I visit the microfabrication cleanroom and materials engineering labs at The University of Alabama?",
                    'a' => "Hello Alexander! Yes, we frequently host international scholars and prospective doctoral candidates. We can schedule an in-depth tour of the South Engineering Research Center (SERC) and the cleanroom facilities on Friday, October 16th at 10:00 AM.\n\nWould you like to confirm that visit?"
                ],
                [
                    'u' => "Yes please, Friday October 16 at 10:00 AM suits my travel itinerary perfectly.",
                    'a' => "Splendid! Please share your contact phone number, email address, and full name."
                ],
                [
                    'u' => "Alexander Davies, +44 7700 900123, alex.davies@oxon.org. Group of 1.",
                    'a' => "Confirmed, Alexander! Your specialized lab tour on Friday, October 16, 2026 at 10:00 AM is locked in. Our research liaison will meet you at the SERC entrance."
                ]
            ]
        ],
        [
            'type' => 'campus_tour',
            'name' => 'Maya Patel',
            'email' => 'maya.patel.bama@gmail.com',
            'phone' => '+1 (205) 555-0291',
            'program_name' => 'Accelerated Master’s Program (AMP)',
            'program_id' => 138,
            'preferred_date' => '2026-10-16',
            'preferred_time' => 'Afternoon (2:00 PM)',
            'group_size' => 2,
            'notes' => 'Looking at accelerated computer science curriculum, cyber-security research center, and student computing labs.',
            'status' => 'confirmed',
            'confirmed_date' => '2026-10-16',
            'counselor_notes' => 'Confirmed for Friday afternoon tour with sibling.',
            'call_attempts' => 0,
            'created_at' => '2026-09-25 10:15:00',
            'turns' => [
                [
                    'u' => "Can my sister and I take a tour to see the computer science department and Cyber Hall for the AMP program?",
                    'a' => "Hello Maya! Yes, definitely! Our guided afternoon tours visit Cyber Hall, the high-performance computing clusters, and collaborative student design studios.\n\nWe have slots available for Friday, October 16th at 2:00 PM. Would you like to reserve for 2 people?"
                ],
                [
                    'u' => "Yes please! 2 people for Friday Oct 16 at 2 PM.",
                    'a' => "Great! Please provide your name, phone number, and email address."
                ],
                [
                    'u' => "Maya Patel, +1 (205) 555-0291, maya.patel.bama@gmail.com. Group size: 2.",
                    'a' => "Confirmed, Maya! Your campus visit is set for Friday, October 16, 2026 at 2:00 PM for 2 guests. We look forward to showing you Cyber Hall!"
                ]
            ]
        ],
        [
            'type' => 'campus_tour',
            'name' => 'Samuel Jenkins',
            'email' => 'samuel.jenkins@outlook.com',
            'phone' => '+1 (256) 555-0234',
            'program_name' => 'Interdisciplinary Studies MA and MS',
            'program_id' => 139,
            'preferred_date' => '2026-10-19',
            'preferred_time' => 'Morning (10:00 AM)',
            'group_size' => 1,
            'notes' => 'Focusing on environmental policy and Alabama Water Institute facilities.',
            'status' => 'pending',
            'confirmed_date' => null,
            'counselor_notes' => null,
            'created_at' => '2026-09-25 12:30:00',
            'turns' => [
                [
                    'u' => "Does the general campus tour include the Alabama Water Institute and environmental sciences buildings?",
                    'a' => "Hello Samuel! Yes, our guided tours traverse the science quadrant including the Alabama Water Institute and hydrology research complexes where interdisciplinary water resource projects are headquartered.\n\nOur next available Monday tour is on October 19th at 10:00 AM. Would you like to join?"
                ],
                [
                    'u' => "Yes, Monday October 19 at 10:00 AM would be ideal for me.",
                    'a' => "Splendid! Could you please share your full name, phone number, and email address?"
                ],
                [
                    'u' => "Samuel Jenkins, +1 (256) 555-0234, samuel.jenkins@outlook.com. 1 person.",
                    'a' => "All booked, Samuel! You are scheduled for the campus tour on Monday, October 19, 2026 at 10:00 AM. We look forward to showing you the Alabama Water Institute!"
                ]
            ]
        ],
        [
            'type' => 'campus_tour',
            'name' => 'Zoe Mitchell',
            'email' => 'zoe.mitchell@gmail.com',
            'phone' => '+1 (404) 555-0256',
            'program_name' => 'Accelerated Master’s Program (AMP)',
            'program_id' => 138,
            'preferred_date' => '2026-10-19',
            'preferred_time' => 'Afternoon (2:00 PM)',
            'group_size' => 2,
            'notes' => 'Student and mother touring Ferguson Student Center, Supe Store, and honors learning community.',
            'status' => 'pending',
            'confirmed_date' => null,
            'counselor_notes' => null,
            'created_at' => '2026-09-25 14:40:00',
            'turns' => [
                [
                    'u' => "My mother and I want to tour the Ferguson Student Center, honors residence halls, and university bookstores. When can we book?",
                    'a' => "Hello Zoe! The Ferguson Student Center, University Supe Store, and Honors College learning communities are prominent highlights of our guided walking tour!\n\nWe have tour passes open for Monday, October 19th at 2:00 PM. Would you like to reserve for the two of you?"
                ],
                [
                    'u' => "Yes, please reserve 2 spots for Monday, October 19 at 2:00 PM.",
                    'a' => "Fantastic! What is your full name, phone number, and email address?"
                ],
                [
                    'u' => "Zoe Mitchell, +1 (404) 555-0256, zoe.mitchell@gmail.com. Group size of 2.",
                    'a' => "Your visit is registered, Zoe! We have reserved 2 tickets for you and your mother on Monday, October 19, 2026 at 2:00 PM. Welcome to Alabama!"
                ]
            ]
        ],
        [
            'type' => 'campus_tour',
            'name' => 'Daniel Thorne',
            'email' => 'daniel.thorne@gmail.com',
            'phone' => '+1 (205) 555-0280',
            'program_name' => 'Accelerated Master’s Program (AMP)',
            'program_id' => 138,
            'preferred_date' => '2026-10-02',
            'preferred_time' => 'Morning (10:00 AM)',
            'group_size' => 1,
            'notes' => 'Completed tour. Met with AMP coordinator. Student is applying for Spring semester.',
            'status' => 'completed',
            'confirmed_date' => '2026-10-02',
            'counselor_notes' => 'Attended morning tour on Oct 2. Showed high enthusiasm for AMP dual credit savings. Assisted with application fee waiver.',
            'call_attempts' => 0,
            'created_at' => '2026-09-24 09:20:00',
            'turns' => [
                [
                    'u' => "Can I book a campus tour for Friday, October 2nd at 10 AM to see the undergraduate-to-graduate engineering facilities?",
                    'a' => "Hello Daniel! Yes, Friday, October 2nd at 10:00 AM has slots open for our Guided Campus & Lab Discovery Tour. You will explore Shelby Hall, engineering labs, and graduate study spaces.\n\nWould you like to reserve your pass now?"
                ],
                [
                    'u' => "Yes please, just 1 ticket for me for Oct 2 at 10 AM.",
                    'a' => "Great! Please provide your full name, phone number, and email address."
                ],
                [
                    'u' => "Daniel Thorne, +1 (205) 555-0280, daniel.thorne@gmail.com.",
                    'a' => "Tour confirmed, Daniel! Your visit for Friday, October 2, 2026 at 10:00 AM is scheduled. We look forward to meeting you on campus!"
                ]
            ]
        ],
        [
            'type' => 'campus_tour',
            'name' => 'Sophia Lin',
            'email' => 'sophia.lin@gmail.com',
            'phone' => '+1 (334) 555-0299',
            'program_name' => 'Interdisciplinary Studies MA and MS',
            'program_id' => 139,
            'preferred_date' => '2026-10-02',
            'preferred_time' => 'Afternoon (2:00 PM)',
            'group_size' => 2,
            'notes' => 'Attended afternoon tour with sister. Met with Interdisciplinary Studies advisor.',
            'status' => 'completed',
            'confirmed_date' => '2026-10-02',
            'counselor_notes' => 'Attended afternoon visit on Oct 2. Interested in combining computer science with educational psychology.',
            'call_attempts' => 0,
            'created_at' => '2026-09-24 11:45:00',
            'turns' => [
                [
                    'u' => "My sister and I want to visit UA on October 2nd in the afternoon to learn more about the Interdisciplinary Studies MS.",
                    'a' => "Hello Sophia! That is a great time to visit. We have an afternoon guided tour on Friday, October 2nd at 2:00 PM that covers academic buildings across colleges, the Student Center, and graduate centers.\n\nWould you like me to book passes for both of you?"
                ],
                [
                    'u' => "Yes, please reserve 2 spots for Friday October 2 at 2:00 PM.",
                    'a' => "Certainly! What is your full name, phone number, and email address?"
                ],
                [
                    'u' => "Sophia Lin, +1 (334) 555-0299, sophia.lin@gmail.com. Group size: 2.",
                    'a' => "All confirmed, Sophia! We have reserved 2 tickets for you and your sister on Friday, October 2, 2026 at 2:00 PM. Have a wonderful visit!"
                ]
            ]
        ]
    ];

    $db->beginTransaction();

    $insertedConversations = 0;
    $insertedMessages = 0;
    $insertedCallbacks = 0;
    $insertedTours = 0;
    $insertedLeads = 0;

    foreach ($scenarios as $idx => $s) {
        $num = $idx + 1;
        $visId = 'vis_uoa_' . bin2hex(random_bytes(8));
        $sessId = 'sess_uoa_' . bin2hex(random_bytes(10));
        $trigger = ($s['type'] === 'callback') ? 'counselor_callback' : 'campus_tour';
        $formsShown = json_encode([$trigger]);

        // 1. Insert Conversation
        $stmtConv = $db->prepare("
            INSERT INTO conversations (
                organization_id, chatbot_id, visitor_id, session_id,
                visitor_name, visitor_email, visitor_phone,
                page_url, page_title,
                lead_name_collected, lead_email_collected, lead_phone_collected,
                lead_program_interest, program_id, lead_capture_trigger,
                last_offer_turn, total_offers_count, lead_forms_shown,
                is_test, lead_captured_at, started_at, last_message_at,
                latest_sentiment, latest_frustration, latest_lead_intent,
                latest_conversation_stage, needs_human, visitor_type
            ) VALUES (
                52, :bot_id, :vis_id, :sess_id,
                :name, :email, :phone,
                'https://www.ua.edu/admissions', 'Admissions & Academics | The University of Alabama',
                1, 1, 1,
                :prog_name, :prog_id, :trigger,
                2, 1, :forms_shown,
                0, :lead_captured_at, :started_at, :last_message_at,
                'positive', 0.00, 'high',
                'decision', :needs_human, 'prospective'
            )
        ");
        $stmtConv->execute([
            ':bot_id' => $botId,
            ':vis_id' => $visId,
            ':sess_id' => $sessId,
            ':name' => $s['name'],
            ':email' => $s['email'],
            ':phone' => $s['phone'],
            ':prog_name' => $s['program_name'],
            ':prog_id' => $s['program_id'],
            ':trigger' => $trigger,
            ':forms_shown' => $formsShown,
            ':lead_captured_at' => $s['created_at'],
            ':started_at' => $s['created_at'],
            ':last_message_at' => $s['created_at'],
            ':needs_human' => ($s['type'] === 'callback') ? 1 : 0
        ]);
        $convId = (int)$db->lastInsertId();
        $insertedConversations++;

        // 2. Insert Multi-Turn Messages
        $msgTime = strtotime($s['created_at']);
        foreach ($s['turns'] as $turnIdx => $turn) {
            // User message
            $uTime = date('Y-m-d H:i:s', $msgTime + ($turnIdx * 60));
            $stmtUMsg = $db->prepare("
                INSERT INTO messages (
                    conversation_id, organization_id, role, content,
                    knowledge_sources_used, tokens_used, sentiment, emotion,
                    frustration, conversation_trend, intent_label,
                    conversation_stage, lead_intent, needs_human, is_fallback,
                    source, created_at
                ) VALUES (
                    :cid, 52, 'user', :content,
                    '[]', 0, 'neutral', 'curious',
                    0.00, 'improving', :intent,
                    'discovery', 'medium', 0, 0,
                    'user', :ctime
                )
            ");
            $uIntent = ($turnIdx === 0) ? 'PROGRAM_QUERY' : (($turnIdx === 1) ? 'INTENT_EXPRESSION' : 'LEAD_DETAILS');
            $stmtUMsg->execute([
                ':cid' => $convId,
                ':content' => $turn['u'],
                ':intent' => $uIntent,
                ':ctime' => $uTime
            ]);
            $insertedMessages++;

            // Assistant message
            $aTime = date('Y-m-d H:i:s', $msgTime + ($turnIdx * 60) + 25);
            $stmtAMsg = $db->prepare("
                INSERT INTO messages (
                    conversation_id, organization_id, role, content,
                    knowledge_sources_used, tokens_used, sentiment, emotion,
                    frustration, conversation_trend, intent_label, intent_explanation,
                    conversation_stage, lead_intent, needs_human, is_fallback,
                    source, created_at
                ) VALUES (
                    :cid, 52, 'assistant', :content,
                    '[]', 165, 'positive', 'enthusiastic',
                    0.00, 'improving', :intent, :expl,
                    :stage, :lead_intent, :needs_human, 0,
                    'llm', :ctime
                )
            ");
            $aIntent = ($turnIdx === 0) ? 'INFORMATION_AND_OFFER' : (($turnIdx === 1) ? 'FIELD_COLLECTION' : 'BOOKING_CONFIRMATION');
            $aExpl = ($turnIdx === 2) ? "Successfully confirmed {$trigger} booking for student." : "Answered academic query and guided booking.";
            $aStage = ($turnIdx === 2) ? 'decision' : 'consideration';
            $aLeadInt = ($turnIdx === 2) ? 'high' : 'medium';
            $stmtAMsg->execute([
                ':cid' => $convId,
                ':content' => $turn['a'],
                ':intent' => $aIntent,
                ':expl' => $aExpl,
                ':stage' => $aStage,
                ':lead_intent' => $aLeadInt,
                ':needs_human' => ($s['type'] === 'callback' && $turnIdx === 2) ? 1 : 0,
                ':ctime' => $aTime
            ]);
            $insertedMessages++;
        }

        // 3. Insert Specific Booking Record
        if ($s['type'] === 'callback') {
            $stmtCb = $db->prepare("
                INSERT INTO counselor_callbacks (
                    organization_id, chatbot_id, program_id, conversation_id,
                    assigned_user_id, student_name, student_phone, student_email,
                    preferred_time_slot, topic_or_query, status, counselor_notes,
                    call_attempts, created_at, updated_at
                ) VALUES (
                    52, :bot_id, :prog_id, :cid,
                    :counselor_id, :name, :phone, :email,
                    :slot, :topic, :status, :notes,
                    :attempts, :cb_created_at, :cb_updated_at
                )
            ");
            $stmtCb->execute([
                ':bot_id' => $botId,
                ':prog_id' => $s['program_id'],
                ':cid' => $convId,
                ':counselor_id' => $counselorId,
                ':name' => $s['name'],
                ':phone' => $s['phone'],
                ':email' => $s['email'],
                ':slot' => $s['slot'],
                ':topic' => $s['topic'],
                ':status' => $s['status'],
                ':notes' => $s['counselor_notes'],
                ':attempts' => $s['call_attempts'],
                ':cb_created_at' => $s['created_at'],
                ':cb_updated_at' => $s['created_at']
            ]);
            $insertedCallbacks++;
        } else {
            $stmtTour = $db->prepare("
                INSERT INTO campus_tour_bookings (
                    organization_id, chatbot_id, program_id, conversation_id,
                    slot_id, assigned_user_id, student_name, student_email,
                    student_phone, preferred_date, preferred_time, program_interest,
                    group_size, notes, status, confirmed_date, counselor_notes,
                    created_at, updated_at
                ) VALUES (
                    52, :bot_id, :prog_id, :cid,
                    :slot_id, :counselor_id, :name, :email,
                    :phone, :pref_date, :pref_time, :prog_interest,
                    :group_size, :notes, :status, :conf_date, :c_notes,
                    :tour_created_at, :tour_updated_at
                )
            ");
            $stmtTour->execute([
                ':bot_id' => $botId,
                ':prog_id' => $s['program_id'],
                ':cid' => $convId,
                ':slot_id' => ($s['preferred_date'] === '2026-10-09' && $activeSlotId) ? $activeSlotId : null,
                ':counselor_id' => $counselorId,
                ':name' => $s['name'],
                ':email' => $s['email'],
                ':phone' => $s['phone'],
                ':pref_date' => $s['preferred_date'],
                ':pref_time' => $s['preferred_time'],
                ':prog_interest' => $s['program_name'],
                ':group_size' => $s['group_size'],
                ':notes' => $s['notes'],
                ':status' => $s['status'],
                ':conf_date' => $s['confirmed_date'],
                ':c_notes' => $s['counselor_notes'],
                ':tour_created_at' => $s['created_at'],
                ':tour_updated_at' => $s['created_at']
            ]);
            $insertedTours++;
        }

        // 4. Insert CRM Lead
        $leadType = ($s['type'] === 'callback') ? 'counselor_callback' : 'campus_tour';
        $pipelineStage = ($s['status'] === 'completed') ? 'decision' : (($s['type'] === 'campus_tour') ? 'campus_visit' : 'contacted');
        $stmtLead = $db->prepare("
            INSERT INTO leads (
                organization_id, chatbot_id, lead_type, conversation_id, session_id,
                program_id, assigned_user_id, name, email, phone, program_interest,
                notes, status, pipeline_stage, conversion_score, conversion_score_rationale,
                acquisition_source, created_at, updated_at
            ) VALUES (
                52, :bot_id, :ltype, :cid, :sess_id,
                :prog_id, :counselor_id, :name, :email, :phone, :prog_interest,
                :notes, :status, :pstage, :cscore, :rationale,
                'Website AI Chatbot', :lead_created_at, :lead_updated_at
            )
        ");
        $stmtLead->execute([
            ':bot_id' => $botId,
            ':ltype' => $leadType,
            ':cid' => $convId,
            ':sess_id' => $sessId,
            ':prog_id' => $s['program_id'],
            ':counselor_id' => $counselorId,
            ':name' => $s['name'],
            ':email' => $s['email'],
            ':phone' => $s['phone'],
            ':prog_interest' => $s['program_name'],
            ':notes' => ($s['type'] === 'callback') ? "Preferred Slot: {$s['slot']}. Query: {$s['topic']}" : "Visit Date: {$s['preferred_date']} ({$s['preferred_time']}). Party: {$s['group_size']}",
            ':status' => ($s['status'] === 'completed') ? 'converted' : 'new',
            ':pstage' => $pipelineStage,
            ':cscore' => ($s['type'] === 'callback') ? 88 : 92,
            ':rationale' => ($s['type'] === 'callback') ? 'Requested priority callback with admissions counselor' : 'Booked guided campus discovery tour',
            ':lead_created_at' => $s['created_at'],
            ':lead_updated_at' => $s['created_at']
        ]);
        $insertedLeads++;

        // 5. Insert Visitor Session & Page Views (for Session Journeys tab)
        $stmtSess = $db->prepare("
            INSERT INTO visitor_sessions (
                organization_id, session_id, visitor_id, referrer, utm_source, utm_medium,
                utm_campaign, entry_page, exit_page, total_pages, total_dwell_seconds,
                conversion_status, converted_at, started_at, last_active_at
            ) VALUES (
                52, :sess_id, :vis_id, 'https://www.google.com/', 'google', 'organic',
                'admissions_2026', 'https://www.ua.edu/admissions', 'https://www.ua.edu/admissions/thank-you',
                3, :dwell, 'lead_converted', :sess_converted_at, :sess_started_at, :last_active
            )
        ");
        $dwell = rand(180, 480);
        $lastActive = date('Y-m-d H:i:s', strtotime($s['created_at']) + $dwell);
        $stmtSess->execute([
            ':sess_id' => $sessId,
            ':vis_id' => $visId,
            ':dwell' => $dwell,
            ':sess_converted_at' => $s['created_at'],
            ':sess_started_at' => $s['created_at'],
            ':last_active' => $lastActive
        ]);

        // Page view 1
        $stmtPv = $db->prepare("
            INSERT INTO visitor_page_views (
                organization_id, session_id, visitor_id, url, page_title,
                time_spent_seconds, view_order, created_at, updated_at
            ) VALUES (
                52, :sess_id, :vis_id, 'https://www.ua.edu/admissions',
                'Admissions & Academics | The University of Alabama', :spent, 1, :pv1_created_at, :pv1_updated_at
            )
        ");
        $stmtPv->execute([
            ':sess_id' => $sessId,
            ':vis_id' => $visId,
            ':spent' => rand(60, 120),
            ':pv1_created_at' => $s['created_at'],
            ':pv1_updated_at' => $s['created_at']
        ]);

        // Page view 2 (Program specific page)
        $progSlug = ($s['program_id'] === 138) ? 'accelerated-masters' : 'interdisciplinary-studies';
        $stmtPv2 = $db->prepare("
            INSERT INTO visitor_page_views (
                organization_id, session_id, visitor_id, url, page_title,
                time_spent_seconds, view_order, created_at, updated_at
            ) VALUES (
                52, :sess_id, :vis_id, :purl, :ptitle, :spent, 2, :pv2_created_at, :pv2_updated_at
            )
        ");
        $pv2Time = date('Y-m-d H:i:s', strtotime($s['created_at']) + 60);
        $stmtPv2->execute([
            ':sess_id' => $sessId,
            ':vis_id' => $visId,
            ':purl' => "https://www.ua.edu/programs/{$progSlug}",
            ':ptitle' => "{$s['program_name']} | The University of Alabama",
            ':spent' => rand(90, 180),
            ':pv2_created_at' => $pv2Time,
            ':pv2_updated_at' => $pv2Time
        ]);

        printf("[%02d/25] ✓ [%s] Conv #%d: %s (%s) — %s\n", $num, strtoupper($s['type']), $convId, $s['name'], $s['phone'], $s['program_name']);
    }

    $db->commit();

    echo "\n============================================================\n";
    echo "SEEDING SUMMARY FOR ORGANIZATION 52:\n";
    echo "============================================================\n";
    echo "✓ Conversations Created: {$insertedConversations}\n";
    echo "✓ Messages Created:      {$insertedMessages} (multi-turn realistic dialogues)\n";
    echo "✓ Callbacks Created:     {$insertedCallbacks} (in counselor_callbacks)\n";
    echo "✓ Campus Tours Created:  {$insertedTours} (in campus_tour_bookings)\n";
    echo "✓ CRM Leads Created:     {$insertedLeads} (in leads)\n";
    echo "============================================================\n\n";

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "❌ ERROR: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
