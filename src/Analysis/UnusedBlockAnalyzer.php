<?php

declare(strict_types=1);

namespace SmartyLint\Analysis;

use SmartyLint\Issue;

/**
 * Detects {block} definitions in parent templates that are never overridden
 * by any child template that extends the parent.
 */
final class UnusedBlockAnalyzer
{
    /** @return list<Issue> */
    public function analyze(TemplateGraph $graph): array
    {
        // Build a map: parent → list of children that directly extend it.
        /** @var array<string, list<string>> */
        $children = [];
        foreach ($graph->extends as $childPath => $parentPath) {
            $children[$parentPath][] = $childPath;
        }

        $issues = [];

        foreach ($graph->blockDefinitions as $parentPath => $definitions) {
            if ($definitions === []) {
                continue;
            }

            $directChildren = $children[$parentPath] ?? [];
            if ($directChildren === []) {
                // No child extends this parent — every block is unoverridden.
                foreach ($definitions as $def) {
                    $issues[] = $this->makeIssue($parentPath, $def);
                }
                continue;
            }

            // Collect all block names overridden by any direct child.
            $overriddenNames = [];
            foreach ($directChildren as $childPath) {
                foreach ($graph->blockOverrides[$childPath] ?? [] as $name) {
                    $overriddenNames[$name] = true;
                }
            }

            foreach ($definitions as $def) {
                if (!isset($overriddenNames[$def['name']])) {
                    $issues[] = $this->makeIssue($parentPath, $def);
                }
            }
        }

        return $issues;
    }

    /** @param array{name:string,line:int,col:int} $def */
    private function makeIssue(string $path, array $def): Issue
    {
        return new Issue(
            $path,
            $def['line'],
            $def['col'],
            'WARNING',
            "Block '{$def['name']}' is defined but never overridden by any extending template.",
        );
    }
}
