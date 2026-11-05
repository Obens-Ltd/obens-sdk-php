<?php

declare(strict_types=1);

namespace Obens\Sdk;

/**
 * Keeps one kind of record in sync with the platform.
 *
 * init($records) is the first full load (chunked, with a report), push() / pushMany() send changes as they happen
 * (upsert, so replays are harmless) and remove() sends a delete. The mapper must return the push body including
 * `externalId`.
 */
class Syncer
{
    private int $chunk;

    /** @param callable(mixed):array $map @param (callable(SyncReport):void)|null $onProgress */
    public function __construct(
        private Resource $resource,
        private string $kind,
        private $map,
        int $chunkSize = 200,
        private $onProgress = null
    ) {
        $this->chunk = max(1, min($chunkSize, Resource::MAX_BULK));
    }

    /** Accepts any iterable, so a big table can be streamed (a generator over a cursor). */
    public function init(iterable $records): SyncReport
    {
        $report = new SyncReport();
        $batch = [];
        $flush = function () use (&$batch, $report): void {
            if (!$batch) {
                return;
            }
            $report->add($this->resource->bulk($batch, $this->chunk));
            $batch = [];
            if ($this->onProgress) {
                ($this->onProgress)($report);
            }
        };
        foreach ($records as $record) {
            $batch[] = $this->mapped($record);
            if (count($batch) >= $this->chunk) {
                $flush();
            }
        }
        $flush();
        return $report;
    }

    public function push(mixed $record): array
    {
        return $this->resource->upsert($this->mapped($record));
    }

    public function pushMany(iterable $records): SyncReport
    {
        $items = [];
        foreach ($records as $record) {
            $items[] = $this->mapped($record);
        }
        $report = new SyncReport();
        $report->add($this->resource->bulk($items, $this->chunk));
        return $report;
    }

    public function remove(mixed $record): array
    {
        $flag = $this->kind === 'facilities' ? ['deleted' => true] : ['status' => 'DELETED'];
        return $this->resource->upsert($flag + $this->mapped($record));
    }

    private function mapped(mixed $record): array
    {
        $push = ($this->map)($record);
        if (!$push || empty($push['externalId'])) {
            throw new \InvalidArgumentException('map(record) must return an array with an externalId');
        }
        return $push;
    }
}
