<?php

namespace Drupal\ai_provider_llama_cpp\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_provider_llama_cpp\Form\LlamaCppServerDeleteForm;
use Drupal\ai_provider_llama_cpp\Form\LlamaCppServerForm;
use Drupal\ai_provider_llama_cpp\LlamaCppServerListBuilder;

/**
 * Defines a configured OpenAI-compatible server instance.
 */
#[ConfigEntityType(
  id: 'llama_cpp_server',
  label: new TranslatableMarkup('OpenAI-compatible Server'),
  label_collection: new TranslatableMarkup('Servers'),
  label_singular: new TranslatableMarkup('server'),
  label_plural: new TranslatableMarkup('servers'),
  handlers: [
    'list_builder' => LlamaCppServerListBuilder::class,
    'form' => [
      'add' => LlamaCppServerForm::class,
      'edit' => LlamaCppServerForm::class,
      'delete' => LlamaCppServerDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  config_prefix: 'server',
  admin_permission: 'administer ai providers',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
  ],
  config_export: [
    'id',
    'label',
    'host_name',
    'port',
    'api_key',
    'timeout',
    'operation_types',
    'model_filter',
  ],
  links: [
    'add-form' => '/admin/config/ai/providers/llama-cpp/add',
    'edit-form' => '/admin/config/ai/providers/llama-cpp/{llama_cpp_server}',
    'delete-form' => '/admin/config/ai/providers/llama-cpp/{llama_cpp_server}/delete',
    'collection' => '/admin/config/ai/providers/llama-cpp',
  ],
)]
class LlamaCppServer extends ConfigEntityBase implements LlamaCppServerInterface {

  /**
   * The server machine name.
   *
   * @var string
   */
  protected string $id;

  /**
   * The human-readable server label.
   *
   * @var string
   */
  protected string $label;

  /**
   * The host name with protocol.
   *
   * @var string
   */
  protected string $host_name = '';

  /**
   * The port number.
   *
   * @var string
   */
  protected string $port = '8080';

  /**
   * Optional Key entity ID for authenticated servers.
   *
   * @var string
   */
  protected string $api_key = '';

  /**
   * Request timeout in seconds.
   *
   * @var int
   */
  protected int $timeout = 600;

  /**
   * Manually configured operation types (empty = auto-detect).
   *
   * @var string[]
   */
  protected array $operation_types = [];

  /**
   * Model filtering pattern (comma-separated globs/sub-strings).
   *
   * @var string
   */
  protected string $model_filter = '';

  /**
   * {@inheritdoc}
   */
  public function getHostName(): string {
    return $this->host_name;
  }

  /**
   * {@inheritdoc}
   */
  public function getPort(): string {
    return $this->port;
  }

  /**
   * {@inheritdoc}
   */
  public function getApiKey(): string {
    return $this->api_key;
  }

  /**
   * {@inheritdoc}
   */
  public function getTimeout(): int {
    return $this->timeout ?: 600;
  }

  /**
   * {@inheritdoc}
   */
  public function getOperationTypes(): array {
    return $this->operation_types ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getModelFilter(): string {
    return $this->model_filter ?? '';
  }

}
