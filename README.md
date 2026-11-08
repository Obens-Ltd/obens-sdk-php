# obens-sdk (PHP)

Source: https://github.com/Obens-Ltd/obens-sdk-php

Push your farmers, facilities and partners to the Obens platform under **your own ids**, and translate ids both ways.
PHP 8.1+, no dependency beyond the `curl` and `json` extensions.

```
composer config repositories.obens vcs https://github.com/Obens-Ltd/obens-sdk-php
composer require obens/obens-sdk:^0.1
```

## How it fits together

Six small pictures of what happens behind the SDK (Mermaid diagrams, GitHub draws them).

### The big picture

Your system keeps its own ids. The SDK sends records with those ids to the platform; the platform stores its own id
next to yours and can translate either way.

```mermaid
flowchart LR
  subgraph yours["Your system"]
    DB[("Your database<br/>farmers, orchards, partners")]
    APP["Your code<br/>+ Obens SDK"]
    DB --> APP
  end
  APP -- "HTTPS + X-API-KEY<br/>PUT / bulk by your id" --> API["Integration API<br/>/v2/core/integration"]
  subgraph obens["Obens platform"]
    API --> ST[("Platform records<br/>farmers, facilities")]
    API <--> MAP[("Id table<br/>your id &harr; platform id")]
  end
```

### What an API key is allowed to touch

A key is bound to **one account in one workspace**. It can only see that account's records (plus accounts that
delegated to it, see below). Farmers live in an **element** (a list with its own custom form); facilities (orchards,
plots...) belong to a farmer.

```mermaid
flowchart TD
  W["Workspace"] --> A["Account<br/>(your company)"]
  K["API key obk_...<br/>acts as this account in this workspace"] -.-> A
  A --> E["Element<br/>a list + its custom form<br/>e.g. 'Farmers'"]
  E --> F1["Farmer<br/>externalId: farmer-42"]
  E --> F2["Farmer<br/>externalId: farmer-43"]
  F1 --> O1["Facility<br/>externalId: orchard-12"]
  F1 --> O2["Facility<br/>externalId: orchard-13"]
  F2 --> O3["Facility<br/>externalId: orchard-12"]
  A --> AF["Facility of the account itself"]
```

Notice `orchard-12` twice: a facility id is unique **per owner**, so the same id under two farmers is two different
facilities. Farmers have their own id space, facilities have theirs.

### One push, step by step

The first push of an id creates the record, every later push updates it. That is why retries and full replays never
create duplicates.

```mermaid
sequenceDiagram
  participant You as Your code (SDK)
  participant API as Integration API
  participant DB as Platform records
  participant Map as Id table

  You->>API: PUT sub-actors/farmer-42 (name, elementId, data)
  API->>API: check key (revoked? expired?)
  API->>Map: is "farmer-42" known for this account?
  alt first time
    API->>DB: create farmer
    API->>Map: store farmer-42 -> platform id
    API-->>You: CREATED + platform id
  else already known
    API->>DB: update the same farmer
    API-->>You: UPDATED + platform id
  end
  Note over You,API: A facility push names its farmer with<br/>subActorExternalId, so the farmer must be pushed first.
```

### Load once, then push changes

```mermaid
flowchart LR
  A["Initial load<br/>init(all rows)<br/>in chunks of 200-500"] --> B{"Any failures<br/>in the report?"}
  B -- yes --> C["Fix the rows<br/>run init again<br/>(done rows are updated, not duplicated)"]
  C --> B
  B -- no --> D["Live: push / pushMany / remove<br/>from your save hook or queue"]
  D --> D
  E["Nightly or after an outage:<br/>replay the whole table"] -.-> A
```

### Acting for other accounts (delegation)

If other accounts have delegated to the key's account (a cooperative and its members, for example), the same key can
act on them. Each account has its **own** id space, so `farmer-42` of the cooperative and `farmer-42` of a member are
different farmers.

```mermaid
flowchart TD
  K["Your API key"] --> C["Your account<br/>(cooperative)"]
  C -- "delegated" --> M1["Member account 1"]
  C -- "delegated" --> M2["Member account 2"]
  C --- I0[("Own ids:<br/>farmer-42 &rarr; farmer A")]
  M1 --- I1[("Ids of member 1:<br/>farmer-42 &rarr; farmer B")]
  M2 --- I2[("Ids of member 2:<br/>farmer-42 &rarr; farmer C")]
```

A revoked delegation answers `403` on the next call.

### Matching accounts that already exist

Accounts are shared between clients, so an account cannot carry your id. You **link** your id to it; the link is
private to your account.

```mermaid
flowchart LR
  subgraph yours["Your ids"]
    S["supplier-9"]
  end
  subgraph obens["Platform"]
    AC["Existing account<br/>66f0aa..."]
  end
  S -- "link (private to your account)" --> AC
  S2["another client's id<br/>'sup-001'"] -. "their own, separate link" .-> AC
```

## 1. Get an API key

In the platform: **Settings → API keys → Create key**. Pick the account and workspace it acts as. The secret
(`obk_...`) is shown **once**, store it like a password. Keys can be revoked or set to expire.

