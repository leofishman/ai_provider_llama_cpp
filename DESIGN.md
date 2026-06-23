# Design Document: ai_provider_llama_cpp 2.0 — Universal Provider Evolution

**Status**: Active Draft  
**Branch**: `2.0`  
**Last Updated**: 2026-06-23  
**Maintainer**: leofishman (with community input)  
**Target**: Evolution of the existing `ai_provider_llama_cpp` module (rename decision deferred)

---

## Executive Summary

This document defines the architectural direction for the 2.0 line of `ai_provider_llama_cpp`.

**Core idea**: Transform the module from a "llama.cpp + derivers" specialized provider into a **strong, multi-backend foundation** that:

- Excels at OpenAI-compatible servers (current killer feature).
- Uses **config entities** for servers and models (instead of State + heavy derivers).
- Allows adding **specific provider plugins** over time without architectural pain.
- Provides clear, low-friction migration paths from the current module **and from other AI provider modules**.
- Is properly internationalized, with Spanish translation as a first-class deliverable.

We treat `2.0` as an **in-place evolution** of the existing module for now. Renaming (e.g. to `ai_universal_provider` or `ai_provider_compat`) will be evaluated only after the foundation is solid.

---

## 1. Motivation & Problems with 1.x

### Current Strengths (keep and improve)
- Excellent multi-server support (different servers for different tasks).
- Sophisticated model capability detection.
- First-class support for advanced operations (rerank, moderation with parsers, speech-to-text, text-to-image).
- Works with a huge ecosystem (llama.cpp, Ollama, vLLM, LiteLLM, LM Studio, etc.).

### Problems to Solve
- **Derivers as the multiplicity mechanism** → Creates `llama_cpp:server-id` plugins. This is powerful but feels hacky and limits future growth.
- **Models and overrides live in State** → Not exportable, not versionable, no Views, hard to attach metadata later.
- **Single plugin identity** → Makes it difficult to cleanly support truly different provider families.
- **Tight coupling** to `OpenAiBasedProviderClientBase`.
- Hard for other developers to contribute "specific" backends.

**Vision**:
> One excellent OpenAI-compatible experience today + a clean path to become the home for multiple specific providers tomorrow.

---

## 2. Key Architectural Principles for 2.0

1. **Config Entities are King**
   - Servers (`llama_cpp_server`) remain the unit of backend configuration.
   - Models become first-class config entities (`llama_cpp_model`).

2. **Specific Plugins, Not Derivers**
   - Stable plugin ID per *provider family* (e.g. `llama_cpp` for OpenAI-compatible).
   - No more `llama_cpp:xxx` derivatives for normal usage.
   - Future plugins (e.g. `huggingface_native`) are separate `#[AiProvider]` classes.

3. **Runtime Backend Resolution**
   - The chosen model (entity) tells the provider which server to use.
   - Plugin instance + model ID → correct backend at execution time.

4. **Pragmatic Abstraction**
   - Leverage `OpenAiBasedProviderClientBase` heavily for the compatible path.
   - Introduce traits/services gradually so new providers can share code.

5. **Simple, Automatic Migrations**
   - Update hooks should do the heavy lifting.
   - Side-by-side operation during transition should be easy.

6. **Internationalization from Day One**
   - All user-facing strings (labels, descriptions, error messages, help texts, drush/update messages) **must** be marked for translation using Drupal's `t()` / `TranslatableMarkup`.
   - Plugin labels, entity type labels, and form elements must be properly translatable.
   - At minimum, provide a full Spanish (es) translation alongside English.
   - Documentation (README, upgrade guides, DESIGN.md) should support Spanish (either directly or via community contributions).
   - When adding specific provider plugins, their strings must follow the same i18n discipline.

---

## 3. Entities (Current State on 2.0 + Future)

### `llama_cpp_server`
- Unchanged core purpose.
- Stores host, port, Key reference, timeout, explicit operation types, model filter.
- Acts as the "backend I control".

