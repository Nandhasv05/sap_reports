<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/09/2026
 * DESCRIPTION : SAP production (cut / sew / wash / finish / ship) status from ZBUSINESS_API_SRV/ZPROD_NEWSet
 */
require_once base_path('app/core/SapODataClient.php');
require_once base_path('app/services/SapValueFormat.php');

class SapProductionService
{
    use SapValueFormat;

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
     * Table columns: key, label, type (text|num|date), stage (header colour group)
     */
    public static function columns(): array
    {
        return [
            ['key' => 'sno',               'label' => 'S.No',                 'type' => 'num',  'stage' => ''],
            ['key' => 'sales_order',       'label' => 'Sales Order',          'type' => 'text', 'stage' => ''],
            ['key' => 'so_item',           'label' => 'SO Item',              'type' => 'num',  'stage' => ''],
            ['key' => 'plant',             'label' => 'Plant',                'type' => 'text', 'stage' => ''],
            ['key' => 'customer',          'label' => 'Customer',             'type' => 'text', 'stage' => ''],
            ['key' => 'customer_name',     'label' => 'Customer Name',        'type' => 'text', 'stage' => ''],
            ['key' => 'header_material',   'label' => 'Header Material',      'type' => 'text', 'stage' => ''],
            ['key' => 'style',             'label' => 'Style',                'type' => 'text', 'stage' => ''],
            ['key' => 'style_description', 'label' => 'Style Description',    'type' => 'text', 'stage' => ''],
            ['key' => 'material_group',    'label' => 'Material Group',       'type' => 'text', 'stage' => ''],
            ['key' => 'colour',            'label' => 'Colour',               'type' => 'text', 'stage' => ''],
            ['key' => 'size',              'label' => 'Size',                 'type' => 'text', 'stage' => ''],
            ['key' => 'fit',               'label' => 'Fit',                  'type' => 'text', 'stage' => ''],
            ['key' => 'delivery_date',     'label' => 'Req. Delivery',        'type' => 'date', 'stage' => ''],
            ['key' => 'so_qty',            'label' => 'SO Qty',               'type' => 'num',  'stage' => 'order'],
            ['key' => 'tolerance',         'label' => 'Over Del. Tol.',       'type' => 'text', 'stage' => 'order'],
            ['key' => 'total_so_qty',      'label' => 'Total SO Qty',         'type' => 'num',  'stage' => 'order'],
            ['key' => 'cut_order',         'label' => 'Cut Order',            'type' => 'num',  'stage' => 'cut'],
            ['key' => 'cut_made',          'label' => 'Cut Made',             'type' => 'num',  'stage' => 'cut'],
            ['key' => 'cut_reject',        'label' => 'Cut Reject',           'type' => 'num',  'stage' => 'cut'],
            ['key' => 'cut_pending_so',    'label' => 'Cut Pending (SO)',     'type' => 'num',  'stage' => 'cut'],
            ['key' => 'cut_pending_co',    'label' => 'Cut Pending (CO)',     'type' => 'num',  'stage' => 'cut'],
            ['key' => 'ready_sewing',      'label' => 'Ready for Sewing',     'type' => 'num',  'stage' => 'cut'],
            ['key' => 'sew_order',         'label' => 'Sew Order',            'type' => 'num',  'stage' => 'sew'],
            ['key' => 'sew_made',          'label' => 'Sew Made',             'type' => 'num',  'stage' => 'sew'],
            ['key' => 'sew_reject',        'label' => 'Sew Reject',           'type' => 'num',  'stage' => 'sew'],
            ['key' => 'sew_pending_so',    'label' => 'Sew Pending (SO)',     'type' => 'num',  'stage' => 'sew'],
            ['key' => 'sew_pending_fo',    'label' => 'Sew Pending (FO)',     'type' => 'num',  'stage' => 'sew'],
            ['key' => 'ready_wash',        'label' => 'Ready for Wash',       'type' => 'num',  'stage' => 'sew'],
            ['key' => 'wash_sent',         'label' => 'Wash Sent',            'type' => 'num',  'stage' => 'wash'],
            ['key' => 'wash_received',     'label' => 'Wash Received',        'type' => 'num',  'stage' => 'wash'],
            ['key' => 'wash_reject',       'label' => 'Wash Reject',          'type' => 'num',  'stage' => 'wash'],
            ['key' => 'wash_pending_so',   'label' => 'Wash Pending (SO)',    'type' => 'num',  'stage' => 'wash'],
            ['key' => 'wash_pending_spo',  'label' => 'Wash Pending (SPO)',   'type' => 'num',  'stage' => 'wash'],
            ['key' => 'ready_finishing',   'label' => 'Ready for Finishing',  'type' => 'num',  'stage' => 'wash'],
            ['key' => 'fin_order',         'label' => 'Finish Order',         'type' => 'num',  'stage' => 'fin'],
            ['key' => 'fin_made',          'label' => 'Finish Made',          'type' => 'num',  'stage' => 'fin'],
            ['key' => 'fin_reject',        'label' => 'Finish Reject',        'type' => 'num',  'stage' => 'fin'],
            ['key' => 'fin_pending_so',    'label' => 'Finish Pending (SO)',  'type' => 'num',  'stage' => 'fin'],
            ['key' => 'fin_pending_po',    'label' => 'Finish Pending (PO)',  'type' => 'num',  'stage' => 'fin'],
            ['key' => 'ready_delivery',    'label' => 'Ready for Delivery',   'type' => 'num',  'stage' => 'fin'],
            ['key' => 'good_garment',      'label' => 'Good Garment Qty',     'type' => 'num',  'stage' => 'ship'],
            ['key' => 'shipment',          'label' => 'Shipment Qty',         'type' => 'num',  'stage' => 'ship'],
            ['key' => 'pending_shipment',  'label' => 'Pending Shipment',     'type' => 'num',  'stage' => 'ship'],
            ['key' => 'delivery_transfer', 'label' => 'Delivery / Transfer',  'type' => 'num',  'stage' => 'ship'],
            ['key' => 'pending_delivery',  'label' => 'Pending Delivery',     'type' => 'num',  'stage' => 'ship'],
        ];
    }