## 2. Connect

```php
use Obens\Sdk\ObensClient;

$client = new ObensClient('https://api.obens.so/v2/core', getenv('OBENS_API_KEY'));

print_r($client->whoami());    // keyId, keyName, accountId, workspaceId
print_r($client->elements());  // the lists farmers can be created in -> you need one 'id'
```

Transient failures (timeouts, 429, 5xx) are retried with backoff. Every call is an upsert, so a retry or a replay
never duplicates a record.

## 3. Write a mapper

A mapper turns **your** record into what the platform expects. Strings are paths into your record (array keys or
properties), closures are computed, `Mapper::constant()` is a fixed value; empty results are skipped so an update
never blanks a field.

```php
use Obens\Sdk\Mapper;

$farmersElement = '<element id from $client->elements()>';

$mapFarmer = Mapper::create([
    'externalId' => 'id',                                  // YOUR id: required, the key of the mapping
    'name'       => fn ($r) => $r['first_name'] . ' ' . $r['last_name'],
    'ref'        => 'code',
    'address'    => 'village.name',
    'gender'     => fn ($r) => ['M' => 'MALE', 'F' => 'FEMALE'][$r['sex']] ?? null,
    'elementId'  => Mapper::constant($farmersElement),
    'type'       => Mapper::constant('FARMER'),
]);
```

Or pass any callable that returns the array.

## 4. Initial load

```php
$farmers = $client->syncer('sub_actors', $mapFarmer, 200, fn ($r) => print("{$r->total}\n"));

$report = $farmers->init($allRows);  // an array, or a generator over a cursor to stream a big table
echo "$report->created created, $report->updated updated, $report->failed failed\n";
print_r($report->failures);
```

One bad row never stops the load; it is listed in `$report->failures`. Run `init` again after fixing them: rows
already pushed are updated, not duplicated.

## 5. Push changes

```php
$farmers->push($row);          // one change: call it from your save hook / queue consumer
$farmers->pushMany($rows);     // a batch of changes
$farmers->remove($row);        // delete (soft: the platform keeps the record)
```

Facilities work the same way:

```php
$facilities = $client->syncer('facilities', Mapper::create([
    'externalId'   => 'id',
    'info.name'    => 'title',
    'info.address' => 'address',
    'coordinates'  => fn ($r) => ['type__' => 'Point', 'latitude' => $r['lat'], 'longitude' => $r['lng']],
]));
```

### Facilities of a farmer

A farmer can own many facilities (orchards, plots...). Give the farmer's external id in `subActorExternalId` and the
facility belongs to that farmer. The farmer must have been pushed first. A facility's id is unique **per owner**, so
read it back with the same farmer:

```php
Mapper::create(['externalId' => 'id', 'subActorExternalId' => 'farmer_id', 'info.name' => 'title']);
$client->facilities->get('orchard-12', 'farmer-42');
```

## 6. Match your ids to platform ids

```php
$ids = $client->idMap('SUB_ACTOR')->load();   // 'SUB_ACTOR' | 'FACILITY' | 'ACCOUNT'
$ids->internal('farmer-42');                  // -> platform id
$ids->external('66f0c0...');                  // -> 'farmer-42'

// link one of your partners to an account that already exists on the platform
$client->accounts->link('supplier-9', '66f0aa...');
```

### Custom fields

```php
$fields = $client->subActorSchema($elementId);  // [{name, staticId, type, writable, choices}]
```

Put plain values in `data`, keyed by field `name` or `staticId`:

```php
$mapper = Mapper::create([
    'externalId' => 'id',
    'name'       => 'full_name',
    'elementId'  => Mapper::constant($elementId),
    'data'       => fn ($r) => ['Crop' => $r['crop'], 'Area (ha)' => $r['area'], 'Planted on' => $r['planted']],
]);
```

Choices accept an option's id, label or value; quantities are `['value' => 1, 'unit' => 'kg']`; dates are
`yyyy-MM-dd`. File, image, table and formula fields can't be written. A bad key or value fails that record with a
message listing every problem; nothing is written for it.

## Delegated accounts

If other accounts have delegated to the key's account, the same key can act on them: create their farmers and
facilities, link their partners and read their id tables.

```php
$accounts = $client->accounts->accessible();         // [{id, name, self}]: the key's own account first
$coop = $client->forAccount($accounts[1]['id']);     // every call below now acts as that account

$coop->syncer('sub_actors', $mapFarmer)->init($coopFarmers);
```

Each account has its **own** id space: `farmer-42` of one account and `farmer-42` of another are different farmers.
A revoked delegation stops working on the next call (`403`).

## Errors

Failed requests throw `Obens\Sdk\ObensApiError` with `status` and `body`. `401` = missing, wrong, revoked or expired
key. Network failures that outlast the retries throw a `RuntimeException`.

## Tests

```
composer install && composer test
```

Full endpoint reference: `traceability-core/api-docs/integration/readme.md`.

## License

MIT, see [LICENSE](LICENSE).
