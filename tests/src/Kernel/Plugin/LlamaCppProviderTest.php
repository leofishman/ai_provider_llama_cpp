<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_llama_cpp\Kernel\Plugin;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the llama.cpp provider is a single (non-derived) plugin and that
 * model config entities are used instead of State + derivatives.
 *
 * @group ai_provider_llama_cpp
 */
#[RunTestsInSeparateProcesses]
final class LlamaCppProviderTest extends KernelTestBase {

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
   * Tests that only the base 'llama_cpp' plugin exists (no derivatives).
   */
  public function testSingleNonDerivedProviderPlugin(): void {
    $plugin_manager = $this->container->get('ai.provider');
    $plugin_manager->clearCachedDefinitions();
    $definitions = $plugin_manager->getDefinitions();

    $this->assertArrayHasKey('llama_cpp', $definitions);
    $this->assertArrayNotHasKey('llama_cpp:local', $definitions);
    $this->assertArrayNotHasKey('llama_cpp:gpu', $definitions);
    $this->assertSame('llama.cpp (OpenAI-compatible)', (string) $definitions['llama_cpp']['label']);
  }

  /**
   * Tests that llama_cpp_model entity type is available and models are stored
   * as config entities (after a server creation + simulated discovery).
   */
  public function testModelConfigEntities(): void {
    $etm = $this->container->get('entity_type.manager');
    $server_storage = $etm->getStorage('llama_cpp_server');
    $model_storage = $etm->getStorage('llama_cpp_model');

    $server = $server_storage->create([
      'id' => 'testserver',
      'label' => 'Test Server',
      'host_name' => 'http://127.0.0.1',
      'port' => '8080',
      'api_key' => '',
      'timeout' => 600,
      'operation_types' => [],
      'model_filter' => '',
    ]);
    $server->save();

    // Simulate what discovery does: create model entities directly.
    $model_storage->create([
      'id' => 'testserver__llama3',
      'label' => 'llama3',
      'server_id' => 'testserver',
      'raw_model_id' => 'llama3',
      'detected_operation_types' => ['chat'],
      'operation_types' => [],
    ])->save();

    $models = $model_storage->loadByProperties(['server_id' => 'testserver']);
    $this->assertCount(1, $models);

    /** @var \Drupal\ai_provider_llama_cpp\Entity\LlamaCppModelInterface $m */
    $m = reset($models);
    $this->assertSame('llama3', $m->getRawModelId());
    $this->assertSame(['chat'], $m->getEffectiveOperationTypes());

    // Apply override.
    $m->setOperationTypes(['embeddings']);
    $m->save();

    $reloaded = $model_storage->load('testserver__llama3');
    $this->assertSame(['embeddings'], $reloaded->getEffectiveOperationTypes());
  }

}
