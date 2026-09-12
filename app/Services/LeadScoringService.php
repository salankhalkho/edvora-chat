<?php

namespace App\Services;

use App\Config\Database;
use PDO;
use Throwable;

class LeadScoringService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Calculate conversion score (0-100) and rationale based on lead signals
     */
    public function computeScore(array $leadData): array
    {
        $score = 40; // Base baseline for any captured lead
        $signals = [];
        $reasons = [];

        // Program specified
        if (!empty($leadData['program_interest'])) {
            $score += 10;
            $signals[] = ['signal' => 'Program Interest Defined: ' . $leadData['program_interest'], 'level' => 'medium', 'icon' => '🎓'];
        }

        // Academic Score / Percentile
        if (!empty($leadData['academic_score'])) {
            $numericScore = (float) filter_var($leadData['academic_score'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            if ($numericScore >= 85 || stripos($leadData['academic_score'], '9') === 0) {
                $score += 20;
                $signals[] = ['signal' => 'High Academic Merit: ' . $leadData['academic_score'], 'level' => 'high', 'icon' => '🔥'];
                $reasons[] = 'Strong academic profile';
            } else {
                $score += 10;
                $signals[] = ['signal' => 'Academic Score Submitted: ' . $leadData['academic_score'], 'level' => 'medium', 'icon' => '📝'];
            }
        }

        // Scholarship interest / eligibility
        if (!empty($leadData['scholarship_tier']) || !empty($leadData['estimated_waiver_amount'])) {
            $score += 15;
            $signals[] = ['signal' => 'Scholarship Eligible / Evaluated', 'level' => 'high', 'icon' => '💰'];
            $reasons[] = 'Evaluated scholarship waiver';
        }

        // Lead type / trigger
        $leadType = $leadData['lead_type'] ?? 'general';
        if ($leadType === 'callback' || $leadType === 'counselor_callback') {
            $score += 25;
            $signals[] = ['signal' => 'Requested Counselor Callback', 'level' => 'very_high', 'icon' => '📞'];
            $reasons[] = 'Proactively requested callback';
        } elseif ($leadType === 'campus_tour') {
            $score += 25;
            $signals[] = ['signal' => 'Booked Campus Tour', 'level' => 'very_high', 'icon' => '🏫'];
            $reasons[] = 'Booked in-person campus visit';
        } elseif ($leadType === 'prospectus' || $leadType === 'lead_magnet' || $leadType === 'brochure') {
            $score += 12;
            $signals[] = ['signal' => 'Downloaded Prospectus / Lead-Magnet', 'level' => 'medium', 'icon' => '📄'];
            $reasons[] = 'Downloaded official brochure';
        }

        // Additional signals from notes / conversation if available
        $notes = strtolower($leadData['notes'] ?? '');
        if (str_contains($notes, 'fee') || str_contains($notes, 'cost') || str_contains($notes, 'tuition')) {
            $signals[] = ['signal' => 'Asked about fee structure & payments', 'level' => 'high', 'icon' => '💰'];
        }
        if (str_contains($notes, 'placement') || str_contains($notes, 'package') || str_contains($notes, 'salary')) {
            $signals[] = ['signal' => 'Asked about career placements & packages', 'level' => 'high', 'icon' => '📈'];
        }

        // Cap score between 15 and 98
        $finalScore = max(15, min(98, $score));

        // Rationale text
        $rationale = 'Moderate interest signal';
        if ($finalScore >= 85) {
            $rationale = 'High probability of application (' . implode(', ', array_slice($reasons, 0, 2)) . ')';
        } elseif ($finalScore >= 70) {
            $rationale = 'Strong candidate with verified engagement';
        } elseif ($finalScore >= 50) {
            $rationale = 'Active inquiry exploring academic programs';
        }

        return [
            'score' => $finalScore,
            'rationale' => $rationale,
            'signals' => $signals
        ];
    }

    /**
     * Determine Next Best Action for counselor/admissions team
     */
    public function determineNextBestAction(array $leadData, array $scoringResult): array
    {
        $score = $scoringResult['score'];
        $leadType = $leadData['lead_type'] ?? 'general';
        $program = $leadData['program_interest'] ?? 'Academic Programs';
        $studentName = $leadData['name'] ?? 'Candidate';

        if ($leadType === 'callback' || $score >= 85) {
            return [
                'action_type' => 'call',
                'title' => "Call {$studentName} today",
                'reason' => "High intent candidate ({$score}/100) — requested callback / high qualification.",
                'recommended_time' => 'Today (Immediate / Morning)',
                'channel' => 'phone',
                'primary_cta' => 'Call Now',
                'secondary_cta' => 'Send WhatsApp'
            ];
        }

        if ($leadType === 'campus_tour') {
            return [
                'action_type' => 'campus_tour',
                'title' => "Confirm Campus Tour for {$studentName}",
                'reason' => "Tour booking pending confirmation for {$program}.",
                'recommended_time' => 'Within 2 hours',
                'channel' => 'whatsapp',
                'primary_cta' => 'Send WhatsApp Invite',
                'secondary_cta' => 'Schedule Call'
            ];
        }

        if (!empty($leadData['scholarship_tier'])) {
            return [
                'action_type' => 'scholarship_followup',
                'title' => "Send Scholarship Letter to {$studentName}",
                'reason' => "Student eligible for {$leadData['scholarship_tier']} — follow up before deadline.",
                'recommended_time' => 'Within 24 hours',
                'channel' => 'whatsapp',
                'primary_cta' => 'WhatsApp Waiver',
                'secondary_cta' => 'Email Details'
            ];
        }

        if ($leadType === 'prospectus' || $leadType === 'brochure') {
            return [
                'action_type' => 'asset_followup',
                'title' => "Follow up on {$program} Prospectus",
                'reason' => "Downloaded prospectus 24h ago — assess admission requirements.",
                'recommended_time' => 'Tomorrow 11:00 AM',
                'channel' => 'whatsapp',
                'primary_cta' => 'Send WhatsApp',
                'secondary_cta' => 'Schedule Callback'
            ];
        }

        return [
            'action_type' => 'general_followup',
            'title' => "Introduce {$program} counselor",
            'reason' => "Engage candidate on eligibility, campus facilities, and admissions cutoff.",
            'recommended_time' => 'Within 48 hours',
            'channel' => 'call',
            'primary_cta' => 'Call Candidate',
            'secondary_cta' => 'WhatsApp'
        ];
    }

    /**
     * Log a milestone event in the student conversion journey
     */
    public function logJourneyEvent(int $orgId, int $leadId, string $eventType, string $title, ?string $desc = null, ?array $metadata = null): void
    {
        try {
            $stmt = $this->db->prepare("INSERT INTO lead_journey_events 
                (organization_id, lead_id, event_type, event_title, event_description, event_metadata, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $orgId,
                $leadId,
                $eventType,
                $title,
                $desc,
                $metadata ? json_encode($metadata) : null
            ]);
        } catch (Throwable $e) {
            // Ignore journey logging failures
        }
    }
}