### `llama_cpp_model` (new)
Fields:
- `id` (stable, e.g. `myserver__llama3_8b`)
- `label` (human readable, e.g. "GPU Server / llama3-8b") — translatable via config translation
- `server_id`
- `raw_model_id`
- `detected_operation_types[]`
- `operation_types[]` (overrides — empty means use detected)

These entities replace the old State keys:
- `ai_provider_llama_cpp.server.*.models`
- `.model_types`
- `.model_overrides`

Benefits: exportable, Views-ready, attachable (future guardrails per model, token limits, etc.).

---

## 4. Provider Plugin Model

Current (on 2.0 branch):
- Single `#[AiProvider(id: 'llama_cpp')]` class.
- `getConfiguredModels()` returns models from all (or filtered) servers using entity IDs as keys.
- Operation methods resolve the correct server from the model entity at call time.

Future:
- `OpenAiCompatibleProvider` (rename/refactor of current class if desired).
- New dedicated plugins for non-compatible providers.

---

## 5. The Critical Dilemma: Drupal AI Core Version Support (1.2 vs 1.4)

This is the most important strategic decision right now.

### Current Reality (June 2026)
- The original module declares `drupal/ai: ^1.2`.
- Drupal AI 1.3 introduced **Guardrails** (major new concept).
- Drupal AI 1.4.0 (very recent) is a "massive" release focused on:
  - Extensibility (new plugin types: Automators, Agents skills, API Explorer, etc.)
  - Advanced Guardrails
  - Views Bulk Operations integration for AI
  - Better enterprise/resilience features
- Many production sites are still on 1.2.x or early 1.3.x.
- Provider modules have historically targeted the lowest common denominator.

### Options Analysis

| Option                    | Pros                                                                 | Cons                                                                 | Recommendation |
|---------------------------|----------------------------------------------------------------------|----------------------------------------------------------------------|----------------|
| **Target ^1.2 only**     | Maximum compatibility. Largest possible user base. Simplest.        | Cannot use new 1.3/1.4 features (Guardrails, new extensibility points). Looks "old". | Short-term safe choice |
| **Target ^1.4 only**     | Can integrate deeply with Guardrails, Automators, new plugin system. Modern. | Loses users on 1.2/1.3. Breaks many existing sites. Risk of low adoption. | Too aggressive for 2.0 |
| **^1.2 + graceful 1.4+** | Best of both. Support old users. Use new features when available via `module_exists` / version checks. | Slightly more complex code. Need to decide what is "core" vs "1.4 bonus". | **Strongly recommended** |
| **Drop 1.2 later (3.x)** | Clean break in a future major.                                      | Requires users to upgrade core AI module.                            | Future plan |

### Recommended Strategy for 2.0

**Primary target: `^1.2` (with notes for 1.3/1.4)**

- Declare `"drupal/ai": "^1.2"` in `composer.json`.
- Ensure all core functionality (multi-server, model entities, OpenAI-compatible operations) works on 1.2.
- Add **optional integration points** for newer AI features:
  - If AI 1.3+ Guardrails are present → expose our moderation models to the guardrail system.
  - If new extensibility APIs exist in 1.4 → implement the new plugin types where it makes sense.
- Document clearly: "Best experience on AI 1.4+. Fully functional on 1.2+."
- Consider a later 2.1 or 3.0 bump of the minimum version once adoption of 1.4 is widespread.

**Rationale**:
- The unique value of this module (reliable multi-instance OpenAI-compatible + advanced ops) is valuable to people still on 1.2.
- Guardrails and Automators are exciting, but they are **additive**. We can integrate without making them a hard requirement.
- Migration friction is already high because of the deriver removal. Adding a core AI version bump on top would be painful.

**Implications for this design**:
- We should not rely on 1.4-only APIs in the base implementation.
- We should design extension points that 1.4 features can plug into later.

---

## 6. Migration Paths (Simple by Design)

We prioritize **automatic as much as possible** + clear user steps.

### 6.1 From ai_provider_llama_cpp (1.2.x → 2.x on same module)

