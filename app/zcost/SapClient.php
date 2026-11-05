<?php
/**
 * AUTHOR: NANDHAKUMAR SV 
 * LAST EDIT : 26/10/2026
 * DESC : Pure Live SAP OData Client - No local storage/database
 */
declare(strict_types=1);

namespace App;

class SapClient
{
    private array $config;

    public function __construct(?array $config = null)
    {
        if ($config === null) {
            $configFile = is_file(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/../config/config.php';
            $this->config = file_exists($configFile) ? require $configFile : [];
        } else {
            $this->config = $config;
        }
    }

    /**
     * Normalize Vbeln
     */
    private function cleanVbeln(string $vbeln): string
    {
        return trim($vbeln);
    }

    /**
     * SAP VBELN is CHAR10 / ALPHA — try the raw value, unpadded, and zero-padded keys.
     *
     * @return list<string>
     */
    private function vbelnCandidates(string $vbeln): array
    {
        $raw = trim($vbeln);
        $digits = preg_replace('/\D+/', '', $raw) ?: $raw;
        $unpadded = ltrim((string) $digits, '0');
        if ($unpadded === '') {
            $unpadded = '0';
        }
        $padded = str_pad((string) $digits, 10, '0', STR_PAD_LEFT);
        $out = [];
        foreach ([$raw, $digits, $unpadded, $padded] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && !in_array($candidate, $out, true)) {
                $out[] = $candidate;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sheetLineCount(array $data): int
    {
        $n = 0;
        foreach (['to_Fabric', 'to_Trim', 'to_Packing', 'to_Special'] as $nav) {
            $rows = $data[$nav]['results'] ?? null;
            if (is_array($rows)) {
                $n += count($rows);
            }
        }
        return $n;
    }

    /**
     * Prefer the payload that actually has costing lines / approved totals.
     *
     * @param array<string, mixed> $data
     */
    private function scoreSheet(array $data): int
    {
        $score = $this->sheetLineCount($data) * 20;
        if (strtoupper(trim((string) ($data['Approve'] ?? ''))) === 'X') {
            $score += 80;
        }
        $score += (int) round((float) ($data['Sellprice'] ?? 0));
        $score += (int) round((float) ($data['Subtot'] ?? 0));
        $score += (int) round((float) ($data['Making'] ?? 0));
        return $score;
    }

    /**
     * Normalize Posnr
     */
    private function cleanPosnr(string $posnr): string
    {
        $p = trim($posnr);
        if (is_numeric($p) && strlen($p) < 6) {
            $p = str_pad($p, 6, '0', STR_PAD_LEFT);
        }
        return $p;
    }

    /**
     * Fetch Costing Sheet directly from Live SAP OData (No Local Cache / No Local Database)
     */
    public function getCostSheet(string $vbeln = '3000008', string $posnr = '000010'): array
    {
        $posnrClean = $this->cleanPosnr($posnr);
        $lastError = "Quotation master not found in live SAP for {$vbeln} (Item {$posnrClean})";
        $lastHttp = 0;
        $lastUrl = '';
        $best = null;
        $bestScore = -1;

        foreach ($this->vbelnCandidates($vbeln) as $vbelnClean) {
            $fetched = $this->fetchCostSheetByKey($vbelnClean, $posnrClean);
            $lastHttp = (int) ($fetched['http_code'] ?? 0);
            $lastUrl = (string) ($fetched['url'] ?? '');
            if (!empty($fetched['error'])) {
                $lastError = (string) $fetched['error'];
            }
            if (empty($fetched['success']) || !is_array($fetched['data'] ?? null)) {
                continue;
            }
            $data = $this->hydrateNavResults($fetched['data']);
            $score = $this->scoreSheet($data);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'success' => true,
                    'source' => 'LIVE_SAP',
                    'http_code' => $lastHttp,
                    'url' => $lastUrl,
                    'timestamp' => date('Y-m-d H:i:s'),
                    'data' => $data,
                ];
                if ($this->sheetLineCount($data) > 0) {
                    break;
                }
            }
        }

        if ($best !== null) {
            return $best;
        }

        return [
            'success' => false,
            'not_found' => true,
            'source' => 'LIVE_SAP',
            'http_code' => $lastHttp,
            'vbeln' => $this->cleanVbeln($vbeln),
            'posnr' => $posnrClean,
            'error' => $lastError,
            'data' => null,
        ];
    }

    /**
     * @return array{success:bool,http_code:int,url:string,data?:array,error?:string}
     */
    private function fetchCostSheetByKey(string $vbelnClean, string $posnrClean): array
    {
        $baseUrl = rtrim($this->config['sap']['base_url'] ?? 'http://APP-PROD.evolvclothing.com:8000', '/');
        $service = $this->config['sap']['service'] ?? '/sap/opu/odata/sap/ZCOST_SRV/ZCOSTHSet';
        $expand = $this->config['defaults']['expand'] ?? 'to_Fabric,to_Trim,to_Special,to_Packing';

        $url = sprintf(
            "%s%s(Vbeln='%s',Posnr='%s')?%%24expand=%s&%%24format=json",
            $baseUrl,
            $service,
            rawurlencode($vbelnClean),
            rawurlencode($posnrClean),
            rawurlencode($expand)
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, ($this->config['sap']['username'] ?? 'APIUSER') . ':' . ($this->config['sap']['password'] ?? 'Api@321'));
        if (!empty($this->config['sap']['resolve'])) {
            curl_setopt($ch, CURLOPT_RESOLVE, $this->config['sap']['resolve']);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->config['sap']['timeout'] ?? 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response !== false && $httpCode === 200) {
            $decoded = json_decode((string) $response, true);
            if (isset($decoded['d']) && is_array($decoded['d'])) {
                return [
                    'success' => true,
                    'http_code' => $httpCode,
                    'url' => $url,
                    'data' => $decoded['d'],
                ];
            }
        }

        $errorMessage = "Quotation master not found in live SAP for {$vbelnClean} (Item {$posnrClean})";
        if (!empty($response)) {
            $errDecoded = json_decode((string) $response, true);
            if (isset($errDecoded['error']['message']['value'])) {
                $errorMessage = $errDecoded['error']['message']['value'];
            }
        } elseif ($curlError) {
            $errorMessage = "Live SAP connection error: {$curlError}";
        }

        return [
            'success' => false,
            'http_code' => $httpCode,
            'url' => $url,
            'error' => $errorMessage,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function hydrateNavResults(array $data): array
    {
        foreach (['to_Fabric', 'to_Trim', 'to_Packing', 'to_Special'] as $nav) {
            $block = $data[$nav] ?? null;
            if (!is_array($block)) {
                $data[$nav] = ['results' => []];
                continue;
            }
            if (isset($block['results']) && is_array($block['results']) && $block['results'] !== []) {
                continue;
            }
            if (isset($block['d']['results']) && is_array($block['d']['results'])) {
                $data[$nav] = ['results' => $block['d']['results']];
                continue;
            }
            $uri = (string) ($block['__deferred']['uri'] ?? '');
            if ($uri !== '') {
                $rows = $this->fetchDeferredResults($uri);
                $data[$nav] = ['results' => $rows];
                continue;
            }
            $data[$nav] = ['results' => []];
        }
        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchDeferredResults(string $uri): array
    {
        $ch = curl_init();
        $url = $uri . (str_contains($uri, '?') ? '&' : '?') . '%24format=json';
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, ($this->config['sap']['username'] ?? 'APIUSER') . ':' . ($this->config['sap']['password'] ?? 'Api@321'));
        if (!empty($this->config['sap']['resolve'])) {
            curl_setopt($ch, CURLOPT_RESOLVE, $this->config['sap']['resolve']);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->config['sap']['timeout'] ?? 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $httpCode !== 200) {
            return [];
        }
        $decoded = json_decode((string) $response, true);
        $rows = $decoded['d']['results'] ?? $decoded['d'] ?? [];
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** Header fields allowed by ZCOSTH metadata (UI-only fields like Kwmeng must not be sent). */
    private const HEADER_FIELDS = [
        'Vbeln', 'Posnr', 'Sellprice', 'Making', 'Overhead', 'Markup', 'Quota', 'Commision',
        'Nmachreq', 'Npeicbnd', 'Fincost', 'Cnv', 'Cnvf', 'Sam', 'Approve', 'Approvedby',
        'Zzall', 'Zwast', 'Uname', 'Udate', 'Utime', 'MarkupP', 'CommVal', 'AddCost',
        'Discount', 'Subtot',
    ];

    /**
     * Line fields per nav entity (from ZCOST_SRV $metadata).
     * Special (ZCOSTIS) has no Zsection / Zline / Slno — sending them causes "Property 'Zsection' is invalid".
     */
    private const LINE_FIELDS_BY_NAV = [
        // zcosti
        'to_Fabric' => [
            'Vbeln', 'Posnr', 'Zline', 'Item', 'Maktx', 'WidSiz', 'Net', 'Wste', 'Gross',
            'Curunit', 'Rate', 'Totalinr', 'Totalfr', 'Totalper', 'Convr', 'Fincost',
            'Fright', 'Purp', 'Duty', 'Slno', 'Zsection', 'Zdeli', 'Uname', 'Udate', 'Utime',
        ],
        // ZCOSTIT
        'to_Trim' => [
            'Vbeln', 'Posnr', 'Zline', 'Item', 'Maktx', 'WidSiz', 'Net', 'Wste', 'Gross',
            'Curunit', 'Rate', 'Totalinr', 'Totalfr', 'Totalper', 'Convr', 'Fright', 'Purp',
            'Duty', 'Fincost', 'Slno', 'Zsection', 'Zdeli', 'Uname', 'Udate', 'Utime',
        ],
        // ZCOSTIP
        'to_Packing' => [
            'Vbeln', 'Posnr', 'Zline', 'Item', 'Maktx', 'WidSiz', 'Net', 'Wste', 'Gross',
            'Curunit', 'Rate', 'Totalinr', 'Totalfr', 'Totalper', 'Convr', 'Fright', 'Purp',
            'Duty', 'Fincost', 'Slno', 'Zsection', 'Zdeli', 'Uname', 'Udate', 'Utime',
        ],
        // ZCOSTIS — no Zsection / Zline / Slno
        'to_Special' => [
            'Vbeln', 'Posnr', 'Item', 'Maktx', 'WidSiz', 'Net', 'Wste', 'Gross',
            'Curunit', 'Rate', 'Totalinr', 'Totalfr', 'Totalper', 'Convr', 'Fright', 'Purp',
            'Duty', 'Fincost', 'Zdeli', 'Uname', 'Udate', 'Utime',
        ],
    ];

    /**
     * Strip UI-only / unknown properties so SAP does not reject the deep insert
     * (e.g. "Property 'Kwmeng' is invalid", "Property 'Zsection' is invalid" on Special).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitizeSavePayload(array $payload): array
    {
        if (isset($payload['d']) && is_array($payload['d'])) {
            $payload = $payload['d'];
        }

        $clean = [];
        foreach (self::HEADER_FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $value = $payload[$field];
            // Drop null deferred metadata blobs from GET responses
            if (is_array($value)) {
                continue;
            }
            $clean[$field] = $value;
        }

        // Ensure keys are normalized
        if (isset($clean['Vbeln'])) {
            $clean['Vbeln'] = $this->cleanVbeln((string)$clean['Vbeln']);
        }
        if (isset($clean['Posnr'])) {
            $clean['Posnr'] = $this->cleanPosnr((string)$clean['Posnr']);
        }
        if (isset($clean['Approve'])) {
            $clean['Approve'] = strtoupper(substr(trim((string)$clean['Approve']), 0, 1));
        }
        if (isset($clean['Approvedby'])) {
            $clean['Approvedby'] = substr(strtoupper(trim((string)$clean['Approvedby'])), 0, 12);
        }
        if (isset($clean['Cnv'])) {
            $clean['Cnv'] = substr(strtoupper(trim((string)$clean['Cnv'])), 0, 5);
        }

        foreach (self::LINE_FIELDS_BY_NAV as $navProp => $allowedFields) {
            $rows = $payload[$navProp]['results'] ?? null;
            if (!is_array($rows)) {
                continue;
            }
            $cleanRows = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $line = [];
                foreach ($allowedFields as $field) {
                    if (!array_key_exists($field, $row)) {
                        continue;
                    }
                    $value = $row[$field];
                    if (is_array($value)) {
                        continue;
                    }
                    $line[$field] = $value;
                }
                if (isset($line['WidSiz'])) {
                    $line['WidSiz'] = substr(trim((string)$line['WidSiz']), 0, 4);
                }
                if (isset($line['Item'])) {
                    $line['Item'] = substr(trim((string)$line['Item']), 0, 40);
                }
                if (isset($line['Maktx'])) {
                    $line['Maktx'] = substr(trim((string)$line['Maktx']), 0, 40);
                }
                if (isset($line['Curunit'])) {
                    $line['Curunit'] = substr(strtoupper(trim((string)$line['Curunit'])), 0, 5);
                }
                if (isset($clean['Vbeln'])) {
                    $line['Vbeln'] = $clean['Vbeln'];
                }
                if (isset($clean['Posnr'])) {
                    $line['Posnr'] = $clean['Posnr'];
                }
                $cleanRows[] = $line;
            }
            $clean[$navProp] = ['results' => $cleanRows];
        }

        return $clean;
    }

    /**
     * POST Costing Sheet directly to Live SAP OData service (No Local Cache / No Local Database)
     */
    public function saveCostSheet(array $payload): array
    {
        $baseUrl = rtrim($this->config['sap']['base_url'] ?? 'http://APP-PROD.evolvclothing.com:8000', '/');
        $serviceRoot = $baseUrl . '/sap/opu/odata/sap/ZCOST_SRV/';
        $postUrl = $baseUrl . '/sap/opu/odata/sap/ZCOST_SRV/ZCOSTHSet';

        // 1. Fetch CSRF token & cookies
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $serviceRoot);
        curl_setopt($ch, CURLOPT_USERPWD, ($this->config['sap']['username'] ?? 'APIUSER') . ':' . ($this->config['sap']['password'] ?? 'Api@321'));
        if (!empty($this->config['sap']['resolve'])) {
            curl_setopt($ch, CURLOPT_RESOLVE, $this->config['sap']['resolve']);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-CSRF-Token: Fetch',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);

        $fetchResponse = curl_exec($ch);
        $fetchCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fetchErr = curl_error($ch);
        curl_close($ch);

        $csrfToken = '';
        if (is_string($fetchResponse) && preg_match('/x-csrf-token:\s*([^\r\n]+)/i', $fetchResponse, $matches)) {
            $csrfToken = trim($matches[1]);
        }

        $cookies = [];
        if (is_string($fetchResponse) && preg_match_all('/Set-Cookie:\s*([^;]+)/i', $fetchResponse, $cookieMatches)) {
            $cookies = $cookieMatches[1];
        }
        $cookieHeader = implode('; ', $cookies);

        // Clean payload: only ZCOSTH + nav properties from $metadata (drops Kwmeng, Matnr, __metadata, …)
        $postData = $this->sanitizeSavePayload($payload);

        $jsonPayload = json_encode($postData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // 2. Perform POST to ZCOSTHSet
        $postHeaders = [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json'
        ];
        if (!empty($csrfToken)) {
            $postHeaders[] = 'X-CSRF-Token: ' . $csrfToken;
        }
        if (!empty($cookieHeader)) {
            $postHeaders[] = 'Cookie: ' . $cookieHeader;
        }

        $chPost = curl_init();
        curl_setopt($chPost, CURLOPT_URL, $postUrl);
        curl_setopt($chPost, CURLOPT_POST, true);
        curl_setopt($chPost, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($chPost, CURLOPT_USERPWD, ($this->config['sap']['username'] ?? 'APIUSER') . ':' . ($this->config['sap']['password'] ?? 'Api@321'));
        if (!empty($this->config['sap']['resolve'])) {
            curl_setopt($chPost, CURLOPT_RESOLVE, $this->config['sap']['resolve']);
        }
        curl_setopt($chPost, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chPost, CURLOPT_HTTPHEADER, $postHeaders);
        curl_setopt($chPost, CURLOPT_TIMEOUT, 25);
        curl_setopt($chPost, CURLOPT_CONNECTTIMEOUT, 6);

        $postRes = curl_exec($chPost);
        $postHttpCode = curl_getinfo($chPost, CURLINFO_HTTP_CODE);
        $postError = curl_error($chPost);
        curl_close($chPost);

        $requestMeta = [
            'method' => 'POST',
            'url' => $postUrl,
            'payload' => $postData,
            'csrf_method' => 'GET',
            'csrf_url' => $serviceRoot,
            'csrf_http_code' => (int)$fetchCode,
        ];

        if ($postHttpCode === 201 || $postHttpCode === 200) {
            $resDecoded = json_decode((string)$postRes, true);
            return array_merge($requestMeta, [
                'success' => true,
                'source' => 'LIVE_SAP',
                'http_code' => $postHttpCode,
                'message' => 'Successfully saved and posted to SAP ZCOST_SRV!',
                'data' => $resDecoded['d'] ?? $postData,
            ]);
        }

        // Check if SAP returned an error message in JSON
        $errorMessage = $postError ?: ($fetchErr ?: "SAP returned HTTP $postHttpCode");
        if (!empty($postRes)) {
            $errDecoded = json_decode((string)$postRes, true);
            if (isset($errDecoded['error']['message']['value'])) {
                $errorMessage = $errDecoded['error']['message']['value'];
            }
        }

        return array_merge($requestMeta, [
            'success' => false,
            'source' => 'LIVE_SAP',
            'http_code' => $postHttpCode,
            'sap_http_code' => $postHttpCode,
            'sap_error' => $errorMessage,
            'error' => $errorMessage,
            'message' => "SAP Save Failed: $errorMessage",
            'data' => $postData,
        ]);
    }

    /**
     * Create In-Memory Quotation Master Template (No Local Disk Storage)
     */
    public function createQuotationMaster(string $vbeln, string $posnr): array
    {
        $vbelnClean = $this->cleanVbeln($vbeln);
        $posnrClean = $this->cleanPosnr($posnr);

        $newMaster = [
            '_is_user_created' => true,
            'Vbeln' => $vbelnClean,
            'Posnr' => $posnrClean ?: '000010',
            'Matnr' => '',
            'Maktxh' => '',
            'Kwmeng' => '0.000',
            'Approve' => '',
            'Zzall' => 'X',
            'Cnv' => 'USD',
            'Cnvf' => '94.95',
            'Nmachreq' => 0,
            'Npeicbnd' => 0,
            'Sam' => '0.00',
            'Making' => '0.000',
            'Overhead' => '0.000',
            'Zwast' => '3.000',
            'Subtot' => '0.0000',
            'MarkupP' => '20.000',
            'Markup' => '0.00',
            'Commision' => '0.000',
            'CommVal' => '0.00',
            'AddCost' => '0.000',
            'Discount' => '0.000',
            'Sellprice' => '0.000',
            'Quota' => '0.000',
            'to_Fabric' => [
                'results' => []
            ],
            'to_Trim' => [
                'results' => []
            ],
            'to_Packing' => [
                'results' => []
            ],
            'to_Special' => [
                'results' => []
            ]
        ];

        return [
            'success' => true,
            'is_new' => true,
            'source' => 'LIVE_SAP',
            'note' => "Template created for {$vbelnClean} (In-Memory)",
            'http_code' => 200,
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => $newMaster
        ];
    }
}
