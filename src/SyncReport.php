<?php

declare(strict_types=1);

namespace Obens\Sdk;

class SyncReport
{
    public int $total = 0;
    public int $created = 0;
    public int $updated = 0;
    public int $failed = 0;
    /** @var list<array{externalId:string,error:?string}> */
    public array $failures = [];
    /** @var list<array<string,mixed>> */
    public array $results = [];

    /** @param iterable<array<string,mixed>> $results */
    public function add(iterable $results): void
    {
        foreach ($results as $r) {
            $this->total++;
            if ($r['outcome'] === 'CREATED') {
                $this->created++;
            } elseif ($r['outcome'] === 'UPDATED') {
                $this->updated++;
            } else {
                $this->failed++;
                $this->failures[] = ['externalId' => $r['externalId'], 'error' => $r['error'] ?? null];
            }
            $this->results[] = $r;
        }
    }
}
