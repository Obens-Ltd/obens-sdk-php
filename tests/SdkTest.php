<?php

declare(strict_types=1);

namespace Obens\Sdk\Tests;

use Obens\Sdk\Mapper;
use Obens\Sdk\ObensApiError;
use Obens\Sdk\ObensClient;
use PHPUnit\Framework\TestCase;

final class SdkTest extends TestCase
{
    /** @var list<array{method:string,url:string,headers:array,body:mixed}> */
    private array $calls = [];
    /** @var array<string,string> */
    private array $subActors = [];
    private int $failFirst = 0;

    private function client(string $key = 'obk_good', ?string $account = null): ObensClient
    {
        $client = new ObensClient('https://api.test/v2/core/', $key, 5.0, 3, 0.0, $this->transport(...));
        return $account ? $client->forAccount($account) : $client;
    }

    private function transport(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $decoded = $body === null ? null : json_decode($body, true);
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $decoded];
        if (!in_array('X-API-KEY: obk_good', $headers, true)) {
            return [401, '', []];
        }
        if ($this->failFirst > 0) {
            $this->failFirst--;
            return [503, '{}', ['retry-after' => '0']];
        }
        $path = parse_url($url, PHP_URL_PATH);
        $prefix = '/v2/core';
        $route = substr($path, strlen($prefix));

