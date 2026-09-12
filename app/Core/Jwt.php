<?php

namespace App\Core;

use App\Config\Env;
use Exception;

class Jwt
{
    private static function getSecret(): string
    {
        return Env::get('JWT_SECRET', 'edvora_chat_jwt_super_secret_key_2026_change_in_env');
    }

    public static function encode(array $payload, int $ttlSeconds = 900): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256'
        ];

        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + $ttlSeconds;

        $base64UrlHeader = self::base64UrlEncode(json_encode($header));
        $base64UrlPayload = self::base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, self::getSecret(), true);
        $base64UrlSignature = self::base64UrlEncode($signature);

        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    public static function decode(string $jwt): ?array
    {
        $tokenParts = explode('.', $jwt);
        if (count($tokenParts) !== 3) {
            return null;
        }

        list($headerB64, $payloadB64, $signatureB64) = $tokenParts;

        $signature = self::base64UrlDecode($signatureB64);
        $expectedSignature = hash_hmac('sha256', $headerB64 . "." . $payloadB64, self::getSecret(), true);

        if (!hash_equals($expectedSignature, $signature)) {
            return null; // Invalid signature
        }

        $payload = json_decode(self::base64UrlDecode($payloadB64), true);
        if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
            return null; // Expired or invalid payload
        }

        return $payload;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
