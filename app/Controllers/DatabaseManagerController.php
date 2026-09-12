<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\AuditLogger;
use PDO;
use PDOException;
use Throwable;

class DatabaseManagerController
{
    /**
     * Helper to retrieve and validate database connection and valid tables list
     */
    private function getValidTables(PDO $db): array
    {
        $stmt = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Helper to retrieve columns and primary keys for a given validated table
     */
    private function getTableColumns(PDO $db, string $table): array
    {
        $stmt = $db->query("SHOW FULL COLUMNS FROM `{$table}`");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $primaryKeys = [];
        $columnNames = [];

        foreach ($columns as $col) {
            $columnNames[] = $col['Field'];
            if ($col['Key'] === 'PRI') {
                $primaryKeys[] = $col['Field'];
            }
        }

        return [
            'columns' => $columns,
            'column_names' => $columnNames,
            'primary_keys' => $primaryKeys
        ];
    }

    /**
     * GET /v1/superadmin/database/tables
     * Lists all base tables in the current database with row counts, size, and metadata
     */
    public function getTables(Request $request, array $params = []): void
    {
        $db = Database::getConnection();

        $sql = "
            SELECT 
                TABLE_NAME AS table_name,
                TABLE_ROWS AS estimated_rows,
                DATA_LENGTH AS data_length_bytes,
                INDEX_LENGTH AS index_length_bytes,
                (DATA_LENGTH + INDEX_LENGTH) AS total_size_bytes,
                ENGINE AS engine,
                TABLE_COLLATION AS collation,
                CREATE_TIME AS created_at,
                UPDATE_TIME AS updated_at
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME ASC
        ";

        $stmt = $db->query($sql);
        $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Enhance with exact row counts and column counts for precision
        foreach ($tables as &$t) {
            $tableName = $t['table_name'];
            try {
                $cntStmt = $db->query("SELECT COUNT(*) FROM `{$tableName}`");
                $t['exact_rows'] = (int)$cntStmt->fetchColumn();
            } catch (Throwable $e) {
                $t['exact_rows'] = (int)($t['estimated_rows'] ?? 0);
            }

            try {
                $colStmt = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tableName}'");
                $t['column_count'] = (int)$colStmt->fetchColumn();
            } catch (Throwable $e) {
                $t['column_count'] = 0;
            }

            // Human readable size
            $bytes = (int)($t['total_size_bytes'] ?? 0);
            if ($bytes >= 1048576) {
                $t['size_formatted'] = number_format($bytes / 1048576, 2) . ' MB';
            } elseif ($bytes >= 1024) {
                $t['size_formatted'] = number_format($bytes / 1024, 1) . ' KB';
            } else {
                $t['size_formatted'] = $bytes . ' B';
            }
        }
        unset($t);

        Response::success($tables);
    }

