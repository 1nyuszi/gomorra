<?php
declare(strict_types=1);

namespace App\Adapter\Mcp;

/** Backward-compatible entry point; protocol handling lives in McpServer. */
final readonly class StdioServer
{
    public function __construct(private McpServer $server)
    {
    }

    public function run(): void
    {
        $this->server->runStdio();
    }

    public function dispatch(mixed $message): ?array
    {
        return $this->server->dispatch($message);
    }
}
