<?php
/**
 * SchemaAudit - validates every column referenced by the module's SQL against the real
 * OpenEMR schema in sql/database.sql.
 *
 * Two fabricated-column bugs shipped undetected because the query errors were swallowed:
 *   history_data.exercise / .diet / .medical_history / .surgical_history
 *   procedure_order.procedure_name, procedure_result.result_name, and a JOIN on
 *   procedure_result.procedure_order_id (that column does not exist; the real chain is
 *   procedure_order -> procedure_report -> procedure_result).
 *
 * This audit parses CREATE TABLE out of database.sql and reports any backticked identifier
 * used in a query that is not a real column of the table it is attributed to. Run from the
 * module root:  php tests/SchemaAudit.php
 *
 * Compatibility: OpenEMR 8.2.0 and 8.4.1.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

// Point at whichever OpenEMR source tree is present.
$candidates = [
    'D:/docs/luis/Documentos/sitios/openemr/desarrollo/openemr/sql/database.sql',
    'D:/docs/luis/Documentos/sitios/openemr/desarrollo/openemr-8.2.0/sql/database.sql',
    'D:/docs/luis/Documentos/sitios/openemr/desarrollo/openemr-8.4.1/sql/database.sql',
];

$schemaFile = null;
foreach ($candidates as $c) {
    if (is_file($c)) {
        $schemaFile = $c;
        break;
    }
}

// Fall back to anything alongside the module.
if ($schemaFile === null) {
    foreach (glob(dirname($root, 1) . '/*/sql/database.sql') ?: [] as $c) {
        $schemaFile = $c;
        break;
    }
}

if ($schemaFile === null) {
    fwrite(STDERR, "No se encontro sql/database.sql de OpenEMR. Ajusta \$candidates.\n");
    exit(2);
}

$db = file_get_contents($schemaFile);
if ($db === false) {
    fwrite(STDERR, "No se pudo leer $schemaFile\n");
    exit(2);
}

// table => set(columns)
// PREG_SET_ORDER makes each element a full match array whose groups are [0], [1], [2].
$schema = [];
if (preg_match_all('/CREATE TABLE `(\w+)` \((.*?)\n\)/s', $db, $m, PREG_SET_ORDER)) {
    foreach ($m as $t) {
        $cols = [];
        if (preg_match_all('/^\s*`(\w+)`/m', $t[2], $c)) {
            foreach ($c[1] as $col) {
                $cols[$col] = true;
            }
        }
        $schema[$t[1]] = $cols;
    }
}

echo "Esquema leido de: $schemaFile\n";
echo "Tablas en esquema: " . count($schema) . "\n\n";

// Collect every SQL string literal in the module, then attribute each backticked
// identifier to the table it is qualified by, or to the FROM/JOIN targets of its query.
$files = [];
foreach (['src', 'public'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $files[] = $f->getPathname();
        }
    }
}

$keywords = [
    'SELECT', 'FROM', 'WHERE', 'AND', 'OR', 'JOIN', 'LEFT', 'RIGHT', 'INNER', 'OUTER',
    'ON', 'ORDER', 'BY', 'DESC', 'ASC', 'LIMIT', 'GROUP', 'HAVING', 'AS', 'NOT', 'NULL',
    'INSERT', 'INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE', 'COUNT', 'SUM', 'DISTINCT',
];

$problems = [];

foreach ($files as $file) {
    $code = file_get_contents($file);

    // Strip comments first. Docblocks legitimately mention column names when documenting
    // a schema bug, and scanning those produces phantom findings.
    $code = preg_replace('#/\*.*?\*/#s', '', $code);
    $code = preg_replace('#^\s*//.*$#m', '', $code);
    $code = preg_replace('#^\s*\*.*$#m', '', $code);

    // Each match is [full, doubleQuoted, singleQuoted].
    $matches = [];
    if (preg_match_all('/"([^"]*\bSELECT\b[^"]*)"|\'([^\']*\bSELECT\b[^\']*)\'/is', $code, $raw, PREG_SET_ORDER)) {
        $matches = $raw;
    }

    foreach ($matches as $q) {
        $sql = ($q[1] ?? '') !== '' ? $q[1] : ($q[2] ?? '');
        $norm = preg_replace('/\s+/', ' ', $sql);

        // Tables referenced in this query, mapped alias => table. The alias is only taken when
        // one is actually written; otherwise the table name is its own alias.
        $aliases = [];   // alias => table
        if (preg_match_all('/(?:FROM|JOIN)\s+`(\w+)`(?:\s+AS\s+`(\w+)`|\s+`(\w+)`)?/i', $norm, $joins, PREG_SET_ORDER)) {
            foreach ($joins as $j) {
                $table = $j[1];
                $alias = ($j[2] ?? '') !== '' ? $j[2] : (($j[3] ?? '') !== '' ? $j[3] : $table);
                $aliases[$alias] = $table;
            }
        }

        // Bare table name followed by a dot (e.g. "FROM history_data WHERE...") is the table
        // itself, not a column reference. Drop those before scanning identifiers.
        $identScan = $norm;
        foreach (array_unique(array_values($aliases)) as $table) {
            $identScan = preg_replace(
                '/(?:FROM|JOIN)\s+`?' . preg_quote($table, '/') . '`?\s+(?!AS)/i',
                'FROM_TABLE ',
                $identScan
            );
        }

        // Per-alias column references: `alias`.`column`
        foreach ($aliases as $alias => $table) {
            $pattern = '/`?' . preg_quote($alias, '/') . '?`?\.`(\w+)`/i';
            if (preg_match_all($pattern, $norm, $cols)) {
                foreach ($cols[1] as $col) {
                    if (!isset($schema[$table])) {
                        continue;
                    }
                    if (!isset($schema[$table][$col])) {
                        $line = substr_count(substr($code, 0, strpos($code, $sql)), "\n") + 1;
                        $problems[] = sprintf(
                            '%s:%d  %s.%s no existe',
                            str_replace($root . '/', '', $file),
                            $line,
                            $table,
                            $col
                        );
                    }
                }
            }
        }

        // Unqualified columns: only check against single-table queries, where attribution
        // is unambiguous. Multi-table queries would produce false positives.
        if (count($aliases) === 1) {
            $table = reset($aliases);
            $stripped = preg_replace('/`?\w+`?\.\s*`\w+`/', ' ', $identScan);
            foreach (preg_split('/\b(?:SELECT|FROM|WHERE|AND|OR|ORDER BY|GROUP BY|LIMIT)\b/i', $stripped) as $chunk) {
                if (preg_match_all('/`(\w+)`/', $chunk, $cols)) {
                    foreach ($cols[1] as $col) {
                        if (in_array(strtoupper($col), $keywords, true)) {
                            continue;
                        }
                        if (isset($schema[$table]) && !isset($schema[$table][$col])) {
                            $line = substr_count(substr($code, 0, strpos($code, $sql)), "\n") + 1;
                            $problems[] = sprintf(
                                '%s:%d  %s.%s no existe (sin calificar)',
                                str_replace($root . '/', '', $file),
                                $line,
                                $table,
                                $col
                            );
                        }
                    }
                }
            }
        }
    }
}

$problems = array_values(array_unique($problems));

if ($problems === []) {
    echo "OK: ninguna referencia a columna inexistente.\n";
    exit(0);
}

echo "COLUMNAS INEXISTENTES ENCONTRADAS (" . count($problems) . "):\n";
foreach ($problems as $p) {
    echo "  $p\n";
}
exit(1);