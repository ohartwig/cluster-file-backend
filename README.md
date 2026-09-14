# cluster-file-backend

**Cluster-aware TYPO3 14 cache backend — no shared filesystem.**

Drop-in replacement for `TYPO3\CMS\Core\Cache\Backend\FileBackend` and
`SimpleFileBackend` in Kubernetes deployments. Implements
`TaggableBackendInterface` and (since v2.2) `PhpCapableBackendInterface`,
so it can serve VariableFrontend caches (`pages`, `extbase`, …) **and**
PhpFrontend caches (`typoscript`, `fluid_template`) on the same backend.
Cache validity is sourced from a **second** TYPO3 cache frontend (which
you configure freely); payloads are materialised pod-locally as atomically
written files.

- **Composer package**: `moselwal/cluster-file-backend`
- **Extension key**: `cluster_file_backend`
- **PHP namespace**: `Moselwal\Typo3ClusterCache\`
- **TYPO3**: 14.3+ (Composer Mode only — no `ext_emconf.php`)
- **PHP**: 8.5+
- **License**: MIT

## What's new in v2.2 / v2.3

- **PhpCapableBackendInterface** (v2.2) — `requireOnce()` / `require()` for
  PhpFrontend caches. The payload-store appends a `.php` suffix on PhpFrontend
  caches so OPcache ingests the files; compression is forced off (PHP code
  cannot be a compressed blob); cluster-coherence comes from the
  BackendVersion-folded hash path (every deploy = new path = OPcache
  automatic-cold, no `opcache_invalidate()` needed).
- **One-byte compression marker** (v2.2) — `0x00 none`, `0x01 zstd`, `0x02 gzip`.
  The reader picks the decompressor from the marker; the writer can mix codecs
  (e.g. skip-compress for tiny payloads while keeping zstd for the rest).
- **Skip-compress for small payloads** (v2.2) — option `minCompressedBytes`
  (default 1024). Payloads below the threshold are stored uncompressed.
- **Request-scoped metadata L1** (v2.2) — `has()` / `get()` / `remove()` hit
  an in-memory map of recent `CacheMetadata` lookups; identical identifiers
  in the same request skip the Valkey/DB roundtrip. ~200× faster on
  repeated `has()`.
- **Request-scoped payload L1** (v2.3) — fully decoded payloads are cached
  in RAM with LRU (default cap 32 entries / 4 MB). Repeated `get()` on a
  hot identifier drops from ~90 µs to ~0.5 µs — **9–20× faster than
  SimpleFileBackend** on every repeated read. Capped per option
  `payloadL1MaxEntries` / `payloadL1MaxBytes`. PhpFrontend caches skip the
  payload L1 (OPcache is the better in-memory representation).
- **`cache_l1_hit_total` Prometheus counter** (v2.3) — alongside
  `cache_hit_total` / `cache_miss_total`, lets operators see the three-layer
  hit distribution.

## Three-layer storage

```text
┌─ FrankenPHP-Worker RAM ─────────────────────────────────────────┐
│  OPcache (compiled PHP code)         → PhpFrontend `.php` files │
│  Payload L1 (decompressed bytes)     → VariableFrontend caches  │
│  Metadata L1 (CacheMetadata objects) → all caches               │
└─────────────────────────────────────────────────────────────────┘
                              ▲
┌─ Pod-local emptyDir ────────────────────────────────────────────┐
│  /<localPath>/<shard>/<sha256>[.php]                            │
│  • VariableFrontend: 1-byte marker + compressed bytes           │
│  • PhpFrontend: plain text PHP with `.php` suffix               │
│  → source of truth for the payload bytes                        │
└─────────────────────────────────────────────────────────────────┘
                              ▲
┌─ Metadata cache (Valkey / DB) ──────────────────────────────────┐
│  ~300-byte records: { hash, checksum, lifetime, tags, state }   │
│  → source of truth for cluster-wide cache validity              │
│  → no PHP code, no payload bytes                                │
└─────────────────────────────────────────────────────────────────┘
```

## Architecture in one diagram

> This package knows **nothing** about Redis/Valkey/KV stores. It speaks only
> to the TYPO3 cache API and delegates cluster persistence to a TYPO3 cache
> backend of your choice.

```text
TYPO3 Cache API → ClusterFileBackend
                      │
                      ├─► Metadata cache (a second TYPO3 cache frontend;
                      │   backend is your choice: Typo3DatabaseBackend,
                      │   KeyValueBackend, MemcachedBackend, …)
                      │
                      └─► Local payload store (pod-local, emptyDir)
