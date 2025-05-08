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
    protected string $token;
    protected bool $isProtected = false;

    public function __construct(
        ?string $username = null,
        ?string $password = null
    )
    {
        $this->api = new AsyncRequestJson();
        $this->api->setResponseHandler(HandlerEnum::Basic);

        if ($username != null && $password != null) {
            $this->token = base64_encode($username . ':' . $password);
            $this->isProtected = true;
        }
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

        if ($this->isProtected) {
            if (!isset($headers['Authorization']) || !$this->isAuth($headers['Authorization'][0])) {
                return new Response(401, ['Content-Type' => 'application/json'], json_encode([
                    'result' => false,
                    'error' => 'Unauthorized: Basic Authentication required'
                ]));
            }
        }

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

    private function isAuth(string $authHeader): bool
    {
        // Check if protection is enabled or not
        // if user don't provide username or password we don't even try to check the Basic auth and every request is valid
        if (!$this->isProtected) {
            return true;
        }

        $explodedAuthHeader = explode(" ", $authHeader);
        if (count($explodedAuthHeader) != 2) {
            return false;
        }

        $providedToken = $explodedAuthHeader[1];

        if ($providedToken != $this->token) {
            return false;
        }

        return true;
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
            if (!$response['result']) {
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