<?php

declare(strict_types=1);

namespace SmartyLint;

final class IssueCollector
{
    /** @var list<Issue> */
    private array $issues = [];

    public function add(string $path, int $line, int $col, string $severity, string $message): void
    {
        $this->issues[] = new Issue($path, $line, $col, strtoupper($severity), $message);
    }

    /** @return list<Issue> */
    public function all(): array
    {
        return $this->issues;
    }
}
