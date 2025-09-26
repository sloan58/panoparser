# Panorama → Elasticsearch Rule Ingestion (Laravel/PHP)

This document captures a complete plan and working PHP scaffolding to parse a **Panorama full XML configuration**, de-reference objects, and emit **one Elasticsearch document _per rule_** for fast search/filter. It is designed to be dropped into a Laravel app and run as a scheduled import (daily).

---

## Overview

**Goal:** Treat each **Security rule** as a single, fully de‑referenced **document** and index into Elasticsearch.
- Input: Daily **Panorama XML snapshot** (full configuration).
- Process (PHP/Laravel):
  1) Parse config → build catalogs (device‑groups, objects, services, apps, zones).
  2) Resolve hierarchy (Shared → ancestors → DG).
  3) Expand groups (address/service/app). Dynamic Address Groups (DAGs) marked unresolved unless later enriched.
  4) Walk all **Security** rulebases (pre/local/post), emit **one ES document per rule** containing **original references** and **expanded values**.
- Output: NDJSON for Elasticsearch Bulk API (or push directly via HTTP).

**Why a snapshot first?**
- Transactionally consistent, diffable, reproducible.
- You can later add **targeted API enrichments** for DAG membership and App‑ID defaults if you want “live-ish” data.

---

## Elasticsearch document shape

One document per rule (example):

```json
{
  "panorama_tenant": "prod-panorama-01",
  "snapshot_date": "2025-09-26",
  "device_group": "AppProd",
  "device_group_path": ["Shared","Corp","App","AppProd"],
  "rulebase": "pre|local|post",
  "rule_name": "allow-web",
  "rule_uid": "AppProd:local:000123:allow-web",
  "position": 123,
  "action": "allow",
  "disabled": false,
  "targets": { "include": ["fw-a","fw-b"], "exclude": [] },

  "orig": {
    "from_zones": ["trust"],
    "to_zones": ["untrust"],
    "sources": ["any"],
    "destinations": ["web-servers"],
    "applications": ["web-browsing","ssl"],
    "services": ["application-default"],
    "users": ["any"],
    "tags": ["prod","web"],
    "profiles": { "group": "Strict-Profile", "names": ["antivirus","anti-spyware"] },
    "comments": "allow https to web servers"
  },

  "expanded": {
    "from_zones": ["trust"],
    "to_zones": ["untrust"],
    "src_addresses": ["any"],
    "dst_addresses": ["10.1.1.10","10.1.1.11"],
    "applications": ["web-browsing","ssl"],
    "services": ["application-default"],
    "ports": ["tcp/80","tcp/443"],
    "users": ["any"],
    "tags": ["prod","web"]
  },

  "meta": {
    "has_dynamic_groups": true,
    "dynamic_groups_unresolved": ["DAG-prod-web"],
    "unresolved_notes": "DAG members not expanded; filter=prod AND web"
  }
}
```

> You can denormalize as much as you want under `expanded.*` for faster queries.

### Suggested index mapping

```json
PUT panorama-rules-v1
{
  "settings": { "number_of_shards": 1 },
  "mappings": {
    "properties": {
      "snapshot_date": { "type": "date" },
      "device_group": { "type": "keyword" },
      "device_group_path": { "type": "keyword" },
      "rulebase": { "type": "keyword" },
      "rule_name": { "type": "keyword" },
      "rule_uid": { "type": "keyword" },
      "position": { "type": "integer" },
      "action": { "type": "keyword" },
      "disabled": { "type": "boolean" },
      "orig": {
        "properties": {
          "from_zones": { "type": "keyword" },
          "to_zones": { "type": "keyword" },
          "sources": { "type": "keyword" },
          "destinations": { "type": "keyword" },
          "applications": { "type": "keyword" },
          "services": { "type": "keyword" },
          "users": { "type": "keyword" },
          "tags": { "type": "keyword" }
        }
      },
      "expanded": {
        "properties": {
          "from_zones": { "type": "keyword" },
          "to_zones": { "type": "keyword" },
          "src_addresses": { "type": "keyword" },
          "dst_addresses": { "type": "keyword" },
          "applications": { "type": "keyword" },
          "services": { "type": "keyword" },
          "ports": { "type": "keyword" },
          "users": { "type": "keyword" },
          "tags": { "type": "keyword" }
        }
      },
      "targets": {
        "properties": {
          "include": { "type": "keyword" },
          "exclude": { "type": "keyword" }
        }
      },
      "meta": {
        "properties": {
          "has_dynamic_groups": { "type": "boolean" },
          "dynamic_groups_unresolved": { "type": "keyword" }
        }
      }
    }
  }
}
```

