<?php

declare(strict_types=1);

namespace SmartyLint;

/**
 * Holds all linter configuration options.
 * Loaded from .smartylintrc.json and/or overridden by CLI flags.
 */
final class LintConfig
{
    /**
     * @param string|null    $templateRoot         Base directory for resolving template includes (Smarty template_dir).
     * @param list<string>   $disabledRules        Walker short names to disable, e.g. ['DeprecatedTag', 'RelativePath', 'DuplicateBlockName'].
     * @param list<string>   $excludePatterns      Glob patterns for files to exclude from linting.
     * @param int            $maxNestingDepth      Maximum allowed block nesting depth (DeepNestingWalker threshold).
     * @param int|null       $maxScanDepth         Maximum recursive directory scan depth; null means unlimited.
     * @param list<string>   $strictRules          Extra rules that are off by default but can be opted in, e.g. ['UnescapedVariable'].
     * @param int            $maxModifierChain     Maximum allowed modifier chain length (ComplexExpressionWalker threshold).
     * @param int            $maxConditionOperands Maximum allowed operands in a boolean condition (ComplexExpressionWalker threshold).
     */
    public function __construct(
        public readonly ?string $templateRoot = null,
        public readonly array $disabledRules = [],
        public readonly array $excludePatterns = [],
        public readonly int $maxNestingDepth = 5,
        public readonly ?int $maxScanDepth = null,
        public readonly array $strictRules = [],
        public readonly int $maxModifierChain = 3,
        public readonly int $maxConditionOperands = 3,
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return new self();
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return new self();
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return new self();
        }

        return self::fromArray($data);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $templateRoot = isset($data['templateRoot']) && is_string($data['templateRoot'])
            ? $data['templateRoot']
            : null;

        $disabledRules = isset($data['disabledRules']) && is_array($data['disabledRules'])
            ? array_values(array_filter($data['disabledRules'], 'is_string'))
            : [];

        $excludePatterns = isset($data['excludePatterns']) && is_array($data['excludePatterns'])
            ? array_values(array_filter($data['excludePatterns'], 'is_string'))
            : [];

        $maxNestingDepth = isset($data['maxNestingDepth']) && is_int($data['maxNestingDepth']) && $data['maxNestingDepth'] > 0
            ? $data['maxNestingDepth']
            : 5;

        $maxScanDepth = isset($data['maxScanDepth']) && is_int($data['maxScanDepth']) && $data['maxScanDepth'] >= 0
            ? $data['maxScanDepth']
            : null;

        $strictRules = isset($data['strictRules']) && is_array($data['strictRules'])
            ? array_values(array_filter($data['strictRules'], 'is_string'))
            : [];

        $maxModifierChain = isset($data['maxModifierChain']) && is_int($data['maxModifierChain']) && $data['maxModifierChain'] > 0
            ? $data['maxModifierChain']
            : 3;

        $maxConditionOperands = isset($data['maxConditionOperands']) && is_int($data['maxConditionOperands']) && $data['maxConditionOperands'] > 0
            ? $data['maxConditionOperands']
            : 3;

        return new self($templateRoot, $disabledRules, $excludePatterns, $maxNestingDepth, $maxScanDepth, $strictRules, $maxModifierChain, $maxConditionOperands);
    }

    /**
     * Returns a new LintConfig with the given fields overridden (non-null values take precedence).
     *
     * @param list<string> $excludePatterns Additional patterns to append.
     */
    public function withOverrides(
        ?string $templateRoot = null,
        array $disabledRules = [],
        array $excludePatterns = [],
        ?int $maxNestingDepth = null,
        ?int $maxScanDepth = null,
        array $strictRules = [],
    ): self {
        return new self(
            templateRoot: $templateRoot ?? $this->templateRoot,
            disabledRules: $disabledRules !== [] ? $disabledRules : $this->disabledRules,
            excludePatterns: array_values(array_unique(array_merge($this->excludePatterns, $excludePatterns))),
            maxNestingDepth: $maxNestingDepth ?? $this->maxNestingDepth,
            maxScanDepth: $maxScanDepth ?? $this->maxScanDepth,
            strictRules: $strictRules !== [] ? array_values(array_unique(array_merge($this->strictRules, $strictRules))) : $this->strictRules,
            maxModifierChain: $this->maxModifierChain,
            maxConditionOperands: $this->maxConditionOperands,
        );
    }
}
