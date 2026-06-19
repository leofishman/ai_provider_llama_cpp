<?php

namespace Drupal\ai_provider_llama_cpp\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\OpenAiBasedProviderClientBase;
use Drupal\ai\Exception\AiRequestErrorException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\ai\OperationType\Moderation\ModerationInput;
use Drupal\ai\OperationType\Moderation\ModerationInterface;
use Drupal\ai\OperationType\Moderation\ModerationOutput;
use Drupal\ai\OperationType\Moderation\ModerationResponse;
use Drupal\ai\OperationType\Rerank\ReRankInput;
use Drupal\ai\OperationType\Rerank\ReRankInterface;
use Drupal\ai\OperationType\Rerank\ReRankOutput;
use Drupal\ai\OperationType\SpeechToText\SpeechToTextInput;
use Drupal\ai\OperationType\SpeechToText\SpeechToTextOutput;
use Drupal\ai\Traits\OperationType\ChatTrait;
use Drupal\ai_provider_llama_cpp\Entity\LlamaCppServerInterface;
use Drupal\ai_provider_llama_cpp\Models\Moderation\LlamaGuard3;
use Drupal\ai_provider_llama_cpp\Models\Moderation\ShieldGemma;
use Drupal\ai_provider_llama_cpp\Plugin\Derivative\LlamaCppProviderDeriver;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Component\Transliteration\TransliterationInterface;
use GuzzleHttp\Client as GuzzleClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation for OpenAI-compatible servers.
 *
 * Each configured server entity produces a derived plugin instance
 * (e.g. "llama_cpp:my_server") that appears as an individual provider
 * within the AI module.
 */
#[AiProvider(
  id: 'llama_cpp',
  label: new TranslatableMarkup('llama.cpp (OpenAI-compatible)'),
  deriver: LlamaCppProviderDeriver::class,
)]
class LlamaCppProvider extends OpenAiBasedProviderClientBase implements ReRankInterface, ModerationInterface {

  use StringTranslationTrait;
  use ChatTrait;

