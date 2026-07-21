<?php
declare(strict_types=1);

namespace Uiskz\HttpClient;

use Psr\Log\LoggerInterface;
use Composer\InstalledVersions;

/**
 * Library for sending HTTP requests using curl
 * @author Dmitriy Gritsenko <dg@uis.kz>
 * @package Uiskz\HttpClient
 * @version 1.0.0
 */
class Client
{
    const string METHOD_GET = 'get';

    const string METHOD_POST = 'post';

    private string $version;

    private LoggerInterface|null $logger;

    const string COMPRESSION_GZIP = 'gzip';

    private string $lastRequest = '';

    private array $lastRequestHeaders = [];

    private string $lastResponse = '';

    private array $lastResponseHeaders = [];

    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        $this->version = InstalledVersions::getPrettyVersion('uiskz/http-client');
    }

    /**
     * Sends an HTTP request to the specified URL with the given parameters.
     *
     * @param string $url The endpoint URL to which the request should be sent.
     * @param array $params An array of request parameters, which may include:
     *                      - timeout (int): Connection timeout in seconds.
     *                      - method (string): HTTP method (e.g., 'get', 'post').
     *                      - auth (array): An array containing username and password for authentication.
     *                      - body (mixed): Request body data. Can be a string or array.
     *                      - json (array): JSON payload to be sent in the request body.
     *                      - compression (int): Compression type (e.g., COMPRESSION_GZIP).
     *                      - headers (array): Additional custom headers to include in the request.
     * @return Response A Response object containing the HTTP status code, headers, body, and potential error information.
     */
    public function sendRequest(string $url, array $params): Response
    {
        $response = new Response();
        $this->lastResponse = '';
        $this->lastResponseHeaders = [];

        if (empty($params['timeout'])) {
            $timeOut = 30;
        } else {
            $timeOut = (int)$params['timeout'];
        }

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HEADER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $timeOut);
        curl_setopt($curl, CURLOPT_TIMEOUT, 300);
        curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        if (empty($params['method'])) {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
        } else {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($params['method']));
        }

        if (!empty($params['auth'])) {
            curl_setopt($curl, CURLOPT_USERPWD, $params['auth'][0] . ':' .$params['auth'][1]);
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json; charset=utf-8',
            'User-Agent' => 'UIS HTTP Client ' . $this->version
        ];

        $body = '';
        if (!empty($params['method']) && self::METHOD_GET !== strtolower($params['method'])) {
            if (!empty($params['body'])) {
                $body = $params['body'];
                if (!is_string($params['body'])) {
                    $body = http_build_query($params['body']);
                }
            } elseif (!empty($params['json'])) {
                $body = json_encode($params['json']);
            }
            if (!empty($body)) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            }
            $headers['Content-Length'] = mb_strlen($body);
        }

        if (!empty($params['headers'])) {
            $headers = array_merge($headers, $params['headers']);
        }
        $this->lastRequest = $body;
        $this->lastRequestHeaders = $headers;

        if (!empty($params['compression']) && self::COMPRESSION_GZIP == $params['compression']) {
            curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
        }

        if (!empty($this->logger)) {
            $this->logger->debug("Параметры запроса:\nURL: $url\nЗаголовки: " . print_r($headers, true)
                . "\n" . print_r($body, true), [
                'method' => __METHOD__,
                'line' => __LINE__,
            ]);
        }

        $headers = $this->prepareHeaders($headers);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($curl);
        if (!empty($this->logger)) {
            $this->logger->debug("Ответ от хоста:\n" . print_r($result, true), [
                'method' => __METHOD__,
                'line' => __LINE__,
            ]);
        }

        $response->code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if (curl_errno($curl)) {
            $response->error = curl_error($curl);
            if (!empty($this->logger)) {
                $this->logger->error('Ошибка запроса: ' . curl_error($curl), [
                    'method' => __METHOD__,
                    'line' => __LINE__,
                ]);
            }
        } else {
            $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            $header = substr($result, 0, $header_size);
            $body = substr($result, $header_size);
            $response->headers = $this->parseHeaders($header);
            $response->body = $body;
            $this->lastResponse = $body;
            $this->lastResponseHeaders = $this->parseHeaders($header);

        }

        return $response;
    }

    /**
     * Method sends multiple simultaneous requests to the URLs provided in the array.
     * @param array $requests
     * @param array $customHeaders
     * @return Response[]
     */
    public function sendMultiRequest(array $requests, array $customHeaders = []): array
    {
        $responses = [];

        $curlHandles = [];
        $master = curl_multi_init();
        foreach ($requests as $i => $request) {
            if (empty($request['responseAttributes'])) {
                $request['responseAttributes'] = [
                    'url' => $request['url'],
                ];
            }
            $curlHandles[$i] = [
                'attributes' => $request['responseAttributes'],
                'handle' => $this->getCurlHandle($request, $customHeaders)
            ];
            curl_multi_add_handle($master, $curlHandles[$i]['handle']);
        }

        $running = 0;
        do {
            curl_multi_exec($master, $running);
            if ($running > 0) {
                curl_multi_select($master, 0.1);
            }
        } while ($running > 0);

        foreach ($curlHandles as $i => $curlHandle) {
            $handle = $curlHandle['handle'];
            $response = new Response();
            $response->code = curl_getinfo($handle, CURLINFO_HTTP_CODE);
            if (curl_errno($handle)) {
                $response->error = curl_error($handle);
                if (!empty($this->logger)) {
                    $this->logger->error('Ошибка запроса: ' . curl_error($handle), [
                        'method' => __METHOD__,
                        'line' => __LINE__,
                    ]);
                }
            } else {
                $result = curl_multi_getcontent($handle);
                $header_size = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
                $header = substr($result, 0, $header_size);
                $body = substr($result, $header_size);
                $response->headers = $this->parseHeaders($header);
                $response->body = $body;
            }
            $responses[$i] = [
                'attributes' => $curlHandle['attributes'],
                'response' => $response,
            ];
            curl_multi_remove_handle($master, $handle); // Remove the handle
        }

        curl_multi_close($master); // Close the multi handle

        return $responses;
    }

    protected function getCurlHandle(array $request, array $customHeaders = []): \CurlHandle|bool
    {
        $timeOut = 15;

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json; charset=utf-8',
            'User-Agent' => 'UIS HTTP Client ' . $this->version
        ];

        if (!empty($customHeaders)) {
            $headers = array_merge($headers, $customHeaders);
        }

        $ch = curl_init($request['url']);
        if ($ch) {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeOut);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
            $body = json_encode($request['json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $headers['Content-Length'] = mb_strlen($body);
            $headers = $this->prepareHeaders($headers);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        return $ch;
    }

    /**
     * Prepares headers for HTTP request by formatting them into an array of strings.
     *
     * @param array $headers Associative array of headers where keys are header names and values are header values.
     * @return array Array of formatted headers as strings.
     */
    protected function prepareHeaders(array $headers): array
    {
        $data = [];
        foreach ($headers as $header => $value) {
            $data[] = $header . ': ' . $value;
        }

        return $data;
    }

    /**
     * Parses the raw HTTP headers string into an associative array.
     *
     * @param string $headers The raw headers string to be parsed.
     *                        Each header should be separated by a newline character.
     * @return array An associative array of parsed headers where the keys are the header names
     *               and the values are the corresponding header values. If multiple 'Set-Cookie'
     *               headers are present, they are grouped as an array under the 'Set-Cookie' key.
     */
    protected function parseHeaders(string $headers): array
    {
        $data = [];
        $temp = explode("\n", $headers);
        array_shift($temp);
        foreach ($temp as $headerStr) {
            if (!empty($headerStr)) {
                $headerVal = explode(':', $headerStr, 2);
                if (count($headerVal) == 2) {
                    if (strtolower($headerVal[0]) == 'set-cookie') {
                        $data[$headerVal[0]][] = trim($headerVal[1]);
                    } else {
                        $data[$headerVal[0]] = trim($headerVal[1]);
                    }
                }
            }
        }

        return $data;
    }

    public function getLastRequest(): string
    {
        return $this->lastRequest;
    }

    public function getLastRequestHeaders(): array
    {
        return $this->lastRequestHeaders;
    }

    public function getLastResponse(): string
    {
        return $this->lastResponse;
    }

    public function getLastResponseHeaders(): array
    {
        return $this->lastResponseHeaders;
    }
}