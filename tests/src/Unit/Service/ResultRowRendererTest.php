<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit\Service;

use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\metsis_drupal\Service\ConfigProvider;
use Drupal\metsis_drupal\Service\LeafletMapRenderer;
use Drupal\metsis_drupal\Service\MarkdownDetectorInterface;
use Drupal\metsis_drupal\Service\MetVocabServiceInterface;
use Drupal\metsis_drupal\Service\ResultRowRenderer;
use Drupal\metsis_drupal\Utility\MetsisHelper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests row plugin component options reach the rendered components.
 */
#[CoversClass(ResultRowRenderer::class)]
#[Group('metsis_drupal')]
final class ResultRowRendererTest extends UnitTestCase {

  /**
   * Builds a renderer with lightweight dependencies.
   */
  private function createRenderer(): ResultRowRenderer {
    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('getModule')->willReturn($this->createMock(Extension::class));
    $config_provider = new ConfigProvider($this->getConfigFactoryStub([
      'metsis_drupal.license_icons' => [
        'license_icons' => [
          'CC-BY-4_0' => [
            'license_uri' => 'https://creativecommons.org/licenses/by/4.0/',
            'icon_id' => 'cc-by',
            'icon_alt_text' => 'CC BY',
          ],
        ],
      ],
    ]));
    $helper = (new \ReflectionClass(MetsisHelper::class))->newInstanceWithoutConstructor();
    $leaflet = (new \ReflectionClass(LeafletMapRenderer::class))->newInstanceWithoutConstructor();
    $renderer = new ResultRowRenderer(
      $helper,
      $module_handler,
      $config_provider,
      $leaflet,
      $this->createMock(MetVocabServiceInterface::class),
      $this->createMock(MarkdownDetectorInterface::class),
    );
    $renderer->setStringTranslation($this->getStringTranslationStub());
    return $renderer;
  }

  /**
   * Returns a Solr document exercising all configurable components.
   *
   * @return array<string, mixed>
   *   Solr document.
   */
  private function document(): array {
    return [
      'id' => 'dataset-1',
      'metadata_identifier' => 'no.met:dataset-1',
      'title' => 'Dataset',
      'isParent' => TRUE,
      'use_constraint_identifier' => 'CC-BY-4.0',
      'dataset_citation_doi' => ['https://doi.org/10.1000/182'],
      'temporal_extent_start_date' => ['2021-05-05T11:20:00Z'],
      'temporal_extent_end_date' => ['2023-10-01T00:00:00Z'],
    ];
  }

  /**
   * Tests configured row options are passed to the component props.
   */
  public function testRenderRowHonoursComponentOptions(): void {
    $fields = $this->createRenderer()->renderRow($this->document(), [
      'component_icon_width' => 64,
      'doi_show_identifier' => TRUE,
      'doi_icon_size' => 32,
      'doi_logo_color' => FALSE,
      'temporal_extent_short_notation' => TRUE,
      'temporal_extent_compact_labels' => FALSE,
      'temporal_extent_add_icon' => FALSE,
      'temporal_extent_icon_id' => 'calendar',
    ], []);

    self::assertSame([
      'start_date' => '2021-05-05T11:20:00Z',
      'end_date' => '2023-10-01T00:00:00Z',
      'add_icon' => FALSE,
      'icon_id' => 'calendar',
      'short_notation' => TRUE,
      'compact_labels' => FALSE,
    ], $fields['temporal_extent']['#props']);
    self::assertTrue($fields['doi_icon']['#props']['show_doi_identifier']);
    self::assertFalse($fields['doi_icon']['#props']['color']);
    self::assertSame(32, $fields['doi_icon']['#props']['size']);
    self::assertSame(64, $fields['license_icon']['#props']['width']);
    self::assertSame(64, $fields['parent']['#props']['width']);
  }

  /**
   * Tests missing options fall back to the row plugin defaults.
   */
  public function testRenderRowDefaults(): void {
    $fields = $this->createRenderer()->renderRow($this->document(), [], []);

    self::assertTrue($fields['temporal_extent']['#props']['add_icon']);
    self::assertSame('date-time', $fields['temporal_extent']['#props']['icon_id']);
    self::assertFalse($fields['temporal_extent']['#props']['short_notation']);
    self::assertTrue($fields['temporal_extent']['#props']['compact_labels']);
    self::assertFalse($fields['doi_icon']['#props']['show_doi_identifier']);
    self::assertTrue($fields['doi_icon']['#props']['color']);
    self::assertSame(24, $fields['doi_icon']['#props']['size']);
    self::assertSame(88, $fields['license_icon']['#props']['width']);
  }

}
