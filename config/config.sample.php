<?php
/**
 * Starship — configuration TEMPLATE.
 * Copy to config.php on the server and fill real values. config.php is git-ignored
 * and denied over HTTP. NEVER commit real secrets.
 */
if (!defined('GLOBE_APP')) { http_response_code(404); exit; }

return [
    'app' => [
        'name'     => 'Starship',
        'base_url' => 'https://fusioneta.com.my/web/globe-starship',
        'env'      => 'production',
        'timezone' => 'Asia/Kuala_Lumpur',
        // 32-byte key (hex) for sodium secretbox (Xero token encryption).
        // Generate: php -r "echo bin2hex(random_bytes(32));"
        'app_key'  => 'REPLACE_WITH_64_HEX_CHARS',
    ],
    // SQLite: single self-contained file. Leave 'path' unset to use storage/starship.sqlite.
    'db' => [
        'path' => '',   // e.g. /home/user/private/starship.sqlite  (default: storage/starship.sqlite)
    ],
    'gemini' => [
        'api_key'      => 'REPLACE_GEMINI_KEY',
        'model_cheap'  => 'gemini-2.0-flash',
        'model_strong' => 'gemini-2.5-pro',
        'escalate_below' => 75,   // overall_confidence threshold to escalate
    ],
    'wazzup' => [
        'api_key'    => 'REPLACE_WAZZUP_KEY',
        'channel_id' => 'REPLACE_WAZZUP_CHANNEL_ID',
        'api_base'   => 'https://api.wazzup24.com/v3',
        'webhook_secret' => '',   // optional shared secret for webhook verification
    ],
    'xero' => [
        'enabled'       => false, // flip true when creds ready -> uses XeroApiClient
        'client_id'     => '',
        'client_secret' => '',
        'redirect_uri'  => 'https://fusioneta.com.my/web/globe-starship/api/xero_callback.php',
        'scopes'        => 'openid profile email accounting.transactions accounting.settings offline_access',
    ],
    'deploy' => [
        'enabled' => true,   // set false (or delete api/deploy.php) at go-live
        'token'   => 'REPLACE_64_HEX',
    ],
];
