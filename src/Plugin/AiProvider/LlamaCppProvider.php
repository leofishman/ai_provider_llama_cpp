<?php

namespace Drupal\ai_provider_llama_cpp\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\OpenAiBasedProviderClientBase;
use Drupal\ai\Exception\AiRequestErrorException;
use Drupal\ai\Exception\AiSetupFailureException;
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
use Drupal\ai\OperationType\TextToImage\TextToImageInput;
use Drupal\ai\OperationType\TextToImage\TextToImageInterface;
use Drupal\ai\OperationType\TextToImage\TextToImageOutput;
use Drupal\ai\Traits\OperationType\ChatTrait;
use Drupal\ai_provider_llama_cpp\Entity\LlamaCppModelInterface;
use Drupal\ai_provider_llama_cpp\Entity\LlamaCppServerInterface;
use Drupal\ai_provider_llama_cpp\Models\Moderation\LlamaGuard3;
use Drupal\ai_provider_llama_cpp\Models\Moderation\ShieldGemma;
use Drupal\ai_provider_llama_cpp\Utility\ModelFilter;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin for OpenAI-compatible servers (llama.cpp, Ollama, vLLM, LiteLLM, …).
 *
 * This is a single non-derived plugin. Multi-server support is achieved via
 * llama_cpp_server config entities + llama_cpp_model config entities.
 * Model IDs are unique across servers to allow the AI module to pick specific
 * backends/models without using plugin derivatives.
 */
#[AiProvider(
  id: 'llama_cpp',
  label: new TranslatableMarkup('llama.cpp (OpenAI-compatible)'),
)]
class LlamaCppProvider extends OpenAiBasedProviderClientBase implements ReRankInterface, ModerationInterface, TextToImageInterface {

  use StringTranslationTrait;
  use ChatTrait;

