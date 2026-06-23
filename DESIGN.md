# Design: Evolving ai_provider_llama_cpp toward a Universal Provider (2.0)

**Status**: Draft / Work in Progress  
**Branch**: `2.0`  
**Goal**: Turn the current powerful but specialized multi-instance llama.cpp provider into a solid foundation for a more universal AI provider module, while keeping a simple evolution and migration story.

## 1. Motivation

The current `ai_provider_llama_cpp` (1.2.x) has several strengths that users love:

- True multi-instance support via config entities (`llama_cpp_server`) + derivers.
- Excellent auto-detection of model capabilities (CLI flags, Hugging Face `pipeline_tag`, heuristics).
- Support for non-standard OpenAI-compatible operations: rerank, moderation (with custom parsers), speech-to-text, text-to-image.
- Model filtering and per-model overrides.
- Works with llama.cpp, Ollama, vLLM, LiteLLM, LM Studio, and many others.

However, the current architecture has limitations for long-term growth:

- Heavy reliance on **plugin derivers** (`llama_cpp:server_id`). This makes the module feel special-cased.
- Model discovery and capability data live primarily in **Drupal State**, not config entities (harder to export, view, version, or attach behavior to).
- One monolithic plugin + deriver makes it harder to add truly different provider types cleanly.
- Tight coupling to `OpenAiBasedProviderClientBase` for most paths.

The vision for 2.x is:
> Support **OpenAI-compatible backends** extremely well (current strength) + provide a clean path to add **specific provider plugins** over time (Hugging Face native tasks, Replicate, Fireworks, Groq, etc.), all within a unified experience for site builders.

We treat the current module as the **evolution** of `ai_provider_llama_cpp`. We will only consider a full rename (e.g. `ai_universal_provider`) once the 2.x line feels solid and stable.

## 2. Core Architectural Principles (2.0)

1. **Config entities are the source of truth for multiplicity**
   - `llama_cpp_server` (backend/connection configuration).
   - `llama_cpp_model` (discovered or manually registered models + their capabilities).

2. **Specific plugins instead of heavy derivation**
   - One stable plugin ID per *provider type* (`llama_cpp` for OpenAI-compatible today).
   - No more `llama_cpp:server123` derivatives in the plugin registry.
   - Different provider types = different `#[AiProvider]` classes in the future.

3. **Models are first-class**
   - Every model that should appear in the AI module is represented by a `llama_cpp_model` config entity.
   - This enables Views, per-model configuration later, export, and better DX.

4. **Runtime resolution instead of plugin identity**
   - The plugin instance learns which server to talk to by looking at the chosen model (or explicit configuration).
   - This decouples "which provider plugin" from "which physical backend".

5. **Phased abstraction**
   - Keep leveraging `OpenAiBasedProviderClientBase` for the compatible path (pragmatic).
   - Gradually introduce shared traits/services so new specific providers can share code where it makes sense.

## 3. Entities

### `llama_cpp_server` (existing, evolved)
- Connection details (host, port, api_key via Key module, timeout).
- Explicit operation type restrictions (optional).
- Model filter (glob patterns).
- Still the unit of "a backend I manage".

### `llama_cpp_model` (new in 2.0)
- `server_id` (reference to the backend).
- `raw_model_id` (what we actually send to the API).
- `detected_operation_types`.
- `operation_types` (user overrides; empty = use detected).
- Label (can be enriched with server name).

Discovery flow populates and updates these entities. Stale models can be cleaned when a server no longer reports them.

## 4. Provider Plugins

- `LlamaCppProvider` (ID: `llama_cpp`) — OpenAI-compatible implementation.
- In the future we can add:
  - `HuggingFaceProvider` (native HF Inference tasks)
  - `LiteLLMProvider` (if it needs special treatment)
  - etc.

Each plugin is responsible for:
- Reporting the models it can serve (from its model entities).
- Executing operations (resolving the correct backend for the chosen model).

This is the main departure from the 1.x deriver approach.

## 5. Migration Paths

We aim for **simple, mostly automatic** migrations with minimal user intervention.

### 5.1 From ai_provider_llama_cpp (1.2.x → 2.x)

**Primary mechanism**: Update hooks + on-save discovery.

**Steps performed automatically** (in `ai_provider_llama_cpp_update_10203` and follow-ups):

1. Ensure the `llama_cpp_model` entity type is installed.
2. Rewrite entries in `ai.settings:default_providers`:
   - `llama_cpp:some-server` → `llama_cpp`
3. For every existing `llama_cpp_server`:
   - Read old State data (`ai_provider_llama_cpp.server.{id}.models`, `.model_types`, `.model_overrides`).
   - Create or update corresponding `llama_cpp_model` entities.
   - Store detected vs override types.
4. (Optional future hook) Trigger a background or on-demand discovery to refresh from the actual servers.

**User actions required** (documented clearly):

- Run `drush updb`.
- Go to the llama.cpp Servers admin page and **re-save** each server (this forces fresh discovery and model entity population).
- Visit the AI module configuration (or wherever providers are assigned).
- Re-select models for any operation types that used the old derived providers (the old model IDs no longer exist).
- Test the affected features.

