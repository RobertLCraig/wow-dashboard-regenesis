<?php

namespace App\Services\Grm;

use RuntimeException;

/**
 * Parses World of Warcraft SavedVariables files (Lua data-only syntax)
 * into nested PHP arrays. Tailored to GRM's output shape, NOT a general
 * Lua interpreter. Handles:
 *
 *  - Top-level `IDENT = { ... }` global assignments
 *  - Tables with mixed integer-positional and ["string"] keys
 *  - String literals (double or single quoted) with WoW's escape set
 *  - Numbers (int/float, optionally negative)
 *  - Booleans, nil
 *  - Nested tables to arbitrary depth
 *  - Trailing commas, optional whitespace, line comments (-- ...)
 *
 * Does NOT handle: long-bracket strings ([[...]]), function values,
 * concatenation, identifiers as keys (e.g. `foo = 1` instead of
 * `["foo"] = 1`), or anything Blizzard's SavedVariables writer never
 * emits. If a real-world file ever trips this parser, add a Pest case
 * before patching - the failure mode should be a hard exception, not
 * silent data loss.
 */
class LuaTableParser
{
    private string $src = '';
    private int $pos = 0;
    private int $len = 0;

    /**
     * Parse a SavedVariables file from disk. Returns
     *   ['GLOBAL_NAME_1' => array, 'GLOBAL_NAME_2' => array, ...]
     * Skips top-level globals not in $only when provided (saves time on
     * the larger payloads where we only care about a subset).
     *
     * @param  list<string>|null  $only
     * @return array<string,mixed>
     */
    public function parseFile(string $path, ?array $only = null): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Could not read $path");
        }
        return $this->parse($contents, $only);
    }

    /**
     * Parse a SavedVariables source string.
     *
     * @param  list<string>|null  $only
     * @return array<string,mixed>
     */
    public function parse(string $source, ?array $only = null): array
    {
        // Strip a UTF-8 BOM if present; Blizzard occasionally emits one.
        if (str_starts_with($source, "\xEF\xBB\xBF")) {
            $source = substr($source, 3);
        }
        $this->src = $source;
        $this->pos = 0;
        $this->len = strlen($source);

        $globals = [];
        $onlySet = $only !== null ? array_flip($only) : null;

        while ($this->pos < $this->len) {
            $this->skipWhitespaceAndComments();
            if ($this->pos >= $this->len) {
                break;
            }

            $name = $this->readIdent();
            $this->skipWhitespaceAndComments();
            $this->expect('=');
            $this->skipWhitespaceAndComments();

            if ($onlySet !== null && ! isset($onlySet[$name])) {
                $this->skipValue();
            } else {
                $globals[$name] = $this->readValue();
            }

            // Optional trailing semicolon between top-level statements.
            $this->skipWhitespaceAndComments();
            if ($this->pos < $this->len && $this->src[$this->pos] === ';') {
                $this->pos++;
            }
        }

        return $globals;
    }

    private function readValue(): mixed
    {
        $this->skipWhitespaceAndComments();
        if ($this->pos >= $this->len) {
            $this->fail('unexpected end of input where value expected');
        }
        $c = $this->src[$this->pos];

        return match (true) {
            $c === '{' => $this->readTable(),
            $c === '"' || $c === "'" => $this->readString(),
            $c === '-' || ($c >= '0' && $c <= '9') => $this->readNumber(),
            $c === 't' || $c === 'f' || $c === 'n' => $this->readKeyword(),
            default => $this->fail("unexpected char '$c' where value expected"),
        };
    }

    private function readTable(): array
    {
        $this->expect('{');
        $out = [];
        // 1-based positional counter (Lua convention; preserved so the
        // numeric ordering of GRM's log/event arrays stays stable).
        $arrayIdx = 1;

        while (true) {
            $this->skipWhitespaceAndComments();
            if ($this->pos >= $this->len) {
                $this->fail('unterminated table');
            }
            if ($this->src[$this->pos] === '}') {
                $this->pos++;
                break;
            }

            $key = null;

            if ($this->src[$this->pos] === '[') {
                $this->pos++;
                $this->skipWhitespaceAndComments();
                $c = $this->src[$this->pos] ?? '';
                if ($c === '"' || $c === "'") {
                    $key = $this->readString();
                } elseif ($c === '-' || ($c >= '0' && $c <= '9')) {
                    $key = $this->readNumber();
                } else {
                    $this->fail("unexpected key char '$c'");
                }
                $this->skipWhitespaceAndComments();
                $this->expect(']');
                $this->skipWhitespaceAndComments();
                $this->expect('=');
                $value = $this->readValue();
            } else {
                // Positional entry: just a value.
                $value = $this->readValue();
                $key = $arrayIdx++;
            }

            $out[$key] = $value;

            $this->skipWhitespaceAndComments();
            if ($this->pos < $this->len && ($this->src[$this->pos] === ',' || $this->src[$this->pos] === ';')) {
                $this->pos++;
            }
        }

        return $out;
    }

    private function readString(): string
    {
        $quote = $this->src[$this->pos];
        $this->pos++;
        $out = '';
        while ($this->pos < $this->len) {
            $c = $this->src[$this->pos];
            if ($c === $quote) {
                $this->pos++;
                return $out;
            }
            if ($c === '\\') {
                $this->pos++;
                if ($this->pos >= $this->len) {
                    $this->fail('unterminated string escape');
                }
                $next = $this->src[$this->pos];
                $out .= match ($next) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    '\\' => '\\',
                    "'" => "'",
                    '"' => '"',
                    '0' => "\0",
                    // Lua \ddd numeric escape (1-3 digits). Used by Blizzard
                    // for non-ASCII player names occasionally.
                    default => ($next >= '0' && $next <= '9')
                        ? $this->readNumericEscape()
                        : $next,
                };
                if (! ($next >= '0' && $next <= '9')) {
                    $this->pos++;
                }
                continue;
            }
            $out .= $c;
            $this->pos++;
        }
        $this->fail('unterminated string literal');
    }

    private function readNumericEscape(): string
    {
        $digits = '';
        for ($i = 0; $i < 3 && $this->pos < $this->len; $i++) {
            $c = $this->src[$this->pos];
            if ($c < '0' || $c > '9') {
                break;
            }
            $digits .= $c;
            $this->pos++;
        }
        return chr((int) $digits);
    }

    private function readNumber(): int|float
    {
        $start = $this->pos;
        if ($this->src[$this->pos] === '-') {
            $this->pos++;
        }
        while ($this->pos < $this->len) {
            $c = $this->src[$this->pos];
            if (($c >= '0' && $c <= '9') || $c === '.' || $c === 'e' || $c === 'E' || $c === '+' || $c === '-') {
                $this->pos++;
            } else {
                break;
            }
        }
        $literal = substr($this->src, $start, $this->pos - $start);
        return str_contains($literal, '.') || str_contains($literal, 'e') || str_contains($literal, 'E')
            ? (float) $literal
            : (int) $literal;
    }

    private function readKeyword(): bool|null
    {
        if (substr($this->src, $this->pos, 4) === 'true') {
            $this->pos += 4;
            return true;
        }
        if (substr($this->src, $this->pos, 5) === 'false') {
            $this->pos += 5;
            return false;
        }
        if (substr($this->src, $this->pos, 3) === 'nil') {
            $this->pos += 3;
            return null;
        }
        $this->fail('unknown keyword at position ' . $this->pos);
    }

    private function readIdent(): string
    {
        $start = $this->pos;
        while ($this->pos < $this->len) {
            $c = $this->src[$this->pos];
            if (($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || ($c >= '0' && $c <= '9') || $c === '_') {
                $this->pos++;
            } else {
                break;
            }
        }
        if ($this->pos === $start) {
            $this->fail('expected identifier');
        }
        return substr($this->src, $start, $this->pos - $start);
    }

    /**
     * Walk past one value without building a PHP representation. Used to
     * skip top-level globals we don't care about (saves CPU on the larger
     * tables). Mirrors readValue's dispatch but discards.
     */
    private function skipValue(): void
    {
        $this->skipWhitespaceAndComments();
        $c = $this->src[$this->pos] ?? '';
        if ($c === '{') {
            $this->skipTable();
        } elseif ($c === '"' || $c === "'") {
            $this->readString();
        } elseif ($c === '-' || ($c >= '0' && $c <= '9')) {
            $this->readNumber();
        } else {
            $this->readKeyword();
        }
    }

    private function skipTable(): void
    {
        $this->expect('{');
        $depth = 1;
        while ($this->pos < $this->len && $depth > 0) {
            $c = $this->src[$this->pos];
            if ($c === '"' || $c === "'") {
                $this->readString();
                continue;
            }
            if ($c === '-' && ($this->src[$this->pos + 1] ?? '') === '-') {
                $this->skipLineComment();
                continue;
            }
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
            }
            $this->pos++;
        }
        if ($depth !== 0) {
            $this->fail('unterminated table while skipping');
        }
    }

    private function skipWhitespaceAndComments(): void
    {
        while ($this->pos < $this->len) {
            $c = $this->src[$this->pos];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $this->pos++;
            } elseif ($c === '-' && ($this->src[$this->pos + 1] ?? '') === '-') {
                $this->skipLineComment();
            } else {
                break;
            }
        }
    }

    private function skipLineComment(): void
    {
        while ($this->pos < $this->len && $this->src[$this->pos] !== "\n") {
            $this->pos++;
        }
    }

    private function expect(string $char): void
    {
        if ($this->pos >= $this->len || $this->src[$this->pos] !== $char) {
            $got = $this->pos < $this->len ? $this->src[$this->pos] : 'EOF';
            $this->fail("expected '$char' but got '$got'");
        }
        $this->pos++;
    }

    private function fail(string $message): never
    {
        $context = substr($this->src, max(0, $this->pos - 40), 80);
        throw new RuntimeException("LuaTableParser: $message (at $this->pos: ...$context...)");
    }
}