**Automated (update hooks)**:
1. Install `llama_cpp_model` entity type.
2. Rewrite `ai.settings.default_providers`:
   - Change any `llama_cpp:servername` → `llama_cpp`
3. Migrate old State data into `llama_cpp_model` entities (per server).
4. Preserve detected vs override operation types.

**User steps** (must be documented in README + release notes):
1. `composer update` + `drush updb`.
2. Go to **Administration → AI → llama.cpp Servers** and **re-save every server** (forces fresh discovery).
3. Go to AI configuration and re-assign providers/models where the old derived IDs were used.
4. Test.

All user-facing messages from update hooks and forms must be properly marked for translation (see section 7).

**Model ID mapping**:
- Old: `llama3`
- New: `default__llama3` (or `serverid__machine_name`)
- The actual model sent to the API (`raw_model_id`) does **not** change.

**Rollback strategy**:
Keep the previous version of the module available. Old derived provider IDs will simply disappear after the update.

### 6.2 From Other AI Provider Modules (General Simple Path)

#### A. OpenAI-compatible providers (`ai_provider_openai`, LiteLLM-based, etc.)

**Easiest path — almost zero data migration**:
1. Install the 2.x version of this module.
2. Create a `llama_cpp_server` pointing to the exact same endpoint + credentials as the old provider.
3. Save the server → models are discovered.
4. In AI settings, switch the relevant operation types to use `llama.cpp (OpenAI-compatible)` + select models.
5. Disable the old provider module.

#### B. Native / non-compatible providers (`ai_provider_huggingface`, Anthropic, etc.)

**Recommended two-phase approach**:

**Phase 1 (safest)**: Run side-by-side.
- Keep the original provider for the tasks it handles best.
- Use this module for OpenAI-compatible workloads or new use cases.

**Phase 2 (when ready)**:
- Create server entities for any compatible parts.
- For truly different providers: wait for (or contribute) a dedicated plugin in this module.
- Long-term: offer an "Import" Drush command or form that reads the other module's configuration and creates equivalent server + model entities.

#### C. Completely different paradigms

Implement as a new specific plugin inside this module family and provide a one-time migration helper.

---

## 7. Internationalization and Translations (i18n / l10n)

### Why This Matters
As we evolve toward a more universal provider (and potentially a broader audience), the module must be usable by non-English speakers from the beginning. Spanish is a hard requirement because of the large Drupal community in Spanish-speaking countries (Spain, Latin America).

### Requirements

- **All strings in code**:
  - Use `$this->t('...')` in forms/classes or `new TranslatableMarkup(...)` in attributes and definitions.
  - Never hardcode English strings that the user will see.
  - Examples that must be translatable:
    - Entity labels and descriptions (`llama_cpp_server`, `llama_cpp_model`)
    - Plugin label: `'llama.cpp (OpenAI-compatible)'`
    - Form field labels, descriptions, error messages
    - Update hook messages (in `.install`)
    - Drush command output
    - Moderation parser messages, help texts

- **Config entities**:
  - Labels are translatable via Drupal's Config Translation module (if users enable it).
  - We should not assume English-only labels for servers or models.

- **Spanish translation (minimum)**:
  - Provide a complete `es` translation.
  - Target files: `translations/es.po` or contribute directly via https://localize.drupal.org
  - At least the following must be translated:
    - All UI strings
    - Module info (name, description)
    - Help texts and long descriptions in forms
    - Key error/setup messages

- **Documentation**:
  - README.md should have a note that Spanish translation is maintained.
  - Consider providing `README.es.md` or at least a Spanish section in the main README.
  - This DESIGN.md should eventually have a Spanish version (or a translated summary).

- **When adding new providers**:
  - Any new `#[AiProvider]` plugin, custom operations, or moderation models must follow the same translation rules.
  - Do not introduce English-only strings in new code.

### Implementation Notes
- In `LlamaCppProvider.php`, `LlamaCppServerForm.php`, entity definitions, and install hooks, audit all strings.
- Use `StringTranslationTrait` where appropriate.
- For complex messages (with placeholders), use proper `@placeholder` / `%placeholder` syntax.
- Future specific plugins (e.g. native Hugging Face) should be developed with translation in mind from the first commit.

