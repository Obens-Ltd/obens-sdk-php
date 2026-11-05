<?php

declare(strict_types=1);

namespace Obens\Sdk;

/**
 * curl wrapper: api key header, JSON, timeout and retries with backoff on transient failures.
 *
 * `$transport` replaces curl (used by the tests): fn(string $method, string $url, array $headers, ?string $body,
 * float $timeout): array{0:int,1:string,2:array<string,string>} returning [status, body, lowercase headers].
 */
class Http
{
    private const RETRYABLE_STATUS = [408, 425, 429, 500, 502, 503, 504];

    private string $baseUrl;
    /** @var callable|null */
    private $transport;

    public function __construct(
        string $baseUrl,
        private string $apiKey,
        private float $timeout = 30.0,
        private int $maxRetries = 3,
        private float $retryDelay = 0.5,
        ?callable $transport = null,
        private ?string $accountId = null
    ) {
        if ($baseUrl === '') {
            throw new \InvalidArgumentException('baseUrl is required');
        }
        if ($apiKey === '') {
            throw new \InvalidArgumentException('apiKey is required');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport;
    }

    public function request(string $method, string $path, mixed $body = null): mixed
    {
        // a delegated account to act on, instead of the key's own
        if ($this->accountId) {
            $path .= (str_contains($path, '?') ? '&' : '?') . 'account-id=' . rawurlencode($this->accountId);
        }
        $payload = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $attempt = 0;
        while (true) {
            try {
                [$status, $text, $headers] = $this->send($method, $this->baseUrl . $path, $payload);
            } catch (\RuntimeException $e) {
                // network error or timeout: safe to repeat because every call is an upsert
                if ($attempt >= $this->maxRetries) {
                    throw $e;
                }
                $this->sleep($this->delay($attempt));
                $attempt++;
                continue;
            }
            if ($status >= 200 && $status < 300) {
                return $text === '' ? null : json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            }
            if (in_array($status, self::RETRYABLE_STATUS, true) && $attempt < $this->maxRetries) {
                $this->sleep($this->delay($attempt, $headers['retry-after'] ?? null));
                $attempt++;
                continue;
            }
            $parsed = json_decode($text, true);
            throw new ObensApiError($status, json_last_error() === JSON_ERROR_NONE ? $parsed : $text, $method, $path);
        }
    }

    /** @return array{0:int,1:string,2:array<string,string>} */
    private function send(string $method, string $url, ?string $payload): array
    {
        $headers = ['X-API-KEY: ' . $this->apiKey, 'Accept: application/json'];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($this->transport) {
            return ($this->transport)($method, $url, $headers, $payload, $this->timeout);
        }

        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $text = curl_exec($ch);
        if ($text === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("$method $url failed: $error");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, (string) $text, $responseHeaders];
    }

    private function delay(int $attempt, ?string $retryAfter = null): float
    {
        $seconds = $retryAfter !== null && is_numeric($retryAfter) ? (float) $retryAfter : 0.0;
        return $seconds > 0 ? $seconds : $this->retryDelay * (2 ** $attempt);
    }

    private function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) ($seconds * 1_000_000));
        }
    }
}
