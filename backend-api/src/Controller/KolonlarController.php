<?php

declare(strict_types=1);

namespace App\Controller;

/*
 * Controller SQL kolon envanteri.
 *   GET|POST /backend-api/kolonlar
 *
 * DB'ye dokunmaz. Sadece controller dosyalarindaki SQL string'lerini tarar,
 * kullanilan gercek tablo.kolon referanslarini ve hangi sorguda gectigini basar.
 */
final class KolonlarController extends Controller
{
    /**
     * @Get
     * @Post
     */
    public function createMissing(): void
    {
        $inventory = $this->controllerSqlInventory();

        $this->response->success([
            'source' => 'controller_sql_inventory',
            'total_tables' => count($inventory['tables']),
            'total_columns' => $inventory['total_columns'],
            'calculate_missing_from_setup' => $this->calculateMissingFromSetup(),
            'skipped' => $inventory['skipped'],
            'tables' => array_values($inventory['tables']),
        ]);
    }

    /**
     * @return array{tables:array<string,array<string,mixed>>,total_columns:int,skipped:array<int,array<string,mixed>>}
     */
    private function controllerSqlInventory(): array
    {
        $files = glob(__DIR__ . '/*Controller.php');
        if (!is_array($files)) {
            return ['tables' => [], 'total_columns' => 0, 'skipped' => []];
        }

        $tables = [];
        $skipped = [];

        foreach ($files as $file) {
            $filename = basename($file);
            if (in_array($filename, ['Controller.php', 'KolonlarController.php'], true)) {
                continue;
            }

            $content = file_get_contents($file);
            if (!is_string($content) || $content === '') {
                continue;
            }

            $controller = substr($filename, 0, -4);
            $cteNames = $this->sqlCteNames($content);
            $queries = $this->sqlFragments($content);

            foreach ($queries as $queryIndex => $sql) {
                $virtualAliases = $this->sqlVirtualAliases($sql);
                $aliases = $this->sqlAliasMap($sql, $cteNames + $this->sqlCteNames($sql), $virtualAliases);

                foreach ($this->sqlColumnReferences($sql) as $ref) {
                    $alias = $ref['alias'];
                    $column = $ref['column'];
                    $lowerAlias = strtolower($alias);

                    if (isset($virtualAliases[$lowerAlias])) {
                        $skipKey = 'virtual_alias:' . strtolower($alias . '.' . $column) . ':' . $controller . ':' . $queryIndex;
                        $skipped[$skipKey] = [
                            'reason' => 'virtual_alias',
                            'reference' => $alias . '.' . $column,
                            'controller' => $controller,
                            'query_index' => $queryIndex,
                        ];
                        continue;
                    }
                    if (!isset($aliases[$alias])) {
                        continue;
                    }

                    $table = $aliases[$alias];
                    if (!$this->referenceAllowed($table, $column)) {
                        $skipKey = 'known_false_positive:' . strtolower($table . '.' . $column) . ':' . $controller . ':' . $queryIndex;
                        $skipped[$skipKey] = [
                            'reason' => 'known_false_positive',
                            'reference' => $table . '.' . $column,
                            'controller' => $controller,
                            'query_index' => $queryIndex,
                        ];
                        continue;
                    }

                    $tableKey = strtolower($table);
                    $columnKey = strtolower($column);
                    if (!isset($tables[$tableKey])) {
                        $tables[$tableKey] = [
                            'table' => $table,
                            'columns' => [],
                        ];
                    }
                    if (!isset($tables[$tableKey]['columns'][$columnKey])) {
                        $tables[$tableKey]['columns'][$columnKey] = [
                            'column' => $column,
                            'used_in' => [],
                        ];
                    }

                    $usageKey = $controller . ':' . $queryIndex;
                    if (!isset($tables[$tableKey]['columns'][$columnKey]['used_in'][$usageKey])) {
                        $tables[$tableKey]['columns'][$columnKey]['used_in'][$usageKey] = [
                            'controller' => $controller,
                            'file' => $filename,
                            'query_index' => $queryIndex,
                            'query' => $this->compactSql($sql),
                        ];
                    }
                }
            }
        }

        $this->addManualColumnReferences($tables);

        ksort($tables);
        $totalColumns = 0;
        foreach ($tables as &$table) {
            ksort($table['columns']);
            foreach ($table['columns'] as &$column) {
                $column['used_in'] = array_values($column['used_in']);
            }
            unset($column);
            $table['columns'] = array_values($table['columns']);
            $table['column_count'] = count($table['columns']);
            $totalColumns += $table['column_count'];
        }
        unset($table);

        return [
            'tables' => $tables,
            'total_columns' => $totalColumns,
            'skipped' => array_values($skipped),
        ];
    }