**Model ID change note**:
Old model keys were local to each derived provider (e.g. `llama3`).
New keys are namespaced: `server-id__machine-name` (e.g. `ollama__llama3`).
The raw model name sent to the backend stays the same.

**Rollback**:
Keep the 1.2.x version installed in parallel during transition if needed (the old provider IDs will simply stop appearing after the update).

### 5.2 Simple Migration Path from *Any* Other AI Provider Module

The goal is to give site builders a low-friction way to move to the universal module without losing their configuration.

#### Category A: OpenAI-compatible providers (easiest)

Examples: `ai_provider_openai`, custom LiteLLM setups, `ai_provider_azure`, many community modules.

**Simple path**:

1. Enable `ai_provider_llama_cpp` (2.x) alongside the old provider.
2. Create a new `llama_cpp_server` entity pointing to the **same host + API key**.
3. Run discovery (save the server).
4. In the AI configuration screens, switch the provider from the old one to `llama.cpp (OpenAI-compatible)` and pick the same (or equivalent) models.
5. Once validated, disable the old provider module.

No data migration is strictly required because the backend is the same. The universal module just becomes the new management layer.

#### Category B: Providers with native / different APIs

Examples: `ai_provider_huggingface` (for non-chat tasks), `ai_provider_anthropic`, `ai_provider_bedrock`, Replicate, etc.

**Recommended simple path** (two-phase):

**Phase 1 – Side-by-side (recommended for safety)**
- Keep the original provider active for the operation types it handles well.
- Use the universal module only for the backends it supports better (or for new use cases).
- Gradually move models/operation types over time.

**Phase 2 – Adoption (when a specific plugin exists)**
- For providers that can be expressed as "a server + models", create server entities.
- Future specific plugins can read or import from the original module's configuration if an integration point is provided.
- Provide a small "Import from other provider" form or Drush command that:
  - Reads the other module's config.
  - Creates the equivalent `llama_cpp_server` (or equivalent for the new plugin).
  - Creates `llama_cpp_model` records with appropriate operation types.
  - Optionally rewrites `ai.settings:default_providers`.

**For module authors** (longer term):
We can publish an event or a service interface that other providers can implement to expose their models in a standardized way. The universal module can then "adopt" them.

#### Category C: Completely different paradigm

Some providers are not really "servers" (e.g. fully managed cloud with no stable endpoint concept). In those cases the path is:
- Implement a dedicated specific plugin under the universal module umbrella.
- Use the same model entity pattern where it makes sense, or extend it.
- Offer a one-time migration assistant.

## 6. Phased Implementation Roadmap (on 2.0 branch)

| Phase | Focus | Status (on 2.0) |
|-------|-------|-----------------|
| 0     | Remove derivers, introduce `llama_cpp_model` entities, fix provider resolution | Done (initial WIP commit) |
| 1     | Stabilize: better labels, discovery reliability, update hooks, docs | In progress |
| 2     | Extract common OpenAI-compatible logic into reusable pieces | Planned |
| 3     | Improve admin UX (model listing, test connection, bulk import) | Planned |
| 4     | Add a second concrete provider plugin (e.g. basic HF router or another compat) as proof | Planned |
| 5     | Evaluate module rename + package split if the vision is clearly more universal than "llama.cpp" | Decision later |

## 7. Compatibility & Breaking Changes

- **Breaking**: Derived plugin IDs disappear (`llama_cpp:*` no longer exist).
- **Breaking**: Model identifiers change format.
- **Non-breaking for most users**: The actual API calls and supported operations remain the same.
- Sites using the module for many specialized servers will feel the model selection change the most.

We will provide clear upgrade documentation and, if possible, a small Drush command to help list "old vs new" model mappings.

## 8. Open Questions

- Should we eventually rename the config entity types (`llama_cpp_server` → `ai_provider_backend`, etc.)? This has migration cost.
- How far do we want to abstract away from `OpenAiBasedProviderClientBase` in 2.x?
- Should model entities live in a shared namespace so multiple provider plugins can contribute to the same model registry?
- Do we want a central "AI Provider Backends" admin section instead of per-module sections?

## 9. Success Criteria for "Decent Enough to Rename"

- The OpenAI-compatible path is at least as good as 1.2.x (or better).
- Adding a new specific provider is clearly easier than maintaining a completely separate module.
- Migration stories for both the llama.cpp module and at least two other popular providers are documented and tested.
- No deriver usage in the core flow.
- Good test coverage for the new entity + plugin model.

## Appendix: How the AI Module Sees Providers (2.0)

- The `ai.provider` plugin manager sees `llama_cpp` (and future plugins) as normal plugins.
- Models returned by `getConfiguredModels()` use the `llama_cpp_model` entity IDs as keys.
- When the AI module calls `chat($input, $model_id, ...)`, the provider looks up the model entity, loads the referenced server, and talks to that backend.
- Multiple physical servers can happily coexist behind a single plugin ID.

---

This document lives on the `2.0` branch and will evolve together with the code.

**Next steps (suggested)**:
- Stabilize the current implementation on this branch.
- Flesh out the update hooks and migration tests.
- Improve model labels and the overrides UI.
- Document the upgrade path in `README.md`.

Contributions and feedback on this design are welcome.