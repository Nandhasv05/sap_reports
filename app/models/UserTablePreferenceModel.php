<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 29/09/2026
 * DESCRIPTION : dbo.user_table_preferences data access (one row per user + table key)
 */
require_once base_path('app/core/Database.php');

final class UserTablePreferenceModel
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /*
     * Saved row (column_order, hidden_columns, updated_at) or null when the user has none for this table
     */
    public function find(int $userId, string $tableKey): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT column_order, hidden_columns, CONVERT(varchar(19), updated_at, 126) AS updated_at
               FROM dbo.user_table_preferences
              WHERE user_id = CAST(? AS INT) AND table_key = CAST(? AS NVARCHAR(64))'
        );
        $stmt->execute([$userId, $tableKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /*
     * Insert or update the user's row atomically; returns the stored updated_at (UTC, ISO 8601)
     */
    public function upsert(int $userId, string $tableKey, string $columnOrderJson, string $hiddenColumnsJson): string
    {
        $stmt = $this->db()->prepare(
            'MERGE dbo.user_table_preferences WITH (HOLDLOCK) AS t
             USING (SELECT CAST(? AS INT) AS user_id, CAST(? AS NVARCHAR(64)) AS table_key,
                           CAST(? AS NVARCHAR(MAX)) AS column_order, CAST(? AS NVARCHAR(MAX)) AS hidden_columns) AS s
                ON t.user_id = s.user_id AND t.table_key = s.table_key
             WHEN MATCHED THEN
                UPDATE SET column_order = s.column_order, hidden_columns = s.hidden_columns, updated_at = SYSUTCDATETIME()
             WHEN NOT MATCHED THEN
                INSERT (user_id, table_key, column_order, hidden_columns)
                VALUES (s.user_id, s.table_key, s.column_order, s.hidden_columns)
             OUTPUT CONVERT(varchar(19), inserted.updated_at, 126) AS updated_at;'
        );
        $stmt->execute([$userId, $tableKey, $columnOrderJson, $hiddenColumnsJson]);
        return (string) $stmt->fetchColumn();
    }

    public function delete(int $userId, string $tableKey): void
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM dbo.user_table_preferences WHERE user_id = CAST(? AS INT) AND table_key = CAST(? AS NVARCHAR(64))'
        );
        $stmt->execute([$userId, $tableKey]);
    }

    private function db(): PDO
    {
        return $this->pdo ??= Database::connection();
    }
}
