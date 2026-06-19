<?php

namespace Drupal\ai_provider_llama_cpp\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for deleting a llama_cpp_server entity.
 */
class LlamaCppServerDeleteForm extends EntityConfirmFormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    protected StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete server %label?', [
      '%label' => $this->entity->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This will remove the server configuration and all cached model data. Models served by this server will no longer be available as an AI provider. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('entity.llama_cpp_server.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $server_id = $this->entity->id();

    // Clean up State data for this server.
    $prefix = "ai_provider_llama_cpp.server.{$server_id}";
    $this->state->delete("{$prefix}.models");
    $this->state->delete("{$prefix}.model_types");
    $this->state->delete("{$prefix}.model_overrides");

    $this->entity->delete();

    $this->messenger()->addMessage($this->t('Server %label has been deleted.', [
      '%label' => $this->entity->label(),
    ]));

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
