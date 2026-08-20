<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Str;

/**
 * Static scan of a Pest test file's SOURCE STRING, used by the guard that
 * enforces the suite's test-organization standard.
 */
final class TestOrganizationScanner
{
    /**
     * Constructs that only count at column 0 — indenting one puts it inside
     * some other construct's body, where it is no longer the file's own.
     *
     * @var array<string, non-empty-string>
     */
    private const array FILE_LEVEL_PATTERNS = [
        'declare' => '/^declare\s*\(/',
        'import' => '/^use\s+[A-Za-z_\\\\]/',
        'banner' => '#^/\*#',
        'function' => '/^function\s+\w+\s*\(/',
    ];

    /**
     * Pest calls, matched at any indentation because describe() nests them.
     *
     * @var array<string, non-empty-string>
     */
    private const array NESTABLE_PATTERNS = [
        'uses' => '/^uses\s*\(/',
        'beforeEach' => '/^beforeEach\s*\(/',
        'describe' => '/^describe\s*\(/',
        'it' => '/^(?:it|test)\s*\(/',
    ];

    /**
     * The canonical order of the file-level skeleton. Kinds absent here
     * ('banner', 'it') take no part in the ordering.
     *
     * @var array<string, int>
     */
    private const array SKELETON_RANKS = [
        'declare' => 1,
        'import' => 2,
        'uses' => 3,
        'function' => 4,
        'beforeEach' => 5,
        'describe' => 6,
    ];

    /**
     * The file's constructs in source order.
     *
     * @return list<array{kind: string, line: int, label: ?string, nested: bool}>
     */
    public static function outline(string $source): array
    {
        $outline = [];
        $heredoc = null;

        foreach (preg_split('/\R/', $source) ?: [] as $index => $line) {
            $body = Str::ltrim($line);

            // A heredoc/nowdoc body is quoted data, not code: sample sources
            // embedded in a test would otherwise be scanned as the file's own
            // constructs, and a flush-left body reads as top-level.
            if ($heredoc !== null) {
                if (self::closesHeredoc($body, $heredoc)) {
                    $heredoc = null;
                }

                continue;
            }

            // Indentation is the only nesting signal available to a source-string
            // scan: a construct written flush left belongs to the file itself.
            $nested = $body !== '' && $body !== $line;

            $kind = self::classify($body, $nested);

            if ($kind !== null) {
                $outline[] = [
                    'kind' => $kind,
                    'line' => $index + 1,
                    'label' => self::label($body, $kind),
                    'nested' => $nested,
                ];
            }

            $heredoc = self::opensHeredoc($body);
        }

        return $outline;
    }

    /**
     * The 1-indexed lines of it()/test() calls declared outside a describe().
     *
     * @return list<int>
     */
    public static function ungroupedTests(string $source): array
    {
        $lines = [];

        foreach (self::outline($source) as $construct) {
            if ($construct['kind'] === 'it' && ! $construct['nested']) {
                $lines[] = $construct['line'];
            }
        }

        return $lines;
    }

    /**
     * The top-level constructs that appear out of canonical skeleton order.
     *
     * A 'banner' carries no rank: the real suite ships both imports -> uses()
     * -> banner and imports -> banner -> uses(), so it never offends and never
     * raises the high-water mark for what follows it.
     *
     * @return list<array{line: int, kind: string}>
     */
    public static function skeletonOffenders(string $source): array
    {
        $offenders = [];
        $highWater = 0;

        foreach (self::outline($source) as $construct) {
            if ($construct['nested']) {
                continue;
            }

            $rank = self::SKELETON_RANKS[$construct['kind']] ?? null;

            if ($rank === null) {
                continue;
            }

            if ($rank < $highWater) {
                $offenders[] = ['line' => $construct['line'], 'kind' => $construct['kind']];

                continue;
            }

            $highWater = $rank;
        }

        return $offenders;
    }

