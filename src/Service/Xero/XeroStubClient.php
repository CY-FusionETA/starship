<?php
declare(strict_types=1);

namespace App\Service\Xero;

use App\Repo\AuditRepo;

/** Logs intended Xero calls to the audit log; no network. Default until creds exist. */
final class XeroStubClient implements XeroClientInterface
{
    public function createPurchaseOrder(array $po, array $lines, array $attachments = []): array
    {
        AuditRepo::log('purchase_order', (int)($po['id'] ?? 0), 'xero_stub_create_po', [
            'po_number'   => $po['po_number'] ?? null,
            'lines'       => count($lines),
            'attachments' => count($attachments),
            'note'        => 'Xero disabled — would push DRAFT PO + quotations here.',
        ]);
        return ['xero_po_id' => null, 'stubbed' => true];
    }

    public function createBill(array $bill, array $lines): array
    {
        AuditRepo::log('bill', (int)($bill['id'] ?? 0), 'xero_stub_create_bill', [
            'invoice_number' => $bill['invoice_number'] ?? null,
            'lines'          => count($lines),
            'note'           => 'Xero disabled — would create ACCPAY bill here.',
        ]);
        return ['xero_bill_id' => null, 'stubbed' => true];
    }
}
