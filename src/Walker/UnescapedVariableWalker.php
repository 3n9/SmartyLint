<?php

declare(strict_types=1);

namespace SmartyLint\Walker;

use SmartyAst\Ast\ModifierChainExpressionNode;
use SmartyAst\Ast\Node;
use SmartyAst\Ast\PrintNode;
use SmartyAst\Ast\VariableExpressionNode;
use SmartyLint\IssueCollector;

/**
 * Warns when a variable is printed without an HTML-escaping modifier.
 *
 * Smarty does not auto-escape output, so {$var} in a template renders the
 * raw value and is a potential XSS vector. This walker flags any PrintNode
 * whose expression is not guarded by one of the recognised escape modifiers:
 * escape, h, htmlspecialchars, htmlentities.
 *
 * This rule is OFF by default. Enable it via:
 *   - CLI:    --enable UnescapedVariable
 *   - Config: "strictRules": ["UnescapedVariable"]
 */
final class UnescapedVariableWalker implements NodeWalker
{
    /** Modifier names that are considered safe HTML-escaping. */
    private const ESCAPE_MODIFIERS = ['escape'];

    public function onNode(Node $node, string $path, IssueCollector $issues): void
    {
        if (!($node instanceof PrintNode)) {
            return;
        }

        $expr = $node->expression;

        if ($expr instanceof VariableExpressionNode) {
            // {$var} with no modifiers at all.
            $issues->add(
                $path,
                $node->span->start->line,
                $node->span->start->column,
                'WARNING',
                "Variable '\${$expr->name}' is printed without an escaping modifier (e.g. |escape).",
            );
            return;
        }

        if ($expr instanceof ModifierChainExpressionNode) {
            foreach ($expr->modifiers as $modifier) {
                if (in_array(strtolower($modifier->name), self::ESCAPE_MODIFIERS, true)) {
                    return; // Escaped — all good.
                }
            }

            // Has modifiers but none is an escape modifier.
            $baseVarName = $expr->base instanceof VariableExpressionNode
                ? $expr->base->name
                : null;

            $label = $baseVarName !== null ? "\${$baseVarName}" : 'expression';
            $issues->add(
                $path,
                $node->span->start->line,
                $node->span->start->column,
                'WARNING',
                "Variable '{$label}' is printed without an escaping modifier (e.g. |escape).",
            );
        }
    }
}
