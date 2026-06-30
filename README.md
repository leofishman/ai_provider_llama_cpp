# AI Provider: llama.cpp (Multi-instance)

A powerful multi-backend provider for the [AI module](https://www.drupal.org/project/ai).
While built with [llama.cpp](https://github.com/ggml-org/llama.cpp) as its primary focus, 
it natively supports **any OpenAI-compatible `/v1` server** (including Ollama, vLLM, LiteLLM, LM Studio).

This module uses **config entities for servers (`llama_cpp_server`) and models (`llama_cpp_model`)** + a single stable plugin (`llama_cpp`). This makes it easy to evolve toward additional specific provider plugins over time.

> **v2.0 released** (June 2026). There is no 1.3 — development moved directly from 1.2.x to 2.0.
> See [UPGRADE.md](UPGRADE.md) for migration details, [DESIGN.md](DESIGN.md) for architecture, and [TODO.md](TODO.md) for the remaining roadmap.

## Quick start

```bash
composer require drupal/ai_provider_llama_cpp
drush pm:enable ai_provider_llama_cpp
```

Then add a backend at **Configuration → AI → llama.cpp Servers**
(`/admin/config/ai/providers/llama-cpp`): set the **Host Name & Port** (e.g.
`http://127.0.0.1:8080`), save the server to discover its models, and select those
models per operation type under the `llama_cpp` provider in the AI module settings.

Upgrading from 1.x? Run `drush updb` — see the [Upgrading from 1.x](#upgrading-from-1x) section below and [UPGRADE.md](UPGRADE.md).

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

## What's new in 2.0

2.0 is a major structural release. Development went directly from the 1.2.x series to 2.0 (no 1.3).

### Major changes

- **Servers and models are now config entities** (`llama_cpp_server` + `llama_cpp_model`):
  fully exportable with `drush config:export`, version controllable, and multi-instance native.
- **Single stable plugin**: `llama_cpp` (no more per-server derivatives like `llama_cpp:default`).
  The selected `llama_cpp_model` entity tells the provider which backend server to use.
- **Full migration support**: `update_10201`–`update_10203` automatically migrate old State + derived plugin IDs.
- Credentials moved to the **Key** module.
- Per-server timeout, optional API key, and powerful glob-based model filtering.
- Native support for rerank, moderation (Llama Guard 3 + ShieldGemma), and text-to-image in addition to chat/embeddings/STT.

See the full [UPGRADE.md](UPGRADE.md) for the migration path and what changed in IDs.

### Translations

A complete Spanish translation (`es`) is included in `translations/ai_provider_llama_cpp.es.po`.

### Future work (post 2.0)

See [TODO.md](TODO.md) for the current backlog.

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

The upgrade is automatic:

1. `composer require 'drupal/ai_provider_llama_cpp:^2.0' -W` then `drush updb`.
2. Update hooks migrate old State data to `llama_cpp_model` entities and rewrite
   `ai.settings.default_providers` (`llama_cpp:servername` → `llama_cpp` and model IDs to the new `server__model` format).
3. If any models could not be auto-mapped, the update message tells you which operation types need manual re-selection.

See [UPGRADE.md](UPGRADE.md) for the complete step-by-step guide and paths from other providers.

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

## Verification

- Unit and Kernel tests (including full migration scenarios) pass.
- phpstan clean.
- Core functionality (multi-server, discovery, moderation, overrides, upgrade path) verified.
- For the 2.0 release the maintainer performed manual testing of the admin forms and end-to-end flows.

## Spanish translation

El módulo incluye una traducción completa al español (`es`) en `translations/ai_provider_llama_cpp.es.po`.

## Maintainers

- [leofishman](https://www.drupal.org/u/leofishman)
