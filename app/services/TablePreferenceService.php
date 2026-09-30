<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 29/09/2026
 * DESCRIPTION : User table column preferences - validation, normalization against the column registry, persistence
 */
require_once base_path('app/services/TableColumnRegistry.php');
require_once base_path('app/services/TablePreferenceException.php');
require_once base_path('app/models/UserTablePreferenceModel.php');

final class TablePreferenceService
{
    private const PAYLOAD_FIELDS = ['columnOrder', 'hiddenColumns'];

    private UserTablePreferenceModel $model;

    public function __construct(?UserTablePreferenceModel $model = null)
    {
        $this->model = $model ?? new UserTablePreferenceModel();
    }

    /*
     * The user's saved configuration, or the table default when none is saved
     */
    public function get(int $userId, string $tableKey): array
    {
        $definition = $this->definition($tableKey);
        $row = $this->model->find($userId, $tableKey);
        if ($row === null) {
            return $this->result($tableKey, $definition, TableColumnRegistry::columnIds($definition), $definition['defaultHidden'], true, null);
        }
        return $this->result(
            $tableKey,
            $definition,
            self::decodeList((string) $row['column_order']),
            self::decodeList((string) $row['hidden_columns']),
            false,
            $row['updated_at']
        );
    }

    /*
     * Validate and store the user's configuration
     */
    public function save(int $userId, string $tableKey, mixed $payload): array
    {
        $definition = $this->definition($tableKey);
        [$order, $hidden] = $this->validate($definition, $payload);

        $normalized = $this->result($tableKey, $definition, $order, $hidden, false, null);
        $normalized['updatedAt'] = $this->model->upsert(
            $userId,
            $tableKey,
            json_encode($normalized['columnOrder'], JSON_UNESCAPED_SLASHES),
            json_encode($normalized['hiddenColumns'], JSON_UNESCAPED_SLASHES)
        );
        return $normalized;
    }

    /*
     * Remove the user's configuration so the default applies again
     */
    public function reset(int $userId, string $tableKey): array
    {
        $definition = $this->definition($tableKey);
        $this->model->delete($userId, $tableKey);
        return $this->result($tableKey, $definition, TableColumnRegistry::columnIds($definition), $definition['defaultHidden'], true, null);
    }

    private function definition(string $tableKey): array
    {
        $definition = preg_match('/^[a-z][a-z0-9_]{0,63}$/', $tableKey) === 1 ? TableColumnRegistry::get($tableKey) : null;
        if ($definition === null) {
            throw new TablePreferenceException(404, 'unknown_table', 'Unknown table.');
        }
        return $definition;
    }

    /*
     * Returns [columnOrder, hiddenColumns]; rejects unknown fields, unknown / duplicate column ids and hiding locked columns
     */
    private function validate(array $definition, mixed $payload): array
    {
        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new TablePreferenceException(422, 'invalid_payload', 'The request body must be a JSON object.');
        }

        $errors = [];
        $unexpected = array_diff(array_map('strval', array_keys($payload)), self::PAYLOAD_FIELDS);
        if ($unexpected !== []) {
            $errors['payload'] = 'Unexpected field(s): ' . implode(', ', array_slice($unexpected, 0, 5)) . '.';
        }

        $known = TableColumnRegistry::columnIds($definition);
        if (!array_key_exists('columnOrder', $payload)) {
            $errors['columnOrder'] = 'columnOrder is required.';
        } else {
            $error = self::listError($payload['columnOrder'], $known);
            if ($error !== null) {
                $errors['columnOrder'] = $error;
            }
        }

        $hidden = $payload['hiddenColumns'] ?? [];
        $error = self::listError($hidden, $known);
        if ($error === null && array_intersect($hidden, TableColumnRegistry::LOCKED) !== []) {
            $error = 'Locked columns cannot be hidden.';
        }
        if ($error !== null) {
            $errors['hiddenColumns'] = $error;
        }

        if ($errors !== []) {
            throw new TablePreferenceException(422, 'validation_failed', 'The column configuration is invalid.', $errors);
        }
        return [$payload['columnOrder'], $hidden];
    }

    private static function listError(mixed $list, array $known): ?string
    {
        if (!is_array($list) || !array_is_list($list)) {
            return 'Must be an array of column ids.';
        }
        if (count($list) > count($known)) {
            return 'Too many column ids.';
        }
        foreach ($list as $id) {
            if (!is_string($id)) {
                return 'Column ids must be strings.';
            }
            if (!in_array($id, $known, true)) {
                return 'Unknown column id: ' . mb_substr($id, 0, 64) . '.';
            }
        }
        if (count(array_unique($list)) !== count($list)) {
            return 'Duplicate column ids are not allowed.';
        }
        return null;
    }

    /*
     * Locked columns first, saved order next (unknown / duplicate ids dropped); columns added since the save
     * go right after the column that precedes them in the default order
     */
    private function result(string $tableKey, array $definition, array $order, array $hidden, bool $isDefault, ?string $updatedAt): array
    {
        $known = TableColumnRegistry::columnIds($definition);
        $saved = array_values(array_intersect(array_unique(array_filter($order, 'is_string')), $known));
        $locked = array_values(array_intersect($known, TableColumnRegistry::LOCKED));
        $columnOrder = array_values(array_unique(array_merge($locked, $saved)));
        foreach ($known as $i => $id) {
            if (in_array($id, $columnOrder, true)) {
                continue;
            }
            $at = count($columnOrder);
            for ($p = $i - 1; $p >= 0; $p--) {
                $pos = array_search($known[$p], $columnOrder, true);
                if ($pos !== false) {
                    $at = $pos + 1;
                    break;
                }
            }
            array_splice($columnOrder, $at, 0, [$id]);
        }
        $hiddenColumns = array_values(array_diff(array_intersect($known, array_filter($hidden, 'is_string')), $locked));

        return [
            'success'       => true,
            'tableKey'      => $tableKey,
            'columnOrder'   => $columnOrder,
            'hiddenColumns' => $hiddenColumns,
            'isDefault'     => $isDefault,
            'updatedAt'     => $updatedAt,
        ];
    }

    private static function decodeList(string $json): array
    {
        $value = json_decode($json, true);
        return is_array($value) && array_is_list($value) ? $value : [];
    }
}
