<?php

declare(strict_types=1);

namespace Obens\Sdk;

/**
 * Batches or transactions, which live in the trace api (a second base url, the workspace id as a header).
 * `$resolver` turns the client's own ids in a push into platform ids.
 */
class TraceResource
{
    /** @var callable(array):array */
    private $resolver;

    public function __construct(protected Http $http, protected string $path, callable $resolver)
    {
        $this->resolver = $resolver;
    }

    /** `?a=1&b=2` from the pairs, skipping null and empty values. */
    protected static function query(array $pairs): string
    {
        $parts = [];
        foreach ($pairs as $name => $value) {
            if ($value !== null && $value !== '') {
                $parts[] = $name . '=' . rawurlencode((string) $value);
            }
        }
        return $parts ? '?' . implode('&', $parts) : '';
    }

    /**
     * Creates the record the first time `externalId` is seen, updates it afterwards. Safe to repeat.
     * Creation waits for the platform to finish, so the call can take several seconds.
     */
    public function upsert(array $push): array
    {
        if (empty($push['externalId'])) {
            throw new \InvalidArgumentException('externalId is required');
        }
        $body = ($this->resolver)($push);
        $externalId = (string) $body['externalId'];
        unset($body['externalId']);
        return $this->http->request('PUT', $this->path . '/' . rawurlencode($externalId), $body);
    }

    /**
     * Upserts many, one after the other (each creation is a heavy write). One result per item; a failure does not
     * stop the rest.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public function bulk(array $items): array
    {
        $results = [];
        foreach ($items as $item) {
            try {
                $results[] = $this->upsert($item);
            } catch (\Throwable $e) {
                $results[] = ['externalId' => $item['externalId'] ?? null, 'id' => null, 'outcome' => 'FAILED', 'error' => $e->getMessage()];
            }
        }
        return $results;
    }
}
