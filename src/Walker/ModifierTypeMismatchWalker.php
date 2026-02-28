<?php

declare(strict_types=1);

namespace SmartyLint\Walker;

use SmartyAst\Ast\LiteralExpressionNode;
use SmartyAst\Ast\ModifierChainExpressionNode;
use SmartyAst\Ast\Node;
use SmartyAst\Ast\PrintNode;
use SmartyAst\Ast\TagLike;
use SmartyAst\Ast\VariableExpressionNode;
use SmartyLint\IssueCollector;

/**
 * Warns when a modifier is used on a base expression whose inferred type
 * does not match the modifier's expected input type.
 *
 * This walker is per-file: instantiate it fresh for each file using the
 * TypeInferenceEngine output, then include it alongside the reusable walkers.
 * It cannot be added to Linter's $allWalkers because the type map is file-specific.
 * The rule key 'modifiertypemismatch' can still be disabled via disabledRules.
 */
final class ModifierTypeMismatchWalker implements NodeWalker
{
    /** Expected input type per modifier name. */
    private const MODIFIER_EXPECTS = [
        'truncate'    => 'string',
        'upper'       => 'string',
        'lower'       => 'string',
        'nl2br'       => 'string',
        'strip'       => 'string',
        'strip_tags'  => 'string',
        'wordwrap'    => 'string',
        'escape'      => 'string',
        'date_format' => 'string',
        'count'       => 'array',
        'implode'     => 'array',
        'join'        => 'array',
        'count_words' => 'string',
    ];

    /**
     * @param array<string, string> $typeMap  variable name (without $) → inferred type
     */
    public function __construct(private readonly array $typeMap)
    {
    }

    public function onNode(Node $node, string $path, IssueCollector $issues): void
    {
        // Find ModifierChainExpressionNode inside PrintNode expressions.
        if ($node instanceof PrintNode) {
            if ($node->expression instanceof ModifierChainExpressionNode) {
                $this->checkChain($node->expression, $path, $issues);
            }
            return;
        }

        // Also check modifier chains inside tag arguments.
        if ($node instanceof TagLike) {
            foreach ($node->resolveTag()->arguments as $arg) {
                if ($arg->value instanceof ModifierChainExpressionNode) {
                    $this->checkChain($arg->value, $path, $issues);
                }
            }
        }
    }

    private function checkChain(ModifierChainExpressionNode $chain, string $path, IssueCollector $issues): void
    {
        $baseType = $this->resolveBaseType($chain);
        if ($baseType === 'unknown') {
            return;
        }

        foreach ($chain->modifiers as $modifier) {
            $modifierName = strtolower($modifier->name);
            if (!isset(self::MODIFIER_EXPECTS[$modifierName])) {
                continue;
            }
            $expected = self::MODIFIER_EXPECTS[$modifierName];

            // date_format accepts both string and int timestamps.
            if ($modifierName === 'date_format' && $baseType === 'int') {
                continue;
            }

            if ($baseType !== $expected) {
                $issues->add(
                    $path,
                    $chain->span->start->line,
                    $chain->span->start->column,
                    'WARNING',
                    "Modifier |{$modifierName} expects {$expected} but base expression is inferred as {$baseType}.",
                );
            }
        }
    }

    private function resolveBaseType(ModifierChainExpressionNode $chain): string
    {
        $base = $chain->base;

        if ($base instanceof VariableExpressionNode) {
            return $this->typeMap[$base->name] ?? 'unknown';
        }

        if ($base instanceof LiteralExpressionNode) {
            return match (true) {
                is_string($base->value) => 'string',
                is_int($base->value)    => 'int',
                is_float($base->value)  => 'float',
                is_bool($base->value)   => 'bool',
                default                 => 'unknown',
            };
        }

        return 'unknown';
    }
}
