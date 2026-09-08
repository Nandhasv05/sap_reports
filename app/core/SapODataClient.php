<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : SAP OData client
 */

/**
 * SAP OData client
 */
class SapODataClient
{
    /** @var array<string, mixed> */
    private array $cfg;

    /*
     * Construct the SAP OData client
     */
    public function __construct(?array $cfg = null)
    {
        $this->cfg = $cfg ?? config('sap');
    }

    /*
     * Check if the SAP OData client is enabled
     */
    public function isEnabled(): bool
    {
        return !empty($this->cfg['enabled']);
    }

    /*
     * Stream OData pages to a callback without keeping all rows in memory.
     *
     * @param callable(array<int, array>):void $onBatch
     * @return array{row_count: int, error: ?string}
     */
    public function eachPage(callable $onBatch, array $extraQuery = []): array
    {
        if (!$this->isEnabled()) {
            return ['row_count' => 0, 'error' => 'SAP integration is disabled.'];
        }

        $pageSize = max(1, (int) ($this->cfg['page_size'] ?? 500));
        $maxRows = max($pageSize, (int) ($this->cfg['max_rows'] ?? 10000));
        $skip = 0;
        $total = 0;
        $lastError = null;

        while ($total < $maxRows) {
            $top = min($pageSize, $maxRows - $total);
            $query = array_merge([
                '$top'    => (string) $top,
                '$skip'   => (string) $skip,
                '$format' => 'json',
            ], $extraQuery);

            $payload = $this->request($this->buildUrl($query));

            if ($payload['error'] !== null) {
                $lastError = $payload['error'];
                break;
            }

            $batch = $this->extractResults($payload['body']);
            unset($payload);

            if ($batch === null) {
                $lastError = 'Unexpected SAP OData response format.';
                break;
            }

            if ($batch === []) {
                break;
            }

            $onBatch($batch);
            $batchCount = count($batch);
            $total += $batchCount;
            $skip += $batchCount;
            unset($batch);

            if ($batchCount < $top) {
                break;
            }
        }

        if ($total > 0) {
            return ['row_count' => $total, 'error' => null];
        }

        return ['row_count' => 0, 'error' => $lastError ?? 'No records returned from SAP.'];
    }

    /*
     * Fetch all rows from a specific OData entity set.
     *
     * @return array{rows: array<int, array>, error: ?string, row_count: int}
     */
    public function fetchResults(string $servicePath, array $extraQuery = []): array
    {
        $saved = (string) ($this->cfg['service'] ?? '');
        $this->cfg['service'] = $servicePath;
        $rows = [];
        $result = ['error' => null];
        try {
            $result = $this->eachPage(function (array $batch) use (&$rows): void {
                foreach ($batch as $row) {
                    if (is_array($row)) {
                        $rows[] = $row;
                    }
                }
            }, $extraQuery);
        } finally {
            $this->cfg['service'] = $saved;
        }

        $error = $result['error'] ?? null;
        if (is_string($error) && str_starts_with($error, 'No records')) {
            $error = null;
        }

        return [
            'rows'      => $rows,
            'error'     => $error,
            'row_count' => count($rows),
        ];
    }

    /*
     * Build the URL
     */
    private function buildUrl(array $query): string
    {
        $base = rtrim((string) ($this->cfg['base_url'] ?? ''), '/');
        $service = (string) ($this->cfg['service'] ?? '');
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $base . $service . '?' . $qs;
    }

    /*
     * Request the URL
     */
    /*
     * @return array{body: ?array, error: ?string}
     */
    private function request(string $url): array
    {
        $username = (string) ($this->cfg['username'] ?? '');
        $password = (string) ($this->cfg['password'] ?? '');
        $timeout = (int) ($this->cfg['timeout'] ?? 45);

        if ($username === '' || $password === '') {
            return ['body' => null, 'error' => 'SAP credentials are not configured.'];
        }

        if (function_exists('curl_init')) {
            return $this->requestViaCurl($url, $username, $password, $timeout);
        }

        return $this->requestViaStream($url, $username, $password, $timeout);
    }

    /*
     * Request via CURL
     */
    private function requestViaCurl(string $url, string $username, string $password, int $timeout): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $username . ':' . $password,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ];
        $resolve = $this->cfg['resolve'] ?? [];
        if (is_array($resolve) && $resolve !== []) {
            $opts[CURLOPT_RESOLVE] = array_values($resolve);
        }
        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['body' => null, 'error' => $err !== '' ? $err : 'SAP request failed.'];
        }

        if ($status >= 400) {
            return ['body' => null, 'error' => "SAP HTTP {$status}: " . $this->truncate($body)];
        }

        $decoded = json_decode($body, true);
        unset($body);

        if (!is_array($decoded)) {
            return ['body' => null, 'error' => 'Invalid JSON returned from SAP.'];
        }

        return ['body' => $decoded, 'error' => null];
    }


    /*
     * Request via stream
     */
    private function requestViaStream(string $url, string $username, string $password, int $timeout): array
    {
        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => [
                    'Accept: application/json',
                    'Authorization: Basic ' . base64_encode($username . ':' . $password),
                ],
                'timeout' => $timeout,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return ['body' => null, 'error' => 'SAP request failed (stream).'];
        }

        $decoded = json_decode($body, true);
        unset($body);

        if (!is_array($decoded)) {
            return ['body' => null, 'error' => 'Invalid JSON returned from SAP.'];
        }

        return ['body' => $decoded, 'error' => null];
    }

    /*
     * Extract the results
     */
    private function extractResults(?array $body): ?array
    {
        if ($body === null) {
            return null;
        }

        if (isset($body['value']) && is_array($body['value'])) {
            return $body['value'];
        }

        if (isset($body['d']['results']) && is_array($body['d']['results'])) {
            return $body['d']['results'];
        }

        if (isset($body['d']) && is_array($body['d']) && $this->isAssocRow($body['d'])) {
            return [$body['d']];
        }

        return null;
    }

    /*
     * Check if the row is an associative array
     */
    private function isAssocRow(array $row): bool
    {
        foreach (array_keys($row) as $key) {
            if (is_string($key) && $key !== '__metadata') {
                return true;
            }
        }

        return false;
    }

    /*
     * Truncate the text
     */
    private function truncate(string $text, int $max = 180): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max) . '…';
    }
}
