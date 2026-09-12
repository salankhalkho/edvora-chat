<?php

namespace App\Database;

use PDO;
use Throwable;

class DepartmentPresets
{
    /**
     * Returns 14 pre-built department template configurations.
     */
    public static function getPresets(): array
    {
        return [
            [
                'slug' => 'mba-admissions',
                'name' => 'MBA Admissions',
                'icon' => '🎓',
                'description' => 'Handles eligibility criteria, application guidelines, deadlines, and entrance exam inquiries.',
                'email' => 'admissions@college.edu',
                'phone' => '+91 98765 43210',
                'whatsapp' => '+919876543210',
                'greeting_message' => 'I can help with MBA Admissions. Would you like information about eligibility, fees, scholarships, or the application process?',
                'working_hours' => [
                    'mon' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'tue' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'wed' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'thu' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'fri' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'sat' => ['open' => '09:00', 'close' => '13:00', 'active' => true],
                    'sun' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                ],
                'escalation_rules' => [
                    'max_unresolved_turns' => 2,
                    'notify_email' => true,
                    'notify_whatsapp' => true,
                ],
                'lead_assignment_rules' => [
                    'method' => 'round_robin',
                ],
                'faqs' => [
                    ['question' => 'What is the eligibility for MBA?', 'answer' => 'Bachelor degree with minimum 50% aggregate and valid CAT/MAT/XAT score.'],
                    ['question' => 'What are the application deadlines?', 'answer' => 'Round 1 applications close on December 15. Round 2 closes March 30.'],
                    ['question' => 'Which entrance exams are accepted?', 'answer' => 'We accept CAT, XAT, CMAT, MAT, and GMAT scores.'],
                ]
            ],
            [
                'slug' => 'fees-finance',
                'name' => 'Fees & Finance',
                'icon' => '💰',
                'description' => 'Tuition fee structure, installment plans, education loans, EMI options, and online payments.',
                'email' => 'finance@college.edu',
                'phone' => '+91 98765 43211',
                'whatsapp' => '+919876543211',
                'greeting_message' => 'I can assist with Fees & Payments. Are you looking for fee structures, EMI options, or online payment details?',
                'working_hours' => [
                    'mon' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'tue' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'wed' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'thu' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'fri' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'sat' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                    'sun' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                ],
                'escalation_rules' => [
                    'max_unresolved_turns' => 2,
                    'notify_email' => true,
                    'notify_whatsapp' => false,
                ],
                'lead_assignment_rules' => [
                    'method' => 'round_robin',
                ],
                'faqs' => [
                    ['question' => 'Can I pay fees in installments?', 'answer' => 'Yes, tuition fees can be paid in 2 or 4 equal semester installments.'],
                    ['question' => 'Do you provide education loan assistance?', 'answer' => 'Yes, we have tie-ups with leading nationalized banks for fast-track loan sanctions.'],
                ]
            ],
            [
                'slug' => 'application-support',
                'name' => 'Application Support',
                'icon' => '📝',
                'description' => 'Help with online application form filling, document upload errors, and status tracking.',
                'email' => 'helpdesk@college.edu',
                'phone' => '+91 98765 43212',
                'whatsapp' => '+919876543212',
                'greeting_message' => 'I can guide your application process. Do you need help filling out forms or checking application status?',
                'working_hours' => [
                    'mon' => ['open' => '09:00', 'close' => '18:00', 'active' => true],
                    'tue' => ['open' => '09:00', 'close' => '18:00', 'active' => true],
                    'wed' => ['open' => '09:00', 'close' => '18:00', 'active' => true],
                    'thu' => ['open' => '09:00', 'close' => '18:00', 'active' => true],
                    'fri' => ['open' => '09:00', 'close' => '18:00', 'active' => true],
                    'sat' => ['open' => '09:00', 'close' => '14:00', 'active' => true],
                    'sun' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                ],
                'escalation_rules' => [
                    'max_unresolved_turns' => 2,
                    'notify_email' => true,
                    'notify_whatsapp' => true,
                ],
                'lead_assignment_rules' => [
                    'method' => 'round_robin',
                ],
                'faqs' => [
                    ['question' => 'Which documents are required for application?', 'answer' => '10th & 12th marksheets, Graduation degree transcript, entrance scorecard, and ID proof.'],
                    ['question' => 'How can I check my application status?', 'answer' => 'Log in to your student application portal or share your Application Reference ID here.'],
                ]
            ],
            [
                'slug' => 'programs-academics',
                'name' => 'Programs & Academics',
                'icon' => '🏫',
                'description' => 'Specializations, PGDM, Executive MBA, curriculum details, and AICTE/UGC accreditation.',
                'email' => 'academics@college.edu',
                'phone' => '+91 98765 43213',
                'whatsapp' => '+919876543213',
                'greeting_message' => 'I can provide course details. Which program would you like to explore (MBA, PGDM, Executive MBA)?',
                'working_hours' => [
                    'mon' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'tue' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'wed' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'thu' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'fri' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'sat' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                    'sun' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                ],
                'escalation_rules' => [
                    'max_unresolved_turns' => 3,
                    'notify_email' => true,
                    'notify_whatsapp' => false,
                ],
                'lead_assignment_rules' => [
                    'method' => 'round_robin',
                ],
                'faqs' => [
                    ['question' => 'What specializations are available?', 'answer' => 'Finance, Marketing, Business Analytics, HR, Operations, and Digital Business.'],
                    ['question' => 'Is the PGDM program AICTE approved?', 'answer' => 'Yes, all our PGDM and MBA programs are AICTE approved and NBA accredited.'],
                ]
            ],
            [
                'slug' => 'hostel-housing',
                'name' => 'Hostel & Housing',
                'icon' => '🛏️',
                'description' => 'Hostel room options, mess facilities, annual fees, amenities, and campus security.',
                'email' => 'hostel@college.edu',
                'phone' => '+91 98765 43214',
                'whatsapp' => '+919876543214',
                'greeting_message' => 'I can share hostel details. Would you like info on room fees, mess menus, or availability?',
                'working_hours' => [
                    'mon' => ['open' => '08:30', 'close' => '17:30', 'active' => true],
                    'tue' => ['open' => '08:30', 'close' => '17:30', 'active' => true],
                    'wed' => ['open' => '08:30', 'close' => '17:30', 'active' => true],
                    'thu' => ['open' => '08:30', 'close' => '17:30', 'active' => true],
                    'fri' => ['open' => '08:30', 'close' => '17:30', 'active' => true],
                    'sat' => ['open' => '09:00', 'close' => '13:00', 'active' => true],
                    'sun' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                ],
                'escalation_rules' => [
                    'max_unresolved_turns' => 2,
                    'notify_email' => true,
                    'notify_whatsapp' => true,
                ],
                'lead_assignment_rules' => [
                    'method' => 'round_robin',
                ],
                'faqs' => [
                    ['question' => 'Are twin-sharing rooms available?', 'answer' => 'Yes, AC and Non-AC twin-sharing and single rooms are available.'],
                    ['question' => 'What is the hostel fee structure?', 'answer' => 'Annual hostel fee ranges from ₹95,000 to ₹1,40,000 including 4-time meals.'],
                ]
            ],
            [
                'slug' => 'international-admissions',
                'name' => 'International Admissions',
                'icon' => '🌍',
                'description' => 'Foreign national eligibility, student visa assistance, NRI quota, and international applications.',
                'email' => 'international@college.edu',
                'phone' => '+91 98765 43215',
                'whatsapp' => '+919876543215',
                'greeting_message' => 'Welcome international applicant! I can help with visa compliance, NRI quota, or English proficiency requirements.',
                'working_hours' => [
                    'mon' => ['open' => '09:00', 'close' => '18:00', 'active' => true],
                    'tue' => ['open' => '09:00', 'close' => '18:00', 'active' => true],
                    'wed' => ['open' => '09:00', 'close' => '18:00', 'active' => true],
                    'thu' => ['open' => '09:00', 'close' => '18:00', 'active' => true],
                    'fri' => ['open' => '09:00', 'close' => '18:00', 'active' => true],
                    'sat' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                    'sun' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                ],
                'escalation_rules' => [
                    'max_unresolved_turns' => 2,
                    'notify_email' => true,
                    'notify_whatsapp' => true,
                ],
                'lead_assignment_rules' => [
                    'method' => 'round_robin',
                ],
                'faqs' => [
                    ['question' => 'Is IELTS required for foreign students?', 'answer' => 'IELTS/TOEFL is waived if medium of instruction in graduation was English.'],
                    ['question' => 'Does the college issue Visa support letters?', 'answer' => 'Yes, provisional admission letter and visa recommendation letter are issued upon seat confirmation.'],
                ]
            ],
            [
                'slug' => 'placements-careers',
                'name' => 'Placements & Careers',
                'icon' => '💼',
                'description' => 'Placement statistics, highest & average CTC, recruiting partners, and internship drives.',
                'email' => 'placements@college.edu',
                'phone' => '+91 98765 43216',
                'whatsapp' => '+919876543216',
                'greeting_message' => 'Interested in career outcomes? I can show placement highlights, top recruiting companies, and average packages.',
                'working_hours' => [
                    'mon' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'tue' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'wed' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'thu' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'fri' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'sat' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                    'sun' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                ],
                'escalation_rules' => [
                    'max_unresolved_turns' => 2,
                    'notify_email' => true,
                    'notify_whatsapp' => false,
                ],
                'lead_assignment_rules' => [
                    'method' => 'round_robin',
                ],
                'faqs' => [
                    ['question' => 'What is the average placement CTC?', 'answer' => 'The average placement CTC for the latest batch was ₹8.5 LPA.'],
                    ['question' => 'Which top companies visit the campus?', 'answer' => 'Deloitte, EY, Amazon, ICICI Bank, TCS, Accenture, and Flipkart are regular recruiters.'],
                ]
            ],
            [
                'slug' => 'scholarships-aid',
                'name' => 'Scholarships & Aid',
                'icon' => '🎓',
                'description' => 'Merit-based scholarships, financial assistance, fee waivers, and sports quota scholarship criteria.',
                'email' => 'scholarships@college.edu',
                'phone' => '+91 98765 43217',
                'whatsapp' => '+919876543217',
                'greeting_message' => 'We offer several scholarships! Would you like to check eligibility for merit or need-based financial aid?',
                'working_hours' => [
                    'mon' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'tue' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'wed' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'thu' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'fri' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'sat' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                    'sun' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                ],
                'escalation_rules' => [
                    'max_unresolved_turns' => 2,
                    'notify_email' => true,
                    'notify_whatsapp' => false,
                ],
                'lead_assignment_rules' => [
                    'method' => 'round_robin',
                ],
                'faqs' => [
                    ['question' => 'Who is eligible for Merit Scholarship?', 'answer' => 'Students scoring 90%+ in graduation or 95+ percentile in CAT receive up to 50% tuition waiver.'],
                    ['question' => 'How to apply for financial assistance?', 'answer' => 'Submit income proof certificate along with scholarship application form at admissions desk.'],
                ]
            ],
            [
                'slug' => 'academics-faculty',
                'name' => 'Academics & Faculty',
                'icon' => '📚',
                'description' => 'Curriculum syllabus, faculty background, guest lectures, exam timetable, and grading system.',
                'email' => 'faculty@college.edu',
                'phone' => '+91 98765 43218',
                'whatsapp' => '+919876543218',
                'greeting_message' => 'I can answer academic queries. Need info about faculty profiles, curriculum details, or term schedules?',
                'working_hours' => [
                    'mon' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'tue' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'wed' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'thu' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'fri' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'sat' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                    'sun' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                ],
                'escalation_rules' => [
                    'max_unresolved_turns' => 3,
                    'notify_email' => true,
                    'notify_whatsapp' => false,
                ],
                'lead_assignment_rules' => [
                    'method' => 'round_robin',
                ],
                'faqs' => [
                    ['question' => 'What is the student-to-faculty ratio?', 'answer' => 'We maintain a 15:1 student-to-faculty ratio for personalized mentorship.'],
                    ['question' => 'Are faculty members industry practitioners?', 'answer' => 'Yes, over 40% of core faculty have 10+ years of corporate leadership experience.'],
                ]
            ],
            [
                'slug' => 'campus-visit',
                'name' => 'Campus Visit & Tours',
                'icon' => '📍',
                'description' => 'Campus tour booking, open house events, location directions, and visitor entry passes.',
                'email' => 'visit@college.edu',
                'phone' => '+91 98765 43219',
                'whatsapp' => '+919876543219',
                'greeting_message' => 'We\'d love to host you on campus! Would you like to schedule a physical tour or get directions?',
                'working_hours' => [
                    'mon' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'tue' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'wed' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'thu' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'fri' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'sat' => ['open' => '09:00', 'close' => '15:00', 'active' => true],
                    'sun' => ['open' => '10:00', 'close' => '14:00', 'active' => true],
                ],
                'escalation_rules' => [
                    'max_unresolved_turns' => 2,
                    'notify_email' => true,
                    'notify_whatsapp' => true,
                ],
                'lead_assignment_rules' => [
                    'method' => 'round_robin',
                ],
                'faqs' => [
                    ['question' => 'When can I visit the campus for a tour?', 'answer' => 'Guided campus tours are conducted Monday through Saturday at 11 AM and 3 PM.'],
                    ['question' => 'Where is the main entrance located?', 'answer' => 'Main Gate 1, Knowledge Park III, easily reachable via Metro Station.'],
                ]
            ],
            [
                'slug' => 'student-services',
                'name' => 'Student Services',
                'icon' => '🧑‍🎓',
                'description' => 'Enrolled student queries, transcript issuance, ID cards, bonafide certificates, and library services.',
                'email' => 'studentservices@college.edu',
                'phone' => '+91 98765 43220',
                'whatsapp' => '+919876543220',
                'greeting_message' => 'For enrolled students, I can direct you to transcript requests, ID cards, or campus services.',
                'working_hours' => [
                    'mon' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'tue' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'wed' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'thu' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'fri' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'sat' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                    'sun' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                ],
                'escalation_rules' => [
                    'max_unresolved_turns' => 2,
                    'notify_email' => true,
                    'notify_whatsapp' => false,
                ],
                'lead_assignment_rules' => [
                    'method' => 'round_robin',
                ],
                'faqs' => [
                    ['question' => 'How to apply for Bonafide Certificate?', 'answer' => 'Submit request on Student Portal under Certificates tab; issued within 24 working hours.'],
                    ['question' => 'How to replace lost ID card?', 'answer' => 'File request at Admin Desk with ₹200 fee receipt.'],
                ]
            ],
            [
                'slug' => 'corporate-relations',
                'name' => 'Corporate Relations',
                'icon' => '🤝',
                'description' => 'Industry partnerships, corporate recruitment drives, MoU agreements, and guest speaker invitations.',
                'email' => 'corporate@college.edu',
                'phone' => '+91 98765 43221',
                'whatsapp' => '+919876543221',
                'greeting_message' => 'Connecting with our corporate office? I can route your inquiry to our Placement & Partnership cell.',
                'working_hours' => [
                    'mon' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'tue' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'wed' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'thu' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'fri' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'sat' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                    'sun' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                ],
                'escalation_rules' => [
                    'max_unresolved_turns' => 2,
                    'notify_email' => true,
                    'notify_whatsapp' => true,
                ],
                'lead_assignment_rules' => [
                    'method' => 'round_robin',
                ],
                'faqs' => [
                    ['question' => 'How can companies register for campus placement?', 'answer' => 'Fill out Corporate Registration form or email corporate@college.edu with job description.'],
                ]
            ],
            [
                'slug' => 'marketing-outreach',
                'name' => 'Marketing & Outreach',
                'icon' => '📢',
                'description' => 'Webinars, info sessions, education fair participation, prospectus requests, and media contact.',
                'email' => 'marketing@college.edu',
                'phone' => '+91 98765 43222',
                'whatsapp' => '+919876543222',
                'greeting_message' => 'Stay connected! Would you like to download our latest prospectus or register for upcoming webinars?',
                'working_hours' => [
                    'mon' => ['open' => '09:00', 'close' => '18:00', 'active' => true],
                    'tue' => ['open' => '09:00', 'close' => '18:00', 'active' => true],
                    'wed' => ['open' => '09:00', 'close' => '18:00', 'active' => true],
                    'thu' => ['open' => '09:00', 'close' => '18:00', 'active' => true],
                    'fri' => ['open' => '09:00', 'close' => '18:00', 'active' => true],
                    'sat' => ['open' => '09:00', 'close' => '14:00', 'active' => true],
                    'sun' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                ],
                'escalation_rules' => [
                    'max_unresolved_turns' => 2,
                    'notify_email' => true,
                    'notify_whatsapp' => false,
                ],
                'lead_assignment_rules' => [
                    'method' => 'round_robin',
                ],
                'faqs' => [
                    ['question' => 'When is the next admissions webinar?', 'answer' => 'Join our live Virtual Open House every Saturday at 4 PM.'],
                ]
            ],
            [
                'slug' => 'alumni-relations',
                'name' => 'Alumni Network',
                'icon' => '🏛️',
                'description' => 'Alumni directory, guest mentoring, reunions, transcript services for alumni, and chapter events.',
                'email' => 'alumni@college.edu',
                'phone' => '+91 98765 43223',
                'whatsapp' => '+919876543223',
                'greeting_message' => 'Welcome back! I can connect you with alumni relations, transcript issuance, or upcoming reunions.',
                'working_hours' => [
                    'mon' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'tue' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'wed' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'thu' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'fri' => ['open' => '09:00', 'close' => '17:00', 'active' => true],
                    'sat' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                    'sun' => ['open' => '00:00', 'close' => '00:00', 'active' => false],
                ],
                'escalation_rules' => [
                    'max_unresolved_turns' => 2,
                    'notify_email' => true,
                    'notify_whatsapp' => false,
                ],
                'lead_assignment_rules' => [
                    'method' => 'round_robin',
                ],
                'faqs' => [
                    ['question' => 'How can alumni request official transcripts?', 'answer' => 'Submit request on Alumni Portal with degree copy; mailed worldwide in 5 business days.'],
                ]
            ]
        ];
    }

