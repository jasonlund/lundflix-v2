<?php

declare(strict_types=1);

use App\Domains\Common\Console\Concerns\EmitsHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
|--------------------------------------------------------------------------
| EmitsHeartbeat trait — boundary arithmetic pinned off any real command
|--------------------------------------------------------------------------
| The trait is consumed by long-running sync commands whose beats are
| otherwise only observable through a full ingest Feature run. This file
| pins the arithmetic directly on a throwaway Command host that `use`s the
| trait and re-exposes its protected methods, wired to a BufferedOutput so
| emitted lines can be read back verbatim.
|
| Both the host and OutputStyle construct without the container, so this
| stays a plain Unit test with no TestCase — nothing here touches the app.
|
| Assertions compare the buffer's FULL line list, never containment: an
| implementation that beats only on `$total % $interval === 0` satisfies a
| containment check while silently dropping every boundary a multi-boundary
| jump skips, which is the normal case for the batch consumers.
*/

/**
 * Throwaway host exposing the trait's protected API to the tests.
 */
final class EmitsHeartbeatTestHost extends Command
{
    use EmitsHeartbeat;

    public function publicMark(string $tag, int $total, ?string $suffix = null): void
    {
        $this->mark($tag, $total, $suffix);
    }

    public function publicBeat(string $tag, int $total, int $interval, ?string $suffix = null): void
    {
        $this->beat($tag, $total, $interval, $suffix);
    }

    public function publicFlushTotal(string $tag, int $total): void
    {
        $this->flushTotal($tag, $total);
    }

    public function publicFailureSummary(int $count, string $noun, string $consequence): void
    {
        $this->failureSummary($count, $noun, $consequence);
    }
}

/**
 * A fresh host bound to its own buffer, so no test inherits another's
 * per-tag boundary state.
 *
 * @return array{0: EmitsHeartbeatTestHost, 1: BufferedOutput}
 */
function emitsHeartbeatHost(): array
{
    $buffer = new BufferedOutput;
    $host = new EmitsHeartbeatTestHost;
    $host->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    return [$host, $buffer];
}

/**
 * The emitted lines, with the trailing empty element from the final newline
 * dropped.
 *
 * @return list<string>
 */
function emittedLines(BufferedOutput $buffer): array
{
    $fetched = $buffer->fetch();

    if ($fetched === '') {
        return [];
    }

    return explode("\n", Str::rtrim($fetched, "\n"));
}

it('emits one indented heartbeat when the total crosses a boundary', function (): void {
    // Arrange
    [$host, $buffer] = emitsHeartbeatHost();

    // Act
    $host->publicBeat('tvdb episodes', 100, 100);

    // Assert
    expect(emittedLines($buffer))->toBe(['  [tvdb episodes 100]']);
});

it('emits one heartbeat per boundary when a single call jumps several', function (): void {
    // Arrange
    [$host, $buffer] = emitsHeartbeatHost();

    // 0 → 3000 in one call crosses three 1000-boundaries; each must land on its
    // own line, at the clean boundary value, in ascending order
    // Act
    $host->publicBeat('tmdb movies', 3000, 1000);

    // Assert
    expect(emittedLines($buffer))->toBe([
        '  [tmdb movies 1000]',
        '  [tmdb movies 2000]',
        '  [tmdb movies 3000]',
    ]);
});

it('stays silent when the total advances without crossing a new boundary', function (): void {
    // Arrange
    [$host, $buffer] = emitsHeartbeatHost();
    $host->publicBeat('tvdb episodes', 100, 100);

    // Act
    $host->publicBeat('tvdb episodes', 147, 100);

    // Assert
    expect(emittedLines($buffer))->toBe(['  [tvdb episodes 100]']);
});

