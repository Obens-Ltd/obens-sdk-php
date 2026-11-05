<?php

declare(strict_types=1);

namespace Obens\Sdk;

class Mappings
{
    public function __construct(private Http $http)
    {
    }

    public function list(string $type): array
    {
        return $this->http->request('GET', "/integration/mappings/$type");
    }

    public function toInternal(string $type, string $externalId): array
    {
        return $this->http->request('GET', "/integration/mappings/$type/external/" . rawurlencode($externalId));
    }

    public function toExternal(string $type, string $id): array
    {
        return $this->http->request('GET', "/integration/mappings/$type/internal/" . rawurlencode($id));
    }
}
