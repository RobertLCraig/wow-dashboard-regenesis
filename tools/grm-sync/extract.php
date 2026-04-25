<?php

/**
 * GRM SavedVariables → JSON CLI.
 *
 * Reads the user's Guild_Roster_Manager.lua, parses the four globals the
 * dashboard cares about, and prints a single JSON envelope to stdout.
 * Intended to be invoked by tools/grm-sync/grm-sync.ps1.
 *
 * Usage:
 *   php tools/grm-sync/extract.php <path-to-Guild_Roster_Manager.lua> [guild_key]
 *
 * Exit codes:
 *   0 - success, JSON written to stdout
 *   1 - bad arguments
 *   2 - file not found / unreadable
 *   3 - parse error (full message on stderr)
 *
 * Requires Composer's autoloader so the App\Services\Grm\LuaTableParser
 * class is available. Sole shared dependency between server + PC.
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php extract.php <path-to-savedvariables.lua> [guild_key]\n");
    exit(1);
}

$path = $argv[1];
$guildKey = $argv[2] ?? null;

if (! is_readable($path)) {
    fwrite(STDERR, "Cannot read file: $path\n");
    exit(2);
}

require __DIR__ . '/../../vendor/autoload.php';

try {
    $parser = new App\Services\Grm\LuaTableParser;
    $payload = $parser->parseFile($path, [
        'GRM_GuildMemberHistory_Save',
        'GRM_PlayersThatLeftHistory_Save',
        'GRM_LogReport_Save',
        'GRM_Alts',
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "Parse failed: " . $e->getMessage() . "\n");
    exit(3);
}

$guildKey = $guildKey ?? array_key_first($payload['GRM_GuildMemberHistory_Save'] ?? []) ?? 'unknown';

$envelope = [
    'guild_key' => $guildKey,
    'captured_at' => gmdate('c'),
    'source' => 'grm',
    'grm_version' => null,
    'source_file' => basename($path),
    'source_size_bytes' => filesize($path),
    'payload' => $payload,
];

// JSON_UNESCAPED_SLASHES keeps ics_uid / log paths readable in storage.
// JSON_UNESCAPED_UNICODE preserves non-ASCII player names verbatim.
echo json_encode(
    $envelope,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
);
