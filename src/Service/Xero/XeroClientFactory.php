<?php
declare(strict_types=1);

namespace App\Service\Xero;

/** Returns the live client when Xero is configured, else the stub. */
final class XeroClientFactory
{
    public static function make(): XeroClientInterface
    {
        if (cfg('xero.enabled') && cfg('xero.client_id')) {
            // XeroApiClient is added in Phase 6; fall back to stub until then.
            $real = __DIR__ . '/XeroApiClient.php';
            if (is_file($real)) return new XeroApiClient();
        }
        return new XeroStubClient();
    }
}
