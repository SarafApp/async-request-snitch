<?php

namespace Saraf;

use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Http\Middleware\RequestBodyParserMiddleware;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use Saraf\ResponseHandlers\HandlerEnum;

class JsonSnitchServer
{
    const BAD_HEADERS = [
        "Host",
        "User-Agent",
        "Accept",
        "X-Proxy-To",
        "X-Proxy-Config",
        'X-Forwarded-Host',
        'X-Forwarded-Port',
        'X-Forwarded-Proto',
        'X-Real-Ip',
        'X-Forwarded-Server',
        'X-Trace-Id'
    ];

    protected AsyncRequestJson $api;

    public function __construct()
    {
        $this->api = new AsyncRequestJson();
        $this->api->setResponseHandler(HandlerEnum::Basic);
    }

    public function start(string $host, string|int $port): void
    {
        $loop = Loop::get();
        $http = new HttpServer(
            new StreamingRequestMiddleware(),
            new LimitConcurrentRequestsMiddleware(100),
            new RequestBodyBufferMiddleware(16 * 1024 * 1024), // 16MB
            new RequestBodyParserMiddleware(),
            $this
        );

        $socket = new SocketServer($host . ':' . $port);
        $http->listen($socket);
        $loop->run();
    }

    public function __invoke(ServerRequestInterface $request): PromiseInterface|Response
    {
        $method = $request->getMethod();
        $headers = $request->getHeaders();

        if (!isset($headers['X-Proxy-To']))
            return new Response(451, ['Content-Type' => 'application/json'], json_encode([
                'result' => false,
                'error' => 'X-Proxy-To header is required'
            ]));

        $body = @$request->getBody()->getContents();
        if (
            isset($headers['Content-Type']) &&
            str_contains($headers['Content-Type'][0], 'application/json') &&
            ($method != 'GET' && $method != 'DELETE')
        ) {
            $body = json_decode($body, true);
            if (json_last_error() != JSON_ERROR_NONE)
                return new Response(451, ['Content-Type' => 'application/json'], json_encode([
                    'result' => false,
                    'error' => 'Body parser error'
                ]));
        }

        $query = @$request->getQueryParams() ?? [];

        $url = $headers['X-Proxy-To'][0];
        if (isset($headers['X-Proxy-Config'])) {
            $config = json_decode($headers['X-Proxy-Config'][0], true);
        } else {
            $config = [
                "followRedirects" => true,
                "timeout" => 5,
            ];
        }

        $headers = $this->cleanHeaders($headers);

        echo '---------------------' . PHP_EOL;
        echo $method . ' ' . $url . PHP_EOL;
        echo 'BODY IS: ' . json_encode($body, 128) . PHP_EOL;
        echo 'HEADER IS: ' . json_encode($headers, 128) . PHP_EOL;
        echo 'QUERY IS: ' . json_encode($query, 128) . PHP_EOL;
        echo '---------------------' . PHP_EOL . PHP_EOL;

        try {
            return $this->executeAPICall(
                $method,
                $url,
                ($method == 'GET' || $method == 'DELETE') ? $query : $body,
                $headers,
                $config
            );
        } catch (\Exception $e) {
            return new Response(451, ['Content-Type' => 'application/json'], json_encode([
                'result' => false,
                'error' => $e->getMessage()
            ]));
        }
    }

    /**
     * @throws \Exception
     */
    private function executeAPICall(
        string       $method,
        string       $url,
        string|array $body,
        array        $headers,
        array        $config
    ): PromiseInterface
    {
        $this->api->setConfig($config);
        $request = match ($method) {
            'GET' => $this->api->get($url, $body, $headers),
            'DELETE' => $this->api->delete($url, $body, $headers),
            'POST' => $this->api->post($url, $body, $headers),
            'PUT' => $this->api->put($url, $body, $headers),
            'PATCH' => $this->api->patch($url, $body, $headers)
        };

        return $request->then(function ($response) {
            if (!$response['result']){
                echo '--------Response Failed------' . PHP_EOL;
                echo 'Response Code: ' . json_encode(@$response['code'] ?? null);
                echo 'Response Body: ' . json_encode(@$response['body'] ?? null);
                echo 'Error Message: ' . json_encode(@$response['error'] ?? null);
                echo '----------------------' . PHP_EOL;

                return new Response(504, @$response['headers'] ?? [], @$response['body'] ?? '');
            }
                

            return new Response($response['code'], $response['headers'], $response['body']);
        });
    }

    private function cleanHeaders(array $headers): array
    {
        $cleanedHeaders = [];
        foreach ($headers as $headerName => $headerValue) {
            if (!in_array($headerName, self::BAD_HEADERS, true))
                $cleanedHeaders[$headerName] = $headerValue[0];
        }

        return $cleanedHeaders;
    }
}