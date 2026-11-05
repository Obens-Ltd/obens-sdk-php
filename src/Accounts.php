<?php

declare(strict_types=1);

namespace Obens\Sdk;

class Accounts
{
    public function __construct(private Http $http)
    {
    }

    /** The key's own account and the accounts delegated to it: id, name, self. */
    public function accessible(): array
    {
        return $this->http->request('GET', '/integration/accounts');
    }

    /** Links your id to an account that already exists on the platform. */
    public function link(string $externalId, string $accountId): array
    {
        return $this->http->request('PUT', '/integration/accounts/' . rawurlencode($externalId), ['accountId' => $accountId]);
    }

    public function unlink(string $externalId): void
    {
        $this->http->request('DELETE', '/integration/accounts/' . rawurlencode($externalId));
    }
}
