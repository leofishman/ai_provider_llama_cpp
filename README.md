# AI Provider: llama.cpp

Provides a [llama.cpp](https://github.com/ggml-org/llama.cpp) provider for the
[AI module](https://www.drupal.org/project/ai) using the OpenAI-compatible
`/v1` HTTP API exposed by `llama-server`.

## Features

- **Chat** completions (`/v1/chat/completions`)
- **Embeddings** (`/v1/embeddings`)
- **Speech to Text** transcription via Whisper models (`/v1/audio/transcriptions`)
- **Rerank** (`/v1/rerank`)
- **Automatic operation type detection** — the provider reads each model's
  server metadata to determine its capabilities without manual configuration:
  1. `--embeddings` / `--reranking` flags in the server's model args
  2. HuggingFace API `pipeline_tag` (when the model was loaded via `--hf-repo`)
  3. Model name heuristics (e.g. `whisper`, `embed`, `rerank`)
- **Manual capability overrides** — the settings form lets you assign any
  operation type to any model, overriding auto-detection
- Model list and detected types cached in Drupal State for resilience when
  the server is offline
- No API key required — designed for local and self-hosted deployments

## Requirements

- Drupal 10.2 or 11
- [AI module](https://www.drupal.org/project/ai) ^1.2
- A running `llama-server` instance (default port: 8080)

## Installation

```bash
composer require drupal/ai_provider_llama_cpp
drush pm:enable ai_provider_llama_cpp
```

## Configuration

Navigate to **Administration → Configuration → AI → llama.cpp Configuration**
(`/admin/config/ai/providers/llama-cpp`) and enter:

- **Host Name** — protocol + hostname, e.g. `http://127.0.0.1` or
  `http://host.docker.internal` for DDEV/Docker environments.
- **Port** — defaults to `8080`.

## Running llama-server

### Single model

```bash
llama-server --model /path/to/model.gguf --port 8080
```

### Multi-model router (recommended)

`llama-server` can manage multiple models and load/unload them on demand.
Create a preset file (e.g. `~/.config/llama-models.ini`):

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
  --models-max 1
```

The module detects each model's capabilities automatically from the
`--embeddings` and `--reranking` flags. For models loaded via `--hf-repo`,
it also queries the HuggingFace API to determine the model type.

## Maintainers

- [leofishman](https://www.drupal.org/u/leofishman)
