<?php

declare(strict_types=1);
namespace Uiskz\HttpClient;

/**
 * Library for sending HTTP requests using curl
 * @author Dmitriy Gritsenko <dg@uis.kz>
 * @package Uiskz\HttpClient
 * @version 1.0.0
 */
class Response
{
    public int $code = 200;

    public string $response = '';

    public string $body = '';

    public array $headers = [];

    public string $error = '';

    /**
     * Method returns header value by header name. Returns null if the header is not found.
     */
    public function getHeader(string $header): ?string
    {
        foreach ($this->headers as $headerID => $value) {
            if (strtolower($headerID) == strtolower($header)) {
                return $value;
            }
        }

        return null;
    }
}