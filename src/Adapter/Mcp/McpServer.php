<?php
declare(strict_types=1);

namespace App\Adapter\Mcp;

use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/** Shared JSON-RPC dispatcher with STDIO and stateless Streamable HTTP listeners. */
#[AsController]
final class McpServer
{
    public const string PROTOCOL_VERSION = '2025-06-18';

    private bool $initialized = false;
    private bool $ready = false;

    /** @param list<string> $allowedOrigins */
    public function __construct(private readonly SchemaMcpTool $tools, private readonly array $allowedOrigins = [])
    {
    }

    public function runStdio(): void
    {
        while (($line = fgets(STDIN)) !== false) {
            $response = $this->handle($line);
            if ($response !== null) {
                fwrite(STDOUT, json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
                fflush(STDOUT);
            }
        }
    }

    public function handle(string $json, bool $stateless = false): ?array
    {
        try {
            $message = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->error(null, -32700, 'Parse error');
        }

        return $this->dispatch($message, $stateless);
    }

    public function dispatch(mixed $message, bool $stateless = false): ?array
    {
        if (!$message instanceof \stdClass || ($message->jsonrpc ?? null) !== '2.0') {
            return $this->error(null, -32600, 'Invalid Request');
        }
        $hasId = property_exists($message, 'id');
        $id = $message->id ?? null;
        if ($hasId && !is_string($id) && !is_int($id)) {
            return $this->error(null, -32600, 'Invalid request id');
        }
        // No server-initiated requests are made; valid client responses can be ignored.
        if (!property_exists($message, 'method') && $hasId) {
            $hasResult = property_exists($message, 'result');
            $hasError = property_exists($message, 'error');
            if ($hasResult !== $hasError && ($hasResult || (($message->error instanceof \stdClass) && is_int($message->error->code ?? null) && is_string($message->error->message ?? null)))) {
                return null;
            }
        }
        if (!is_string($message->method ?? null)) {
            return $this->error(null, -32600, 'Invalid Request');
        }
        if (property_exists($message, 'result') || property_exists($message, 'error')) {
            return $this->error($id, -32600, 'Invalid Request');
        }
        $params = property_exists($message, 'params') ? $message->params : new \stdClass();
        if (!$params instanceof \stdClass) {
            return $hasId ? $this->error($id, -32602, 'params must be an object') : null;
        }
        if (!$hasId) {
            if ($message->method === 'notifications/initialized' && $this->initialized && !$stateless) {
                $this->ready = true;
            }

            // Notifications never invoke mutating tools and never receive responses.
            return null;
        }

        try {
            if ($message->method === 'initialize') {
                if ((!$stateless && $this->initialized) || !is_string($params->protocolVersion ?? null) || $params->protocolVersion === '' || !($params->capabilities ?? null) instanceof \stdClass || !($params->clientInfo ?? null) instanceof \stdClass || !is_string($params->clientInfo->name ?? null) || !is_string($params->clientInfo->version ?? null)) {
                    return $this->error($id, -32602, 'Invalid initialization');
                }
                if (!$stateless) {
                    $this->initialized = true;
                }
                $result = [
                    'protocolVersion' => self::PROTOCOL_VERSION,
                    'capabilities' => ['tools' => new \stdClass()],
                    'serverInfo' => ['name' => 'gomorra', 'version' => '1.0.0'],
                ];
            } elseif ($message->method === 'ping') {
                $result = new \stdClass();
            } elseif (!$stateless && !$this->ready) {
                return $this->error($id, -32000, 'Complete initialization first');
            } elseif ($message->method === 'tools/list') {
                if (property_exists($params, 'cursor')) {
                    return $this->error($id, -32602, 'This server does not issue pagination cursors');
                }
                $result = ['tools' => $this->tools->definitions()];
            } elseif ($message->method === 'tools/call') {
                $method = match ($params->name ?? null) {
                    'create_dynamic_schema' => 'create',
                    'update_dynamic_schema' => 'update',
                    default => null,
                };
                if ($method === null) {
                    return $this->error($id, -32602, 'Unknown tool');
                }
                $arguments = property_exists($params, 'arguments') ? $params->arguments : new \stdClass();
                if (!$arguments instanceof \stdClass) {
                    return $this->error($id, -32602, 'arguments must be an object');
                }
                $result = $this->tools->execute(json_decode(json_encode($arguments, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR), $method);
            } else {
                return $this->error($id, -32601, 'Method not found');
            }

            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        } catch (\Throwable $error) {
            // error_log writes to stderr/server logs, never to the protocol output.
            error_log('MCP internal error: '.$error->getMessage());

            return $this->error($id, -32603, 'Internal error');
        }
    }

    #[Route('/mcp', name: 'mcp_server', methods: ['POST', 'GET', 'DELETE'], format: 'json')]
    public function handleHttp(Request $request): Response
    {
        $origin = $request->headers->get('Origin');
        if ($origin !== null && !in_array($origin, $this->allowedOrigins, true)) {
            return new JsonResponse(['error' => 'Origin is not allowed'], 403);
        }
        if (!$request->isMethod('POST')) {
            return new Response('', 405, ['Allow' => 'POST']);
        }
        if (strtolower(trim(explode(';', $request->headers->get('Content-Type', ''))[0])) !== 'application/json') {
            return new JsonResponse(['error' => 'Content-Type must be application/json'], 415);
        }
        $accept = AcceptHeader::fromString($request->headers->get('Accept', ''));
        foreach (['application/json', 'text/event-stream'] as $type) {
            if (!$accept->has($type) || $accept->get($type)->getQuality() <= 0) {
                return new JsonResponse(['error' => 'Accept must include application/json and text/event-stream'], 406);
            }
        }
        $json = $request->getContent();
        try {
            $message = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse($this->error(null, -32700, 'Parse error'), 400);
        }
        $version = $request->headers->get('MCP-Protocol-Version');
        $isInitialize = $message instanceof \stdClass && ($message->method ?? null) === 'initialize';
        if (($version !== null && $version !== self::PROTOCOL_VERSION) || ($version === null && !$isInitialize)) {
            return new JsonResponse($this->error(null, -32600, 'Unsupported or missing MCP-Protocol-Version'), 400);
        }
        // HTTP is stateless: no lifecycle state is shared between unrelated clients.
        $response = $this->dispatch($message, true);
        if ($response === null) {
            return new Response('', 202, ['Cache-Control' => 'no-store']);
        }
        $status = isset($response['error']) && in_array($response['error']['code'], [-32600, -32602], true) ? 400 : 200;

        return new JsonResponse($response, $status, ['Cache-Control' => 'no-store']);
    }

    private function error(string|int|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
