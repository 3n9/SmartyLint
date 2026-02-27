<?php

declare(strict_types=1);

namespace SmartyLint;

use SmartyAst\Ast\Node;
use SmartyAst\Parser\SmartyParser;
use SmartyLint\Walker\BlockStructureWalker;
use SmartyLint\Walker\DeepNestingWalker;
use SmartyLint\Walker\DeprecatedTagWalker;
use SmartyLint\Walker\DuplicateBlockNameWalker;
use SmartyLint\Walker\EmptyBlockWalker;
use SmartyLint\Walker\ExitAwareNodeWalker;
use SmartyLint\Walker\IncludeParameterWalker;
use SmartyLint\Walker\NodeWalker;
use SmartyLint\Walker\RelativePathWalker;
use SmartyLint\Walker\UnusedAssignWalker;
use SmartyLint\Walker\UnusedCaptureWalker;
use SmartyLint\Walker\UnescapedVariableWalker;

final class Linter
{
    private SmartyParser $parser;
    private ?LintCache $cache;
    private IncludeParameterWalker $includeParameterWalker;
    private UnusedCaptureWalker $unusedCaptureWalker;
    private DeepNestingWalker $deepNestingWalker;
    private DuplicateBlockNameWalker $duplicateBlockNameWalker;
    private UnusedAssignWalker $unusedAssignWalker;
    private IncludeCycleDetector $includeCycleDetector;
    /** @var list<NodeWalker> */
    private array $walkers;

    public function __construct(?SmartyParser $parser = null, ?LintCache $cache = null, ?LintConfig $config = null)
    {
        $config ??= new LintConfig();
        $this->parser = $parser ?? new SmartyParser();
        $this->cache = $cache;
        $includeParser = new IncludeParser($this->parser, $config->templateRoot);
        $this->includeParameterWalker = new IncludeParameterWalker($includeParser);
        $this->unusedCaptureWalker = new UnusedCaptureWalker();
        $this->deepNestingWalker = new DeepNestingWalker($config->maxNestingDepth);
        $this->duplicateBlockNameWalker = new DuplicateBlockNameWalker();
        $this->unusedAssignWalker = new UnusedAssignWalker();
        $this->includeCycleDetector = new IncludeCycleDetector($includeParser);

        $disabled = array_map('strtolower', $config->disabledRules);
        $strict   = array_map('strtolower', $config->strictRules);

        $allWalkers = [
            'blockstructure'     => new BlockStructureWalker(),
            'deprecatedtag'      => new DeprecatedTagWalker(),
            'relativepath'       => new RelativePathWalker(),
            'includeparameter'   => $this->includeParameterWalker,
            'unusedcapture'      => $this->unusedCaptureWalker,
            'emptyblock'         => new EmptyBlockWalker(),
            'deepnesting'        => $this->deepNestingWalker,
            'duplicateblockname' => $this->duplicateBlockNameWalker,
            'unusedassign'       => $this->unusedAssignWalker,
        ];

        // Strict (opt-in) walkers — only added when explicitly enabled.
        $strictWalkers = [
            'unescapedvariable' => new UnescapedVariableWalker(),
        ];

        $this->walkers = [];
        foreach ($allWalkers as $key => $walker) {
            if (!in_array($key, $disabled, true)) {
                $this->walkers[] = $walker;
            }
        }
        foreach ($strictWalkers as $key => $walker) {
            if (in_array($key, $strict, true)) {
                $this->walkers[] = $walker;
            }
        }
    }

    /** @return list<Issue> */
    public function lintFile(string $path): array
    {
        $normalizedPath = realpath($path) ?: $path;
        if ($this->cache !== null) {
            $cached = $this->cache->getIssues($normalizedPath);
            if ($cached !== null) {
                return $cached;
            }
        }

        $collector = new IssueCollector();
        $dependencies = [$normalizedPath];
        $lintedFromAst = false;

        try {
            $result = $this->parser->parseFile($normalizedPath);

            foreach ($result->diagnostics as $diagnostic) {
                $collector->add(
                    $normalizedPath,
                    $diagnostic->span->start->line,
                    $diagnostic->span->start->column,
                    strtoupper($diagnostic->severity->value),
                    sprintf('[%s] %s', $diagnostic->code, $diagnostic->message),
                );
            }

            $this->unusedCaptureWalker->reset();
            $this->includeParameterWalker->reset();
            $this->deepNestingWalker->reset();
            $this->duplicateBlockNameWalker->reset();
            $this->unusedAssignWalker->reset();
            $this->includeCycleDetector->detect($normalizedPath, $result, $collector);
            $this->walkNode($result->ast, $normalizedPath, $collector);
            $this->unusedCaptureWalker->finalize($normalizedPath, $collector);
            $this->duplicateBlockNameWalker->finalize($normalizedPath, $collector);
            $this->unusedAssignWalker->finalize($normalizedPath, $collector);
            $dependencies = array_merge(
                $dependencies,
                $this->includeParameterWalker->getDependencies(),
                $this->includeCycleDetector->getDependencies(),
            );
            $lintedFromAst = true;
        } catch (\Throwable $e) {
            $collector->add($normalizedPath, 1, 1, 'ERROR', $e->getMessage());
        }

        $issues = $collector->all();
        if ($this->cache !== null) {
            if (!$lintedFromAst) {
                $dependencies = [$normalizedPath];
            }
            $this->cache->putIssues($normalizedPath, $issues, $dependencies);
        }

        return $issues;
    }

    private function walkNode(Node $node, string $path, IssueCollector $collector): void
    {
        foreach ($this->walkers as $walker) {
            $walker->onNode($node, $path, $collector);
        }

        foreach ($node->children() as $child) {
            $this->walkNode($child, $path, $collector);
        }

        foreach ($this->walkers as $walker) {
            if ($walker instanceof ExitAwareNodeWalker) {
                $walker->onExit($node, $path, $collector);
            }
        }
    }
}
