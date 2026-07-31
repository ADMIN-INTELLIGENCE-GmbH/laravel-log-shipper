<?php

namespace AdminIntelligence\LogShipper\Tests\Unit\Status;

use AdminIntelligence\LogShipper\Status\CommandRunner;
use AdminIntelligence\LogShipper\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CommandRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Shell assertions target POSIX shells.');
        }
    }

    #[Test]
    public function it_returns_trimmed_command_output(): void
    {
        $runner = new CommandRunner;

        $this->assertSame('hello', $runner->run('echo hello', 5));
    }

    #[Test]
    public function it_returns_null_when_the_command_produces_no_output(): void
    {
        $runner = new CommandRunner;

        $this->assertNull($runner->run('printf ""', 5));
    }

    #[Test]
    public function it_returns_null_when_the_command_exceeds_the_timeout(): void
    {
        $runner = new CommandRunner;

        $this->assertNull($runner->run('sleep 3', 1));
    }

    #[Test]
    public function it_reports_the_exit_code_of_a_failed_command(): void
    {
        $runner = new CommandRunner;

        $result = $runner->runWithExitCode('exit 100', 5);

        $this->assertSame(100, $result['exit_code']);
        $this->assertFalse($result['timed_out']);
    }

    #[Test]
    public function it_reports_a_successful_exit_code_with_output(): void
    {
        $runner = new CommandRunner;

        $result = $runner->runWithExitCode('echo ok', 5);

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame('ok', $result['output']);
    }

    #[Test]
    public function it_flags_a_timed_out_command_with_a_null_exit_code(): void
    {
        $runner = new CommandRunner;

        $result = $runner->runWithExitCode('sleep 3', 1);

        $this->assertTrue($result['timed_out']);
        $this->assertNull($result['exit_code']);
    }

    #[Test]
    public function it_detects_an_available_binary(): void
    {
        $runner = new CommandRunner;

        $this->assertTrue($runner->commandExists('sh'));
    }

    #[Test]
    public function it_detects_a_missing_binary(): void
    {
        $runner = new CommandRunner;

        $this->assertFalse($runner->commandExists('log-shipper-no-such-binary'));
    }

    #[Test]
    public function it_does_not_treat_a_shell_metacharacter_as_an_existing_binary(): void
    {
        $runner = new CommandRunner;

        $this->assertFalse($runner->commandExists('sh; echo pwned'));
    }

    #[Test]
    public function it_drains_stderr_so_chatty_commands_do_not_deadlock(): void
    {
        $runner = new CommandRunner;

        // Writes ~200KB to stderr (far beyond the 64KB pipe buffer) before
        // writing to stdout. If stderr is never drained the command blocks
        // forever and the timeout kills it, losing the stdout payload.
        $output = $runner->run("dd if=/dev/zero bs=1024 count=200 2>/dev/null | tr '\\0' 'e' >&2; echo done", 10);

        $this->assertSame('done', $output);
    }
}
