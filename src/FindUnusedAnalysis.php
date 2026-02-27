<?php

declare(strict_types=1);

namespace SmartyLint;

use SmartyLint\Analysis\DeadParameterAnalyzer;
use SmartyLint\Analysis\TemplateGraph;
use SmartyLint\Analysis\UnusedBlockAnalyzer;
use SmartyLint\Analysis\UnusedFunctionAnalyzer;

/**
 * Project-wide unused-code analysis (dead params, unused blocks, unused functions).
 * Builds a TemplateGraph from the full file set, then runs all three analyzers.
 */
final class FindUnusedAnalysis
{
    private IncludeParser $includeParser;
    private DeadParameterAnalyzer $deadParams;
    private UnusedBlockAnalyzer $unusedBlocks;
    private UnusedFunctionAnalyzer $unusedFunctions;

    public function __construct(?IncludeParser $includeParser = null)
    {
        $parser = new \SmartyAst\Parser\SmartyParser();
        $this->includeParser = $includeParser ?? new IncludeParser($parser);
        $this->deadParams = new DeadParameterAnalyzer();
        $this->unusedBlocks = new UnusedBlockAnalyzer();
        $this->unusedFunctions = new UnusedFunctionAnalyzer();
    }

    /**
     * @param list<string> $paths Absolute paths to all templates that were linted.
     * @return list<Issue>
     */
    public function analyze(array $paths): array
    {
        // Pre-parse every file through the shared IncludeParser cache.
        foreach ($paths as $path) {
            $this->includeParser->parse($path);
        }

        $graph = TemplateGraph::build($paths, $this->includeParser);

        $issues = array_merge(
            $this->deadParams->analyze($graph),
            $this->unusedBlocks->analyze($graph),
            $this->unusedFunctions->analyze($graph),
        );

        return array_values($issues);
    }
}
