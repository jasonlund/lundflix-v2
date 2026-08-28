<?php

declare(strict_types=1);

namespace App\Domains\Local\Console\Commands;

use App\Domains\Local\Support\WorkspaceName;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

#[Description('Derive a workspace\'s Herd site, database name, and app URL from its directory slug and write them into an env file')]
#[Signature('lf:workspace-env {workspace} {project} {--file=}')]
final class WriteWorkspaceEnv extends Command
{
    public function handle(): int
    {
        $branch = WorkspaceName::branch((string) $this->argument('workspace'), (string) $this->argument('project'));
        $site = WorkspaceName::site($branch);

        $path = $this->path();
        $contents = File::exists($path) ? File::get($path) : '';

        $this->output->writeln("Writing workspace env to {$path}…");

        $values = [
            'LF_SITE' => $site,
            'DB_DATABASE' => WorkspaceName::database($branch),
            'APP_URL' => "https://{$site}.test",
        ];

        foreach ($values as $key => $value) {
            $contents = $this->replaceOrAppend($contents, $key, $value);
            $this->output->writeln("  [{$key} {$value}]");
        }

        File::put($path, $contents);

        $this->output->writeln('Done.');

        return self::SUCCESS;
    }

    private function path(): string
    {
        $file = $this->option('file');

        return is_string($file) && $file !== '' ? $file : base_path('.env');
    }

    /**
     * Rewrite in place rather than append, so a second run against the same file
     * can't leave two definitions of $key behind.
     */
    private function replaceOrAppend(string $contents, string $key, string $value): string
    {
        $line = "{$key}={$value}";

        // Anchored to the line start, so neither a commented `#KEY=` nor a longer
        // key this one prefixes (DB_DATABASE vs DB_DATABASE_URL) is a match.
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $contents) === 1) {
            // A callback, not a replacement string: `$`/`\` in a derived value
            // would otherwise be read as backreferences.
            return (string) preg_replace_callback($pattern, static fn (): string => $line, $contents, 1);
        }

        $prefix = $contents === '' || Str::endsWith($contents, "\n") ? $contents : $contents."\n";

        return $prefix.$line."\n";
    }
}