it('tracks boundary state per tag so interleaved tags never suppress each other', function (): void {
    // Arrange
    [$host, $buffer] = emitsHeartbeatHost();
    $host->publicBeat('movies', 100, 100);
    $host->publicBeat('shows', 100, 100);
    $host->publicBeat('movies', 150, 100);

    // Act
    $host->publicBeat('shows', 200, 100);

    // Assert
    expect(emittedLines($buffer))->toBe([
        '  [movies 100]',
        '  [shows 100]',
        '  [shows 200]',
    ]);
});

it('emits a line on every mark regardless of any interval', function (): void {
    // Arrange
    [$host, $buffer] = emitsHeartbeatHost();
    $host->publicMark('imdb titles', 37);

    // the batch consumers pick their own cadence — neither total sits on a
    // boundary, and both must still print
    // Act
    $host->publicMark('imdb titles', 61);

    // Assert
    expect(emittedLines($buffer))->toBe([
        '  [imdb titles 37]',
        '  [imdb titles 61]',
    ]);
});

it('appends a suffix after the bracket when one is given', function (): void {
    // Arrange
    [$host, $buffer] = emitsHeartbeatHost();

    // Act
    $host->publicMark('tmdb shows', 1000, 'Some Title');

    // Assert
    expect(emittedLines($buffer))->toBe(['  [tmdb shows 1000] Some Title']);
});

it('counts a mark as the tag last mark so a matching final total stays silent', function (): void {
    // Arrange
    [$host, $buffer] = emitsHeartbeatHost();
    $host->publicMark('imdb titles', 61);

    // mark() and flushTotal() must share ONE per-tag map, or the last batch line
    // and the closing total would print the same number twice
    // Act
    $host->publicFlushTotal('imdb titles', 61);

    // Assert
    expect(emittedLines($buffer))->toBe(['  [imdb titles 61]']);
});

it('emits the exact final total after a partial interval', function (): void {
    // Arrange
    [$host, $buffer] = emitsHeartbeatHost();
    $host->publicBeat('tvdb episodes', 100, 100);

    // the run ended 47 past the last boundary — the closing line reports the
    // ragged real number, not the boundary the beats stopped on
    // Act
    $host->publicFlushTotal('tvdb episodes', 147);

    // Assert
    expect(emittedLines($buffer))->toBe([
        '  [tvdb episodes 100]',
        '  [tvdb episodes 147]',
    ]);
});

it('stays silent when the final total repeats the last beat', function (): void {
    // Arrange
    [$host, $buffer] = emitsHeartbeatHost();
    $host->publicBeat('tvdb episodes', 100, 100);

    // Act
    $host->publicFlushTotal('tvdb episodes', 100);

    // Assert
    expect(emittedLines($buffer))->toBe(['  [tvdb episodes 100]']);
});

it('reports a zero total for a tag that never beat', function (): void {
    // Arrange
    [$host, $buffer] = emitsHeartbeatHost();

    // a run that processed nothing must still say so — reading an untouched
    // tag's last mark as 0 would suppress this line and leave the operator with
    // a bare prompt, the console silence this heartbeat exists to end
    // Act
    $host->publicFlushTotal('tvdb episodes', 0);

    // Assert
    expect(emittedLines($buffer))->toBe(['  [tvdb episodes 0]']);
});

it('names the failed count and its consequence on an unindented line', function (): void {
    // Arrange
    [$host, $buffer] = emitsHeartbeatHost();

    // Act
    $host->publicFailureSummary(3, 'shows', 'marker not advanced');

    // the summary sits deliberately outside the `  [tag value]` vocabulary —
    // indented, an operator reads it as one more heartbeat count, so the full
    // line list pins the absence of the indent as much as the text
    // Assert
    expect(emittedLines($buffer))->toBe(['3 shows failed; marker not advanced.']);
});

it('stays silent when nothing failed', function (): void {
    // Arrange
    [$host, $buffer] = emitsHeartbeatHost();

    // Act
    $host->publicFailureSummary(0, 'shows', 'marker not advanced');

    // Assert
    expect(emittedLines($buffer))->toBe([]);
});
