<?php

declare(strict_types=1);

namespace SmartyLint\Analysis;

use SmartyAst\Ast\Node;
use SmartyAst\Ast\PrintNode;
use SmartyAst\Ast\TagNode;
use SmartyAst\ParseResult;
use SmartyLint\AstWalkerHelpers;
use SmartyLint\IncludeParser;

/**
 * Project-wide relationship graph built from a set of already-parsed templates.
 * Reuses the IncludeParser cache so no double-parsing occurs.
 */
final class TemplateGraph
{
    /** @var array<string, list<array{targetPath:string, args:array<string,string|null>, line:int, col:int}>> */
    public array $includes = [];

    /** @var array<string, string> child → parent */
    public array $extends = [];

    /** @var array<string, list<array{name:string, line:int, col:int}>> */
    public array $blockDefinitions = [];

    /** @var array<string, list<string>> child → list of overridden block names */
    public array $blockOverrides = [];

    /** @var array<string, list<array{name:string, line:int, col:int}>> */
    public array $functionDefinitions = [];

    /** @var array<string, list<string>> */
    public array $functionCalls = [];

    /** @var array<string, list<string>> path → variable paths used */
    public array $variablesUsed = [];

    /**
     * @param list<string> $paths Absolute paths to all templates to analyse.
     */
    public static function build(array $paths, IncludeParser $includeParser): self
    {
        // Normalize all paths so keys are consistent with realpath-resolved values.
        $paths = array_values(array_filter(array_map(
            static fn (string $p): string|false => realpath($p),
            $paths,
        )));
        $graph = new self();

        // First pass: parse every file and collect everything except shorthand
        // function calls (which require the complete function-definition set).
        foreach ($paths as $path) {
            $result = $includeParser->parse($path);
            if ($result === null) {
                continue;
            }

            $graph->includes[$path] = [];
            $graph->blockDefinitions[$path] = [];
            $graph->blockOverrides[$path] = [];
            $graph->functionDefinitions[$path] = [];
            $graph->functionCalls[$path] = [];
            $graph->variablesUsed[$path] = [];

            $graph->collectFromAst($path, $result, $includeParser);
        }

        // Second pass: detect shorthand function invocations now that we know
        // all defined function names across the entire project.
        $allFunctionNames = $graph->allDefinedFunctionNames();
        if ($allFunctionNames !== []) {
            foreach ($paths as $path) {
                $result = $includeParser->parse($path);
                if ($result === null) {
                    continue;
                }
                $graph->collectShorthandCalls($path, $result->ast, $allFunctionNames);
            }
        }

        return $graph;
    }

    // ------------------------------------------------------------------
    // First-pass collection
    // ------------------------------------------------------------------

    private function collectFromAst(string $path, ParseResult $result, IncludeParser $includeParser): void
    {
        $this->walkNode($path, $result->ast, $includeParser);
    }

    private function walkNode(string $path, Node $node, IncludeParser $includeParser): void
    {
        $this->processNode($path, $node, $includeParser);
        foreach ($node->children() as $child) {
            $this->walkNode($path, $child, $includeParser);
        }
    }

    private function processNode(string $path, Node $node, IncludeParser $includeParser): void
    {
        // Collect variable usage from print expressions and tag arguments.
        if ($node instanceof PrintNode) {
            foreach (AstWalkerHelpers::expressionVariablePaths($node->expression) as $v) {
                $this->variablesUsed[$path][] = $v;
            }
        }

        // Only process TagNode directly; BlockTagNode openTags are now visited as
        // TagNode children via BlockTagNode::children(), so no BlockTagNode fallback needed.
        if (!($node instanceof TagNode)) {
            return;
        }

        foreach ($node->arguments as $arg) {
            foreach (AstWalkerHelpers::expressionVariablePaths($arg->value) as $v) {
                $this->variablesUsed[$path][] = $v;
            }
        }

        $name = strtolower($node->name);

        match ($name) {
            'include'  => $this->processInclude($path, $node, $includeParser),
            'extends'  => $this->processExtends($path, $node, $includeParser),
            'block'    => $this->processBlock($path, $node),
            'function' => $this->processFunction($path, $node),
            'call'     => $this->processCall($path, $node),
            default    => null,
        };
    }

    private function processInclude(string $path, TagNode $tag, IncludeParser $includeParser): void
    {
        $fileArgNode = AstWalkerHelpers::fileArgNode($tag);
        if ($fileArgNode === null) {
            return;
        }
        $literal = AstWalkerHelpers::stringLiteral($fileArgNode->value);
        if ($literal === null || $literal === '') {
            return;
        }

        $targetPath = $includeParser->resolve($literal, dirname($path));
        if ($targetPath === null) {
            return;
        }

        $args = [];
        foreach ($tag->arguments as $arg) {
            $argName = $arg->name;
            if ($argName !== null && strtolower($argName) !== 'file') {
                $args[$argName] = AstWalkerHelpers::stringLiteral($arg->value);
            }
        }

        $this->includes[$path][] = ['targetPath' => $targetPath, 'args' => $args, 'line' => $tag->span->start->line, 'col' => $tag->span->start->column];
    }

