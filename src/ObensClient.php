<?php

declare(strict_types=1);

namespace Obens\Sdk;

class ObensClient
{
    public const VERSION = '0.1.4';

    public readonly Resource $subActors;
    public readonly Resource $facilities;
    public readonly Accounts $accounts;
    public readonly Mappings $mappings;
    public readonly Batches $batches;
    public readonly Transactions $transactions;
    private Http $http;
    private ?string $workspaceId = null;
    /** @var array<string,string> */
    private array $ids = [];

    /**
     * `$baseUrl` is the core api, e.g. `https://api.obens.so/v2/core`.
     * `$transport` replaces curl (see Http), mainly for tests.
     * `$traceUrl` is the trace api that holds batches and transactions; it defaults to `$baseUrl` with `/v2/core`
     * swapped for `/v2/trace`. Creations are heavy and the server waits for them, hence `$traceTimeout`.
     */
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private float $timeout = 30.0,
        private int $maxRetries = 3,
        private float $retryDelay = 0.5,
        private $transport = null,
        ?string $accountId = null,
        private ?string $traceUrl = null,
        private float $traceTimeout = 90.0
    ) {
        $this->http = new Http($baseUrl, $apiKey, $timeout, $maxRetries, $retryDelay, $transport, $accountId);
        $trace = new Http(
            $traceUrl ?? preg_replace('#/v2/core/*$#', '/v2/trace', $baseUrl),
            $apiKey, $traceTimeout, $maxRetries, $retryDelay, $transport, $accountId,
            fn(): array => ['X-WORKSPACE-ID' => $this->workspace()]
        );
        $this->batches = new Batches($trace, fn(array $push): array => $this->resolve($push, false));
        $this->transactions = new Transactions($trace, fn(array $push): array => $this->resolve($push, true));
        $this->subActors = new Resource($this->http, '/integration/sub-actors');
        $this->facilities = new Resource($this->http, '/integration/facilities');
        $this->accounts = new Accounts($this->http);
        $this->mappings = new Mappings($this->http);
    }

    /**
     * A client acting as an account delegated to the key's account (see accounts->accessible()): farmers,
     * facilities, links and id tables are then those of that account. Its ids are separate from the key account's.
     */
    public function forAccount(string $accountId): static
    {
        return new static($this->baseUrl, $this->apiKey, $this->timeout, $this->maxRetries, $this->retryDelay, $this->transport, $accountId, $this->traceUrl, $this->traceTimeout);
    }

    /** The key's workspace: the trace api is routed by it, and core knows it from the key. */
    private function workspace(): string
    {
        return $this->workspaceId ??= (string) $this->whoami()['workspaceId'];
    }

    private function internal(string $type, string $externalId): string
    {
        return $this->ids["$type:$externalId"] ??= (string) $this->mappings->toInternal($type, $externalId)['id'];
    }

    /**
     * Replaces your own ids in a transaction with platform ids: `subActorExternalId` -> `subActorId`,
     * `supplierExternalId` / `clientExternalId` -> `supplierId` / `clientId` (linked accounts) and
     * `collected.facilityExternalIds` -> `collected.facilityIds`.
     */
    private function resolve(array $push, bool $transaction): array
    {
        if (!$transaction) {
            return $push;
        }
        if (isset($push['subActorExternalId'])) {
            $push['subActorId'] = $this->internal('SUB_ACTOR', (string) $push['subActorExternalId']);
            unset($push['subActorExternalId']);
        }
        foreach (['supplier', 'client'] as $role) {
            if (isset($push[$role . 'ExternalId'])) {
                $push[$role . 'Id'] = $this->internal('ACCOUNT', (string) $push[$role . 'ExternalId']);
                unset($push[$role . 'ExternalId']);
            }
        }
        if (isset($push['collected']['facilityExternalIds'])) {
            $push['collected']['facilityIds'] = array_map(
                fn($f) => $this->internal('FACILITY', (string) $f),
                $push['collected']['facilityExternalIds']
            );
            unset($push['collected']['facilityExternalIds']);
        }
        return $push;
    }

    /** Who the key is: keyId, keyName, accountId, workspaceId. Handy as a connection check. */
    public function whoami(): array
    {
        return $this->http->request('GET', '/integration/whoami');
    }

    /** The lists farmers can be created in: id, name, path. */
    public function elements(): array
    {
        return $this->http->request('GET', '/integration/elements');
    }

    /** Custom fields of the farmers in a list. Use a field's name (or staticId) as a key of `data`. */
    public function subActorSchema(string $elementId): array
    {
        return $this->http->request('GET', '/integration/sub-actors/schema?element-id=' . rawurlencode($elementId));
    }

    /** Id table for SUB_ACTOR | FACILITY | ACCOUNT. Call ->load() to fill it. */
    public function idMap(string $type): IdMap
    {
        return new IdMap($this, $type);
    }

    /**
     * Initial load + push-on-change for "sub_actors" or "facilities".
     *
     * @param callable(mixed):array $map
     * @param (callable(SyncReport):void)|null $onProgress
     */
    public function syncer(string $kind, callable $map, int $chunkSize = 200, ?callable $onProgress = null): Syncer
    {
        $resource = match ($kind) {
            'sub_actors' => $this->subActors,
            'facilities' => $this->facilities,
            default => throw new \InvalidArgumentException("kind must be 'sub_actors' or 'facilities'"),
        };
        return new Syncer($resource, $kind, $map, $chunkSize, $onProgress);
    }
}