  /**
   * All operation types this provider can support.
   */
  const SUPPORTED_OPERATION_TYPES = ['chat', 'embeddings', 'speech_to_text', 'rerank', 'moderation', 'text_to_image'];

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
    'shield-gemma' => ShieldGemma::class,
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
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The HTTP client factory.
   *
   * @var \Drupal\Core\Http\ClientFactory
   */
  protected ClientFactory $httpClientFactory;

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
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->httpClientFactory = $container->get('http_client_factory');
    return $instance;
  }

  /**
   * Gets the server config entity.
   *
   * Resolution order:
   * 1. Explicit server_id passed via plugin $configuration when instantiated.
   * 2. Active server set for the current model op (setActiveServerForModel).
   * 3. NULL (generic/validation paths that inject host_name into config).
   *
   * @return \Drupal\ai_provider_llama_cpp\Entity\LlamaCppServerInterface|null
   *   The resolved server entity, or NULL when there is no server context.
   */
  protected function getServerEntity(): ?LlamaCppServerInterface {
    if ($this->serverEntity === FALSE) {
      $server_id = $this->configuration['server_id'] ?? NULL;

      if (!$server_id && $this->activeServerId) {
        $server_id = $this->activeServerId;
      }

      if ($server_id) {
        $entity = $this->entityTypeManager
          ->getStorage('llama_cpp_server')
          ->load($server_id);
        $this->serverEntity = $entity instanceof LlamaCppServerInterface ? $entity : NULL;
      }
      else {
        $this->serverEntity = NULL;
      }
    }
    return $this->serverEntity;
  }

  /**
   * Active server ID for the duration of a model-specific operation.
   *
   * @var string|null
   */
  protected ?string $activeServerId = NULL;

  /**
   * Resolve and set the active server based on a model identifier.
   *
   * The model identifier here is the key returned by getConfiguredModels()
   * (which is the llama_cpp_model entity id).
   */
  protected function setActiveServerForModel(string $model_key): void {
    $this->activeServerId = NULL;
    $this->serverEntity = FALSE;

    // Try to load the model entity to find its server.
    $model = $this->entityTypeManager
      ->getStorage('llama_cpp_model')
      ->load($model_key);

    if ($model instanceof LlamaCppModelInterface) {
      $this->activeServerId = $model->getServerId();
    }
    elseif (str_contains($model_key, '__')) {
      // Fallback for legacy compound keys "server__machine" during transition.
      [$maybe_server] = explode('__', $model_key, 2);
      if ($this->entityTypeManager->getStorage('llama_cpp_server')->load($maybe_server)) {
        $this->activeServerId = $maybe_server;
      }
    }
  }

  /**
   * Clear any active server context (call after an operation if needed).
   */
  protected function clearActiveServer(): void {
    $this->activeServerId = NULL;
    $this->serverEntity = FALSE;
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
    parent::setAuthentication($authentication);
    $this->client = NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function loadApiKey(): string {
    $server = $this->getServerEntity();
    $key_id = $server?->getApiKey() ?? '';
    if ($key_id === '') {
      throw new AiSetupFailureException(
        sprintf(
          'Could not load the %s API key, please check your environment settings or your setup key.',
          $this->getPluginDefinition()['label']
        ),
      );
    }

    $api_key = $this->keyRepository->getKey($key_id)?->getKeyValue();
    if (empty($api_key)) {
      throw new AiSetupFailureException(
        sprintf(
          'Could not load the %s API key, please check your environment settings or your setup key.',
          $this->getPluginDefinition()['label']
        ),
      );
    }

    return $api_key;
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

    // No specific server context: union across all servers.
    $storage = $this->entityTypeManager->getStorage('llama_cpp_server');
    $servers = $storage->loadMultiple();
    $union = [];
    foreach ($servers as $srv) {
      $t = $srv->getOperationTypes();
      if (empty($t)) {
        // One unrestricted server => all.
        return self::SUPPORTED_OPERATION_TYPES;
      }
      $union = array_unique(array_merge($union, $t));
    }
    return $union ?: self::SUPPORTED_OPERATION_TYPES;
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
      }
      $timeout = $this->configuration['timeout'] ?? $timeout;

      $this->setHttpClient($this->httpClientFactory->fromOptions(['timeout' => $timeout]));
      $this->client = $this->createClient();
    }
  }

  /**
   * {@inheritdoc}
   *
   * Read-only: this never writes configuration. Model discovery (creating or
   * updating llama_cpp_model config entities) happens explicitly when a server
   * is saved (see LlamaCppServerForm) or via ::discoverModels(). Keeping this
   * read path side-effect free avoids polluting config sync with runtime data
   * pulled from a remote server, and prevents config writes on cache-cold reads
   * from the AI subsystem.
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    $server = $this->getServerEntity();
    $server_id = $server ? $server->id() : NULL;

    // When $server_id is NULL this returns the union across all servers.
    return $this->buildModelListForOperation($server_id, $operation_type);
  }

  /**
   * Discovers models from the active server and persists them as entities.
   *
   * This is the write path: call it only from explicit user actions (saving a
   * server) or maintenance commands, never from a read/render path.
   *
   * @return array<string, string>
   *   Map of model entity id => raw model id for the active server. Falls back
   *   to the already-known models if the server is unreachable.
   */
  public function discoverModels(): array {
    $server = $this->getServerEntity();
    $server_id = $server ? $server->id() : NULL;
    if (!$server_id) {
      return [];
    }

    try {
      $this->loadClient();
      $response = $this->client->models()->list()->toArray();
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('ai_provider_llama_cpp')->error(
        'Failed to get models from server: @message',
        ['@message' => $e->getMessage()]
      );
      return $this->buildModelListForOperation($server_id, NULL);
    }

    $filter_pattern = $server->getModelFilter() ?: '';
    $discovered = [];

    foreach ($response['data'] ?? [] as $model_entry) {
      $raw_id = $model_entry['id'];
      if ($filter_pattern !== '' && !ModelFilter::matches($raw_id, $filter_pattern)) {
        continue;
      }
      $machine = $this->getMachineName($raw_id);
      $model_entity_id = $this->buildModelEntityId($server_id, $machine);

      $detected = $this->detectOperationTypes($model_entry);
      $discovered[$model_entity_id] = [
        'raw' => $raw_id,
        'detected' => $detected,
      ];
    }

    $this->persistModelsForServer($server_id, $discovered);

    return $this->buildModelListForOperation($server_id, NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    $this->setActiveServerForModel($model_id);
    try {
      $resolved = $this->getModel($model_id);
      return parent::chat($input, $resolved, $tags);
    }
    finally {
      $this->clearActiveServer();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function embeddings(string|EmbeddingsInput $input, string $model_id, array $tags = []): EmbeddingsOutput {
    $this->setActiveServerForModel($model_id);
    try {
      $resolved = $this->getModel($model_id);
      return parent::embeddings($input, $resolved, $tags);
    }
    finally {
      $this->clearActiveServer();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function speechToText(string|SpeechToTextInput $input, string $model_id, array $tags = []): SpeechToTextOutput {
    $this->setActiveServerForModel($model_id);
    try {
      $resolved = $this->getModel($model_id);
      return parent::speechToText($input, $resolved, $tags);
    }
    finally {
      $this->clearActiveServer();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function rerank(ReRankInput $input, string $model_id, array $tags = []): ReRankOutput {
    $this->setActiveServerForModel($model_id);
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

      $options = ['json' => $payload];
      if ($this->hasAuthentication()) {
        $options['headers'] = ['Authorization' => 'Bearer ' . $this->loadApiKey()];
      }

      $response = $this->httpRequest(
        'POST',
        rtrim($this->getBaseHost(), '/') . '/v1/rerank',
        $options,
        $timeout,
      );
      $data = json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (\Throwable $e) {
      $this->handleApiThrowable($e);
      throw $e;
    }

    $output = new ReRankOutput(
      $data['results'] ?? [],
      $data['id'] ?? '',
      $data,
    );
    $this->clearActiveServer();
    return $output;
  }

  /**
   * {@inheritdoc}
   */
  public function moderation(string|ModerationInput $input, ?string $model_id = NULL, array $tags = []): ModerationOutput {
    if ($model_id) {
      $this->setActiveServerForModel($model_id);
    }
    $this->loadClient();

    $prompt = $input instanceof ModerationInput ? $input->getPrompt() : $input;
    $raw_model_id = $this->getModel($model_id ?? '');

    $parser_class = $this->getModerationParser($raw_model_id);

    $is_shield = $parser_class === ShieldGemma::class
      || preg_match('/shield.?gemma/', strtolower($raw_model_id));

    // ShieldGemma's chat template requires a `guideline` variable, so a plain
    // chat.completions call fails with "'guideline' is undefined". Instead we
    // post a fully-built prompt to /v1/completions once per safety policy and
    // flag the content if any policy is violated.
    if ($is_shield) {
      // Resolve the request timeout the same way as the other custom paths.
      $timeout = 600;
      $server = $this->getServerEntity();
      if ($server) {
        $timeout = $server->getTimeout() ?: 600;
      }
      $timeout = $this->configuration['timeout'] ?? $timeout;

      $url = rtrim($this->getBaseHost(), '/') . '/v1/completions';
      $headers = [];
      if ($this->hasAuthentication()) {
        $headers['Authorization'] = 'Bearer ' . $this->loadApiKey();
      }

      $flagged = FALSE;
      $categories = [];
      $raw_outputs = [];
      foreach (ShieldGemma::getDefaultGuidelines() as $category => $guideline) {
        $payload = [
          'model'       => $raw_model_id,
          'prompt'      => ShieldGemma::buildPrompt($prompt, $guideline),
          'max_tokens'  => 4,
          'temperature' => 0.0,
        ];

        try {
          $options = ['json' => $payload];
          if ($headers) {
            $options['headers'] = $headers;
          }
          $http_response = $this->httpRequest('POST', $url, $options, $timeout);
          $response = json_decode($http_response->getBody()->getContents(), TRUE);
        }
        catch (\Throwable $e) {
          $this->handleApiThrowable($e);
          throw $e;
        }

        if (!is_array($response)) {
          throw new AiRequestErrorException('Invalid JSON response from completions endpoint.');
        }
        if (isset($response['error'])) {
          throw new AiRequestErrorException('Completions error from ShieldGemma: ' . json_encode($response['error']));
        }

        $text = trim($response['choices'][0]['text'] ?? '');
        $violated = ShieldGemma::responseIndicatesViolation($text);
        $categories[$category] = $violated;
        $raw_outputs[$category] = $text;
        $flagged = $flagged || $violated;
      }

      $moderation_response = new ModerationResponse($flagged, ['categories' => $categories]);
      $out = new ModerationOutput($moderation_response, $raw_outputs, ['categories' => $categories]);
      $this->clearActiveServer();
      return $out;
    }

    // Default path for LlamaGuard3 and unknown parsers: use chat completions.
    // (Their templates are typically satisfied by a plain user message or the
    // server is configured with an appropriate --chat-template.)
    $payload = [
      'model' => $raw_model_id,
      'messages' => [
        ['role' => 'user', 'content' => $prompt],
      ],
    ] + $this->configuration;

    try {
      $response = $this->client->chat()->create($payload)->toArray();
    }
    catch (\Throwable $e) {
      $this->handleApiThrowable($e);
      throw $e;
    }

    if (!isset($response['choices'][0]['message']['content'])) {
      throw new AiRequestErrorException('No content in moderation response.');
    }
    $message = $response['choices'][0]['message']['content'];

    if ($parser_class) {
      $moderation_response = $parser_class::parse($message);
    }
    else {
      $flagged = str_contains(strtolower($message), 'unsafe');
      $moderation_response = new ModerationResponse($flagged);
    }

    $out = new ModerationOutput($moderation_response, $message, $response);
    $this->clearActiveServer();
    return $out;
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
    // Fallback for ShieldGemma id variants (shield-gemma, shield_gemma).
    if (preg_match('/shield.?gemma/', $name)) {
      return ShieldGemma::class;
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function embeddingsVectorSize(string $model_id): int {
    $this->setActiveServerForModel($model_id);
    $this->loadClient();
    $raw_model_id = $this->getModel($model_id);
    try {
      $model_response = $this->client->models()->retrieve($raw_model_id);
      /** @var array<string, mixed> $data */
      $data = (array) $model_response->toArray();
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
    $this->clearActiveServer();
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
    if (preg_match('/stable.diffusion|stable[-_]diffusion|sdxl|flux|dall[-_]e|sd[-_]cascade|flux[-_]dev/', $name)) {
      return ['text_to_image'];
    }
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
      $response = $this->httpRequest('GET', "https://huggingface.co/api/models/{$repo}", [], 5);
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
   * Build the list of models (entity_id => raw) filtered for a given op type.
   *
   * If $server_id is NULL, includes models from all servers.
   */
  protected function buildModelListForOperation(?string $server_id, ?string $operation_type): array {
    $storage = $this->entityTypeManager->getStorage('llama_cpp_model');
    $query = $storage->getQuery();

    if ($server_id) {
      $query->condition('server_id', $server_id);
    }

    $ids = $query->accessCheck(FALSE)->execute();
    $models = $storage->loadMultiple($ids);

    $result = [];
    $this->models = [];

    /** @var \Drupal\ai_provider_llama_cpp\Entity\LlamaCppModelInterface $model */
    foreach ($models as $model) {
      $key = $model->id();
      $raw = $model->getRawModelId();
      $this->models[$key] = $raw;

      $effective = $model->getEffectiveOperationTypes();
      if ($operation_type === NULL || in_array($operation_type, $effective, TRUE)) {
        $result[$key] = $raw;
      }
    }

    return $result;
  }

  /**
   * Persist (create or update) model entities for a server after discovery.
   *
   * @param string $server_id
   *   The owning server entity id.
   * @param array<string, array{raw: string, detected: string[]}> $discovered
   *   Discovered models keyed by model entity id.
   */
  protected function persistModelsForServer(string $server_id, array $discovered): void {
    $storage = $this->entityTypeManager->getStorage('llama_cpp_model');

    // Load existing for this server to update / remove stale.
    $existing = $storage->loadByProperties(['server_id' => $server_id]);

    $seen_ids = [];

    foreach ($discovered as $entity_id => $info) {
      $seen_ids[$entity_id] = TRUE;

      /** @var \Drupal\ai_provider_llama_cpp\Entity\LlamaCppModelInterface|null $model */
      $model = $storage->load($entity_id);

      $raw = $info['raw'];
      $detected = $info['detected'];

      if (!$model) {
        $model = $storage->create([
          'id' => $entity_id,
          'label' => $raw,
          'server_id' => $server_id,
          'raw_model_id' => $raw,
        ]);
      }

      $model->setRawModelId($raw);
      $model->setServerId($server_id);
      $model->setDetectedOperationTypes($detected);

      // Make label more informative: "ServerLabel / raw" when possible.
      $server_label = '';
      $srv = $this->entityTypeManager->getStorage('llama_cpp_server')->load($server_id);
      if ($srv) {
        $server_label = $srv->label();
      }
      $nice_label = $server_label ? ($server_label . ' / ' . $raw) : $raw;

      // Only overwrite label if it was previously simple.
      if (empty($model->label()) || $model->label() === $model->getRawModelId() || str_ends_with($model->label(), ' / ' . $raw)) {
        $model->set('label', $nice_label);
      }

      $model->save();
    }

    // Remove models that disappeared from the server.
    foreach ($existing as $old_id => $old_model) {
      if (!isset($seen_ids[$old_id])) {
        $old_model->delete();
      }
    }
  }

  /**
   * Generate a stable, valid config entity id for a model on a server.
   *
   * The id is "server__machine". Both parts are already restricted to
   * [a-z0-9_] by their machine-name handling, so the only remaining risks are
   * an empty machine name and excessive length (config object names are capped
   * at 250 chars). We guard both, disambiguating any truncation with a short
   * deterministic hash so the same (server, raw model) always yields the same
   * id.
   */
  protected function buildModelEntityId(string $server_id, string $machine_name): string {
    if ($machine_name === '') {
      $machine_name = 'model';
    }
    $id = $server_id . '__' . $machine_name;

    // Leave generous headroom under the 250-char config name limit (the
    // "ai_provider_llama_cpp.model." prefix already consumes ~28 chars).
    $max = 160;
    if (strlen($id) > $max) {
      $suffix = '_' . substr(hash('sha256', $id), 0, 8);
      $id = substr($id, 0, $max - strlen($suffix)) . $suffix;
    }
    return $id;
  }

  /**
   * Gets the raw model identifier from the stored mapping (or model entities).
   *
   * The $model_id here is the key used by the AI system (llama_cpp_model id).
   */
  protected function getModel(string $model_id): string {
    if (empty($this->models)) {
      // Populate from the current server context, or load the specific model.
      $server = $this->getServerEntity();
      if ($server) {
        $this->models = $this->buildModelListForOperation($server->id(), NULL);
      }
      else {
        // Try direct load of the model entity.
        $model = $this->entityTypeManager
          ->getStorage('llama_cpp_model')
          ->load($model_id);
        if ($model instanceof LlamaCppModelInterface) {
          return $model->getRawModelId();
        }
      }
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
    $machine = (string) preg_replace('@[^a-z0-9_]+@', '_', $transliterated);
    // Collapse runs of underscores and trim them so ids stay clean and stable
    // for raw model names containing slashes, dots or repeated separators
    // (e.g. "Qwen/Qwen2.5-7B-Instruct" => "qwen_qwen2_5_7b_instruct").
    $machine = (string) preg_replace('@_+@', '_', $machine);
    return trim($machine, '_');
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
      $host = rtrim((string) ($this->configuration['host_name'] ?? $this->getConfig()->get('host_name') ?? ''), '/');
      $port = $this->configuration['port'] ?? $this->getConfig()->get('port') ?? '';
    }
    if ($port) {
      $host .= ':' . $port;
    }
    return $host;
  }

  /**
   * {@inheritdoc}
   */
  public function textToImage(string|TextToImageInput $input, string $model_id, array $tags = []): TextToImageOutput {
    $this->setActiveServerForModel($model_id);
    try {
      $resolved = $this->getModel($model_id);
      return parent::textToImage($input, $resolved, $tags);
    }
    finally {
      $this->clearActiveServer();
    }
  }

  /**
   * Performs an HTTP request using Drupal's HTTP client factory.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $uri
   *   The request URI.
   * @param array $options
   *   Guzzle request options.
   * @param int $timeout
   *   Request timeout in seconds.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The HTTP response.
   */
  protected function httpRequest(string $method, string $uri, array $options = [], int $timeout = 60): ResponseInterface {
    $client = $this->httpClientFactory->fromOptions(['timeout' => $timeout]);
    return $client->request($method, $uri, $options);
  }

}
