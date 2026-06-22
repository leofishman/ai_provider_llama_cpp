<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_llama_cpp\Kernel\Update;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests update 10202 plaintext API key migration to Key entities.
 *
 * @group ai_provider_llama_cpp
 */
#[RunTestsInSeparateProcesses]
final class Update10202Test extends KernelTestBase {

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
   * Tests plaintext API keys are migrated to Key module entities.
   */
  public function testUpdate10202MigratesPlaintextApiKeys(): void {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $server_storage = $entity_type_manager->getStorage('llama_cpp_server');
    $key_storage = $entity_type_manager->getStorage('key');

    $server_storage->create([
      'id' => 'secure',
      'label' => 'Secure Server',
      'host_name' => 'http://api.example.com',
      'port' => '8080',
      'api_key' => 'sk-plaintext-secret',
      'timeout' => 600,
      'operation_types' => [],
      'model_filter' => '',
    ])->save();

    ai_provider_llama_cpp_update_10202();

    /** @var \Drupal\ai_provider_llama_cpp\Entity\LlamaCppServerInterface $server */
    $server = $server_storage->load('secure');
    $this->assertSame('llama_cpp_secure', $server->getApiKey());

    /** @var \Drupal\key\KeyInterface $key */
    $key = $key_storage->load('llama_cpp_secure');
    $this->assertNotNull($key);
    $this->assertSame('sk-plaintext-secret', $key->getKeyValue());
  }

  /**
   * Tests servers without API keys are unchanged.
   */
  public function testUpdate10202SkipsEmptyApiKeys(): void {
    $server_storage = $this->container->get('entity_type.manager')
      ->getStorage('llama_cpp_server');

    $server_storage->create([
      'id' => 'local',
      'label' => 'Local Server',
      'host_name' => 'http://127.0.0.1',
      'port' => '8080',
      'api_key' => '',
      'timeout' => 600,
      'operation_types' => [],
      'model_filter' => '',
    ])->save();

    ai_provider_llama_cpp_update_10202();

    /** @var \Drupal\ai_provider_llama_cpp\Entity\LlamaCppServerInterface $server */
    $server = $server_storage->load('local');
    $this->assertSame('', $server->getApiKey());
  }

}
