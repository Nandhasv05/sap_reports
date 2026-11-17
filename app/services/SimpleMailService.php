<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 05/11/2026
 * DESCRIPTION : SMTP / mail() helper for PO comment @mention alerts
 */
class SimpleMailService
{
    /** @var array<string,mixed> */
    private array $cfg;

    public function __construct(?array $cfg = null)
    {
        $this->cfg = $cfg ?? (function_exists('config') ? config('mail') : []);
    }

    /**
     * @param list<string> $toEmails
     * @param list<array{path?:string,name?:string,mime?:string,content?:string}> $attachments
     * @return array{sent:int,failed:int,errors:list<string>,from:string,driver:string}
     */
    public function send(
        array $toEmails,
        string $subject,
        string $bodyHtml,
        string $fromEmail = '',
        string $fromName = '',
        array $attachments = []
    ): array {
        $toEmails = array_values(array_unique(array_filter(array_map(static function ($e) {
            $e = trim((string) $e);
            return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
        }, $toEmails))));

        $cfgFrom = (string) ($this->cfg['from']['address'] ?? 'apps@evolvclothing.com');
        $cfgName = (string) ($this->cfg['from']['name'] ?? 'SAP Reports');
        $fromEmail = trim($fromEmail);
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = $cfgFrom;
        }
        // Always prefer configured sender identity for deliverability
        if (!empty($this->cfg['from']['address'])) {
            $fromEmail = (string) $this->cfg['from']['address'];
        }
        $fromName = trim($fromName) !== '' ? trim($fromName) : $cfgName;
        $attachments = $this->normalizeAttachments($attachments);

        $driver = strtolower((string) ($this->cfg['driver'] ?? 'smtp'));
        if (empty($this->cfg['enabled'])) {
            return [
                'sent' => 0,
                'failed' => count($toEmails),
                'errors' => ['Mail is disabled in config/mail.php'],
                'from' => $fromEmail,
                'driver' => $driver,
            ];
        }
        if (!$toEmails) {
            return [
                'sent' => 0,
                'failed' => 0,
                'errors' => ['No valid recipient emails'],
                'from' => $fromEmail,
                'driver' => $driver,
            ];
        }

        if ($driver === 'mail') {
            return $this->sendViaPhpMail($toEmails, $subject, $bodyHtml, $fromEmail, $fromName, $attachments);
        }