```

## What it is

- **No RWX volume** required between pods.
- **Central cache validity** via the TYPO3 cache API.
- **Deterministic re-materialisation** via sha256 hash validation.
- **Tag-based invalidation** cluster-wide (through TYPO3's `TaggableBackendInterface`).
- **Garbage collection** via CLI (`clusterfilebackend:gc`) — delegated to the
  metadata cache backend.
- **Deployment-time warm-up** via CLI (`clusterfilebackend:warmup`) and a
  listener on TYPO3's `CacheWarmupEvent`.

## What it is **not**

- Not a replacement for TYPO3 FAL.
- Not a replacement for fileadmin.
- Not a replacement for TYPO3 Core's code cache (`var/cache/code/core` stays
  in the container image).
- Not a session store.
- Not a generic blob store.
- Not a distributed filesystem.
- **Carries no Redis/Valkey knowledge.** If you want Redis as cluster storage,
  install a TYPO3 cache backend for it (e.g. `moselwal/keyvalue-store`'s
  `KeyValueBackend`) and point `ClusterFileBackend` at it via
  `metadataCacheIdentifier`.

## Setup prerequisites — what YOU need to do

> This package intentionally ships **no automatic cache registration**.
> Hostnames, ports, TLS, paths are inherently site-specific. The steps below
> are one-time setup.

### Required steps

1. **Install via Composer** (see below).
2. **Provide a cluster-capable cache backend for metadata.** Out of the box,
   the default example uses TYPO3 Core's `Typo3DatabaseBackend`, which works
   without any extra dependency as long as your database is reachable from
   all pods (Galera, RDS Multi-AZ, etc.). For higher performance, install
   `moselwal/keyvalue-store` and use its `KeyValueBackend`.
3. **Register a TYPO3 cache frontend** (we call it `cluster_meta` by
   convention) that holds the metadata.
4. **Reconfigure the file-based TYPO3 caches** (`pages`, `pagesection`,
   `rootline`, `imagesizes`, `assets`, `hash`) to use `ClusterFileBackend`
   and reference `cluster_meta` via `metadataCacheIdentifier`.
5. **Mount a pod-local `emptyDir`** at `/app/var/cache/cluster/` (or
   wherever `localPath` points).

### What we ship

| Artefact | Path in package | Purpose |
|---|---|---|
| **Default config (no extra deps)** | `Configuration/Example/cache-configurations.example.php` | Database-backed metadata + cluster file caches. Works on any TYPO3 install. |
| **Redis/Valkey config (optional)** | `Configuration/Example/cache-configurations-redis.example.php` | High-performance variant using `moselwal/keyvalue-store` |
| **JSON Schema** | `Configuration/Backend/ClusterFileBackend.options.schema.json` | Validated at backend construction; misconfiguration raises `InvalidCacheException` with the offending field |
| **CLI commands** | `Configuration/Commands.php` | `clusterfilebackend:gc`, `clusterfilebackend:warmup` |
| **Event listener** | `Configuration/Services.yaml` | Wires into TYPO3's `CacheWarmupEvent` so `bin/typo3 cache:warmup` triggers our warm-up too |
| **DI bindings** | `Configuration/Services.yaml` | Auto-discovery for `MetricsPort`, `ClockPort`, `CompressorPort` |

### Constructor validation

The `ClusterFileBackend` constructor validates options against a JSON schema.
**Mandatory fields** (otherwise `InvalidCacheException`):

- `localPath` (string, absolute path)
- `metadataCacheIdentifier` (string, name of the metadata cache frontend)
- `namespace.environment` (`prod` | `staging` | `testing` | `development`)
- `namespace.instance` (string, slug `[a-z0-9-]{1,64}`)

**Optional fields with defaults**:

| Option | Default | Meaning |
|---|---|---|
| `compression` | `zstd` | `zstd` \| `gzip` \| `none`. Forced to `none` for PhpFrontend caches regardless of this setting. |
| `serializer` | `igbinary` | `igbinary` \| `php`. Affects the payload hash; switching invalidates pre-switch entries. |
| `defaultLifetimeSeconds` | `3600` | TTL when the caller passes `null`. Minimum `1` (schema rejects `0`). |
| `maxPayloadBytes` | `10485760` (10 MB) | Writes larger than this are rejected with `InvalidDataException`. Also the upper bound for decompressed reads (zstd-bomb mitigation). |
| `minCompressedBytes` | `1024` | Payloads below this size are stored uncompressed (1-byte marker = `0x00`). Skip-compress saves the gzdeflate/zstd_compress fixed cost on tiny values. `0` = always compress. |
| `payloadL1MaxEntries` | `32` | Maximum entries in the request-scoped payload L1. `0` disables the payload memoization (the metadata L1 stays active). LRU eviction. |
| `payloadL1MaxBytes` | `4194304` (4 MB) | Soft memory budget for the payload L1. Entries that would push the total above are evicted in insertion order; a single payload larger than this bypasses the L1 entirely. `0` = no byte budget (entry-count cap only). |
| `backendVersionEnvVar` | `IMAGE_TAG` | Env var carrying the deploy-scoped identifier. Folded into every payload hash so every deploy gets a fresh path tree. Override for CI conventions like `CI_COMMIT_SHA`. |

If the configured `metadataCacheIdentifier` is not registered as a TYPO3
cache, the constructor fails **immediately** with a message that names the
config path — no silent failure on first `set()`.

## Installation

```bash
composer require moselwal/cluster-file-backend:^2.3
# Optional for production: Redis/Valkey backend with TLS / Sentinel
composer require moselwal/keyvalue-store
```text

When `moselwal/typo3-config` (≥ 5.4) is also installed, file-backed TYPO3
caches are auto-rewired onto `ClusterFileBackend` via
`Config::useClusterFileBackend()`. The Bootstrap-phase caches (`core`,
`assets`, `database_schema`) are excluded by default because they are
instantiated before the Symfony DI container exists.

## Configuration

### Quick start (zero extra dependencies)

Copy the contents of
`vendor/moselwal/cluster-file-backend/Configuration/Example/cache-configurations.example.php`
into your `config/system/settings.php` (or `additional.php`) and adjust
`environment`, `instance`, and `localPath` to your deployment.

This example uses TYPO3 Core's `Typo3DatabaseBackend` for the metadata cache
— no extra Composer dependency required. It's cluster-safe when your
database is clustered.

### Redis/Valkey variant

For sub-millisecond metadata latency, copy
`Configuration/Example/cache-configurations-redis.example.php` instead. It
uses `moselwal/keyvalue-store`'s `KeyValueBackend` with optional TLS and
Sentinel support.

### Manual setup

**Step 1**: Define a TYPO3 cache frontend that persists metadata. Any backend
that implements `TaggableBackendInterface` (for `flushByTag` support) works.

```php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['cluster_meta'] = [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend'  => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
    'options'  => [],
    'groups'   => ['system'],
];
```

**Step 2**: Point `ClusterFileBackend` at the metadata cache.

```php
foreach (['pages', 'pagesection', 'rootline'] as $cacheName) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$cacheName] = [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend'  => \Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\ClusterFileBackend::class,
        'options'  => [
            'localPath'               => '/app/var/cache/cluster/' . $cacheName,
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => [
                'environment' => 'prod',
                'instance'    => 'website-a',
            ],
        ],
        'groups' => ['pages'],
    ];
}
```text

## Kubernetes deployment (excerpt)

```yaml
volumes:
  - name: cluster-cache
    emptyDir: { sizeLimit: 2Gi }
