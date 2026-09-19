<?php

namespace App\Database;

use PDO;

class BackfillKnowledgeData
{
    public static function run(PDO $db): void
    {
        echo "--> Running BackfillKnowledgeData for salan.khalkho@edvora.chat (Organization 33)...\n";

        // Find user 45 / organization 33
        $stmtUser = $db->prepare("SELECT organization_id FROM users WHERE email = 'salan.khalkho@edvora.chat' LIMIT 1");
        $stmtUser->execute();
        $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);
        $orgId = $userRow ? (int)$userRow['organization_id'] : 33;

        // Ensure chatbot exists
        $stmtBot = $db->prepare("SELECT id FROM chatbots WHERE organization_id = ? ORDER BY id ASC LIMIT 1");
        $stmtBot->execute([$orgId]);
        $botRow = $stmtBot->fetch(PDO::FETCH_ASSOC);
        $botId = $botRow ? (int)$botRow['id'] : 33;

        // 1. Departments mapping
        $departments = [
            'undergrad_admissions' => ['name' => 'Undergraduate Admissions', 'icon' => '🎓', 'color' => '#047857'],
            'financial_aid'        => ['name' => 'Financial Aid & Bursar',    'icon' => '💰', 'color' => '#B45309'],
            'student_affairs'      => ['name' => 'Student Affairs & Housing', 'icon' => '🏠', 'color' => '#6D28D9'],
            'governance'           => ['name' => "Dean's Office & Compliance",'icon' => '⚖️', 'color' => '#475569'],
            'placements'           => ['name' => 'Career Services & Placements','icon' => '💼', 'color' => '#059669'],
        ];

