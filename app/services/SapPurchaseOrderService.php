<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 01/10/2026
 * DESCRIPTION : SAP purchase order list (condition-wise) and details from ZI_PURCHASEORDERAPI_HUB_CDS/ZI_PurchaseOrderAPI_HUB
*/
require_once base_path('app/core/SapODataClient.php');
require_once base_path('app/services/SapValueFormat.php');

class SapPurchaseOrderService
{
    use SapValueFormat;

    public const MAX_RANGE_DAYS = 100;
    private const DEFAULT_SERVICE = '/sap/opu/odata/sap/ZI_PURCHASEORDERAPI_HUB_CDS/ZI_PurchaseOrderAPI_HUB';
    private const SALES_ORDER_FIELDS = 20;
    private const RANGE_TIMEZONE = 'Asia/Kolkata';

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
     * Date presets for PO filtering (exact order requested: Today, Yesterday, This Week, Previous Week, This Month, Previous Month, Custom Date)
     */
    public static function rangePresets(): array
    {
        return [
            'today'      => 'Today',
            'yesterday'  => 'Yesterday',
            'this_week'  => 'This Week',
            'last_week'  => 'Previous Week',
            'this_month' => 'This Month',
            'last_month' => 'Previous Month',
            'custom'     => 'Custom Date',
        ];
    }

    public const DOCUMENT_TYPES = [
        ['code' => 'AN',   'name' => 'RFQ'],
        ['code' => 'DB',   'name' => 'Dummy Purchase Order'],
        ['code' => 'ENB',  'name' => 'Standard PO DFPS'],
        ['code' => 'NB',   'name' => 'Purchase Requisition'],
        ['code' => 'NB',   'name' => 'Standard PO'],
        ['code' => 'NBXE', 'name' => 'XLO Inter Com PO'],
        ['code' => 'UB',   'name' => 'Stock Transp. Order'],
        ['code' => 'ZCAP', 'name' => 'Capital/Asset PO'],
        ['code' => 'ZDOM', 'name' => 'Domestic Pur Order'],
        ['code' => 'ZDSS', 'name' => 'Domestic Sub Scrn Cr'],
        ['code' => 'ZEIS', 'name' => 'Enterprise Intra STO'],
        ['code' => 'ZIMP', 'name' => 'Import Pur Order'],
        ['code' => 'ZINT', 'name' => 'Enterprise Inter STO'],
        ['code' => 'ZRTV', 'name' => 'Return Pur Order'],
        ['code' => 'ZSCP', 'name' => 'Subcontracting PO'],
        ['code' => 'ZSER', 'name' => 'Service Purchase Ord'],
        ['code' => 'ZST1', 'name' => 'Stock Transp. Order'],
        ['code' => 'ZSTO', 'name' => 'Stock Transp order'],
    ];

    /*
     * Purchasing Document Types list for UI dropdown
     */
    public static function documentTypes(): array
    {
        return self::DOCUMENT_TYPES;
    }

