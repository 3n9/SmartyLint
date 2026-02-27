<?php

declare(strict_types=1);

namespace SmartyLint\Walker;

use SmartyAst\Ast\Node;
use SmartyLint\AstWalkerHelpers;
use SmartyLint\IssueCollector;

final class DeprecatedTagWalker implements NodeWalker
{
    private const DEPRECATED_TAGS = [
        'php' => '{php} tag is deprecated',
        'insert' => '{insert} tag is deprecated',
    ];

    public function onNode(Node $node, string $path, IssueCollector $issues): void
    {
        $name = AstWalkerHelpers::tagName($node);
        if ($name !== null && isset(self::DEPRECATED_TAGS[$name])) {
            [$line, $col] = AstWalkerHelpers::tagStart($node);
            $issues->add($path, $line, $col, 'ERROR', self::DEPRECATED_TAGS[$name]);
        }
    }
}
