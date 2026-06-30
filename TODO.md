# TODO — ai_provider_llama_cpp 2.0

> **v2.0 released** (2026-06-30). Core refactor complete. Unit + Kernel tests green + phpstan clean.  
> Functional UI tests deferred (manual verification performed by maintainer).  
> Spanish translation shipped as `translations/ai_provider_llama_cpp.es.po` (in addition to .pot).

Task list for the **2.0** release. There is no 1.3: development goes directly to 2.0.
This file is the actionable companion to [DESIGN.md](DESIGN.md) (the source of truth for
the architecture). Phases mirror DESIGN.md §9.

Legend: `[ ]` todo · `[~]` in progress · `[x]` done

---

## Phase 0 — Core refactor (no derivers + config entities)

- [x] `llama_cpp_server` config entity (host, port, Key ref, timeout, operation types, filter).
- [x] `llama_cpp_model` config entity (server_id, raw_model_id, detected + override op types).
- [x] Single `#[AiProvider(id: 'llama_cpp')]` plugin — derivers removed.
- [x] Runtime backend resolution: server resolved from the selected model entity.
- [x] Server form drives discovery and writes model entities on save.
- [x] Migrate State → entities + remap `default_providers` (`update_10201`–`update_10203`).

## Phase 0.5 — Harden the data model

- [x] Assert `getConfiguredModels()` is strictly **read-only** (no config writes on read paths)
      — back it with a Kernel test that fails if discovery runs on read.
- [x] Ensure `discoverModels()` is the **only** write path (server save / explicit command).
- [x] Robust entity ID sanitization in `buildModelEntityId()`: `[a-z0-9_]`, length cap,
      deterministic hash suffix; test collisions and over-long raw model ids.
- [x] Complete **config schema** for both entities in `config/schema/` (every key typed and
      labelled) so `drush config:export` / config validation stays clean — see
      `drupal-configuration`. Discovered fields are config by decision but written only on
      action; verify no config diff appears on a cold read.
- [x] Confirm credentials stay in the **Key module** (never raw in config/State) per the
      config/state/settings split in `drupal-configuration`.
- [x] Complete `update_10203` `model_id` remap coverage; report unmapped models in the
      update message for manual re-selection.
- [x] Decide whether `discoverModels()` should be exposed as a Drush command for re-discovery
      on new environments / CI (re-reconcile catalog without saving the form).

## Phase 1 — Stabilization, migration, docs, UX

- [X] Expand test coverage (prefer **Kernel/Functional** over Unit — see
      `drupal-automated-testing`; use required PHPUnit attributes `#[Group]` / `#[CoversClass]`):
  - [x] Model discovery + filtering edge cases (glob allow/deny, `!*old*`).
  - [x] Moderation parsers (LlamaGuard3 + ShieldGemma multi-policy).
  - [X] Capability detection (args + name heuristics) covered by `ModelCatalogTest`;
        end-to-end rerank / text-to-image operation request paths still pending.
  - [X] Multi-server setups (chat / embeddings / moderation on different backends).
  - [X] Migration edge cases (unmappable model_id, multiple servers, missing entities).
  - [ ] **Functional** test for the server admin form (add / edit / delete, discovery on save,
        validation) — the form is UI + FAPI, so a `BrowserTestBase` test fits better than Unit.
        (Deferred for v2.0 — manual testing performed instead.)
- [~] Upgrade documentation:
  - [x] In-place 1.x → 2.0 upgrade guide (composer update + `drush updb` + re-select notes) — see `UPGRADE.md`.
  - [x] Migration paths from other AI provider modules (OpenAI-compatible, native) — see `UPGRADE.md`.
  - [x] Release notes covering changed provider/model IDs (condense from `UPGRADE.md` into the release). See README "What's new in 2.0".
- [ ] Admin UX polish:
  - [ ] "Test connection" / status action from the server list (beyond form validation).
  - [ ] Show last discovered model count + capability summary in the server list.
  - [ ] **(Post-2.0) Migrate model/operation filtering UI to Views.** Currently the
        model capability overrides live in `LlamaCppServerForm::buildOverridesForm()`
        (per-server checkboxes list of `llama_cpp_model` entities). Blocker: `llama_cpp_model`
        is a **config entity** and Views core has no `views_data` for config entities, so this
        is not a click-in-UI task. Options: (A) custom Views query plugin + views_data over
        config entities (~2-4d, fragile); (B) convert `llama_cpp_model` to a content entity +
        data-migration update hook, then standard View (~3-5d, cleaner but touches the
        config/deployment model); (C) cheap win — keep the form, improve UX with tableselect /
        pagination (~half-day). The `model_filter` pattern textfield is a string on the server
        config, not a list — out of scope for Views.