---

## Laravel implementation

**Structure**
- Artisan command: `panorama:import`
- Services:
  - `PanoramaXmlLoader` (load XML)
  - `CatalogBuilder` (device groups, objects, zones)
  - `Dereferencer` (resolve+expand)
  - `RuleEmitter` (walk rules → NDJSON docs)

### 1) `app/Console/Commands/ImportPanorama.php`

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Panorama\PanoramaXmlLoader;
use App\Services\Panorama\CatalogBuilder;
use App\Services\Panorama\Dereferencer;
use App\Services\Panorama\RuleEmitter;
use Illuminate\Support\Facades\Storage;

class ImportPanorama extends Command
{
    protected $signature = 'panorama:import 
        {--file= : Path to Panorama XML export} 
        {--tenant= : Logical tenant name (e.g. prod-panorama-01)}
        {--date= : Snapshot date YYYY-MM-DD (defaults to today)} 
        {--out= : NDJSON output path (default: storage/app/panorama/rules.ndjson)}';

    protected $description = 'Parse Panorama XML and emit de-referenced Security rule documents for Elasticsearch';

    public function handle(): int
    {
        $xmlPath  = $this->option('file') ?? base_path('panorama.xml');
        $tenant   = $this->option('tenant') ?? 'default-panorama';
        $date     = $this->option('date') ?? now()->toDateString();
        $outPath  = $this->option('out') ?? 'panorama/rules.ndjson';

        if (!is_file($xmlPath)) {
            $this->error(\"XML file not found: {$xmlPath}\");
            return self::FAILURE;
        }

        $this->info(\"Loading XML: {$xmlPath}\");
        $loader = new PanoramaXmlLoader();
        $root   = $loader->load($xmlPath);

        $this->info(\"Building catalogs (device groups, objects, services, apps, zones)...\");
        $catalog = (new CatalogBuilder())->build($root);

        $this->info(\"De-referencing and emitting Security rules...\");
        $dereferencer = new Dereferencer($catalog);
        $emitter      = new RuleEmitter($tenant, $date, $dereferencer);

        $stream = Storage::disk('local')->writeStream($outPath, '');
        if ($stream === false) {
            $this->error(\"Unable to open output for writing: {$outPath}\");
            return self::FAILURE;
        }

        $count = $emitter->emitSecurityRulesAsNdjson($root, $stream);
        fclose($stream);

        $this->info(\"Wrote {$count} rule documents to storage/app/{$outPath}\");
        $this->info(\"Done.\");
        return self::SUCCESS;
    }
}
```

### 2) `app/Services/Panorama/PanoramaXmlLoader.php`

```php
<?php

namespace App\Services\Panorama;

class PanoramaXmlLoader
{
    public function load(string $path): \SimpleXMLElement
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path, 'SimpleXMLElement', LIBXML_COMPACT | LIBXML_PARSEHUGE);
        if (!$xml) {
            $err = collect(libxml_get_errors())->map(fn($e) => trim($e->message))->join(\"; \");
            throw new \RuntimeException(\"Failed to parse XML: {$err}\");
        }
        return $xml;
    }
}
```

### 3) `app/Services/Panorama/CatalogBuilder.php`

```php
<?php

namespace App\Services\Panorama;

/**
 * Builds lookup catalogs:
 * - deviceGroups: tree with ancestor paths
 * - objects: addresses, address-groups (static/dynamic), services, service-groups, applications/app-groups
 * - zones: by device/vsys (v1 simplified: global union)
 */
class CatalogBuilder
{
    public function build(\SimpleXMLElement $root): array
    {
        $cfg = $root->xpath('/config')[0] ?? $root; // handle root being <config> already.

        $deviceGroups = $this->buildDeviceGroups($cfg);
        $objects      = $this->buildObjects($cfg, $deviceGroups);
        $zones        = $this->buildZones($cfg, $deviceGroups);

        return compact('deviceGroups','objects','zones');
    }

    private function buildDeviceGroups(\SimpleXMLElement $cfg): array
    {
        $dgs = [
            'Shared' => ['name' => 'Shared', 'parent' => null, 'children' => [], 'path' => ['Shared']]
        ];

        foreach ($cfg->xpath('//device-group/entry') as $dg) {
            $name = (string)$dg['name'];
            $parent = (string)($dg->parent ?: '');
            if ($parent === '' || strtolower($parent) === 'shared') $parent = 'Shared';

            $dgs[$name] = [
                'name' => $name,
                'parent' => $parent,
                'children' => [],
                'path' => []
            ];
        }

        foreach ($dgs as $name => &$dg) {
            if ($dg['parent'] && isset($dgs[$dg['parent']])) {
                $dgs[$dg['parent']]['children'][] = $name;
            }
        }
        unset($dg);

        $computePath = function($name) use (&$dgs, &$computePath) {
            if ($name === 'Shared') return ['Shared'];
            $parent = $dgs[$name]['parent'] ?? null;
            $path = $parent ? array_merge($computePath($parent), [$name]) : [$name];
            $dgs[$name]['path'] = $path;
            return $path;
        };
        foreach (array_keys($dgs) as $name) { $computePath($name); }

        return $dgs;
    }

    private function buildObjects(\SimpleXMLElement $cfg, array $dgs): array
    {
        $scopes = array_keys($dgs);
        $objects = [];
        foreach ($scopes as $scope) {
            $objects[$scope] = [
                'address'        => [],
                'address-group'  => [],
                'service'        => [],
                'service-group'  => [],
                'application'    => [],
                'application-group' => []
            ];
        }

        $shared = $cfg->xpath('//shared')[0] ?? null;
        if ($shared) $this->collectObjectsUnder($objects['Shared'], $shared);

        foreach ($cfg->xpath('//device-group/entry') as $dg) {
            $name = (string)$dg['name'];
            $this->collectObjectsUnder($objects[$name], $dg);
        }

        return $objects;
    }

    private function collectObjectsUnder(array &$bucket, \SimpleXMLElement $scopeRoot): void
    {
        foreach ($scopeRoot->xpath('.//address/entry') as $e) {
            $name = (string)$e['name'];
            $kind = 'ip'; $value = '';
            if (isset($e->ipnetmask)) { $value = (string)$e->ipnetmask; $kind = strpos($value, '/') !== false ? 'cidr' : 'ip'; }
            if (isset($e->iprange))   { $value = (string)$e->iprange;   $kind = 'range'; }
            if (isset($e->fqdn))      { $value = (string)$e->fqdn;      $kind = 'fqdn'; }
            $bucket['address'][$name] = ['kind'=>$kind,'value'=>$value];
        }

        foreach ($scopeRoot->xpath('.//address-group/entry') as $e) {
            $name = (string)$e['name'];
            if (isset($e->static)) {
                $members = [];
                foreach ($e->xpath('./static/member') as $m) $members[] = (string)$m;
                $bucket['address-group'][$name] = ['kind'=>'static','members'=>$members,'match'=>null];
            } elseif (isset($e->dynamic) && isset($e->dynamic->filter)) {
                $bucket['address-group'][$name] = ['kind'=>'dynamic','members'=>[],'match'=>(string)$e->dynamic->filter];
            }
        }

        foreach ($scopeRoot->xpath('.//service/entry') as $e) {
            $name = (string)$e['name'];
            $proto = isset($e->protocol->tcp) ? 'tcp' : (isset($e->protocol->udp) ? 'udp' : (isset($e->protocol->ip) ? 'ip' : 'other'));
            $ports = [];
            if ($proto === 'tcp' && isset($e->protocol->tcp->port)) $ports = array_map('trim', explode(',', (string)$e->protocol->tcp->port));
            if ($proto === 'udp' && isset($e->protocol->udp->port)) $ports = array_map('trim', explode(',', (string)$e->protocol->udp->port));
            $bucket['service'][$name] = ['proto'=>$proto,'ports'=>$ports];
        }

        foreach ($scopeRoot->xpath('.//service-group/entry') as $e) {
            $name = (string)$e['name'];
            $members = [];
            foreach ($e->xpath('./members/member') as $m) $members[] = (string)$m;
            $bucket['service-group'][$name] = ['members'=>$members];
        }

        foreach ($scopeRoot->xpath('.//application-group/entry') as $e) {
            $name = (string)$e['name'];
            $members = [];
            foreach ($e->xpath('./members/member') as $m) $members[] = (string)$m;
            $bucket['application-group'][$name] = ['members'=>$members];
        }
    }

    private function buildZones(\SimpleXMLElement $cfg, array $dgs): array
    {
        $zonesByName = [];
        foreach ($cfg->xpath('//zone/entry') as $z) {
            $zonesByName[(string)$z['name']] = true;
        }
        $allZones = array_keys($zonesByName);

        $zones = [];
        foreach (array_keys($dgs) as $dgName) {
            $zones[$dgName] = $allZones; // v1 simplified union; refine per target devices later
        }
        return $zones;
    }
}
```

### 4) `app/Services/Panorama/Dereferencer.php`

```php
<?php

namespace App\Services\Panorama;

class Dereferencer
{
    public function __construct(public array $catalog) {}

    private function resolve(string $dgName, string $type, string $name): ?array
    {
        $path = $this->catalog['deviceGroups'][$dgName]['path'] ?? ['Shared'];
        $resolved = null;
        foreach ($path as $scope) {
            $bucket = $this->catalog['objects'][$scope][$type] ?? [];
            if (array_key_exists($name, $bucket)) $resolved = $bucket[$name];
        }
        return $resolved;
    }

    public function expandAddresses(string $dgName, array $names): array
    {
        $out = [];
        $seen = [];
        $stack = $names;

        while ($stack) {
            $name = array_pop($stack);
            if ($name === 'any') { $out[] = 'any'; continue; }
            if (isset($seen[\"addr:$name\"])) continue; $seen[\"addr:$name\"]=true;

            if ($addr = $this->resolve($dgName, 'address', $name)) {
                $out[] = $addr['value'];
                continue;
            }
            if ($ag = $this->resolve($dgName, 'address-group', $name)) {
                if ($ag['kind'] === 'static') {
                    foreach ($ag['members'] as $m) $stack[] = $m;
                } else {
                    $out[] = \"DAG:$name\";
                }
                continue;
            }
            $out[] = \"UNKNOWN:$name\";
        }
        return array_values(array_unique($out));
    }

    public function expandServices(string $dgName, array $names): array
    {
        $leafNames = [];
        $ports = [];
        $seen = [];
        $stack = $names;

        while ($stack) {
            $name = array_pop($stack);
            if ($name === 'any') { $leafNames[] = 'any'; continue; }
            if (isset($seen[\"svc:$name\"])) continue; $seen[\"svc:$name\"]=true;

            if ($svc = $this->resolve($dgName, 'service', $name)) {
                $leafNames[] = $name;
                foreach ($svc['ports'] as $p) $ports[] = ($svc['proto'] ?: 'tcp').\"/\".$p;
                continue;
            }
            if ($sg = $this->resolve($dgName, 'service-group', $name)) {
                foreach ($sg['members'] as $m) $stack[] = $m;
                continue;
            }
            $leafNames[] = \"UNKNOWN:$name\";
        }
        return [
            'names' => array_values(array_unique($leafNames)),
            'ports' => array_values(array_unique($ports)),
        ];
    }

    public function expandApplications(string $dgName, array $names): array
    {
        $leaf = [];
        $seen = [];
        $stack = $names;

        while ($stack) {
            $name = array_pop($stack);
            if (isset($seen[\"app:$name\"])) continue; $seen[\"app:$name\"]=true;

            if ($ag = $this->resolve($dgName, 'application-group', $name)) {
                foreach ($ag['members'] as $m) $stack[] = $m;
                continue;
            }
            $leaf[] = $name;
        }
        return array_values(array_unique($leaf));
    }

    public function zonesFor(string $dgName, array $zoneNames): array
    {
        return array_values(array_unique($zoneNames));
    }
}
```

### 5) `app/Services/Panorama/RuleEmitter.php`

```php
<?php

namespace App\Services\Panorama;

class RuleEmitter
{
    public function __construct(
        private string $tenant,
        private string $snapshotDate,
        private Dereferencer $deref
    ) {}

    public function emitSecurityRulesAsNdjson(\SimpleXMLElement $root, $stream): int
    {
        $count = 0;
        $ruleSets = [
            ['xpath' => '//pre-rulebase/security/rules/entry',   'rulebase' => 'pre'],
            ['xpath' => '//rulebase/security/rules/entry',        'rulebase' => 'local'],
            ['xpath' => '//post-rulebase/security/rules/entry',   'rulebase' => 'post'],
        ];

        foreach ($root->xpath('//device-group/entry') as $dg) {
            $dgName = (string)$dg['name'];
            foreach ($ruleSets as $rs) {
                $pos = 0;
                foreach ($dg->xpath($rs['xpath']) as $r) {
                    $doc = $this->buildRuleDoc($dgName, $rs['rulebase'], ++$pos, $r);
                    fwrite($stream, json_encode(['index'=>['_index'=>'panorama-rules-v1','_id'=>$doc['rule_uid']]], JSON_UNESCAPED_SLASHES) . \"\n\");
                    fwrite($stream, json_encode($doc, JSON_UNESCAPED_SLASHES) . \"\n\");
                    $count++;
                }
            }
        }

        $shared = $root->xpath('//shared')[0] ?? null;
        if ($shared) {
            foreach ([['path'=>'.//pre-rulebase/security/rules/entry','rb'=>'pre'],
                      ['path'=>'.//post-rulebase/security/rules/entry','rb'=>'post']] as $cfg) {
                $pos = 0;
                foreach ($shared->xpath($cfg['path']) as $r) {
                    $doc = $this->buildRuleDoc('Shared', $cfg['rb'], ++$pos, $r);
                    fwrite($stream, json_encode(['index'=>['_index'=>'panorama-rules-v1','_id'=>$doc['rule_uid']]], JSON_UNESCAPED_SLASHES) . \"\n\");
                    fwrite($stream, json_encode($doc, JSON_UNESCAPED_SLASHES) . \"\n\");
                    $count++;
                }
            }
        }
        return $count;
    }

    private function textList(\SimpleXMLElement $node, string $path): array
    {
        $out = [];
        foreach ($node->xpath($path) as $m) $out[] = trim((string)$m);
        return $out ?: ['any'];
    }

    private function buildRuleDoc(string $dgName, string $rulebase, int $position, \SimpleXMLElement $r): array
    {
        $name     = (string)$r['name'] ?: '(unnamed)';
        $action   = (string)($r->action ?? 'deny');
        $disabled = ((string)$r['disabled']) === 'yes';

        $fromZones = $this->textList($r, './from/member');
        $toZones   = $this->textList($r, './to/member');
        $sources   = $this->textList($r, './source/member');
        $dests     = $this->textList($r, './destination/member');
        $apps      = $this->textList($r, './application/member');
        $services  = $this->textList($r, './service/member');
        $users     = $this->textList($r, './source-user/member');
        $tags      = $this->textList($r, './tag/member');

        $profiles = [
            'group' => (string)($r->{'profile-setting'}->{'group'}->{'member'} ?? ''),
            'names' => []
        ];

        $targets = ['include'=>[],'exclude'=>[]];
        foreach ($r->xpath('./target/devices/member') as $m) $targets['include'][] = (string)$m;
        foreach ($r->xpath('./target/negate/devices/member') as $m) $targets['exclude'][] = (string)$m;

        $expandedSrcAddrs = $this->deref->expandAddresses($dgName, $sources);
        $expandedDstAddrs = $this->deref->expandAddresses($dgName, $dests);
        $expandedApps     = $this->deref->expandApplications($dgName, $apps);

        $expandedServices = ['names'=>[], 'ports'=>[]];
        if (count($services) === 1 && $services[0] === 'application-default') {
            $expandedServices['names'] = ['application-default'];
            $expandedServices['ports'] = [];
        } else {
            $expandedServices = $this->deref->expandServices($dgName, $services);
        }

        $doc = [
            'panorama_tenant'    => $this->tenant,
            'snapshot_date'      => $this->snapshotDate,
            'device_group'       => $dgName,
            'device_group_path'  => $this->deref->catalog['deviceGroups'][$dgName]['path'] ?? ['Shared'],
            'rulebase'           => $rulebase,
            'rule_name'          => $name,
            'rule_uid'           => \"{$dgName}:{$rulebase}:\" . str_pad((string)$position, 6, '0', STR_PAD_LEFT) . \":{$name}\",
            'position'           => $position,
            'action'             => $action,
            'disabled'           => $disabled,
            'targets'            => $targets,

            'orig' => [
                'from_zones'   => $fromZones,
                'to_zones'     => $toZones,
                'sources'      => $sources,
                'destinations' => $dests,
                'applications' => $apps,
                'services'     => $services,
                'users'        => $users,
                'tags'         => $tags,
                'profiles'     => $profiles,
                'comments'     => (string)($r->description ?? '')
            ],

            'expanded' => [
                'from_zones'   => $this->deref->zonesFor($dgName, $fromZones),
                'to_zones'     => $this->deref->zonesFor($dgName, $toZones),
                'src_addresses'=> $expandedSrcAddrs,
                'dst_addresses'=> $expandedDstAddrs,
                'applications' => $expandedApps,
                'services'     => $expandedServices['names'],
                'ports'        => $expandedServices['ports'],
                'users'        => $users,
                'tags'         => $tags
            ],

            'meta' => [
                'has_dynamic_groups' => (bool)collect($expandedSrcAddrs)->first(fn($v)=>str_starts_with($v,'DAG:'))
                                         || (bool)collect($expandedDstAddrs)->first(fn($v)=>str_starts_with($v,'DAG:')),
                'dynamic_groups_unresolved' => collect(array_merge($expandedSrcAddrs, $expandedDstAddrs))
                    ->filter(fn($v)=>str_starts_with($v,'DAG:'))
                    ->map(fn($v)=>substr($v,4))->unique()->values()->all()
            ]
        ];

        return $doc;
    }
}
```

---

## Usage

```bash
php artisan panorama:import \
  --file=/path/to/panorama.xml \
  --tenant=prod-panorama-01 \
  --date=2025-09-26 \
  --out=panorama/rules.ndjson
```

Bulk load into Elasticsearch:

```bash
curl -H 'Content-Type: application/x-ndjson' \
  -XPOST http://localhost:9200/_bulk \
  --data-binary @storage/app/panorama/rules.ndjson
```

---

## UI queries (examples)

- Allow RDP anywhere:
  - `expanded.ports:"tcp/3389" AND action:allow AND expanded.dst_addresses:"any"`

- Post rules in AppProd that touch SSL:
  - `device_group:"AppProd" AND rulebase:"post" AND expanded.applications:"ssl"`

- Rules with unresolved DAGs:
  - `meta.has_dynamic_groups:true`

---

## Enrichment ideas (optional v2)

1. **Dynamic Address Groups (DAGs)**  
   - Pull registered‑IP ↔ tag bindings from Panorama/firewalls, resolve DAG membership, and update `expanded.src_addresses`/`dst_addresses`.

2. **App‑ID defaults**  
   - Fetch predefined applications metadata and expand `application-default` to concrete ports per app.

3. **Per‑target zone catalogs**  
   - Map zones per device/vsys and restrict by rule `targets` (include/exclude). Useful for validation and drift detection.

4. **Shadowing / redundancy analyzer**  
   - With expanded values and strict positions, flag shadowed or redundant rules; emit findings to a separate ES index.

---

## Notes & Caveats

- Keep **both** original references (`orig.*`) and expanded values (`expanded.*`)—auditors want the originals.
- Mark unknown/missing references explicitly (e.g., `UNKNOWN:<name>`) to surface drift and cleanup opportunities.
- Add cycle guards when expanding groups; log anomalies.
- Processing time isn’t critical (daily run), so favor **clarity and correctness** over micro‑optimizations.
