<?php

declare(strict_types=1);

namespace SmartyLint\Walker;

use SmartyAst\Ast\CommentNode;
use SmartyAst\Ast\Node;
use SmartyAst\Ast\TagNode;
use SmartyAst\ParseResult;
use SmartyLint\AstWalkerHelpers;
use SmartyLint\IncludeParser;
use SmartyLint\IssueCollector;

final class IncludeParameterWalker implements NodeWalker
{
    private IncludeParser $includeParser;

    /** @var array<string,bool> */
    private array $dependencies = [];

    public function __construct(IncludeParser $includeParser)
    {
        $this->includeParser = $includeParser;
    }

    public function reset(): void
    {
        $this->dependencies = [];
    }

    /** @return list<string> */
    public function getDependencies(): array
    {
        return array_keys($this->dependencies);
    }

    public function onNode(Node $node, string $path, IssueCollector $issues): void
    {
        $tag = $node instanceof TagNode ? $node : null;

        if ($tag !== null && strtolower($tag->name) === 'include') {
            $this->checkInclude($tag, $path, $issues);
        }
    }

    private function checkInclude(TagNode $tag, string $currentPath, IssueCollector $issues): void
    {
        $fileArgNode = AstWalkerHelpers::fileArgNode($tag);
        $includeFile = $fileArgNode !== null ? AstWalkerHelpers::stringLiteral($fileArgNode->value) : null;

        if ($includeFile === null || $includeFile === '' || str_starts_with($includeFile, '$')) {
            return;
        }

        $provided = [];
        foreach ($tag->arguments as $argument) {
            $argName = $argument->name;
            if ($argName !== null && strtolower($argName) !== 'file') {
                $provided[] = $argName;
            }
        }

        $includePath = $this->includeParser->resolve($includeFile, dirname($currentPath));
        if ($includePath === null) {
            return;
        }
        $this->dependencies[$includePath] = true;

        $result = $this->includeParser->parse($includePath);
        if ($result === null) {
            return;
        }

        $required = $this->requiredParamsFromParseResult($result);
        if ($required === []) {
            return;
        }

        $missing = [];
        foreach ($required as $param) {
            $root = explode('.', $param)[0];
            if (!in_array($root, $provided, true)) {
                $missing[] = $param;
            }
        }

        if ($missing !== []) {
            $issues->add(
                $currentPath,
                $tag->span->start->line,
                $tag->span->start->column,
                'WARNING',
                "Include '{$includeFile}' is missing required parameters: " . implode(', ', $missing),
            );
        }
    }

    /** @return list<string> */
    private function requiredParamsFromParseResult(ParseResult $result): array
    {
        $comments = array_values(array_filter($result->ast->children, static fn (Node $n): bool => $n instanceof CommentNode));
        if ($comments === []) {
            return [];
        }

        $first = $comments[0];
        $params = [];
        foreach ($first->annotations as $annotation) {
            if ($annotation->name !== 'param') {
                continue;
            }

            $name = $annotation->data['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $params[] = $name;
            }
        }

        return array_values(array_unique($params));
    }
}