volumeMounts:
  - name: cluster-cache
    mountPath: /app/var/cache/cluster
```

### Deployment-time warm-up

After a rolling deploy you typically want every new pod to verify it can
reach the metadata cache and that its `localPath` is writable before it
starts serving requests. Trigger our warm-up explicitly:

```bash
./vendor/bin/typo3 clusterfilebackend:warmup \
    --namespace=cfb:prod:website-a:pages \
    --namespace=cfb:prod:website-a:pagesection \
    --namespace=cfb:prod:website-a:rootline
```text

The command emits one JSON line per namespace and exits non-zero if any
namespace fails health checks. Hook this into your readiness/startup probe
or post-deploy job.

Alternatively, run TYPO3's standard cache warm-up — our event listener
hooks into `cache:warmup` automatically:

```bash
./vendor/bin/typo3 cache:warmup
```

### Garbage collection

```yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: clusterfilebackend-gc-pages
spec:
  schedule: "*/15 * * * *"
  concurrencyPolicy: Forbid
  jobTemplate:
    spec:
      template:
        spec:
          containers:
            - name: typo3-cli
              args: ["clusterfilebackend:gc", "--namespace=cfb:prod:website-a:pages"]
```text

## Cluster consistency — what happens on cache-clear?

> Frequently asked: "When an editor clicks *Clear all caches* in the TYPO3
> backend, how do we make sure **all** pods see it?"

Short answer: the pod handling the click clears the central metadata cache.
All other pods see it on their next `get()` because they query the central
metadata cache, not their local filesystem. **No pod-to-pod sync needed**,
because metadata truth never lives on a pod.

### Detailed flow

```text
Pod A: TYPO3 backend "Clear all caches" / editor saves page /
       `bin/typo3 cache:flush`
   │
   ▼
