<?php

namespace Drupal\ai_provider_llama_cpp;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * List builder for llama_cpp_server config entities.
 */
class LlamaCppServerListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Server');
    $header['host'] = $this->t('Host');
    $header['operation_types'] = $this->t('Operation Types');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\ai_provider_llama_cpp\Entity\LlamaCppServerInterface $entity */
    $row['label'] = $entity->label();

    $host = $entity->getHostName();
    $port = $entity->getPort();
    $row['host'] = $port ? "{$host}:{$port}" : $host;

    $types = $entity->getOperationTypes();
    $row['operation_types'] = $types ? implode(', ', $types) : $this->t('Auto-detect');

    return $row + parent::buildRow($entity);
  }

}
