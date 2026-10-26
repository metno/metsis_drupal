<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Kernel;

use Drupal\Core\Cache\NullBackend;
use Drupal\KernelTests\KernelTestBase;
use Drupal\metsis_drupal\Service\MetVocabService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Kernel tests for MetVocabService with a null cache backend.
 */
#[CoversClass(MetVocabService::class)]
#[Group('metsis_drupal')]
class MetVocabServiceNullCacheKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'search_api',
    'search_api_solr',
    'leaflet',
    'geofield',
    'honeypot',
    'metsis_drupal',
  ];

  /**
   * Tests that lookups resolve when persistent cache writes are disabled.
   */
  #[Test]
  public function testLookupByLabelAndUriWithNullBackend(): void {
    $config_factory = $this->container->get('config.factory');

    // Ensure test uses the vendor TTL path relative to project root.
    $config_factory->getEditable('metsis_drupal.settings')
      ->set('vocab_source_path', 'vendor/metno/mmd/thesauri/mmd-vocabulary.ttl')
      ->set('vocab_cache_ttl', 86400)
      ->save();

    $service = new MetVocabService(
      $config_factory,
      new NullBackend('metsis_vocab'),
      $this->container->get('datetime.time'),
    );

    $by_label = $service->lookupByLabel('Use_Constraint', 'CC-BY-4.0');
    $this->assertNotNull($by_label, 'lookupByLabel should resolve with null cache backend.');
    $this->assertSame('CC-BY-4.0', $by_label['pref_label']);

    $this->assertNotEmpty($by_label['uri']);
    $by_uri = $service->lookupByUri((string) $by_label['uri']);
    $this->assertNotNull($by_uri, 'lookupByUri should resolve with null cache backend.');
    $this->assertSame((string) $by_label['uri'], $by_uri['uri']);

    $group = $service->getGroup('Use_Constraint');
    $this->assertNotNull($group, 'getGroup should resolve with null cache backend.');
    $this->assertSame('Use Constraint', $group['label']);
  }

}
