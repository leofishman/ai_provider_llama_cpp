<?php

namespace Drupal\ai_provider_llama_cpp\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai_provider_llama_cpp\Plugin\AiProvider\LlamaCppProvider;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for adding and editing llama_cpp_server entities.
 */
class LlamaCppServerForm extends EntityForm {

  /**
   * Operation type labels for override checkboxes.
   */
  const OPERATION_TYPE_LABELS = [
    'chat'           => 'Chat',
    'embeddings'     => 'Embeddings',
    'speech_to_text' => 'Speech to Text',
    'rerank'         => 'Rerank',
    'moderation'     => 'Moderation',
    'text_to_image'  => 'Text to Image',
  ];

  /**
   * Constructs the form.
   */
  public function __construct(
    protected AiProviderPluginManager $aiProviderManager,
    protected KeyRepositoryInterface $keyRepository,
    protected ClientFactory $httpClientFactory,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    // Assign to the (untyped) property inherited from EntityForm instead of
    // promoting a natively-typed override.
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai.provider'),
      $container->get('key.repository'),
      $container->get('http_client_factory'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\ai_provider_llama_cpp\Entity\LlamaCppServerInterface $server */
    $server = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Server name'),
      '#description' => $this->t('A human-readable name for this server (e.g. "Ollama Local", "GPU Chat Server").'),
      '#maxlength' => 255,
      '#default_value' => $server->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $server->id(),
      '#machine_name' => [
        'exists' => '\Drupal\ai_provider_llama_cpp\Entity\LlamaCppServer::load',
      ],
      '#disabled' => !$server->isNew(),
    ];

    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Connection'),
      '#open' => TRUE,
    ];