    /**
     * Parser'in yakalayamadigi dinamik/unqualified SQL kolonlarini envantere ekler.
     *
     * @param array<string,array<string,mixed>> $tables
     */
    private function addManualColumnReferences(array &$tables): void
    {
        foreach ($this->calculateManualColumns() as $table => $columns) {
            foreach ($columns as $column) {
                $this->addManualColumnReference(
                    $tables,
                    $table,
                    $column,
                    'CalculateController',
                    'CalculateController dynamic SQL/manual inventory'
                );
            }
        }
    }

    /**
     * @return array<string,array<int,string>>
     */
    private function calculateManualColumns(): array
    {
        return [
            'KiralamaTakvimi.CalendarHomes' => [
                'homesId',
                'EstateId',
                'RoomType',
                'BookableDirectly',
            ],
            'KiralamaTakvimi.HotelAvailabilityRooms' => [
                'EstateId',
                'Date',
                'RoomCount',
                'IsClosed',
            ],
            'dolu' => [
                'id',
                'emlak',
                'durum',
                'Durum',
                'tarih',
                'tarih2',
            ],
            'homes' => [
                'id',
                'aktif',
                'baslik',
                'depozito',
                'doviz',
                'hasar',
                'kazancorani',
                'kur',
                'resim',
                'sitemap',
            ],
            'sezonlar' => [
                'site',
                'islem_id',
                'islem',
                'tarih1',
                'tarih2',
                'gece',
                'temizlikgece',
                'temizlikFiyat',
                'isitmaFiyat',
                'isitmaHizmetDisi',
            ],
            'dbo.HomesExtraPayments' => [
                'id',
                'homesId',
                'title',
                'amount',
                'CurrencyId',
                'start_date',
                'end_date',
                'IsOptional',
                'Type',
            ],
            'promotionCodes' => [
                'code',
                'startDate',
                'endDate',
                'stock',
                'isPrice',
                'value',
            ],
            'Finance.Currency' => [
                'CurrencyId',
                'CurrencyName',
                'CurrencyCode',
                'Symbol',
            ],
            'Finance.Rate' => [
                'RateId',
            ],
            'Finance.RateDetail' => [
                'RateId',
                'FromCurrencyId',
                'ToCurrencyId',
                'Buy',
            ],
        ];
    }

    /**
     * @return array<int,array{table:string,missing_columns:array<int,string>,missing_count:int}>
     */
    private function calculateMissingFromSetup(): array
    {
        $setupColumns = $this->setupColumnSet();
        $missing = [];

        foreach ($this->calculateManualColumns() as $table => $columns) {
            foreach ($columns as $column) {
                $key = strtolower($this->normalizeSetupTableName($table));
                if (isset($setupColumns[$key][strtolower($column)])) {
                    continue;
                }

                $missing[$table][] = $column;
            }
        }

        $result = [];
        foreach ($missing as $table => $columns) {
            $columns = array_values(array_unique($columns));
            sort($columns);
            $result[] = [
                'table' => $table,
                'missing_columns' => $columns,
                'missing_count' => count($columns),
            ];
        }

        return $result;
    }

