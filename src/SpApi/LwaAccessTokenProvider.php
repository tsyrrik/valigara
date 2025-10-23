<?php

namespace App\SpApi;

use RuntimeException;

/**
 * Lightweight Login With Amazon (LWA) token provider that exchanges a refresh token for an access token.
 */
class LwaAccessTokenProvider
{
    private string $clientId;
    private string $clientSecret;
    private string $refreshToken;
    private string $endpoint;
    private ?string $accessToken = null;
    private int $expiresAt = 0;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $refreshToken,
        string $endpoint = 'https://api.amazon.com/auth/o2/token'
    ) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->refreshToken = $refreshToken;
        $this->endpoint = $endpoint;
    }

    /**
     * Returns a cached access token or fetches a new one when the current token is missing or expired.
     */
    public function getAccessToken(): string
    {
        if ($this->accessToken !== null && $this->expiresAt > time() + 60) {
            return $this->accessToken;
        }

        $payload = http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        $ch = curl_init($this->endpoint);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize curl while requesting LWA token');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $rawResponse = curl_exec($ch);
        if ($rawResponse === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Unable to request LWA token: ' . $error);
        }

        $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid response received from LWA token endpoint');
        }

        if ($statusCode !== 200) {
            $message = $decoded['error_description'] ?? $decoded['error'] ?? 'Unknown error';
            throw new RuntimeException('LWA token request failed: ' . $message);
        }

        $token = $decoded['access_token'] ?? null;
        $expiresIn = isset($decoded['expires_in']) ? (int)$decoded['expires_in'] : 0;

        if (!is_string($token) || $token === '' || $expiresIn <= 0) {
            throw new RuntimeException('Unexpected LWA token response: missing access token or expiration');
        }

        $this->accessToken = $token;
        $this->expiresAt = time() + $expiresIn;

        return $this->accessToken;
    }
}

