<?php

declare(strict_types=1);

use TiborSrc\XaiSdkPhp\APIConnectionError;
use TiborSrc\XaiSdkPhp\SpaceXAI;

function serve(string $response, ?string &$request = null): string
{
    $script = tempnam(sys_get_temp_dir(), 'xai-sdk-');
    file_put_contents($script, <<<'PHP'
<?php
$server = stream_socket_server('tcp://127.0.0.1:0');
fwrite(STDOUT, stream_socket_get_name($server, false) . "\n");
fflush(STDOUT);
$conn = stream_socket_accept($server, 5);
$buf = '';
while (! str_contains($buf, "\r\n\r\n")) {
    $chunk = fread($conn, 8192);
    if ($chunk === false || $chunk === '') {
        break;
    }
    $buf .= $chunk;
}
file_put_contents($argv[2], $buf);
fwrite($conn, $argv[1]);
fclose($conn);
PHP);
    $body = tempnam(sys_get_temp_dir(), 'xai-req-');
    $proc = proc_open(
        [PHP_BINARY, $script, $response, $body],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    $address = trim((string) fgets($pipes[1]));
    register_shutdown_function(static function () use ($proc, $pipes, $script, $body, &$request): void {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($proc)) {
            proc_close($proc);
        }
        $request = is_file($body) ? (string) file_get_contents($body) : '';
        @unlink($script);
        @unlink($body);
    });

    return 'http://' . $address;
}

it('reads a json response through curl', function () {
    $payload = json_encode([
        'object' => 'list',
        'data' => [['id' => 'grok-4.6']],
    ], JSON_THROW_ON_ERROR);
    $request = null;
    $base = serve("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nx-request-id: req_curl\r\nContent-Length: " . strlen($payload) . "\r\nConnection: close\r\n\r\n" . $payload, $request);
    $client = new SpaceXAI([
        'apiKey' => 'secret-key',
        'baseURL' => $base,
        'maxRetries' => 0,
        'timeout' => 5000,
    ]);

    $page = $client->models->list();

    expect($page['data'][0]['id'])->toBe('grok-4.6');
    expect($page->http->requestId)->toBe('req_curl');
});

it('does not follow a curl redirect', function () {
    $base = serve("HTTP/1.1 307 Temporary Redirect\r\nLocation: http://127.0.0.1/nope\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    $client = new SpaceXAI([
        'apiKey' => 'k',
        'baseURL' => $base,
        'maxRetries' => 2,
        'timeout' => 5000,
    ]);

    expect(fn () => $client->models->list())->toThrow(APIConnectionError::class, 'redirect');
});
