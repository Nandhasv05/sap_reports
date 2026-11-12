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
     * @return array{sent:int,failed:int,errors:list<string>,from:string,driver:string}
     */
    public function send(array $toEmails, string $subject, string $bodyHtml, string $fromEmail = '', string $fromName = ''): array
    {
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
            return $this->sendViaPhpMail($toEmails, $subject, $bodyHtml, $fromEmail, $fromName);
        }

        try {
            return $this->sendViaSmtp($toEmails, $subject, $bodyHtml, $fromEmail, $fromName);
        } catch (Throwable $e) {
            // Fall back to PHP mail() if SMTP fails (e.g. local MAMP without SMTP)
            $fallback = $this->sendViaPhpMail($toEmails, $subject, $bodyHtml, $fromEmail, $fromName);
            $fallback['errors'][] = 'SMTP failed: ' . $e->getMessage();
            $fallback['driver'] = 'smtp→mail';
            return $fallback;
        }
    }

    /**
     * @param list<string> $toEmails
     * @return array{sent:int,failed:int,errors:list<string>,from:string,driver:string}
     */
    private function sendViaPhpMail(array $toEmails, string $subject, string $bodyHtml, string $fromEmail, string $fromName): array
    {
        $sent = 0;
        $failed = 0;
        $errors = [];
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . $this->formatAddress($fromName, $fromEmail),
            'Reply-To: ' . $fromEmail,
            'X-Mailer: PHP/' . PHP_VERSION,
        ];
        $headerStr = implode("\r\n", $headers);
        $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        foreach ($toEmails as $to) {
            $ok = @mail($to, $subjectEncoded, $bodyHtml, $headerStr, '-f' . $fromEmail);
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
     * @return array{sent:int,failed:int,errors:list<string>,from:string,driver:string}
     */
    private function sendViaSmtp(array $toEmails, string $subject, string $bodyHtml, string $fromEmail, string $fromName): array
    {
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

        $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$fp) {
            throw new RuntimeException("Cannot connect to SMTP {$host}:{$port} — {$errstr} ({$errno})");
        }
        stream_set_timeout($fp, $timeout);

        $this->smtpExpect($fp, 220);
        $this->smtpCmd($fp, 'EHLO localhost', [250]);

        if ($enc === 'tls') {
            $this->smtpCmd($fp, 'STARTTLS', [220]);
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (!@stream_socket_enable_crypto($fp, true, $crypto)) {
                fclose($fp);
                throw new RuntimeException('SMTP STARTTLS negotiation failed');
            }
            $this->smtpCmd($fp, 'EHLO localhost', [250]);
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
                $message = $this->buildMimeMessage($to, $fromEmail, $fromName, $subject, $bodyHtml);
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

    private function buildMimeMessage(string $to, string $fromEmail, string $fromName, string $subject, string $bodyHtml): string
    {
        $lines = [
            'Date: ' . date('r'),
            'From: ' . $this->formatAddress($fromName, $fromEmail),
            'To: <' . $to . '>',
            'Reply-To: ' . $fromEmail,
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: SAP-Reports-SMTP',
            '',
            $bodyHtml,
        ];
        // Dot-stuff lines starting with .
        $out = [];
        foreach ($lines as $line) {
            $line = str_replace(["\r", "\n"], '', $line);
            if (isset($line[0]) && $line[0] === '.') {
                $line = '.' . $line;
            }
            $out[] = $line;
        }
        return implode("\r\n", $out);
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
}
