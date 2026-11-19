<?php

declare(strict_types=1);

namespace Obens\Sdk\Tests;

use Obens\Sdk\ObensClient;
use PHPUnit\Framework\TestCase;

final class TraceTest extends TestCase
{
    /** @var list<array{method:string,url:string,headers:array,body:mixed}> */
    private array $calls = [];
    private int $whoami = 0;

    private function client(): ObensClient
    {
        return new ObensClient('https://api.test/v2/core', 'obk_good', 5.0, 0, 0.0, $this->transport(...));
    }

    private function transport(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $decoded = $body === null ? null : json_decode($body, true);
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $decoded];
        $path = parse_url($url, PHP_URL_PATH);
        if ($path === '/v2/core/integration/whoami') {
            $this->whoami++;
            return [200, json_encode(['keyId' => 'k', 'keyName' => 'n', 'accountId' => 'a1', 'workspaceId' => 'w1']), []];
        }
        $mappings = [
            '/v2/core/integration/mappings/SUB_ACTOR/external/farmer-1' => ['SUB_ACTOR', 'farmer-1', 'sa-1'],
            '/v2/core/integration/mappings/FACILITY/external/orchard-1' => ['FACILITY', 'orchard-1', 'fa-1'],
            '/v2/core/integration/mappings/ACCOUNT/external/buyer-1' => ['ACCOUNT', 'buyer-1', 'ac-9'],
        ];
        if (isset($mappings[$path])) {
            [$type, $ext, $id] = $mappings[$path];
            return [200, json_encode(['type' => $type, 'externalId' => $ext, 'id' => $id]), []];
        }
        if ($method === 'PUT' && str_starts_with($path, '/v2/trace/integration/batches/')) {
            if (($decoded['name'] ?? null) === 'bad') {
                return [400, '{"message":"QUANTITY_IS_REQUIRED"}', []];
            }
            return [200, json_encode(['externalId' => rawurldecode(basename($path)), 'id' => 'b-1', 'outcome' => 'CREATED', 'error' => null]), []];
        }
        if ($method === 'PUT' && str_starts_with($path, '/v2/trace/integration/transactions/')) {
            return [200, json_encode(['externalId' => rawurldecode(basename($path)), 'id' => 't-1', 'outcome' => 'CREATED', 'error' => null]), []];
        }
        if ($path === '/v2/trace/integration/transactions') {
            return [200, json_encode(['data' => [['id' => 't-1', 'externalId' => 'tr-1']], 'page' => 0, 'size' => 10, 'total' => 1]), []];
        }
        if (str_ends_with($path, '/data')) {
            return [200, json_encode([['id' => 'f1', 'name' => 'Moisture', 'type' => 'NumericInput', 'value' => 12]]), []];
        }
        if ($path === '/v2/trace/integration/batches/schema') {
            return [200, json_encode([['id' => 'f1', 'name' => 'Moisture', 'type' => 'NumericInput', 'writable' => true]]), []];
        }
        return [404, '{}', []];
    }

    private function puts(): array
    {
        return array_values(array_filter($this->calls, fn($c) => $c['method'] === 'PUT'));
    }

    public function testBatchPushGoesToTraceWithTheWorkspaceAskedOnce(): void
    {
        $c = $this->client();
        $r = $c->batches->upsert(['externalId' => 'lot/1', 'name' => 'Lot', 'quantity' => ['value' => 5, 'unit' => 'KG'], 'data' => ['Moisture' => 12]]);
        $c->batches->upsert(['externalId' => 'lot/1', 'name' => 'Lot']);
        $this->assertSame('CREATED', $r['outcome']);
        $first = $this->puts()[0];
        $this->assertSame('https://api.test/v2/trace/integration/batches/lot%2F1', $first['url']);
        $this->assertContains('X-WORKSPACE-ID: w1', $first['headers']);
        $this->assertArrayNotHasKey('externalId', $first['body']);
        $this->assertSame(1, $this->whoami);
    }

    public function testPurchaseNamesFarmerBuyerAndFacilitiesByClientIds(): void
    {
        $c = $this->client();
        $c->transactions->upsert([
            'externalId' => 'buy-1', 'elementId' => 'e1', 'subActorExternalId' => 'farmer-1', 'date' => '2026-02-01',
            'collected' => ['name' => 'Olives', 'quantity' => ['value' => 100, 'unit' => 'KG'], 'facilityExternalIds' => ['orchard-1']],
        ]);
        $c->transactions->upsert(['externalId' => 'tr-1', 'elementId' => 'e1', 'clientExternalId' => 'buyer-1', 'supplierId' => 'a1', 'batches' => []]);
        [$purchase, $transfer] = array_map(fn($p) => $p['body'], $this->puts());
        $this->assertSame('sa-1', $purchase['subActorId']);
        $this->assertArrayNotHasKey('subActorExternalId', $purchase);
        $this->assertSame(['fa-1'], $purchase['collected']['facilityIds']);
        $this->assertSame('ac-9', $transfer['clientId']);
        $this->assertSame('a1', $transfer['supplierId']);
    }

    public function testBulkKeepsGoingAfterAFailedItem(): void
    {
        $results = $this->client()->batches->bulk([['externalId' => 'a', 'name' => 'ok'], ['externalId' => 'b', 'name' => 'bad'], ['externalId' => 'c', 'name' => 'ok']]);
        $this->assertSame(['CREATED', 'FAILED', 'CREATED'], array_column($results, 'outcome'));
        $this->assertStringContainsString('QUANTITY_IS_REQUIRED', $results[1]['error']);
    }

    public function testSchemaReadsTheTraceApi(): void
    {
        $this->assertSame('Moisture', $this->client()->batches->schema('pm1')[0]['name']);
    }

    public function testReadsBuildTheirQueryAndReadFormData(): void
    {
        $c = $this->client();
        $page = $c->transactions->list('e1', null, 'BUY', '2026-01-01', null, 'olive oil', null, 5);
        $this->assertSame('tr-1', $page['data'][0]['externalId']);
        $urls = array_column($this->calls, 'url');
        $this->assertContains('https://api.test/v2/trace/integration/transactions?element-id=e1&type=BUY&start=2026-01-01&search=olive%20oil&size=5', $urls);
        $this->assertSame(12, $c->batches->data('lot/1', 'sa-1')[0]['value']);
        $this->assertContains('https://api.test/v2/trace/integration/batches/lot%2F1/data?owner-id=sa-1', array_column($this->calls, 'url'));
        $this->assertSame('Moisture', $c->transactions->data('tr-1')[0]['name']);
    }
}