    /*
     * Code to Description map for fallback lookup
     */
    public static function documentTypeMap(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (self::DOCUMENT_TYPES as $dt) {
                $map[$dt['code']] = $dt['name'];
            }
        }
        return $map;
    }

    /*
     * Resolve date range preset or custom From/To into Y-m-d format (custom max 100 days)
     *
     * @return array{preset: string, from: string, to: string, label: string, error: ?string}
     */
    public static function resolveRange(string $preset, string $from = '', string $to = ''): array
    {
        $tz = new DateTimeZone(self::RANGE_TIMEZONE);
        $today = new DateTimeImmutable('today', $tz);
        $preset = strtolower(trim($preset));
        // No date filter — used by PO Validation bulk list
        if ($preset === 'all') {
            return ['preset' => 'all', 'from' => '', 'to' => '', 'label' => 'All', 'error' => null];
        }
        $preset = array_key_exists($preset, self::rangePresets()) ? $preset : 'today';
        $out = ['preset' => $preset, 'from' => '', 'to' => '', 'label' => self::rangePresets()[$preset], 'error' => null];

        [$start, $end] = match ($preset) {
            'today'      => [$today, $today],
            'yesterday'  => [$today->modify('-1 day'), $today->modify('-1 day')],
            'this_week'  => [$today->modify('monday this week'), $today],
            'last_week'  => [$today->modify('monday last week'), $today->modify('monday last week')->modify('+6 days')],
            'this_month' => [$today->modify('first day of this month'), $today],
            'last_month' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
            'custom'     => [
                $from !== '' ? self::parseDate($from, $tz) : $today->modify('-7 days'),
                $to !== '' ? self::parseDate($to, $tz) : $today
            ],
            default      => [self::parseDate($from, $tz), self::parseDate($to, $tz)],
        };

        if ($start === null || $end === null) {
            $out['error'] = 'Choose both a From and a To date.';
            return $out;
        }
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        if ($end > $today) {
            $end = $today;
        }
        if ($start > $today) {
            $out['error'] = 'The date range is in the future.';
            return $out;
        }
        $minAllowed = $today->modify('-' . self::MAX_RANGE_DAYS . ' days');
        if ($start < $minAllowed) {
            $out['error'] = 'Select dates within the last ' . self::MAX_RANGE_DAYS . ' days.';
            return $out;
        }
        if ((int) $start->diff($end)->days + 1 > self::MAX_RANGE_DAYS) {
            $out['error'] = 'Custom Date allows a maximum of ' . self::MAX_RANGE_DAYS . ' days.';
            return $out;
        }

        $out['from'] = $start->format('Y-m-d');
        $out['to'] = $end->format('Y-m-d');
        if ($preset === 'custom') {
            $out['label'] = $out['from'] === $out['to'] ? $start->format('d M Y') : $start->format('d M Y') . ' – ' . $end->format('d M Y');
        }
        return $out;
    }

    /*
     * From / To (Y-m-d) of presets for client-side date population
     */
    public static function presetDates(): array
    {
        $out = [];
        foreach (array_keys(self::rangePresets()) as $key) {
            if ($key !== 'custom') {
                $r = self::resolveRange($key);
                $out[$key] = ['from' => $r['from'], 'to' => $r['to']];
            }
        }
        return $out;
    }

    private static function parseDate(string $value, DateTimeZone $tz): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), $tz);
        return $date !== false && $date->format('Y-m-d') === trim($value) ? $date : null;
    }

    /*
     * Configured production plants
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
     * Columns for the condition-wise Purchase Order list view (action column removed)
     */
    public static function listColumns(): array
    {
        return [
            ['key' => 'sno',            'label' => 'S.No',         'type' => 'num'],
            ['key' => 'purchase_order', 'label' => 'PO Number',    'type' => 'text'],
            ['key' => 'purchase_order_category', 'label' => 'PO Category', 'type' => 'text'],
            ['key' => 'purchase_order_type', 'label' => 'PO Type', 'type' => 'text'],
            ['key' => 'po_type_name',   'label' => 'PO Type Name', 'type' => 'text'],
            ['key' => 'date',           'label' => 'PO Date',      'type' => 'date'],
            ['key' => 'plant',          'label' => 'Plant',        'type' => 'text'],
            ['key' => 'supplier',       'label' => 'Supplier',     'type' => 'text'],
            ['key' => 'supplier_full_name', 'label' => 'Supplier Name', 'type' => 'text'],
            ['key' => 'brand_name',     'label' => 'Brand Name',   'type' => 'text'],
            ['key' => 'materials',      'label' => 'Material',     'type' => 'text'],
            ['key' => 'material_type',  'label' => 'MaterialType', 'type' => 'text'],
            ['key' => 'mat_type_desc',  'label' => 'MatTypeDesc',  'type' => 'text'],
            ['key' => 'material_group', 'label' => 'MaterialGroup','type' => 'text'],
            ['key' => 'items_count',    'label' => 'Items',        'type' => 'num'],
            ['key' => 'total_qty',      'label' => 'Order Qty',    'type' => 'num'],
            ['key' => 'unit',           'label' => 'Unit',         'type' => 'text'],
            ['key' => 'total_value',    'label' => 'Net Value',    'type' => 'num'],
            ['key' => 'currency',       'label' => 'Currency',     'type' => 'text'],
            ['key' => 'sales_orders',   'label' => 'Sales Orders', 'type' => 'text'],
            ['key' => 'created_by',     'label' => 'Created By',   'type' => 'text'],
        ];
    }

    /*
     * Material category filter options (All / Fabric / Trims)
     */
    public static function materialCategories(): array
    {
        return [
            'all'    => 'All Materials',
            'fabric' => 'Fabric',
            'trims'  => 'Trims',
        ];
    }

    /*
     * Brand hotcodes (SAP BrandName) → display names for filter dropdowns
     *
     * @return list<array{code:string,name:string}>
     */
    public static function brands(): array
    {
        return [
            ['code' => 'ANSN', 'name' => 'AND SONS'],
            ['code' => 'AS',   'name' => 'tAS'],
            ['code' => 'ASOS', 'name' => 'ASOS'],
            ['code' => 'BB',   'name' => 'BB'],
            ['code' => 'BBOX', 'name' => 'Banana Boxes'],
            ['code' => 'BONO', 'name' => 'BONOBOS'],
            ['code' => 'BRAN', 'name' => 'BRANDIS'],
            ['code' => 'BRBR', 'name' => 'BARBOUR'],
            ['code' => 'BROO', 'name' => 'BROOKS BROTHERS'],
            ['code' => 'COLP', 'name' => 'COLOUR PLUS'],
            ['code' => 'DICK', 'name' => 'dickies'],
            ['code' => 'DRIZ', 'name' => 'DRIZA BONE'],
            ['code' => 'DUMM', 'name' => 'DUMMY'],
            ['code' => 'EM',   'name' => 'EM'],
            ['code' => 'EVLR', 'name' => 'EVOLV RETAIL'],
            ['code' => 'EVOL', 'name' => 'EVOLV'],
            ['code' => 'EVZI', 'name' => 'EVOLUZIONE'],
            ['code' => 'EWBB', 'name' => 'EWIN BEL & BO'],
            ['code' => 'EWBL', 'name' => 'EWIN BLAUER'],
            ['code' => 'EWBM', 'name' => 'EWIN BOMBOOGIE'],
            ['code' => 'EWBR', 'name' => 'EWIN BARBOUR'],
            ['code' => 'EWBS', 'name' => 'EWIN BIG STAR'],
            ['code' => 'EWBT', 'name' => 'EWIN BENETTON'],
            ['code' => 'EWDL', 'name' => 'EWIN-DIESEL'],
            ['code' => 'EWFI', 'name' => 'EWIN-WEIRD FISH'],
            ['code' => 'EWMA', 'name' => 'EWIN MONICA +ANDY'],
            ['code' => 'EWMI', 'name' => 'EWIN MEXIM INDIA'],
            ['code' => 'EWNT', 'name' => 'EWIN NATIONAL'],
            ['code' => 'EWOK', 'name' => 'EWIN OLD KHAKI'],
            ['code' => 'EWPC', 'name' => 'EWIN PICTURE'],
            ['code' => 'EWPT', 'name' => 'EWIN PEUTEREY'],
            ['code' => 'EWSL', 'name' => 'EWIN SCALPERS'],
            ['code' => 'EWSO', 'name' => 'EWIN S OLIVER'],
            ['code' => 'EWUB', 'name' => 'EWIN UNITED BY BLUE'],
            ['code' => 'EWUW', 'name' => 'EWIN UNIVERSAL WORKS'],
            ['code' => 'EXPR', 'name' => 'EXPRESS'],
            ['code' => 'FAB',  'name' => 'FABDIST'],
            ['code' => 'FACC', 'name' => 'FACCONABLE'],
            ['code' => 'FILI', 'name' => 'FILIPPA K'],
            ['code' => 'FYHA', 'name' => 'FYNCH HATTON'],
            ['code' => 'G-ST', 'name' => 'G-STAR'],
            ['code' => 'GEN',  'name' => 'GENERAL'],
            ['code' => 'HARM', 'name' => 'HARMONT & BLAINE'],
            ['code' => 'ICHI', 'name' => 'ICHI'],
            ['code' => 'INN',  'name' => 'INNOVATION'],
            ['code' => 'IT',   'name' => 'INDIAN TERRAIN'],
            ['code' => 'JOHN', 'name' => 'JOHN VARVATOS'],
            ['code' => 'JSBR', 'name' => 'JUST BRAND'],
            ['code' => 'JV',   'name' => 'JV'],
            ['code' => 'LEND', 'name' => 'LANDS END'],
            ['code' => 'LIFE', 'name' => 'FUTURE LIFESTYLE'],
            ['code' => 'MAPO', 'name' => "Marc O'Polo"],
            ['code' => 'NAPA', 'name' => 'NAPAPAPIJRI'],
            ['code' => 'NGA',  'name' => 'NGA'],
            ['code' => 'NORD', 'name' => 'NORDSTROM'],
            ['code' => 'NORS', 'name' => 'NORTH SAILS'],
            ['code' => 'NZAK', 'name' => 'NEWZEALAND AUCKLAND'],
            ['code' => 'ONE',  'name' => 'ONEILL'],
            ['code' => 'PACE', 'name' => 'PACE ACTIVE'],
            ['code' => 'PAIG', 'name' => 'PAIGE'],
            ['code' => 'POLO', 'name' => 'POLO'],
            ['code' => 'RBTL', 'name' => 'ROBERT TALBOTT'],
            ['code' => 'SOFT', 'name' => 'SOFT SURROUNDINGS'],
            ['code' => 'SPLA', 'name' => 'SPLASH'],
            ['code' => 'SQST', 'name' => 'SEQUESTO'],
            ['code' => 'STID', 'name' => 'SOUTHERN TIDE'],
            ['code' => 'STUS', 'name' => 'STUSSY'],
            ['code' => 'TEND', 'name' => 'TEN THOUSAND'],
            ['code' => 'THFR', 'name' => 'TOMMY HILFIGER'],
            ['code' => 'TIMB', 'name' => 'TIMBERLAND'],
            ['code' => 'TNOR', 'name' => 'THE NORMAL BRAND'],
            ['code' => 'TOBA', 'name' => 'TOMMY BAHAMA'],
            ['code' => 'TOM',  'name' => 'TOM TAILOR'],
            ['code' => 'VERM', 'name' => 'VERMONT'],
            ['code' => 'VFS',  'name' => 'VFS'],
            ['code' => 'VINY', 'name' => 'Vineyard'],
            ['code' => 'WIND', 'name' => 'WIN INDIA'],
            ['code' => 'WNU',  'name' => 'WITH NOTHING UNDERNEATH'],
            ['code' => 'WOOL', 'name' => 'WOOLRICH'],
            ['code' => 'WRAN', 'name' => 'WRANGLER'],
            ['code' => 'ZARA', 'name' => 'ZARA'],
        ];
    }

    /** code => name map for BrandName display */
    public static function brandMap(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (self::brands() as $b) {
                $map[$b['code']] = $b['name'];
            }
        }
        return $map;
    }

    /** Normalize filter value to a known brand code, or '' for All */
    public static function normalizeBrand(string $brand): string
    {
        $brand = strtoupper(trim($brand));
        if ($brand === '' || $brand === 'ALL') {
            return '';
        }
        // Preserve exact code casing from catalog (e.g. G-ST)
        foreach (self::brands() as $b) {
            if (strcasecmp($b['code'], $brand) === 0) {
                return $b['code'];
            }
        }
        return '';
    }

    /**
     * Normalize one or many brand filter values to known codes.
     *
     * @param mixed $raw comma string, array, or single code
     * @return list<string>
     */
    public static function normalizeBrands($raw): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = preg_split('/[,\s]+/', (string) $raw) ?: [];
        }
        $out = [];
        foreach ($parts as $part) {
            $code = self::normalizeBrand((string) $part);
            if ($code !== '' && !in_array($code, $out, true)) {
                $out[] = $code;
            }
        }
        return $out;
    }

    /** Friendly label for a SAP BrandName code */
    public static function brandLabel(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return '';
        }
        $map = self::brandMap();
        foreach ($map as $k => $name) {
            if (strcasecmp((string) $k, $code) === 0) {
                return (string) $name;
            }
        }
        return $code;
    }

    /** Fabric MaterialType codes only */
    public const FABRIC_MATERIAL_TYPES = ['ZFRI', 'ZFRD'];

    /** Trims MaterialType codes only */
    public const TRIMS_MATERIAL_TYPES = ['ZTRD', 'ZTRI'];

    /*
     * Classify a PO line as fabric | trims | other from MaterialType only.
     * Fabric: ZFRI, ZFRD | Trims: ZTRD, ZTRI
     */
    public static function classifyMaterial(string $materialType, string $matTypeDesc = '', string $materialGroup = ''): string
    {
        $type = strtoupper(trim($materialType));
        if (in_array($type, self::FABRIC_MATERIAL_TYPES, true)) {
            return 'fabric';
        }
        if (in_array($type, self::TRIMS_MATERIAL_TYPES, true)) {
            return 'trims';
        }
        return 'other';
    }

    /*
     * Columns for the single Purchase Order item table
     */
    public static function columns(): array
    {
        return [
            ['key' => 'sno',              'label' => 'S.No',         'type' => 'num'],
            ['key' => 'item',             'label' => 'Item',         'type' => 'num'],
            ['key' => 'purchase_order_category', 'label' => 'PO Category', 'type' => 'text'],
            ['key' => 'purchase_order_type', 'label' => 'PO Type', 'type' => 'text'],
            ['key' => 'po_type_name',     'label' => 'PO Type Name', 'type' => 'text'],
            ['key' => 'supplier',         'label' => 'Supplier',     'type' => 'text'],
            ['key' => 'supplier_full_name', 'label' => 'Supplier Name', 'type' => 'text'],
            ['key' => 'brand_name',       'label' => 'Brand Name',   'type' => 'text'],
            ['key' => 'material',         'label' => 'Material',     'type' => 'text'],
            ['key' => 'description',      'label' => 'Description',  'type' => 'text'],
            ['key' => 'material_type',    'label' => 'MaterialType', 'type' => 'text'],
            ['key' => 'mat_type_desc',    'label' => 'MatTypeDesc',  'type' => 'text'],
            ['key' => 'material_group',   'label' => 'MaterialGroup','type' => 'text'],
            ['key' => 'plant',            'label' => 'Plant',        'type' => 'text'],
            ['key' => 'storage_location', 'label' => 'Storage Loc.', 'type' => 'text'],
            ['key' => 'order_qty',        'label' => 'Order Qty',    'type' => 'num'],
            ['key' => 'unit',             'label' => 'Unit',         'type' => 'text'],
            ['key' => 'net_price',        'label' => 'Net Price',    'type' => 'num'],
            ['key' => 'net_value',        'label' => 'Net Value',    'type' => 'num'],
            ['key' => 'currency',         'label' => 'Currency',     'type' => 'text'],
            ['key' => 'sales_orders',     'label' => 'Sales Orders', 'type' => 'text'],
        ];
    }

    /*
     * Condition-wise PO list from SAP ZI_PurchaseOrderAPI_HUB
     *
     * @param array<string, mixed> $filters
     * @return array{records: array, total: int, totals: array, range: array, error: ?string}
     */
    public function listReport(array $filters = []): array
    {
        $preset = strtolower(trim((string) ($filters['range'] ?? 'today')));
        $range = self::resolveRange($preset, (string) ($filters['from'] ?? ''), (string) ($filters['to'] ?? ''));
        $plant = strtoupper(trim((string) ($filters['plant'] ?? '')));
        $rawSuppliers = $filters['supplier'] ?? $filters['vendor'] ?? '';
        if (is_array($rawSuppliers)) {
            $suppliers = array_values(array_unique(array_filter(array_map('trim', $rawSuppliers))));
        } else {
            $suppliers = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $rawSuppliers)))));
        }
        $supplier = implode(',', $suppliers);
        $so = preg_replace('/\D/', '', (string) ($filters['so'] ?? '')) ?? '';

        $rawTypes = $filters['po_type'] ?? $filters['type'] ?? '';
        if (is_array($rawTypes)) {
            $poTypes = array_values(array_unique(array_filter(array_map('strtoupper', array_map('trim', $rawTypes)))));
        } else {
            $poTypes = array_values(array_unique(array_filter(array_map('strtoupper', array_map('trim', explode(',', (string) $rawTypes))))));
        }

        $matCat = strtolower(trim((string) ($filters['mat_cat'] ?? $filters['category'] ?? 'all')));
        if (!array_key_exists($matCat, self::materialCategories())) {
            $matCat = 'all';
        }

        $brands = self::normalizeBrands($filters['brand'] ?? $filters['brand_name'] ?? $filters['BrandName'] ?? '');
        $brand = $brands[0] ?? '';

        $searchPo = preg_replace('/\D/', '', (string) ($filters['po'] ?? $filters['purchase_order'] ?? $filters['q'] ?? '')) ?? '';
        if ($searchPo !== '' && !preg_match('/^\d{1,10}$/', $searchPo)) {
            $searchPo = '';
        }

        $approvedStatus = trim((string) ($filters['approved_status'] ?? $filters['APPROVEDSTATUS'] ?? ''));
        if (!in_array($approvedStatus, ['Approved', 'NotApproved'], true)) {
            $approvedStatus = '';
        }
        // Exact PO search should find the PO regardless of Approved / Unapproved toggle
        if ($searchPo !== '') {
            $approvedStatus = '';
        }

        $emptyTotals = [
            'pos_count'       => 0,
            'items_count'     => 0,
            'total_qty'       => 0,
            'total_value'     => 0,
            'currency'        => 'INR',
            'suppliers_count' => 0,
        ];

        if (!empty($range['error'])) {
            return [
                'records'       => [],
                'total'         => 0,
                'totals'        => $emptyTotals,
                'range'         => $range,
                'truncated'     => false,
                'items_fetched' => 0,
                'error'         => $range['error'],
            ];
        }

        $typesKey = $poTypes;
        sort($typesKey);
        $supKey = $suppliers;
        sort($supKey);
        // Paginate only when the client asks (UI list). CSV / internal callers get the full set.
        $paginate = array_key_exists('page', $filters)
            || array_key_exists('page_size', $filters)
            || array_key_exists('limit', $filters);
        $pageSize = (int) ($filters['page_size'] ?? $filters['limit'] ?? 25);
        $page = (int) ($filters['page'] ?? 1);
        if ($pageSize < 1) {
            $pageSize = 25;
        }
        if ($pageSize > 100) {
            $pageSize = 100;
        }
        if ($page < 1) {
            $page = 1;
        }

        // Live SAP only — do not read/write storage/cache for PO Validation list.
        $filterParts = [];
        if ($range['preset'] !== 'all' && $range['from'] !== '' && $range['to'] !== '') {
            $filterParts[] = "PurchaseOrderDate ge datetime'{$range['from']}T00:00:00' and PurchaseOrderDate le datetime'{$range['to']}T23:59:59'";
        }
        // APPROVEDSTATUS is not on ZI_PurchaseOrderAPI_HUB — filter locally after fetch.
        if ($plant !== '') {
            $filterParts[] = "Plant eq '{$plant}'";
        }
        if (!empty($suppliers)) {
            if (count($suppliers) === 1) {
                $supPadded = $this->padSalesOrder($suppliers[0]);
                $filterParts[] = "(Supplier eq '{$suppliers[0]}' or Supplier eq '{$supPadded}')";
            } else {
                $supClauses = [];
                foreach ($suppliers as $s) {
                    $supPadded = $this->padSalesOrder($s);
                    $supClauses[] = "Supplier eq '{$s}' or Supplier eq '{$supPadded}'";
                }
                $filterParts[] = '(' . implode(' or ', $supClauses) . ')';
            }
        }
        if (!empty($poTypes)) {
            if (count($poTypes) === 1) {
                $filterParts[] = "PurchaseOrderType eq '{$poTypes[0]}'";
            } else {
                $typeClauses = array_map(static fn (string $t): string => "PurchaseOrderType eq '{$t}'", $poTypes);
                $filterParts[] = '(' . implode(' or ', $typeClauses) . ')';
            }
        }
        if ($brands !== []) {
            if (count($brands) === 1) {
                $brandEsc = str_replace("'", "''", $brands[0]);
                $filterParts[] = "BrandName eq '{$brandEsc}'";
            } else {
                $brandClauses = array_map(
                    static function (string $code): string {
                        $esc = str_replace("'", "''", $code);
                        return "BrandName eq '{$esc}'";
                    },
                    $brands
                );
                $filterParts[] = '(' . implode(' or ', $brandClauses) . ')';
            }
        }
        if ($searchPo !== '') {
            $poDisplay = $this->displayNumber($searchPo);
            $poPadded = preg_match('/^\d+$/', $poDisplay)
                ? str_pad($poDisplay, 10, '0', STR_PAD_LEFT)
                : $poDisplay;
            if ($poPadded !== '' && $poPadded !== $poDisplay) {
                $filterParts[] = "(PurchaseOrder eq '{$poDisplay}' or PurchaseOrder eq '{$poPadded}')";
            } else {
                $filterParts[] = "PurchaseOrder eq '{$poDisplay}'";
            }
        }

        // Do not pass $top here — SapODataClient paginates ($skip) up to config max_rows.
        // A hard $top=1000 was truncating Previous Month (and other ranges) to ~1000 line items
        // which then aggregated into far fewer purchase orders (e.g. 126 POs / 1,000 items).
        $query = [
            '$orderby' => 'PurchaseOrderDate desc, PurchaseOrder desc',
        ];
        if (!empty($filterParts)) {
            $query['$filter'] = implode(' and ', $filterParts);
        }

        $service = (string) ($this->cfg['purchase_order_service'] ?? self::DEFAULT_SERVICE);
        $maxRows = max(1, (int) ($this->cfg['po_max_rows'] ?? $this->cfg['max_rows'] ?? 10000));
        // No date filter can span the full PO hub — cap line fetch so PHP/SAP do not time out
        // with an empty response body ("Unexpected end of JSON input" in the browser).
        $maxUniquePos = 0;
        if ($range['preset'] === 'all') {
            $maxRows = min($maxRows, max(500, (int) ($this->cfg['po_all_max_rows'] ?? 5000)));
            $maxUniquePos = max(100, (int) ($this->cfg['po_all_max_pos'] ?? 400));
        }

        // Stream pages and aggregate per-PO so wide Custom Date ranges do not OOM
        // (fetchResults kept every SAP line in memory and exhausted 128MB).
        $pos = [];
        $currencies = [];
        $docTypeMap = self::documentTypeMap();
        $pageResult = $this->client->eachPage(function (array $batch) use (
            &$pos,
            &$currencies,
            $poTypes,
            $so,
            $matCat,
            $docTypeMap,
            $maxUniquePos
        ) {
            foreach ($batch as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $poNum = $this->displayNumber((string) ($row['PurchaseOrder'] ?? ''));
                if ($poNum === '') {
                    continue;
                }

                $rowPoType = trim((string) ($row['PurchaseOrderType'] ?? ''));
                if (!empty($poTypes) && !in_array(strtoupper($rowPoType), $poTypes, true)) {
                    continue;
                }

                $soList = [];
                for ($i = 1; $i <= self::SALES_ORDER_FIELDS; $i++) {
                    $v = $this->displayNumber((string) ($row['zzvbeln' . $i] ?? ''));
                    if ($v !== '') {
                        $soList[] = $v;
                    }
                }
                if ($so !== '' && !in_array($so, $soList, true)) {
                    continue;
                }

                $materialType = trim((string) ($row['MaterialType'] ?? ''));
                $matTypeDesc = trim((string) ($row['MatTypeDesc'] ?? ''));
                $materialGroup = trim((string) ($row['MaterialGroup'] ?? ''));
                $lineCat = self::classifyMaterial($materialType, $matTypeDesc, $materialGroup);
                if ($matCat !== 'all' && $lineCat !== $matCat) {
                    continue;
                }

                if (!isset($pos[$poNum])) {
                    $typeName = trim((string) ($row['PurchasingDocumentTypeName'] ?? ''));
                    if ($typeName === '' && isset($docTypeMap[$rowPoType])) {
                        $typeName = $docTypeMap[$rowPoType];
                    }

                    $pos[$poNum] = [
                        'purchase_order'         => $poNum,
                        'purchase_order_category'=> trim((string) ($row['PurchaseOrderCategory'] ?? '')),
                        'purchase_order_type'    => $rowPoType,
                        'po_type_name'           => $typeName,
                        'date'                   => $this->sapDate((string) ($row['PurchaseOrderDate'] ?? '')),
                        'supplier'               => $this->displayNumber((string) ($row['Supplier'] ?? '')),
                        'supplier_full_name'     => trim((string) ($row['SupplierFullName'] ?? '')),
                        'brand_name'             => '',
                        'company_code'           => trim((string) ($row['CompanyCode'] ?? '')),
                        'purchasing_org'         => trim((string) ($row['PurchasingOrganization'] ?? '')),
                        'purchasing_group'       => trim((string) ($row['PurchasingGroup'] ?? '')),
                        'created_by'             => trim((string) ($row['CreatedByUser'] ?? '')),
                        'plant'                  => trim((string) ($row['Plant'] ?? '')),
                        'plants'                 => [],
                        'approved_status'        => '',
                        'items_count'            => 0,
                        'materials'              => [],
                        'material_types'         => [],
                        'mat_categories'         => [],
                        'mat_type_descs'         => [],
                        'material_groups'        => [],
                        'brand_names'            => [],
                        'total_qty'              => 0.0,
                        'unit'                   => trim((string) ($row['PurchaseOrderQuantityUnit'] ?? '')),
                        'total_value'            => 0.0,
                        'currency'               => trim((string) ($row['DocumentCurrency'] ?? '')),
                        'sales_orders'           => [],
                    ];
                }

                $p = &$pos[$poNum];
                $p['items_count']++;
                $plantVal = trim((string) ($row['Plant'] ?? ''));
                if ($plantVal !== '' && !in_array($plantVal, $p['plants'], true)) {
                    $p['plants'][] = $plantVal;
                }
                $mat = trim((string) ($row['Material'] ?? ''));
                if ($mat !== '' && !in_array($mat, $p['materials'], true)) {
                    $p['materials'][] = $mat;
                }
                if ($lineCat !== 'other' && !in_array($lineCat, $p['mat_categories'], true)) {
                    $p['mat_categories'][] = $lineCat;
                }
                if ($materialType !== '' && !in_array($materialType, $p['material_types'], true)) {
                    $p['material_types'][] = $materialType;
                }
                if ($matTypeDesc !== '' && !in_array($matTypeDesc, $p['mat_type_descs'], true)) {
                    $p['mat_type_descs'][] = $matTypeDesc;
                }
                if ($materialGroup !== '' && !in_array($materialGroup, $p['material_groups'], true)) {
                    $p['material_groups'][] = $materialGroup;
                }
                $brandName = trim((string) ($row['BrandName'] ?? ''));
                if ($brandName !== '' && !in_array($brandName, $p['brand_names'], true)) {
                    $p['brand_names'][] = $brandName;
                }
                $qty = $this->sapNumber($row['OrderQuantity'] ?? '');
                $price = $this->sapNumber($row['NetPriceAmount'] ?? '');
                $p['total_qty'] += $qty;
                $p['total_value'] += round($qty * $price, 2);
                foreach ($soList as $s) {
                    if (!in_array($s, $p['sales_orders'], true)) {
                        $p['sales_orders'][] = $s;
                    }
                }
                if ($p['currency'] !== '') {
                    $currencies[$p['currency']] = ($currencies[$p['currency']] ?? 0) + 1;
                }
                unset($p);

                if ($maxUniquePos > 0 && count($pos) >= $maxUniquePos) {
                    return false;
                }
            }
            return $maxUniquePos > 0 && count($pos) >= $maxUniquePos ? false : true;
        }, $query, $service, $maxRows);

        $fetchError = $pageResult['error'] ?? null;
        if (is_string($fetchError) && str_starts_with($fetchError, 'No records')) {
            $fetchError = null;
        }
        if ($fetchError !== null) {
            return [
                'records'       => [],
                'total'         => 0,
                'totals'        => $emptyTotals,
                'range'         => $range,
                'truncated'     => false,
                'items_fetched' => 0,
                'error'         => $fetchError,
            ];
        }

        $itemsFetched = (int) ($pageResult['row_count'] ?? 0);
        $truncated = $itemsFetched >= $maxRows
            || ($maxUniquePos > 0 && count($pos) >= $maxUniquePos);

        $records = array_values($pos);
        foreach ($records as &$r) {
            $r['plant'] = implode(', ', $r['plants']);
            $r['total_qty'] = round($r['total_qty'], 3);
            $r['total_value'] = round($r['total_value'], 2);
            $r['materials_count'] = count($r['materials']);
            // List view Material column: unique materials on the PO (search/export friendly string)
            $r['material'] = implode(', ', $r['materials']);
            $r['material_type'] = implode(', ', $r['material_types']);
            $r['mat_type_desc'] = implode(', ', $r['mat_type_descs']);
            $r['material_group'] = implode(', ', $r['material_groups']);
            $codes = $r['brand_names'] ?? [];
            $r['brand_code'] = implode(', ', $codes);
            $labels = [];
            foreach ($codes as $code) {
                $label = self::brandLabel((string) $code);
                if ($label !== '' && !in_array($label, $labels, true)) {
                    $labels[] = $label;
                }
            }
            $r['brand_name'] = $labels !== [] ? implode(', ', $labels) : implode(', ', $codes);
            $cats = $r['mat_categories'] ?? [];
            if (count($cats) > 1) {
                $r['mat_category'] = 'mixed';
            } elseif (count($cats) === 1) {
                $r['mat_category'] = $cats[0];
            } else {
                $r['mat_category'] = 'other';
            }
        }
        unset($r);

        // APPROVEDSTATUS from Z_QuotationZCostCom (ApprovedStatus eq 'NotApproved' / 'Approved'),
        // merged with local Pass log — SAP PO hub has no APPROVEDSTATUS property.
        $poKeys = array_column($records, 'purchase_order');
        $sapApprovedSet = $this->sapZcostApprovedPurchaseOrderKeys($poKeys);
        $localApprovedSet = $this->approvedPurchaseOrderKeys($poKeys);
        foreach ($records as &$r) {
            $key = $this->poLookupKey((string) ($r['purchase_order'] ?? ''));
            $isApproved = $key !== '' && (isset($sapApprovedSet[$key]) || isset($localApprovedSet[$key]));
            $r['approved_status'] = $isApproved ? 'Approved' : 'NotApproved';
        }
        unset($r);
        if ($approvedStatus === 'Approved' || $approvedStatus === 'NotApproved') {
            $records = array_values(array_filter(
                $records,
                static fn (array $r): bool => (($r['approved_status'] ?? '') === $approvedStatus)
            ));
            $currencies = [];
            foreach ($records as $r) {
                $cur = trim((string) ($r['currency'] ?? ''));
                if ($cur !== '') {
                    $currencies[$cur] = ($currencies[$cur] ?? 0) + 1;
                }
            }
        }

        arsort($currencies);
        $primaryCurrency = key($currencies) ?: 'INR';

        $totalQty = 0.0;
        $totalVal = 0.0;
        $totalItems = 0;
        $uniqueSuppliers = [];
        foreach ($records as $r) {
            $totalQty += $r['total_qty'];
            $totalVal += $r['total_value'];
            $totalItems += $r['items_count'];
            $supCode = trim((string) ($r['supplier'] ?? ''));
            if ($supCode !== '' && $supCode !== '—') {
                $supName = trim((string) ($r['supplier_full_name'] ?? ''));
                if (!isset($uniqueSuppliers[$supCode])) {
                    $uniqueSuppliers[$supCode] = [
                        'code' => $supCode,
                        'name' => $supName !== '' ? $supName : ('Supplier ' . $supCode),
                    ];
                }
            }
        }
        usort($uniqueSuppliers, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        $out = [
            'records' => $records,
            'total'   => count($records),
            'totals'  => [
                'pos_count'       => count($records),
                'items_count'     => $totalItems,
                'total_qty'       => round($totalQty, 3),
                'total_value'     => round($totalVal, 2),
                'currency'        => $primaryCurrency,
                'suppliers_count' => count($uniqueSuppliers),
            ],
            'suppliers' => array_values($uniqueSuppliers),
            'mat_cat' => $matCat,
            'brand'   => implode(',', $brands),
            'approved_status' => $approvedStatus,
            'range'   => $range,
            'truncated' => $truncated,
            'items_fetched' => $itemsFetched,
            'max_rows' => $maxRows,
            'error'   => null,
        ];

        return $paginate ? $this->paginateListPayload($out, $page, $pageSize) : $out;
    }

    /**
     * Slice aggregated PO list into a page for server-side pagination.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function paginateListPayload(array $payload, int $page, int $pageSize): array
    {
        $records = array_values($payload['records'] ?? []);
        $total = isset($payload['total']) ? (int) $payload['total'] : count($records);
        if ($total < count($records)) {
            $total = count($records);
        }
        if ($pageSize < 1) {
            $pageSize = 25;
        }
        $pageCount = max(1, (int) ceil(max(0, $total) / $pageSize));
        if ($page < 1) {
            $page = 1;
        }
        if ($page > $pageCount) {
            $page = $pageCount;
        }
        $offset = ($page - 1) * $pageSize;
        $payload['records'] = array_slice($records, $offset, $pageSize);
        $payload['total'] = $total;
        $payload['page'] = $page;
        $payload['page_size'] = $pageSize;
        $payload['page_count'] = $pageCount;
        return $payload;
    }

    /*
     * Detailed single PO report
     *
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
        if (!preg_match('/^\d{1,10}$/', $po)) {
            $out['error'] = 'Purchase order must be a number of up to 10 digits.';
            return $out;
        }

        // Live SAP only — do not read/write storage/cache for PO detail.
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
        $headerPoType = trim((string) ($first['PurchaseOrderType'] ?? ''));
        $headerTypeName = trim((string) ($first['PurchasingDocumentTypeName'] ?? ''));
        if ($headerTypeName === '' && isset(self::documentTypeMap()[$headerPoType])) {
            $headerTypeName = self::documentTypeMap()[$headerPoType];
        }

        $out['header'] = [
            'purchase_order'   => $this->displayNumber((string) ($first['PurchaseOrder'] ?? $po)),
            'purchase_order_category' => trim((string) ($first['PurchaseOrderCategory'] ?? '')),
            'purchase_order_type' => $headerPoType,
            'po_type_name'     => $headerTypeName,
            'date'             => $this->sapDate((string) ($first['PurchaseOrderDate'] ?? '')),
            'supplier'         => $this->displayNumber((string) ($first['Supplier'] ?? '')),
            'supplier_full_name' => trim((string) ($first['SupplierFullName'] ?? '')),
            'brand_code'       => implode(', ', $this->distinct($rows, 'brand_code')),
            'brand_name'       => implode(', ', array_values(array_unique(array_filter(array_map(
                static fn ($c): string => self::brandLabel((string) $c),
                $this->distinct($rows, 'brand_code')
            ))))),
            'company_code'     => trim((string) ($first['CompanyCode'] ?? '')),
            'purchasing_org'   => trim((string) ($first['PurchasingOrganization'] ?? '')),
            'purchasing_group' => trim((string) ($first['PurchasingGroup'] ?? '')),
            'created_by'       => trim((string) ($first['CreatedByUser'] ?? '')),
            'plants'           => $this->distinct($rows, 'plant'),
            'sales_orders'     => array_values(array_unique(array_merge(...array_column($rows, 'sales_orders')))),
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

        $rowPoType = trim((string) ($row['PurchaseOrderType'] ?? ''));
        $rowTypeName = trim((string) ($row['PurchasingDocumentTypeName'] ?? ''));
        if ($rowTypeName === '' && isset(self::documentTypeMap()[$rowPoType])) {
            $rowTypeName = self::documentTypeMap()[$rowPoType];
        }

        $materialType = trim((string) ($row['MaterialType'] ?? ''));
        $matTypeDesc = trim((string) ($row['MatTypeDesc'] ?? ''));
        $materialGroup = trim((string) ($row['MaterialGroup'] ?? ''));

        return [
            'item'                    => $this->displayNumber((string) ($row['PurchaseOrderItem'] ?? '')),
            'purchase_order_category' => trim((string) ($row['PurchaseOrderCategory'] ?? '')),
            'purchase_order_type'     => $rowPoType,
            'po_type_name'            => $rowTypeName,
            'supplier'                => $this->displayNumber((string) ($row['Supplier'] ?? '')),
            'supplier_full_name'      => trim((string) ($row['SupplierFullName'] ?? '')),
            'brand_code'              => trim((string) ($row['BrandName'] ?? '')),
            'brand_name'              => self::brandLabel(trim((string) ($row['BrandName'] ?? ''))),
            'material'                => trim((string) ($row['Material'] ?? '')),
            'description'             => trim((string) ($row['PurchaseOrderItemText'] ?? '')),
            'material_type'           => $materialType,
            'mat_type_desc'           => $matTypeDesc,
            'material_group'          => $materialGroup,
            'mat_category'            => self::classifyMaterial($materialType, $matTypeDesc, $materialGroup),
            'plant'                   => trim((string) ($row['Plant'] ?? '')),
            'storage_location'        => trim((string) ($row['StorageLocation'] ?? '')),
            'order_qty'               => $qty,
            'unit'                    => trim((string) ($row['PurchaseOrderQuantityUnit'] ?? '')),
            'net_price'               => $price,
            'net_value'               => round($qty * $price, 2),
            'currency'                => trim((string) ($row['DocumentCurrency'] ?? '')),
            'sales_orders'            => $salesOrders,
            // SAP pricing conditions (when exposed on ZI_PurchaseOrderAPI_HUB)
            'cond_othr'               => $this->sapNumber($row['CondOTHR'] ?? $row['CONDOTHR'] ?? ''),
            'cond_frb1'               => $this->sapNumber($row['CondFRB1'] ?? $row['CONDFRB1'] ?? ''),
            'cond_frb2'               => $this->sapNumber($row['CondFRB2'] ?? $row['CONDFRB2'] ?? ''),
            'cond_zb00'               => $this->sapNumber($row['CondZB00'] ?? $row['CONDZB00'] ?? ''),
            'cond_zsci'               => $this->sapNumber($row['CondZSCI'] ?? $row['CONDZSCI'] ?? ''),
            'cond_zbcr'               => $this->sapNumber($row['CondZBCR'] ?? $row['CONDZBCR'] ?? ''),
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

    private function poLookupKey(string $po): string
    {
        $digits = preg_replace('/\D/', '', $po) ?? '';
        $trimmed = ltrim($digits, '0');
        return $trimmed !== '' ? $trimmed : $digits;
    }

    /**
     * APPROVEDSTATUS for a single PO: "Approved" | "NotApproved".
     * Prefers Z_QuotationZCostCom.ApprovedStatus, then local Pass log.
     */
    public function approvedStatusFor(string $purchaseOrder): string
    {
        $key = $this->poLookupKey($purchaseOrder);
        if ($key === '') {
            return 'NotApproved';
        }
        $sap = $this->sapZcostApprovedPurchaseOrderKeys([$purchaseOrder]);
        if (isset($sap[$key])) {
            return 'Approved';
        }
        $local = $this->approvedPurchaseOrderKeys([$purchaseOrder]);
        return isset($local[$key]) ? 'Approved' : 'NotApproved';
    }

    /**
     * POs with ApprovedStatus = 'Approved' on Z_QuotationZCostCom
     * (same entity as Postman: …/Z_QuotationZCostCom?$filter=ApprovedStatus eq '…').
     *
     * @param list<string|int> $purchaseOrders
     * @return array<string, true>
     */
    private function sapZcostApprovedPurchaseOrderKeys(array $purchaseOrders): array
    {
        $wanted = [];
        foreach ($purchaseOrders as $po) {
            $k = $this->poLookupKey((string) $po);
            if ($k !== '') {
                $wanted[$k] = true;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $service = (string) ($this->cfg['quotation_costing_service']
            ?? '/sap/opu/odata/sap/Z_QUOTATIONZCOSTCOM_CDS/Z_QuotationZCostCom');

        // Batch OR filters (PurchaseOrder eq '…') — keep chunks small for SAP URL limits
        $variants = [];
        foreach (array_keys($wanted) as $k) {
            $display = $this->displayNumber($k);
            $padded = preg_match('/^\d+$/', $display) ? str_pad($display, 10, '0', STR_PAD_LEFT) : $display;
            $variants[$display] = true;
            if ($padded !== '' && $padded !== $display) {
                $variants[$padded] = true;
            }
        }
        $poList = array_keys($variants);
        $approved = [];
        $chunkSize = 20;
        for ($i = 0; $i < count($poList); $i += $chunkSize) {
            $chunk = array_slice($poList, $i, $chunkSize);
            $orParts = array_map(
                static fn (string $p): string => "PurchaseOrder eq '" . str_replace("'", "''", $p) . "'",
                $chunk
            );
            $filter = '(' . implode(' or ', $orParts) . ") and ApprovedStatus eq 'Approved'";
            try {
                $res = $this->client->fetchResults($service, [
                    '$filter' => $filter,
                    '$select' => 'PurchaseOrder,ApprovedStatus',
                ], 2000);
            } catch (Throwable $e) {
                error_log('SapPurchaseOrderService sapZcostApprovedPurchaseOrderKeys: ' . $e->getMessage());
                continue;
            }
            foreach ($res['rows'] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $k = $this->poLookupKey((string) ($row['PurchaseOrder'] ?? $row['purchase_order'] ?? ''));
                if ($k !== '' && isset($wanted[$k])) {
                    $approved[$k] = true;
                }
            }
        }
        return $approved;
    }

    /**
     * POs successfully passed/approved in local Pass_po_validtion (latest row Value = X).
     *
     * @param list<string|int> $purchaseOrders
     * @return array<string, true>
     */
    private function approvedPurchaseOrderKeys(array $purchaseOrders): array
    {
        $keys = [];
        foreach ($purchaseOrders as $po) {
            $k = $this->poLookupKey((string) $po);
            if ($k !== '') {
                $keys[$k] = true;
            }
        }
        if ($keys === []) {
            return [];
        }

        try {
            require_once base_path('app/core/Database.php');
            require_once base_path('app/services/PassPoValidationLogService.php');
            $log = new PassPoValidationLogService();
            $log->ensureTable();
            $pdo = Database::connection();

            // Match both padded and unpadded PO numbers stored in the log
            $candidates = [];
            foreach (array_keys($keys) as $k) {
                $candidates[$k] = true;
                $candidates[str_pad($k, 10, '0', STR_PAD_LEFT)] = true;
            }
            $params = array_keys($candidates);
            $placeholders = implode(',', array_fill(0, count($params), '?'));
            $stmt = $pdo->prepare("
                SELECT l.purchase_order, l.value_flag, l.action, l.success
                FROM [dbo].[Pass_po_validtion] l
                INNER JOIN (
                    SELECT purchase_order, MAX(id) AS max_id
                    FROM [dbo].[Pass_po_validtion]
                    WHERE purchase_order IN ({$placeholders})
                    GROUP BY purchase_order
                ) latest ON latest.max_id = l.id
            ");
            $stmt->execute($params);
            $approved = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $k = $this->poLookupKey((string) ($row['purchase_order'] ?? ''));
                if ($k === '' || !isset($keys[$k])) {
                    continue;
                }
                $ok = !empty($row['success']);
                $flag = strtoupper(trim((string) ($row['value_flag'] ?? '')));
                $action = strtolower(trim((string) ($row['action'] ?? '')));
                if ($ok && ($flag === 'X' || in_array($action, ['pass', 'approve', 'approved'], true))) {
                    $approved[$k] = true;
                }
            }
            return $approved;
        } catch (Throwable $e) {
            error_log('SapPurchaseOrderService approvedPurchaseOrderKeys: ' . $e->getMessage());
            return [];
        }
    }

}
?>