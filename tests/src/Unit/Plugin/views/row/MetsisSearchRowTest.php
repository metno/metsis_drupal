<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit\Plugin\views\row;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Form\FormState;
use Drupal\metsis_drupal\Hook\MetsisThemeHooks;
use Drupal\metsis_drupal\Service\MetVocabServiceInterface;
use Drupal\metsis_drupal\Plugin\views\row\MetsisSearchRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests METSIS search result row rendering.
 */
#[CoversClass(MetsisSearchRow::class)]
#[Group('metsis_drupal')]
final class MetsisSearchRowTest extends TestCase {

  /**
   * Tests named custom row validation and compatibility template fallback.
   */
  public function testCustomStyleValidationAndFallback(): void {
    $row = (new \ReflectionClass(MetsisSearchRow::class))->newInstanceWithoutConstructor();
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(static fn ($markup): string => $markup->getUntranslatedString());
    $row->setStringTranslation($translation);
    $form = [
      'custom_style_name' => ['#parents' => ['custom_style_name']],
      'view_modes' => ['#parents' => ['view_modes']],
    ];
    $state = (new FormState())->setValues([
      'style' => 'custom',
      'custom_style_name' => '../invalid',
      'view_modes' => [],
    ]);
    $row->validateOptionsForm($form, $state);
    self::assertArrayHasKey('custom_style_name', $state->getErrors());
    $state = (new FormState())->setValues([
      'style' => 'custom',
      'custom_style_name' => 'my_dataset',
      'view_modes' => [],
    ]);
    $row->validateOptionsForm($form, $state);
    self::assertSame([], $state->getErrors());
    $hooks = new MetsisThemeHooks($this->createMock(MetVocabServiceInterface::class));
    self::assertSame('rows/metsis-search-row-default', $hooks->themeHook()['metsis_search_row_custom']['template']);
    self::assertFalse($row::styleNameExists('my_dataset'));
  }

  /**
   * Tests data-access links use theme button styling and announce file details.
   */
  #[Test]
  public function testDataAccessLinksHaveThemeButtonStyleAndAccessibleLabels(): void {
    $row = (new \ReflectionClass(MetsisSearchRow::class))->newInstanceWithoutConstructor();
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')
      ->willReturnCallback(static fn ($markup): string => $markup->getUntranslatedString());
    $row->setStringTranslation($translation);

    /** @var array<string, mixed> $operations */
    $operations = [];
    $method = new \ReflectionMethod(MetsisSearchRow::class, 'buildDataAccessOptions');
    $method->invokeArgs($row, [
      &$operations,
      [
        'id' => 'dataset-1',
        'data_access_json' => [
          [
            'type' => 'HTTP',
            'resource' => 'https://example.test/files/data.nc?download=1',
            'description' => 'Direct access to the full data file.',
          ],
          [
            'type' => 'OPeNDAP',
            'resource' => 'https://example.test/thredds/dodsC/data',
          ],
          [
            'type' => 'HTTP',
            'resource' => 'https://nbstds.example/files/archive.zip?download=1',
          ],
        ],
      ],
      'row-1',
    ]);

    $netcdf_link = $operations['data_access_popover']['item_0']['#slots']['button'];
    self::assertSame('link', $netcdf_link['#type']);
    self::assertSame('Direct HTTP download (NetCDF file)', (string) $netcdf_link['#title']);
    self::assertContains('button', $netcdf_link['#attributes']['class']);
    self::assertArrayNotHasKey('aria-label', $netcdf_link['#attributes']);
    self::assertSame('Direct access to the full data file.', $netcdf_link['#attributes']['title']);
    self::assertTrue($netcdf_link['#attributes']['download']);

    $opendap_link = $operations['data_access_popover']['item_1']['#slots']['button'];
    self::assertSame(
      'OPeNDAP access (opens in a new tab)',
      (string) $opendap_link['#title'],
    );
    self::assertSame('_blank', $opendap_link['#attributes']['target']);
    self::assertContains('button', $opendap_link['#attributes']['class']);

    $zip_link = $operations['data_access_popover']['item_2']['#slots']['button'];
    self::assertSame(
      'Direct HTTP download (ZIP archive, SAFE)',
      (string) $zip_link['#title'],
    );
  }

  /**
   * Tests nested component settings are flattened into the saved options.
   */
  #[Test]
  public function testSubmitOptionsFormFlattensComponentSettings(): void {
    $row = (new \ReflectionClass(MetsisSearchRow::class))->newInstanceWithoutConstructor();
    $row->options = ['show_operations' => TRUE, 'style' => 'default'];
    $form = ['#parents' => ['row_options']];
    $state = (new FormState())->setValues([
      'row_options' => [
        'show_operations' => '1',
        'style' => 'nbs',
        'custom_style_name' => '',
        'component_settings' => [
          'shared_icons' => ['component_icon_width' => '4'],
          'thumbnail' => ['thumbnail_link_to_wms' => '1'],
          'doi_component' => [
            'doi_show_identifier' => '1',
            'doi_icon_size' => '32',
            'doi_logo_color' => '0',
          ],
          'temporal_extent_component' => [
            'temporal_extent_short_notation' => '1',
            'temporal_extent_compact_labels' => '0',
            'temporal_extent_add_icon' => '0',
            'temporal_extent_icon_id' => ' ',
          ],
        ],
      ],
    ]);
    $row->submitOptionsForm($form, $state);
    $saved = $state->getValue('row_options');

    self::assertArrayNotHasKey('component_settings', $saved);
    self::assertSame('nbs', $saved['style']);
    self::assertSame(16, $saved['component_icon_width']);
    self::assertTrue($saved['thumbnail_link_to_wms']);
    self::assertTrue($saved['doi_show_identifier']);
    self::assertSame(32, $saved['doi_icon_size']);
    self::assertFalse($saved['doi_logo_color']);
    self::assertTrue($saved['temporal_extent_short_notation']);
    self::assertFalse($saved['temporal_extent_compact_labels']);
    self::assertFalse($saved['temporal_extent_add_icon']);
    self::assertSame('date-time', $saved['temporal_extent_icon_id']);
    // The flattened values are also merged into the plugin options.
    self::assertSame($saved, array_intersect_key($row->options, $saved));
  }

}
