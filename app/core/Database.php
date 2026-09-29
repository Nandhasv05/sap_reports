<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 29/09/2026
 * DESCRIPTION : Portal database (CLIENT_API_LIVE) connection shared with the EVOL portal login
 */
require_once __DIR__ . '/access.php';

final class Database
{
    private static ?PDO $pdo = null;

    /*
     * Reuses the EVOL portal connection settings so the app never holds its own credentials
     */
    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $evolDir = sap_reports_evol_dir();
        $configFile = $evolDir . DIRECTORY_SEPARATOR . 'db_config_client_api.php';
        if ($evolDir === '' || !is_file($configFile)) {
            throw new RuntimeException('Portal database configuration was not found.');
        }
        require_once $configFile;

        self::$pdo = evol_client_api_pdo();
        return self::$pdo;
    }
}
