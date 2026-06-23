<?php

namespace Drupal\ai_provider_llama_cpp\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Interface for llama.cpp model config entities.
 *
 * Models are discovered from servers and stored as first-class config entities
 * (instead of State). This enables Views, export, per-model config, etc.
 */
interface LlamaCppModelInterface extends ConfigEntityInterface {

  /**
   * Gets the owning server entity ID.
   */
  public function getServerId(): string;

  /**
   * Gets the raw model identifier as reported by the /v1/models endpoint.
   */
  public function getRawModelId(): string;

  /**
   * Gets auto-detected operation types (from server flags, HF tags, heuristics).
   *
   * @return string[]
   */
  public function getDetectedOperationTypes(): array;

  /**
   * Gets the manually overridden operation types for this model.
   *
   * Empty array means "use detected".
   *
   * @return string[]
   */
  public function getOperationTypes(): array;

  /**
   * Sets manual operation types (overrides). Empty = use detected.
   */
  public function setOperationTypes(array $types): self;

  /**
   * Returns the effective operation types for this model.
   *
   * Prefers manual overrides; falls back to detected.
   *
   * @return string[]
   */
  public function getEffectiveOperationTypes(): array;

  /**
   * Sets the detected operation types (internal, from discovery).
   */
  public function setDetectedOperationTypes(array $types): self;

  /**
   * Sets the raw model id.
   */
  public function setRawModelId(string $raw_id): self;

  /**
   * Sets the server id this model belongs to.
   */
  public function setServerId(string $server_id): self;

}