    /*
     * Columns that repeat the same value on every line of one sales order; hidden until the user shows them.
     */
    public static function defaultHiddenColumns(): array
    {
        return ['sales_order', 'plant', 'customer', 'customer_name', 'header_material', 'material_group'];
    }

    /*
     * Configured plant list for the plant selector
     */
    public function plants(): array
    {
        $plants = $this->cfg['production_plants'] ?? [];
        return is_array($plants) ? array_values(array_filter(array_map('strval', $plants), [$this, 'validPlant'])) : [];
    }

    public function validPlant(string $plant): bool
    {
        return (bool) preg_match('/^[A-Z0-9]{4}$/', $plant);
    }

    /*
     * @return array{records: array, total: int, page: int, pages: int, per_page: int, sales_order: string, plants: array, info: array, error: ?string}
     */
    public function report(string $salesOrder, string $plant = ''): array
    {
        $so = $this->padSalesOrder($salesOrder);
        $plant = strtoupper(trim($plant));
        $out = [
            'records'     => [],
            'total'       => 0,
            'page'        => 1,
            'pages'       => 1,
            'per_page'    => 0,
            'sales_order' => $this->displayNumber($so),
            'plants'      => [],
            'info'        => [],
            'warning'     => '',
            'error'       => null,
        ];
        if ($so === '') {
            return $out;
        }
        if (!preg_match('/^\d{1,10}$/', $so)) {
            $out['error'] = 'Sales order must be a number.';
            return $out;
        }
        if ($plant !== '' && !$this->validPlant($plant)) {
            $out['error'] = 'Plant must be a 4 character code, for example P002. Leave it empty to find the plant automatically.';
            return $out;
        }

        // A wrong plant makes ZPROD_NEWSet scan for ~20 s and return nothing, so check the order's real plant first.
        $detected = $this->detectPlants($so);
        $soText = $this->displayNumber($so);
        if ($plant === '') {
            if ($detected['error'] !== null) {
                $out['error'] = $detected['error'];
                return $out;
            }
            $plants = $detected['plants'];
            if ($plants === []) {
                $out['error'] = 'Sales order ' . $soText . ' was not found in SAP. Check the number or enter the plant.';
                return $out;
            }
        } elseif ($detected['plants'] !== [] && !in_array($plant, $detected['plants'], true)) {
            $plants = $detected['plants'];
            $out['warning'] = 'Sales order ' . $soText . ' belongs to plant ' . implode(', ', $plants) . ', not ' . $plant . '. Showing plant ' . implode(', ', $plants) . '.';
        } else {
            $plants = [$plant];
        }

        $service = (string) ($this->cfg['production_service'] ?? '');
        if ($service === '') {
            $out['error'] = 'Production service path is not configured.';
            return $out;
        }

        $rows = [];
        foreach ($plants as $p) {
            $result = $this->client->fetchResults($service, [
                '$filter' => "SalesOrder eq '{$so}' and Plant eq '{$p}'",
            ]);
            if (($result['error'] ?? null) !== null) {
                $out['error'] = $result['error'];
                return $out;
            }
            foreach ($result['rows'] as $row) {
                if (is_array($row)) {
                    $rows[] = $this->mapRow($row);
                }
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['plant'], (int) $a['so_item']] <=> [$b['plant'], (int) $b['so_item']]);

        $first = $rows[0] ?? [];
        $out['records'] = $rows;
        $out['total'] = count($rows);
        $out['per_page'] = count($rows);
        $out['plants'] = $plants;
        $out['info'] = [
            'customer'        => (string) ($first['customer'] ?? ''),
            'customer_name'   => (string) ($first['customer_name'] ?? ''),
            'header_material' => (string) ($first['header_material'] ?? ''),
            'delivery_date'   => (string) ($first['delivery_date'] ?? ''),
            'tolerance'       => (string) ($first['tolerance'] ?? ''),
        ];
        return $out;
    }

