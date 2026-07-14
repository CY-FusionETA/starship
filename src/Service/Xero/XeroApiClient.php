<?php
declare(strict_types=1);

namespace App\Service\Xero;

use App\Db;
use App\Repo\AuditRepo;

/**
 * Live Xero client. Pushes Starship purchase orders (and DO-derived bills) to
 * the connected Xero organisation. Every method is self-contained: on any
 * failure it returns a null id + 'error' string rather than throwing, so a
 * Xero outage never blocks creating the PO inside Starship.
 */
final class XeroApiClient implements XeroClientInterface
{
    private const API = 'https://api.xero.com/api.xro/2.0/';

    public function createPurchaseOrder(array $po, array $lines): array
    {
        try {
            $auth = XeroOAuth::accessToken();
            if (!$auth) return ['xero_po_id' => null, 'stubbed' => false, 'error' => 'Xero is not connected.'];

            $supplier = Db::one("SELECT * FROM suppliers WHERE id = ?", [(int)$po['supplier_id']]);
            $contact = !empty($supplier['xero_contact_id'])
                ? ['ContactID' => $supplier['xero_contact_id']]
                : ['Name' => $supplier['name'] ?? ($po['supplier_name'] ?? 'Supplier')];

            // The project maps to a Xero tracking option; carry it on every line so
            // spend is analysed against the project in Xero (Starship's whole point).
            $project = Db::one("SELECT * FROM projects WHERE id = ?", [(int)($po['project_id'] ?? 0)]);
            $tracking = (!empty($project['xero_tracking_category_id']) && !empty($project['xero_tracking_option_id']))
                ? [['TrackingCategoryID' => $project['xero_tracking_category_id'], 'TrackingOptionID' => $project['xero_tracking_option_id']]]
                : null;

            $lineItems = [];
            foreach ($lines as $l) {
                $lineItems[] = array_filter([
                    'Description' => (string)($l['description'] ?: 'Item'),
                    'Quantity'    => (float)$l['qty_ordered'],
                    'UnitAmount'  => $l['unit_price'] !== null ? (float)$l['unit_price'] : 0,
                    'ItemCode'    => !empty($l['xero_item_code']) ? (string)$l['xero_item_code'] : null,
                    'Tracking'    => $tracking,
                ], fn($v) => $v !== null);
            }

            $order = array_filter([
                'PurchaseOrderNumber' => (string)$po['po_number'],
                'Contact'             => $contact,
                'Date'                => !empty($po['order_date']) ? substr((string)$po['order_date'], 0, 10) : null,
                'Reference'           => !empty($po['mr_number']) ? ('MR ' . $po['mr_number']) : null,
                'CurrencyCode'        => $po['currency'] ?? 'MYR',
                'Status'              => 'AUTHORISED',
                'LineItems'           => $lineItems,
            ], fn($v) => $v !== null && $v !== '');

            [$code, $body] = XeroOAuth::http('POST', self::API . 'PurchaseOrders', [
                'Authorization: Bearer ' . $auth['access_token'],
                'Xero-tenant-id: ' . $auth['tenant_id'],
                'Accept: application/json',
                'Content-Type: application/json',
            ], json_encode(['PurchaseOrders' => [$order]], JSON_UNESCAPED_UNICODE));

            $json = json_decode($body, true);
            $created = $json['PurchaseOrders'][0] ?? null;
            $xeroId = $created['PurchaseOrderID'] ?? null;

            if ($code < 200 || $code >= 300 || !$xeroId) {
                $err = self::extractError($json, $body);
                AuditRepo::log('purchase_order', (int)$po['id'], 'xero_po_failed', ['http' => $code, 'error' => $err]);
                return ['xero_po_id' => null, 'stubbed' => false, 'error' => $err];
            }

            // Remember the Xero contact so future POs match instead of re-creating.
            if (empty($supplier['xero_contact_id']) && !empty($created['Contact']['ContactID'])) {
                Db::q("UPDATE suppliers SET xero_contact_id = ? WHERE id = ?",
                    [$created['Contact']['ContactID'], (int)$po['supplier_id']]);
            }
            AuditRepo::log('purchase_order', (int)$po['id'], 'xero_po_created', ['xero_po_id' => $xeroId]);
            return ['xero_po_id' => $xeroId, 'stubbed' => false];
        } catch (\Throwable $e) {
            AuditRepo::log('purchase_order', (int)($po['id'] ?? 0), 'xero_po_error', ['error' => $e->getMessage()]);
            return ['xero_po_id' => null, 'stubbed' => false, 'error' => $e->getMessage()];
        }
    }

    public function createBill(array $bill, array $lines): array
    {
        try {
            $auth = XeroOAuth::accessToken();
            if (!$auth) return ['xero_bill_id' => null, 'stubbed' => false, 'error' => 'Xero is not connected.'];

            $supplier = Db::one("SELECT * FROM suppliers WHERE id = ?", [(int)($bill['supplier_id'] ?? 0)]);
            $contact = !empty($supplier['xero_contact_id'])
                ? ['ContactID' => $supplier['xero_contact_id']]
                : ['Name' => $supplier['name'] ?? 'Supplier'];

            $lineItems = [];
            foreach ($lines as $l) {
                $lineItems[] = array_filter([
                    'Description' => (string)($l['description'] ?? 'Item'),
                    'Quantity'    => (float)($l['qty'] ?? $l['qty_ordered'] ?? 1),
                    'UnitAmount'  => isset($l['unit_price']) && $l['unit_price'] !== null ? (float)$l['unit_price'] : 0,
                ], fn($v) => $v !== null);
            }

            $invoice = array_filter([
                'Type'            => 'ACCPAY',
                'Contact'         => $contact,
                'InvoiceNumber'   => $bill['invoice_number'] ?? null,
                'Date'            => !empty($bill['invoice_date']) ? substr((string)$bill['invoice_date'], 0, 10) : null,
                'CurrencyCode'    => $bill['currency'] ?? 'MYR',
                'Status'          => 'DRAFT',
                'LineItems'       => $lineItems,
            ], fn($v) => $v !== null && $v !== '');

            [$code, $body] = XeroOAuth::http('POST', self::API . 'Invoices', [
                'Authorization: Bearer ' . $auth['access_token'],
                'Xero-tenant-id: ' . $auth['tenant_id'],
                'Accept: application/json',
                'Content-Type: application/json',
            ], json_encode(['Invoices' => [$invoice]], JSON_UNESCAPED_UNICODE));

            $json = json_decode($body, true);
            $xeroId = $json['Invoices'][0]['InvoiceID'] ?? null;
            if ($code < 200 || $code >= 300 || !$xeroId) {
                return ['xero_bill_id' => null, 'stubbed' => false, 'error' => self::extractError($json, $body)];
            }
            return ['xero_bill_id' => $xeroId, 'stubbed' => false];
        } catch (\Throwable $e) {
            return ['xero_bill_id' => null, 'stubbed' => false, 'error' => $e->getMessage()];
        }
    }

    /** Pull a human-readable message out of a Xero error/validation response. */
    private static function extractError(?array $json, string $raw): string
    {
        if (is_array($json)) {
            $els = $json['Elements'][0]['ValidationErrors'] ?? $json['ValidationErrors'] ?? null;
            if ($els) return implode('; ', array_map(fn($e) => $e['Message'] ?? '', $els));
            if (!empty($json['Message'])) return (string)$json['Message'];
            if (!empty($json['detail'])) return (string)$json['detail'];
        }
        return trim(substr($raw, 0, 300)) ?: 'Unknown Xero error.';
    }
}
