<?php

namespace Drupal\Tests\ai_provider_llama_cpp\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_provider_llama_cpp\Form\LlamaCppConfigForm;

/**
 * Tests the LlamaCppConfigForm.
 *
 * @group ai_provider_llama_cpp
 */
class LlamaCppConfigFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'ai', 'ai_provider_llama_cpp', 'key'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['ai', 'ai_provider_llama_cpp']);
  }

  /**
   * Tests config form saving.
   */
  public function testConfigFormSave(): void {
    $config_factory = $this->container->get('config.factory');
    $config = $config_factory->getEditable('ai_provider_llama_cpp.settings');

    // Test default configuration.
    $this->assertEquals('8080', $config->get('port'));
    $this->assertNull($config->get('host_name'));

    // Set configuration values.
    $config->set('host_name', 'http://127.0.0.1')
      ->set('port', '11434')
      ->save();

    // Verify values were saved.
    $updated_config = $config_factory->get('ai_provider_llama_cpp.settings');
    $this->assertEquals('http://127.0.0.1', $updated_config->get('host_name'));
    $this->assertEquals('11434', $updated_config->get('port'));
  }

}
