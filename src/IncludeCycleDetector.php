<?php

declare(strict_types=1);

namespace SmartyLint;

use SmartyAst\Ast\Node;
use SmartyAst\Ast\TagLike;
use SmartyAst\Ast\TagNode;
use SmartyAst\ParseResult;
use SmartyLint\AstWalkerHelpers;

final class IncludeCycleDetector
{
    private IncludeParser $includeParser;

    /** @var array<string,bool> */
    private array $reportedCycles = [];
    /** @var array<string,bool> */
    private array $dependencies = [];

    public function __construct(IncludeParser $includeParser)
    {
        $this->includeParser = $includeParser;
    }

    public function detect(string $rootPath, ParseResult $rootResult, IssueCollector $issues): void
    {
        $this->reportedCycles = [];
        $this->dependencies = [];

        $root = realpath($rootPath) ?: $rootPath;
        $stack = [$root];

        $this->dfs($root, $rootResult, $stack, $issues);
    }

    /** @return list<string> */
    public function getDependencies(): array
    {
        return array_keys($this->dependencies);
    }

    /** @param list<string> $stack */
    private function dfs(string $sourcePath, ParseResult $result, array &$stack, IssueCollector $issues): void
    {
        foreach ($this->extractIncludeEdges($sourcePath, $result->ast) as $edge) {
            $targetPath = $edge['target'];
            $this->dependencies[$targetPath] = true;

            $existingIndex = array_search($targetPath, $stack, true);
            if ($existingIndex !== false) {
                $cycle = array_slice($stack, $existingIndex);
                $cycle[] = $targetPath;
                $signature = implode('->', $cycle);

                if (!isset($this->reportedCycles[$signature])) {
                    $this->reportedCycles[$signature] = true;
                    $issues->add(
                        $sourcePath,
                        $edge['line'],
                        $edge['col'],
                        'ERROR',
                        'Include cycle detected: ' . implode(' -> ', $cycle),
                    );
                }

                continue;
            }

            $targetResult = $this->includeParser->parse($targetPath);
            if ($targetResult === null) {
                continue;
            }

            $stack[] = $targetPath;
            $this->dfs($targetPath, $targetResult, $stack, $issues);
            array_pop($stack);
        }
    }

    /**
     * @return list<array{target:string,line:int,col:int}>
     */
    private function extractIncludeEdges(string $sourcePath, Node $root): array
    {
        $edges = [];
        $this->collectIncludeEdges($root, dirname($sourcePath), $edges);
        return $edges;
    }

    /**
     * @param list<array{target:string,line:int,col:int}> $edges
     */
    private function collectIncludeEdges(Node $node, string $baseDir, array &$edges): void
    {
        $tag = $node instanceof TagLike ? $node->resolveTag() : null;
        if ($tag !== null && in_array(strtolower($tag->name), ['include', 'extends'], true)) {
            $path = $this->extractIncludePath($tag, $baseDir);
            if ($path !== null) {
                $edges[] = [
                    'target' => $path,
                    'line' => $tag->span->start->line,
                    'col' => $tag->span->start->column,
                ];
            }
        }

        foreach (AstWalkerHelpers::childNodes($node) as $child) {
            $this->collectIncludeEdges($child, $baseDir, $edges);
        }
    }

    private function extractIncludePath(TagNode $tag, string $baseDir): ?string
    {
        $argNode = AstWalkerHelpers::fileArgNode($tag);
        $literal = $argNode !== null ? AstWalkerHelpers::stringLiteral($argNode->value) : null;
        return $literal !== null ? $this->includeParser->resolve($literal, $baseDir) : null;
    }
}
