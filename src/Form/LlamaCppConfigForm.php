<?php

namespace Drupal\ai_provider_llama_cpp\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Service\AiProviderFormHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure llama.cpp provider API access.
 */
class LlamaCppConfigForm extends ConfigFormBase {

  /**
   * Config name.
   */
  const CONFIG_NAME = 'ai_provider_llama_cpp.settings';

  /**
   * Operation type labels for the override checkboxes.
   */
  const OPERATION_TYPE_LABELS = [
    'chat'           => 'Chat',
    'embeddings'     => 'Embeddings',
    'speech_to_text' => 'Speech to Text',
    'rerank'         => 'Rerank',
  ];

  /**
   * Constructs a new config form.
   */
  final public function __construct(
    protected AiProviderPluginManager $aiProviderManager,
    protected AiProviderFormHelper $formHelper,
    protected StateInterface $state,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  final public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai.provider'),
      $container->get('ai.form_helper'),
      $container->get('state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'llama_cpp_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [static::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(static::CONFIG_NAME);

    $form['host_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Host Name'),
      '#description' => $this->t('The host name for the API, including protocol. For DDEV use http://host.docker.internal.'),
      '#required' => TRUE,
      '#default_value' => $config->get('host_name'),
      '#attributes' => [
        'placeholder' => 'http://127.0.0.1',
      ],
    ];

    $form['port'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Port'),
      '#description' => $this->t('The port number for the API. Can be left empty for default ports. The llama.cpp default port is 8080.'),
      '#default_value' => $config->get('port') ?? '8080',
      '#attributes' => [
        'placeholder' => '8080',
      ],
    ];

    $provider = $this->aiProviderManager->createInstance('llama_cpp');
    $form['models'] = $this->formHelper->getModelsTable($form, $form_state, $provider);

    $form['overrides'] = $this->buildOverridesForm($provider);

    return parent::buildForm($form, $form_state);
  }

  /**
   * Builds the model capability overrides fieldset.
   *
   * @param \Drupal\ai\AiProviderInterface $provider
   *   The llama.cpp provider instance.
   *
   * @return array
   *   The form element.
   */
  protected function buildOverridesForm($provider): array {
    $element = [
      '#type'        => 'details',
      '#title'       => $this->t('Model capability overrides'),
      '#description' => $this->t(
        'Capabilities are auto-detected from server metadata and HuggingFace. Use these overrides for models whose type cannot be auto-detected, or to assign multiple types to a single model. Leave all checkboxes unchecked to use auto-detection.'
      ),
      '#open'        => FALSE,
    ];

    try {
      $all_models = $provider->getConfiguredModels();
    }
    catch (\Throwable) {
      $all_models = $this->state->get('ai_provider_llama_cpp.models', []);
    }

    if (empty($all_models)) {
      $element['empty'] = [
        '#markup' => $this->t('<p>No models found. Configure the host and port above and save first.</p>'),
      ];
      return $element;
    }

    $overrides = $this->state->get('ai_provider_llama_cpp.model_overrides', []);
    $detected = $this->state->get('ai_provider_llama_cpp.model_types', []);
    $type_options = [
      'chat'           => $this->t('Chat'),
      'embeddings'     => $this->t('Embeddings'),
      'speech_to_text' => $this->t('Speech to Text'),
      'rerank'         => $this->t('Rerank'),
    ];

    foreach ($all_models as $machine_id => $raw_id) {
      $auto_types = $detected[$machine_id] ?? ['chat'];
      $auto_label = implode(', ', $auto_types);

      $element[$machine_id] = [
        '#type'          => 'checkboxes',
        '#title'         => $raw_id,
        '#description'   => $this->t('Auto-detected: <em>@types</em>', ['@types' => $auto_label]),
        '#options'       => $type_options,
        '#default_value' => $overrides[$machine_id] ?? [],
      ];
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $provider = $this->aiProviderManager->createInstance('llama_cpp');

    $provider->setConfiguration([
      'host_name' => $form_state->getValue('host_name'),
      'port'      => $form_state->getValue('port'),
    ]);

    try {
      $provider->testConnection();
    }
    catch (\Exception) {
      $form_state->setErrorByName('host_name', $this->t('Could not connect to the host. Please check the host name and port.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config(static::CONFIG_NAME)
      ->set('host_name', $form_state->getValue('host_name'))
      ->set('port', $form_state->getValue('port'))
      ->save();

    $this->saveModelOverrides($form_state);

    parent::submitForm($form, $form_state);
  }

  /**
   * Persists manual model capability overrides to State.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  protected function saveModelOverrides(FormStateInterface $form_state): void {
    $all_models = $this->state->get('ai_provider_llama_cpp.models', []);
    $overrides = [];

    foreach (array_keys($all_models) as $machine_id) {
      $raw_values = $form_state->getValue(['overrides', $machine_id], []);
      $selected = array_values(array_filter($raw_values));
      if (!empty($selected)) {
        $overrides[$machine_id] = $selected;
      }
    }

    $this->state->set('ai_provider_llama_cpp.model_overrides', $overrides);
  }

}
