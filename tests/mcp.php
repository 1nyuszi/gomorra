<?php
declare(strict_types=1);

// Included by run.php with its isolated MySQL database and registry.
use App\Adapter\Mcp\McpServer;
use App\Adapter\Mcp\StdioServer;
use Symfony\Component\HttpFoundation\Request;

$rpc = static fn (string $method, array $params = [], string|int $id = 0): array => [
    'jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params === [] ? new stdClass() : $params,
];
$call = static fn (array $frame): ?array => $server->handle(json_encode($frame, JSON_THROW_ON_ERROR));
check($server->handle('{')['error']['code'] === -32700, 'MCP parse error');
foreach (['null', '[]', '1', '"x"', '{"jsonrpc":"1.0","id":1,"method":"ping"}', '{"jsonrpc":"2.0","id":null,"method":"ping"}'] as $invalidFrame) {
    check($server->handle($invalidFrame)['error']['code'] === -32600, 'MCP invalid envelope');
}
check($call($rpc('ping', id: 'string-id'))['id'] === 'string-id', 'Preserve string request id');
check($call($rpc('ping'))['id'] === 0, 'Preserve zero request id');
check($call($rpc('missing'))['error']['code'] === -32601, 'Unknown JSON-RPC method');
check($call($rpc('tools/call', ['name' => 'missing']))['error']['code'] === -32602, 'Unknown tool');
check($call($rpc('tools/call', ['name' => 'create_dynamic_schema', 'arguments' => []]))['error']['code'] === -32602, 'Reject list tool arguments');
check($call($rpc('tools/call', ['name' => 'create_dynamic_schema', 'arguments' => new stdClass()]))['result']['isError'] === true, 'Missing arguments are tool validation failures');
check($call($rpc('tools/list', ['cursor' => 'unknown']))['error']['code'] === -32602, 'Reject invalid cursor');
check($server->handle('{"jsonrpc":"2.0","id":1,"method":"ping","params":null}')['error']['code'] === -32602, 'Reject explicit null params');
check($server->handle('{"jsonrpc":"2.0","id":1,"result":{}}') === null, 'Ignore client responses');
check($server->handle('{"jsonrpc":"2.0","method":"missing"}') === null, 'Ignore unknown notifications');
check($server->handle(json_encode($init, JSON_THROW_ON_ERROR))['error']['code'] === -32602, 'Reject repeat STDIO initialization');
$freshServer = new McpServer($tool);
$invalidInit = $init;
$invalidInit['params']['clientInfo'] = new stdClass();
check($freshServer->handle(json_encode($invalidInit, JSON_THROW_ON_ERROR))['error']['code'] === -32602, 'Initialization requires client information');
check($freshServer->handle(json_encode($init, JSON_THROW_ON_ERROR))['result']['protocolVersion'] === McpServer::PROTOCOL_VERSION, 'Valid initialization after invalid attempt');
check($freshServer->handle(json_encode($rpc('tools/list'), JSON_THROW_ON_ERROR))['error']['code'] === -32000, 'Tools need initialized notification');
check((new StdioServer($server))->dispatch($message($rpc('ping')))['result'] instanceof stdClass, 'Compatibility wrapper delegates');

