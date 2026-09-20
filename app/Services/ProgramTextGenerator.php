<?php

namespace App\Services;

/**
 * ProgramTextGenerator
 *
 * Converts a structured programs table row into clean, embedding-friendly self-contained sentences.
 * Every sentence pre-pends the program name to ensure the vector embedding captures full relational identity.
 *
 * Example Output:
 *   "MBA Program — Degree: Master's in Business Administration."
 *   "MBA Program — Duration: 2 years."
 *   "MBA Program — Tuition: $32,000 USD per year."
 *   "MBA Program — Study mode: Full-time."
 */
class ProgramTextGenerator
{
    /**
     * Generate embedding-friendly sentences array from a programs row.
     *
     * @param  array $program  Array representing a programs table database row.
     * @return string[]        Array of self-contained fact sentences.
     */
    public static function generateSentences(array $program): array
    {
        $name = trim($program['course_name'] ?? $program['name'] ?? 'Academic Program');
        $sentences = [];

        // 1. Overview sentence
        $degree = trim($program['degree_type'] ?? '');
        $dept   = trim($program['department_name'] ?? '');

        if ($degree !== '' && $dept !== '') {
            $sentences[] = "{$name} is a {$degree} program in {$dept}.";
        } elseif ($degree !== '') {
            $sentences[] = "{$name} is a {$degree} program.";
        } else {
            $sentences[] = "{$name} is an academic program offered by the institution.";
        }

        // 2. Duration
        if (!empty($program['duration'])) {
            $dur = trim($program['duration']);
            $sentences[] = "{$name} Program — Duration: {$dur}.";
        }

        // 3. Tuition Fee
        if (!empty($program['tuition_fee'])) {
            $fee = trim($program['tuition_fee']);
            $sentences[] = "{$name} Program — Tuition: {$fee}.";
        }

        // 4. Study Mode
        if (!empty($program['mode'])) {
            $mode = trim($program['mode']);
            $sentences[] = "{$name} Program — Study mode: {$mode}.";
        }

        // 5. Intake Months
        if (!empty($program['intake_months'])) {
            $intake = trim($program['intake_months']);
            $sentences[] = "{$name} Program — Intake months: {$intake}.";
        }

        // 6. Eligibility Criteria
        if (!empty($program['eligibility'])) {
            $elig = trim($program['eligibility']);
            $sentences[] = "{$name} Program — Eligibility requirements: {$elig}";
        }

        // 7. General Description / Summary
        if (!empty($program['description'])) {
            $desc = trim($program['description']);
            $sentences[] = "{$name} Program overview: {$desc}";
        }

        return $sentences;
    }

    /**
     * Generate complete combined TXT content string from a programs row.
     *
     * @param  array $program
     * @return string
     */
    public static function generateTxt(array $program): string
    {
        $sentences = self::generateSentences($program);
        return implode("\n", $sentences);
    }
}
