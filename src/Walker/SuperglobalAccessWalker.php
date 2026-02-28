<?php

declare(strict_types=1);

namespace SmartyLint\Walker;

use SmartyAst\Ast\BlockTagNode;
use SmartyAst\Ast\Node;
use SmartyAst\Ast\PrintNode;
use SmartyAst\Ast\TagNode;
use SmartyLint\AstWalkerHelpers;
use SmartyLint\IssueCollector;

/**
 * Warns when a template accesses HTTP input or server superglobals directly
 * via $smarty.get.*, $smarty.post.*, $smarty.request.*, $smarty.cookies.*,
 * $smarty.server.*, $smarty.env.*, or $smarty.session.*.
 *
 * Templates should receive pre-processed, validated data from the controller.
 * Direct superglobal access bypasses input validation, makes templates harder
 * to test, and is a potential XSS / injection vector.
 *
 * This rule is ON by default. Disable it via:
 *   - Config: { "disabledRules": ["SuperglobalAccess"] }
 */
final class SuperglobalAccessWalker implements NodeWalker
{
    /** @var list<string> Smarty superglobal key prefixes to flag (without trailing dot) */
    private const FLAGGED_PREFIXES = [
        'smarty.get',
        'smarty.post',
        'smarty.request',
        'smarty.cookies',
        'smarty.server',
        'smarty.env',
        'smarty.session',
    ];

    public function onNode(Node $node, string $path, IssueCollector $issues): void
    {
        if ($node instanceof PrintNode) {
            $seen = [];
            foreach (AstWalkerHelpers::expressionVariablePaths($node->expression) as $varPath) {
                $this->checkPath($varPath, $node->span->start->line, $node->span->start->column, $path, $issues, $seen);
            }
            return;
        }

        $tag = $node instanceof TagNode ? $node : ($node instanceof BlockTagNode ? $node->openTag : null);
        if ($tag === null) {
            return;
        }

        foreach ($tag->arguments as $argument) {
            $seen = [];
            foreach (AstWalkerHelpers::expressionVariablePaths($argument->value) as $varPath) {
                $this->checkPath($varPath, $tag->span->start->line, $tag->span->start->column, $path, $issues, $seen);
            }
        }
    }

    /** @param array<string, bool> $seen Already-warned prefixes for this expression */
    private function checkPath(string $varPath, int $line, int $col, string $path, IssueCollector $issues, array &$seen): void
    {
        foreach (self::FLAGGED_PREFIXES as $prefix) {
            // Match exact prefix (e.g. smarty.get) or deeper (smarty.get.q)
            if ($varPath === $prefix || str_starts_with($varPath, $prefix . '.')) {
                if (isset($seen[$prefix])) {
                    return;
                }
                $seen[$prefix] = true;
                $key = explode('.', $varPath, 3)[1] ?? $prefix; // get, post, etc.
                $issues->add(
                    $path,
                    $line,
                    $col,
                    'WARNING',
                    "Direct access to \$smarty.{$key}.* in template. Move input handling to controller code.",
                );
                return;
            }
        }
    }
}
