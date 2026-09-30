<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 29/09/2026
 * DESCRIPTION : Portal database (CLIENT_API_LIVE) connection
 */
final class Database
{
    private static ?PDO $pdo = null;

    /*
     * Settings come from config/database.php; tries an encrypted connection first, then unencrypted
     */
    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $c = config('database');
        if (($c['server'] ?? '') === '' || ($c['database'] ?? '') === '') {
            throw new RuntimeException('Portal database configuration was not found.');
        }

        $server = str_contains((string) $c['server'], ',') ? (string) $c['server'] : $c['server'] . ',' . ($c['port'] ?? '1433');
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => (int) ($c['timeout'] ?? 8),
        ];
        $errors = [];
        foreach (['yes', 'no'] as $encrypt) {
            $dsn = 'odbc:Driver={ODBC Driver 18 for SQL Server};Server=' . $server
                . ';Database=' . $c['database']
                . ';Encrypt=' . $encrypt
                . ';TrustServerCertificate=yes;LoginTimeOut=' . (int) ($c['timeout'] ?? 8) . ';';
            try {
                return self::$pdo = new PDO($dsn, (string) $c['user'], (string) $c['password'], $opts);
            } catch (PDOException $e) {
                $errors[] = $e->getMessage();
            }
        }
        throw new PDOException('Could not connect to ' . $c['database'] . ' on ' . $server . '. ' . ($errors[0] ?? ''));
    }
}
