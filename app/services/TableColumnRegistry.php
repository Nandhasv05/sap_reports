<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 29/09/2026
 * DESCRIPTION : Central column definitions (stable ids in default order) for every table that supports user column preferences
*/
require_once base_path('app/services/SapProcurementService.php');
require_once base_path('app/services/SapProductionService.php');
require_once base_path('app/services/SapPurchaseOrderService.php');
require_once base_path('app/services/SapCostService.php');
require_once base_path('app/services/PoValidationStatusService.php');

final class TableColumnRegistry
{
    /** Columns that always stay first and visible (tables may lock additional ids via define()) */
    public const LOCKED = ['sno'];

    /*
     * Table definition: ['columns' => [['id', 'label', 'locked']], 'defaultHidden' => [ids]] or null for an unknown table key
     */
    public static function get(string $tableKey): ?array
    {
        return self::tables()[$tableKey] ?? null;
    }

    /*
     * Column ids of a table definition in default order
     */
    public static function columnIds(array $definition): array
    {
        return array_column($definition['columns'], 'id');
    }

    /*
     * Locked column ids for a table definition (always visible / pinned first)
     */
    public static function lockedIds(array $definition): array
    {
        $ids = [];
        foreach ($definition['columns'] as $col) {
            if (!empty($col['locked'])) {
                $ids[] = (string) $col['id'];
            }
        }
        return $ids !== [] ? $ids : self::LOCKED;
    }

