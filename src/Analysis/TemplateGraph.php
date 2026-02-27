<?php

declare(strict_types=1);

namespace SmartyLint\Analysis;

use SmartyAst\Ast\BlockTagNode;
use SmartyAst\Ast\Node;
use SmartyAst\Ast\PrintNode;
use SmartyAst\Ast\TagLike;
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

        $tag = null;
        if ($node instanceof TagNode) {
            $tag = $node;
        } elseif ($node instanceof BlockTagNode) {
            $tag = $node->openTag;
        }

        if ($tag !== null) {
            foreach ($tag->arguments as $arg) {
                foreach (AstWalkerHelpers::expressionVariablePaths($arg->value) as $v) {
                    $this->variablesUsed[$path][] = $v;
                }
            }
        }

        if (!($node instanceof TagLike)) {
            return;
        }

        $tagNode = $node->resolveTag();
        $name = strtolower($tagNode->name);

        match ($name) {
            'include'  => $this->processInclude($path, $tagNode, $includeParser),
            'extends'  => $this->processExtends($path, $tagNode, $includeParser),
            'block'    => $this->processBlock($path, $node),
            'function' => $this->processFunction($path, $tagNode),
            'call'     => $this->processCall($path, $tagNode),
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

    private function processBlock(string $path, Node $node): void
    {
        $tagNode = $node->resolveTag();
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
        if ($node instanceof TagLike) {
            $tagName = strtolower($node->resolveTag()->name);
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
    // Helpers
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
}
