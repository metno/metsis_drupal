<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\metsis_drupal\Controller\MetsisStatusReportController;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Kernel test for MetsisStatusReportController.
 */
#[Group('metsis_drupal')]
#[CoversClass('Drupal\metsis_drupal\Controller\MetsisStatusReportController')]
class MetsisStatusReportControllerTest extends KernelTestBase {

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

    // Add other required modules if needed.
  ];

  /**
   * Tests the statusReportPage() method when Solr is unavailable.
   */
  #[Test]
  public function testStatusReportPageSolrUnavailable() {
    // Mock the entity type manager and its getStorage() method.
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);

    // Mock the server storage and server entity.
    $server_storage = $this->getMockBuilder('Drupal\Core\Entity\EntityStorageInterface')
      ->disableOriginalConstructor()
      ->getMock();

    $server = $this->getMockBuilder('Drupal\search_api\Entity\Server')
      ->disableOriginalConstructor()
      ->getMock();

    // Mock the backend.
    $backend = $this->getMockBuilder('Drupal\search_api_solr\Plugin\search_api\backend\SearchApiSolrBackend')
      ->disableOriginalConstructor()
      ->getMock();

    // Mock the solr connector.
    $connector = $this->getMockBuilder('Drupal\search_api_solr\SolrConnectorInterface')
      ->getMock();

    // Set up the backend to return FALSE for isAvailable().
    $backend->method('isAvailable')->willReturn(FALSE);

    // Make backend return the connector mock for getSolrConnector().
    $backend->method('getSolrConnector')->willReturn($connector);

    // Set up the server to return the backend and config.
    $server->method('getBackend')->willReturn($backend);
    $server->method('getBackendConfig')->willReturn([
      'connector_config' => [
        'scheme' => 'http',
        'host' => 'localhost',
        'port' => 8983,
        'context' => 'solr',
        'core' => 'metsis',
      ],
    ]);
    $server->method('getDescription')->willReturn('Test Solr server');

    // Set up the storage to return the mocked server.
    $server_storage->method('load')->willReturn($server);

    // Mock the index storage and index entity.
    $index_storage = $this->getMockBuilder('Drupal\Core\Entity\EntityStorageInterface')
      ->disableOriginalConstructor()
      ->getMock();
    $index = $this->getMockBuilder('Drupal\search_api\Entity\Index')
      ->disableOriginalConstructor()
      ->getMock();
    $index->method('status')->willReturn('unavailable');
    $index_storage->method('load')->willReturn($index);

    // Set up the entity type manager to return the mocked storages.
    $entity_type_manager->method('getStorage')
      ->willReturnMap([
        ['search_api_server', $server_storage],
        ['search_api_index', $index_storage],
      ]);

    // Define mocks for the connector methods used in the controller.
    $connector->method('pingCore')->willReturn("2.1");
    $connector->method('getSolrVersion')->willReturn("8.11.2");
    $connector->method('getSchemaVersionString')->willReturn("1.6");
    $connector->method('getStatsSummary')->willReturn([
      "@pending_docs" => 0,
      "@autocommit_time_seconds" => 600,
      "@autocommit_time" => "10 min",
      "@deletes_by_id" => 0,
      "@deletes_by_query" => 0,
      "@deletes_total" => 0,
      "@schema_version" => "metsis-adc-3.x",
      "@core_name" => "adc-dev_shard1_replica_n1",
      "@index_size" => "56.2 KB",
      "@collection_name" => "adc-dev",
    ]);
    $connector->method('getLuke')->willReturn([
      "index" => [
        "numDocs" => 9,
        "maxDoc" => 11,
        "deletedDocs" => 2,
      ],
    ]
    );

    // Instantiate the controller with mocked and auto-mock constructor args.
    $reflection = new \ReflectionClass(MetsisStatusReportController::class);
    $constructor = $reflection->getConstructor();
    if ($constructor) {
      $args = [];
      $params = $constructor->getParameters();
      foreach ($params as $index => $param) {
        if ($index === 0) {
          // Use our prepared EntityTypeManager mock for the first parameter.
          $args[] = $entity_type_manager;
          continue;
        }
        $type = $param->getType();
        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
          $className = $type->getName();
          $args[] = $this->getMockBuilder($className)->disableOriginalConstructor()->getMock();
        }
        else {
          $args[] = NULL;
        }
      }
      $controller = $reflection->newInstanceArgs($args);
    }
    else {
      // Fallback if no constructor is present.
      $controller = $reflection->newInstanceWithoutConstructor();
    }

    // Call the method.
    $build = $controller->statusReportPage();

    // Assert the render array structure.
    $this->assertIsArray($build);
    $this->assertArrayHasKey('report', $build);
    $this->assertArrayHasKey('#requirements', $build['report']);
    $this->assertArrayHasKey('status', $build['report']['#requirements']);

    // Assert the status requirement indicates not available.
    $status_req = $build['report']['#requirements']['status'];
    $this->assertEquals('Status', $status_req['title']);
    $this->assertStringContainsString('not available', strtolower((string) $status_req['value']));
    $this->assertEquals(RequirementSeverity::Error, $status_req['severity']);
  }

}
