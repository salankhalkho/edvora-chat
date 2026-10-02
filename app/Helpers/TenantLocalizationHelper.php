<?php

namespace App\Helpers;

use App\Config\Database;
use DateTime;
use DateTimeZone;
use Throwable;

class TenantLocalizationHelper
{
    /**
     * Resolves the primary campus and localized timezone metadata for a tenant.
     * Follows the 4-step resolution chain:
     * 1. campuses.is_primary = 1
     * 2. campuses (first registered campus)
     * 3. organizations (root organization country/state)
     * 4. Fallback 3: Mandatory Default to U.S.A. (America/New_York / EST/EDT)
     */
    public static function getTenantLocalization(?int $orgId): array
    {
        $defaultUsa = [
            'campus_id'      => null,
            'campus_name'    => null,
            'country'        => 'United States',
            'state'          => null,
            'city'           => null,
            'timezone'       => 'America/New_York',
            'timezone_short' => 'EDT',
            'locale'         => 'en-US'
        ];

        // Compute dynamic abbreviation for USA default
        try {
            $dt = new DateTime('now', new DateTimeZone('America/New_York'));
            $defaultUsa['timezone_short'] = $dt->format('T');
        } catch (Throwable $e) {}

        if (!$orgId) {
            return $defaultUsa;
        }

        try {
            $db = Database::getConnection();

            // Step 1: Query primary campus (is_primary = 1)
            $stmtPrimary = $db->prepare("
                SELECT id, name, city, state, country, is_primary
                FROM campuses
                WHERE organization_id = :org_id AND is_primary = 1
                LIMIT 1
            ");
            $stmtPrimary->execute([':org_id' => $orgId]);
            $campus = $stmtPrimary->fetch();

            // Step 2: Fallback to first registered campus if none is primary
            if (!$campus) {
                $stmtFirst = $db->prepare("
                    SELECT id, name, city, state, country, is_primary
                    FROM campuses
                    WHERE organization_id = :org_id
                    ORDER BY id ASC
                    LIMIT 1
                ");
                $stmtFirst->execute([':org_id' => $orgId]);
                $campus = $stmtFirst->fetch();
            }

            $country = $campus['country'] ?? null;
            $state   = $campus['state'] ?? null;
            $city    = $campus['city'] ?? null;
            $campusId   = isset($campus['id']) ? (int)$campus['id'] : null;
            $campusName = $campus['name'] ?? null;

            // Step 3: Fallback to organization root profile
            if (empty(trim((string)$country))) {
                $stmtOrg = $db->prepare("
                    SELECT country, state, city
                    FROM organizations
                    WHERE id = :org_id
                    LIMIT 1
                ");
                $stmtOrg->execute([':org_id' => $orgId]);
                $org = $stmtOrg->fetch();
                if ($org) {
                    $country = $org['country'] ?? null;
                    if (empty($state)) $state = $org['state'] ?? null;
                    if (empty($city))  $city  = $org['city'] ?? null;
                }
            }

            // Step 4: Fallback 3 — Mandatory Default to U.S.A.
            if (empty(trim((string)$country))) {
                $country = 'United States';
            }

            $tzInfo = self::resolveTimezone($country, $state, $city);

            return [
                'campus_id'      => $campusId,
                'campus_name'    => $campusName,
                'country'        => $country,
                'state'          => $state,
                'city'           => $city,
                'timezone'       => $tzInfo['timezone'],
                'timezone_short' => $tzInfo['timezone_short'],
                'locale'         => $tzInfo['locale']
            ];
        } catch (Throwable $e) {
            error_log('[TenantLocalizationHelper] Error resolving tenant localization: ' . $e->getMessage());
            return $defaultUsa;
        }
    }

    /**
     * Resolves country, state, and city to IANA timezone, abbreviation, and locale.
     */
    public static function resolveTimezone(?string $country, ?string $state = null, ?string $city = null): array
    {
        $cleanCountry = trim((string)$country);
        $cleanState   = trim((string)$state);

        // Normalize Country
        $cLower = strtolower($cleanCountry);
        $sLower = strtolower($cleanState);

        $timezone = 'America/New_York'; // Fallback 3: U.S.A.
        $locale   = 'en-US';

        if (in_array($cLower, ['india', 'in', 'bharat'])) {
            $timezone = 'Asia/Kolkata';
            $locale   = 'en-IN';
        } elseif (in_array($cLower, ['united states', 'usa', 'us', 'united states of america'])) {
            $locale = 'en-US';
            // US Central Time States
            $centralStates = [
                'al', 'alabama', 'tx', 'texas', 'il', 'illinois', 'tn', 'tennessee',
                'mo', 'missouri', 'wi', 'wisconsin', 'mn', 'minnesota', 'la', 'louisiana',
                'ms', 'mississippi', 'ok', 'oklahoma', 'ks', 'kansas', 'ne', 'nebraska',
                'ia', 'iowa', 'ar', 'arkansas', 'nd', 'north dakota', 'sd', 'south dakota'
            ];
            // US Mountain Time States
            $mountainStates = [
                'co', 'colorado', 'ut', 'utah', 'nm', 'new mexico', 'id', 'idaho',
                'mt', 'montana', 'wy', 'wyoming', 'az', 'arizona'
            ];
            // US Pacific Time States
            $pacificStates = [
                'ca', 'california', 'wa', 'washington', 'or', 'oregon', 'nv', 'nevada'
            ];
            // US Alaska & Hawaii
            $alaskaStates = ['ak', 'alaska'];
            $hawaiiStates = ['hi', 'hawaii'];

            if (in_array($sLower, $centralStates)) {
                $timezone = 'America/Chicago';
            } elseif (in_array($sLower, $mountainStates)) {
                $timezone = ($sLower === 'az' || $sLower === 'arizona') ? 'America/Phoenix' : 'America/Denver';
            } elseif (in_array($sLower, $pacificStates)) {
                $timezone = 'America/Los_Angeles';
            } elseif (in_array($sLower, $alaskaStates)) {
                $timezone = 'America/Anchorage';
            } elseif (in_array($sLower, $hawaiiStates)) {
                $timezone = 'Pacific/Honolulu';
            } else {
                // Eastern Time (default for US)
                $timezone = 'America/New_York';
            }
        } elseif (in_array($cLower, ['united kingdom', 'uk', 'gb', 'great britain', 'england', 'scotland', 'wales'])) {
            $timezone = 'Europe/London';
            $locale   = 'en-GB';
        } elseif (in_array($cLower, ['canada', 'ca'])) {
            $locale   = 'en-CA';
            if (in_array($sLower, ['bc', 'british columbia'])) {
                $timezone = 'America/Vancouver';
            } elseif (in_array($sLower, ['ab', 'alberta', 'calgary', 'edmonton'])) {
                $timezone = 'America/Edmonton';
            } else {
                $timezone = 'America/Toronto';
            }
        } elseif (in_array($cLower, ['australia', 'au'])) {
            $locale   = 'en-AU';
            if (in_array($sLower, ['wa', 'western australia', 'perth'])) {
                $timezone = 'Australia/Perth';
            } elseif (in_array($sLower, ['sa', 'south australia', 'adelaide'])) {
                $timezone = 'Australia/Adelaide';
            } else {
                $timezone = 'Australia/Sydney';
            }
        } elseif (in_array($cLower, ['united arab emirates', 'uae', 'dubai', 'abu dhabi'])) {
            $timezone = 'Asia/Dubai';
            $locale   = 'en-AE';
        } elseif (in_array($cLower, ['singapore', 'sg'])) {
            $timezone = 'Asia/Singapore';
            $locale   = 'en-SG';
        } elseif (in_array($cLower, ['germany', 'de', 'deutschland'])) {
            $timezone = 'Europe/Berlin';
            $locale   = 'en-DE';
        } elseif (in_array($cLower, ['france', 'fr'])) {
            $timezone = 'Europe/Paris';
            $locale   = 'en-FR';
        } elseif (in_array($cLower, ['ireland', 'ie'])) {
            $timezone = 'Europe/Dublin';
            $locale   = 'en-IE';
        } elseif (in_array($cLower, ['new zealand', 'nz'])) {
            $timezone = 'Pacific/Auckland';
            $locale   = 'en-NZ';
        } elseif (in_array($cLower, ['japan', 'jp'])) {
            $timezone = 'Asia/Tokyo';
            $locale   = 'ja-JP';
        } elseif (in_array($cLower, ['south africa', 'za'])) {
            $timezone = 'Africa/Johannesburg';
            $locale   = 'en-ZA';
        } elseif (in_array($cLower, ['saudi arabia', 'sa'])) {
            $timezone = 'Asia/Riyadh';
            $locale   = 'ar-SA';
        } elseif (in_array($cLower, ['qatar', 'qa'])) {
            $timezone = 'Asia/Qatar';
            $locale   = 'ar-QA';
        } elseif (in_array($cLower, ['malaysia', 'my'])) {
            $timezone = 'Asia/Kuala_Lumpur';
            $locale   = 'en-MY';
        } elseif (in_array($cLower, ['netherlands', 'nl', 'holland'])) {
            $timezone = 'Europe/Amsterdam';
            $locale   = 'en-NL';
        } elseif (in_array($cLower, ['spain', 'es', 'espana'])) {
            $timezone = 'Europe/Madrid';
            $locale   = 'es-ES';
        } elseif (in_array($cLower, ['italy', 'it', 'italia'])) {
            $timezone = 'Europe/Rome';
            $locale   = 'it-IT';
        } elseif (in_array($cLower, ['switzerland', 'ch'])) {
            $timezone = 'Europe/Zurich';
            $locale   = 'de-CH';
        } elseif (in_array($cLower, ['sweden', 'se'])) {
            $timezone = 'Europe/Stockholm';
            $locale   = 'sv-SE';
        } elseif (in_array($cLower, ['south korea', 'kr', 'korea'])) {
            $timezone = 'Asia/Seoul';
            $locale   = 'ko-KR';
        } elseif (in_array($cLower, ['hong kong', 'hk'])) {
            $timezone = 'Asia/Hong_Kong';
            $locale   = 'zh-HK';
        } elseif (in_array($cLower, ['philippines', 'ph'])) {
            $timezone = 'Asia/Manila';
            $locale   = 'en-PH';
        } elseif (in_array($cLower, ['brazil', 'br', 'brasil'])) {
            $timezone = 'America/Sao_Paulo';
            $locale   = 'pt-BR';
        } elseif (in_array($cLower, ['mexico', 'mx'])) {
            $timezone = 'America/Mexico_City';
            $locale   = 'es-MX';
        } else {
            // Unrecognized country: Fallback 3 (Default to U.S.A.)
            $timezone = 'America/New_York';
            $locale   = 'en-US';
        }

        // Canonical Abbreviation Resolution
        $tzShort = self::getShortAbbreviation($timezone);

        return [
            'timezone'       => $timezone,
            'timezone_short' => $tzShort,
            'locale'         => $locale
        ];
    }

    /**
     * Obtains canonical short timezone abbreviation considering current daylight saving time.
     */
    public static function getShortAbbreviation(string $timezone): string
    {
        // Canonical overrides where PHP or standard formats may differ
        $staticMap = [
            'Asia/Kolkata'        => 'IST',
            'Asia/Dubai'          => 'GST',
            'Asia/Singapore'      => 'SGT',
            'Asia/Tokyo'          => 'JST',
            'Africa/Johannesburg' => 'SAST',
            'Asia/Riyadh'         => 'AST',
            'Asia/Qatar'          => 'AST',
            'Asia/Kuala_Lumpur'   => 'MYT',
            'Europe/Amsterdam'    => 'CET',
            'Europe/Madrid'       => 'CET',
            'Europe/Rome'         => 'CET',
            'Europe/Zurich'       => 'CET',
            'Europe/Stockholm'    => 'CET',
            'Asia/Seoul'          => 'KST',
            'Asia/Hong_Kong'      => 'HKT',
            'Asia/Manila'         => 'PHT',
            'America/Sao_Paulo'   => 'BRT',
            'America/Mexico_City' => 'CST',
        ];

        if (isset($staticMap[$timezone])) {
            return $staticMap[$timezone];
        }

        try {
            $dt = new DateTime('now', new DateTimeZone($timezone));
            $formatted = $dt->format('T');
            // If PHP format is an offset like +04, check fallback
            if (preg_match('/^[+-]\d+/', $formatted)) {
                return $timezone;
            }
            return $formatted;
        } catch (Throwable $e) {
            return 'EST';
        }
    }

    /**
     * Formats any UTC or standard date string to the tenant's localized timestamp.
     */
    public static function formatTenantDateTime(?string $datetime, int $orgId, string $format = 'd M Y, h:i A'): string
    {
        if (empty($datetime)) {
            return 'N/A';
        }

        try {
            $loc = self::getTenantLocalization($orgId);
            $dtz = new DateTimeZone($loc['timezone']);
            $dt  = new DateTime($datetime);
            $dt->setTimezone($dtz);
            return $dt->format($format) . ' ' . $loc['timezone_short'];
        } catch (Throwable $e) {
            return (string)$datetime;
        }
    }

    /**
     * Returns DateTimeZone instance and short abbreviation tuple for a tenant.
     */
    public static function getTenantDateTimeZone(int $orgId): array
    {
        $loc = self::getTenantLocalization($orgId);
        try {
            $dtz = new DateTimeZone($loc['timezone']);
        } catch (Throwable $e) {
            $dtz = new DateTimeZone('America/New_York');
        }
        return [$dtz, $loc['timezone_short'], $loc];
    }
}
