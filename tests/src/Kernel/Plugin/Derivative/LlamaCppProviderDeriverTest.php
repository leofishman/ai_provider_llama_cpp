<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_llama_cpp\Kernel\Plugin\Derivative;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the llama.cpp provider deriver.
 *
 * @group ai_provider_llama_cpp
 */
#[RunTestsInSeparateProcesses]
final class LlamaCppProviderDeriverTest extends KernelTestBase {

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
   * Tests one plugin derivative is created per server entity.
   */
  public function testDerivativesMatchServerEntities(): void {
    $server_storage = $this->container->get('entity_type.manager')
      ->getStorage('llama_cpp_server');

    $server_storage->create([
      'id' => 'local',
      'label' => 'Local llama.cpp',
      'host_name' => 'http://127.0.0.1',
      'port' => '8080',
      'api_key' => '',
      'timeout' => 600,
      'operation_types' => [],
      'model_filter' => '',
    ])->save();

    $server_storage->create([
      'id' => 'gpu',
      'label' => 'GPU Server',
      'host_name' => 'http://gpu.local',
      'port' => '11434',
      'api_key' => '',
      'timeout' => 600,
      'operation_types' => [],
      'model_filter' => '',
    ])->save();

    $plugin_manager = $this->container->get('ai.provider');
    $plugin_manager->clearCachedDefinitions();
    $definitions = $plugin_manager->getDefinitions();

    $this->assertArrayHasKey('llama_cpp:local', $definitions);
    $this->assertArrayHasKey('llama_cpp:gpu', $definitions);
    $this->assertSame('Local llama.cpp', (string) $definitions['llama_cpp:local']['label']);
    $this->assertSame('GPU Server', (string) $definitions['llama_cpp:gpu']['label']);
  }

}