ClusterFileBackend::flush()                 on pod A
   │
   ▼  delegates to metadata cache frontend (e.g. cluster_meta)
$metadataCache->flush()
   │
   ▼  TYPO3 cache API calls the configured backend
KeyValueBackend / DatabaseBackend / MemcachedBackend → flush()
   │
   ▼  happens SERVER-SIDE (Redis FLUSHDB, SQL TRUNCATE, Memcached flush_all)
All pods see the empty metadata immediately
```text

On the next `get(id)` on **any** pod:

```php
$metadata = $this->metadataCache->get($identifier);   // → null (cache flushed)
if ($metadata === null) {
    // cache_miss_total{reason=no-metadata}++
    return null;   // ← pod does NOT consult its local FS
}
```

### Verified by test suite

`Tests/Unit/Deployment/CrossPodFlushTest.php` contains five tests proving:

- `flush()` propagates to pod B immediately, no sync.
- `flushByTag()` invalidates only matching entries.
- Local file survives flush as harmless orphan.
- Re-write after flush re-establishes consistency.
- Flush works for arbitrary numbers of pods (no scaling assumption).

## Complexity — why it's faster in a cluster

Notation: *n* = total entries in the namespace, *m* = entries matching a
given tag (*m* ≤ *n*), *k* = expired entries at GC time (*k* ≤ *n*), *p* =
number of pods in the cluster.

| Operation | TYPO3 Core FileBackend | ClusterFileBackend | Speedup |
|---|---|---|---|
| `flushByTag` | **O(*n*)** — DirectoryIterator over every cache file, 2× `file_get_contents` per file | **O(*m*)** — backend reads tag index directly | Different complexity class + tag indexes |
| `findIdentifiersByTag` | O(*n*) | O(*m*) | same |
| `collectGarbage` | O(*n* · *p*) — every pod scans its own copy | **O(1)** client-side (Redis TTL auto-expire is asynchronous server work) or O(*k*) server-side (DB) | Backend-native + cluster-once |
| `flush` | O(*n* · *p*) — every pod unlinks its own copy | O(*n*) **once server-side** | No pod multiplier; constants ~100–1000× smaller |

**Concrete example**: *n* = 10,000 cache entries, *m* = 100 tagged `site_1`,
*p* = 5 pods.

| | File reads | unlink calls | Round-trips |
|---|---|---|---|
| Core FileBackend (`flushByTag('site_1')`) | **20,000** | 100 | ~20,100 local FS I/O **per pod** |
| ClusterFileBackend (Redis) | 0 | 0 | ~2 (SMEMBERS + pipeline DEL) **once cluster-wide** |

It's not just "smaller *n*": **different complexity class**,
**backend-native algorithms**, and **no pod multiplier (·*p*)**.

## Development

```bash
composer install
composer test               # Unit tests
composer phpstan            # PHPStan level 8 + bleeding edge + deprecation rules
composer deptrac            # DDD layer enforcement
composer cs:check           # @Symfony + @PER-CS3x0 + @PHP85Migration via moselwal/dev
composer qa                 # Aggregate of all checks above
```text

REUSE/SPDX header conformance is checked in CI via the official
`fsfe/reuse:latest` Docker image (see `.gitlab-ci.yml`); for local
verification run `docker run --rm -v "$(pwd):/data" fsfe/reuse lint`.
TYPO3 14 deprecation usage is detected by
`phpstan/phpstan-deprecation-rules`, loaded automatically as part of
`composer phpstan`.

## Rolling deploys with version skew

During a rolling deploy old and new pods serve traffic simultaneously.
ClusterFileBackend keeps **correctness** in every skew scenario, but two
cases are worth understanding because they change the **performance**
profile of the deploy window.

### A) Application code with changed cache layout

If the new image writes a different shape of payload for the same cache
identifier (extra fields, different serialised classes, changed value
objects), and you do **not** explicitly invalidate, the following happens:

1. Pod-old writes payload v1 → metadata stores hash_v1.
2. Pod-new reads, sees hash_v1, has no local blob → blob-miss → TYPO3
   frontend calls the caller-rebuild → pod-new writes payload v2 →
   metadata is overwritten with hash_v2.
3. Pod-old reads, sees hash_v2, has no local blob → blob-miss → rebuilds
   v1 → metadata back to hash_v1.
4. **Hash-thrashing** for the duration of the rolling deploy.

The bigger risk is **silent layout drift**: if pod-new is technically able
to deserialise pod-old's bytes but the resulting object is wrong (missing
fields, old enum cases, removed class properties), the user sees stale or
corrupt content. PHP's `unserialize` does **not** verify class shape
beyond the class name.

**Recommended: tie the cache identity to your deploy** so every release
automatically gets a new BackendVersion and stale entries become
unreachable. ClusterFileBackend reads an environment variable — by
default `IMAGE_TAG` — and folds its value into the payload hash via
crc32. Set this in your deployment manifest:

```yaml
# Helm values, Kustomize patch, or plain Pod spec
env:
  - name: IMAGE_TAG
    value: "{{ .Values.image.tag }}"  # or $CI_COMMIT_SHA, release semver, …
