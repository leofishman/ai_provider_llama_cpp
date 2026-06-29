# Upgrading to `ai_provider_llama_cpp` 2.0

> There is no 1.3 release. Development goes directly from the 1.2.x series to 2.0.

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

## In-place upgrade (1.2.x → 2.0)

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
   server(s) are present (the migrated one is labelled *"llama.cpp (migrated)"*,
   id `default`).
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

- **OpenAI-compatible providers** (e.g. the generic OpenAI provider pointed at a
  llama.cpp `/v1` endpoint): create a `llama_cpp_server` entity for the same host
  + port (+ Key for the API key if any), run `drush llama-cpp:discover-models`,
  then switch the relevant operation types in **AI → Settings** to the
  `llama_cpp` provider and the discovered model.
- **The native `ai_provider_ollama` module:** Ollama exposes an OpenAI-compatible
  endpoint as well; point a `llama_cpp_server` at it (or keep both providers
  installed side by side) and re-select per operation type. The two providers are
  independent and can coexist.

There is no automated cross-module migration; the step above (create server →
discover → re-select) is the supported path.

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