        if ($method === 'PUT' && str_starts_with($route, '/integration/sub-actors/')) {
            $item = $decoded + ['externalId' => rawurldecode(basename($route))];
            return [200, json_encode($this->upsert($item)), []];
        }
        if ($method === 'POST' && $route === '/integration/sub-actors/bulk') {
            $out = [];
            foreach ($decoded['items'] as $i) {
                $out[] = ($i['name'] ?? null) === 'bad'
                    ? ['externalId' => $i['externalId'], 'id' => null, 'outcome' => 'FAILED', 'error' => 'nope']
                    : $this->upsert($i);
            }
            return [200, json_encode($out), []];
        }
        if ($route === '/integration/whoami') {
            return [200, json_encode(['keyId' => 'k1', 'accountId' => 'a1']), []];
        }
        if ($route === '/integration/mappings/SUB_ACTOR') {
            $rows = [];
            foreach ($this->subActors as $ext => $id) {
                $rows[] = ['type' => 'SUB_ACTOR', 'externalId' => $ext, 'id' => $id];
            }
            return [200, json_encode($rows), []];
        }
        if (str_starts_with($route, '/integration/mappings/SUB_ACTOR/external/')) {
            $ext = rawurldecode(basename($route));
            return isset($this->subActors[$ext])
                ? [200, json_encode(['externalId' => $ext, 'id' => $this->subActors[$ext]]), []]
                : [404, '{"error":"not found"}', []];
        }
        return [404, '{}', []];
    }

    private function upsert(array $item): array
    {
        $created = !isset($this->subActors[$item['externalId']]);
        if ($created) {
            $this->subActors[$item['externalId']] = 'id-' . (count($this->subActors) + 1);
        }
        return [
            'externalId' => $item['externalId'],
            'id' => $this->subActors[$item['externalId']],
            'outcome' => $created ? 'CREATED' : 'UPDATED',
            'error' => null,
        ];
    }

    public function testWhoamiSendsKeyAndTrimsBaseUrl(): void
    {
        $this->assertSame('a1', $this->client()->whoami()['accountId']);
        $this->assertSame('https://api.test/v2/core/integration/whoami', $this->calls[0]['url']);
        $this->assertContains('X-API-KEY: obk_good', $this->calls[0]['headers']);
    }

    public function testBadKeyRaisesApiError(): void
    {
        try {
            $this->client('nope')->whoami();
            $this->fail('expected ObensApiError');
        } catch (ObensApiError $e) {
            $this->assertSame(401, $e->status);
        }
    }

    public function testRetriesTransientFailures(): void
    {
        $this->failFirst = 2;
        $this->client()->whoami();
        $this->assertCount(3, $this->calls);
    }

    public function testUpsertEncodesExternalIdAndIsIdempotent(): void
    {
        $c = $this->client();
        $first = $c->subActors->upsert(['externalId' => 'a/b c', 'name' => 'X']);
        $second = $c->subActors->upsert(['externalId' => 'a/b c', 'name' => 'X']);
        $this->assertSame('CREATED', $first['outcome']);
        $this->assertSame('UPDATED', $second['outcome']);
        $this->assertStringEndsWith('/integration/sub-actors/a%2Fb%20c', $this->calls[0]['url']);
    }

    public function testUpsertRequiresExternalId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->subActors->upsert(['name' => 'X']);
    }

    public function testBulkChunksAndKeepsOrder(): void
    {
        $items = array_map(fn ($i) => ['externalId' => "f$i"], range(1, 1100));
        $results = $this->client()->subActors->bulk($items);
        $this->assertCount(1100, $results);
        $this->assertSame('f1', $results[0]['externalId']);
        $this->assertCount(3, $this->calls);
    }

    public function testSyncerInitReportsFailuresAndStreams(): void
    {
        $rows = (function () {
            yield ['id' => '1', 'n' => 'ok'];
            yield ['id' => '2', 'n' => 'bad'];
            yield ['id' => '3', 'n' => 'ok'];
        })();
        $progress = 0;
        $syncer = $this->client()->syncer(
            'sub_actors',
            Mapper::create(['externalId' => 'id', 'name' => 'n']),
            2,
            function () use (&$progress) {
                $progress++;
            }
        );
        $report = $syncer->init($rows);
        $this->assertSame(3, $report->total);
        $this->assertSame(2, $report->created);
        $this->assertSame(1, $report->failed);
        $this->assertSame('2', $report->failures[0]['externalId']);
        $this->assertSame(2, $progress);
    }

    public function testRemoveSendsDeletedStatus(): void
    {
        $syncer = $this->client()->syncer('sub_actors', Mapper::create(['externalId' => 'id']));
        $syncer->remove(['id' => '9']);
        $this->assertSame('DELETED', $this->calls[0]['body']['status']);
        $fac = $this->client()->syncer('facilities', Mapper::create(['externalId' => 'id']));
        $this->assertNotNull($fac);
    }

    public function testMapperPathsNestingAndSkippingNull(): void
    {
        $map = Mapper::create([
            'externalId' => 'id',
            'info.name' => 'title',
            'info.address' => 'missing.deep',
            'type' => Mapper::constant('FARMER'),
            'upper' => fn ($r) => strtoupper($r['title']),
        ]);
        $this->assertSame(
            ['externalId' => '7', 'info' => ['name' => 'Farm'], 'type' => 'FARMER', 'upper' => 'FARM'],
            $map(['id' => '7', 'title' => 'Farm'])
        );
    }

    public function testMapperReadsObjectProperties(): void
    {
        $record = new \stdClass();
        $record->id = 5;
        $record->village = (object) ['name' => 'V'];
        $map = Mapper::create(['externalId' => 'id', 'address' => 'village.name']);
        $this->assertSame(['externalId' => 5, 'address' => 'V'], $map($record));
    }

    public function testIdMapLoadsAndFallsBackTo404AsNull(): void
    {
        $c = $this->client();
        $c->subActors->upsert(['externalId' => 'farmer-1']);
        $ids = $c->idMap('SUB_ACTOR')->load();
        $this->assertSame('id-1', $ids->internal('farmer-1'));
        $this->assertSame('farmer-1', $ids->external('id-1'));
        $this->assertNull($ids->internal('unknown'));
    }

    public function testForAccountAddsAccountIdToEveryCall(): void
    {
        $this->client('obk_good', 'acc 2')->whoami();
        $this->assertStringEndsWith('/integration/whoami?account-id=acc%202', $this->calls[0]['url']);
    }

    public function testUnknownSyncerKind(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->syncer('farmers', fn ($r) => $r);
    }
}
