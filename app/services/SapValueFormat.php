<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/09/2026
 * DESCRIPTION : SAP OData value helpers shared by the report services
 */
trait SapValueFormat
{
    /*
     * SAP ABAP numbers come as "172.000 " or with a trailing minus "1.000-".
     */
    public function sapNumber($raw): float
    {
        $text = str_replace(',', '', trim((string) $raw));
        if ($text === '') {
            return 0.0;
        }
        $negative = str_ends_with($text, '-');
        $text = rtrim($text, '-');
        if (!is_numeric($text)) {
            return 0.0;
        }
        $n = (float) $text;
        return $negative ? -$n : $n;
    }

    /*
     * "20260916" -> "2026-09-16"
     */
    public function sapDate(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m) && $m[1] !== '0000') {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        if (preg_match('#/Date\((\d+)\)/#', $raw, $m)) {
            return gmdate('Y-m-d', (int) ((int) $m[1] / 1000));
        }
        return '';
    }

    public function padSalesOrder(string $raw): string
    {
        $raw = strtoupper(trim(str_replace(',', '', $raw)));
        if (preg_match('/^\d+$/', $raw)) {
            return str_pad(substr($raw, -10), 10, '0', STR_PAD_LEFT);
        }
        return $raw;
    }

    public function displayNumber(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
            return $raw;
        }
        $trimmed = ltrim($raw, '0');
        return $trimmed === '' ? '0' : $trimmed;
    }
}
