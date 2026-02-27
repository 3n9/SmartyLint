<?php

declare(strict_types=1);

namespace SmartyLint\Analysis;

use SmartyLint\Issue;

/**
 * Detects {function name="..."} template functions that are defined but never
 * invoked — either via {call name="..."} or via shorthand {funcName ...} tags.
 */
final class UnusedFunctionAnalyzer
{
    /** @return list<Issue> */
    public function analyze(TemplateGraph $graph): array
    {
        // Collect all call sites across the entire project.
        $calledNames = [];
        foreach ($graph->functionCalls as $calls) {
            foreach ($calls as $name) {
                $calledNames[$name] = true;
            }
        }

        $issues = [];

        foreach ($graph->functionDefinitions as $path => $definitions) {
            foreach ($definitions as $def) {
                if (!isset($calledNames[$def['name']])) {
                    $issues[] = new Issue(
                        $path,
                        $def['line'],
                        $def['col'],
                        'WARNING',
                        "Template function '{$def['name']}' is defined but never called.",
                    );
                }
            }
        }

        return $issues;
    }
}
