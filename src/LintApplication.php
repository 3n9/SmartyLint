<?php

declare(strict_types=1);

namespace SmartyLint;

use SmartyLint\Output\CheckstyleFormatter;
use SmartyLint\Output\JsonFormatter;
use SmartyLint\Output\OutputFormatter;
use SmartyLint\Output\SarifFormatter;
use SmartyLint\Output\TextFormatter;

final class LintApplication
{
    public const VERSION = '1.1.0';

    public function run(array $argv): int
    {
        $args = array_slice($argv, 1);

        // ----------------------------------------------------------------
        // Parse CLI flags
        // ----------------------------------------------------------------
        $format = 'text';
        $recursive = false;
        $findUnused = false;
        $errorsOnly = false;
        $paths = [];
        $excludePatterns = [];
        $templateRoot = null;
        $maxScanDepth = null;

        for ($i = 0, $n = count($args); $i < $n; $i++) {
            $arg = $args[$i];

            if ($arg === '--version' || $arg === '-V') {
                echo 'SmartyLint ' . self::VERSION . PHP_EOL;
                return 0;
            } elseif ($arg === '--json') {
                $format = 'json';
            } elseif ($arg === '--format') {
                $format = $args[++$i] ?? 'text';
            } elseif (str_starts_with($arg, '--format=')) {
                $format = substr($arg, 9);
            } elseif ($arg === '--find-unused') {
                $findUnused = true;
            } elseif ($arg === '--recursive' || $arg === '-r') {
                $recursive = true;
            } elseif ($arg === '--errors-only') {
                $errorsOnly = true;
            } elseif ($arg === '--exclude') {
                $excludePatterns[] = $args[++$i] ?? '';
            } elseif (str_starts_with($arg, '--exclude=')) {
                $excludePatterns[] = substr($arg, 10);
            } elseif ($arg === '--template-root') {
                $templateRoot = $args[++$i] ?? null;
            } elseif (str_starts_with($arg, '--template-root=')) {
                $templateRoot = substr($arg, 16);
            } elseif ($arg === '--max-depth') {
                $parsed = $this->parseMaxDepth($args[++$i] ?? null);
                if ($parsed === null) {
                    fwrite(STDERR, "Invalid --max-depth value. Expected a non-negative integer.\n");
                    return 1;
                }
                $maxScanDepth = $parsed;
            } elseif (str_starts_with($arg, '--max-depth=')) {
                $parsed = $this->parseMaxDepth(substr($arg, 12));
                if ($parsed === null) {
                    fwrite(STDERR, "Invalid --max-depth value. Expected a non-negative integer.\n");
                    return 1;
                }
                $maxScanDepth = $parsed;
            } else {
                $paths[] = $arg;
            }
        }

        if ($paths === []) {
            $this->printUsage();
            return 1;
        }

        // ----------------------------------------------------------------
        // Resolve formatter
        // ----------------------------------------------------------------
        $formatter = $this->resolveFormatter($format);
        if ($formatter === null) {
            fwrite(STDERR, "Unknown format '{$format}'. Valid values: text, json, sarif, checkstyle\n");
            return 1;
        }

        // ----------------------------------------------------------------
        // Load config file and apply CLI overrides
        // ----------------------------------------------------------------
        $cwd = getcwd();
        $cachePathBase = is_string($cwd) ? $cwd : __DIR__;
        $configPath = $cachePathBase . '/.smartylintrc.json';
        $config = LintConfig::fromFile($configPath)->withOverrides(
            templateRoot: $templateRoot,
            excludePatterns: $excludePatterns,
            maxScanDepth: $maxScanDepth,
        );

        // ----------------------------------------------------------------
        // Collect files to lint
        // ----------------------------------------------------------------
        $filesToLint = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $filesToLint[] = $path;
            } elseif (is_dir($path)) {
                if ($recursive) {
                    $filesToLint = array_merge($filesToLint, self::findTemplateFiles($path, $config->maxScanDepth));
                } else {
                    fwrite(STDERR, "{$path}: is a directory (use --recursive to scan directories)\n");
                }
            } else {
                fwrite(STDERR, "{$path}: not found\n");
            }
        }

        $filesToLint = array_values(array_unique($filesToLint));

        // Apply --exclude patterns.
        if ($config->excludePatterns !== []) {
            $filesToLint = array_values(array_filter(
                $filesToLint,
                static function (string $file) use ($config): bool {
                    foreach ($config->excludePatterns as $pattern) {
                        if (fnmatch($pattern, $file) || fnmatch($pattern, basename($file))) {
                            return false;
                        }
                    }
                    return true;
                },
            ));
        }

        if ($filesToLint === []) {
            fwrite(STDERR, "No files to lint\n");
            return 1;
        }

        // ----------------------------------------------------------------
        // Run linting
        // ----------------------------------------------------------------
        $cache = new LintCache($cachePathBase . '/.smartylint-cache.json');
        $engine = new LintEngine(null, null, $cache, $config);
        $issues = $engine->lintFiles($filesToLint, $findUnused);
        $engine->saveCache();

        usort($issues, static fn (Issue $a, Issue $b): int => [$a->path, $a->line, $a->col] <=> [$b->path, $b->line, $b->col]);

        // Apply --errors-only filter.
        if ($errorsOnly) {
            $issues = array_values(array_filter($issues, static fn (Issue $i): bool => $i->severity === 'ERROR'));
        }

        echo $formatter->format($issues);

        return $issues === [] ? 0 : 1;
    }

    private function resolveFormatter(string $format): ?OutputFormatter
    {
        return match (strtolower($format)) {
            'text'        => new TextFormatter(),
            'json'        => new JsonFormatter(),
            'sarif'       => new SarifFormatter(self::VERSION),
            'checkstyle'  => new CheckstyleFormatter(),
            default       => null,
        };
    }

    private function printUsage(): void
    {
        fwrite(STDERR, "Usage: smarty-lint [options] <file|directory> [...]\n");
        fwrite(STDERR, "\nOptions:\n");
        fwrite(STDERR, "  --recursive, -r         Recursively scan directories for .tpl files\n");
        fwrite(STDERR, "  --format <fmt>          Output format: text (default), json, sarif, checkstyle\n");
        fwrite(STDERR, "  --json                  Alias for --format json\n");
        fwrite(STDERR, "  --find-unused           Run project-wide unused-code analysis\n");
        fwrite(STDERR, "  --errors-only           Suppress warnings; only report errors\n");
        fwrite(STDERR, "  --exclude <pattern>     Exclude files matching glob pattern (repeatable)\n");
        fwrite(STDERR, "  --template-root <path>  Base directory for resolving template includes\n");
        fwrite(STDERR, "  --max-depth <n>         Maximum recursion depth for --recursive directory scans\n");
        fwrite(STDERR, "  --version, -V           Print version and exit\n");
    }

    /** @return list<string> */
    private static function findTemplateFiles(string $dir, ?int $maxDepth = null): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        if ($maxDepth !== null) {
            $iterator->setMaxDepth($maxDepth);
        }

        foreach ($iterator as $file) {
            if ($file->isFile() && pathinfo($file->getPathname(), PATHINFO_EXTENSION) === 'tpl') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);
        return $files;
    }

    private function parseMaxDepth(mixed $value): ?int
    {
        if (!is_string($value) || $value === '' || preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
