# Proposal: first-class multi-instance provider support in the AI module

> Draft to be turned into an issue in the `drupal/ai` queue. Written from the
> experience of building `ai_provider_llama_cpp` 2.0, which needs to expose
> several independent llama.cpp / OpenAI-compatible servers through one provider.

## Summary

Today the AI module models configuration as **provider → model**. Each provider
is a singleton plugin, and `ai.settings.default_providers[<operation>]` stores a
`{provider_id, model_id}` pair.

That works when a "provider" is a single endpoint (one API base URL, one key,
one model catalog). It breaks down for the very common case where a single
provider *technology* is deployed as **several independent server instances**,
each with its own host/port, its own API key, and its own model catalog.

We propose adding an optional **instance** dimension to the model:
**provider → instance → model**, fully backward compatible (providers that do
not support instances report a single implicit "default" instance and behave
exactly as today).

## The real-world case

A typical self-hosted setup exposes multiple OpenAI-compatible servers:

| Instance          | Host                  | Purpose                    |
|-------------------|-----------------------|----------------------------|
| `gpu-chat`        | `http://box:8080`     | llama.cpp, chat            |
| `ollama`          | `http://box:11434`    | Ollama, chat + embeddings  |
| `vllm`            | `http://box:8000`     | vLLM, chat                 |
| `vllm-moderation` | `http://box:8001`     | vLLM, ShieldGemma moderation |

All four speak the same protocol, so they are naturally *one provider*
(`llama_cpp` / "OpenAI-compatible"), but they are four distinct endpoints with
four distinct model lists. The same shape appears with LiteLLM/OpenAI gateways,
multiple OpenAI orgs/projects, Azure deployments across regions, or an Ollama
fleet.

## Why the two existing workarounds are not enough

1. **Plugin derivers (one derived provider per instance).**
   `ai_provider_llama_cpp` 1.x did this (`llama_cpp:<server>`). It pollutes the
   provider dropdown with N entries, makes `default_providers` provider IDs
   unstable (renaming/removing an instance breaks stored config), and forces
   every consumer to understand derivative IDs. We removed it in 2.0.

2. **Collapse to one provider, encode the instance in the model id.**
   2.0 does this: one `llama_cpp` provider, models keyed `server__model`. It
   keeps the provider list clean, but the AI settings UI has **no notion of the
   instance**, so the model `<select>` shows model names with no indication of
   which server they belong to. When two servers expose a model with the same
   raw id (e.g. `llama3.2:1b` on both Ollama boxes) the user cannot tell them
   apart. Our current mitigation is to return the model options grouped by
   server as `<optgroup>`s from `getConfiguredModels()` — a per-provider hack
   that every multi-instance provider would otherwise have to reinvent.

Neither is a real solution: the AI module simply has no first-class concept of
"instance", so each provider improvises.

## Proposed design

Add an optional instance layer that is invisible to single-instance providers.

### 1. Provider interface

```php
interface AiProviderInterface {
  // Existing methods unchanged.

  /**
   * Instances (endpoints) this provider exposes.
   *
   * @return array<string,string>
   *   Map of instance id => human label. An empty array (the default) means
   *   the provider is single-instance and the AI module behaves as today.
   */
  public function getInstances(): array;

  // getConfiguredModels() gains an optional instance id:
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = [], ?string $instance_id = NULL): array;
}
```

A default trait implementation returns `[]` from `getInstances()` and ignores
`$instance_id`, so **every existing provider keeps working with zero changes**.

### 2. Storage / `default_providers`

```php
$default_providers['chat'] = [
  'provider_id' => 'llama_cpp',
  'instance_id' => 'ollama',   // optional; absent/NULL for single-instance providers
  'model_id'    => 'llama3.2:1b',
];
```

`instance_id` is nullable. When absent, resolution is exactly as today.

### 3. AI settings form

- If `getInstances()` is empty → render the current Provider + Model columns
  unchanged.
- If it returns instances → render an **Instance** select between Provider and
  Model (AJAX-refreshing the Model options for the chosen instance), or, as a
  lighter first step, render the Model select with one `<optgroup>` per
  instance. Either way the instance becomes a first-class, visible dimension
  instead of something each provider smuggles into model labels.

### 4. Runtime resolution

`ProviderProxy` / the operation dispatch passes `instance_id` (when present) to
the provider so it can select the right endpoint. Single-instance providers
ignore it.

## Backward compatibility (the key point)

- Existing providers: `getInstances()` returns `[]` → no UI change, no config
  change, no migration. The instance layer is purely additive.
- Providers that want it opt in by implementing `getInstances()` and honoring
  `instance_id`. Adoption can be gradual, provider by provider.
- Stored `default_providers` without `instance_id` keep resolving to the single
  implicit instance.

## Reference implementation

`ai_provider_llama_cpp` 2.0 already has all the moving parts behind its own
config entities and can serve as a reference / first adopter:

- `llama_cpp_server` config entities model instances (host, port, Key ref,
  timeout, per-instance model filter).
- `llama_cpp_model` config entities model the per-instance catalog
  (`<server>__<model>`), so model ids are globally unique and stable.
- `getConfiguredModels()` already groups by server (the `<optgroup>` mitigation)
  and API calls resolve the raw model id from the model entity, independently of
  the display label — exactly the decoupling a core instance layer would
  formalize.

If the interface above lands, this provider would drop its optgroup workaround
and implement `getInstances()` / `instance_id` directly.

## Shipping this as patches (two tiers)

Rather than a prose-only proposal, we propose landing it as reviewable MRs, in
two tiers so the easy win is not blocked on the larger design discussion.

### Tier 1 — make `AiSettingsForm` optgroup-aware (tiny, low risk)

Today `AiSettingsForm` assumes `getConfiguredModels()` returns a **flat**
`[model_id => label]` array. A provider can already return **nested**
(`[group => [model_id => label]]`) options and Drupal's `select` renders them as
`<optgroup>`s — except one membership check clears a valid current model because
it does `isset($models[$current_model])` against the nested array.

The fix is three lines: flatten before the check. See
[`patches/ai-support-optgrouped-model-options.patch`](../patches/ai-support-optgrouped-model-options.patch):

```php
$flat_models = [];
array_walk_recursive($models, function ($label, $key) use (&$flat_models) {
  $flat_models[$key] = $label;
});
if ($current_model && !isset($flat_models[$current_model])) { … }
```

With that, any provider can group its models (by instance, by family, by size…)
with zero interface changes. `ai_provider_llama_cpp` already returns grouped
options and works with this patch. Apply it via `composer` patches:

```json
"extra": {
  "patches": {
    "drupal/ai": {
      "Support optgrouped model options in AI settings":
        "web/modules/custom/ai_provider_llama_cpp/patches/ai-support-optgrouped-model-options.patch"
    }
  }
}
```

### Tier 2 — the full instance layer

The `getInstances()` / `instance_id` design above, as a follow-up MR once the
approach is agreed. Tier 1 is independent and useful on its own.

## Open questions

- Naming: "instance" vs "endpoint" vs "connection" vs "server".
- Should instances be discoverable/config entities defined by the provider, or a
  generic core entity the AI module owns?
- How does the API Explorer surface instances?
- Interaction with the Key module for per-instance credentials (already solved
  in this provider; worth standardizing).
