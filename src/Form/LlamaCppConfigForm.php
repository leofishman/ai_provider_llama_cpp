<?php

namespace Drupal\ai_provider_llama_cpp\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Service\AiProviderFormHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure llama.cpp provider API access.
 */
class LlamaCppConfigForm extends ConfigFormBase {

  /**
   * Constructs a new config form.
   */
  final public function __construct(
    protected AiProviderPluginManager $aiProviderManager,
    protected AiProviderFormHelper $formHelper,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  final public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai.provider'),
      $container->get('ai.form_helper')
    );
  }

  /**
   * Config name.
   */
  const CONFIG_NAME = 'ai_provider_llama_cpp.settings';

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

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $provider = $this->aiProviderManager->createInstance('llama_cpp');

    // Temporarily set the form values for validation.
    $provider->setConfiguration([
      'host_name' => $form_state->getValue('host_name'),
      'port' => $form_state->getValue('port'),
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

    parent::submitForm($form, $form_state);
  }

}
