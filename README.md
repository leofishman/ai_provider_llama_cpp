# AI Provider: llama.cpp (Multi-instance)

A powerful, multi-instance provider for the [AI module](https://www.drupal.org/project/ai).
While built with [llama.cpp](https://github.com/ggml-org/llama.cpp) as its primary focus, 
it natively supports **any OpenAI-compatible `/v1` server** (including Ollama, vLLM, LiteLLM, LM Studio).

## Features

- **Multi-instance Architecture**: Configure multiple servers simultaneously (e.g. a GPU server for chat, a local instance for embeddings, and an Ollama instance for moderation). Each server appears as an independent provider in Drupal.
- **Supported Operations**:
  - **Chat** completions (`/v1/chat/completions`)
  - **Embeddings** (`/v1/embeddings`)
  - **Speech to Text** transcription via Whisper models (`/v1/audio/transcriptions`)
  - **Rerank** (`/v1/rerank`)
  - **Moderation** (native support for LlamaGuard3 and ShieldGemma)
  - **Text to Image** generation (`/v1/images/generations` - e.g. via LiteLLM/OpenRouter)
- **Model Filtering**: Allow or restrict models per server using clean glob patterns (e.g. `llama3*, !*old*`).
- **Smart Auto-detection**: Reads server metadata to automatically determine model capabilities via CLI flags, HuggingFace API `pipeline_tag`, and intelligent name heuristics.
- **Manual Capability Overrides**: Assign any operation type to any model per-server to override auto-detection.
- **Robust Caching**: Model lists and detected capabilities are cached in Drupal State for resilience.
- **Flexible Connection**: Configurable timeout per-server and optional API key support (for authenticated instances like vLLM).

## Roadmap / Future (1.3.x)

### Planned for the next major release

- **Migrate model data from State to Config Entities**:
  1. **Views Integration**: Expose discovered models and overrides as editable/filterable Views.
  2. **Model Guardrails**: Natively attach pre-moderation models (LlamaGuard/ShieldGemma), regex sanitization rules, and output validators directly to specific models.
  3. **Advanced Token Control**: Enforce hard token limits (`max_input_length`) per-model.

- **Improved test coverage**:
  - Expand Kernel tests for model discovery, filtering, moderation parsers, rerank, and text-to-image paths.
  - Add more scenarios for multi-server setups and edge cases in derivatives.

- **Admin UI enhancements**:
  - Add a "Test connection" / status action directly from the server listing (beyond form validation).
  - Show last discovered model count and basic capability summary in the server list.

- **Architecture & DX improvements**:
  - Extract model cache management and discovery logic into a dedicated service for better testability and reuse.
  - Evaluate additional per-server configuration options (e.g. default model per operation type).

Stabilization work for reliable multi-server support, Key module integration, model filtering, and robust derivative handling was completed during the 1.2.x cycle.

## Requirements

- Drupal 10.2, 11 or 12.
- [AI module](https://www.drupal.org/project/ai) ^1.2
- A running `llama-server`, Ollama, vLLM, or other OpenAI-compatible API.


## Installation

```bash
composer require drupal/ai_provider_llama_cpp
drush pm:enable ai_provider_llama_cpp
```

## Configuration

Navigate to **Administration → Configuration → AI → llama.cpp Servers**
(`/admin/config/ai/providers/llama-cpp`). From here you can add and manage multiple server instances. 

For each server, you can configure:
- **Host Name & Port** — e.g. `http://127.0.0.1:8080`, or `http://host.docker.internal` for DDEV.
- **API Key** — Optional, required if your server enforces authentication.
- **Timeout** — Configurable per-server (defaults to 600s).
- **Operation Types & Model Overrides** — Assign specific roles to the server or manually override auto-detected capabilities per model.

## Running llama-server

### Single model

```bash
llama-server --model /path/to/model.gguf --port 8080
```

### Multi-model router (recommended)

`llama-server` can manage multiple models and load/unload them on demand.
Create a preset file and configure each model according to your hardware e.g with all layers loaded in gpu. `~/.config/llama-models.ini`):

```ini
[my-chat-model]
hf-repo = bartowski/SmolLM2-360M-Instruct-GGUF:Q4_K_M
n-gpu-layers = 99

[my-embedding-model]
hf-repo = nomic-ai/nomic-embed-text-v1.5-GGUF:Q4_K_M
n-gpu-layers = 99
embeddings = on

[my-reranker]
hf-repo = gpustack/bge-reranker-v2-m3-GGUF:Q4_K_M
n-gpu-layers = 99
reranking = on

[my-whisper-model]
hf-repo = FL33TW00D-HF/whisper-tiny
hf-file = tiny_q4k.gguf
n-gpu-layers = 99
```

Then start the router:

```bash
llama-server \
  --host 0.0.0.0 \
  --port 8080 \
  --models-dir ~/.cache/huggingface/hub/ \
  --models-preset ~/.config/llama-models.ini \
  --models-max 3
```

The module detects each model's capabilities automatically from the
`--embeddings` and `--reranking` flags. For models loaded via `--hf-repo`,
it also queries the HuggingFace API to determine the model type.

For custom fine tuned models that have no flags or pipeline tags, you can use the model overrides in the config form to assign any operation type to any model.

## Maintainers

- [leofishman](https://www.drupal.org/u/leofishman)
