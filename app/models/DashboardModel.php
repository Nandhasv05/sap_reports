<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Dashboard Model for the dashboards
 */

/*
 * Dashboard Model class
 */
class DashboardModel
{
    public const KAPIS_KEYS = ['sales', 'material', 'planning'];

    private array $dashboards;
    private int $year;

    /*
     * Constructor
     */
    public function __construct()
    {
        $data = require base_path('app/models/data/dashboards.php');
        $this->dashboards = $data['dashboards'] ?? $data;
        $this->year = $data['year'] ?? (int) date('Y');
        $this->ensurePlanning();
        $this->applyKapisSubtitles();
    }

    /*
     * All method
     */
    public function all(): array
    {
        return $this->dashboards;
    }

    /*
    * Kapis hub: Sales, Material, Planning, Production.
     */
    public function kapis(): array
    {
        $out = [];
        foreach (self::KAPIS_KEYS as $key) {
            if (isset($this->dashboards[$key])) {
                $out[$key] = $this->dashboards[$key];
            }
        }
        return $out;
    }

    /*
     * Find method
     */
    public function find(string $type): ?array
    {
        return $this->dashboards[$type] ?? null;
    }

    /*
     * Year method
     */
    public function year(): int
    {
        return $this->year;
    }

    /*
     * Types method
     */
    public function types(): array
    {
        return array_keys($this->dashboards);
    }

    /*
     * Prepare items method
     */
    public function prepareItems(array $cfg): array
    {
        $items = $cfg['items'] ?? [];
        foreach ($items as &$item) {
            $item['monthly_units'] = $cfg['monthly_qty'][$item['id']] ?? [];
        }
        unset($item);
        return $items;
    }

    /*
     * Apply Kapis subtitles method
     */
    private function applyKapisSubtitles(): void
    {
        $subtitles = [
            'sales'      => 'Orders, revenue, and channel performance',
            'material'   => 'Fabric, trims, and purchase spend',
            'planning'   => 'Capacity, TNA, and style plan',
            'production' => 'Plant output, work orders, and efficiency',
        ];
        foreach ($subtitles as $key => $subtitle) {
            if (!isset($this->dashboards[$key])) {
                continue;
            }
            if (empty($this->dashboards[$key]['subtitle'])) {
                $this->dashboards[$key]['subtitle'] = $subtitle;
            }
            $this->dashboards[$key]['nav_label'] = ucfirst($key);
        }
    }

    /*
     * Ensure planning method
     */
    private function ensurePlanning(): void
    {
        if (isset($this->dashboards['planning'])) {
            return;
        }
        $base = $this->dashboards['production'] ?? null;
        if (!is_array($base)) {
            return;
        }

        $p = $base;
        $p['title'] = 'Planning Dashboard';
        $p['subtitle'] = 'Capacity, TNA, and style plan';
        $p['primary'] = '#0f766e';
        $p['primary_dark'] = '#115e59';
        $p['nav_icon'] = 'event_note';
        $p['mode'] = 'planning';
        $p['kpis'] = [
            ['key' => 'kpi1', 'label' => 'Plan Value', 'icon' => 'payments', 'color' => 'blue', 'money' => true],
            ['key' => 'kpi2', 'label' => 'Units Planned', 'icon' => 'event_available', 'color' => 'green', 'money' => false],
            ['key' => 'kpi3', 'label' => 'Style Plans', 'icon' => 'view_week', 'color' => 'orange', 'money' => false],
            ['key' => 'kpi4', 'label' => 'Avg Plan Value', 'icon' => 'request_quote', 'color' => 'purple', 'money' => true],
            ['key' => 'kpi5', 'label' => 'Capacity Used', 'icon' => 'donut_large', 'color' => 'green', 'money' => false, 'suffix' => '%'],
            ['key' => 'kpi6', 'label' => 'On-Time TNA', 'icon' => 'schedule', 'color' => 'blue', 'money' => false, 'suffix' => '%'],
        ];
        $p['charts'] = [
            'main'   => ['icon' => 'show_chart', 'title' => 'Plan vs Actual'],
            'pie'    => ['icon' => 'donut_large', 'title' => 'Plan by Plant'],
            'bar'    => ['icon' => 'event_note', 'title' => 'Top Planned Styles'],
            'orders' => ['icon' => 'assignment', 'title' => 'TNA Milestone Volume'],
        ];
        $p['table'] = [
            'title'   => 'Style Planning Report',
            'icon'    => 'event_note',
            'headers' => ['Style / Line', 'Plant', 'Unit Cost', 'Qty Planned', 'Plan Value', 'Open TNA'],
        ];
        $p['highlights'] = [
            ['icon' => 'event_available', 'title' => 'Q4 capacity locked', 'text' => 'Padalam + Perungudi booked through November'],
            ['icon' => 'timeline', 'title' => 'TNA on track', 'text' => '86% of styles hitting current milestone'],
            ['icon' => 'groups', 'title' => 'Line loading', 'text' => 'Sewing load balanced across 3 plants'],
            ['icon' => 'inventory', 'title' => 'Fabric cover', 'text' => 'Critical styles covered 18 days ahead'],
        ];
        $p['alerts'] = [
            ['type' => 'warn', 'text' => 'Wool Blazer TNA — fabric in-house date slipped 4 days'],
            ['type' => 'info', 'text' => 'Festival collection cutting plan released for Monday'],
            ['type' => 'ok', 'text' => 'Cotton Tee pack plan confirmed against sales forecast'],
        ];
        $p['recent'] = [
            'title' => 'Recent Style Plans',
            'icon' => 'event_note',
            'headers' => ['Plan #', 'Style', 'Plant', 'Qty', 'Value', 'Status'],
            'rows' => [
                ['PL-2214', 'Denim Jacket', 'Padalam', '800', '$33,600', 'Confirmed'],
                ['PL-2210', 'Cotton Tee Pack', 'Perungudi', '2,400', '$28,800', 'In TNA'],
                ['PL-2206', 'Floral Dress', 'Ambattur', '600', '$17,100', 'Cutting plan'],
                ['PL-2199', 'Kids Hoodie', 'Padalam', '900', '$14,850', 'Confirmed'],
                ['PL-2191', 'Wool Blazer', 'Perungudi', '220', '$14,960', 'Hold'],
                ['PL-2184', 'Linen Polo', 'Ambattur', '750', '$13,125', 'Released'],
            ],
        ];
        $this->dashboards['planning'] = $p;
    }
}
?>