<?php
declare(strict_types=1);

/**
 * Import a mysqldump (data-only, --skip-extended-insert) into the local
 * SQLite database. Called by db-pull.ps1 after migrate:fresh.
 *
 * Processes only INSERT lines; skips all MySQL-specific SET/LOCK/UNLOCK
 * directives. Transforms bit literals (b'0', b'1') to plain integers so
 * they land correctly in SQLite's typeless columns.
 *
 * Usage: php tools/db-import.php <dump.sql>
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/db-import.php <dump.sql>\n");
    exit(1);
}

$dumpFile = $argv[1];

if (! file_exists($dumpFile)) {
    fwrite(STDERR, "File not found: $dumpFile\n");
    exit(1);
}

$dbPath = dirname(__DIR__) . '/database/database.sqlite';

if (! file_exists($dbPath)) {
    fwrite(STDERR, "SQLite DB not found at: $dbPath\n");
    fwrite(STDERR, "Run: php artisan migrate:fresh --force\n");
    exit(1);
}

$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Speed up bulk inserts significantly.
$pdo->exec('PRAGMA journal_mode = WAL');
$pdo->exec('PRAGMA synchronous = NORMAL');
$pdo->exec('PRAGMA cache_size = -32000'); // 32 MB page cache

// Disable FK enforcement during import; constraints are already satisfied
// by the production data. Re-enabled at the end.
$pdo->exec('PRAGMA foreign_keys = OFF');

$handle = fopen($dumpFile, 'r');
if ($handle === false) {
    fwrite(STDERR, "Cannot open: $dumpFile\n");
    exit(1);
}

$inserted = 0;
$errors   = 0;
$batchSize = 500;

$pdo->beginTransaction();

while (($line = fgets($handle)) !== false) {
    // Only process INSERT statements; everything else (SET, LOCK, comments,
    // blank lines) is MySQL bookkeeping that SQLite doesn't need.
    if (! str_starts_with(strtoupper(ltrim($line)), 'INSERT')) {
        continue;
    }

    $stmt = rtrim($line, ";\r\n");

    // MySQL dump escapes every backslash as \\ and uses \' for single
    // quotes, \" for double quotes. SQLite uses '' for single quotes and
    // stores backslashes literally. Steps must run in this order:
    //
    //  1. Protect \\ (escaped backslash) so later passes don't mistake
    //     \\" (escaped-backslash + literal double-quote) for an escaped
    //     double-quote.
    //  2. \' -> '' (SQL single-quote escaping)
    //  3. \" -> "  (MySQL-escaped double-quotes in JSON column values;
    //     \\ pairs are already swapped out so \\" is safely handled)
    //  4. \n \r \t -> real chars (descriptions, notes, etc.)
    //  5. Restore \\ so backslash pairs reach SQLite intact.
    $stmt = str_replace('\\\\', "\x00", $stmt);
    $stmt = str_replace("\\'", "''", $stmt);
    $stmt = str_replace('\\"', '"', $stmt);
    $stmt = str_replace(['\\n', '\\r', '\\t'], ["\n", "\r", "\t"], $stmt);

    // MySQL BIT column literals (b'0', b'1') -> plain integers.
    $stmt = preg_replace("/\\bb'([01]+)'/", '$1', $stmt);

    try {
        $pdo->exec($stmt);
        $inserted++;

        if ($inserted % $batchSize === 0) {
            $pdo->commit();
            $pdo->beginTransaction();
            echo "  $inserted rows...\r";
        }
    } catch (PDOException $e) {
        $errors++;
        $preview = substr($stmt, 0, 160);
        echo "\nERROR on row $inserted: " . $e->getMessage() . "\n";
        echo "  $preview\n";
    }
}

$pdo->commit();
$pdo->exec('PRAGMA foreign_keys = ON');
fclose($handle);

echo "\n";
echo "Imported : $inserted rows\n";

if ($errors > 0) {
    echo "Errors   : $errors\n";
    exit(1);
}

echo "Done.\n";
