<?php

namespace Drupal\ai_provider_llama_cpp\Models\Moderation;

use Drupal\ai\OperationType\Moderation\ModerationResponse;

/**
 * Response parser for Google's ShieldGemma moderation model.
 *
 * ShieldGemma outputs "Yes" (unsafe) or "No" (safe).
 *
 * @see https://huggingface.co/google/shieldgemma-2b
 */
class ShieldGemma {

  /**
   * Parses a ShieldGemma response into a ModerationResponse.
   *
   * @param string $response
   *   The raw text response from the model.
   *
   * @return \Drupal\ai\OperationType\Moderation\ModerationResponse
   *   The moderation response.
   */
  public static function parse(string $response): ModerationResponse {
    $flagged = strtolower(trim($response)) === 'yes';
    return new ModerationResponse($flagged);
  }

}
