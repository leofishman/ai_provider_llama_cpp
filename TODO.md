# TODO — ai_provider_llama_cpp 2.0

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

- [ ] Expand test coverage (prefer **Kernel/Functional** over Unit — see
      `drupal-automated-testing`; use required PHPUnit attributes `#[Group]` / `#[CoversClass]`):
  - [x] Model discovery + filtering edge cases (glob allow/deny, `!*old*`).
  - [x] Moderation parsers (LlamaGuard3 + ShieldGemma multi-policy).
  - [~] Capability detection (args + name heuristics) covered by `ModelCatalogTest`;
        end-to-end rerank / text-to-image operation request paths still pending.
  - [ ] Multi-server setups (chat / embeddings / moderation on different backends).
  - [ ] Migration edge cases (unmappable model_id, multiple servers, missing entities).
  - [ ] **Functional** test for the server admin form (add / edit / delete, discovery on save,
        validation) — the form is UI + FAPI, so a `BrowserTestBase` test fits better than Unit.
- [~] Upgrade documentation:
  - [x] In-place 1.x → 2.0 upgrade guide (composer update + `drush updb` + re-select notes) — see `UPGRADE.md`.
  - [x] Migration paths from other AI provider modules (OpenAI-compatible, native) — see `UPGRADE.md`.
  - [ ] Release notes covering changed provider/model IDs (condense from `UPGRADE.md` into the release).
- [ ] Admin UX polish:
  - [ ] "Test connection" / status action from the server list (beyond form validation).
  - [ ] Show last discovered model count + capability summary in the server list.
- [ ] i18n audit (interface strings only):
  - [ ] `t()` / `TranslatableMarkup` on all UI strings: provider label, entity labels,
        form labels/descriptions/errors, update hook messages, moderation messages.
  - [ ] Use `StringTranslationTrait`; proper `@placeholder` / `%placeholder` usage.
  - [ ] Generate and ship a `.pot` template.

## Phase 2 — Reusable OpenAI-compatible base

- [~] Extract shared OpenAI-compatible logic into a trait/base (prep for Track B reuse).
  - [x] Moved model discovery, persistence, ID building, capability detection and HF lookup into dedicated `ModelCatalog` service.
    - Review points in REVIEW-modelcatalog.md addressed:
      - 🔴 Autowire fixed (explicit transliteration + key.repository).
      - 🟠 Real client (with proper Key auth) now passed from provider; errors propagate to provider logger.
  - [ ] Further extraction of server context, custom operation handlers (rerank/moderation), and chat wrappers.
- [ ] Keep `OpenAiBasedProviderClientBase` leverage for the compatible path.

## Phase 2.5 — Spanish translation (non-blocking)

- [ ] Contribute `es` translation via https://localize.drupal.org once strings are stable.
- [ ] README note that Spanish translation is maintained; optional `README.es.md`.

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

- [ ] All existing 1.2.x functionality works at least as well.
- [ ] Migration from the llama.cpp module documented and tested via update hooks, with
      `model_id` auto-remapped.
- [ ] `getConfiguredModels()` read-only, asserted by a test.
- [ ] No derivers used for core multiplicity.
- [ ] Clear AI 1.2-vs-1.4 compatibility statement.
- [ ] All interface strings marked + `.pot` exported (Spanish `.po` is a follow-up).

## Drupal best practices (from `~/Proyects/ai_best_practices`)

Cross-cutting quality items aligned with the `ai_best_practices` skills.

- [ ] **Docs** (`drupal-writing-documentation`): align README with the canonical
      [contrib README template](https://www.drupal.org/docs/develop/managing-a-drupalorg-theme-module-or-distribution/documenting-your-project/readme-template);
      keep it scannable and opinionated; document Drupal, link out for third-party servers.
- [ ] **Accessibility** (`drupal-accessibility`): audit the server add/edit form (FAPI) —
      labels tied to inputs, fieldset/legend grouping, error messages associated with fields,
      no color-only state in the server list builder.
- [ ] **Render pipeline** (`drupal-render-pipeline`): the list builder / forms emit render
      arrays (no raw HTML string concatenation), placeholders auto-escaped via `t()`.
- [ ] **i18n** (see Phase 1): all interface strings via `t()` / `TranslatableMarkup`,
      `.pot` exported; Spanish `.po` via localize.drupal.org (Phase 2.5).

## CI / release housekeeping

- [x] Add `OPT_IN_TEST_NEXT_MAJOR` to GitLab CI to validate Drupal 12 (carried over from 1.x).
- [ ] GitLab CI (`.gitlab-ci.yml`) green: PHPUnit (Kernel + Functional), phpcs, phpstan —
      see `drupal-gitlab` for the issue-fork / MR workflow when contributing back.
- [ ] Confirm `composer.json` `drupal/ai: ^1.2.0` and info.yml core requirement before tagging.
- [ ] phpcs / phpstan clean; cspell dictionary updated.