        $deptIds = [];
        $stmtCheckDept = $db->prepare("SELECT id FROM departments WHERE organization_id = ? AND slug = ? LIMIT 1");
        $stmtInsertDept = $db->prepare("
            INSERT INTO departments (organization_id, name, slug, description, icon, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
        ");

        foreach ($departments as $slug => $info) {
            $stmtCheckDept->execute([$orgId, $slug]);
            $existingDept = $stmtCheckDept->fetch(PDO::FETCH_ASSOC);
            if ($existingDept) {
                $deptIds[$slug] = (int)$existingDept['id'];
            } else {
                $stmtInsertDept->execute([$orgId, $info['name'], $slug, $info['name'] . ' Department', $info['icon']]);
                $deptIds[$slug] = (int)$db->lastInsertId();
            }
        }

        // 2. 8 Institutional Documents
        $sources = [
            [
                'title' => '2026–27 Master Admissions Prospectus & Eligibility Matrix',
                'type' => 'document',
                'file_name' => 'apex_admissions_prospectus_2026_27_v2.6.pdf',
                'file_size' => 14889728,
                'mime_type' => 'application/pdf',
                'source_url' => null,
                'category' => 'Admissions & Prospectus',
                'academic_version' => '2026-27',
                'effective_from' => '2026-08-01',
                'expires_on' => '2027-06-30',
                'review_frequency_days' => 365,
                'last_reviewed_at' => '2026-08-01 09:00:00',
                'status' => 'active',
                'keywords' => 'eligibility matrix, cutoffs, btech, mba, bba, entrance exams, apex entrance test, application deadline, quota, admission criteria',
                'semantic_tags' => json_encode(['admissions', 'undergraduate', 'prospectus', 'matrix', 'cutoffs']),
                'dept_slug' => 'undergrad_admissions',
                'raw_content' => "APEX INSTITUTE OF HIGHER EDUCATION — 2026–27 MASTER ADMISSIONS PROSPECTUS & ELIGIBILITY MATRIX\nAcademic Year: 2026-2027 | Document Version: 2.6\n\n1. ELIGIBILITY CRITERIA FOR UNDERGRADUATE DEGREE PROGRAMS:\n- B.Tech in Computer Science & Artificial Intelligence: Minimum 75% aggregate in 10+2 (PCM) or equivalent with valid JEE Mains or Apex Entrance Examination (AEE) score.\n- B.Tech in Electronics & Communication: Minimum 70% aggregate in 10+2 (PCM).\n- Bachelor of Business Administration (BBA - Honours): Minimum 65% in 10+2 from recognized central or state boards. Direct interview round required.\n- B.Sc. Data Science: Minimum 70% in 10+2 with Mathematics / Computer Applications.\n\n2. ADMISSIONS TIMELINE & CUTOFF DATES:\n- Application Window Opens: August 1, 2026\n- Phase 1 Seat Allocation: September 15, 2026\n- Phase 2 Final Acceptance Deadline: June 30, 2027\n\n3. ADMISSION CONTACT:\nAdmissions Office, Block A, Apex Campus, Bangalore. Email: admissions@apex.edu | Direct Line: +91 80 4129 8800."
            ],
            [
                'title' => 'Undergraduate Tuition, Hostel & Laboratory Fee Schedule 2026',
                'type' => 'document',
                'file_name' => 'ug_tuition_hostel_lab_fee_schedule_2026.pdf',
                'file_size' => 3774873,
                'mime_type' => 'application/pdf',
                'source_url' => null,
                'category' => 'Tuition & Fee Structure',
                'academic_version' => '2026-27',
                'effective_from' => '2026-01-15',
                'expires_on' => '2026-09-16',
                'review_frequency_days' => 180,
                'last_reviewed_at' => '2026-01-15 10:30:00',
                'status' => 'active',
                'keywords' => 'tuition fees, semester payments, hostel charges, laboratory deposit, refund policy, bursar desk, installments, late fee penalty',
                'semantic_tags' => json_encode(['tuition', 'fee_schedule', 'hostel_charges', 'bursar', 'financial_aid']),
                'dept_slug' => 'financial_aid',
                'raw_content' => "OFFICE OF THE BURSAR — UNDERGRADUATE TUITION, HOSTEL & LABORATORY FEE SCHEDULE 2026\nCycle: 2026 | Validity Window: Jan 15, 2026 to Sep 16, 2026 (Expiring Soon - Subject to Semi-Annual Review)\n\n1. PROGRAM TUITION SCHEDULE (PER ANNUM):\n- B.Tech (CSE / AI & ML): INR 2,75,000 per academic year (payable in two equal semester installments).\n- B.Tech (Core Engineering): INR 2,10,000 per academic year.\n- BBA & Management Programs: INR 1,85,000 per academic year.\n\n2. HOSTEL & RESIDENTIAL CHARGES:\n- Air-Conditioned Twin Sharing: INR 1,35,000 per annum (includes mess and laundry charges).\n- Non-AC Three Sharing: INR 95,000 per annum (includes nutritious 4-meal daily dining).\n- Caution Deposit (Refundable): INR 15,000 one-time.\n\n3. PAYMENT GATEWAYS & INSTALLMENTS:\nAll fees must be remitted via the student portal or direct NEFT/RTGS to Apex Institute account. Installment requests must receive Bursar approval before semester census date."
            ],
            [
                'title' => 'Merit-Based Scholarship Criteria & Fee Waiver Tiers 2025–26',
                'type' => 'url',
                'file_name' => null,
                'file_size' => null,
                'mime_type' => null,
                'source_url' => 'https://apex.edu/scholarships/merit-2025',
                'category' => 'Scholarships & Aid',
                'academic_version' => '2025-26',
                'effective_from' => '2025-11-01',
                'expires_on' => '2026-08-31',
                'review_frequency_days' => 365,
                'last_reviewed_at' => '2025-11-01 14:00:00',
                'status' => 'expired',
                'keywords' => 'scholarships, merit waiver, 2025 criteria, financial aid tiers, sports quota, endowment grant, expired policy, quarantined',
                'semantic_tags' => json_encode(['scholarships', 'merit_waiver', 'expired', 'quarantined', 'financial_aid']),
                'dept_slug' => 'financial_aid',
                'raw_content' => "APEX SCHOLARSHIP COMMITTEE — MERIT-BASED SCHOLARSHIP CRITERIA & FEE WAIVER TIERS 2025–26 (HISTORICAL - QUARANTINED)\nEffective: Nov 1, 2025 | Expiration: Aug 31, 2026 [EXPIRED & QUARANTINED FROM LIVE CHAT CONTEXT]\n\n1. 2025-26 WAIVER TIERS:\n- Tier 1 (Founder's Excellence Award): 100% Tuition Waiver for students scoring >98% in 10+2 boards or top 500 in national examinations.\n- Tier 2 (Dean's Merit Scholarship): 50% Tuition Waiver for students scoring 92% to 97.9%.\n- Tier 3 (Apex Sports & Talent Endowment): 25% to 50% fee rebate for national / state sports medalists.\n\nNOTE: This document has completed its validity on August 31, 2026. Current applicants must refer to the upcoming 2026-27 Scholarship Gazette once ratified by the Board of Governors."
            ],
            [
                'title' => 'Campus Housing, Hostel Allocations & Mess Rules 2026',
                'type' => 'text_paste',
                'file_name' => null,
                'file_size' => null,
                'mime_type' => null,
                'source_url' => null,
                'category' => 'Housing & Campus Life',
                'academic_version' => '2026-27',
                'effective_from' => '2026-03-01',
                'expires_on' => '2026-12-31',
                'review_frequency_days' => 180,
                'last_reviewed_at' => '2026-03-01 08:00:00',
                'status' => 'active',
                'keywords' => 'hostel rules, curfew timings, mess menu, room allocation, visitor policy, laundry schedule, warden contacts, silent hours, campus life',
                'semantic_tags' => json_encode(['housing', 'hostel_rules', 'mess_menu', 'campus_life', 'review_due']),
                'dept_slug' => 'student_affairs',
                'raw_content' => "OFFICE OF RESIDENTIAL LIFE & STUDENT AFFAIRS — CAMPUS HOUSING, HOSTEL ALLOCATIONS & MESS RULES 2026\nEffective: March 1, 2026 to December 31, 2026 | Scheduled Audit Review: Due (Every 6 Months)\n\n1. HOSTEL PROTOCOLS & RESIDENCE DISCIPLINE:\n- Curfew: Campus main gates close at 10:00 PM on weekdays and 10:30 PM on weekends.\n- Room Allocation: Rooms are allotted on a first-come, first-served basis upon full payment of residential advance.\n- Quiet Hours: Strictly enforced across all blocks between 11:00 PM and 6:00 AM.\n\n2. MESS AND DINING FACILITIES:\n- 4 Meals provided daily (Breakfast: 7:30 AM – 9:30 AM, Lunch: 12:30 PM – 2:30 PM, High Tea: 5:00 PM – 6:00 PM, Dinner: 7:30 PM – 9:30 PM).\n- Both Vegetarian and Non-Vegetarian certified counters available with monthly food committee menu rotation.\n\n3. WARDEN & SECURITY HELPLINES:\n- Chief Warden Office: +91 80 4129 8890 | 24x7 Security Control: Ext 100."
            ],
            [
                'title' => 'Institutional Anti-Ragging Code of Conduct & Safety Protocols',
                'type' => 'document',
                'file_name' => 'anti_ragging_policy_statutory_apex.pdf',
                'file_size' => 1887436,
                'mime_type' => 'application/pdf',
                'source_url' => null,
                'category' => 'Institutional Governance',
                'academic_version' => 'Evergreen',
                'effective_from' => '2024-07-10',
                'expires_on' => null,
                'review_frequency_days' => 730,
                'last_reviewed_at' => '2026-07-10 11:00:00',
                'status' => 'active',
                'keywords' => 'anti-ragging, safety, code of conduct, statutory compliance, ugc regulations, zero tolerance, complaint committee, evergreen policy',
                'semantic_tags' => json_encode(['anti_ragging', 'statutory', 'governance', 'safety', 'evergreen']),
                'dept_slug' => 'governance',
                'raw_content' => "INSTITUTIONAL GOVERNANCE & STATUTORY COMPLIANCE — ANTI-RAGGING CODE OF CONDUCT & SAFETY PROTOCOLS\nStatus: Permanent Evergreen Institutional Policy | Zero-Tolerance Statutory Framework (UGC / AICTE Compliant)\n\n1. ZERO TOLERANCE STATEMENT:\nApex Institute maintains an absolute zero-tolerance policy towards ragging in any form on campus premises, hostels, transport vehicles, or virtual academic spaces.\n\n2. DEFINITION & OFFENCES:\nAny conduct by a student whether by words spoken or written or by an act which has the effect of teasing, treating or handling with rudeness a fresher or any other student is legally punishable under university statutes.\n\n3. COMPLAINT & REPORTING MECHANISMS:\n- Anti-Ragging Toll-Free Helpline: 1800-180-5522\n- Campus Anti-Ragging Squad Hotline: +91 80 4129 8811 (Confidential & 24x7 Active)\n- Online Complaint Portal: https://apex.edu/anti-ragging\nAll complaints are adjudicated within 24 hours by the Dean of Student Welfare and Campus Legal Counsel."
            ],
            [
                'title' => 'Annual Placement Velocity & Top Recruiter Salary Disclosures',
                'type' => 'document',
                'file_name' => 'apex_career_placement_velocity_report_2025_26.pdf',
                'file_size' => 5452595,
                'mime_type' => 'application/pdf',
                'source_url' => null,
                'category' => 'Placements & Careers',
                'academic_version' => '2025-26',
                'effective_from' => '2026-01-20',
                'expires_on' => '2026-11-30',
                'review_frequency_days' => 180,
                'last_reviewed_at' => '2026-07-20 15:30:00',
                'status' => 'active',
                'keywords' => 'placements, highest salary package, average ctc, marquee recruiters, microsoft, google, amazon, placement percentage, internship stipend',
                'semantic_tags' => json_encode(['placements', 'recruiter_salary', 'ctc', 'career_cell', 'internships']),
                'dept_slug' => 'placements',
                'raw_content' => "CAREER SERVICES & PLACEMENT CELL — ANNUAL PLACEMENT VELOCITY & RECRUITER SALARY DISCLOSURES 2025–26\nAudited by University Corporate Relations Board | Effective: Jan 20, 2026 to Nov 30, 2026\n\n1. EXECUTIVE SUMMARY & BENCHMARKS:\n- Total Offers Extended: 1,248 across Engineering, Management & Computing\n- Highest International CTC: INR 54.2 LPA\n- Highest Domestic CTC: INR 44.5 LPA (Offered by top-tier Cloud & AI firm)\n- Overall Campus Average CTC: INR 11.8 LPA\n- Top 20% Batch Average CTC: INR 21.4 LPA\n- Placement Conversion Rate: 97.4% for registered eligible candidates.\n\n2. MARQUEE CORPORATE RECRUITERS:\nGoogle, Microsoft, Amazon, Cisco, Goldman Sachs, Deloitte, Bain & Company, Morgan Stanley, Siemens Healthineers, and TCS Research.\n\n3. INTERNSHIP VELOCITY:\nOver 420 students received pre-placement offers (PPOs) during summer internships with average stipend exceeding INR 55,000/month."
            ],
            [
                'title' => 'International Student Visa Guidance & IELTS/TOEFL Cutoffs',
                'type' => 'url',
                'file_name' => null,
                'file_size' => null,
                'mime_type' => null,
                'source_url' => 'https://apex.edu/international/admissions',
                'category' => 'Admissions & Prospectus',
                'academic_version' => '2026-27',
                'effective_from' => '2026-08-01',
                'expires_on' => '2027-07-31',
                'review_frequency_days' => 365,
                'last_reviewed_at' => '2026-08-01 12:00:00',
                'status' => 'active',
                'keywords' => 'international students, student visa, fro registration, ielts cutoffs, toefl requirements, passport validation, global admissions',
                'semantic_tags' => json_encode(['international_admissions', 'visa_guidance', 'ielts', 'toefl', 'global_desk']),
                'dept_slug' => 'undergrad_admissions',
                'raw_content' => "INTERNATIONAL RELATIONS & GLOBAL ADMISSIONS — STUDENT VISA GUIDANCE & ENGLISH PROFICIENCY CUTOFFS\nCycle: 2026-2027 | Web URL Ingest & Webhook Synchronized | Valid until: July 31, 2027\n\n1. ENGLISH LANGUAGE PROFICIENCY REQUIREMENTS:\n- IELTS: Minimum overall band score of 6.5 (no sub-score below 6.0) for Undergraduate Engineering and Computing.\n- TOEFL iBT: Minimum score of 85 (Writing 22, Speaking 20).\n- Duolingo English Test (DET): Accepted score of 115 or higher.\n\n2. STUDENT VISA & BONAFIDE LETTER DISCLOSURE:\nUpon payment of registration seat deposit, the International Student Office issues the official Visa Eligibility Certificate and Bonafide Letter within 48 hours for submission to the Indian Embassy / High Commission.\n\n3. MANDATORY FRRO REGISTRATION:\nAll international passport holders must complete Foreigners Regional Registration Officer (FRRO) formalities within 14 days of arrival in Bangalore, supported by campus international affairs liaisons."
            ],
            [
                'title' => '2024–25 Master Undergraduate Prospectus (Superseded)',
                'type' => 'document',
                'file_name' => 'apex_undergraduate_prospectus_2024_25_archived.pdf',
                'file_size' => 18979225,
                'mime_type' => 'application/pdf',
                'source_url' => null,
                'category' => 'Admissions & Prospectus',
                'academic_version' => '2024-25',
                'effective_from' => '2024-08-01',
                'expires_on' => '2025-07-31',
                'review_frequency_days' => 365,
                'last_reviewed_at' => '2025-07-31 18:00:00',
                'status' => 'archived',
                'keywords' => 'historical catalog, 2024 prospectus, superseded version, audit trail, past syllabus, archived brochure, previous academic year',
                'semantic_tags' => json_encode(['archived_history', 'prospectus_2024', 'superseded', 'audit_lineage']),
                'dept_slug' => 'undergrad_admissions',
                'raw_content' => "APEX INSTITUTE OF HIGHER EDUCATION — 2024–25 MASTER UNDERGRADUATE PROSPECTUS (SUPERSEDED)\nArchived on August 1, 2025 | Replaced by Version 2.6 for 2026-27 | Retained for 5-Year Institutional Compliance Audit\n\n[ARCHIVED HISTORICAL RECORD]\nThis catalog reflects academic policies, programs, and fee structures applicable to the 2024-25 cohort. It is permanently quarantined from prospective student queries and retained strictly for university accreditation and accreditation compliance inspection."
            ]
        ];

        // Ensure ONLY the 8 exact institutional documents exist for Org 33
        $validTitles = array_column($sources, 'title');
        $placeholders = implode(',', array_fill(0, count($validTitles), '?'));
        $stmtDeleteOthers = $db->prepare("DELETE FROM knowledge_sources WHERE organization_id = ? AND title NOT IN ($placeholders)");
        $stmtDeleteOthers->execute(array_merge([$orgId], $validTitles));

        $stmtCheckSource = $db->prepare("SELECT id FROM knowledge_sources WHERE organization_id = ? AND title = ? LIMIT 1");
        $stmtInsertSource = $db->prepare("
            INSERT INTO knowledge_sources (
                organization_id, chatbot_id, title, type, file_path, source_url,
                raw_content, processed_content, category, academic_version, effective_from, expires_on,
                review_frequency_days, last_reviewed_at, status, keywords,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                NOW(), NOW()
            )
        ");

        $stmtUpdateSource = $db->prepare("
            UPDATE knowledge_sources SET
                chatbot_id = ?, type = ?, file_path = ?, source_url = ?,
                raw_content = ?, processed_content = ?, category = ?, academic_version = ?,
                effective_from = ?, expires_on = ?, review_frequency_days = ?, last_reviewed_at = ?,
                status = ?, keywords = ?, updated_at = NOW()
            WHERE id = ?
        ");

        $stmtCheckDeptLink = $db->prepare("SELECT 1 FROM department_knowledge WHERE department_id = ? AND knowledge_source_id = ? LIMIT 1");
        $stmtInsertDeptLink = $db->prepare("INSERT IGNORE INTO department_knowledge (department_id, knowledge_source_id) VALUES (?, ?)");

        foreach ($sources as $s) {
            $filePath = !empty($s['file_name']) ? 'uploads/' . $s['file_name'] : null;

            $stmtCheckSource->execute([$orgId, $s['title']]);
            $existing = $stmtCheckSource->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $sourceId = (int)$existing['id'];
                $stmtUpdateSource->execute([
                    $botId, $s['type'], $filePath, $s['source_url'],
                    $s['raw_content'], $s['raw_content'], $s['category'], $s['academic_version'],
                    $s['effective_from'], $s['expires_on'], $s['review_frequency_days'], $s['last_reviewed_at'],
                    $s['status'], $s['keywords'],
                    $sourceId
                ]);
            } else {
                $stmtInsertSource->execute([
                    $orgId, $botId, $s['title'], $s['type'], $filePath, $s['source_url'],
                    $s['raw_content'], $s['raw_content'], $s['category'], $s['academic_version'], $s['effective_from'], $s['expires_on'],
                    $s['review_frequency_days'], $s['last_reviewed_at'], $s['status'], $s['keywords']
                ]);
                $sourceId = (int)$db->lastInsertId();
            }

            // Link to department
            $targetDeptId = $deptIds[$s['dept_slug']] ?? null;
            if ($targetDeptId && $sourceId) {
                $stmtCheckDeptLink->execute([$targetDeptId, $sourceId]);
                if (!$stmtCheckDeptLink->fetch()) {
                    $stmtInsertDeptLink->execute([$targetDeptId, $sourceId]);
                }
            }
        }

        echo "✓ Successfully ensured 8 knowledge sources and department linkages for Org {$orgId}.\n";
    }
}
