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
        if (!empty($params['method']) && self::METHOD_POST === strtolower($params['method'])) {
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