    private function processExtends(string $path, TagNode $tag, IncludeParser $includeParser): void
    {
        $fileArgNode = AstWalkerHelpers::fileArgNode($tag);
        if ($fileArgNode === null) {
            return;
        }
        $literal = AstWalkerHelpers::stringLiteral($fileArgNode->value);
        if ($literal === null || $literal === '') {
            return;
        }

        $parentPath = $includeParser->resolve($literal, dirname($path));
        if ($parentPath !== null) {
            $this->extends[$path] = $parentPath;
        }
    }

    private function processBlock(string $path, TagNode $tagNode): void
    {
        $nameArg = null;
        foreach ($tagNode->arguments as $arg) {
            $argName = $arg->name !== null ? strtolower($arg->name) : null;
            if ($argName === 'name' || ($tagNode->isShorthand && $argName === null)) {
                $nameArg = $arg;
                break;
            }
            // positional first arg for shorthand {block 'name'}
            if ($argName === null) {
                $nameArg = $arg;
                break;
            }
        }

        $blockName = $nameArg !== null ? AstWalkerHelpers::stringLiteral($nameArg->value) : null;
        if ($blockName === null || $blockName === '') {
            return;
        }

        // If this template extends a parent, this block is an override.
        // Otherwise it's a definition in the parent.
        if (isset($this->extends[$path])) {
            $this->blockOverrides[$path][] = $blockName;
        } else {
            $this->blockDefinitions[$path][] = [
                'name' => $blockName,
                'line' => $tagNode->span->start->line,
                'col'  => $tagNode->span->start->column,
            ];
        }
    }

    private function processFunction(string $path, TagNode $tag): void
    {
        $nameArg = null;
        foreach ($tag->arguments as $arg) {
            $argName = $arg->name !== null ? strtolower($arg->name) : null;
            if ($argName === 'name') {
                $nameArg = $arg;
                break;
            }
        }

        $funcName = $nameArg !== null ? AstWalkerHelpers::stringLiteral($nameArg->value) : null;
        if ($funcName === null || $funcName === '') {
            return;
        }

        $this->functionDefinitions[$path][] = [
            'name' => $funcName,
            'line' => $tag->span->start->line,
            'col'  => $tag->span->start->column,
        ];
    }

    private function processCall(string $path, TagNode $tag): void
    {
        $nameArg = null;
        foreach ($tag->arguments as $arg) {
            $argName = $arg->name !== null ? strtolower($arg->name) : null;
            if ($argName === 'name') {
                $nameArg = $arg;
                break;
            }
        }

        $funcName = $nameArg !== null ? AstWalkerHelpers::stringLiteral($nameArg->value) : null;
        if ($funcName !== null && $funcName !== '') {
            $this->functionCalls[$path][] = $funcName;
        }
    }

    // ------------------------------------------------------------------
    // Second pass: shorthand function calls
    // ------------------------------------------------------------------

    /** @param array<string,true> $knownNames */
    private function collectShorthandCalls(string $path, Node $node, array $knownNames): void
    {
        if ($node instanceof TagNode) {
            $tagName = strtolower($node->name);
            // Exclude the definition tag itself.
            if ($tagName !== 'function' && isset($knownNames[$tagName])) {
                $this->functionCalls[$path][] = $tagName;
            }
        }

        foreach ($node->children() as $child) {
            $this->collectShorthandCalls($path, $child, $knownNames);
        }
    }

    // ------------------------------------------------------------------
    // New public methods
    // ------------------------------------------------------------------

    /** @return array<string,true> */
    private function allDefinedFunctionNames(): array
    {
        $names = [];
        foreach ($this->functionDefinitions as $defs) {
            foreach ($defs as $def) {
                $names[$def['name']] = true;
            }
        }
        return $names;
    }

    /**
     * Returns all template paths that transitively include or extend $path.
     * Result is sorted for deterministic output. Does not include $path itself.
     *
     * @return list<string>
     */
    public function getDependents(string $path): array
    {
        // Build reverse adjacency map: target → list of sources that reference it.
        /** @var array<string, list<string>> $reverse */
        $reverse = [];

        foreach ($this->includes as $source => $targets) {
            foreach ($targets as $target) {
                $reverse[$target['targetPath']][] = $source;
            }
        }

        foreach ($this->extends as $child => $parent) {
            $reverse[$parent][] = $child;
        }

        // BFS from $path through the reverse graph.
        $visited = [];
        $queue   = [$path];

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($reverse[$current] ?? [] as $dependent) {
                if (!isset($visited[$dependent])) {
                    $visited[$dependent] = true;
                    $queue[] = $dependent;
                }
            }
        }

        $result = array_keys($visited);
        sort($result);
        return array_values($result);
    }

    /**
     * Serializes the full graph as JSON for external consumers.
     * Omits variablesUsed (too verbose).
     */
    public function toJson(int $flags = 0): string
    {
        $includesData = [];
        foreach ($this->includes as $source => $targets) {
            $includesData[$source] = array_map(static fn (array $t) => [
                'targetPath' => $t['targetPath'],
                'line'       => $t['line'],
                'col'        => $t['col'],
            ], $targets);
        }

        $data = [
            'includes'            => $includesData,
            'extends'             => $this->extends,
            'blockDefinitions'    => $this->blockDefinitions,
            'blockOverrides'      => $this->blockOverrides,
            'functionDefinitions' => $this->functionDefinitions,
            'functionCalls'       => $this->functionCalls,
        ];

        return json_encode($data, JSON_THROW_ON_ERROR | $flags);
    }
}
