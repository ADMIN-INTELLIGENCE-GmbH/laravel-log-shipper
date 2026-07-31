<?php

declare(strict_types=1);

namespace AdminIntelligence\LogShipper\Status;

/**
 * Runs short shell commands with a hard timeout.
 *
 * Every command issued by the status collectors is a fixed literal; no
 * configuration or user input is ever interpolated into a command string.
 */
class CommandRunner
{
    /**
     * Run a command and return its trimmed stdout, or null on failure/timeout.
     */
    public function run(string $command, int $timeoutSeconds): ?string
    {
        $result = $this->runWithExitCode($command, $timeoutSeconds);

        if ($result['timed_out'] || $result['output'] === '') {
            return null;
        }

        return $result['output'];
    }

    /**
     * Run a command and return its stdout alongside the exit code.
     *
     * Some package managers signal state through the exit code rather than
     * stdout (`dnf check-update` exits 100 when updates are pending), so the
     * code has to survive the call.
     *
     * @return array{output: string, exit_code: int|null, timed_out: bool}
     */
    public function runWithExitCode(string $command, int $timeoutSeconds): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return ['output' => '', 'exit_code' => null, 'timed_out' => false];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $start = microtime(true);
        $output = '';
        $exitCode = null;
        $timedOut = false;

        while (true) {
            $output .= (string) stream_get_contents($pipes[1]);

            // Drain stderr and throw it away. A command that fills the stderr
            // pipe buffer blocks forever if nobody reads it.
            stream_get_contents($pipes[2]);

            $status = proc_get_status($process);

            if (!$status['running']) {
                $output .= (string) stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                $exitCode = $status['exitcode'];

                break;
            }

            if ((microtime(true) - $start) > $timeoutSeconds) {
                $timedOut = true;
                proc_terminate($process);

                break;
            }

            usleep(50000); // 50ms
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [
            'output' => trim($output),
            'exit_code' => $timedOut ? null : $exitCode,
            'timed_out' => $timedOut,
        ];
    }

    /**
     * Check whether a binary is available on the host.
     */
    public function commandExists(string $binary): bool
    {
        // Reject anything that is not a plain binary name or path before it
        // reaches a shell.
        if (!preg_match('/^[A-Za-z0-9._\/-]+$/', $binary)) {
            return false;
        }

        $command = PHP_OS_FAMILY === 'Windows'
            ? 'where ' . escapeshellarg($binary) . ' 2>NUL'
            : 'command -v ' . escapeshellarg($binary) . ' 2>/dev/null';

        $result = $this->runWithExitCode($command, 5);

        return $result['exit_code'] === 0 && $result['output'] !== '';
    }
}