  /**
   * All operation types this provider can support.
   */
  const SUPPORTED_OPERATION_TYPES = ['chat', 'embeddings', 'speech_to_text', 'rerank', 'moderation'];

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
   * Map from moderation model name patterns to parser classes.
   */
  const MODERATION_PARSERS = [
    'llama-guard3' => LlamaGuard3::class,
    'llamaguard'   => LlamaGuard3::class,
    'llama_guard'  => LlamaGuard3::class,
    'shieldgemma'  => ShieldGemma::class,
    'shield_gemma' => ShieldGemma::class,
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
   * Cached server entity.
   *
   * @var \Drupal\ai_provider_llama_cpp\Entity\LlamaCppServerInterface|null|false
   */
  protected LlamaCppServerInterface|null|false $serverEntity = FALSE;

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
   * Gets the server config entity for this derived plugin instance.
   *
   * @return \Drupal\ai_provider_llama_cpp\Entity\LlamaCppServerInterface|null
   *   The server entity, or NULL for non-derived usage.
   */
  protected function getServerEntity(): ?LlamaCppServerInterface {
    if ($this->serverEntity === FALSE) {
      $derivative_id = $this->getDerivativeId();
      if ($derivative_id) {
        $this->serverEntity = \Drupal::entityTypeManager()
          ->getStorage('llama_cpp_server')
          ->load($derivative_id);
      }
      else {
        $this->serverEntity = NULL;
      }
    }
    return $this->serverEntity;
  }

  /**
   * Returns the State key prefix for this server instance.
   *
   * @return string
   *   A prefix like "ai_provider_llama_cpp.server.my_server".
   */
  protected function getStateKeyPrefix(): string {
    $derivative_id = $this->getDerivativeId();
    if ($derivative_id) {
      return "ai_provider_llama_cpp.server.{$derivative_id}";
    }
    return 'ai_provider_llama_cpp';
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
    $server = $this->getServerEntity();
    return $server && !empty($server->getApiKey());
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
    $server = $this->getServerEntity();
    if ($server) {
      $types = $server->getOperationTypes();
      if (!empty($types)) {
        return $types;
      }
    }
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
        throw new AiRequestErrorException('Server host is not configured.');
      }
      $this->setEndpoint(rtrim($host, '/') . '/v1');

      $timeout = 600;
      $server = $this->getServerEntity();
      if ($server) {
        $timeout = $server->getTimeout() ?: 600;
        $api_key = $server->getApiKey();
        if ($api_key) {
          // Set API key in configuration so the base class picks it up.
          $this->configuration['api_key'] = $api_key;
        }
      }
      $timeout = $this->configuration['timeout'] ?? $timeout;

      $this->setHttpClient(new GuzzleClient(['timeout' => $timeout]));
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
        'Failed to get models from server: @message',
        ['@message' => $e->getMessage()]
      );
      return $this->getFallbackModels($operation_type);
    }

    $prefix = $this->getStateKeyPrefix();
    $overrides = $this->state->get("{$prefix}.model_overrides", []);
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
    $this->state->set("{$prefix}.models", $all_models);
    $this->state->set("{$prefix}.model_types", $all_types);

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
      $timeout = 600;
      $server = $this->getServerEntity();
      if ($server) {
        $timeout = $server->getTimeout() ?: 600;
      }
      $options = ['timeout' => $timeout, 'json' => $payload];

      $api_key = $server?->getApiKey();
      if ($api_key) {
        $options['headers'] = ['Authorization' => 'Bearer ' . $api_key];
      }

      $http = new GuzzleClient($options);
      $response = $http->request('POST', rtrim($this->getBaseHost(), '/') . '/v1/rerank');
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
  public function moderation(string|ModerationInput $input, ?string $model_id = NULL, array $tags = []): ModerationOutput {
    $this->loadClient();

    $prompt = $input instanceof ModerationInput ? $input->getPrompt() : $input;
    $raw_model_id = $this->getModel($model_id);

    $payload = [
      'model' => $raw_model_id,
      'messages' => [
        ['role' => 'user', 'content' => $prompt],
      ],
    ] + $this->configuration;

    $response = $this->client->chat()->create($payload)->toArray();
    if (!isset($response['choices'][0]['message']['content'])) {
      throw new AiRequestErrorException('No content in moderation response.');
    }
    $message = $response['choices'][0]['message']['content'];

    // Find the right parser for this model.
    $parser_class = $this->getModerationParser($raw_model_id);
    if ($parser_class) {
      $moderation_response = $parser_class::parse($message);
    }
    else {
      // Unknown moderation model: flag if output contains "unsafe".
      $flagged = str_contains(strtolower($message), 'unsafe');
      $moderation_response = new ModerationResponse($flagged);
    }

    return new ModerationOutput($moderation_response, $message, $response);
  }

  /**
   * Finds the moderation parser class for a model ID.
   *
   * @param string $model_id
   *   The raw model ID.
   *
   * @return string|null
   *   The parser class FQCN, or NULL if unknown.
   */
  protected function getModerationParser(string $model_id): ?string {
    $name = strtolower($model_id);
    foreach (self::MODERATION_PARSERS as $pattern => $class) {
      if (str_contains($name, $pattern)) {
        return $class;
      }
    }
    return NULL;
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
   * Tests connectivity to the server.
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
   *  5. Default: chat.
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
    if (preg_match('/llama.guard|llamaguard|shield.?gemma/', $name)) {
      return ['moderation'];
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
    $prefix = $this->getStateKeyPrefix();
    $this->models = $this->state->get("{$prefix}.models", []);
    if ($operation_type === NULL) {
      return $this->models;
    }
    $types = $this->state->get("{$prefix}.model_types", []);
    $overrides = $this->state->get("{$prefix}.model_overrides", []);
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
   *   The raw model id.
   */
  protected function getModel(string $model_id): string {
    if (empty($this->models)) {
      $prefix = $this->getStateKeyPrefix();
      $this->models = $this->state->get("{$prefix}.models", []);
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
   * Gets the base host URL for the server.
   *
   * Reads from the config entity when available (derived instance),
   * or from runtime configuration (form validation / temporary instances).
   *
   * @return string
   *   The base host URL.
   */
  protected function getBaseHost(): string {
    $server = $this->getServerEntity();
    if ($server) {
      $host = rtrim((string) $server->getHostName(), '/');
      $port = $server->getPort();
    }
    else {
      // Fallback: runtime configuration (e.g. form validation).
      $host = rtrim((string) ($this->configuration['host_name'] ?? $this->getConfig()->get('host_name') ?? ''), '/');
      $port = $this->configuration['port'] ?? $this->getConfig()->get('port') ?? '';
    }
    if ($port) {
      $host .= ':' . $port;
    }
    return $host;
  }

}