    $form['connection']['host_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Host Name'),
      '#description' => $this->t('The host name including protocol. For DDEV use http://host.docker.internal.'),
      '#required' => TRUE,
      '#default_value' => $server->getHostName(),
      '#attributes' => ['placeholder' => 'http://127.0.0.1'],
    ];

    $form['connection']['port'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Port'),
      '#description' => $this->t('Port number. Leave empty for default. llama.cpp default is 8080, Ollama is 11434.'),
      '#default_value' => $server->getPort() ?: '8080',
      '#attributes' => ['placeholder' => '8080'],
    ];

    $form['connection']['api_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('API Key'),
      '#description' => $this->t('Optional. Select a Key for authenticated servers (e.g. vLLM, LiteLLM). Leave empty for local llama.cpp servers without authentication.'),
      '#default_value' => $server->getApiKey(),
    ];

    $form['connection']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout (seconds)'),
      '#description' => $this->t('Request timeout in seconds. Increase for slow models or large inputs.'),
      '#default_value' => $server->getTimeout() ?: 600,
      '#min' => 5,
      '#max' => 3600,
    ];

    $form['filtering'] = [
      '#type' => 'details',
      '#title' => $this->t('Model Filtering'),
      '#open' => TRUE,
    ];

    $form['filtering']['model_filter'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model filter pattern'),
      '#description' => $this->t('Comma-separated list of allowed models (wildcards * supported). Examples: <code>llama3*, *mistral*, !*old*</code>. Leave empty to allow all models.'),
      '#default_value' => $server->getModelFilter(),
      '#attributes' => ['placeholder' => 'llama3*, *mistral*, !*old*'],
      '#parents' => ['model_filter'],
    ];

    $form['operation_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Operation types'),
      '#description' => $this->t('Select which operation types this server supports. Leave all unchecked for auto-detection.'),
      '#options' => array_map([$this, 't'], self::OPERATION_TYPE_LABELS),
      '#default_value' => $server->getOperationTypes(),
    ];

    // Model capability overrides (edit only, when models are known).
    if (!$server->isNew()) {
      $form['overrides'] = $this->buildOverridesForm($server);
    }

    return $form;

  }

  /**
   * Builds the model capability overrides fieldset.
   *
   * Now reads from llama_cpp_model config entities (instead of State).
   */
  protected function buildOverridesForm($server): array {
    $server_id = $server->id();

    $element = [
      '#type'  => 'details',
      '#title' => $this->t('Model capability overrides'),
      '#description' => $this->t(
        'Capabilities are auto-detected from server metadata and HuggingFace. Use these overrides for models whose type cannot be auto-detected. Leave all checkboxes unchecked to use auto-detection.'
      ),
      '#open' => FALSE,
    ];

    $model_storage = $this->entityTypeManager->getStorage('llama_cpp_model');
    $models = $model_storage->loadByProperties(['server_id' => $server_id]);

    if (empty($models)) {
      $element['empty'] = [
        '#markup' => $this->t('<p>No models found yet. Save the server first, then models will be discovered automatically.</p>'),
      ];
      return $element;
    }

    $type_options = array_map([$this, 't'], self::OPERATION_TYPE_LABELS);

    /** @var \Drupal\ai_provider_llama_cpp\Entity\LlamaCppModelInterface $model */
    foreach ($models as $model) {
      $raw_id = $model->getRawModelId();
      $auto_types = $model->getDetectedOperationTypes() ?: ['chat'];
      $auto_label = implode(', ', $auto_types);
      $current_overrides = $model->getOperationTypes();

      // Use model entity id as form key (stable).
      $key = $model->id();

      $element[$key] = [
        '#type'          => 'checkboxes',
        '#title'         => $raw_id,
        '#description'   => $this->t('Auto-detected: <em>@types</em>', ['@types' => $auto_label]),
        '#options'       => $type_options,
        '#default_value' => $current_overrides,
      ];
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $host = rtrim((string) $form_state->getValue('host_name'), '/');
    $port = $form_state->getValue('port');
    if ($port) {
      $host .= ':' . $port;
    }

    try {
      $options = [
        'connect_timeout' => 5,
      ];

      $key_id = $form_state->getValue('api_key');
      if ($key_id) {
        $api_key = $this->keyRepository->getKey($key_id)?->getKeyValue();
        if ($api_key) {
          $options['headers'] = ['Authorization' => 'Bearer ' . $api_key];
        }
      }

      $client = $this->httpClientFactory->fromOptions(['timeout' => 10]);
      $client->request('GET', rtrim($host, '/') . '/v1/models', $options);
    }
    catch (\Exception) {
      $form_state->setErrorByName('host_name', $this->t('Could not connect to the server. Check the host, port, and API key.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\ai_provider_llama_cpp\Entity\LlamaCppServerInterface $server */
    $server = $this->entity;

    // Clean operation_types: keep only checked values.
    $raw_types = $form_state->getValue('operation_types', []);
    $server->set('operation_types', array_values(array_filter($raw_types)));

    $status = $server->save();

    // Discover models (write path) so the edit form and AI settings can use
    // them. getConfiguredModels() is read-only; persisting model entities is an
    // explicit action triggered here on save.
    try {
      $provider = $this->aiProviderManager->createInstance('llama_cpp', ['server_id' => $server->id()]);
      if ($provider instanceof LlamaCppProvider) {
        $provider->discoverModels();
      }
    }
    catch (\Throwable) {
      // Connectivity is validated in validateForm(); discovery may still fail
      // if the server is temporarily unreachable after save.
    }

    // Save model overrides to State if present.
    if (!$server->isNew()) {
      $this->saveModelOverrides($form_state, $server->id());
    }

    $this->messenger()->addMessage($this->t('Server %label has been @action.', [
      '%label' => $server->label(),
      '@action' => $status === SAVED_NEW ? $this->t('created') : $this->t('updated'),
    ]));

    $form_state->setRedirectUrl($server->toUrl('collection'));

    return $status;
  }

  /**
   * Persists manual model capability overrides to llama_cpp_model entities.
   */
  protected function saveModelOverrides(FormStateInterface $form_state, string $server_id): void {
    $model_storage = $this->entityTypeManager->getStorage('llama_cpp_model');
    $models = $model_storage->loadByProperties(['server_id' => $server_id]);

    /** @var \Drupal\ai_provider_llama_cpp\Entity\LlamaCppModelInterface $model */
    foreach ($models as $model) {
      $key = $model->id();
      $raw_values = $form_state->getValue(['overrides', $key], []);
      $selected = array_values(array_filter($raw_values));
      $model->setOperationTypes($selected);
      $model->save();
    }
  }

}