    /*
     * Find the plant(s) of a sales order from the sales order items CDS view.
     *
     * @return array{plants: array<int, string>, error: ?string}
     */
    private function detectPlants(string $so): array
    {
        $service = (string) ($this->cfg['service'] ?? '');
        $result = $this->client->fetchResults($service, [
            '$filter' => "Salesorder eq '{$so}'",
            '$select' => 'Salesorder,Plant',
        ]);
        if (($result['error'] ?? null) !== null) {
            return ['plants' => [], 'error' => 'Could not find the plant for this sales order: ' . $result['error']];
        }
        $plants = [];
        foreach ($result['rows'] as $row) {
            $p = strtoupper(trim((string) ($row['Plant'] ?? '')));
            if ($this->validPlant($p) && !in_array($p, $plants, true)) {
                $plants[] = $p;
            }
        }
        sort($plants);
        return ['plants' => $plants, 'error' => null];
    }

    private function mapRow(array $row): array
    {
        $q = fn (string $field): float => $this->sapNumber($row[$field] ?? '');
        return [
            'sales_order'       => $this->displayNumber((string) ($row['SalesOrder'] ?? '')),
            'so_item'           => $this->displayNumber((string) ($row['SalesOrderItem'] ?? '')),
            'plant'             => trim((string) ($row['Plant'] ?? '')),
            'customer'          => $this->displayNumber((string) ($row['Customer'] ?? '')),
            'customer_name'     => trim((string) ($row['CustomerName'] ?? '')),
            'header_material'   => trim((string) ($row['HeaderMaterial'] ?? '')),
            'style'             => trim((string) ($row['Style'] ?? '')),
            'style_description' => trim((string) ($row['StyleDescription'] ?? '')),
            'material_group'    => trim((string) ($row['MaterialGroup'] ?? '')),
            'colour'            => trim((string) ($row['Characteristic1'] ?? '')),
            'size'              => trim((string) ($row['Characteristic2'] ?? '')),
            'fit'               => trim((string) ($row['Characteristic3'] ?? '')),
            'delivery_date'     => $this->sapDate((string) ($row['RequestedDeliveryDate'] ?? '')),
            'so_qty'            => $q('SalesOrderQuantity'),
            'tolerance'         => trim((string) ($row['OverDeliveryTolerance'] ?? '')),
            'total_so_qty'      => $q('TotalSalesOrderQuantity'),
            'cut_order'         => $q('CutOrderQty'),
            'cut_made'          => $q('CutMadeQty'),
            'cut_reject'        => $q('CutRejectQty'),
            'cut_pending_so'    => $q('CutPendingQtySO'),
            'cut_pending_co'    => $q('CutPendingQtyCO'),
            'ready_sewing'      => $q('CutReadyForSewing'),
            'sew_order'         => $q('SewOrderQty'),
            'sew_made'          => $q('SewMadeQty'),
            'sew_reject'        => $q('SewRejectQty'),
            'sew_pending_so'    => $q('SewPendingQtySO'),
            'sew_pending_fo'    => $q('SewPendingQtyFO'),
            'ready_wash'        => $q('GarmentReadyForWash'),
            'wash_sent'         => $q('WashSentQty'),
            'wash_received'     => $q('WashReceivedQty'),
            'wash_reject'       => $q('WashRejectQty'),
            'wash_pending_so'   => $q('WashPendingQtySO'),
            'wash_pending_spo'  => $q('WashPendingQtySPO'),
            'ready_finishing'   => $q('GarmentReadyForFinishing'),
            'fin_order'         => $q('FinOrderQty'),
            'fin_made'          => $q('FinMadeQty'),
            'fin_reject'        => $q('FinRejectQty'),
            'fin_pending_so'    => $q('FinPendingQtySO'),
            'fin_pending_po'    => $q('FinPendingQtyPO'),
            'ready_delivery'    => $q('GarmentReadyForDelivery'),
            'good_garment'      => $q('GoodGarmentQty'),
            'shipment'          => $q('ShipmentQty'),
            'pending_shipment'  => $q('PendingShipmentQty'),
            'delivery_transfer' => $q('DeliveryTransferQty'),
            'pending_delivery'  => $q('PendingDeliveryQty'),
        ];
    }
}