        try {
            return $this->sendViaSmtp($toEmails, $subject, $bodyHtml, $fromEmail, $fromName, $attachments);
        } catch (Throwable $e) {
            // Prefer the real SMTP error. PHP mail() usually fails on Windows/MAMP
            // and only hides the STARTTLS / auth problem as "via mail()".
            $smtpError = 'SMTP failed: ' . $e->getMessage();
            $fallback = $this->sendViaPhpMail($toEmails, $subject, $bodyHtml, $fromEmail, $fromName, $attachments);
            if ((int) ($fallback['sent'] ?? 0) > 0) {
                $fallback['errors'][] = $smtpError;
                $fallback['driver'] = 'smtp→mail';
                return $fallback;
            }
            return [
                'sent' => 0,
                'failed' => count($toEmails),
                'errors' => array_values(array_filter(array_merge([$smtpError], $fallback['errors'] ?? []))),
                'from' => $fromEmail,
                'driver' => 'smtp',
            ];
        }
    }

    /**
     * @param list<array{path?:string,name?:string,mime?:string,content?:string}> $attachments
     * @return list<array{name:string,mime:string,content:string}>
     */
    private function normalizeAttachments(array $attachments): array
    {
        $out = [];
        foreach ($attachments as $att) {
            if (!is_array($att)) {
                continue;
            }
            $content = '';
            $name = trim((string) ($att['name'] ?? ''));
            $mime = trim((string) ($att['mime'] ?? 'application/octet-stream'));
            if (!empty($att['content']) && is_string($att['content'])) {
                $content = $att['content'];
            } elseif (!empty($att['path']) && is_string($att['path']) && is_file($att['path'])) {
                $bin = @file_get_contents($att['path']);
                if (!is_string($bin) || $bin === '') {
                    continue;
                }
                $content = $bin;
                if ($name === '') {
                    $name = basename($att['path']);
                }
            }
            if ($content === '') {
                continue;
            }
            if ($name === '') {
                $name = 'attachment.bin';
            }
            if ($mime === '') {
                $mime = 'application/octet-stream';
            }
            $out[] = [
                'name' => $name,
                'mime' => $mime,
                'content' => $content,
            ];
        }
        return $out;
    }

    /**
     * @param list<string> $toEmails
     * @param list<array{name:string,mime:string,content:string}> $attachments
     * @return array{sent:int,failed:int,errors:list<string>,from:string,driver:string}
     */
    private function sendViaPhpMail(
        array $toEmails,
        string $subject,
        string $bodyHtml,
        string $fromEmail,
        string $fromName,
        array $attachments = []
    ): array {
        $sent = 0;
        $failed = 0;
        $errors = [];
        $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        foreach ($toEmails as $to) {
            if ($attachments) {
                $boundary = '=_sap_' . md5(uniqid((string) mt_rand(), true));
                $headers = [
                    'MIME-Version: 1.0',
                    'From: ' . $this->formatAddress($fromName, $fromEmail),
                    'Reply-To: ' . $fromEmail,
                    'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
                    'X-Mailer: PHP/' . PHP_VERSION,
                ];
                $body = $this->buildMultipartBody($boundary, $bodyHtml, $attachments);
            } else {
                $headers = [
                    'MIME-Version: 1.0',
                    'Content-type: text/html; charset=UTF-8',
                    'From: ' . $this->formatAddress($fromName, $fromEmail),
                    'Reply-To: ' . $fromEmail,
                    'X-Mailer: PHP/' . PHP_VERSION,
                ];
                $body = $bodyHtml;
            }
            $headerStr = implode("\r\n", $headers);
            $ok = @mail($to, $subjectEncoded, $body, $headerStr, '-f' . $fromEmail);
            if ($ok) {
                $sent++;
            } else {
                $failed++;
                $errors[] = "Failed to send to {$to} via mail()";
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'errors' => $errors,
            'from' => $fromEmail,
            'driver' => 'mail',
        ];
    }

    /**
     * Minimal SMTP client (AUTH LOGIN optional).
     *
     * @param list<string> $toEmails
     * @param list<array{name:string,mime:string,content:string}> $attachments
     * @return array{sent:int,failed:int,errors:list<string>,from:string,driver:string}
     */
    private function sendViaSmtp(
        array $toEmails,
        string $subject,
        string $bodyHtml,
        string $fromEmail,
        string $fromName,
        array $attachments = []
    ): array {

        $smtp = is_array($this->cfg['smtp'] ?? null) ? $this->cfg['smtp'] : [];
        $host = trim((string) ($smtp['host'] ?? ''));
        $port = (int) ($smtp['port'] ?? 587);
        $enc = strtolower(trim((string) ($smtp['encryption'] ?? 'tls')));
        $user = trim((string) ($smtp['username'] ?? ''));
        $pass = (string) ($smtp['password'] ?? '');
        $timeout = max(5, (int) ($smtp['timeout'] ?? 20));

        if ($host === '') {
            throw new RuntimeException('SMTP host is not configured in config/mail.php');
        }

        $cafile = $this->resolveCaFile((string) ($smtp['cafile'] ?? ''));
        $crypto = defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')
            ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            : STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }

        $sslOpts = [
            'verify_peer' => $cafile !== '',
            'verify_peer_name' => $cafile !== '',
            'allow_self_signed' => $cafile === '',
            'SNI_enabled' => true,
            'peer_name' => $host,
            'crypto_method' => $crypto,
        ];
        if ($cafile !== '') {
            $sslOpts['cafile'] = $cafile;
        }
        $ctx = stream_context_create(['ssl' => $sslOpts]);

        // Prefer IPv4 — some Wi‑Fi / firewalls reset IPv6 TLS to Office 365.
        $connectHost = $host;
        $ipv4 = gethostbyname($host);
        if ($ipv4 !== '' && $ipv4 !== $host && filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $connectHost = $ipv4;
        }

        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $connectHost . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new RuntimeException("Cannot connect to SMTP {$host}:{$port} — {$errstr} ({$errno})");
        }
        stream_set_timeout($fp, $timeout);

        $this->smtpExpect($fp, 220);
        $this->smtpCmd($fp, 'EHLO evolv.local', [250]);

        if ($enc === 'tls') {
            $this->smtpCmd($fp, 'STARTTLS', [220]);
            $tlsOk = @stream_socket_enable_crypto($fp, true, $crypto);
            if (!$tlsOk && defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                // Retry without cert verify (corporate SSL inspection / missing CA)
                stream_context_set_option($fp, [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                        'SNI_enabled' => true,
                        'peer_name' => $host,
                        'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                    ],
                ]);
                $tlsOk = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
            }
            if (!$tlsOk) {
                $last = error_get_last();
                fclose($fp);
                $detail = trim((string) ($last['message'] ?? ''));
                throw new RuntimeException(
                    'SMTP STARTTLS failed to ' . $host . ':587'
                    . ($detail !== '' ? ' — ' . $detail : '')
                    . '. Outbound TLS to Office 365 is blocked on this network/server.'
                );
            }
            $this->smtpCmd($fp, 'EHLO evolv.local', [250]);
        }

        if ($user !== '' && $pass !== '') {
            $this->smtpCmd($fp, 'AUTH LOGIN', [334]);
            $this->smtpCmd($fp, base64_encode($user), [334]);
            $this->smtpCmd($fp, base64_encode($pass), [235]);
        }

        $sent = 0;
        $failed = 0;
        $errors = [];
        foreach ($toEmails as $to) {
            try {
                $this->smtpCmd($fp, 'MAIL FROM:<' . $fromEmail . '>', [250]);
                $this->smtpCmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
                $this->smtpCmd($fp, 'DATA', [354]);
                $message = $this->buildMimeMessage($to, $fromEmail, $fromName, $subject, $bodyHtml, $attachments);
                fwrite($fp, $message . "\r\n.\r\n");
                $this->smtpExpect($fp, 250);
                $sent++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = "SMTP to {$to}: " . $e->getMessage();
                // Reset transaction if possible
                try {
                    $this->smtpCmd($fp, 'RSET', [250, 220]);
                } catch (Throwable $ignored) {
                }
            }
        }

        try {
            $this->smtpCmd($fp, 'QUIT', [221, 250]);
        } catch (Throwable $ignored) {
        }
        fclose($fp);

        return [
            'sent' => $sent,
            'failed' => $failed,
            'errors' => $errors,
            'from' => $fromEmail,
            'driver' => 'smtp',
        ];
    }

    /**
     * @param list<array{name:string,mime:string,content:string}> $attachments
     */
    private function buildMimeMessage(
        string $to,
        string $fromEmail,
        string $fromName,
        string $subject,
        string $bodyHtml,
        array $attachments = []
    ): string {
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->formatAddress($fromName, $fromEmail),
            'To: <' . $to . '>',
            'Reply-To: ' . $fromEmail,
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'X-Mailer: SAP-Reports-SMTP',
        ];

        if ($attachments) {
            $boundary = '=_sap_' . md5(uniqid((string) mt_rand(), true));
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
            $body = $this->buildMultipartBody($boundary, $bodyHtml, $attachments);
        } else {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $body = $bodyHtml;
        }

        $lines = array_merge($headers, ['', $body]);
        // Dot-stuff lines starting with .
        $out = [];
        foreach ($lines as $line) {
            // Preserve multipart body newlines; only strip CR from header-ish single lines
            if (strpos($line, "\n") !== false) {
                foreach (preg_split("/\r\n|\n|\r/", $line) ?: [] as $part) {
                    if (isset($part[0]) && $part[0] === '.') {
                        $part = '.' . $part;
                    }
                    $out[] = $part;
                }
                continue;
            }
            $line = str_replace(["\r", "\n"], '', $line);
            if (isset($line[0]) && $line[0] === '.') {
                $line = '.' . $line;
            }
            $out[] = $line;
        }
        return implode("\r\n", $out);
    }

    /**
     * @param list<array{name:string,mime:string,content:string}> $attachments
     */
    private function buildMultipartBody(string $boundary, string $bodyHtml, array $attachments): string
    {
        $parts = [];
        $parts[] = '--' . $boundary;
        $parts[] = 'Content-Type: text/html; charset=UTF-8';
        $parts[] = 'Content-Transfer-Encoding: 8bit';
        $parts[] = '';
        $parts[] = $bodyHtml;

        foreach ($attachments as $att) {
            $safeName = str_replace(['"', "\r", "\n"], '', (string) ($att['name'] ?? 'file.bin'));
            $mime = str_replace(["\r", "\n"], '', (string) ($att['mime'] ?? 'application/octet-stream'));
            $parts[] = '--' . $boundary;
            $parts[] = 'Content-Type: ' . $mime . '; name="' . $safeName . '"';
            $parts[] = 'Content-Transfer-Encoding: base64';
            $parts[] = 'Content-Disposition: attachment; filename="' . $safeName . '"';
            $parts[] = '';
            $parts[] = chunk_split(base64_encode((string) ($att['content'] ?? '')), 76, "\r\n");
        }
        $parts[] = '--' . $boundary . '--';
        $parts[] = '';
        return implode("\r\n", $parts);
    }

    /** @param resource $fp */
    private function smtpCmd($fp, string $cmd, array $okCodes): void
    {
        fwrite($fp, $cmd . "\r\n");
        $this->smtpExpect($fp, $okCodes);
    }

    /**
     * @param resource $fp
     * @param int|list<int> $ok
     */
    private function smtpExpect($fp, $ok): void
    {
        $okCodes = is_array($ok) ? $ok : [$ok];
        $response = '';
        while (($line = fgets($fp, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
            if (!isset($line[3])) {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $okCodes, true)) {
            throw new RuntimeException(trim($response) !== '' ? trim($response) : 'Empty SMTP response');
        }
    }

    private function formatAddress(string $name, string $email): string
    {
        $safeName = str_replace(["\r", "\n", '"'], '', $name);
        return '"' . $safeName . '" <' . $email . '>';
    }

    private function resolveCaFile(string $configured): string
    {
        $candidates = array_filter([
            trim($configured),
            (string) (ini_get('openssl.cafile') ?: ''),
            (string) (ini_get('curl.cainfo') ?: ''),
            'C:\\MAMP\\bin\\apache\\bin\\cacert.pem',
            'C:\\MAMP\\bin\\phpMyAdmin\\libraries\\certs\\cacert.pem',
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
        ], static fn ($p): bool => is_string($p) && $p !== '');

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return '';
    }
}