    /**
     * @return array<string,array<string,bool>>
     */
    private function setupColumnSet(): array
    {
        $path = dirname(__DIR__, 2) . '/setup-collums.sql';
        $content = is_file($path) ? file_get_contents($path) : '';
        if (!is_string($content) || $content === '') {
            return [];
        }

        $columns = [];
        $count = preg_match_all(
            "/COL_LENGTH\\(N?'([^']+)'\\s*,\\s*N?'([^']+)'\\)/i",
            $content,
            $matches,
            PREG_SET_ORDER
        );
        if ($count === false || $count === 0) {
            return [];
        }

        foreach ($matches as $match) {
            $table = strtolower($this->normalizeSetupTableName($match[1]));
            $column = strtolower($match[2]);
            $columns[$table][$column] = true;
        }

        return $columns;
    }

    private function normalizeSetupTableName(string $table): string
    {
        $table = str_replace(['[', ']'], '', trim($table));
        if (stripos($table, 'dbo.') === 0) {
            return substr($table, 4);
        }

        return $table;
    }

    /**
     * @param array<string,array<string,mixed>> $tables
     */
    private function addManualColumnReference(
        array &$tables,
        string $table,
        string $column,
        string $controller,
        string $query
    ): void {
        if (!$this->referenceAllowed($table, $column)) {
            return;
        }

        $tableKey = strtolower($table);
        $columnKey = strtolower($column);
        if (!isset($tables[$tableKey])) {
            $tables[$tableKey] = [
                'table' => $table,
                'columns' => [],
            ];
        }
        if (!isset($tables[$tableKey]['columns'][$columnKey])) {
            $tables[$tableKey]['columns'][$columnKey] = [
                'column' => $column,
                'used_in' => [],
            ];
        }

        $usageKey = $controller . ':manual';
        if (!isset($tables[$tableKey]['columns'][$columnKey]['used_in'][$usageKey])) {
            $tables[$tableKey]['columns'][$columnKey]['used_in'][$usageKey] = [
                'controller' => $controller,
                'file' => $controller . '.php',
                'query_index' => -1,
                'query' => $query,
            ];
        }
    }

    /**
     * @return array<int,string>
     */
    private function sqlFragments(string $content): array
    {
        $fragments = [];
        $pattern = '/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'/s';
        $count = preg_match_all($pattern, $content, $matches);
        if ($count === false || $count === 0) {
            return [];
        }

        foreach ($matches[0] as $literal) {
            $quote = substr($literal, 0, 1);
            $value = substr($literal, 1, -1);
            $value = $quote === '"' ? stripcslashes($value) : str_replace(["\\\\", "\\'"], ["\\", "'"], $value);
            if (preg_match('/\b(SELECT|FROM|JOIN|UPDATE|INSERT\s+INTO|DELETE\s+FROM|ALTER\s+TABLE|CREATE\s+TABLE)\b/i', $value) === 1) {
                $fragments[] = $value;
            }
        }

        return $fragments;
    }

    /**
     * @param array<string,bool> $cteNames
     * @param array<string,bool> $virtualAliases
     * @return array<string,string>
     */
    private function sqlAliasMap(string $sql, array $cteNames, array $virtualAliases): array
    {
        $aliasTargets = [];
        $aliases = [];
        $keywords = array_fill_keys([
            'ON', 'WHERE', 'INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS', 'OUTER',
            'JOIN', 'ORDER', 'GROUP', 'WITH', 'VALUES', 'SET',
        ], true);

        $pattern = '/\b(?:FROM|JOIN|UPDATE|INTO)\s+((?:\[?[A-Za-z_][A-Za-z0-9_]*\]?\.)?\[?[A-Za-z_][A-Za-z0-9_]*\]?)(?:\s+(?:AS\s+)?(\[?[A-Za-z_][A-Za-z0-9_]*\]?))?/i';
        $count = preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER);
        if ($count === false || $count === 0) {
            return [];
        }

