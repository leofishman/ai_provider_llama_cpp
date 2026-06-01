<?php

namespace Drupal\ai_provider_llama_cpp\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\OpenAiBasedProviderClientBase;
use Drupal\ai\Exception\AiRequestErrorException;
use Drupal\ai\Traits\OperationType\ChatTrait;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Component\Transliteration\TransliterationInterface;
use GuzzleHttp\Client as GuzzleClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation for llama.cpp OpenAI-compatible servers.
 */
#[AiProvider(
  id: 'llama_cpp',
  label: new TranslatableMarkup('llama.cpp (OpenAI-compatible)'),
)]
class LlamaCppProvider extends OpenAiBasedProviderClientBase {

  use StringTranslationTrait;
  use ChatTrait;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The transliteration service.
   *
   * @var \Drupal\Core\Transliteration\TransliterationInterface
   */
  protected TransliterationInterface $transliteration;

  /**
   * Cached model mapping.
   *
   * @var array
   */
  protected array $models = [];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->state = $container->get('state');
    $instance->transliteration = $container->get('transliteration');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    if (!$this->getBaseHost()) {
      return FALSE;
    }
    if ($operation_type) {
      return in_array($operation_type, $this->getSupportedOperationTypes());
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function hasAuthentication(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthentication(mixed $authentication): void {
    $this->client = NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return [
      'chat',
      'embeddings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   */
  protected function loadClient(): void {
    if (empty($this->client)) {
      $host = $this->getBaseHost();
      if (!$host) {
        throw new AiRequestErrorException('llama.cpp host is not configured.');
      }
      $this->setEndpoint(rtrim($host, '/') . '/v1');
      $this->setHttpClient(new GuzzleClient(['timeout' => 600]));
      $this->client = $this->createClient();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    try {
      $this->loadClient();
      $response = $this->client->models()->list()->toArray();
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('ai_provider_llama_cpp')->error(
        'Failed to get models from llama.cpp: @message',
        ['@message' => $e->getMessage()]
      );
      $this->models = $this->state->get('ai_provider_llama_cpp.models', []);
      return $this->models;
    }

    $models = [];
    foreach ($response['data'] ?? [] as $model) {
      $raw_model_id = $model['id'];
      $machine_model_id = $this->getMachineName($raw_model_id);
      $models[$machine_model_id] = $raw_model_id;
    }

    $this->models = $models;
    $this->state->set('ai_provider_llama_cpp.models', $models);

    return $models;
  }

  /**
   * Gets the raw model identifier from the stored mapping.
   *
   * @param string $model_id
   *   The machine-safe model id.
   *
   * @return string
   *   The raw llama.cpp model id.
   */
  protected function getModel(string $model_id): string {
    if (empty($this->models)) {
      $this->models = $this->state->get('ai_provider_llama_cpp.models', []);
    }
    return $this->models[$model_id] ?? $model_id;
  }

  /**
   * {@inheritdoc}
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    $model_id = $this->getModel($model_id);
    return parent::chat($input, $model_id, $tags);
  }

  /**
   * {@inheritdoc}
   */
  public function embeddings(string|EmbeddingsInput $input, string $model_id, array $tags = []): EmbeddingsOutput {
    $model_id = $this->getModel($model_id);
    return parent::embeddings($input, $model_id, $tags);
  }

  /**
   * Generates a machine name from a string.
   *
   * @param string $string
   *   String to have translated.
   *
   * @return string
   *   The machine name.
   */
  protected function getMachineName(string $string): string {
    $transliterated = $this->transliteration->transliterate($string, LanguageInterface::LANGCODE_DEFAULT, '_');
    $transliterated = mb_strtolower($transliterated);
    return (string) preg_replace('@[^a-z0-9_]+@', '_', $transliterated);
  }

  /**
   * {@inheritdoc}
   */
  public function embeddingsVectorSize(string $model_id): int {
    $this->loadClient();
    $raw_model_id = $this->getModel($model_id);
    try {
      $data = $this->client->models()->retrieve($raw_model_id)->toArray();
      if (!empty($data['embedding']) && is_array($data['embedding'])) {
        return (int) ($data['embedding']['size'] ?? count($data['embedding']));
      }
      if (!empty($data['hidden_size'])) {
        return (int) $data['hidden_size'];
      }
      if (!empty($data['context_length'])) {
        return (int) $data['context_length'];
      }
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('ai_provider_llama_cpp')->warning(
        'Could not determine embedding vector size for model @model: @message',
        ['@model' => $raw_model_id, '@message' => $e->getMessage()]
      );
    }
    return parent::embeddingsVectorSize($model_id);
  }

  /**
   * Tests connectivity to the llama.cpp server.
   *
   * @throws \Drupal\ai\Exception\AiRequestErrorException
   *   If the server is unreachable or not configured.
   */
  public function testConnection(): void {
    $this->loadClient();
    $this->client->models()->list();
  }

  /**
   * Gets the host configured for llama.cpp.
   *
   * @return string
   *   The base host URL.
   */
  protected function getBaseHost(): string {
    $host = $this->configuration['host_name'] ?? $this->getConfig()->get('host_name');
    $host = rtrim((string) $host, '/');
    $port = $this->configuration['port'] ?? $this->getConfig()->get('port');
    if ($port) {
      $host .= ':' . $port;
    }
    return $host;
  }

}