    /**
     * GET /v1/superadmin/database/tables/{table}
     * Retrieves table schema, primary keys, and paginated rows with search & sorting
     */
    public function getTableData(Request $request, array $params = []): void
    {
        $tableName = trim($params['table'] ?? '');
        $db = Database::getConnection();

        $validTables = $this->getValidTables($db);
        if (!in_array($tableName, $validTables, true)) {
            Response::error("Table '{$tableName}' does not exist in the database.", 404);
            return;
        }

        $schema = $this->getTableColumns($db, $tableName);
        $columns = $schema['columns'];
        $columnNames = $schema['column_names'];
        $primaryKeys = $schema['primary_keys'];

        // Pagination parameters
        $page = max(1, (int)($request->get('page') ?? 1));
        $perPage = max(10, min(500, (int)($request->get('per_page') ?? 50)));
        $offset = ($page - 1) * $perPage;

        // Search parameter
        $search = trim((string)($request->get('search') ?? ''));
        $whereClauses = [];
        $bindings = [];

        if ($search !== '') {
            $searchParts = [];
            foreach ($columns as $idx => $col) {
                $field = $col['Field'];
                $placeholder = ":search_{$idx}";
                $searchParts[] = "CAST(`{$field}` AS CHAR) LIKE {$placeholder}";
                $bindings[$placeholder] = '%' . $search . '%';
            }
            if (!empty($searchParts)) {
                $whereClauses[] = '(' . implode(' OR ', $searchParts) . ')';
            }
        }

        $whereSql = !empty($whereClauses) ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

        // Sorting
        $sortCol = (string)($request->get('sort_col') ?? '');
        if (!in_array($sortCol, $columnNames, true)) {
            $sortCol = !empty($primaryKeys) ? $primaryKeys[0] : $columnNames[0];
        }

        $sortDir = strtoupper((string)($request->get('sort_dir') ?? 'ASC'));
        if ($sortDir !== 'ASC' && $sortDir !== 'DESC') {
            $sortDir = 'ASC';
        }

        // Count total matching rows
        $countSql = "SELECT COUNT(*) FROM `{$tableName}` {$whereSql}";
        $countStmt = $db->prepare($countSql);
        foreach ($bindings as $k => $v) {
            $countStmt->bindValue($k, $v);
        }
        $countStmt->execute();
        $totalRows = (int)$countStmt->fetchColumn();
        $totalPages = (int)ceil($totalRows / $perPage);

        // Fetch paginated rows
        $dataSql = "SELECT * FROM `{$tableName}` {$whereSql} ORDER BY `{$sortCol}` {$sortDir} LIMIT :limit OFFSET :offset";
        $dataStmt = $db->prepare($dataSql);
        foreach ($bindings as $k => $v) {
            $dataStmt->bindValue($k, $v);
        }
        $dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $dataStmt->execute();
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        Response::success([
            'table' => $tableName,
            'columns' => $columns,
            'primary_keys' => $primaryKeys,
            'rows' => $rows,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_rows' => $totalRows,
                'total_pages' => $totalPages,
                'sort_col' => $sortCol,
                'sort_dir' => $sortDir,
                'search' => $search
            ]
        ]);
    }

    /**
     * POST /v1/superadmin/database/tables/{table}/rows
     * Insert a new record into table
     */
    public function insertRow(Request $request, array $params = []): void
    {
        $tableName = trim($params['table'] ?? '');
        $db = Database::getConnection();

        $validTables = $this->getValidTables($db);
        if (!in_array($tableName, $validTables, true)) {
            Response::error("Table '{$tableName}' does not exist.", 404);
            return;
        }

        $schema = $this->getTableColumns($db, $tableName);
        $columns = $schema['columns'];
        $columnNames = $schema['column_names'];

        $inputData = $request->all();
        if (empty($inputData) || !is_array($inputData)) {
            Response::error('Row data payload is empty.', 422);
            return;
        }

        $fieldsToInsert = [];
        $placeholders = [];
        $bindings = [];

        foreach ($columns as $col) {
            $field = $col['Field'];
            $isAutoInc = str_contains(strtolower($col['Extra'] ?? ''), 'auto_increment');

            if (array_key_exists($field, $inputData)) {
                $val = $inputData[$field];

                // If auto increment and empty, omit it
                if ($isAutoInc && ($val === '' || $val === null)) {
                    continue;
                }

                // If empty string and nullable, treat as NULL if desired
                if ($val === '' && $col['Null'] === 'YES' && !str_starts_with(strtolower($col['Type']), 'varchar') && !str_starts_with(strtolower($col['Type']), 'text')) {
                    $val = null;
                }

                $placeholder = ":ins_{$field}";
                $fieldsToInsert[] = "`{$field}`";
                $placeholders[] = $placeholder;
                $bindings[$placeholder] = $val;
            }
        }

        if (empty($fieldsToInsert)) {
            Response::error('No valid columns provided for insertion.', 422);
            return;
        }

        $insertSql = "INSERT INTO `{$tableName}` (" . implode(', ', $fieldsToInsert) . ") VALUES (" . implode(', ', $placeholders) . ")";
        
        try {
            $stmt = $db->prepare($insertSql);
            $stmt->execute($bindings);
            $lastInsertId = $db->lastInsertId();

            AuditLogger::log('database_row_inserted', $tableName, $lastInsertId ?: null, [
                'table' => $tableName,
                'data' => $inputData
            ]);

            Response::success([
                'inserted_id' => $lastInsertId,
                'message' => 'Row inserted successfully into ' . $tableName
            ], 'Row created successfully');
        } catch (PDOException $e) {
            Response::error('Database Error: ' . $e->getMessage(), 400);
        }
    }

    /**
     * PUT /v1/superadmin/database/tables/{table}/rows
     * Update an existing row or cell in table
     * Payload: { pkeys: { id: 123 }, data: { col: 'new_val' } }
     */
    public function updateRow(Request $request, array $params = []): void
    {
        $tableName = trim($params['table'] ?? '');
        $db = Database::getConnection();

        $validTables = $this->getValidTables($db);
        if (!in_array($tableName, $validTables, true)) {
            Response::error("Table '{$tableName}' does not exist.", 404);
            return;
        }

        $schema = $this->getTableColumns($db, $tableName);
        $columns = $schema['columns'];
        $columnNames = $schema['column_names'];
        $primaryKeys = $schema['primary_keys'];

        if (empty($primaryKeys)) {
            Response::error("Table '{$tableName}' has no primary key defined. Updating rows is not supported.", 400);
            return;
        }

        $pkeys = $request->get('pkeys');
        $data = $request->get('data');

        if (empty($pkeys) || !is_array($pkeys)) {
            Response::error('Primary key identifiers (pkeys) must be provided.', 422);
            return;
        }

        if (empty($data) || !is_array($data)) {
            Response::error('Update data payload is empty.', 422);
            return;
        }

        // Validate WHERE clause using primary keys
        $whereParts = [];
        $bindings = [];
        foreach ($primaryKeys as $pk) {
            if (!isset($pkeys[$pk])) {
                Response::error("Missing primary key value for '{$pk}'.", 422);
                return;
            }
            $whereParts[] = "`{$pk}` = :pk_{$pk}";
            $bindings[":pk_{$pk}"] = $pkeys[$pk];
        }

        // Validate SET columns
        $setParts = [];
        foreach ($data as $colName => $val) {
            if (!in_array($colName, $columnNames, true)) {
                continue; // Ignore unknown fields
            }

            // Find column definition
            $colDef = null;
            foreach ($columns as $c) {
                if ($c['Field'] === $colName) {
                    $colDef = $c;
                    break;
                }
            }

            if ($val === '' && $colDef && $colDef['Null'] === 'YES' && !str_starts_with(strtolower($colDef['Type']), 'varchar') && !str_starts_with(strtolower($colDef['Type']), 'text')) {
                $val = null;
            }

            $setPlaceholder = ":set_{$colName}";
            $setParts[] = "`{$colName}` = {$setPlaceholder}";
            $bindings[$setPlaceholder] = $val;
        }

        if (empty($setParts)) {
            Response::error('No valid columns specified to update.', 422);
            return;
        }

        $updateSql = "UPDATE `{$tableName}` SET " . implode(', ', $setParts) . " WHERE " . implode(' AND ', $whereParts);

        try {
            $stmt = $db->prepare($updateSql);
            $stmt->execute($bindings);

            $resourceId = isset($pkeys['id']) && is_numeric($pkeys['id']) ? (int)$pkeys['id'] : null;
            AuditLogger::log('database_row_updated', $tableName, $resourceId, [
                'table' => $tableName,
                'pkeys' => $pkeys,
                'updated_fields' => array_keys($data)
            ]);

            Response::success([
                'affected_rows' => $stmt->rowCount(),
                'message' => 'Row updated successfully in ' . $tableName
            ], 'Row updated successfully');
        } catch (PDOException $e) {
            Response::error('Database Error: ' . $e->getMessage(), 400);
        }
    }

    /**
     * DELETE /v1/superadmin/database/tables/{table}/rows
     * Delete a row by primary key
     * Payload: { pkeys: { id: 123 } }
     */
    public function deleteRow(Request $request, array $params = []): void
    {
        $tableName = trim($params['table'] ?? '');
        $db = Database::getConnection();

        $validTables = $this->getValidTables($db);
        if (!in_array($tableName, $validTables, true)) {
            Response::error("Table '{$tableName}' does not exist.", 404);
            return;
        }

        $schema = $this->getTableColumns($db, $tableName);
        $primaryKeys = $schema['primary_keys'];

        if (empty($primaryKeys)) {
            Response::error("Table '{$tableName}' has no primary key defined. Direct row deletion not supported.", 400);
            return;
        }

        $pkeys = $request->get('pkeys');
        if (empty($pkeys) || !is_array($pkeys)) {
            Response::error('Primary key identifiers (pkeys) must be provided.', 422);
            return;
        }

        $whereParts = [];
        $bindings = [];
        foreach ($primaryKeys as $pk) {
            if (!isset($pkeys[$pk])) {
                Response::error("Missing primary key value for '{$pk}'.", 422);
                return;
            }
            $whereParts[] = "`{$pk}` = :pk_{$pk}";
            $bindings[":pk_{$pk}"] = $pkeys[$pk];
        }

        $deleteSql = "DELETE FROM `{$tableName}` WHERE " . implode(' AND ', $whereParts) . " LIMIT 1";

        try {
            $stmt = $db->prepare($deleteSql);
            $stmt->execute($bindings);

            $resourceId = isset($pkeys['id']) && is_numeric($pkeys['id']) ? (int)$pkeys['id'] : null;
            AuditLogger::log('database_row_deleted', $tableName, $resourceId, [
                'table' => $tableName,
                'pkeys' => $pkeys
            ]);

            Response::success([
                'deleted_rows' => $stmt->rowCount(),
                'message' => 'Row deleted successfully from ' . $tableName
            ], 'Row deleted successfully');
        } catch (PDOException $e) {
            Response::error('Cannot delete row: ' . $e->getMessage(), 400);
        }
    }

    /**
     * GET /v1/public/db-info
     * Public endpoint — no auth required.
     * Returns comprehensive schema info: all tables, all fields and full field properties.
     */
    public function getDbInfo(Request $request, array $params = []): void
    {
        $db = Database::getConnection();

        // Fetch all base tables with metadata
        $sql = "
            SELECT
                TABLE_NAME          AS table_name,
                TABLE_ROWS          AS estimated_rows,
                DATA_LENGTH         AS data_length_bytes,
                INDEX_LENGTH        AS index_length_bytes,
                (DATA_LENGTH + INDEX_LENGTH) AS total_size_bytes,
                ENGINE              AS engine,
                TABLE_COLLATION     AS collation,
                TABLE_COMMENT       AS table_comment,
                CREATE_TIME         AS created_at,
                UPDATE_TIME         AS updated_at
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME ASC
        ";
        $stmt = $db->query($sql);
        $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($tables as $t) {
            $tableName = $t['table_name'];

            // Human-readable size
            $bytes = (int)($t['total_size_bytes'] ?? 0);
            if ($bytes >= 1048576) {
                $t['size_formatted'] = number_format($bytes / 1048576, 2) . ' MB';
            } elseif ($bytes >= 1024) {
                $t['size_formatted'] = number_format($bytes / 1024, 1) . ' KB';
            } else {
                $t['size_formatted'] = $bytes . ' B';
            }

            // Exact row count
            try {
                $cntStmt = $db->query("SELECT COUNT(*) FROM `{$tableName}`");
                $t['exact_rows'] = (int)$cntStmt->fetchColumn();
            } catch (Throwable $e) {
                $t['exact_rows'] = (int)($t['estimated_rows'] ?? 0);
            }

            // Full column schema (SHOW FULL COLUMNS)
            try {
                $colStmt = $db->query("SHOW FULL COLUMNS FROM `{$tableName}`");
                $t['columns'] = $colStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $t['columns'] = [];
            }

            $t['column_count'] = count($t['columns']);
            $result[] = $t;
        }

        Response::success([
            'generated_at' => date('Y-m-d H:i:s'),
            'database'     => $db->query('SELECT DATABASE()')->fetchColumn(),
            'table_count'  => count($result),
            'tables'       => $result,
        ]);
    }

    /**
     * GET /v1/superadmin/database/tables/{table}/export
     * Export all or filtered table records as CSV
     */
    public function exportCsv(Request $request, array $params = []): void
    {
        $tableName = trim($params['table'] ?? '');
        $db = Database::getConnection();

        $validTables = $this->getValidTables($db);
        if (!in_array($tableName, $validTables, true)) {
            Response::error("Table '{$tableName}' does not exist.", 404);
            return;
        }

        $schema = $this->getTableColumns($db, $tableName);
        $columns = $schema['columns'];
        $columnNames = $schema['column_names'];

        $search = trim((string)($request->get('search') ?? ''));
        $whereClauses = [];
        $bindings = [];

        if ($search !== '') {
            $searchParts = [];
            foreach ($columns as $idx => $col) {
                $field = $col['Field'];
                $placeholder = ":search_{$idx}";
                $searchParts[] = "CAST(`{$field}` AS CHAR) LIKE {$placeholder}";
                $bindings[$placeholder] = '%' . $search . '%';
            }
            if (!empty($searchParts)) {
                $whereClauses[] = '(' . implode(' OR ', $searchParts) . ')';
            }
        }

        $whereSql = !empty($whereClauses) ? ' WHERE ' . implode(' AND ', $whereClauses) : '';
        $sql = "SELECT * FROM `{$tableName}` {$whereSql} LIMIT 5000";

        $stmt = $db->prepare($sql);
        foreach ($bindings as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $tableName . '_export_' . date('Y-m-d_His') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        // Write header
        fputcsv($output, $columnNames);

        // Write rows
        foreach ($rows as $row) {
            $rowValues = [];
            foreach ($columnNames as $colName) {
                $val = $row[$colName] ?? '';
                if (is_array($val)) {
                    $val = json_encode($val);
                }
                $rowValues[] = $val;
            }
            fputcsv($output, $rowValues);
        }
        fclose($output);
        exit;
    }
}