    /**
     * Seed preset departments into an organization by organization ID.
     */
    public static function seedPresetsForOrganization(PDO $db, int $orgId, array $selectedSlugs = []): array
    {
        $presets = self::getPresets();
        $imported = 0;
        $createdDeptId = null;

        foreach ($presets as $preset) {
            if (!empty($selectedSlugs) && !in_array($preset['slug'], $selectedSlugs)) {
                continue;
            }

            // Check if slug exists
            $stmtCheck = $db->prepare("SELECT id FROM departments WHERE organization_id = ? AND slug = ?");
            $stmtCheck->execute([$orgId, $preset['slug']]);
            if ($stmtCheck->fetch()) {
                continue; // Already exists
            }

            // Insert department in inactive mode (is_active = 0, enable_dedicated_widget = 0) until configured
            $stmtInsert = $db->prepare("INSERT INTO departments (
                organization_id, name, slug, icon, description, email, phone, whatsapp,
                working_hours, greeting_message, escalation_rules, lead_assignment_rules, is_active, enable_dedicated_widget, is_preset
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 1)");

            $stmtInsert->execute([
                $orgId,
                $preset['name'],
                $preset['slug'],
                $preset['icon'],
                $preset['description'],
                $preset['email'],
                $preset['phone'],
                $preset['whatsapp'],
                json_encode($preset['working_hours']),
                $preset['greeting_message'],
                json_encode($preset['escalation_rules']),
                json_encode($preset['lead_assignment_rules'])
            ]);

            $deptId = (int)$db->lastInsertId();
            $createdDeptId = $deptId;

            // Insert FAQs
            if (!empty($preset['faqs'])) {
                $stmtFaq = $db->prepare("INSERT INTO department_faqs (department_id, question, answer, sort_order) VALUES (?, ?, ?, ?)");
                foreach ($preset['faqs'] as $idx => $faq) {
                    $stmtFaq->execute([$deptId, $faq['question'], $faq['answer'], $idx + 1]);
                }
            }

            $imported++;
        }

        return [
            'imported_count' => $imported,
            'department_id' => $createdDeptId
        ];
    }
}
