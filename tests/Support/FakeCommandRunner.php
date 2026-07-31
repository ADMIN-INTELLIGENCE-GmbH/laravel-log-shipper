<?php

namespace AdminIntelligence\LogShipper\Tests\Support;

use AdminIntelligence\LogShipper\Status\CommandRunner;

/**
 * A CommandRunner that answers from a canned map instead of the real shell,
 * so collector tests never depend on the host they run on.
 */
class FakeCommandRunner extends CommandRunner
{
    /**
     * Commands the collector asked for, in order.
     *
     * @var array<int, string>
     */
    public array $commands = [];

    /**
     * @param  array<string, string|array{output?: string, exit_code?: int|null, timed_out?: bool}|null>  $responses  Keyed by a substring of the command.
     * @param  array<int, string>  $binaries  Binary names that should report as installed.
     */
    public function __construct(
        protected array $responses = [],
        protected array $binaries = []
    ) {}

    public function run(string $command, int $timeoutSeconds): ?string
    {
        $result = $this->runWithExitCode($command, $timeoutSeconds);

        if ($result['timed_out'] || $result['output'] === '') {
            return null;
        }

        return $result['output'];
    }

    public function runWithExitCode(string $command, int $timeoutSeconds): array
    {
        $this->commands[] = $command;

        foreach ($this->responses as $needle => $response) {
            if (!str_contains($command, (string) $needle)) {
                continue;
            }

            if (is_array($response)) {
                return [
                    'output' => trim($response['output'] ?? ''),
                    'exit_code' => array_key_exists('exit_code', $response) ? $response['exit_code'] : 0,
                    'timed_out' => $response['timed_out'] ?? false,
                ];
            }

            return [
                'output' => trim((string) $response),
                'exit_code' => 0,
                'timed_out' => false,
            ];
        }

        return ['output' => '', 'exit_code' => 1, 'timed_out' => false];
    }

    public function commandExists(string $binary): bool
    {
        return in_array($binary, $this->binaries, true);
    }
}
