<?php

declare(strict_types=1);

namespace Drupal\Tests\dynamic_landing_pages\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\dynamic_landing_pages\Controller\DynamicLandingPagesController;
use Drupal\leaflet\LeafletService;
use Drupal\metsis_drupal\Service\CatalogButtonBuilder;
use Drupal\metsis_drupal\Service\DatasetVisualisationBuilder;
use Drupal\metsis_drupal\Service\LeafletMapRenderer;
use Drupal\metsis_drupal\Service\MetadataDocumentNormalizer;
use Drupal\metsis_drupal\Service\MetVocabServiceInterface;
use Drupal\metsis_drupal\Service\SolrConnectorProvider;
use Drupal\metsis_drupal\Service\SolrDocumentLoader;
use Drupal\metsis_drupal\Service\SolrQueryFactory;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\ServerInterface;
use Drupal\search_api_solr\Plugin\search_api\backend\SearchApiSolrBackend;
use Drupal\search_api_solr\SolrConnectorInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Solarium\Core\Client\Response;
use Solarium\QueryType\Select\Query\Query;
use Solarium\QueryType\Select\Result\Result;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests data-center sections are rebuilt for old landing-page cache entries.
 */
#[CoversClass(DynamicLandingPagesController::class)]
#[Group('metsis_drupal')]
final class DataCenterCacheTest extends UnitTestCase {

  /**
   * Tests old cache bypass, normalized section storage, and timestamp reuse.
   */
  public function testCacheTransitionAndReuse(): void {
    $document = [
      'title' => 'Example dataset',
      'metadata_identifier' => 'example',
      'timestamp' => '2026-01-01T00:00:00Z',
      'last_metadata_updated_date' => '2026-01-01T00:00:00Z',
      'data_center_long_name' => 'Norwegian Meteorological Institute',
      'data_center_short_name' => 'METNO',
      'data_center_url' => 'https://met.no',
    ];
    $connector = $this->createMock(SolrConnectorInterface::class);
    $connector->method('getSelectQuery')->willReturnCallback(static fn(): Query => new Query());
    $calls = [];
    $connector->expects(self::exactly(4))->method('execute')->willReturnCallback(
      static function (Query $query) use ($document, &$calls): Result {
        $calls[] = $query->getFields();
        if (count($calls) >= 3) {
          $document['timestamp'] = '2026-02-01T00:00:00Z';
          $document['data_center_long_name'] = 'Updated data center';
        }
        return new Result($query, new Response(json_encode([
          'response' => ['numFound' => 1, 'docs' => [$document]],
        ], JSON_THROW_ON_ERROR), ['HTTP/1.1 200 OK']));
      },
    );
    $backend = $this->createMock(SearchApiSolrBackend::class);
    $backend->method('getSolrConnector')->willReturn($connector);
    $server = $this->createMock(ServerInterface::class);
    $server->method('getBackend')->willReturn($backend);
    $index = $this->createMock(IndexInterface::class);
    $index->method('getServerInstance')->willReturn($server);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with('metsis')->willReturn($index);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('search_api_index')->willReturn($storage);
    $provider = new SolrConnectorProvider($manager);
    $loader = new SolrDocumentLoader(new SolrQueryFactory($provider), $provider);

    $cache = new MemoryBackend($this->createMock(TimeInterface::class));
    $cache->set('dataset:no.met.adc:example', [
      'sections' => [],
      'solr_timestamp' => $document['timestamp'],
      'solr_last_update' => $document['last_metadata_updated_date'],
    ]);
    $translation = $this->getStringTranslationStub();
    $urls = $this->createMock(UrlGeneratorInterface::class);
    $urls->method('generateFromRoute')->willReturn('https://example.org/dataset/example');
    $forms = $this->createMock(FormBuilderInterface::class);
    $forms->method('getForm')->willReturn([]);
    $container = new ContainerBuilder();
    $container->set('url_generator', $urls);
    $container->set('form_builder', $forms);
    $container->set('config.factory', $this->getConfigFactoryStub([
      'dynamic_landing_pages.settings' => ['naming_authority' => 'no.met.adc'],
    ]));
    \Drupal::setContainer($container);

    $controller = new DynamicLandingPagesController(
      $loader,
      new MetadataDocumentNormalizer($this->createMock(MetVocabServiceInterface::class)),
      new LeafletMapRenderer($this->createMock(LeafletService::class)),
      $cache,
      new CatalogButtonBuilder(),
      new DatasetVisualisationBuilder($translation, $urls),
    );
    $first = $controller->getLandingPage(new Request(), 'example');
    $second = $controller->getLandingPage(new Request(), 'example');
    self::assertContains('*', $calls[0]);
    self::assertSame(['timestamp', 'last_metadata_updated_date'], $calls[1]);
    self::assertSame($first['#sections'], $second['#sections']);
    self::assertSame('data_center', $first['#sections'][0]['field']);
    self::assertSame('METNO', $first['#sections'][0]['value']['short_name']);
    $cached = $cache->get('dataset:v3:no.met.adc:example');
    self::assertNotFalse($cached);
    self::assertSame($first['#sections'], $cached->data['sections']);
    self::assertSame(['dynamic_landing_pages:dataset:no.met.adc:example'], $cached->tags);
    $updated = $controller->getLandingPage(new Request(), 'example');
    self::assertSame(['timestamp', 'last_metadata_updated_date'], $calls[2]);
    self::assertContains('*', $calls[3]);
    self::assertSame('Updated data center', $updated['#sections'][0]['value']['name']);
  }

}
