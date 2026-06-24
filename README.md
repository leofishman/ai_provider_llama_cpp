# AI Provider: llama.cpp (Multi-instance)

A powerful multi-backend provider for the [AI module](https://www.drupal.org/project/ai).
While built with [llama.cpp](https://github.com/ggml-org/llama.cpp) as its primary focus, 
it natively supports **any OpenAI-compatible `/v1` server** (including Ollama, vLLM, LiteLLM, LM Studio).

This module uses **config entities for servers (`llama_cpp_server`) and models (`llama_cpp_model`)** + a single stable plugin (`llama_cpp`). This makes it easy to evolve toward additional specific provider plugins over time.

> **Important**: This work is happening on the `2.0` branch. See [DESIGN.md](DESIGN.md) for the full architectural design and [TODO.md](TODO.md) for the task list. There is no 1.3 release — development goes directly to 2.0.

## Quick start

```bash
composer require drupal/ai_provider_llama_cpp
drush pm:enable ai_provider_llama_cpp
```

Then add a backend at **Configuration → AI → llama.cpp Servers**
(`/admin/config/ai/providers/llama-cpp`): set the **Host Name & Port** (e.g.
`http://127.0.0.1:8080`), save the server to discover its models, and select those
models per operation type under the `llama_cpp` provider in the AI module settings.

Upgrading from 1.x? Run `drush updb` — see [Upgrading from 1.x](#upgrading-from-1x).

## Features

- **Multi-instance Architecture**: Configure multiple servers simultaneously (e.g. a GPU server for chat, a local instance for embeddings, and an Ollama instance for moderation). Servers are stored as `llama_cpp_server` config entities. Individual models are stored as `llama_cpp_model` config entities (migrated from State). All models are exposed through the single `llama_cpp` AI provider plugin (no more plugin derivatives). Model keys are unique across servers.
- **Supported Operations**:
  - **Chat** completions (`/v1/chat/completions`)
  - **Embeddings** (`/v1/embeddings`)
  - **Speech to Text** transcription via Whisper models (`/v1/audio/transcriptions`)
  - **Rerank** (`/v1/rerank`)
  - **Moderation** (native support for LlamaGuard3 and ShieldGemma).  
    ShieldGemma's chat template requires a safety "guideline", so the module builds the full prompt and calls `/v1/completions` directly. It checks the content against ShieldGemma's four official safety policies (harassment, hate speech, dangerous content, sexually explicit) and flags it if any is violated.
  - **Text to Image** generation (`/v1/images/generations` - e.g. via LiteLLM/OpenRouter)
- **Model Filtering**: Allow or restrict models per server using clean glob patterns (e.g. `llama3*, !*old*`).
- **Smart Auto-detection**: Reads server metadata to automatically determine model capabilities via CLI flags, HuggingFace API `pipeline_tag`, and intelligent name heuristics.
- **Manual Capability Overrides**: Assign any operation type to any model per-server to override auto-detection.
- **Robust Caching**: Model lists and detected capabilities are cached in Drupal State for resilience.
- **Flexible Connection**: Configurable timeout per-server and optional API key support (for authenticated instances like vLLM).

## Roadmap (2.0)

There is no 1.3 release: development goes **directly to 2.0**. See [DESIGN.md](DESIGN.md)
for the full architecture and [TODO.md](TODO.md) for the concrete task list.

### Done in the 2.0 refactor

- **Servers and models are config entities** (`llama_cpp_server`, `llama_cpp_model`):
  discovered models + overrides are now exportable, versionable config (migrated from State).
- **Single stable `llama_cpp` plugin, no derivers**: runtime resolves the backend server
  from the selected model entity, so model keys stay unique across servers.
- **Automatic migration** from 1.x via update hooks (`update_10201`–`update_10203`):
  remaps `default_providers` from `llama_cpp:servername` → `llama_cpp` and auto-remaps
  `model_id` to the new `servername__model` form so operations keep working without manual
  re-selection.
- Key module integration, per-server timeout/API key, and glob model filtering.

### Planned for 2.0

- **Harden the data model**: keep `getConfiguredModels()` strictly read-only (no config
  writes on a read path), discovery only on explicit action, robust ID sanitization.
- **Internationalization**: audit all interface strings for `t()` / `TranslatableMarkup`
  and ship a generated `.pot` template. (Spanish `.po` follows via localize.drupal.org once
  strings stabilize — not a 2.0 blocker.)
- **Expanded test coverage**: model discovery, filtering, moderation parsers, rerank, and
  text-to-image; more multi-server and migration edge cases.
- **Migration & upgrade docs**: clear in-place upgrade guide plus paths from other AI
  provider modules.

### Future (post-2.0)

- **Views integration** for discovered models and overrides.
- **Per-model guardrails**: attach pre-moderation models, sanitization rules, output
  validators, and hard token limits (`max_input_length`) directly to models.
- **Configurable & extensible moderation policies**: make ShieldGemma's safety guidelines
  configurable per server and dispatch an event so other modules can alter them.
- **Admin UX**: "Test connection" action and a model-count / capability summary in the
  server list.
- **Shared OpenAI-compatible base/trait** extracted for reuse by a future vendor-neutral
  universal provider module (Track B in DESIGN.md), and optional deep integration with
  AI 1.3+ Guardrails.

## Requirements

- Drupal 10.2, 11 or 12.
- [AI module](https://www.drupal.org/project/ai) ^1.2.
- [Key module](https://www.drupal.org/project/key) ^1.18 (for credentials management).
- A running OpenAI-compatible server. Setting one up is outside the scope of this module —
  see the docs for [Ollama](https://ollama.com), [vLLM](https://docs.vllm.ai),
  [llama.cpp](https://github.com/ggml-org/llama.cpp) or [LiteLLM](https://docs.litellm.ai).

## Configuration

Navigate to **Administration → Configuration → AI → llama.cpp Servers**
(`/admin/config/ai/providers/llama-cpp`). From here you can add and manage multiple server (backend) instances. Discovered models become `llama_cpp_model` config entities. You select servers/models under the `llama_cpp` provider in the AI configuration. 

For each server, you can configure:
- **Host Name & Port** — e.g. `http://127.0.0.1:8080`, or `http://host.docker.internal` for DDEV.
- **API Key** — Optional, required if your server enforces authentication.
- **Timeout** — Configurable per-server (defaults to 600s).
- **Operation Types & Model Overrides** — Assign specific roles to the server or manually override auto-detected capabilities per model.

## Upgrading from 1.x

2.0 replaces the per-server plugin derivatives (`llama_cpp:servername`) with a single
`llama_cpp` provider plus `llama_cpp_model` config entities, and moves model data out of
State. The upgrade is automatic:

1. `composer update drupal/ai_provider_llama_cpp` then `drush updb`.
2. The update hooks migrate State to model entities and remap your AI settings:
   `llama_cpp:servername` → `llama_cpp`, with each `model_id` rewritten to the new
   `servername__model` form. Most sites keep working with no manual change.
3. Only models the hooks could not auto-map are reported in the update message — re-save
   the relevant server (to re-run discovery) and re-select the model in the AI settings.

See [DESIGN.md §6](DESIGN.md) for the full migration design, including paths from other AI
provider modules.

## Compatible servers

This module works with **any server** that exposes an OpenAI-compatible `/v1` API (Ollama, vLLM, llama.cpp, LiteLLM, LM Studio, etc.).

You do **not** need to run llama.cpp. The majority of users actually run it with Ollama or vLLM.

### Multiple specialized instances

One of the strongest features of this module is running **several servers at once**, each specialized for different tasks:

- Fast chat server (Ollama or llama.cpp)
- Dedicated embeddings server (vLLM or Ollama)
- Lightweight moderation server (Llama Guard 3 or ShieldGemma)

Models are selected under the single `llama_cpp` provider. Each model internally knows which server backend it came from.

### Operation types and model overrides

The most important configuration options (after host and port) are:

- **Operation Types**: Choose which tasks this server should handle (Chat, Embeddings, Rerank, Moderation, etc.).  
  Manual selection is usually more reliable than auto-detection when using Ollama, vLLM or custom models.

- **Model Overrides**: For fine-tuned or unusual models, you can explicitly assign operation types per model in the server edit form.

These two options are what allow the module to work well across very different backends.

## Maintainers

- [leofishman](https://www.drupal.org/u/leofishman)
