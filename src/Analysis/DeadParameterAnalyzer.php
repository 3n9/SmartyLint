<?php

declare(strict_types=1);

namespace SmartyLint\Analysis;

use SmartyLint\Issue;

/**
 * Detects parameters passed to {include} tags that are never referenced
 * inside the included template.
 */
final class DeadParameterAnalyzer
{
    /** @return list<Issue> */
    public function analyze(TemplateGraph $graph): array
    {
        $issues = [];

        foreach ($graph->includes as $callerPath => $edges) {
            foreach ($edges as $edge) {
                $targetPath = $edge['targetPath'];
                $args = $edge['args'];

                if ($args === []) {
                    continue;
                }

                // Only analyse if we have variable-usage data for the target.
                if (!isset($graph->variablesUsed[$targetPath])) {
                    continue;
                }

                $usedInTarget = $graph->variablesUsed[$targetPath];

                foreach ($args as $argName => $_value) {
                    if ($this->isReferenced($argName, $usedInTarget)) {
                        continue;
                    }

                    $shortTarget = basename($targetPath);
                    $issues[] = new Issue(
                        $callerPath,
                        $edge['line'],
                        $edge['col'],
                        'WARNING',
                        "Dead parameter '\${$argName}' passed to '{$shortTarget}' is never referenced in the included template.",
                    );
                }
            }
        }

        return $issues;
    }

    /** @param list<string> $usedPaths */
    private function isReferenced(string $argName, array $usedPaths): bool
    {
        foreach ($usedPaths as $path) {
            // Match exact variable name or as first segment of a property path.
            $root = explode('.', $path)[0];
            if ($root === $argName) {
                return true;
            }
        }

        return false;
    }
}
