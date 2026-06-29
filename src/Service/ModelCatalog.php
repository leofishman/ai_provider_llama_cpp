<?php

namespace Drupal\ai_provider_llama_cpp\Service;

use Drupal\ai_provider_llama_cpp\Entity\LlamaCppModelInterface;
use Drupal\ai_provider_llama_cpp\Entity\LlamaCppServerInterface;
use Drupal\ai_provider_llama_cpp\Utility\ModelFilter;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\State\StateInterface;
use Drupal\key\KeyRepositoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Handles model discovery, persistence and catalog queries for llama.cpp servers.
 *
 * This service centralizes logic that was previously inside LlamaCppProvider,
 * making the provider smaller and the catalog logic reusable and testable.
 */
class ModelCatalog {

  /**
   * Map from HuggingFace pipeline_tag to operation type.
   */
  protected const HF_TAG_MAP = [
    'text-generation'               => 'chat',
    'text2text-generation'          => 'chat',
    'feature-extraction'            => 'embeddings',
    'sentence-similarity'           => 'embeddings',
    'automatic-speech-recognition'  => 'speech_to_text',
    'text-to-speech'                => 'text_to_speech',
    'text-to-image'                 => 'text_to_image',
    'text-ranking'                  => 'rerank',
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StateInterface $state,
    protected ClientFactory $httpClientFactory,
    protected TransliterationInterface $transliteration,
    protected ?KeyRepositoryInterface $keyRepository = null,
  ) {}

  /**
   * Discovers models from a server and persists them as llama_cpp_model entities.
   *
   * @param \OpenAI\Client|null $client
   *   Optional pre-configured OpenAI client (recommended, so auth + timeout are
   *   handled by the caller exactly as in normal operation).
   *
   * @return array<string, string>
   *   Map of model entity id => raw model id.
   */
  public function discoverModels(LlamaCppServerInterface $server, ?\OpenAI\Client $client = null): array {
    $serverId = $server->id();

    if ($client === null) {
      $client = $this->createOpenAiClient($server);
    }

    $response = $client->models()->list()->toArray();

    $filterPattern = $server->getModelFilter() ?: '';
    $discovered = [];

    foreach ($response['data'] ?? [] as $modelEntry) {
      $rawId = $modelEntry['id'];
      if ($filterPattern !== '' && !ModelFilter::matches($rawId, $filterPattern)) {
        continue;
      }
      $machine = $this->getMachineName($rawId);
      $entityId = $this->buildModelEntityId($serverId, $machine);
      $detected = $this->detectOperationTypes($modelEntry);

      $discovered[$entityId] = [
        'raw' => $rawId,
        'detected' => $detected,
      ];
    }

    $this->persistModelsForServer($serverId, $discovered);

    return $this->getModelsForServer($serverId);
  }

  /**
   * Returns configured models for a server (or all if no serverId).
   */
  public function getModelsForServer(?string $serverId = NULL, ?string $operationType = NULL): array {
    $storage = $this->entityTypeManager->getStorage('llama_cpp_model');
    $query = $storage->getQuery();

    if ($serverId) {
      $query->condition('server_id', $serverId);
    }

    $ids = $query->accessCheck(FALSE)->execute();
    $models = $storage->loadMultiple($ids);

    $result = [];

    /** @var \Drupal\ai_provider_llama_cpp\Entity\LlamaCppModelInterface $model */
    foreach ($models as $model) {
      $key = $model->id();
      $raw = $model->getRawModelId();

      $effective = $model->getEffectiveOperationTypes();
      if ($operationType === NULL || in_array($operationType, $effective, TRUE)) {
        $result[$key] = $raw;
      }
    }

    return $result;
  }

