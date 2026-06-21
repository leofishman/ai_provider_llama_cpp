<?php

namespace Drupal\ai_provider_llama_cpp\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Interface for llama.cpp server config entities.
 */
interface LlamaCppServerInterface extends ConfigEntityInterface {

  /**
   * Gets the host name (including protocol).
   */
  public function getHostName(): string;

  /**
   * Gets the port.
   */
  public function getPort(): string;

  /**
   * Gets the Key entity ID for API authentication, if any.
   *
   * Empty string means no authentication (e.g. local llama.cpp).
   */
  public function getApiKey(): string;

  /**
   * Gets the request timeout in seconds.
   */
  public function getTimeout(): int;

  /**
   * Gets the manually configured operation types.
   *
   * @return string[]
   *   Operation type IDs, or empty array for auto-detection.
   */
  public function getOperationTypes(): array;

  /**
   * Gets the model filter pattern.
   *
   * @return string
   *   Glob-style or simple string filter pattern.
   */
  public function getModelFilter(): string;

}
