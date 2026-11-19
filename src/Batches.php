<?php

declare(strict_types=1);

namespace Obens\Sdk;

class Batches extends TraceResource
{
    public function __construct(Http $http, callable $resolver)
    {
        parent::__construct($http, '/integration/batches', $resolver);
    }

    /** `$ownerId`: a sub-actor's platform id, for what a purchase from a farmer created. */
    public function get(string $externalId, ?string $ownerId = null): array
    {
        $query = $ownerId ? '?owner-id=' . rawurlencode($ownerId) : '';
        return $this->http->request('GET', $this->path . '/' . rawurlencode($externalId) . $query);
    }

    /** A page of batches in an element: data, page, size, total, totalPages, hasNext. `$search` matches name / ref. */
    public function list(string $elementId, ?string $ownerId = null, ?string $search = null, ?int $year = null, ?int $page = null, ?int $size = null): array
    {
        return $this->http->request('GET', $this->path . self::query([
            'element-id' => $elementId, 'owner-id' => $ownerId, 'search' => $search, 'year' => $year, 'page' => $page, 'size' => $size,
        ]));
    }

    /** What the batch's form holds: id, staticId, name, type, value (in the shape `data` accepts). */
    public function data(string $externalId, ?string $ownerId = null): array
    {
        return $this->http->request('GET', $this->path . '/' . rawurlencode($externalId) . '/data' . self::query(['owner-id' => $ownerId]));
    }

    /** The fields of a production method's form: the names `data` accepts. */
    public function schema(string $productionMethodId): array
    {
        return $this->http->request('GET', $this->path . '/schema?production-method-id=' . rawurlencode($productionMethodId));
    }
}
