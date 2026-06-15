<?php

namespace Drupal\Tests\ai_provider_llama_cpp\Unit;

use Drupal\ai_provider_llama_cpp\Plugin\AiProvider\LlamaCppProvider;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @coversDefaultClass \Drupal\ai_provider_llama_cpp\Plugin\AiProvider\LlamaCppProvider
 * @group ai_provider_llama_cpp
 */
class LlamaCppProviderTest extends UnitTestCase {

  /**
   * Tests operation type detection.
   *
   * @covers ::detectOperationTypes
   */
  public function testDetectOperationTypes(): void {
    // Create a mock for LlamaCppProvider, exposing detectOperationTypes.
    $provider = $this->getMockBuilder(LlamaCppProvider::class)
      ->disableOriginalConstructor()
      ->onlyMethods([])
      ->getMock();

    // Use reflection to test the protected method.
    $reflection = new \ReflectionClass($provider);
    $method = $reflection->getMethod('detectOperationTypes');
    $method->setAccessible(TRUE);

    // Test with --embeddings arg.
    $model = ['status' => ['args' => ['--embeddings']]];
    $this->assertEquals(['embeddings'], $method->invoke($provider, $model));

    // Test with --reranking arg.
    $model = ['status' => ['args' => ['--reranking']]];
    $this->assertEquals(['rerank'], $method->invoke($provider, $model));

    // Test whisper model name.
    $model = ['id' => 'whisper-small', 'status' => ['args' => []]];
    $this->assertEquals(['speech_to_text'], $method->invoke($provider, $model));

    // Test embedding model name.
    $model = ['id' => 'nomic-embed-text', 'status' => ['args' => []]];
    $this->assertEquals(['embeddings'], $method->invoke($provider, $model));

    // Test rerank model name.
    $model = ['id' => 'bge-reranker-v2-m3', 'status' => ['args' => []]];
    $this->assertEquals(['rerank'], $method->invoke($provider, $model));

    // Test chat fallback.
    $model = ['id' => 'llama3', 'status' => ['args' => []]];
    $this->assertEquals(['chat'], $method->invoke($provider, $model));
  }

}
