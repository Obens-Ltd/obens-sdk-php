<?php

declare(strict_types=1);

namespace Obens\Sdk;

class Resource
{
    public const MAX_BULK = 500;

    public function __construct(private Http $http, private string $path)
    {
    }

    /** Creates the record the first time `externalId` is seen, updates it afterwards. Safe to repeat. */
    public function upsert(array $push): array
    {
        if (empty($push['externalId'])) {
            throw new \InvalidArgumentException('externalId is required');
        }
        return $this->http->request('PUT', $this->path . '/' . rawurlencode((string) $push['externalId']), $push);
    }

    /**
     * Upserts many in requests of at most 500. One result per item, in order, failures included.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public function bulk(array $items, int $chunkSize = self::MAX_BULK): array
    {
        $size = max(1, min($chunkSize, self::MAX_BULK));
        $results = [];
        foreach (array_chunk($items, $size) as $chunk) {
            $results = array_merge($results, $this->http->request('POST', $this->path . '/bulk', ['items' => $chunk]));
        }
        return $results;
    }

    /** `$subActor`: the external id of the farmer a facility belongs to (facility ids are unique per owner). */
    public function get(string $externalId, ?string $subActor = null): array
    {
        $query = $subActor ? '?sub-actor=' . rawurlencode($subActor) : '';
        return $this->http->request('GET', $this->path . '/' . rawurlencode($externalId) . $query);
    }
}
