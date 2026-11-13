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

## Performance notes

Measured on aarch64 (Graviton) in a PHP 8.5.12 production image; numbers
from a cold cache unless stated otherwise.

- **Install `ext-openssl`** (recommended, optional). Every write and every
  full verification hashes the whole payload with sha256. ext-hash does that
  in portable C; OpenSSL uses the CPU's SHA instructions: 2.2 MB in 1.0 ms
  instead of 15.9 ms. The backend uses OpenSSL when it is loaded, offers
  sha256 and passes a known-answer check once per process, and falls back to
  `hash()` otherwise -- same digest either way, so pods with and without
  OpenSSL share one cache.
- **One sha256 pass for PHP caches without OpenSSL.** The payload identity
  takes its context as a suffix (`BackendVersion` 3), so a PHP cache's
  checksum comes from the same ext-hash pass (`hash_copy()`): 21.6 -> 10.6 ms
  for 2.2 MB.
- **Option validation once per process.** TYPO3 builds every cache backend
  again per request, also in a FrankenPHP worker with a persistent
  bootstrap. The JSON-schema validation of the options now runs once per
  distinct option set and process: 164 -> 9.5 µs per backend.
- **One `lstat()` per require.** `doRequire()` and the payload store read the
  file type, the fingerprint and the mtime from a single `lstat()` instead of
  `is_file()` + `is_link()` + `lstat()` + `filemtime()`. A symlink is still
  refused on every call -- now also one that points nowhere.
- **Nothing rewritten when nothing changed.** A `set()` that would store the
  same bytes, tags and format for an entry that never expires (every PHP
  cache entry) leaves the metadata alone and writes the file only if this
  pod lacks it: one metadata read instead of five round trips, and no
  generation bump that would empty every worker's metadata memo. Counter
  `repair_unchanged_total`.
- **`open_basedir` costs more than all of the above.** PHP switches the
  realpath cache off while `open_basedir` is set (`realpath_cache_size()`
  returns 0 whatever `realpath_cache_size` says), so every file operation
  walks the path again: `is_file()` 20 µs instead of 1.5 µs, a repeated
  `require()` of a code cache 75 µs instead of 14 µs. This affects all of
  TYPO3, not only this backend. Whether the hardening is worth it is a
  decision for your platform; with a read-only root filesystem and a
  non-root user much of what it protects is covered already.

## Three-layer storage

