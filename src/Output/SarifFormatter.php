<?php

declare(strict_types=1);

namespace SmartyLint\Output;

use SmartyLint\Issue;

/**
 * Produces SARIF 2.1.0 output (https://docs.oasis-open.org/sarif/sarif/v2.1.0/).
 * Compatible with GitHub Actions, VS Code SARIF Viewer, and other CI tools.
 */
final class SarifFormatter implements OutputFormatter
{
    private const SCHEMA = 'https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/sarif-schema-2.1.0.json';

    public function __construct(private readonly string $toolVersion = '1.0.0')
    {
    }

    /** @param list<Issue> $issues */
    public function format(array $issues): string
    {
        $ruleIds = [];
        $results = [];

        foreach ($issues as $issue) {
            $ruleId = $this->extractRuleId($issue->message);
            $ruleIds[$ruleId] = true;

            $results[] = [
                'ruleId' => $ruleId,
                'level' => $this->sarifLevel($issue->severity),
                'message' => ['text' => $issue->message],
                'locations' => [
                    [
                        'physicalLocation' => [
                            'artifactLocation' => [
                                'uri' => $this->toUri($issue->path),
                                'uriBaseId' => '%SRCROOT%',
                            ],
                            'region' => [
                                'startLine' => $issue->line,
                                'startColumn' => $issue->col,
                            ],
                        ],
                    ],
                ],
            ];
        }

        $rules = array_values(array_map(
            static fn (string $id): array => ['id' => $id, 'name' => $id],
            array_keys($ruleIds),
        ));

        $sarif = [
            '$schema' => self::SCHEMA,
            'version' => '2.1.0',
            'runs' => [
                [
                    'tool' => [
                        'driver' => [
                            'name' => 'SmartyLint',
                            'version' => $this->toolVersion,
                            'informationUri' => 'https://github.com/3n9/smarty-lint',
                            'rules' => $rules,
                        ],
                    ],
                    'results' => $results,
                ],
            ],
        ];

        return json_encode($sarif, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    private function extractRuleId(string $message): string
    {
        // Messages from the parser look like "[PARSE001] Unexpected ..."
        if (preg_match('/^\[([A-Z][A-Z0-9]+)\]/', $message, $m)) {
            return $m[1];
        }

        return 'SMARTYLINT001';
    }

    private function sarifLevel(string $severity): string
    {
        return match (strtoupper($severity)) {
            'ERROR' => 'error',
            'WARNING' => 'warning',
            default => 'note',
        };
    }

    private function toUri(string $path): string
    {
        // Convert absolute path to a file:// URI for SARIF consumers.
        return 'file://' . str_replace('\\', '/', $path);
    }
}
