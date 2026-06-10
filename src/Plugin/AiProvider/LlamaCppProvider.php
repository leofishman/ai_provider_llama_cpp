<?php

namespace Drupal\ai_provider_llama_cpp\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\OpenAiBasedProviderClientBase;
use Drupal\ai\Exception\AiRequestErrorException;
use Drupal\ai\OperationType\Rerank\ReRankInput;
use Drupal\ai\OperationType\Rerank\ReRankInterface;
use Drupal\ai\OperationType\Rerank\ReRankOutput;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\ai\OperationType\SpeechToText\SpeechToTextInput;
use Drupal\ai\OperationType\SpeechToText\SpeechToTextOutput;
use Drupal\ai\Traits\OperationType\ChatTrait;
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
class LlamaCppProvider extends OpenAiBasedProviderClientBase implements ReRankInterface {

  use StringTranslationTrait;
  use ChatTrait;

  /**
   * Operation types detectable from llama.cpp server metadata or HF API.
   */
  const SUPPORTED_OPERATION_TYPES = ['chat', 'embeddings', 'speech_to_text', 'rerank'];

  /**
   * Map from HuggingFace pipeline_tag to Drupal AI operation type id.
   */
  const HF_TAG_MAP = [
    'text-generation'               => 'chat',
    'text2text-generation'          => 'chat',
    'feature-extraction'            => 'embeddings',
    'sentence-similarity'           => 'embeddings',
    'automatic-speech-recognition'  => 'speech_to_text',
    'text-to-speech'                => 'text_to_speech',
    'text-to-image'                 => 'text_to_image',
    'text-ranking'                  => 'rerank',
  ];

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The transliteration service.
   *
   * @var \Drupal\Component\Transliteration\TransliterationInterface
   */
  protected TransliterationInterface $transliteration;

  /**
   * Cached model mapping (machine_id => raw_id).
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
    return self::SUPPORTED_OPERATION_TYPES;
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
      return $this->getFallbackModels($operation_type);
    }

    $overrides = $this->state->get('ai_provider_llama_cpp.model_overrides', []);
    $all_models = [];
    $all_types = [];
    $filtered = [];

    foreach ($response['data'] ?? [] as $model) {
      $raw_id = $model['id'];
      $machine_id = $this->getMachineName($raw_id);

      $detected = $this->detectOperationTypes($model);
      $all_models[$machine_id] = $raw_id;
      $all_types[$machine_id] = $detected;

      $effective = $overrides[$machine_id] ?? $detected;
      if ($operation_type === NULL || in_array($operation_type, $effective)) {
        $filtered[$machine_id] = $raw_id;
      }
    }

    $this->models = $all_models;
    $this->state->set('ai_provider_llama_cpp.models', $all_models);
    $this->state->set('ai_provider_llama_cpp.model_types', $all_types);

    return $filtered;
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
   * {@inheritdoc}
   */
  public function speechToText(string|SpeechToTextInput $input, string $model_id, array $tags = []): SpeechToTextOutput {
    $model_id = $this->getModel($model_id);
    return parent::speechToText($input, $model_id, $tags);
  }

