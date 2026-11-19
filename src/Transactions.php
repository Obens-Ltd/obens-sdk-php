<?php

declare(strict_types=1);

namespace Obens\Sdk;

class Transactions extends TraceResource
{
    public function __construct(Http $http, callable $resolver)
    {
        parent::__construct($http, '/integration/transactions', $resolver);
    }

    /** `$supplierId`: a sub-actor's platform id, for a purchase from a farmer (default: the key's account). */
    public function get(string $externalId, ?string $supplierId = null): array
    {
        $query = $supplierId ? '?supplier-id=' . rawurlencode($supplierId) : '';
        return $this->http->request('GET', $this->path . '/' . rawurlencode($externalId) . $query);
    }

    /**
     * A page of the account's transactions in an element, as buyer or seller. `$type`: BUY | SELL;
     * `$start` / `$end`: 2026-01-31; `$otherAccountId`: the counterparty's platform id.
     */
    public function list(string $elementId, ?string $otherAccountId = null, ?string $type = null, ?string $start = null, ?string $end = null, ?string $search = null, ?int $page = null, ?int $size = null): array
    {
        return $this->http->request('GET', $this->path . self::query([
            'element-id' => $elementId, 'other-account-id' => $otherAccountId, 'type' => $type, 'start' => $start,
            'end' => $end, 'search' => $search, 'page' => $page, 'size' => $size,
        ]));
    }

    /** What the transaction's form holds: id, staticId, name, type, value. */
    public function data(string $externalId, ?string $supplierId = null): array
    {
        return $this->http->request('GET', $this->path . '/' . rawurlencode($externalId) . '/data' . self::query(['supplier-id' => $supplierId]));
    }

    /** The fields of the transaction form an account (the buyer, default: the key's) has for an element. */
    public function schema(string $elementId, ?string $clientId = null): array
    {
        $query = '?element-id=' . rawurlencode($elementId) . ($clientId ? '&client-id=' . rawurlencode($clientId) : '');
        return $this->http->request('GET', $this->path . '/schema' . $query);
    }
}
