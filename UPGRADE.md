# Upgrading to `ai_provider_llama_cpp` 2.0

> **Supported source versions: 1.0.x, 1.1.x and 1.2.x.** There is no 1.3 release —
> development goes from the 1.2.x series directly to 2.0. The update hooks cover
> every 1.x layout (see [What the update hooks do](#what-the-update-hooks-do)):
>
> - **1.0.x / 1.1.x** — single server stored in `ai_provider_llama_cpp.settings`
>   (host + port) plus a global State model catalog. `update_10201` migrates it
>   to a server entity with id `default`.
> - **1.2.x** — multi-server. Depending on your sub-version the servers may live
>   as `llama_cpp_server` config entities **or** only as per-server State keys
>   (`ai_provider_llama_cpp.server.<id>.*`). `update_10203` handles both, creating
>   any missing server entities so their models migrate.

> ⚠️ **This is an early release (`2.0.0-alpha*`).** It was tested as thoroughly as
> we could against real upgrade data, but **not** across every possible 1.x
> layout. Treat it accordingly:
>
> - **Take a full database + config backup before upgrading.** There is no
>   in-place downgrade (see [Rollback](#rollback)).
> - Run the upgrade on a copy/staging site first if you can.
> - **Please report any problem** you hit (with the `drush updb` output and your
>   source version) on the project's issue queue. Bug reports on this release are
>   genuinely useful and welcome.

2.0 is a structural release. The provider stops using **plugin derivers** (one
derived plugin per server) and moves to a **single provider plugin** backed by
two config entity types:

| Concept            | 1.2.x                               | 2.0                                   |
|--------------------|-------------------------------------|---------------------------------------|
| Provider plugin    | `llama_cpp` + derivatives (`llama_cpp:<server>`) | single `llama_cpp` plugin |
| Server config      | `ai_provider_llama_cpp.settings` (single) + State | `llama_cpp_server` config entities (multi) |
| Discovered models  | State (`*.models`, `*.model_types`, `*.model_overrides`) | `llama_cpp_model` config entities |
| API keys           | plaintext in config/State           | **Key** module entities               |

Because provider IDs and model IDs change, **the upgrade rewrites
`ai.settings.default_providers` for you** via update hooks — but you should still
verify your AI operation-type assignments afterward.

---

## Before you start

- **Back up** your database and exported config.
- Note your current AI default providers:
  `drush config:get ai.settings default_providers`.
- Ensure the **Key** module is available (it is a hard dependency in 2.0):
  `composer require drupal/key` if it is not already installed.

## In-place upgrade (1.0.x / 1.1.x / 1.2.x → 2.0)

```bash
# 1. Pull the new code.
composer require 'drupal/ai_provider_llama_cpp:^2.0' -W

# 2. Run database updates (the migration happens here).
drush updb

# 3. Rebuild caches.
drush cr
```

### What the update hooks do

The updates run in order and are idempotent (safe to re-run):

- **`update_10201`** — installs the `llama_cpp_server` entity type and migrates
  the single `ai_provider_llama_cpp.settings` config (host, port) into a server
  entity with the id **`default`**. Migrates the old State keys to the
  per-server namespace and remaps `default_providers` `llama_cpp` →
  `llama_cpp:default`. Deletes the obsolete simple config.
- **`update_10202`** — moves any plaintext API key off the server entity into a
  **Key** entity (`llama_cpp_<server_id>`, `config` provider) and points the
  server at it. Credentials never stay in config/State afterward.
- **`update_10203`** — installs the `llama_cpp_model` entity type, converts the
  State-based model catalog into `llama_cpp_model` entities
  (`<server>__<machine_name>`), then collapses derived provider ids
  (`llama_cpp:<server>`) back to the single `llama_cpp` plugin and **remaps each
  `model_id`** from the old per-server machine name to the new entity id.

  For 1.2.x multi-server setups whose extra servers existed only as State
  (evidenced by keys such as `ai_provider_llama_cpp.server.ollama.*` with no
  matching `llama_cpp_server` config entity), the update also auto-creates the
  corresponding `llama_cpp_server` entities so their models migrate. **1.x never
  stored per-server host/port for these**, so they are created with empty
  host/port and a "(migrated from 1.x state)" label. You must edit each one to
  enter the correct host and port, then re-run `drush llama-cpp:discover-models`.
  (Your single `default` server keeps its host/port, which did survive in
  config.)

> **Tip — with only a few servers, recreating them by hand is often simpler.**
> Because the automated migration can only recover per-server host/port for the
> single `default` server (1.x never stored it for the extra ones), a multi-server
> setup leaves you re-entering hosts and re-discovering models anyway. If you only
> have a handful of backends, it is usually faster and cleaner to skip the
> half-migrated shells and just recreate the servers manually:
>
> 1. **Configuration → AI → Providers → llama.cpp servers → Add server** — enter
>    host, port and (if needed) a Key for each backend. Saving discovers its models.
> 2. Delete the empty "(migrated from 1.x state)" shells you don't need.
> 3. Re-select your models per operation type in **AI → Settings**.
>
> You still get the automatic `default_providers` remap from the update hooks; this
> just trades a few minutes of clicking for not having to fix up incomplete entities.

> **Read the `drush updb` output.** If a model reference could not be
> auto-remapped, `update_10203` reports the affected operation types, e.g.:
>
> ```
> Migrated llama.cpp providers. Remapped models for: chat, embeddings.
> Could not auto-map models for: moderation — please re-select them in the AI settings.
> ```
>
> For each unmapped operation type, go to **Configuration → AI → Settings** and
> re-select the model.

## After upgrading

1. Visit **Configuration → AI → Providers → llama.cpp servers** and confirm your
   server(s) are present. The main one will be labelled *"llama.cpp (migrated)"*
   (id `default`). Additional servers from 1.x state data will have
   "(migrated from 1.x state)" labels and empty host/port — **edit them to
   fill the correct host and port**, then re-discover models.
2. Re-discover models if needed (e.g. on a fresh environment with no State to
   migrate):

   ```bash
   drush llama-cpp:discover-models           # all servers
   drush llama-cpp:discover-models default   # one server
   ```

3. Verify `ai.settings`:
   `drush config:get ai.settings default_providers` — every `provider_id` should
   now be `llama_cpp` (no `llama_cpp:` prefix) and each `model_id` should match an
   existing `llama_cpp_model` entity id.

## Fresh environments / CI (no State to migrate)

Model entities are config, but they are only **written on an explicit action**
(server form save or the Drush command) — never on a read. After importing config
on a new environment, run discovery to reconcile the catalog against the live
server:

```bash
drush llama-cpp:discover-models
```

---

## Migrating from other AI provider modules

If you currently reach a llama.cpp / OpenAI-compatible server through a different
provider module:

- **OpenAI-compatible providers** (e.g., `ai_provider_openai` pointed at a
  llama.cpp or vLLM `/v1` endpoint): Create a `llama_cpp_server` entity for the same host
  and port (create a Key entity for the API key if it's required). Run
  `drush llama-cpp:discover-models` to populate the model entities, and finally switch the
  relevant operation types in **AI → Settings** to the `llama_cpp` provider, selecting your model.
- **The native `ai_provider_ollama` module:** Ollama natively supports the OpenAI `/v1`
  API. You can point a new `llama_cpp_server` at your Ollama instance (`http://127.0.0.1:11434`),
  discover models, and re-select them. Alternatively, you can keep both `ai_provider_ollama`
  and this module running side by side (e.g., use the Ollama provider for standard chat and
  this provider for embedding or Moderation via ShieldGemma).

There is no automated cross-module migration; you must manually:
1. Create your `llama_cpp_server`
2. Run model discovery
3. Re-select your models in the AI settings page.

## Compatibility

- **Drupal:** `^10.2 || ^11 || ^12`.
- **drupal/ai:** `^1.2`. Optional integrations (e.g. AI 1.3+ Guardrails) are
  feature-detected and stay optional; the module targets AI `^1.2` as the floor.
- **PHP:** `^8.3`.
- **Key:** `^1.18` (required).

## Rollback

2.0 deletes the old `ai_provider_llama_cpp.settings` config and converts State to
config entities, so there is no in-place downgrade. To roll back, restore the
database + config backup taken before the upgrade.
