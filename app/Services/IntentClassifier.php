<?php

namespace App\Services;

class IntentClassifier
{
    public const TIER_CONVERSATIONAL = 'conversational';
    public const TIER_CLARIFICATION   = 'clarification';
    public const TIER_KNOWLEDGE_QUERY = 'knowledge_query';

    /**
     * Common social and greeting tokens (English, Romanized Indic, etc.)
     */
    private static array $greetingTokens = [
        'hi', 'hello', 'hey', 'heyy', 'hii', 'hiii', 'hola', 'namaste', 'namaskar', 'pranam',
        'vanakkam', 'salaam', 'salam', 'khalas', 'good morning', 'good afternoon', 'good evening',
        'good day', 'morning', 'evening', 'sup', 'yo', 'howdy', 'test', 'testing'
    ];

    /**
     * Affirmative agreement / confirmation tokens
     */
    private static array $affirmativeTokens = [
        'yes', 'yeah', 'yep', 'yup', 'sure', 'absolutely', 'definitely', 'please', 'yes please',
        'please do', 'arrange it', 'book it', 'schedule it', 'go ahead', 'proceed', 'why not',
        'of course', 'haan', 'ha', 'ha ji', 'zarur', 'theek hai', 'avashya', 'do it', 'sure please'
    ];

    /**
     * Common acknowledgment, gratitude, and closing tokens (excluding affirmative actions)
     */
    private static array $socialTokens = [
        'ok', 'okay', 'okk', 'k', 'cool', 'great', 'nice', 'awesome', 'fine', 'alright',
        'understood', 'got it', 'thanks', 'thank you', 'thx', 'thankyou', 'dhanyawad', 'shukriya',
        'bye', 'goodbye', 'cya', 'see you', 'good night', 'take care', 'cheers'
    ];

    /**
     * Friction and clarification indicators
     */
    private static array $frictionPhrases = [
        'no', 'nope', 'nah', 'not really', 'not this', 'not that', 'thats not what i asked',
        'that is not what i asked', 'thats not what i meant', 'that is not what i meant',
        'you did not answer', 'you didnt answer', 'wrong answer', 'i dont understand',
        'i do not understand', 'what do you mean', 'what?', 'huh', 'explain again',
        'didnt get you', 'didn\'t get you', 'not answering', 'incorrect', 'not helpful'
    ];

    /**
     * Academic and college domain keywords that signal high-intent knowledge queries
     */
    private static array $academicKeywords = [
        'fee', 'fees', 'cost', 'tuition', 'scholarship', 'waiver', 'concession', 'installment',
        'admission', 'admissions', 'admit', 'apply', 'application', 'eligibility', 'cutoff', 'cut-off',
        'course', 'courses', 'program', 'programs', 'branch', 'branches', 'degree', 'btech', 'b.tech',
        'mba', 'bba', 'bca', 'mca', 'mtech', 'phd', 'diploma', 'syllabus', 'curriculum',
        'hostel', 'mess', 'food', 'accommodation', 'room', 'campus', 'tour', 'visit',
        'placement', 'placements', 'package', 'salary', 'recruiter', 'recruiters', 'company', 'companies',
        'faculty', 'prof', 'professor', 'dean', 'hod', 'department', 'departments',
        'rank', 'ranking', 'nirf', 'accreditation', 'naac', 'ugc', 'aicte',
        'exam', 'entrance', 'jee', 'cat', 'mat', 'gate', 'cet',
        'brochure', 'prospectus', 'contact', 'phone', 'email', 'number', 'address', 'location'
    ];

    /**
     * Check if a message is an affirmative response (e.g. accepting a tour, brochure, or callback offer)
     */
    public static function isAffirmativeResponse(string $message, array $recentHistory = []): bool
    {
        $clean = trim(mb_strtolower($message, 'UTF-8'));
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $clean);
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized));

        if (empty($normalized)) {
            return false;
        }

        $words = explode(' ', $normalized);
        $isAffirmativeMatch = in_array($normalized, self::$affirmativeTokens, true)
            || (count($words) <= 3 && in_array($words[0], ['yes', 'yeah', 'yep', 'yup', 'sure', 'haan', 'ha'], true));

        if (!$isAffirmativeMatch) {
            return false;
        }

        // If history is present, verify if previous assistant message asked a question or offered a CTA
        if (!empty($recentHistory)) {
            for ($i = count($recentHistory) - 1; $i >= 0; $i--) {
                $msg = $recentHistory[$i];
                if (($msg['role'] ?? '') === 'assistant') {
                    $content = $msg['content'] ?? '';
                    if (str_contains($content, '?') || preg_match('/\b(tour|visit|brochure|syllabus|counselor|callback|call|arrange|book)\b/i', $content)) {
                        return true;
                    }
                    break;
                }
            }
        }

        // Fallback: If normalized is an explicit affirmative confirmation, treat as affirmative
        return true;
    }

    /**
     * Classify user query intent into Tier A, Tier B, or Tier C
     */
    public static function classify(string $message, array $recentHistory = []): string
    {
        $clean = trim(mb_strtolower($message, 'UTF-8'));
        if (empty($clean)) {
            return self::TIER_CONVERSATIONAL;
        }

        // Strip basic punctuation for matching
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $clean);
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized));

        // 1. Check for Affirmative Agreement to previous offer/question (High Intent Action)
        if (self::isAffirmativeResponse($clean, $recentHistory)) {
            return self::TIER_KNOWLEDGE_QUERY;
        }

        // 2. Check for Academic Domain Keywords (Prioritize knowledge queries even if short)
        foreach (self::$academicKeywords as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/u', $normalized)) {
                return self::TIER_KNOWLEDGE_QUERY;
            }
        }

        // 3. Check for Friction / Clarification
        if (in_array($normalized, ['no', 'nope', 'nah', 'not this', 'wrong', 'what', 'huh'], true)) {
            return self::TIER_CLARIFICATION;
        }
        foreach (self::$frictionPhrases as $fp) {
            if (str_contains($normalized, $fp)) {
                return self::TIER_CLARIFICATION;
            }
        }

        // 4. Check for Social Greetings & Small Talk
        if (in_array($normalized, self::$greetingTokens, true) || in_array($normalized, self::$socialTokens, true)) {
            return self::TIER_CONVERSATIONAL;
        }

        // Also check if message starts with greeting (e.g. "hi there", "hello assistant") and is very short
        if (mb_strlen($normalized) <= 20) {
            $words = explode(' ', $normalized);
            if (count($words) <= 3) {
                if (in_array($words[0], self::$greetingTokens, true) || in_array($words[0], self::$socialTokens, true)) {
                    return self::TIER_CONVERSATIONAL;
                }
            }
        }

        // 5. Default: If message has substantive length (> 3 distinct words or > 25 chars), treat as knowledge query
        $wordCount = count(explode(' ', $normalized));
        if ($wordCount >= 3 || mb_strlen($normalized) >= 25) {
            return self::TIER_KNOWLEDGE_QUERY;
        }

        // Fallback short unrecognized text (e.g. "ok", "hey")
        return self::TIER_CONVERSATIONAL;
    }
}
