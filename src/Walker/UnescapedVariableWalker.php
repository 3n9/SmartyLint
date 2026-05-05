<?php

declare(strict_types=1);

namespace SmartyLint\Walker;

use SmartyAst\Ast\ForeachIterationPropertyNode;
use SmartyAst\Ast\ModifierChainExpressionNode;
use SmartyAst\Ast\Node;
use SmartyAst\Ast\PrintNode;
use SmartyAst\Ast\VariableExpressionNode;
use SmartyLint\AstWalkerHelpers;
use SmartyLint\IssueCollector;

/**
 * Warns when a variable is printed without an HTML-escaping modifier.
 *
 * Smarty does not auto-escape output, so {$var} in a template renders the
 * raw value and is a potential XSS vector. This walker flags any PrintNode
 * whose expression contains variable references but is not guarded by the
 * recognised escape modifier: escape (including escape:'html', etc).
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

        // Foreach iteration properties ($item@first, $item@last, etc.) are booleans
        // or integers — they never need HTML-escaping.
        if ($expr instanceof ForeachIterationPropertyNode) {
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
            return;
        }

        // For any non-modifier expression, warn if it references variables
        // (e.g. {$a + $b}, {$obj->prop}, etc).
        $varPaths = AstWalkerHelpers::expressionVariablePaths($expr);
        if ($varPaths === []) {
            return;
        }

        $label = $expr instanceof VariableExpressionNode ? "\${$expr->name}" : 'expression';
        $issues->add(
            $path,
            $node->span->start->line,
            $node->span->start->column,
            'WARNING',
            "Variable '{$label}' is printed without an escaping modifier (e.g. |escape).",
        );
    }
}
