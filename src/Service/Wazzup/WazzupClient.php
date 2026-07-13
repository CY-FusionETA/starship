<?php
declare(strict_types=1);

namespace App\Service\Wazzup;

use App\Settings;

/**
 * Wazzup24 v3 API client: send WhatsApp text, register the inbound webhook, and
 * download inbound media. Config lives in app_settings (Settings tab). Setting
 * the WAZZUP_DRYRUN env skips the real send (used by tests) and just returns the
 * composed message so nothing is delivered to a real phone.
 */
final class WazzupClient
{
    private static function apiBase(): string { return rtrim((string)(Settings::get('wazzup.api_base', 'https://api.wazzup24.com/v3')), '/'); }
    public static function apiKey(): string    { return trim((string)Settings::raw('wazzup.api_key', '')); }
    public static function channelId(): string { return trim((string)Settings::raw('wazzup.channel_id', '')); }
    public static function number(): string    { return trim((string)Settings::raw('wazzup.number', '')); }
    public static function enabled(): bool      { return Settings::bool('wazzup.enabled'); }
    public static function isConfigured(): bool { return self::apiKey() !== '' && self::channelId() !== ''; }

    /** Send a WhatsApp text message to a contact (phone digits). */
    public static function sendText(string $chatId, string $text): array
    {
        if (getenv('WAZZUP_DRYRUN')) {
            @file_put_contents(STORAGE_ROOT . '/logs/wazzup-out.log',
                '[' . date('c') . "] DRYRUN -> {$chatId}\n{$text}\n\n", FILE_APPEND);
            return ['ok' => true, 'dryrun' => true, 'text' => $text];
        }
        if (!self::isConfigured()) return ['ok' => false, 'skipped' => true, 'error' => 'Wazzup not configured'];

        [$code, $body] = self::http('POST', self::apiBase() . '/message', [
            'channelId' => self::channelId(),
            'chatType'  => 'whatsapp',
            'chatId'    => preg_replace('/\D+/', '', $chatId),
            'text'      => $text,
        ]);
        $json = json_decode($body, true);
        if ($code >= 200 && $code < 300) {
            return ['ok' => true, 'messageId' => $json['messageId'] ?? null];
        }
        return ['ok' => false, 'error' => 'HTTP ' . $code . ': ' . substr($body, 0, 200)];
    }

    /** Point Wazzup's inbound webhook at our endpoint. */
    public static function registerWebhook(string $uri): array
    {
        if (!self::isConfigured()) return ['ok' => false, 'error' => 'Enter the API key and channel ID first.'];
        [$code, $body] = self::http('PATCH', self::apiBase() . '/webhooks', [
            'webhooksUri'   => $uri,
            'subscriptions' => [
                'messagesAndStatuses' => true,
                'contactsAndDealsCreation' => false,
                'channelsUpdates' => false,
                'templateStatus' => false,
            ],
        ]);
        if ($code >= 200 && $code < 300) return ['ok' => true];
        return ['ok' => false, 'error' => 'HTTP ' . $code . ': ' . substr($body, 0, 300)];
    }

    /** Download inbound media. @return array{0:string,1:string} [bytes, ext] */
    public static function downloadMedia(string $url): array
    {
        // Wazzup media links are usually public/temporary; retry once with auth if refused.
        [$code, $bytes, $ctype] = self::rawGet($url, false);
        if ($code === 401 || $code === 403) {
            [$code, $bytes, $ctype] = self::rawGet($url, true);
        }
        if ($code < 200 || $code >= 300 || $bytes === '') {
            throw new \RuntimeException('Could not download media (HTTP ' . $code . ')');
        }
        $ext = match (true) {
            str_contains($ctype, 'pdf')  => 'pdf',
            str_contains($ctype, 'png')  => 'png',
            str_contains($ctype, 'webp') => 'webp',
            str_contains($ctype, 'jpeg'), str_contains($ctype, 'jpg') => 'jpg',
            default => strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION)) ?: 'jpg',
        };
        return [$bytes, $ext];
    }

    /** @return array{0:int,1:string} [code, body] */
    private static function http(string $method, string $url, array $json): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . self::apiKey(),
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($json, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 25,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) return [0, 'network error: ' . $err];
        return [$code, (string)$resp];
    }

    /** @return array{0:int,1:string,2:string} [code, bytes, content-type] */
    private static function rawGet(string $url, bool $auth): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $auth ? ['Authorization: Bearer ' . self::apiKey()] : [],
        ]);
        $bytes = curl_exec($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        return [$code, $bytes === false ? '' : (string)$bytes, strtolower($ctype)];
    }
}