### Testing
- Enable Spanish language in a test site.
- Verify that the module appears fully in Spanish (plugin name, server form, model overrides, messages).

---

## 8. Detailed Architecture & Current Implementation Notes

(See the code on the `2.0` branch for the current state of the refactor.)

Key files changed in the initial refactor:
- New entities: `LlamaCppModel`, `LlamaCppModelInterface`
- `LlamaCppProvider` no longer uses a deriver
- Server form now works with model entities instead of State
- Update hook + uninstall logic updated
- Runtime active server resolution via model lookup

---

## 9. Phased Roadmap

| Phase | Goal                                      | Dependencies          | Target AI Core |
|-------|-------------------------------------------|-----------------------|----------------|
| 0     | Core refactor (no derivers + model entities) | —                     | 1.2+           |
| 1     | Stabilization, migration hooks, docs, UX polish + full Spanish translation | Phase 0               | 1.2+           |
| 2     | Extract reusable OpenAI-compatible base / traits | Phase 1               | 1.2+           |
| 3     | Optional deep integration with AI 1.3 Guardrails | AI >=1.3              | 1.3+ (optional)|
| 4     | Add second concrete provider plugin as validation | Phase 2               | 1.2+           |
| 5     | Evaluate rename + broader "universal" positioning | Phase 4 + community   | TBD            |

---

## 10. Risks & Mitigations

- **User confusion from changed provider/model IDs** → Excellent release notes + Drush helper to show old → new mapping.
- **Adoption if we target only 1.4** → We are explicitly choosing broad 1.2 compatibility.
- **Maintenance burden of supporting multiple AI core versions** → Keep new 1.4 features clearly optional and behind `if (\Drupal::moduleHandler()->moduleExists('ai') && version_compare(...))`.
- **Entity ID strategy** → Using `server__machine` is pragmatic. We can evolve naming later with an update hook if needed.

---

## 11. Open Questions & Decisions

**Decided**:
- Primary minimum = AI ^1.2 for 2.0 line.
- Keep using `llama_cpp_*` entity and plugin names for now (rename later).
- Models use composite IDs (`server__machine`).

**Still open**:
- Should we expose a generic "AI Provider Backend" entity type that multiple provider plugins can share?
- How should per-model guardrails (when we add them) interact with the core AI 1.3+ Guardrails system?
- When (if ever) do we make 1.4 the minimum?

---

## 12. Success Criteria

Before considering a rename or declaring 2.0 stable:
- All existing 1.2.x functionality works at least as well.
- Migration from the llama.cpp module is documented and tested via update hooks.
- At least one non-trivial migration story from another provider module is documented.
- No use of derivers for core multiplicity.
- Clear compatibility statement regarding AI 1.2 vs 1.4.
- At least a complete and reviewed Spanish translation is available for all UI strings and documentation.

---

## Appendix A: Glossary

- **Deriver**: Drupal plugin mechanism that dynamically creates multiple plugin definitions from one base class.
- **OpenAI-compatible**: Any server exposing `/v1/chat/completions`, `/v1/embeddings`, etc.
- **Specific provider**: A plugin written for a particular vendor's native API rather than the OpenAI shim.

---

## Appendix B: How Providers Appear to the AI Module (2.0)

1. `ai.provider` plugin manager only sees stable IDs (`llama_cpp`, future others).
2. `getConfiguredModels()` returns an array where keys are `llama_cpp_model` entity IDs.
3. When executing an operation, the provider resolves the server from the model entity ID.
4. Multiple physical backends are hidden behind one plugin + many models.

---

**This document is the source of truth for the 2.0 effort.**

Next actions after design agreement:
- Improve and test the update/migration code on the `2.0` branch.
- Expand test coverage for model entities.
- Write clear upgrade documentation.
- Decide on any 1.4-specific enhancements.
- Audit all strings and deliver a complete Spanish (es) translation.

Contributions, questions, and alternative viewpoints are very welcome.