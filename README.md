# AI Provider: llama.cpp

Provides a [llama.cpp](https://github.com/ggml-org/llama.cpp) provider for the
[AI module](https://www.drupal.org/project/ai) using the OpenAI-compatible
`/v1` HTTP API exposed by `llama-server`.

## Features

- Chat completions (`/v1/chat/completions`)
- Embeddings (`/v1/embeddings`)
- Auto-discovery of available models via `/v1/models`
- Model list cached in Drupal State for resilience when the server is offline
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

```bash
llama-server --model /path/to/model.gguf --port 8080
```

For embedding models add `--embeddings`:

```bash
llama-server --model /path/to/embedding-model.gguf --port 8080 --embeddings
```

## Maintainers

- [leofishman](https://www.drupal.org/u/leofishman)