$httpHeaders = [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json, text/event-stream',
    'HTTP_MCP_PROTOCOL_VERSION' => McpServer::PROTOCOL_VERSION,
];
$http = static function (array|string $frame, array $headers = [], string $method = 'POST') use ($kernel, $httpHeaders): \Symfony\Component\HttpFoundation\Response {
    $request = Request::create('/mcp', $method, server: array_replace($httpHeaders, $headers), content: is_string($frame) ? $frame : json_encode($frame, JSON_THROW_ON_ERROR));

    return $kernel->handle($request);
};
$decode = static fn (\Symfony\Component\HttpFoundation\Response $response): array => json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
$response = $http($init, ['HTTP_MCP_PROTOCOL_VERSION' => null]);
check($response->getStatusCode() === 200 && $decode($response)['result']['protocolVersion'] === McpServer::PROTOCOL_VERSION, 'HTTP initialization without version header');
check(!$response->headers->has('Mcp-Session-Id'), 'HTTP does not advertise sessions');
check($http(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->getStatusCode() === 202, 'HTTP notification accepted');
check($http(['jsonrpc' => '2.0', 'id' => 7, 'result' => new stdClass()])->getContent() === '', 'Client responses receive empty body');
$response = $http($rpc('tools/list'));
check($response->getStatusCode() === 200 && count($decode($response)['result']['tools']) === 2, 'HTTP tool discovery');
check($decode($response)['result']['tools'][1]['name'] === 'update_dynamic_schema', 'Update tool advertised');

$httpInput = $input;
$httpInput['name'] = $prefix.'HttpMcpCustomer';
$tables[] = $database->tableName($httpInput['name']);
$response = $http($rpc('tools/call', ['name' => 'create_dynamic_schema', 'arguments' => $httpInput], 'create'));
check($response->getStatusCode() === 200 && $decode($response)['result']['isError'] === false, 'HTTP MCP create');
$httpTable = $database->tableName($httpInput['name']);
$pdo->prepare('INSERT INTO `'.$httpTable.'` (`email`) VALUES (?)')->execute(['mcp@example.com']);
$httpInput['schema']['properties']['mcp_added'] = ['type' => 'string'];
$response = $http($rpc('tools/call', ['name' => 'update_dynamic_schema', 'arguments' => $httpInput], 'update'));
check($decode($response)['result']['structuredContent']['schema']['properties']['mcp_added']['type'] === 'string', 'HTTP MCP update');
check($pdo->query('SELECT `email` FROM `'.$httpTable.'`')->fetchColumn() === 'mcp@example.com', 'HTTP MCP update preserves rows');
$badHttpUpdate = $httpInput;
unset($badHttpUpdate['schema']['properties']['age']);
$response = $http($rpc('tools/call', ['name' => 'update_dynamic_schema', 'arguments' => $badHttpUpdate]));
check($response->getStatusCode() === 200 && $decode($response)['result']['isError'] === true, 'Domain failures use tool error results over HTTP');
check($http($rpc('missing'))->getStatusCode() === 200, 'Unknown RPC method returns JSON-RPC response');
check($decode($http($rpc('tools/call', ['name' => 'unknown'])))['error']['code'] === -32602, 'Unknown HTTP tool');
check($http('{')->getStatusCode() === 400, 'HTTP parse error');
check($http('[]')->getStatusCode() === 400, 'HTTP batches are unsupported');
check($http($rpc('ping'), ['CONTENT_TYPE' => 'text/plain'])->getStatusCode() === 415, 'HTTP content type validation');
check($http($rpc('ping'), ['HTTP_ACCEPT' => 'application/json'])->getStatusCode() === 406, 'HTTP Accept validation');
check($http($rpc('ping'), ['HTTP_ACCEPT' => 'application/json, text/event-stream;q=0'])->getStatusCode() === 406, 'HTTP excludes unacceptable SSE');
check($http($rpc('ping'), ['HTTP_MCP_PROTOCOL_VERSION' => 'unsupported'])->getStatusCode() === 400, 'HTTP protocol validation');
check($http($rpc('ping'), ['HTTP_MCP_PROTOCOL_VERSION' => null])->getStatusCode() === 400, 'HTTP version required after initialization');
check($http($rpc('ping'), ['HTTP_ORIGIN' => 'https://untrusted.example'])->getStatusCode() === 403, 'HTTP Origin rejection');
check($http($rpc('ping'), ['HTTP_ORIGIN' => 'http://localhost:8000'])->getStatusCode() === 200, 'HTTP allowed Origin');
foreach (['GET', 'DELETE'] as $method) {
    $response = $http('', method: $method);
    check($response->getStatusCode() === 405 && $response->headers->get('Allow') === 'POST', 'HTTP optional streaming/sessions disabled');
}
$notificationInput = $httpInput;
$notificationInput['name'] = $prefix.'NotificationMustNotCreate';
check($http(['jsonrpc' => '2.0', 'method' => 'tools/call', 'params' => ['name' => 'create_dynamic_schema', 'arguments' => $notificationInput]])->getStatusCode() === 202, 'Tool notification receives no response');
check($repository->find($notificationInput['name']) === null, 'Notifications must not mutate schemas');

// Infrastructure failures are sanitized, while full details stay in server logs.
$registryContents = file_get_contents($storage);
file_put_contents($storage, '{');
try {
    $response = $http($rpc('tools/call', ['name' => 'create_dynamic_schema', 'arguments' => $httpInput]));
    check($decode($response)['error'] === ['code' => -32603, 'message' => 'Internal error'], 'Sanitized infrastructure failures');
} finally {
    file_put_contents($storage, $registryContents);
}
