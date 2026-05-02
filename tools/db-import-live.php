<?php
declare(strict_types=1);

/**
 * Copy tables from a remote MySQL database (accessed via an SSH tunnel
 * on localhost:3307 by default) into the local SQLite database.
 *
 * PDO handles all character encoding and quoting transparently, so there
 * are no manual escape transforms and no dump-file encoding edge cases.
 *
 * Called by db-pull.ps1 after the SSH tunnel is established and after
 * php artisan migrate:fresh --force has wiped the local schema.
 *
 * Usage:
 *   php tools/db-import-live.php \
 *     --db=dbname --user=root --pass=secret \
 *     [--host=127.0.0.1] [--port=3307] \
 *     [--exclude=cache,jobs,sessions,migrations]
 */

$opts = getopt('', ['host:', 'port:', 'db:', 'user:', 'pass:', 'exclude:']);

$host    = $opts['host']    ?? '127.0.0.1';
$port    = (int) ($opts['port'] ?? 3307);
$dbName  = $opts['db']      ?? '';
$user    = $opts['user']    ?? '';
$pass    = $opts['pass']    ?? '';
$exclude = isset($opts['exclude'])
    ? array_map('trim', explode(',', $opts['exclude']))
    : [];

if (! $dbName || ! $user) {
    fwrite(STDERR, "Usage: php tools/db-import-live.php --db=NAME --user=USER [--pass=PASS] [--host=HOST] [--port=PORT] [--exclude=t1,t2]\n");
    exit(1);
}

// ── Connect to MySQL via SSH tunnel ──────────────────────────────────
$dsn = "mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4";
try {
    $mysql = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false, // stream large result sets
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Cannot connect to MySQL: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Is the SSH tunnel running? (ssh -N -L $port:127.0.0.1:3306 ...)\n");
    exit(1);
}

// ── Connect to local SQLite ───────────────────────────────────────────
$dbPath = dirname(__DIR__) . '/database/database.sqlite';
if (! file_exists($dbPath)) {
    fwrite(STDERR, "SQLite DB not found at: $dbPath\n");
    fwrite(STDERR, "Run: php artisan migrate:fresh --force\n");
    exit(1);
}

$sqlite = new PDO("sqlite:$dbPath", '', '', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
]);
$sqlite->exec('PRAGMA journal_mode = WAL');
$sqlite->exec('PRAGMA synchronous = NORMAL');
$sqlite->exec('PRAGMA cache_size = -32000');
$sqlite->exec('PRAGMA foreign_keys = OFF');

// ── Get table list from MySQL ─────────────────────────────────────────
$tables = $mysql->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
sort($tables);

$totalRows   = 0;
$totalErrors = 0;
$chunkSize   = 500;

foreach ($tables as $table) {
    if (in_array($table, $exclude, true)) {
        echo "  skip  $table\n";
        continue;
    }

    // Column names from MySQL (authoritative for column order).
    $cols = $mysql->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    $colList      = implode(', ', array_map(fn ($c) => "`$c`", $cols));
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));

    $total = (int) $mysql->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    if ($total === 0) {
        echo "  empty $table\n";
        continue;
    }

    $insertSql  = "INSERT INTO `$table` ($colList) VALUES ($placeholders)";
    $insertStmt = $sqlite->prepare($insertSql);

    $inserted   = 0;
    $errors     = 0;
    $offset     = 0;

    $sqlite->beginTransaction();

    while ($offset < $total) {
        $rows = $mysql
            ->query("SELECT $colList FROM `$table` LIMIT $chunkSize OFFSET $offset")
            ->fetchAll(PDO::FETCH_NUM);

        if (empty($rows)) {
            break;
        }

        foreach ($rows as $row) {
            try {
                $insertStmt->execute($row);
                $inserted++;
            } catch (PDOException $e) {
                $errors++;
                if ($errors <= 3) {
                    echo "\n  ERR  $table row $inserted: " . $e->getMessage() . "\n";
                }
            }
        }

        $offset += $chunkSize;
        printf("  %-40s %d/%d\r", $table, $inserted, $total);
    }

    $sqlite->commit();
    printf("  %-40s %d rows%s\n", $table, $inserted, $errors ? " ($errors errors)" : '');

    $totalRows   += $inserted;
    $totalErrors += $errors;
}

$sqlite->exec('PRAGMA foreign_keys = ON');

echo "\n";
echo "Total: $totalRows rows. Errors: $totalErrors.\n";

exit($totalErrors > 0 ? 1 : 0);
