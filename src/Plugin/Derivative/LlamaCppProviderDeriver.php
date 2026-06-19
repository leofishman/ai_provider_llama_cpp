<?php

namespace Drupal\ai_provider_llama_cpp\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Derives one AI provider plugin per configured server entity.
 *
 * Each llama_cpp_server config entity produces a plugin derivative
 * with ID "llama_cpp:{server_id}", making it appear as an individual
 * provider within the AI module.
 */
class LlamaCppProviderDeriver extends DeriverBase implements ContainerDeriverInterface {

  /**
   * Constructs the deriver.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $servers = $this->entityTypeManager
      ->getStorage('llama_cpp_server')
      ->loadMultiple();

    foreach ($servers as $server) {
      $this->derivatives[$server->id()] = [
        'label' => new TranslatableMarkup('@label', ['@label' => $server->label()]),
      ] + $base_plugin_definition;
    }

    return $this->derivatives;
  }

}