        foreach ($matches as $match) {
            $table = $this->cleanIdentifier($match[1]);
            if (!$this->identifierAllowed($table) || isset($cteNames[strtolower($table)])) {
                continue;
            }

            $unqualified = $this->unqualifiedTable($table);
            if (!isset($virtualAliases[strtolower($unqualified)])) {
                $aliasTargets[$unqualified][strtolower($table)] = $table;
            }

            $alias = isset($match[2]) ? $this->cleanIdentifier($match[2]) : '';
            if (
                $alias !== ''
                && !isset($virtualAliases[strtolower($alias)])
                && !isset($keywords[strtoupper($alias)])
                && $this->columnAllowed($alias)
            ) {
                $aliasTargets[$alias][strtolower($table)] = $table;
            }
        }

        foreach ($aliasTargets as $alias => $targets) {
            if (count($targets) === 1) {
                $aliases[$alias] = array_values($targets)[0];
            }
        }

        return $aliases;
    }

    /**
     * @return array<string,bool>
     */
    private function sqlVirtualAliases(string $sql): array
    {
        $aliases = [];
        foreach ([
            '/\)\s+AS\s+([A-Za-z_][A-Za-z0-9_]*)/i',
            '/\)\s+([A-Za-z_][A-Za-z0-9_]*)\s*(?:WHERE|JOIN|INNER|LEFT|RIGHT|FULL|OUTER|CROSS|ORDER|GROUP|$)/i',
        ] as $pattern) {
            $count = preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER);
            if ($count === false || $count === 0) {
                continue;
            }
            foreach ($matches as $match) {
                $aliases[strtolower($match[1])] = true;
            }
        }

        return $aliases;
    }

    /**
     * @return array<string,bool>
     */
    private function sqlCteNames(string $sql): array
    {
        $names = [];
        $count = preg_match_all('/\b(?:WITH|,)\s+([A-Za-z_][A-Za-z0-9_]*)\s+AS\s*\(/i', $sql, $matches, PREG_SET_ORDER);
        if ($count === false || $count === 0) {
            return [];
        }

        foreach ($matches as $match) {
            $names[strtolower($match[1])] = true;
        }

        return $names;
    }

    /**
     * @return array<int,array{alias:string,column:string}>
     */
    private function sqlColumnReferences(string $sql): array
    {
        $refs = [];
        $count = preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\s*\.\s*\[?([A-Za-z_][A-Za-z0-9_]*)\]?/', $sql, $matches, PREG_SET_ORDER);
        if ($count === false || $count === 0) {
            return [];
        }

        foreach ($matches as $match) {
            $refs[] = [
                'alias' => $match[1],
                'column' => $match[2],
            ];
        }

        return $refs;
    }

    private function referenceAllowed(string $table, string $column): bool
    {
        $deny = [
            'destinations.m' => true,
            'dolugunler.date' => true,
        ];

        return $this->identifierAllowed($table)
            && $this->columnAllowed($column)
            && !isset($deny[strtolower($table . '.' . $column)]);
    }

    private function identifierAllowed(string $identifier): bool
    {
        if (strpos($identifier, '{') !== false || strpos($identifier, '(') !== false) {
            return false;
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/', $identifier) !== 1) {
            return false;
        }

        $lower = strtolower($identifier);
        return strpos($lower, 'sys.') !== 0 && strpos($lower, 'information_schema.') !== 0;
    }

    private function columnAllowed(string $column): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) === 1;
    }

    private function cleanIdentifier(string $value): string
    {
        return str_replace(['[', ']'], '', trim($value));
    }

    private function unqualifiedTable(string $table): string
    {
        $parts = explode('.', $table);

        return $parts[count($parts) - 1];
    }

    private function compactSql(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        return (string) $sql;
    }
}
