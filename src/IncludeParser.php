<?php

declare(strict_types=1);

namespace SmartyLint;

use SmartyAst\ParseResult;
use SmartyAst\Parser\SmartyParser;

final class IncludeParser
{
    /** @var array<string, ParseResult|null> */
    private array $cache = [];

    public function __construct(
        private readonly SmartyParser $parser,
        private readonly ?string $templateRoot = null,
    ) {
    }

    public function parse(string $absolutePath): ?ParseResult
    {
        if (array_key_exists($absolutePath, $this->cache)) {
            return $this->cache[$absolutePath];
        }

        try {
            $this->cache[$absolutePath] = $this->parser->parseFile($absolutePath);
        } catch (\Throwable) {
            $this->cache[$absolutePath] = null;
        }

        return $this->cache[$absolutePath];
    }

    public function resolve(string $literal, string $baseDir): ?string
    {
        if ($literal === '' || str_starts_with($literal, '$')) {
            return null;
        }

        if ($literal[0] === '/') {
            $resolved = realpath($literal);
            return ($resolved !== false && is_file($resolved)) ? $resolved : null;
        }

        // Try templateRoot first (Smarty's template_dir), then relative to caller.
        $candidates = [];
        if ($this->templateRoot !== null) {
            $candidates[] = rtrim($this->templateRoot, '/') . '/' . $literal;
        }
        $candidates[] = $baseDir . '/' . $literal;

        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved !== false && is_file($resolved)) {
                return $resolved;
            }
        }

        return null;
    }
}
