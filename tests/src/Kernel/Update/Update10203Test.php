<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_llama_cpp\Kernel\Update;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

/**
 * Tests update 10203 legacy derived plugin and state-model migration.
 *
 * @group ai_provider_llama_cpp
 */
#[Group('ai_provider_llama_cpp')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
final class Update10203Test extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'ai',
    'ai_provider_llama_cpp',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_handler')
      ->loadInclude('ai_provider_llama_cpp', 'install');
  }

  /**
   * Tests migration from derived plugins and State to config entities.
   */
  public function testUpdate10203MigratesDerivativesAndState(): void {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $server_storage = $entity_type_manager->getStorage('llama_cpp_server');
    $model_storage = $entity_type_manager->getStorage('llama_cpp_model');

    // Create a mock server entity.
    $server_storage->create([
      'id' => 'gpu',
      'label' => 'GPU Server',
      'host_name' => 'http://gpu.example.com',
      'port' => '8080',
      'api_key' => '',
      'timeout' => 600,
      'operation_types' => [],
      'model_filter' => '',
    ])->save();

    // Populate the old legacy State keys as if they were set on 1.x.
    $state = $this->container->get('state');
    $state->set('ai_provider_llama_cpp.server.gpu.models', [
      'llama3' => 'llama3-8b',
    ]);
    $state->set('ai_provider_llama_cpp.server.gpu.model_types', [
      'llama3' => ['chat'],
    ]);
    $state->set('ai_provider_llama_cpp.server.gpu.model_overrides', [
      'llama3' => ['chat', 'embeddings'],
    ]);

    // Set default provider configuration referencing the old derived plugin ID.
    // 'chat' has a model that migrates to an entity (mappable); 'embeddings'
    // points at a model with no State (unmappable), exercising both paths.
    $this->config('ai.settings')
      ->set('default_providers', [
        'chat' => [
          'provider_id' => 'llama_cpp:gpu',
          'model_id' => 'llama3',
        ],
        'embeddings' => [
          'provider_id' => 'llama_cpp:gpu',
          'model_id' => 'ghost',
        ],
      ])
      ->save(TRUE);

    // Verify that the model entity does not exist yet.
    $this->assertNull($model_storage->load('gpu__llama3'));

    // Execute the update hook.
    ai_provider_llama_cpp_update_10203();

    // Assert the AI default provider was collapsed to the non-derived ID
    // and the model_id remapped to the new entity id.
    $defaults = $this->config('ai.settings')->get('default_providers');
    $this->assertSame('llama_cpp', $defaults['chat']['provider_id']);
    $this->assertSame('gpu__llama3', $defaults['chat']['model_id']);

    // Unmappable model: provider_id still collapsed, model_id left untouched so
    // the admin can re-select it.
    $this->assertSame('llama_cpp', $defaults['embeddings']['provider_id']);
    $this->assertSame('ghost', $defaults['embeddings']['model_id']);

    // Assert that the model config entity has been created.
    /** @var \Drupal\ai_provider_llama_cpp\Entity\LlamaCppModelInterface $model */
    $model = $model_storage->load('gpu__llama3');
    $this->assertNotNull($model);
    $this->assertSame('gpu', $model->getServerId());
    $this->assertSame('llama3-8b', $model->getRawModelId());
    $this->assertSame(['chat'], $model->getDetectedOperationTypes());
    $this->assertSame(['chat', 'embeddings'], $model->getOperationTypes());
    $this->assertSame('GPU Server / llama3-8b', $model->label());
  }

}
