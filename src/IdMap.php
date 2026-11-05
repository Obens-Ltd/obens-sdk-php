<?php

declare(strict_types=1);

namespace Obens\Sdk;

/** Your id <-> platform id for one kind (SUB_ACTOR, FACILITY or ACCOUNT), cached after load(). */
class IdMap
{
    /** @var array<string,string> */
    private array $toId = [];
    /** @var array<string,string> */
    private array $toExternal = [];

    public function __construct(private ObensClient $client, public readonly string $type)
    {
    }

    /** Downloads the whole table once. */
    public function load(): static
    {
        $this->toId = [];
        $this->toExternal = [];
        foreach ($this->client->mappings->list($this->type) as $m) {
            $this->put($m['externalId'], $m['id']);
        }
        return $this;
    }

    /** The platform id for your id, or null when it is unknown. */
    public function internal(string $externalId): ?string
    {
        if (!isset($this->toId[$externalId])) {
            $m = $this->lookup(fn () => $this->client->mappings->toInternal($this->type, $externalId));
            if ($m) {
                $this->put($m['externalId'], $m['id']);
            }
        }
        return $this->toId[$externalId] ?? null;
    }

    /** Your id for a platform id, or null when the record has none. */
    public function external(string $id): ?string
    {
        if (!isset($this->toExternal[$id])) {
            $m = $this->lookup(fn () => $this->client->mappings->toExternal($this->type, $id));
            if ($m) {
                $this->put($m['externalId'], $m['id']);
            }
        }
        return $this->toExternal[$id] ?? null;
    }

    /** Keeps the ids of a push without another request. @param iterable<array<string,mixed>> $results */
    public function remember(iterable $results): void
    {
        foreach ($results as $r) {
            if (!empty($r['id'])) {
                $this->put($r['externalId'], $r['id']);
            }
        }
    }

    private function put(string $externalId, string $id): void
    {
        $this->toId[$externalId] = $id;
        $this->toExternal[$id] = $externalId;
    }

    private function lookup(callable $call): ?array
    {
        try {
            return $call();
        } catch (ObensApiError $e) {
            if ($e->status === 404) {
                return null;
            }
            throw $e;
        }
    }
}