  /**
   * {@inheritdoc}
   */
  public function rerank(ReRankInput $input, string $model_id, array $tags = []): ReRankOutput {
    $this->loadClient();
    $raw_model_id = $this->getModel($model_id);

    $payload = [
      'model'     => $raw_model_id,
      'query'     => $input->getQuery(),
      'documents' => $input->getInputs(),
    ];
    if ($input->getTopN() > 0) {
      $payload['top_n'] = $input->getTopN();
    }

    try {
      $http = new GuzzleClient(['timeout' => 600]);
      $response = $http->request('POST', rtrim($this->getBaseHost(), '/') . '/v1/rerank', [
        'json' => $payload,
      ]);
      $data = json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (\Throwable $e) {
      $this->handleApiThrowable($e);
      throw $e;
    }

    return new ReRankOutput(
      $data['results'] ?? [],
      $data['id'] ?? '',
      $data,
    );
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
   * Detects the operation types supported by a model from server metadata.
   *
   * Detection cascade:
   *  1. --embeddings flag in status.args
   *  2. --reranking flag in status.args
   *  3. HuggingFace API pipeline_tag (via --hf-repo in status.args)
   *  4. Model name heuristics
   *  5. Default: chat
   *
   * @param array $model
   *   A model entry from the /v1/models response.
   *
   * @return string[]
   *   Array of operation type IDs.
   */
  protected function detectOperationTypes(array $model): array {
    $args = $model['status']['args'] ?? [];

    if (in_array('--embeddings', $args, TRUE)) {
      return ['embeddings'];
    }
    if (in_array('--reranking', $args, TRUE)) {
      return ['rerank'];
    }

    $hf_repo = $this->extractHfRepo($args);
    if ($hf_repo) {
      $tag = $this->getHfPipelineTag($hf_repo);
      if ($tag && isset(self::HF_TAG_MAP[$tag])) {
        return [self::HF_TAG_MAP[$tag]];
      }
    }

    $name = strtolower($model['id']);
    if (preg_match('/whisper|wav2vec|vosk/', $name)) {
      return ['speech_to_text'];
    }
    if (preg_match('/rerank/', $name)) {
      return ['rerank'];
    }
    if (preg_match('/embed|bge[-_]|nomic|e5[-_]|gte[-_]|minilm/', $name)) {
      return ['embeddings'];
    }

    return ['chat'];
  }

  /**
   * Extracts the HuggingFace repo identifier from a model's CLI args.
   *
   * @param array $args
   *   The status.args array from the /v1/models response.
   *
   * @return string|null
   *   The repo in "owner/name" format, or NULL if not found.
   */
  protected function extractHfRepo(array $args): ?string {
    $index = array_search('--hf-repo', $args, TRUE);
    if ($index === FALSE || !isset($args[$index + 1])) {
      return NULL;
    }
    // Strip quantization tag: "owner/repo:Q4_K_M" → "owner/repo".
    return explode(':', $args[$index + 1])[0];
  }

  /**
   * Fetches the pipeline_tag for a HuggingFace repo, with State-based cache.
   *
   * @param string $repo
   *   The HuggingFace repo in "owner/name" format.
   *
   * @return string|null
   *   The pipeline_tag value, or NULL on failure.
   */
  protected function getHfPipelineTag(string $repo): ?string {
    $cache = $this->state->get('ai_provider_llama_cpp.hf_tag_cache', []);
    if (array_key_exists($repo, $cache)) {
      return $cache[$repo];
    }

    try {
      $http = new GuzzleClient(['timeout' => 5]);
      $response = $http->request('GET', "https://huggingface.co/api/models/{$repo}");
      $data = json_decode($response->getBody()->getContents(), TRUE);
      $tag = $data['pipeline_tag'] ?? NULL;
    }
    catch (\Throwable) {
      $tag = NULL;
    }

    $cache[$repo] = $tag;
    $this->state->set('ai_provider_llama_cpp.hf_tag_cache', $cache);

    return $tag;
  }

  /**
   * Returns models from State cache, filtered by operation type if given.
   *
   * @param string|null $operation_type
   *   Operation type to filter by, or NULL for all models.
   *
   * @return array
   *   Machine-safe model IDs mapped to raw model IDs.
   */
  protected function getFallbackModels(?string $operation_type): array {
    $this->models = $this->state->get('ai_provider_llama_cpp.models', []);
    if ($operation_type === NULL) {
      return $this->models;
    }
    $types = $this->state->get('ai_provider_llama_cpp.model_types', []);
    $overrides = $this->state->get('ai_provider_llama_cpp.model_overrides', []);
    return array_filter(
      $this->models,
      function ($raw_id, $machine_id) use ($operation_type, $types, $overrides): bool {
        $effective = $overrides[$machine_id] ?? $types[$machine_id] ?? ['chat'];
        return in_array($operation_type, $effective, TRUE);
      },
      ARRAY_FILTER_USE_BOTH,
    );
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
