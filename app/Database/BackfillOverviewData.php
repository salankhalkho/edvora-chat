<?php

namespace App\Database;

use PDO;

class BackfillOverviewData
{
    public static function run(PDO $db): void
    {
        // PERMANENTLY DISABLED: Database seeding was a one-time job and is retired.
        return;

        // 1. Ensure Organization 33 details are set to Apex Institute of Higher Education
        $stmtOrg = $db->prepare("
            UPDATE organizations 
            SET name = 'Apex Institute of Higher Education',
                short_name = 'Apex Institute',
                institution_type = 'University',
                city = 'Bangalore',
                state = 'Karnataka',
                country = 'India',
                academic_year = '2026-2027',
                plan_id = 2,
                subscription_status = 'active',
                onboarding_completed = 1,
                onboarding_step = 16
            WHERE id = 33
        ");
        $stmtOrg->execute();

        // 2. Subscription -> Plan 2 (Growth)
        $stmtSub = $db->prepare("
            INSERT INTO subscriptions (organization_id, plan_id, billing_cycle, status, current_period_start, current_period_end, created_at, updated_at)
            VALUES (33, 2, 'monthly', 'active', DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_ADD(NOW(), INTERVAL 25 DAY), NOW(), NOW())
            ON DUPLICATE KEY UPDATE plan_id = 2, status = 'active', updated_at = NOW()
        ");
        $stmtSub->execute();

        // 3. Plan Quotas for Plan 2 (Console / Growth Plan)
        // Documents: 50 limit | Teams: 10 seats | Departments: 5 | Chatbots: 3
        $quotas = [
            ['plan_id' => 2, 'key' => 'max_chatbots', 'val' => 3, 'label' => 'Max Chatbots'],
            ['plan_id' => 2, 'key' => 'max_knowledge_sources', 'val' => 50, 'label' => 'Max Knowledge Sources'],
            ['plan_id' => 2, 'key' => 'max_staff_users', 'val' => 10, 'label' => 'Max Staff Users'],
            ['plan_id' => 2, 'key' => 'max_departments', 'val' => 5, 'label' => 'Max Departments'],
            ['plan_id' => 2, 'key' => 'max_messages_per_month', 'val' => 25000, 'label' => 'Max Messages Per Month'],
            ['plan_id' => 2, 'key' => 'max_leads_per_month', 'val' => 2500, 'label' => 'Max Leads Per Month'],
            ['plan_id' => 2, 'key' => 'max_file_upload_mb', 'val' => 25, 'label' => 'Max File Upload Mb'],
            ['plan_id' => 2, 'key' => 'conversation_history_days', 'val' => 90, 'label' => 'Conversation History Days']
        ];

        foreach ($quotas as $q) {
            $stmtQ = $db->prepare("
                INSERT INTO plan_quotas (plan_id, quota_key, quota_label, quota_value, quota_period, created_at, updated_at)
                VALUES (:plan_id, :key, :label, :val, 'monthly', NOW(), NOW())
                ON DUPLICATE KEY UPDATE quota_value = :val2, updated_at = NOW()
            ");
            $stmtQ->execute([
                ':plan_id' => $q['plan_id'],
                ':key'     => $q['key'],
                ':label'   => $q['label'],
                ':val'     => $q['val'],
                ':val2'    => $q['val']
            ]);
        }

        // 4. Ensure All 9 Departments for Org 33 are configured & active
        $depts = [
            [
                'name' => 'MBA Admissions',
                'slug' => 'mba-admissions',
                'icon' => '🎓',
                'desc' => 'Executive MBA, Full-Time MBA, and Specialization Admissions Desk',
                'email' => 'mba-admissions@edvora.chat',
                'phone' => '+91 98765 43210',
                'is_active' => 1,
                'is_preset' => 1
            ],
            [
                'name' => 'Undergraduate Admissions',
                'slug' => 'undergrad_admissions',
                'icon' => '🏛️',
                'desc' => 'First-year undergraduate admissions, eligibility guidelines, campus prospectus, and counseling.',
                'email' => 'undergrad@edvora.chat',
                'phone' => '+91 98765 43214',
                'is_active' => 1,
                'is_preset' => 1
            ],
            [
                'name' => 'Financial Aid & Bursar',
                'slug' => 'financial_aid',
                'icon' => '💰',
                'desc' => 'Tuition schedules, laboratory fees, installment plans, bursar payments, and banking partnerships.',
                'email' => 'bursar@edvora.chat',
                'phone' => '+91 98765 43215',
                'is_active' => 1,
                'is_preset' => 1
            ],
            [
                'name' => 'Student Affairs & Housing',
                'slug' => 'student_affairs',
                'icon' => '🛏️',
                'desc' => 'Campus residential halls, hostel room allotment, mess dining timings, and student life.',
                'email' => 'housing@edvora.chat',
                'phone' => '+91 98765 43216',
                'is_active' => 1,
                'is_preset' => 1
            ],
            [
                'name' => 'Dean\'s Office & Compliance',
                'slug' => 'governance',
                'icon' => '⚖️',
                'desc' => 'Statutory compliance, anti-ragging code of conduct, institutional governance, and safety protocols.',
                'email' => 'dean@edvora.chat',
                'phone' => '+91 98765 43217',
                'is_active' => 1,
                'is_preset' => 1
            ],
            [
                'name' => 'Career Services & Placements',
                'slug' => 'placements',
                'icon' => '💼',
                'desc' => 'Annual placement reports, marquee recruiters, CTC compensation packages, and internship disclosures.',
                'email' => 'careers@edvora.chat',
                'phone' => '+91 98765 43218',
                'is_active' => 1,
                'is_preset' => 1
            ],
            [
                'name' => 'School of Engineering & Technology',
                'slug' => 'engineering-tech',
                'icon' => '💻',
                'desc' => 'B.Tech, M.Tech, Computer Science, AI/ML, and Robotics Admissions',
                'email' => 'engineering@edvora.chat',
                'phone' => '+91 98765 43211',
                'is_active' => 1,
                'is_preset' => 0
            ],
            [
                'name' => 'Financial Aid & Merit Evaluation',
                'slug' => 'financial-aid',
                'icon' => '🏅',
                'desc' => 'Institutional Merit Scholarships, Need-Based Aid, Fee Waivers',
                'email' => 'finaid@edvora.chat',
                'phone' => '+91 98765 43212',
                'is_active' => 1,
                'is_preset' => 0
            ],
            [
                'name' => 'Campus Experience & Tours',
                'slug' => 'campus-experience',
                'icon' => '📍',
                'desc' => 'In-Person Campus Visits, Resident Hostel Experience, Facility Showcases',
                'email' => 'visit@edvora.chat',
                'phone' => '+91 98765 43213',
                'is_active' => 1,
                'is_preset' => 0
            ]
        ];

        foreach ($depts as $d) {
            $checkD = $db->prepare("SELECT id FROM departments WHERE organization_id = 33 AND slug = :slug");
            $checkD->execute([':slug' => $d['slug']]);
            $existingD = $checkD->fetch();
            if ($existingD) {
                $db->prepare("
                    UPDATE departments 
                    SET name = :name, slug = :slug, icon = :icon, description = :desc, email = :email, phone = :phone, is_active = :is_active, is_preset = :is_preset, updated_at = NOW() 
                    WHERE id = :id
                ")->execute([
                    ':name'      => $d['name'],
                    ':slug'      => $d['slug'],
                    ':icon'      => $d['icon'],
                    ':desc'      => $d['desc'],
                    ':email'     => $d['email'],
                    ':phone'     => $d['phone'],
                    ':is_active' => $d['is_active'],
                    ':is_preset' => $d['is_preset'],
                    ':id'        => $existingD['id']
                ]);
            } else {
                $db->prepare("
                    INSERT INTO departments (organization_id, name, slug, icon, description, email, phone, is_active, enable_dedicated_widget, is_preset, created_at, updated_at)
                    VALUES (33, :name, :slug, :icon, :desc, :email, :phone, :is_active, 1, :is_preset, NOW(), NOW())
                ")->execute([
                    ':name'      => $d['name'],
                    ':slug'      => $d['slug'],
                    ':icon'      => $d['icon'],
                    ':desc'      => $d['desc'],
                    ':email'     => $d['email'],
                    ':phone'     => $d['phone'],
                    ':is_active' => $d['is_active'],
                    ':is_preset' => $d['is_preset']
                ]);
            }
        }

        // Get department IDs for foreign keys (restricted strictly to the 9 default demo departments)
        $defaultSlugs = ['mba-admissions', 'undergrad_admissions', 'financial_aid', 'student_affairs', 'governance', 'placements', 'engineering-tech', 'financial-aid', 'campus-experience'];
        $inSlugs = "'" . implode("','", $defaultSlugs) . "'";
        $deptIds = $db->query("SELECT id FROM departments WHERE organization_id = 33 AND is_active = 1 AND slug IN ($inSlugs) ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
        $primaryDeptId = $deptIds[0] ?? null;

        // 5. Ensure 8 Staff Members for Org 33 (1 Owner + 7 Staff/Admins)
        $staffUsers = [
            ['name' => 'Sarah Jenkins', 'email' => 'sarah.jenkins@edvora.chat', 'role' => 'admin'],
            ['name' => 'Marcus Chen', 'email' => 'marcus.chen@edvora.chat', 'role' => 'staff'],
            ['name' => 'Elena Rostova', 'email' => 'elena.rostova@edvora.chat', 'role' => 'staff'],
            ['name' => 'David Miller', 'email' => 'david.miller@edvora.chat', 'role' => 'staff'],
            ['name' => 'Priya Sharma', 'email' => 'priya.sharma@edvora.chat', 'role' => 'staff'],
            ['name' => 'Rahul Verma', 'email' => 'rahul.verma@edvora.chat', 'role' => 'staff'],
            ['name' => 'Aisha Patel', 'email' => 'aisha.patel@edvora.chat', 'role' => 'staff']
        ];

        $defaultPass = password_hash('EdvoraStaff_2026!', PASSWORD_BCRYPT);
        foreach ($staffUsers as $su) {
            $chkU = $db->prepare("SELECT id FROM users WHERE email = :email");
            $chkU->execute([':email' => $su['email']]);
            $exU = $chkU->fetch();
            if (!$exU) {
                $db->prepare("
                    INSERT INTO users (organization_id, name, email, password_hash, role, can_manage_structure, email_verified_at, created_at, updated_at)
                    VALUES (33, :name, :email, :pass, :role, 1, NOW(), NOW(), NOW())
                ")->execute([
                    ':name'  => $su['name'],
                    ':email' => $su['email'],
                    ':pass'  => $defaultPass,
                    ':role'  => $su['role']
                ]);
            } else {
                $db->prepare("UPDATE users SET organization_id = 33, name = :name, role = :role, updated_at = NOW() WHERE id = :id")
                   ->execute([':name' => $su['name'], ':role' => $su['role'], ':id' => $exU['id']]);
            }
        }

        $userIds = $db->query("SELECT id FROM users WHERE organization_id = 33 ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);

        // 6. Ensure 2 Active Chatbots for Org 33
        // Bot 1: token c074a1862919e240ee863231a67701dc (Preserve!)
        $db->prepare("
            UPDATE chatbots 
            SET name = 'AI Admissions Assistant', is_active = 1, updated_at = NOW() 
            WHERE organization_id = 33 AND bot_token = 'c074a1862919e240ee863231a67701dc'
        ")->execute();

        // Bot 2: Scholarship & Financial Aid Assistant
        $chkBot2 = $db->prepare("SELECT id FROM chatbots WHERE organization_id = 33 AND bot_token = 'f1a842b10931e5f884a29810dc372991'");
        $chkBot2->execute();
        if (!$chkBot2->fetch()) {
            $db->prepare("
                INSERT INTO chatbots (organization_id, name, bot_token, primary_color, secondary_color, header_subtitle, is_active, created_at, updated_at)
                VALUES (33, 'Financial Aid & Scholarship Assistant', 'f1a842b10931e5f884a29810dc372991', '#063D3B', '#38BDF8', 'Online • Financial Aid Desk', 1, NOW(), NOW())
            ")->execute();
        }

        $botIds = $db->query("SELECT id FROM chatbots WHERE organization_id = 33 ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
        $primaryBotId = $botIds[0] ?? 33;

        // 7. Ensure Exact 48 Knowledge Sources (40 active, 6 expiring soon, 2 expired)
        echo "--> Backfilling 48 institutional knowledge sources (40 active, 6 expiring, 2 expired)...\n";
        $db->exec("DELETE FROM knowledge_sources WHERE organization_id = 33");

        $docTemplates = [
            // 40 Active Documents
            ['title' => 'Fall 2026 Academic Catalog & Course Directory', 'status' => 'active', 'cat' => 'Admissions', 'exp' => '+180 days'],
            ['title' => 'MBA Admissions Criteria & Work Experience Guidelines', 'status' => 'active', 'cat' => 'Admissions', 'exp' => '+180 days'],
            ['title' => 'B.Tech Computer Science & AI Curriculum Structure', 'status' => 'active', 'cat' => 'Admissions', 'exp' => '+180 days'],
            ['title' => 'School of Engineering Lab Safety & Research Policies', 'status' => 'active', 'cat' => 'Academics', 'exp' => '+180 days'],
            ['title' => 'Merit Scholarship Slabs & Board Cutoff Matrix 2026', 'status' => 'active', 'cat' => 'Financial Aid', 'exp' => '+180 days'],
            ['title' => 'Need-Based Bursary & Fee Concession Regulations', 'status' => 'active', 'cat' => 'Financial Aid', 'exp' => '+180 days'],
            ['title' => 'Campus Hostel Accommodation & Mess Tariff 2026', 'status' => 'active', 'cat' => 'Campus Life', 'exp' => '+180 days'],
            ['title' => 'International Student Visa, FRO & Healthcare Protocol', 'status' => 'active', 'cat' => 'Admissions', 'exp' => '+180 days'],
            ['title' => 'Corporate Placement Report & Highest Salary Packages 2025', 'status' => 'active', 'cat' => 'Placements', 'exp' => '+180 days'],
            ['title' => 'Credit Transfer & Academic Bank of Credits (ABC) Guide', 'status' => 'active', 'cat' => 'Academics', 'exp' => '+180 days'],
            ['title' => 'Sports Merit Scholarships & Athlete Quota Norms', 'status' => 'active', 'cat' => 'Financial Aid', 'exp' => '+180 days'],
            ['title' => 'Student Health Insurance & On-Campus Medical Center SOP', 'status' => 'active', 'cat' => 'Campus Life', 'exp' => '+180 days'],
            ['title' => 'Library Digital Databases & Research Journal Access Guidelines', 'status' => 'active', 'cat' => 'Academics', 'exp' => '+180 days'],
            ['title' => 'Entrepreneurship Incubation Cell & Seed Grant Policy', 'status' => 'active', 'cat' => 'Campus Life', 'exp' => '+180 days'],
            ['title' => 'B.Tech Data Science Specialization Electives Syllabus', 'status' => 'active', 'cat' => 'Academics', 'exp' => '+180 days'],
            ['title' => 'Global Exchange Programs & Dual Degree Agreements (USA/EU)', 'status' => 'active', 'cat' => 'Admissions', 'exp' => '+180 days'],
            ['title' => 'Code of Student Conduct & Anti-Ragging Guidelines 2026', 'status' => 'active', 'cat' => 'Campus Life', 'exp' => '+180 days'],
            ['title' => 'Tuition Installment Payment Options & Banking Partners', 'status' => 'active', 'cat' => 'Financial Aid', 'exp' => '+180 days'],
            ['title' => 'Campus Shuttle Routes & Student Transport Fee Schedule', 'status' => 'active', 'cat' => 'Campus Life', 'exp' => '+180 days'],
            ['title' => 'Doctoral (Ph.D.) Research Fellowship & Entrance Test Syllabus', 'status' => 'active', 'cat' => 'Admissions', 'exp' => '+180 days'],
            ['title' => 'Executive Management Certificate Programs Overview', 'status' => 'active', 'cat' => 'Admissions', 'exp' => '+180 days'],
            ['title' => 'M.Tech Artificial Intelligence Course Schedule & Prerequisites', 'status' => 'active', 'cat' => 'Academics', 'exp' => '+180 days'],
            ['title' => 'Alumni Mentorship Network & Placement Referral Handbook', 'status' => 'active', 'cat' => 'Placements', 'exp' => '+180 days'],
            ['title' => 'Women in STEM Excellence Fellowship Details 2026', 'status' => 'active', 'cat' => 'Financial Aid', 'exp' => '+180 days'],
            ['title' => 'Robotics & Automation Laboratory Access & Training Manual', 'status' => 'active', 'cat' => 'Academics', 'exp' => '+180 days'],
            ['title' => 'Foreign Language Electives (German, Japanese, French) Guide', 'status' => 'active', 'cat' => 'Academics', 'exp' => '+180 days'],
            ['title' => 'Campus Security & Biometric Access Protocol for Residents', 'status' => 'active', 'cat' => 'Campus Life', 'exp' => '+180 days'],
            ['title' => 'Internship Mandatory Guidelines & Summer Project Credits', 'status' => 'active', 'cat' => 'Placements', 'exp' => '+180 days'],
            ['title' => 'Cafeteria Hygiene Standards & Meal Plan Options', 'status' => 'active', 'cat' => 'Campus Life', 'exp' => '+180 days'],
            ['title' => 'Early Decision Round 1 Timeline & Scholarship Advantages', 'status' => 'active', 'cat' => 'Admissions', 'exp' => '+180 days'],
            ['title' => 'Defense Personnel Ward Fee Concession Scheme 2026', 'status' => 'active', 'cat' => 'Financial Aid', 'exp' => '+180 days'],
            ['title' => 'Cloud Computing & DevOps Certification Track Syllabus', 'status' => 'active', 'cat' => 'Academics', 'exp' => '+180 days'],
            ['title' => 'Campus Tour Booking Schedule & Parent Information Kit', 'status' => 'active', 'cat' => 'Admissions', 'exp' => '+180 days'],
            ['title' => 'Green Campus Initiatives & Student Sustainability Council', 'status' => 'active', 'cat' => 'Campus Life', 'exp' => '+180 days'],
            ['title' => 'Undergraduate Admissions FAQs & Document Verification Checklist', 'status' => 'active', 'cat' => 'Admissions', 'exp' => '+180 days'],
            ['title' => 'Cybersecurity & Ethical Hacking Minor Degree Syllabus', 'status' => 'active', 'cat' => 'Academics', 'exp' => '+180 days'],
            ['title' => 'Late Registration Fee Waiver Guidelines & Request Protocol', 'status' => 'active', 'cat' => 'Admissions', 'exp' => '+180 days'],
            ['title' => 'Differently-Abled Student Support Services & Infrastructure Map', 'status' => 'active', 'cat' => 'Campus Life', 'exp' => '+180 days'],
            ['title' => 'Summer School International Faculty Immersion Series', 'status' => 'active', 'cat' => 'Academics', 'exp' => '+180 days'],
            ['title' => 'Direct Admission Scheme for Single Girl Child Policy', 'status' => 'active', 'cat' => 'Financial Aid', 'exp' => '+180 days'],

            // 6 Expiring Soon Documents (expires in 5 to 20 days)
            ['title' => 'Phase 1 Early Bird Merit Scholarship Discount Slab (Expiring)', 'status' => 'expiring_soon', 'cat' => 'Financial Aid', 'exp' => '+8 days'],
            ['title' => 'Round 1 Seat Acceptance Fee Deposit Deadline Notice', 'status' => 'expiring_soon', 'cat' => 'Admissions', 'exp' => '+11 days'],
            ['title' => 'Special NRI Quota Advance Seat Reservation Circular', 'status' => 'expiring_soon', 'cat' => 'Admissions', 'exp' => '+14 days'],
            ['title' => 'Monsoon Hostel Room Selection & Advance Deposit Order', 'status' => 'expiring_soon', 'cat' => 'Campus Life', 'exp' => '+18 days'],
            ['title' => 'Entrance Test Mock Exam Slot Registration Window', 'status' => 'expiring_soon', 'cat' => 'Admissions', 'exp' => '+22 days'],
            ['title' => 'Alumni Sibling Tuition Rebate Phase 1 Cutoff', 'status' => 'expiring_soon', 'cat' => 'Financial Aid', 'exp' => '+25 days'],

            // 2 Expired Documents (expired in past)
            ['title' => 'Spring 2026 Lateral Entry Admission Guidelines (Archived)', 'status' => 'expired', 'cat' => 'Admissions', 'exp' => '-30 days'],
            ['title' => 'Academic Year 2025 Examination Timetable & Grace Marks Policy', 'status' => 'expired', 'cat' => 'Academics', 'exp' => '-60 days']
        ];

        $stmtDoc = $db->prepare("
            INSERT INTO knowledge_sources (
                organization_id, chatbot_id, type, title, category, academic_version, 
                effective_from, expires_on, last_reviewed_at, review_frequency_days, 
                raw_content, processed_content, keywords, status, created_at, updated_at
            ) VALUES (
                33, :bot_id, 'document', :title, :cat, '2026.1',
                DATE_SUB(CURDATE(), INTERVAL 60 DAY), :exp_date, CURDATE(), 180,
                :content1, :content2, :keywords, :status, NOW(), NOW()
            )
        ");

        foreach ($docTemplates as $dt) {
            $expDate = date('Y-m-d', strtotime($dt['exp']));
            $rawContent = "Comprehensive official policy regarding {$dt['title']} for Apex Institute of Higher Education academic cycle 2026-2027.";
            $keywords = strtolower(str_replace(' ', ', ', $dt['title']));

            $stmtDoc->execute([
                ':bot_id'   => $primaryBotId,
                ':title'    => $dt['title'],
                ':cat'      => $dt['cat'],
                ':exp_date' => $expDate,
                ':content1' => $rawContent,
                ':content2' => $rawContent,
                ':keywords' => $keywords,
                ':status'   => $dt['status']
            ]);
        }

        // 8. Ensure Lead Assets (5 downloadable brochures & guides)
        $db->exec("DELETE FROM lead_assets WHERE organization_id = 33");
        $leadAssets = [
            ['title' => 'Official Admissions Prospectus 2026-27', 'cat' => 'brochure', 'downloads' => 380, 'trigger' => 'prospectus_request'],
            ['title' => 'Merit Scholarship Matrix & Eligibility Guide', 'cat' => 'scholarship_guide', 'downloads' => 248, 'trigger' => 'scholarship_interest'],
            ['title' => 'B.Tech & AI Placement Intelligence Report', 'cat' => 'placement_report', 'downloads' => 164, 'trigger' => 'placement_inquiry'],
            ['title' => 'Campus Virtual Tour & Residence Handbook', 'cat' => 'hostel_guide', 'downloads' => 112, 'trigger' => 'campus_tour'],
            ['title' => 'MBA Global Immersion & Executive Electives', 'cat' => 'curriculum', 'downloads' => 50, 'trigger' => 'mba_curriculum']
        ];

        $stmtAsset = $db->prepare("
            INSERT INTO lead_assets (
                organization_id, department_id, title, category, description,
                file_path, file_name, file_size_bytes, mime_type, lead_intent_trigger,
                is_active, downloads_count, created_at, updated_at
            ) VALUES (
                33, :dept_id, :title, :cat, :desc,
                :path, :fname, 2450120, 'application/pdf', :trigger,
                1, :downloads, DATE_SUB(NOW(), INTERVAL 28 DAY), NOW()
            )
        ");

        foreach ($leadAssets as $la) {
            $stmtAsset->execute([
                ':dept_id'   => $primaryDeptId,
                ':title'     => $la['title'],
                ':cat'       => $la['cat'],
                ':desc'      => "Official high-resolution guide for prospective applicants: {$la['title']}",
                ':path'      => '/storage/assets/' . strtolower(str_replace(' ', '_', $la['title'])) . '.pdf',
                ':fname'     => strtolower(str_replace(' ', '_', $la['title'])) . '.pdf',
                ':trigger'   => $la['trigger'],
                ':downloads' => $la['downloads']
            ]);
        }

        // 8.1 Ensure Department Staff, Knowledge Sources, and FAQs are Mapped for all 9 departments
        echo "--> Mapping staff, knowledge sources, and starter FAQs for all 9 departments of Org 33...\n";
        $allDeptRows = $db->query("SELECT id, name, slug FROM departments WHERE organization_id = 33 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

        // A. Staff Assignment (2 staff members per department)
        $db->exec("DELETE FROM department_staff WHERE department_id IN (SELECT id FROM departments WHERE organization_id = 33)");
        $stmtStaffAssign = $db->prepare("INSERT IGNORE INTO department_staff (department_id, user_id, role, is_on_duty, created_at) VALUES (?, ?, ?, 1, NOW())");

        if (!empty($userIds)) {
            foreach ($allDeptRows as $idx => $deptRow) {
                $dId = (int)$deptRow['id'];
                $u1 = $userIds[$idx % count($userIds)];
                $u2 = $userIds[($idx + 1) % count($userIds)];
                $stmtStaffAssign->execute([$dId, $u1, 'lead']);
                if ($u1 !== $u2) {
                    $stmtStaffAssign->execute([$dId, $u2, 'agent']);
                }
            }
        }

        // B. Knowledge Source Mapping (distribute 48 docs across 9 departments)
        $db->exec("DELETE FROM department_knowledge WHERE department_id IN (SELECT id FROM departments WHERE organization_id = 33)");
        $allKsIds = $db->query("SELECT id FROM knowledge_sources WHERE organization_id = 33 ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
        $stmtKsAssign = $db->prepare("INSERT IGNORE INTO department_knowledge (department_id, knowledge_source_id) VALUES (?, ?)");

        if (!empty($allKsIds) && !empty($allDeptRows)) {
            foreach ($allKsIds as $kIdx => $ksId) {
                $targetDeptId = $allDeptRows[$kIdx % count($allDeptRows)]['id'];
                $stmtKsAssign->execute([$targetDeptId, $ksId]);
            }
        }

        // C. Starter FAQs per department
        $db->exec("DELETE FROM department_faqs WHERE department_id IN (SELECT id FROM departments WHERE organization_id = 33)");
        $stmtFaq = $db->prepare("INSERT INTO department_faqs (department_id, question, answer, sort_order, created_at) VALUES (?, ?, ?, ?, NOW())");
        foreach ($allDeptRows as $deptRow) {
            $dId = (int)$deptRow['id'];
            $dName = $deptRow['name'];
            $stmtFaq->execute([$dId, "What are the eligibility and intake criteria for {$dName}?", "Key requirements include official academic transcripts, minimum cutoff criteria, and completed application forms.", 1]);
            $stmtFaq->execute([$dId, "What are the fee installments and financial aid policies for {$dName}?", "Tuition fees can be paid in semester-wise installments, and institutional merit scholarships are available for eligible candidates.", 2]);
            $stmtFaq->execute([$dId, "How do I schedule a 1-on-1 counseling session with {$dName}?", "You can request a prioritized counselor callback directly inside this chat or book a physical campus visit slot.", 3]);
        }


        // 9. Backfill Leads, Counselor Callbacks, Campus Tours, Conversations, and Journey Events
        $currentLeads30d = (int)$db->query("SELECT COUNT(*) FROM leads WHERE organization_id = 33 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
        if ($currentLeads30d < 1284) {
            echo "--> Backfilling 1,284 leads with authentic timestamps across 30d, 7d, and 24h...\n";

            // Clean existing sample records for 33 to guarantee exact metric alignments
            $db->exec("DELETE FROM lead_journey_events WHERE organization_id = 33");
            $db->exec("DELETE FROM counselor_callbacks WHERE organization_id = 33");
            $db->exec("DELETE FROM campus_tour_bookings WHERE organization_id = 33");
            $db->exec("DELETE FROM messages WHERE organization_id = 33");
            $db->exec("DELETE FROM conversations WHERE organization_id = 33");
            $db->exec("DELETE FROM leads WHERE organization_id = 33");

            $firstNames = ['Aarav', 'Ananya', 'Rohan', 'Sneha', 'Vikram', 'Isha', 'Aditya', 'Meera', 'Kabir', 'Rhea', 'Arjun', 'Diya', 'Karan', 'Pooja', 'Nikhil', 'Tanvi', 'Siddharth', 'Avni', 'Varun', 'Shreya'];
            $lastNames = ['Sharma', 'Verma', 'Patel', 'Reddy', 'Gupta', 'Iyer', 'Singh', 'Chopra', 'Nair', 'Deshmukh', 'Mukherjee', 'Bose', 'Kapoor', 'Menon', 'Joshi', 'Bhat', 'Rao', 'Mehta', 'Das', 'Sen'];
            $programs = ['B.Tech Computer Science & Engineering', 'Master of Business Administration (MBA)', 'B.Tech AI & Data Science', 'BBA in Fintech', 'M.Tech Robotics', 'Ph.D. in Computer Engineering'];

            $insertLeadStmt = $db->prepare("
                INSERT INTO leads (
                    organization_id, chatbot_id, department_id, assigned_user_id,
                    name, email, phone, program_interest, academic_score, scholarship_tier,
                    status, pipeline_stage, conversion_score, created_at, updated_at
                ) VALUES (
                    33, :bot_id, :dept_id, :user_id,
                    :name, :email, :phone, :program, :score, :scholarship,
                    :status, :stage, :conv_score, :created_at, :updated_at
                )
            ");

            $generateLeads = function(int $count, int $qualifiedCount, int $appIntentCount, int $scholarshipCount, float $minHours, float $maxHours) 
                use ($insertLeadStmt, $firstNames, $lastNames, $programs, $deptIds, $userIds, $primaryBotId) 
            {
                for ($i = 0; $i < $count; $i++) {
                    $hoursAgo = $minHours + (($maxHours - $minHours) * ($i / max(1, $count - 1)));
                    $createdAt = date('Y-m-d H:i:s', time() - (int)round($hoursAgo * 3600));

                    $isAppIntent = ($i < $appIntentCount);
                    $isQualified = $isAppIntent || ($i < $qualifiedCount);
                    $hasScholarship = ($i < $scholarshipCount);

                    $stage = 'new';
                    $status = 'new';
                    $convScore = 45;

                    if ($isAppIntent) {
                        $stage = 'application';
                        $status = 'contacted';
                        $convScore = 92;
                    } elseif ($isQualified) {
                        $stages = ['qualified', 'campus_visit', 'decision'];
                        $stage = $stages[$i % count($stages)];
                        $status = 'contacted';
                        $convScore = 78;
                    }

                    $fn = $firstNames[$i % count($firstNames)];
                    $ln = $lastNames[($i * 3) % count($lastNames)];
                    $name = "{$fn} {$ln}";
                    $email = strtolower("{$fn}.{$ln}{$i}@example.com");
                    $phone = '+91 9' . str_pad((string)(800000000 + $i), 9, '0', STR_PAD_LEFT);
                    $prog = $programs[$i % count($programs)];
                    $deptId = $deptIds[$i % count($deptIds)];
                    $userId = $userIds[$i % count($userIds)];
                    $schTier = $hasScholarship ? (($i % 3 === 0) ? 'Dean Merit 40%' : (($i % 2 === 0) ? 'Chairman Honors 25%' : 'Founder Merit 15%')) : null;

                    $insertLeadStmt->execute([
                        ':bot_id'      => $primaryBotId,
                        ':dept_id'     => $deptId,
                        ':user_id'     => $userId,
                        ':name'        => $name,
                        ':email'       => $email,
                        ':phone'       => $phone,
                        ':program'     => $prog,
                        ':score'       => ($hasScholarship ? '94.5% CBSE' : '82.0% State'),
                        ':scholarship' => $schTier,
                        ':status'      => $status,
                        ':stage'       => $stage,
                        ':conv_score'  => $convScore,
                        ':created_at'  => $createdAt,
                        ':updated_at'  => $createdAt
                    ]);
                }
            };

            // 1. Last 24 Hours: 92 leads (38 qualified, 9 app intents, 46 scholarships)
            $generateLeads(92, 38, 9, 46, 0.5, 23.5);

            // 2. Days 1 to 7: 292 leads (104 qualified, 19 app intents, 139 scholarships) -> 7d total = 384 leads (142 qualified, 28 app intents, 185 scholarships)
            $generateLeads(292, 104, 19, 139, 24.5, 167.5);

            // 3. Days 7 to 30: 900 leads (344 qualified, 66 app intents, 443 scholarships) -> 30d total = 1,284 leads (486 qualified, 94 app intents, 628 scholarships)
            $generateLeads(900, 344, 66, 443, 168.5, 719.5);

            // 4. Prior 30 Days (Days 31 to 60): 1,082 leads (giving +18.7% MoM delta)
            $generateLeads(1082, 409, 71, 529, 721.0, 1439.0);

            echo "✓ 1,284 leads + 1,082 prior period leads inserted successfully.\n";

            // 10. Counselor Callbacks
            echo "--> Backfilling counselor callbacks (412 total in 30d, 118 in 7d, 28 in 24h)...\n";
            $cbStmt = $db->prepare("
                INSERT INTO counselor_callbacks (
                    organization_id, chatbot_id, department_id, assigned_user_id,
                    student_name, student_phone, student_email, preferred_time_slot,
                    topic_or_query, status, scheduled_at, created_at, updated_at
                ) VALUES (
                    33, :bot_id, :dept_id, :user_id,
                    :name, :phone, :email, 'Immediate (ASAP)',
                    'Needs urgent consultation on MBA fee schedule and scholarship evaluation.',
                    :status, :sched_at, :created_at, :updated_at
                )
            ");

            $generateCallbacks = function(int $count, float $minH, float $maxH) use ($cbStmt, $firstNames, $lastNames, $deptIds, $userIds, $primaryBotId) {
                for ($i = 0; $i < $count; $i++) {
                    $h = $minH + (($maxH - $minH) * ($i / max(1, $count - 1)));
                    $dt = date('Y-m-d H:i:s', time() - (int)round($h * 3600));
                    $fn = $firstNames[($i * 2) % count($firstNames)];
                    $ln = $lastNames[($i * 5) % count($lastNames)];
                    $st = ($i % 3 === 0) ? 'completed' : (($i % 2 === 0) ? 'scheduled' : 'pending');

                    $cbStmt->execute([
                        ':bot_id'     => $primaryBotId,
                        ':dept_id'    => $deptIds[$i % count($deptIds)],
                        ':user_id'    => $userIds[$i % count($userIds)],
                        ':name'       => "{$fn} {$ln}",
                        ':phone'      => '+91 9' . str_pad((string)(700000000 + $i), 9, '0', STR_PAD_LEFT),
                        ':email'      => strtolower("{$fn}.{$ln}.cb{$i}@example.com"),
                        ':status'     => $st,
                        ':sched_at'   => date('Y-m-d H:i:s', strtotime($dt) + 14400),
                        ':created_at' => $dt,
                        ':updated_at' => $dt
                    ]);
                }
            };

            $generateCallbacks(28, 0.5, 23.5);     // 24h: 28
            $generateCallbacks(90, 24.5, 167.5);   // 7d: 118 total
            $generateCallbacks(294, 168.5, 719.5); // 30d: 412 total
            $generateCallbacks(365, 721.0, 1439.0);// Prior 30d: 365

            // 11. Campus Tour Bookings
            echo "--> Backfilling campus tours (186 total in 30d, 52 in 7d, 14 in 24h)...\n";
            $tourStmt = $db->prepare("
                INSERT INTO campus_tour_bookings (
                    organization_id, chatbot_id, department_id, assigned_user_id,
                    student_name, student_email, student_phone, preferred_date, preferred_time,
                    program_interest, group_size, status, created_at, updated_at
                ) VALUES (
                    33, :bot_id, :dept_id, :user_id,
                    :name, :email, :phone, :pref_date, 'Morning',
                    'B.Tech AI & Robotics', 2, :status, :created_at, :updated_at
                )
            ");

            $generateTours = function(int $count, float $minH, float $maxH) use ($tourStmt, $firstNames, $lastNames, $deptIds, $userIds, $primaryBotId) {
                for ($i = 0; $i < $count; $i++) {
                    $h = $minH + (($maxH - $minH) * ($i / max(1, $count - 1)));
                    $dt = date('Y-m-d H:i:s', time() - (int)round($h * 3600));
                    $fn = $firstNames[($i * 4) % count($firstNames)];
                    $ln = $lastNames[($i * 2) % count($lastNames)];
                    $st = ($i % 3 === 0) ? 'completed' : 'confirmed';

                    $tourStmt->execute([
                        ':bot_id'     => $primaryBotId,
                        ':dept_id'    => $deptIds[$i % count($deptIds)],
                        ':user_id'    => $userIds[$i % count($userIds)],
                        ':name'       => "{$fn} {$ln}",
                        ':email'      => strtolower("{$fn}.{$ln}.tour{$i}@example.com"),
                        ':phone'      => '+91 9' . str_pad((string)(600000000 + $i), 9, '0', STR_PAD_LEFT),
                        ':pref_date'  => date('Y-m-d', strtotime($dt) + 259200),
                        ':status'     => $st,
                        ':created_at' => $dt,
                        ':updated_at' => $dt
                    ]);
                }
            };

            $generateTours(14, 0.5, 23.5);     // 24h: 14
            $generateTours(38, 24.5, 167.5);   // 7d: 52 total
            $generateTours(134, 168.5, 719.5); // 30d: 186 total
            $generateTours(162, 721.0, 1439.0);// Prior 30d: 162

            // 12. Lead-Magnet Events (Dispatched Guides)
            echo "--> Backfilling lead magnet dispatch events (954 total in 30d, 280 in 7d, 72 in 24h)...\n";
            $evtStmt = $db->prepare("
                INSERT INTO lead_journey_events (
                    organization_id, lead_id, event_type, event_title, event_description, created_at
                ) VALUES (
                    33, :lead_id, 'lead_magnet_sent', 'Official Guide PDF Dispatched via Chatbot',
                    'Instant PDF prospectus generated and delivered to applicant device.', :created_at
                )
            ");

            $topLeadIds = $db->query("SELECT id FROM leads WHERE organization_id = 33 ORDER BY id ASC LIMIT 954")->fetchAll(PDO::FETCH_COLUMN);
            $lCount = count($topLeadIds);

            $generateEvents = function(int $count, float $minH, float $maxH, int $offset) use ($evtStmt, $topLeadIds, $lCount) {
                for ($i = 0; $i < $count; $i++) {
                    $h = $minH + (($maxH - $minH) * ($i / max(1, $count - 1)));
                    $dt = date('Y-m-d H:i:s', time() - (int)round($h * 3600));
                    $leadId = $topLeadIds[($offset + $i) % $lCount] ?? 1;

                    $evtStmt->execute([
                        ':lead_id'    => $leadId,
                        ':created_at' => $dt
                    ]);
                }
            };

            $generateEvents(72, 0.5, 23.5, 0);       // 24h: 72
            $generateEvents(208, 24.5, 167.5, 72);   // 7d: 280 total
            $generateEvents(674, 168.5, 719.5, 280); // 30d: 954 total

            // 13. Omnichannel Conversations
            echo "--> Backfilling conversations across time windows for exact conversion ratios...\n";

            $convStmt = $db->prepare("
                INSERT INTO conversations (
                    organization_id, chatbot_id, department_id, visitor_id,
                    page_url, page_title, is_test, started_at, last_message_at
                ) VALUES (
                    33, :bot_id, :dept_id, :visitor_id,
                    'https://edvora.chat/', 'Apex Institute Admissions', 0, :started_at, :last_message_at
                )
            ");

            $db->beginTransaction();
            $generateConv = function(int $count, float $minH, float $maxH) use ($convStmt, $deptIds, $primaryBotId) {
                for ($i = 0; $i < $count; $i++) {
                    $h = $minH + (($maxH - $minH) * ($i / max(1, $count - 1)));
                    $dt = date('Y-m-d H:i:s', time() - (int)round($h * 3600));
                    $visId = 'vis_' . md5("conv_{$h}_{$i}");

                    $convStmt->execute([
                        ':bot_id'          => $primaryBotId,
                        ':dept_id'         => $deptIds[$i % count($deptIds)],
                        ':visitor_id'      => $visId,
                        ':started_at'      => $dt,
                        ':last_message_at' => $dt
                    ]);
                }
            };

            // 24h: 648 (92 / 648 = 14.2% V2L)
            $generateConv(648, 0.5, 23.5);
            // 7d: 2,196 additional (total in 7d = 2,844 -> 384 / 2844 = 13.5% V2L)
            $generateConv(2196, 24.5, 167.5);
            // 30d: 7,187 additional (total in 30d = 10,031 -> 1284 / 10031 = 12.8% V2L)
            $generateConv(7187, 168.5, 719.5);
            // Prior 30d: 9,836 (1082 / 9836 = 11.0% V2L -> +2.4% delta)
            $generateConv(9836, 721.0, 1439.0);

            $db->commit();
            echo "✓ Conversations populated successfully.\n";
        }

        echo "✓ BackfillOverviewData completed successfully for Organization 33!\n";
    }
}
