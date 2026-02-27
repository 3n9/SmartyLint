<?php

declare(strict_types=1);

namespace SmartyLint;

/**
 * Reusable library facade around SmartyLint's core analysis pipeline.
 * Intended for integrations (editors/LSP/CI) that need linting without CLI parsing/output.
 */
final class LintEngine
{
    private Linter $linter;
    private FindUnusedAnalysis $findUnusedAnalysis;
    private ?LintCache $cache;

    public function __construct(
        ?Linter $linter = null,
        ?FindUnusedAnalysis $findUnusedAnalysis = null,
        ?LintCache $cache = null,
        ?LintConfig $config = null,
    ) {
        $this->cache = $cache;
        $this->linter = $linter ?? new Linter(null, $cache, $config);
        $this->findUnusedAnalysis = $findUnusedAnalysis ?? new FindUnusedAnalysis();
    }

    /** @return list<Issue> */
    public function lintFile(string $path): array
    {
        return $this->linter->lintFile($path);
    }

    /**
     * @param list<string> $paths
     * @return list<Issue>
     */
    public function lintFiles(array $paths, bool $findUnused = false): array
    {
        $allIssues = [];
        foreach ($paths as $path) {
            array_push($allIssues, ...$this->linter->lintFile($path));
        }

        if ($findUnused) {
            array_push($allIssues, ...$this->findUnusedAnalysis->analyze($paths));
        }

        return array_values($allIssues);
    }

    public function saveCache(): void
    {
        $this->cache?->save();
    }
}