```

Override the variable name per cache if you use a different CI convention:

```php
'options' => [
    'localPath'              => '/app/var/cache/cluster/pages',
    'metadataCacheIdentifier' => 'cluster_meta',
    'namespace'              => ['environment' => 'prod', 'instance' => 'site'],
    'backendVersionEnvVar'   => 'CI_COMMIT_SHA',
],
```text

When the variable is unset or empty, the backend falls back to the
package-internal `BackendVersion::current()` — safe for local
development, but **explicitly wire the variable in production** to get
deploy-scoped invalidation.

**Alternative invalidation strategies** (if `IMAGE_TAG`-based bumping
doesn't fit your release model):

- **Run `clusterfilebackend:warmup` with a pre-flush** in your deploy
  pipeline. Drains stale entries before the new image takes traffic.
- **Rename the cache identifier** (e.g. `pages` → `pages_v2` in
  `cacheConfigurations`). Heavy hammer, only for large schema reworks.

If the layout change is **non-breaking** (additive, ignored-by-old-code),
you can accept the temporary thrashing — correctness is preserved.

### B) PHP major.minor version change

The identity hash includes `PHP_MAJOR.PHP_MINOR`
(`Classes/Application/Hash/ComputePayloadHash.php`). PHP 8.4 ↔ 8.5 (or
any other major.minor jump) automatically produces divergent hashes — no
manual action required. Correctness is guaranteed.

The cost is the same thrashing pattern as in case A) for the duration of
the rollout. Watch `blob_miss_total` in Prometheus; a sustained spike
beyond the deploy window indicates the version skew did not converge
(e.g. one pod stuck in the old image).

PHP **patch** updates (8.5.4 → 8.5.5) do **not** invalidate — only major
and minor are in the hash.

### Operational recommendation

For a stateless cluster:

- **Patch updates** (igbinary patch, PHP patch, app bug-fix without cache
  layout change): plain rolling deploy, no extra steps.
- **Minor / major updates** (PHP minor bump, BackendVersion bump, cache
  layout change): plain rolling deploy still safe but expect a
  blob-miss spike. For zero-degradation deploys, run a `Recreate`
  strategy or pre-flush via the warm-up command.

## Operational requirements

### Pod clock synchronisation

Cache lifetimes are evaluated against each pod's local clock
(`SystemClock::now()` → `time()`). If pods disagree on wall-clock time by
more than a few seconds, a pod whose clock runs ahead will treat entries
as expired earlier than peers — leading to extra rebuilds (correctness
stays intact, only performance degrades).

In Kubernetes this is normally a non-issue: nodes run `chrony` or
`systemd-timesyncd` against the cluster NTP, and pods inherit the node
clock. Worth a sanity check during incidents:

```bash
kubectl exec deploy/typo3 -- date -u
```

A skew above ~30 seconds across pods is the threshold where
`blob_miss_total` and `cache_miss_total{reason=expired}` start to drift
visibly in Prometheus.

### Metadata cache availability

The metadata cache (Redis/Valkey/DB) is the single source of truth.
When it is unreachable:

- **Reads degrade gracefully** to cache misses; the TYPO3 frontend
  triggers caller rebuilds. The application keeps serving, but upstream
  load (DB queries, render time) spikes.
- **Writes surface the underlying exception** so the caller can decide
  how to handle it. This is intentional — silently swallowing write
  failures would mask outages.

Alert on `cache_miss_total{reason=metadata-error}` for early detection.

### Required metadata-cache backend capabilities

The metadata cache MUST be backed by a TYPO3 cache backend that
implements `TaggableBackendInterface`. Otherwise `flushByTag` becomes a
no-op (the entire tag-based invalidation flow silently does nothing).
Verified backends:

| Backend | Taggable | Notes |
|---|---|---|
| `Typo3DatabaseBackend` | ✅ | zero-dependency default |
| `KeyValueBackend` (moselwal/keyvalue-store) | ✅ | Redis/Valkey, recommended for high-traffic |
| `MemcachedBackend` (TYPO3 core) | ❌ | does NOT support tags — incompatible |
| `RedisBackend` (TYPO3 core) | ❌ | TYPO3's built-in Redis backend is not taggable; use moselwal/keyvalue-store instead |

### Deploy-time IMAGE_TAG consistency

Every container that talks to the same metadata-cache backend MUST see
the same `IMAGE_TAG` (or whatever variable is configured via
`backendVersionEnvVar`). If the web pod runs `IMAGE_TAG=1.2.3` and a
worker / cron pod still runs `IMAGE_TAG=1.2.2`, the two will compute
different `BackendVersion` values and treat each other's writes as
blob-misses. Symptom: persistent thrashing in mixed deployments.

Helm/Kustomize tip: extract the tag into a single value and reference
it from every Pod spec, instead of per-deployment hard-coding.

### Y2K38 limitation for unlimited-lifetime entries

`Lifetime::unlimited()` maps to `expiresAt = 2147483647` (mirrors TYPO3
core's `Typo3DatabaseBackend::FAKED_UNLIMITED_EXPIRE`). On 2038-01-19
03:14:07 UTC that timestamp becomes "now" and any entry that was set as
unlimited will be considered expired. Practical impact between now and
then: none. After: bump the constant or, more cleanly, migrate to a
64-bit-safe expiry sentinel in a major release.

### crc32-based BackendVersion folding

`BackendVersion::fromString(...)` folds the deploy identifier via crc32,
yielding a 32-bit integer. Birthday collisions occur at ~77k unique
deploy identifiers — extremely unlikely under realistic release cadence
(a few deploys per day for the lifetime of a project still leaves the
collision probability below 1 in 10⁵). If you regularly cycle through
thousands of distinct identifiers, consider truncating to a stable,
human-readable semver string instead of feeding raw commit SHAs.

## Common pitfalls

- **`localPath` must be writable.** With a read-only `/app` image, mount
  `emptyDir` / `tmpfs` at that path.
- **Identical container image across all pods.** Different PHP or igbinary
  versions produce divergent hashes → permanent blob-misses. Major versions
  are enough — patch versions are not in the hash (since v1.0.1).
- **Wire `IMAGE_TAG` (or your equivalent) in production.** Without it the
  backend uses a package-internal version constant that does NOT change
  across deploys — breaking cache-layout changes can then silently serve
  stale or corrupt content. See "Rolling deploys with version skew".
- **`metadataCacheIdentifier`** must be registered before any cache that
  uses `ClusterFileBackend`. TYPO3 loads `cacheConfigurations` in array
  insertion order — define `cluster_meta` first.
- **Composer mode only.** No `ext_emconf.php`, no Classic mode.

## License

MIT — see `LICENSES/MIT.txt`.