```text
┌─ FrankenPHP-Worker RAM ─────────────────────────────────────────┐
│  OPcache (compiled PHP code)         → PhpFrontend `.php` files │
│  Payload L1 (decompressed bytes)     → VariableFrontend caches  │
│  Metadata L1 (CacheMetadata objects) → all caches               │
│  Metadata memo across requests (opt-in) → all caches            │
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
- Local file survives flush as an orphan until the local pass of `clusterfilebackend:gc` (also run by the scheduler's cache GC task via `collectGarbage()`) removes it after `--local-max-age` seconds (default 7 days) without a verified read.
- Re-write after flush re-establishes consistency.
- Flush works for arbitrary numbers of pods (no scaling assumption).

## Cross-request metadata memo

Without it, every request reads the metadata of every identifier it
touches once: that read is how a pod learns about other pods' writes and
flushes. A warm, uncached page render costs some 40 such reads. With the
option `crossRequestMetadataMemo` on, a request reads **one** value
instead — the generation of the metadata store — and serves metadata from
a memo kept in the PHP process (in FrankenPHP worker mode: across
requests) for as long as that generation is unchanged.

```php
'options' => [
    // …
    'crossRequestMetadataMemo' => true,   // default false, see "Rolling it out"
],
```

The gain needs a process that serves many requests: FrankenPHP worker
mode. In classic mode (one PHP state per request), PHP-FPM or a CLI run the
memo starts empty every time, so the option only adds the one generation
read per request.

### How it works

- **Generation.** One value per metadata cache, shared by every cache
  that uses that metadata cache. It is store-wide on purpose: entries are
  keyed by the bare cache identifier, so all caches share one key space,
  and the namespace `instance` may differ between pods (it defaults to the
  hostname in some setups). Where it lives depends on the metadata
  backend:
  - a backend that keeps generations itself (`KeyValueBackend` of
    `moselwal/keyvalue-store` from 4.9.0): a raw key of the backend,
    `<keyPrefix>generation:cfb_generation_v2`, holding the plain string
    `<random>` or `dirty-<random>` — not an entry, never serialized;
  - any other backend (`Typo3DatabaseBackend`, …): the entry
    `__cfb_generation_v1__` of the metadata cache, written through the
    frontend. This is also the only key releases before the raw key know
    ("legacy key" below).
- **Every mutation** through this package moves the generation, whether or
  not the memo is on. The invariant: *no mutation completes without the
  generation taking a value it never had before, or holding a dirty
  marker.* Two forms keep it:
  - **Three steps** — `remove`, `flush`, `flushByTag(s)`,
    `collectGarbage` (backend and CLI) on every backend, and `set` on a
    backend that only keeps entries: write `dirty-<random>`; mutate;
    publish a fresh `<random>` unless the generation is *another*
    writer's dirty marker. On a backend with its own generation the
    publish is one conditional write on the server (three round trips in
    all, generation writes included); otherwise a read and a write (four).
  - **One step** — `set` (which includes broken-markers) on a backend with
    its own generation: the entry, its tag index and a fresh `<random>`
    generation are written by one server-side script, atomically, in the
    round trip the set takes anyway. There is no moment at which the entry
    has changed and the generation has not, so no dirty marker is needed.
    If the generation is another writer's dirty marker, the script writes
    the entry and **leaves the marker**: that writer is in the middle of a
    multi-step mutation and publishes after it; putting a clean value over
    its marker would leave a clean generation over a half-applied mutation
    if its publish then failed.
  Measured once (2026-11-13, PHP 8.5.8 against a local Valkey 9.1.3
  behind a Docker port forward, where one round trip costs about 0.35 ms;
  medians, metadata with two tags): `set` 1,500 µs → 410 µs, 40 sets as in
  a cold render 61.5 ms → 16.6 ms, `remove` 1,490 µs → 1,050 µs. The
  ratios are the round trips, 4 → 1 and 4 → 3; on a production network
  each round trip is some 50 µs.
- **Every request** that touches metadata reads the generation once
  (shared by all caches of the request). Same clean value as the memo was
  filled under, and the memo younger than 5 minutes → serve from the memo.
  Different clean value → empty the memo, refill it under the new value.
  Dirty, absent or unreadable → empty it and read per entry, as without
  the memo.
- Entries are served only until their own `expiresAt`; `broken` markers are
  never memoised; the process's own mutations empty its memo for the rest
  of the request (the request-scoped memo keeps read-your-writes).
- The memo holds at most 2048 entries per metadata cache and process
  (about 3 MiB); beyond that, further identifiers are read per request.

### Why it is as consistent as per-entry reads

A change completed on pod B is visible on pod A at A's next request.
Generations are random, so each value is written exactly once. Entries are
memoised only after the generation check, so everything in a memo filled
under generation G was read after G was written. Every mutation is
followed by a write of a value other than G — its own publish step, or
(when it defers to another writer's dirty marker, or a one-step set leaves
one in place) that writer's later publish — or, for a one-step set, is
that write: entry and new generation change in the same atomic step. So
if A's next request still reads G, every mutation completed by then wrote
its metadata no later than G was written, i.e. before A read it.

Failure cases:

| Case | Behaviour |
|---|---|
| Dirty-marker write fails | The mutation is not attempted; the error surfaces exactly like a metadata-cache outage today. |
| One-step set fails | Entry and generation are one script: either both changed or neither did. The error surfaces like a failed metadata write. (Inside the script the generation is written first, so a server that aborts it half-way leaves a moved generation, never a changed entry under the old one.) |
| One-step set meets another writer's dirty marker | The entry is written, the marker stays, counted as `metadata_generation_publish_total{result=deferred}`. Readers read per entry until that writer publishes. |
| Mutation fails half-way | The publish step still runs. |
| Publish write fails / process dies mid-mutation | The generation stays dirty: every pod reads per entry (pre-memo behaviour) until the next successful mutation. Counted as `metadata_generation_publish_total{result=error}`. |
| Generation evicted, expired or flushed with the whole metadata cache | Absent = per-entry reads until the next mutation. |
| Entry expired or evicted by the store without a mutation | The memo honours `expiresAt`; anything else is re-read after at most 5 minutes. |
| Two pods mutate concurrently in three steps **and** the final publish of one of them fails | On a backend that only keeps entries, the publish is a read followed by a write, and the second pod's dirty marker replaces the first's: a reader may keep a pre-mutation entry for at most 5 minutes (the memo's maximum age). On a backend with its own generation the publish is a compare-and-set and a one-step set never replaces a marker, which leaves only the overlap of two multi-step mutations (two flushes, say) where the later one's marker replaced the earlier one's, the later one published, and the earlier one's publish then failed. Same bound. |

### Rolling it out (version skew)

Pods that run an older package version mutate the metadata cache
**without** touching the generation. A pod with the memo on would not
notice those changes (an editor's save on an old pod would stay invisible
on a new pod). Pods with the new version but the memo off maintain the
generation, so they are safe neighbours. Hence:

1. Deploy this version with the option off (the default). Every pod now
   maintains the generation; behaviour is otherwise unchanged.
2. Once no pod, worker or cron job runs an older version, switch the
   option on. A rolling restart mixing "on" and "off" pods is safe.
3. To roll back to an older package version, switch the option off first.

This concerns versions that do not maintain a generation at all. Versions
that maintain it in different places are compatible without a switch, see
the next section.

### Moving the generation into the backend (version skew)

Nothing to switch: a pod uses the raw key as soon as it runs this version
with `moselwal/keyvalue-store` ≥ 4.9.0 as the metadata backend, and the
legacy key otherwise. Both kinds of pod can run next to each other — in a
rolling deploy, a rollback, or for good — because the newer side follows
three rules:

1. **A reader trusts the raw generation only while the legacy key is
   absent.** A legacy writer writes the legacy key before it mutates and
   again after; as long as that key exists, pods on the raw key read per
   entry. One script returns both the raw generation and whether the
   legacy key exists, so this costs no extra round trip.
2. **The one-step set refuses while the legacy key exists** and the set
   runs in three steps instead. So an entry never changes under a legacy
   generation that a legacy reader still trusts. (Counted as
   `metadata_generation_atomic_refused_total`.)
3. **The three steps also speak legacy while the legacy key exists:** the
   dirty marker goes to the raw key and to the legacy key (exactly a legacy
   writer's first step, so legacy readers stop using their memos), and
   after publishing the raw generation the pod removes the legacy key —
   unless it holds a dirty marker of another writer, who is still mutating
   and will publish itself. A removed legacy key reads as "absent" on
   legacy pods: per-entry reads.

What that means in practice:

| Situation | Legacy pods (memo on) | Pods on the raw key (memo on) |
|---|---|---|
| Only legacy pods write | memo works | per-entry reads (legacy key exists) |
| Both kinds write (rolling deploy) | per-entry reads whenever a newer pod wrote last | per-entry reads whenever a legacy pod wrote last |
| Only pods on the raw key write | per-entry reads (legacy key absent) | memo works; the first mutation removed the legacy key, every later `set` is one round trip |

Nobody serves stale metadata in any row; a mixed fleet only loses the
memo's saving for as long as it is mixed. The first mutation after the
last legacy write costs seven round trips once (the refused set, two dirty
markers, the set, the publish, reading and removing the legacy key).

The one case not excluded is the one the legacy protocol never excluded
between its own writers: a legacy writer is still mutating when a newer
pod's dirty marker replaces its marker in the legacy key, and the legacy
writer's own final write then fails. Bounded by the memo's maximum age.

`moselwal/keyvalue-store` < 4.9.0, or a backend of another kind, simply
keeps the legacy key and the three steps through the frontend; nothing in
the configuration changes either way.

### Metrics

| Counter | Labels |
|---|---|
| `metadata_memo_check_total` | `result`: `reuse` (memo served), `reset` (generation changed), `bypass` (dirty/absent/unreadable) |
| `metadata_memo_hit_total` / `metadata_memo_miss_total` | per lookup below the request-scoped memo |
| `metadata_memo_full_total` | identifier not memoised because the memo is full |
| `metadata_generation_publish_total` | `result`: `published`, `deferred` (another writer's dirty marker was left in place), `error`; one per mutation, one-step sets included |
| `metadata_generation_atomic_refused_total` | one-step sets that ran in three steps because the legacy key existed; non-zero only while legacy pods write |

`ClusterFileBackend::isCrossRequestMetadataMemoEnabled()` tells whether a
cache runs with the memo.

## Cross-request PHP payload verification

Code caches (`typoscript`, `fluid_template`) are executed with `require`,
so before a payload file runs, `doRequire()` checks it: the path must not
be a symlink (one `lstat()`, on every call -- a link that points nowhere is
refused as well), and the file's sha256 must match
the checksum in the metadata. Without this option the sha256 check runs on
the first require of each payload in every request; TYPO3 14 requires some
25 TypoScript payloads per page, up to 1.3 MiB each, which is roughly 10 ms
of hashing per uncached render.

With `crossRequestPhpVerification` on, the verification is remembered in
the process (in FrankenPHP worker mode: across requests), keyed by the
file's **device, inode, size and ctime** as one `lstat()` reports them --
the same `lstat()` that answered the symlink check.
On every require (not only the first of a request) the fingerprint is
taken again: unchanged, the file is executed without being read; any
difference means a full verification, exactly as without the option.

```php
'options' => [
    // …
    'crossRequestPhpVerification' => true,   // default false
],
```

Threat model: the check defends the pod-local payload directory against
a process that can write there but is not root. It cannot change a file's
content or swap the file without the kernel setting a new ctime (or giving
the path another inode), and only root can set ctime. Root in the
container, and anyone who can write the metadata cache (it states the
checksum), are out of scope, as they are without the option. ctime has
whole-second resolution in PHP, and the kernel stamps file times from a
coarse clock that can run behind the wall clock, so a write just after a
second boundary may still carry the previous second. A fingerprint is
therefore only recorded when the file's ctime lies at least two seconds
before the check (`VerifiedPayloadFingerprints::SETTLE_SECONDS`); a file
written less than two seconds earlier is verified again on its next
require. Up to v2.8.1 one second was assumed to be enough -- on a CI runner
a write right after the boundary kept the previous second's ctime and went
unnoticed. Once an hour a
payload is verified in full anyway: that read refreshes its mtime, which
keeps garbage collection away from payloads in use.

The option is pod-local: no other pod needs to run a particular version
first. `moselwal/typo3-config` switches it on with
`CLUSTER_CACHE_PHP_VERIFY_MEMO=1`. Counter
`php_payload_fingerprint_total` (`result`: `unchanged`, `verified`) shows
how often the hash was skipped;
`ClusterFileBackend::isCrossRequestPhpVerificationEnabled()` tells whether
a cache runs with it.

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