- [X] i18n audit (interface strings only):
  - [x] `t()` / `TranslatableMarkup` on all UI strings: provider label, entity labels,
        form labels/descriptions/errors, update hook messages, moderation messages.
  - [x] Use `StringTranslationTrait`; proper `@placeholder` / `%placeholder` usage.
  - [X] Generate and ship a `.pot` template.

## Phase 2 — Reusable OpenAI-compatible base

- [~] Extract shared OpenAI-compatible logic into a trait/base (prep for Track B reuse).
  - [x] Moved model discovery, persistence, ID building, capability detection and HF lookup into dedicated `ModelCatalog` service.
    - Review points in REVIEW-modelcatalog.md addressed:
      - 🔴 Autowire fixed (explicit transliteration + key.repository).
      - 🟠 Real client (with proper Key auth) now passed from provider; errors propagate to provider logger.
  - [ ] Further extraction of server context, custom operation handlers (rerank/moderation), and chat wrappers.
- [ ] Keep `OpenAiBasedProviderClientBase` leverage for the compatible path.

## Phase 2.5 — Spanish translation (non-blocking)

- [x] Spanish `.po` completed and shipped in `translations/ai_provider_llama_cpp.es.po` (in-repo for convenience; also contribute via localize.drupal.org).
- [x] README note added about Spanish translation.

## Phase 3 — Optional AI 1.3+ Guardrails integration

- [ ] Behind `module_exists('ai')` + `version_compare`, expose moderation models to the
      Guardrails system when AI ≥ 1.3 is present. Must stay optional (min target = AI ^1.2).

## Phase 4 — Second concrete provider plugin (validation)

- [ ] Add a second `#[AiProvider]` plugin to validate the abstraction (no derivers).

## Phase 5 — Evaluate vendor-neutral positioning (3.x)

- [ ] Decide on the universal module name/project (`ai_provider_universal`?) and whether to
      extract Track B. Resolve open questions in DESIGN.md §11.

---

## Release gate — 2.0 stable success criteria (DESIGN.md §12)

- [x] All existing 1.2.x functionality works at least as well. (Unit + Kernel tests + manual verification)
- [x] Migration from the llama.cpp module documented and tested via update hooks, with
      `model_id` auto-remapped. (See UPGRADE.md + Kernel update tests)
- [x] `getConfiguredModels()` read-only, asserted by a test.
- [x] No derivers used for core multiplicity.
- [x] Clear AI 1.2-vs-1.4 compatibility statement. (See UPGRADE.md + README)
- [x] All interface strings marked + `.pot` exported. Spanish `.po` shipped in `translations/`.

## Drupal best practices (from `~/Proyects/ai_best_practices`)

Cross-cutting quality items aligned with the `ai_best_practices` skills.

- [x] **Docs** (`drupal-writing-documentation`): README updated for 2.0 release with clear "What's new", upgrade path, and Spanish note. Aligned with scannable structure (full template alignment can be iterated).
- [ ] **Accessibility** (`drupal-accessibility`): audit the server add/edit form (FAPI) —
      labels tied to inputs, fieldset/legend grouping, error messages associated with fields,
      no color-only state in the server list builder.
- [ ] **Render pipeline** (`drupal-render-pipeline`): the list builder / forms emit render
      arrays (no raw HTML string concatenation), placeholders auto-escaped via `t()`.
- [ ] **i18n** (see Phase 1): all interface strings via `t()` / `TranslatableMarkup`,
      `.pot` exported; Spanish `.po` via localize.drupal.org (Phase 2.5).

## CI / release housekeeping

- [x] Add `OPT_IN_TEST_NEXT_MAJOR` to GitLab CI to validate Drupal 12 (carried over from 1.x).
- [x] GitLab CI (`.gitlab-ci.yml`) green: PHPUnit (Kernel + Functional), phpcs, phpstan —
      (Local: Unit + Kernel pass, phpstan clean. Functional deferred to manual test.)
- [x] Confirm `composer.json` `drupal/ai: ^1.2.0` and info.yml core requirement before tagging.
- [x] phpcs / phpstan clean; cspell dictionary updated. (phpstan clean verified pre-release)
