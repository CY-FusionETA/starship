<?php
declare(strict_types=1);

namespace App\Service\Xero;

/** The seam every Xero call goes through. Stubbed now, real client later. */
interface XeroClientInterface
{
    /**
     * Push a purchase order to Xero.
     * @return array{xero_po_id: ?string, stubbed: bool}
     */
    public function createPurchaseOrder(array $po, array $lines): array;

    /**
     * Create a draft ACCPAY bill from an accepted delivery order.
     * @return array{xero_bill_id: ?string, stubbed: bool}
     */
    public function createBill(array $bill, array $lines): array;
}
