<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 01/10/2026
 * DESCRIPTION : SAP purchase order header and items from ZI_PURCHASEORDERAPI_HUB_CDS/ZI_PurchaseOrderAPI_HUB
 */
require_once base_path('app/core/SapODataClient.php');
require_once base_path('app/services/SapValueFormat.php');

class SapPurchaseOrderService
{
    use SapValueFormat;

    private const DEFAULT_SERVICE = '/sap/opu/odata/sap/ZI_PURCHASEORDERAPI_HUB_CDS/ZI_PurchaseOrderAPI_HUB';
    private const SALES_ORDER_FIELDS = 20;

    private SapODataClient $client;
    private array $cfg;

    /*
     * Constructor
     */
    public function __construct(?SapODataClient $client = null)
    {
        $this->client = $client ?? new SapODataClient();
        $this->cfg = config('sap');
    }

    /*
     * @return array{purchase_order: string, header: array, records: array, totals: array, error: ?string}
     */
    public function report(string $purchaseOrder): array
    {
        $po = $this->padSalesOrder($purchaseOrder);
        $out = [
            'purchase_order' => $this->displayNumber($po),
            'header'         => [],
            'records'        => [],
            'totals'         => [],
            'error'          => null,
        ];
        if (!preg_match('/^\d{10}$/', $po)) {
            $out['error'] = 'Purchase order must be a number of up to 10 digits.';
            return $out;
        }

        $service = (string) ($this->cfg['purchase_order_service'] ?? self::DEFAULT_SERVICE);
        $result = $this->client->fetchResults($service, ['$filter' => "PurchaseOrder eq '{$po}'"]);
        if (($result['error'] ?? null) !== null) {
            $out['error'] = $result['error'];
            return $out;
        }

        $rows = [];
        foreach ($result['rows'] as $row) {
            if (is_array($row)) {
                $rows[] = $this->mapItem($row);
            }
        }
        usort($rows, static fn (array $a, array $b): int => (int) $a['item'] <=> (int) $b['item']);
        if ($rows === []) {
            return $out;
        }

        $first = $result['rows'][0];
        $out['header'] = [
            'purchase_order'  => $this->displayNumber((string) ($first['PurchaseOrder'] ?? $po)),
            'date'            => $this->sapDate((string) ($first['PurchaseOrderDate'] ?? '')),
            'supplier'        => $this->displayNumber((string) ($first['Supplier'] ?? '')),
            'company_code'    => trim((string) ($first['CompanyCode'] ?? '')),
            'purchasing_org'  => trim((string) ($first['PurchasingOrganization'] ?? '')),
            'purchasing_group' => trim((string) ($first['PurchasingGroup'] ?? '')),
            'created_by'      => trim((string) ($first['CreatedByUser'] ?? '')),
            'plants'          => $this->distinct($rows, 'plant'),
            'sales_orders'    => array_values(array_unique(array_merge(...array_column($rows, 'sales_orders')))),
        ];
        $out['records'] = $rows;
        $out['totals'] = $this->totals($rows);
        return $out;
    }

    private function mapItem(array $row): array
    {
        $qty = $this->sapNumber($row['OrderQuantity'] ?? '');
        $price = $this->sapNumber($row['NetPriceAmount'] ?? '');
        $salesOrders = [];
        for ($i = 1; $i <= self::SALES_ORDER_FIELDS; $i++) {
            $so = $this->displayNumber((string) ($row['zzvbeln' . $i] ?? ''));
            if ($so !== '' && !in_array($so, $salesOrders, true)) {
                $salesOrders[] = $so;
            }
        }

        return [
            'item'             => $this->displayNumber((string) ($row['PurchaseOrderItem'] ?? '')),
            'material'         => trim((string) ($row['Material'] ?? '')),
            'description'      => trim((string) ($row['PurchaseOrderItemText'] ?? '')),
            'plant'            => trim((string) ($row['Plant'] ?? '')),
            'storage_location' => trim((string) ($row['StorageLocation'] ?? '')),
            'order_qty'        => $qty,
            'unit'             => trim((string) ($row['PurchaseOrderQuantityUnit'] ?? '')),
            'net_price'        => $price,
            'net_value'        => round($qty * $price, 2),
            'currency'         => trim((string) ($row['DocumentCurrency'] ?? '')),
            'sales_orders'     => $salesOrders,
        ];
    }

    /*
     * Quantity and value are only summed when every item shares the same unit / currency.
     */
    private function totals(array $rows): array
    {
        $units = $this->distinct($rows, 'unit');
        $currencies = $this->distinct($rows, 'currency');
        return [
            'items'      => count($rows),
            'materials'  => count($this->distinct($rows, 'material')),
            'unit'       => count($units) === 1 ? $units[0] : '',
            'order_qty'  => count($units) === 1 ? round(array_sum(array_column($rows, 'order_qty')), 3) : null,
            'currency'   => count($currencies) === 1 ? $currencies[0] : '',
            'net_value'  => count($currencies) === 1 ? round(array_sum(array_column($rows, 'net_value')), 2) : null,
        ];
    }

    private function distinct(array $rows, string $key): array
    {
        return array_values(array_unique(array_filter(array_column($rows, $key), static fn ($v): bool => $v !== '')));
    }
}
