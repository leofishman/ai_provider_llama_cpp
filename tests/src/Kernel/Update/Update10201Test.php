<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_llama_cpp\Kernel\Update;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests update 10201 legacy config migration.
 *
 * @group ai_provider_llama_cpp
 */
#[RunTestsInSeparateProcesses]
final class Update10201Test extends KernelTestBase {

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
   * Tests migration from single-server settings to config entities.
   */
  public function testUpdate10201MigratesLegacyConfig(): void {
    $this->config('ai_provider_llama_cpp.settings')
      ->set('host_name', 'http://127.0.0.1')
      ->set('port', '9000')
      ->save(TRUE);

    $state = $this->container->get('state');
    $state->set('ai_provider_llama_cpp.models', ['llama3' => 'llama3-8b']);
    $state->set('ai_provider_llama_cpp.model_types', ['llama3' => ['chat']]);
    $state->set('ai_provider_llama_cpp.model_overrides', []);

    $this->config('ai.settings')
      ->set('default_providers', [
        'chat' => [
          'provider_id' => 'llama_cpp',
          'model_id' => 'llama3',
        ],
      ])
      ->save(TRUE);

    ai_provider_llama_cpp_update_10201();

    $server_storage = $this->container->get('entity_type.manager')
      ->getStorage('llama_cpp_server');
    $server = $server_storage->load('default');
    $this->assertNotNull($server);
    $this->assertSame('http://127.0.0.1', $server->getHostName());
    $this->assertSame('9000', $server->getPort());

    $this->assertSame(
      ['llama3' => 'llama3-8b'],
      $state->get('ai_provider_llama_cpp.server.default.models')
    );
    $this->assertNull($state->get('ai_provider_llama_cpp.models'));

    $defaults = $this->config('ai.settings')->get('default_providers');
    $this->assertSame('llama_cpp:default', $defaults['chat']['provider_id']);
    $this->assertTrue($this->config('ai_provider_llama_cpp.settings')->isNew());
  }

}