    /**
     * The it()/test() descriptions that break the description-form rule.
     *
     * Duplicates are judged per describe() group — the same wording in two
     * groups reads unambiguously, so only a repeat within one group offends.
     *
     * @return list<array{line: int, description: string}>
     */
    public static function descriptionOffenders(string $source): array
    {
        $offenders = [];
        $seen = [];

        foreach (self::outline($source) as $construct) {
            if ($construct['kind'] === 'describe' && ! $construct['nested']) {
                $seen = [];

                continue;
            }

            if ($construct['kind'] !== 'it') {
                continue;
            }

            $description = $construct['label'];

            if ($description === null) {
                continue;
            }

            if (self::offendsDescriptionForm($description, $seen)) {
                $offenders[] = ['line' => $construct['line'], 'description' => $description];
            }

            $seen[] = $description;
        }

        return $offenders;
    }

    /**
     * The repeat occurrences of a describe() label reused within one file.
     *
     * @return list<array{line: int, label: string}>
     */
    public static function duplicateDescribeLabels(string $source): array
    {
        $duplicates = [];
        $seen = [];

        foreach (self::outline($source) as $construct) {
            $label = $construct['label'];

            if ($construct['kind'] !== 'describe' || $label === null) {
                continue;
            }

            if (in_array($label, $seen, true)) {
                $duplicates[] = ['line' => $construct['line'], 'label' => $label];

                continue;
            }

            $seen[] = $label;
        }

        return $duplicates;
    }

    /**
     * The file-level function declarations, in source order.
     *
     * @return list<array{line: int, name: string}>
     */
    public static function helperDeclarations(string $source): array
    {
        $helpers = [];

        foreach (self::outline($source) as $construct) {
            $name = $construct['label'];

            if ($construct['kind'] !== 'function' || $construct['nested'] || $name === null) {
                continue;
            }

            $helpers[] = ['line' => $construct['line'], 'name' => $name];
        }

        return $helpers;
    }

    /**
     * A leading run of two or more capitals is an acronym the prose owes its
     * casing to ('GETs /updates…', 'TMDB ids…'), not a sentence-cased opener,
     * so only a lone capital breaks the lowercase-start rule.
     *
     * @param  list<string>  $seen  descriptions already used in this group
     */
    private static function offendsDescriptionForm(string $description, array $seen): bool
    {
        return preg_match('/^\p{Lu}(?!\p{Lu})/u', $description) === 1
            || Str::startsWith($description, 'should')
            || in_array($description, $seen, true);
    }

    /**
     * The identifier of the heredoc/nowdoc a line opens, or null when it opens
     * none. Both the quoted (nowdoc) and bare (heredoc) forms count; PHP allows
     * nothing but whitespace after the opener, which keeps `<<` shifts out.
     */
    private static function opensHeredoc(string $body): ?string
    {
        return preg_match('/<<<[ \t]*([\'"]?)([A-Za-z_]\w*)\1\s*$/', $body, $matches) === 1
            ? $matches[2]
            : null;
    }

    /**
     * Whether a line is the closing identifier of the open heredoc/nowdoc. The
     * body is already left-trimmed, which absorbs the indented closer PHP 7.3+
     * allows.
     */
    private static function closesHeredoc(string $body, string $identifier): bool
    {
        return preg_match('/^'.preg_quote($identifier, '/').'\b/', $body) === 1;
    }

    /**
     * The construct a single line opens, or null when it opens none.
     */
    private static function classify(string $body, bool $nested): ?string
    {
        $patterns = $nested
            ? self::NESTABLE_PATTERNS
            : [...self::FILE_LEVEL_PATTERNS, ...self::NESTABLE_PATTERNS];

        foreach ($patterns as $kind => $pattern) {
            if (preg_match($pattern, $body) === 1) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * A construct's label: a declaration's own name, or a call's string-literal
     * first argument.
     */
    private static function label(string $body, string $kind): ?string
    {
        if ($kind === 'function') {
            return preg_match('/^function\s+(\w+)/', $body, $matches) === 1
                ? $matches[1]
                : null;
        }

        if (preg_match('/^\w+\s*\(\s*([\'"])(.*?)\1/', $body, $matches) === 1) {
            return $matches[2];
        }

        return null;
    }
}