  /**
   * Persists discovered models and cleans up removed ones.
   */
  protected function persistModelsForServer(string $serverId, array $discovered): void {
    $storage = $this->entityTypeManager->getStorage('llama_cpp_model');
    $existing = $storage->loadByProperties(['server_id' => $serverId]);
    $seen = [];

    foreach ($discovered as $entityId => $info) {
      $seen[$entityId] = TRUE;

      /** @var \Drupal\ai_provider_llama_cpp\Entity\LlamaCppModelInterface $model */
      $model = $storage->load($entityId) ?: $storage->create(['id' => $entityId]);

      $raw = $info['raw'];
      $detected = $info['detected'];

      $model->setRawModelId($raw);
      $model->setServerId($serverId);
      $model->setDetectedOperationTypes($detected);

      $server = $this->entityTypeManager->getStorage('llama_cpp_server')->load($serverId);
      $serverLabel = $server?->label() ?? '';
      $niceLabel = $serverLabel ? ($serverLabel . ' / ' . $raw) : $raw;

      if (empty($model->label()) || $model->label() === $raw || str_ends_with($model->label(), ' / ' . $raw)) {
        $model->set('label', $niceLabel);
      }

      $model->save();
    }

    foreach ($existing as $oldId => $oldModel) {
      if (!isset($seen[$oldId])) {
        $oldModel->delete();
      }
    }
  }

  /**
   * Builds a stable entity ID "server__machine".
   */
  public function buildModelEntityId(string $serverId, string $machineName): string {
    $cleanServer = $this->sanitizeForId($serverId);
    $cleanMachine = $this->sanitizeForId($machineName);

    if ($cleanMachine === '') {
      $cleanMachine = 'model';
    }
    if ($cleanServer === '') {
      $cleanServer = 'server';
    }

    $id = $cleanServer . '__' . $cleanMachine;

    $max = 160;
    if (strlen($id) > $max) {
      $suffix = '_' . substr(hash('sha256', $id), 0, 8);
      $id = substr($id, 0, $max - strlen($suffix)) . $suffix;
    }
    return $id;
  }

  protected function sanitizeForId(string $value): string {
    $value = preg_replace('@[^a-z0-9_]+@', '_', mb_strtolower($value));
    return trim(preg_replace('@_+@', '_', $value), '_');
  }

  /**
   * Generates a machine name.
   */
  public function getMachineName(string $string): string {
    $trans = $this->transliteration->transliterate($string, LanguageInterface::LANGCODE_DEFAULT, '_');
    $trans = mb_strtolower($trans);
    $machine = (string) preg_replace('@[^a-z0-9_]+@', '_', $trans);
    return trim((string) preg_replace('@_+@', '_', $machine), '_');
  }

  /**
   * Detects supported operation types for a model.
   */
  public function detectOperationTypes(array $model): array {
    $args = $model['status']['args'] ?? [];

    if (in_array('--embeddings', $args, TRUE)) {
      return ['embeddings'];
    }
    if (in_array('--reranking', $args, TRUE)) {
      return ['rerank'];
    }

    $hfRepo = $this->extractHfRepo($args);
    if ($hfRepo) {
      $tag = $this->getHfPipelineTag($hfRepo);
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

  protected function extractHfRepo(array $args): ?string {
    $index = array_search('--hf-repo', $args, TRUE);
    if ($index === FALSE || !isset($args[$index + 1])) {
      return NULL;
    }
    return explode(':', $args[$index + 1])[0];
  }

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
   * Creates a minimal OpenAI client for discovery.
   */
  protected function createOpenAiClient(LlamaCppServerInterface $server): \OpenAI\Client {
    $host = rtrim($server->getHostName(), '/');
    if ($port = $server->getPort()) {
      $host .= ':' . $port;
    }

    $factory = \OpenAI::factory();

    $keyId = $server->getApiKey();
    if ($keyId && $this->keyRepository) {
      $keyValue = $this->keyRepository->getKey($keyId)?->getKeyValue();
      if ($keyValue) {
        $factory = $factory->withApiKey($keyValue);
      }
    }
    // If no key or no repository, proceed without (some servers don't require it).

    return $factory->withHttpClient(
      $this->httpClientFactory->fromOptions(['timeout' => $server->getTimeout() ?: 600])
    )->withBaseUri($host . '/v1')->make();
  }

  /**
   * Simple HTTP request helper (used for HF API).
   */
  protected function httpRequest(string $method, string $uri, array $options = [], int $timeout = 60): ResponseInterface {
    $client = $this->httpClientFactory->fromOptions(['timeout' => $timeout]);
    return $client->request($method, $uri, $options);
  }

}
