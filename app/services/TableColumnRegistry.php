<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 29/09/2026
 * DESCRIPTION : Central column definitions (stable ids in default order) for every table that supports user column preferences
 */
require_once base_path('app/services/SapProcurementService.php');
require_once base_path('app/services/SapProductionService.php');
require_once base_path('app/services/SapPurchaseOrderService.php');

final class TableColumnRegistry
{
    /** Columns that always stay first and visible */
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
            'procurement_table' => $fromService(SapProcurementService::columns(), SapProcurementService::defaultHiddenColumns()),
            'production_table'  => $fromService(SapProductionService::columns(), SapProductionService::defaultHiddenColumns()),
            'production_range_table' => $fromService(SapProductionService::columns(), SapProductionService::defaultHiddenRangeColumns()),
            'purchase_order_table' => $fromService(SapPurchaseOrderService::columns(), []),
            'purchase_order_list_table' => $fromService(SapPurchaseOrderService::listColumns(), []),
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
        ];
        return $tables;
    }

    /*
     * Build a definition from id => label pairs (declaration order is the default order)
     */
    private static function define(array $labels, array $defaultHidden = []): array
    {
        $columns = [];
        foreach ($labels as $id => $label) {
            $columns[] = ['id' => (string) $id, 'label' => (string) $label, 'locked' => in_array($id, self::LOCKED, true)];
        }
        $known = array_keys($labels);
        return [
            'columns'       => $columns,
            'defaultHidden' => array_values(array_filter(
                $defaultHidden,
                static fn ($id): bool => in_array($id, $known, true) && !in_array($id, self::LOCKED, true)
            )),
        ];
    }
}