    private static function tables(): array
    {
        static $tables = null;
        if ($tables !== null) {
            return $tables;
        }

        $fromService = static fn (array $columns, array $defaultHidden): array => self::define(
            array_column($columns, 'label', 'key'),
            $defaultHidden
        );

        $tables = [
            'procurement_table'    => $fromService(SapProcurementService::columns(), SapProcurementService::defaultHiddenColumns()),
            'procurement_table_v2' => $fromService(SapProcurementService::columns(), SapProcurementService::defaultHiddenColumns()),
            'production_table'     => $fromService(SapProductionService::columns(), SapProductionService::defaultHiddenColumns()),
            'production_range_table' => $fromService(SapProductionService::columns(), SapProductionService::defaultHiddenRangeColumns()),
            'purchase_order_table' => $fromService(SapPurchaseOrderService::columns(), []),
            'purchase_order_list_table' => $fromService(SapPurchaseOrderService::listColumns(), []),
            'purchase_order_costing_table' => $fromService(SapCostService::costingColumns(), [
                'overhead', 'markup', 'subtot', 'total_fr', 'freight', 'duty', 'fin_cost',
            ]),
            'purchase_order_costing_list_table' => $fromService(PoValidationStatusService::costingListColumns(), [
                'material_type', 'mat_type_desc', 'material_group', 'created_by',
            ]),
            'purchase_order_costing_bulk_table' => self::define([
                'check'        => 'Select',
                'po'           => 'PO Number',
                'date'         => 'PO Date',
                'vendor_code'  => 'Vendor Code',
                'vendor_name'  => 'Vendor Name',
                'material'     => 'Material Type',
                'items'        => 'No. of Items',
                'value'        => 'PO Value (₹)',
                'passed'       => 'Passed',
                'exceptions'   => 'Exceptions',
                'pending'      => 'Pending',
                'status'       => 'Validation Status',
                'last'         => 'Last Validated',
                'validated_by' => 'Validated By',
                'actions'      => 'Actions',
            ], [], ['check', 'actions']),
            'purchase_order_costing_items_table' => self::define([
                'check'              => 'Select',
                'status'             => 'Status',
                'po_item'            => 'PO Item',
                'material'           => 'Material Code',
                'description'        => 'Material Description',
                'mat_category'       => 'Material Type',
                'sales_order'        => 'Sales Order',
                'po_qty'             => 'PO Qty',
                'unit'               => 'UOM',
                'currency'           => 'Currency',
                'net_value'          => 'Net Value (₹)',
                'costing_ref'        => 'Costing Ref',
                'cost_section'       => 'Cost Section',
                'cost_item'          => 'Cost Item',
                'cost_description'   => 'ZCOST Description',
                'expected_unit_cost' => 'Expected Unit Cost (₹)',
                'po_unit_cost'       => 'PO Unit Cost (₹)',
                'difference'         => 'Difference (₹)',
                'variance_pct'       => 'Variance %',
                'sell_price'         => 'Sell Price',
                'making'             => 'Making',
                'quoted_uom'         => 'Quoted UOM',
                'quoted_currency'    => 'Quoted Currency',
                'validation_result'  => 'Validation Result',
            ], [
                'sell_price', 'making', 'quoted_uom', 'quoted_currency',
            ], ['check']),
            'purchase_order_costing_workbench_table' => self::define([
                'sno'                 => 'S.No',
                'status'              => 'Status',
                'pono'                => 'PONO',
                'poitem'              => 'POITEM',
                'prno'                => 'PRNO',
                'salordno'            => 'SALORDNO',
                'style'               => 'STYLE',
                'material'            => 'MATERIAL',
                'matgroup'            => 'MATGROUP',
                'so_qty'              => 'SO_QTY',
                'prwise_so_qty'       => 'PRWISE_SO_QTY',
                'consumption'         => 'CONSUMPTION',
                'reqqty'              => 'REQQTY',
                'tot_poqty'           => 'TOT_POQTY',
                'so_wise_poqty'       => 'SO_WISE_POQTY',
                'so_wise_po_val'      => 'SO_WISE_PO_VAL',
                'ouom'                => 'OUOM',
                'porate'              => 'PORATE',
                'totvalue'            => 'TOTVALUE',
                'mat_desc'            => 'MAT_DESC',
                'po_currency'         => 'PO_CURRENCY',
                'cost_item'           => 'ITEM',
                'maktx'               => 'MAKTX',
                'budgeted_cons'       => 'BUDGETED_CONS',
                'budget_inr'          => 'BUDGET_INR',
                'budget_currency'     => 'BUDGET_CURRENCY',
                'budget_conv_rt'      => 'BUDGET_CONV_RT',
                'budget_cost_per_gmt' => 'BUDGET_COST_PER_GMT',
                'conv'                => 'CONV',
                'buom'                => 'BUOM',
                'actual_cost'         => 'ACTUAL_COST',
                'cost_matrix'         => 'zCOST Matrix',
                'variance_pct'        => 'Variance %',
                'validation_result'   => 'Validation Result',
            ], [
                'prno',
            ], ['sno', 'status']),
            'fabric_utilization_table' => self::define([
                'sno'                     => 'S.No',
                'sales_order'             => 'Sales Order',
                'material'                => 'Material',
                'description'             => 'Description',
                'season'                  => 'Season',
                'season_year'             => 'Season Year',
                'purchase_order'          => 'Purchase Order',
                'po_line'                 => 'PO Line',
                'so_qty'                  => 'SO Qty',
                'bom_qty'                 => 'BOM Qty',
                'total_bom_qty'           => 'Total Add SO BOM Qty',
                'planned_qty'             => 'Planned',
                'production_qty'          => 'Production',
                'po_qty'                  => 'PO Qty',
                'grn_qty'                 => 'GRN Qty',
                'issue_qty'               => 'Issue Qty',
                'additional_sales_orders' => 'Additional Sale Order',
                'attribute1'              => 'Attribute1_text',
                'attribute2'              => 'Attribute2_text',
                'attribute3'              => 'Attribute3_text',
                'colour'                  => 'Colour',
            ]),
            'trims_utilization_table' => self::define([
                'sno'                 => 'S.No',
                'sales_order'         => 'Sales Order',
                'material'            => 'Material',
                'description'         => 'Description',
                'material_type'       => 'MatType',
                'material_type_desc'  => 'MatTypeDesc',
                'material_group'      => 'MatGroup',
                'material_group_desc' => 'MatGroupDesc',
                'season'              => 'Season',
                'season_year'         => 'Season Year',
                'purchase_order'      => 'Purchase Order',
                'po_line'             => 'PO Line',
                'so_qty'              => 'SO Qty',
                'bom_qty'             => 'BOM Qty',
                'total_bom_qty'       => 'Total Add SO BOM Qty',
                'planned_qty'         => 'Planned',
                'production_qty'      => 'Production',
                'po_qty'              => 'PO Qty',
                'grn_qty'             => 'GRN Qty',
                'issue_qty'           => 'Issue Qty',
                'grn_sales_orders'    => 'Additional Sale Order',
                'colour'              => 'COLOR',
                'size1'               => 'SIZE1',
                'size2'               => 'SIZE2',
            ]),
            'fabric_sale_table' => self::define([
                'sno'                  => 'S.No',
                'so'                   => 'Sale Order',
                'date'                 => 'Date',
                'buyer'                => 'Buyer',
                'customer_group'       => 'Customer Group',
                'sales_district'       => 'Sales District',
                'material'             => 'Material',
                'style'                => 'Description',
                'orig_material'        => 'Orig. Material',
                'material_group'       => 'Mat. Group',
                'material_by_customer' => 'Mat. by Customer',
                'lines'                => 'Lines',
                'qty'                  => 'Order Qty',
                'requested_qty'        => 'Req. Qty',
                'confd_deliv_qty'      => 'Conf. Deliv Qty',
                'unit'                 => 'Unit',
                'unit_price'           => 'Unit Price',
                'value'                => 'Net Value',
                'cost'                 => 'Cost',
                'tax'                  => 'Tax',
                'currency'             => 'Currency',
                'status'               => 'Status',
                'delivery_status'      => 'Delivery Status',
                'rejection_status'     => 'Rejection Status',
                'plant'                => 'Plant',
                'storage_location'     => 'Storage Loc',
                'shipping_point'       => 'Shipping Point',
                'shipping_type'        => 'Shipping Type',
                'route'                => 'Route',
                'delivery_priority'    => 'Deliv. Priority',
                'sales_org'            => 'Sales Org',
                'channel'              => 'Channel',
                'division'             => 'Division',
                'so_type'              => 'SO Type',
                'item_category'        => 'Item Category',
                'item_type'            => 'Item Type',
                'incoterms'            => 'Incoterms',
                'payment_terms'        => 'Payment Terms',
                'billing_date'         => 'Billing Date',
                'pricing_date'         => 'Pricing Date',
                'last_change_date'     => 'Last Change',
                'created_by'           => 'Created By',
            ], [
                'customer_group',
                'sales_district',
                'style',
                'orig_material',
                'material_group',
                'material_by_customer',
                'requested_qty',
                'confd_deliv_qty',
                'cost',
                'tax',
                'rejection_status',
                'storage_location',
                'shipping_type',
                'route',
                'delivery_priority',
                'sales_org',
                'channel',
                'division',
                'so_type',
                'item_category',
                'item_type',
                'incoterms',
                'payment_terms',
                'billing_date',
                'pricing_date',
                'last_change_date',
            ], ['so']),
        ];
        return $tables;
    }

    /*
     * Build a definition from id => label pairs (declaration order is the default order).
     * $extraLocked pins additional columns with sno (always visible / first).
     */
    private static function define(array $labels, array $defaultHidden = [], array $extraLocked = []): array
    {
        $lockedIds = array_values(array_unique(array_merge(self::LOCKED, $extraLocked)));
        $columns = [];
        foreach ($labels as $id => $label) {
            $columns[] = [
                'id'     => (string) $id,
                'label'  => (string) $label,
                'locked' => in_array((string) $id, $lockedIds, true),
            ];
        }
        $known = array_map('strval', array_keys($labels));
        return [
            'columns'       => $columns,
            'defaultHidden' => array_values(array_filter(
                $defaultHidden,
                static fn ($id): bool => in_array($id, $known, true) && !in_array($id, $lockedIds, true)
            )),
        ];
    }
}
