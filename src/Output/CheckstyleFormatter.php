<?php

declare(strict_types=1);

namespace SmartyLint\Output;

use SmartyLint\Issue;

/**
 * Produces Checkstyle XML output, compatible with Jenkins, PHPStorm, and
 * GitHub Actions problem matchers.
 */
final class CheckstyleFormatter implements OutputFormatter
{
    /** @param list<Issue> $issues */
    public function format(array $issues): string
    {
        $byFile = [];
        foreach ($issues as $issue) {
            $byFile[$issue->path][] = $issue;
        }

        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<checkstyle version="8.0">'];

        foreach ($byFile as $path => $fileIssues) {
            $lines[] = '  <file name="' . htmlspecialchars($path, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '">';
            foreach ($fileIssues as $issue) {
                $lines[] = sprintf(
                    '    <error line="%d" column="%d" severity="%s" message=%s source="smartylint"/>',
                    $issue->line,
                    $issue->col,
                    $this->checkstyleSeverity($issue->severity),
                    '"' . htmlspecialchars($issue->message, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '"',
                );
            }
            $lines[] = '  </file>';
        }

        if ($byFile === []) {
            $lines[] = '</checkstyle>';
        } else {
            $lines[] = '</checkstyle>';
        }

        return implode("\n", $lines) . "\n";
    }

    private function checkstyleSeverity(string $severity): string
    {
        return match (strtoupper($severity)) {
            'ERROR' => 'error',
            'WARNING' => 'warning',
            default => 'info',
        };
    }
}
