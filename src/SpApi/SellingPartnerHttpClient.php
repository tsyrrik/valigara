<?php

namespace App\SpApi;

use RuntimeException;

/**
 * Minimal Selling Partner API HTTP client capable of signing requests with AWS Signature Version 4.
 */
class SellingPartnerHttpClient
{
    private string $endpoint;
    private string $region;
    private string $accessKeyId;
    private string $secretAccessKey;
    private ?string $securityToken;
    private LwaAccessTokenProvider $tokenProvider;
    private string $userAgent;

    public function __construct(
        string $endpoint,
        string $region,
        string $accessKeyId,
        string $secretAccessKey,
        LwaAccessTokenProvider $tokenProvider,
        ?string $securityToken = null,
        string $userAgent = 'ValigaraTestAssignment/1.0 (+https://valigara.com)'
    ) {
        $this->endpoint = $endpoint;
        $this->region = $region;
        $this->accessKeyId = $accessKeyId;
        $this->secretAccessKey = $secretAccessKey;
        $this->tokenProvider = $tokenProvider;
        $this->securityToken = $securityToken;
        $this->userAgent = $userAgent;
    }

    /**
     * Sends a request to the Selling Partner API and returns the decoded JSON response.
     *
     * @return array<string,mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $method = strtoupper($method);
        $queryString = $this->buildCanonicalQuery($query);
        $bodyString = $body === null ? '' : (string)json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($body !== null && $bodyString === '') {
            throw new RuntimeException('Unable to encode request body as JSON');
        }

        $accessToken = $this->tokenProvider->getAccessToken();
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        $headers = [
            'content-type' => 'application/json; charset=utf-8',
            'host' => $this->endpoint,
            'x-amz-date' => $amzDate,
            'x-amz-access-token' => $accessToken,
            'user-agent' => $this->userAgent,
        ];

        if ($this->securityToken !== null) {
            $headers['x-amz-security-token'] = $this->securityToken;
        }

        if ($bodyString === '' && $method === 'GET') {
            unset($headers['content-type']);
        }

        ksort($headers);

        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= strtolower($name) . ':' . trim((string)$value) . "\n";
        }

        $signedHeaders = implode(';', array_keys($headers));
        $payloadHash = hash('sha256', $bodyString);

        $canonicalRequest = implode("\n", [
            $method,
            $this->normalizePath($path),
            $queryString,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = $dateStamp . '/' . $this->region . '/execute-api/aws4_request';
        $stringToSign = implode("\n", [
            $algorithm,
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->getSigningKey($dateStamp);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorizationHeader = $algorithm . ' Credential=' . $this->accessKeyId . '/' . $credentialScope .
            ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

        $headers['authorization'] = $authorizationHeader;

        $requestHeaders = [];
        foreach ($headers as $name => $value) {
            $requestHeaders[] = $this->formatHeaderName($name) . ': ' . $value;
        }

        $url = 'https://' . $this->endpoint . $this->normalizePath($path);
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize curl for SP-API request');
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => '',
        ];

        if ($bodyString !== '' && $method !== 'GET') {
            $options[CURLOPT_POSTFIELDS] = $bodyString;
        }

        curl_setopt_array($curl, $options);

        $rawResponse = curl_exec($curl);
        if ($rawResponse === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException('Selling Partner API request failed: ' . $error);
        }

        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        curl_close($curl);

        $rawHeaders = substr($rawResponse, 0, $headerSize);
        $rawBody = substr($rawResponse, $headerSize);

        $decodedBody = null;
        if ($rawBody !== '') {
            $decodedBody = json_decode($rawBody, true);
        }

        if ($status < 200 || $status >= 300) {
            $errorMessage = 'HTTP ' . $status;
            if (is_array($decodedBody) && isset($decodedBody['errors'][0]['message'])) {
                $errorMessage .= ': ' . $decodedBody['errors'][0]['message'];
            }
            throw new RuntimeException('Selling Partner API returned an error: ' . $errorMessage);
        }

        return [
            'status' => $status,
            'headers' => $this->parseHeaders($rawHeaders),
            'body' => $decodedBody,
            'raw_body' => $rawBody,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $rawHeaders) ?: [];
        $headers = [];
        foreach ($lines as $line) {
            if ($line === '' || strncasecmp($line, 'HTTP/', 5) === 0) {
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));
            $headers[$name] = $value;
        }

        return $headers;
    }

    private function buildCanonicalQuery(array $query): string
    {
        if ($query === []) {
            return '';
        }

        $pieces = [];
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                foreach ($this->normalizeMultiValue($key, $value) as $part) {
                    $pieces[] = $part;
                }
                continue;
            }
            $pieces[] = rawurlencode((string)$key) . '=' . rawurlencode($this->normalizeScalarValue($value));
        }

        sort($pieces);

        return implode('&', $pieces);
    }

    private function normalizeMultiValue(string $key, array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $normalized[] = rawurlencode($key) . '=' . rawurlencode($this->normalizeScalarValue($value));
        }
        sort($normalized);

        return $normalized;
    }

    private function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }
        if ($path[0] !== '/') {
            return '/' . $path;
        }

        return $path;
    }

    private function getSigningKey(string $dateStamp)
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretAccessKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 'execute-api', $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    private function formatHeaderName(string $name): string
    {
        $parts = explode('-', $name);
        $parts = array_map(static fn(string $part): string => ucfirst(strtolower($part)), $parts);

        return implode('-', $parts);
    }

    private function normalizeScalarValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
        }

        return (string)$value;
    }
}
