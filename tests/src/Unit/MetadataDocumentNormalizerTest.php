<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit;

use Drupal\metsis_drupal\Service\MetadataDocumentNormalizer;
use Drupal\metsis_drupal\Service\MetVocabServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MetadataDocumentNormalizer.
 */
#[CoversClass(MetadataDocumentNormalizer::class)]
#[Group('metsis_drupal')]
final class MetadataDocumentNormalizerTest extends TestCase {

  /**
   * Met vocabulary service mock.
   *
   * @var \Drupal\metsis_drupal\Service\MetVocabServiceInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  private MetVocabServiceInterface&MockObject $metVocabService;

  /**
   * Normalizer under test.
   */
  private MetadataDocumentNormalizer $normalizer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->metVocabService = $this->createMock(MetVocabServiceInterface::class);
    $this->normalizer = new MetadataDocumentNormalizer($this->metVocabService);
  }

  /**
   * Test that platform entries render correctly.
   *
   * Names, links, nested instruments, and vocab popover metadata.
   */
  #[Test]
  public function buildSectionsRendersPlatformAndInstrumentVocabularyMetadata(): void {
    $lookup_calls = [];
    $concept_map = [
      'Platform' => [
        'Sentinel-1C' => $this->createConcept(
          'https://vocab.met.no/mmd/Platform/Sentinel-1C',
          'Sentinel-1C',
          'Platform satellite concept',
          ['S1C'],
        ),
        'S1A' => $this->createConcept(
          'https://vocab.met.no/mmd/Platform/S1A',
          'S1A',
          'Platform satellite concept',
          ['Sentinel-1A'],
        ),
      ],
      'Instrument' => [
        'SAR-C' => $this->createConcept(
          'https://vocab.met.no/mmd/Instrument/SAR-C',
          'SAR-C',
          'Synthetic Aperture Radar instrument concept',
          ['Synthetic Aperture Radar (C-band)'],
        ),
      ],
      'Instrument_Modes' => [
        'IW' => $this->createConcept(
          'https://vocab.met.no/mmd/Instrument_Modes/IW',
          'IW',
          'Interferometric Wide mode',
        ),
      ],
      'Polarisation_Modes' => [
        'VV+VH' => $this->createConcept(
          'https://vocab.met.no/mmd/Polarisation_Modes/VV-VH',
          'VV+VH',
          'Dual-polarisation mode',
        ),
      ],
    ];

    $this->metVocabService->method('lookupByLabel')->willReturnCallback(
      function (string $collection_key, string $label) use (&$lookup_calls, $concept_map): ?array {
        $lookup_calls[] = [$collection_key, $label];

        return $concept_map[$collection_key][$label] ?? NULL;
      }
    );
    $this->metVocabService->method('lookupByUri')->willReturnCallback(
      function (string $uri) use (&$lookup_calls): ?array {
        $lookup_calls[] = ['uri', $uri];

        return NULL;
      }
    );

    $sections = $this->normalizer->buildSections([
      'platform_json' => [
        [
          'short_name' => 'Sentinel-1C',
          'long_name' => 'Sentinel-1C',
          'resource' => 'https://space.oscar.wmo.int/satellites/view/sentinel_1c',
        ],
        [
          'short_name' => 'S1A',
          'long_name' => 'Sentinel-1A',
          'resource' => 'https://space.oscar.wmo.int/satellites/view/sentinel_1a',
          'orbit_direction' => 'ascending',
          'orbit_relative' => '121',
          'orbit_absolute' => '62391',
          'instrument' => [
            'short_name' => 'SAR-C',
            'long_name' => 'Synthetic Aperture Radar (C-band)',
            'resource' => 'https://space.oscar.wmo.int/instruments/view/sar_c_sentinel_1',
            'mode' => 'IW',
            'polarisation' => 'VV+VH',
          ],
          'ancillary' => [
            'cloud_coverage' => '23.4',
            'scene_coverage' => '77',
            'timeliness' => 'NRT',
          ],
        ],
      ],
    ]);

    $this->assertCount(1, $sections);
    $platform_section = $sections[0];
    $this->assertSame('platform_json', $platform_section['field']);
    $this->assertSame('Platforms and instruments', $platform_section['title']);
    $this->assertArrayHasKey('platform_entries', $platform_section);
    $this->assertCount(2, $platform_section['platform_entries']);

    $first_platform = $platform_section['platform_entries'][0];
    $this->assertSame('Sentinel-1C', $first_platform['name']['text']);
    $this->assertSame('https://space.oscar.wmo.int/satellites/view/sentinel_1c', $first_platform['name']['resource_url']);
    $this->assertSame('platform-0-name', $first_platform['name']['popover_id']);
    $this->assertSame('https://vocab.met.no/mmd/Platform/Sentinel-1C', $first_platform['name']['vocabulary']['uri']);
    $this->assertSame(['S1C'], $first_platform['name']['vocabulary']['alt_labels']);
    $this->assertCount(0, $first_platform['details']);
    $this->assertCount(0, $first_platform['instruments']);
    $this->assertCount(0, $first_platform['ancillary']);

    $second_platform = $platform_section['platform_entries'][1];
    $this->assertSame('Sentinel-1A', $second_platform['name']['text']);
    $this->assertSame('https://space.oscar.wmo.int/satellites/view/sentinel_1a', $second_platform['name']['resource_url']);
    $this->assertSame('platform-1-name', $second_platform['name']['popover_id']);
    $this->assertSame('https://vocab.met.no/mmd/Platform/S1A', $second_platform['name']['vocabulary']['uri']);
    $this->assertSame(['Sentinel-1A'], $second_platform['name']['vocabulary']['alt_labels']);

    $this->assertSame('Orbit direction', $second_platform['details'][0]['label']);
    $this->assertSame('ascending', $second_platform['details'][0]['value']['text']);
    $this->assertNull($second_platform['details'][0]['value']['vocabulary']);

    $this->assertSame('Orbit relative', $second_platform['details'][1]['label']);
    $this->assertSame('121', $second_platform['details'][1]['value']['text']);

    $this->assertSame('Orbit absolute', $second_platform['details'][2]['label']);
    $this->assertSame('62391', $second_platform['details'][2]['value']['text']);

    $this->assertCount(1, $second_platform['instruments']);
    $instrument = $second_platform['instruments'][0];
    $this->assertSame('Synthetic Aperture Radar (C-band)', $instrument['name']['text']);
    $this->assertSame('https://space.oscar.wmo.int/instruments/view/sar_c_sentinel_1', $instrument['name']['resource_url']);
    $this->assertSame('platform-1-instrument-0-name', $instrument['name']['popover_id']);
    $this->assertSame('https://vocab.met.no/mmd/Instrument/SAR-C', $instrument['name']['vocabulary']['uri']);
    $this->assertSame(['Synthetic Aperture Radar (C-band)'], $instrument['name']['vocabulary']['alt_labels']);
    $this->assertSame('Mode', $instrument['details'][0]['label']);
    $this->assertSame('IW', $instrument['details'][0]['value']['text']);
    $this->assertSame('https://vocab.met.no/mmd/Instrument_Modes/IW', $instrument['details'][0]['value']['vocabulary']['uri']);
    $this->assertSame('Polarisation', $instrument['details'][1]['label']);
    $this->assertSame('VV+VH', $instrument['details'][1]['value']['text']);
    $this->assertSame('https://vocab.met.no/mmd/Polarisation_Modes/VV-VH', $instrument['details'][1]['value']['vocabulary']['uri']);

    $this->assertCount(3, $second_platform['ancillary']);
    $this->assertSame('Cloud coverage', $second_platform['ancillary'][0]['label']);
    $this->assertSame('23.4', $second_platform['ancillary'][0]['value']['text']);
    $this->assertNull($second_platform['ancillary'][0]['value']['vocabulary']);

    $this->assertSame('Scene coverage', $second_platform['ancillary'][1]['label']);
    $this->assertSame('77', $second_platform['ancillary'][1]['value']['text']);

    $this->assertSame('Timeliness', $second_platform['ancillary'][2]['label']);
    $this->assertSame('NRT', $second_platform['ancillary'][2]['value']['text']);

    $this->assertSame([
      ['Platform', 'Sentinel-1C'],
      ['Platform', 'Sentinel-1A'],
      ['Platform', 'S1A'],
      ['Instrument', 'Synthetic Aperture Radar (C-band)'],
      ['Instrument', 'SAR-C'],
      ['Instrument_Modes', 'IW'],
      ['Polarisation_Modes', 'VV+VH'],
    ], $lookup_calls);
  }

  /**
   * Test that core metadata summary values include mapped vocabulary metadata.
   */
  #[Test]
  public function buildSummaryAddsVocabularyMetadataForConfiguredFields(): void {
    $concept_map = [
      'Metadata_Status' => [
        'Active' => $this->createConcept(
          'https://vocab.met.no/mmd/Metadata_Status/Active',
          'Active',
          'Record is active',
        ),
      ],
      'Metadata_Source' => [
        'MMD' => $this->createConcept(
          'https://vocab.met.no/mmd/Metadata_Source/MMD',
          'MMD',
          'Metadata source concept',
        ),
      ],
      'Dataset_Production_Status' => [
        'Complete' => $this->createConcept(
          'https://vocab.met.no/mmd/Dataset_Production_Status/Complete',
          'Complete',
          'Dataset is complete',
        ),
      ],
      'Operational_Status' => [
        'Operational' => $this->createConcept(
          'https://vocab.met.no/mmd/Operational_Status/Operational',
          'Operational',
          'Operational status concept',
        ),
      ],
      'Activity_Type' => [
        'Observation' => $this->createConcept(
          'https://vocab.met.no/mmd/Activity_Type/Observation',
          'Observation',
          'Activity type concept',
        ),
      ],
      'Quality_Control' => [
        'QC0' => $this->createConcept(
          'https://vocab.met.no/mmd/Quality_Control/QC0',
          'QC0',
          'Quality control concept',
        ),
      ],
      'Access_Constraint' => [
        'Open' => $this->createConcept(
          'https://vocab.met.no/mmd/Access_Constraint/Open',
          'Open',
          'Access constraint concept',
        ),
      ],
      'Collection_Keywords' => [
        'NMAP' => $this->createConcept(
          'https://vocab.met.no/mmd/Collection_Keywords/NMAP',
          'NMAP',
          'Norwegian mapping collection',
          ['Norwegian Mapping Programme'],
        ),
        'YOPP' => $this->createConcept(
          'https://vocab.met.no/mmd/Collection_Keywords/YOPP',
          'YOPP',
          'Year of Polar Prediction collection',
          ['Polar Prediction'],
        ),
      ],
      'ISO_Topic_Category' => [
        'climatologyMeteorologyAtmosphere' => $this->createConcept(
          'https://vocab.met.no/mmd/ISO_Topic_Category/climatologyMeteorologyAtmosphere',
          'climatologyMeteorologyAtmosphere',
          'Climate, meteorology and atmosphere topic',
        ),
        'oceans' => $this->createConcept(
          'https://vocab.met.no/mmd/ISO_Topic_Category/oceans',
          'oceans',
          'Oceans topic category',
        ),
      ],
    ];

    $this->metVocabService->method('lookupByLabel')->willReturnCallback(
      static function (string $collection_key, string $label) use ($concept_map): ?array {
        return $concept_map[$collection_key][$label] ?? NULL;
      }
    );
    $this->metVocabService->method('lookupByUri')->willReturn(NULL);

    $summary = $this->normalizer->buildSummary([
      'metadata_identifier' => 'no.met.dataset.1',
      'metadata_status' => 'Active',
      'metadata_source' => 'MMD',
      'collection' => ['NMAP', 'YOPP'],
      'dataset_production_status' => 'Complete',
      'operational_status' => 'Operational',
      'activity_type' => 'Observation',
      'quality_control' => 'QC0',
      'iso_topic_category' => ['climatologyMeteorologyAtmosphere', 'oceans'],
      'feature_type' => 'Grid',
      'access_constraint' => 'Open',
      'use_constraint_identifier' => 'CC-BY-4.0',
      'use_constraint_resource' => 'https://creativecommons.org/licenses/by/4.0/',
    ]);

    $this->assertSame('no.met.dataset.1', $summary['Metadata identifier']['text']);
    $this->assertNull($summary['Metadata identifier']['vocabulary']);

    $this->assertSame('https://vocab.met.no/mmd/Metadata_Status/Active', $summary['Metadata status']['vocabulary']['uri']);
    $this->assertSame('https://vocab.met.no/mmd/Metadata_Source/MMD', $summary['Metadata source']['vocabulary']['uri']);
    $this->assertSame('https://vocab.met.no/mmd/Dataset_Production_Status/Complete', $summary['Production status']['vocabulary']['uri']);
    $this->assertSame('https://vocab.met.no/mmd/Operational_Status/Operational', $summary['Operational status']['vocabulary']['uri']);
    $this->assertSame('https://vocab.met.no/mmd/Activity_Type/Observation', $summary['Activity type']['vocabulary']['uri']);
    $this->assertSame('https://vocab.met.no/mmd/Quality_Control/QC0', $summary['Quality control']['vocabulary']['uri']);
    $this->assertSame('https://vocab.met.no/mmd/Access_Constraint/Open', $summary['Access constraint']['vocabulary']['uri']);
    $this->assertSame('CC-BY-4.0', $summary['License']['text']);
    $this->assertSame('https://creativecommons.org/licenses/by/4.0/', $summary['License']['resource_url']);
    $this->assertNull($summary['License']['vocabulary']);

    $this->assertSame('NMAP, YOPP', $summary['Collection']['text']);
    $this->assertCount(2, $summary['Collection']['vocabulary']['entries']);
    $this->assertSame('https://vocab.met.no/mmd/Collection_Keywords/NMAP', $summary['Collection']['vocabulary']['entries'][0]['uri']);
    $this->assertSame('https://vocab.met.no/mmd/Collection_Keywords/YOPP', $summary['Collection']['vocabulary']['entries'][1]['uri']);

    $this->assertSame('climatologyMeteorologyAtmosphere, oceans', $summary['Iso topic category']['text']);
    $this->assertCount(2, $summary['Iso topic category']['vocabulary']['entries']);
    $this->assertSame('https://vocab.met.no/mmd/ISO_Topic_Category/climatologyMeteorologyAtmosphere', $summary['Iso topic category']['vocabulary']['entries'][0]['uri']);
    $this->assertSame('https://vocab.met.no/mmd/ISO_Topic_Category/oceans', $summary['Iso topic category']['vocabulary']['entries'][1]['uri']);
  }

  /**
   * Ensure relations section is not returned and data access still works.
   */
  #[Test]
  public function buildSectionsOmitsRelationsSectionAndNormalizesWmsDataAccess(): void {
    $this->metVocabService->method('lookupByLabel')->willReturn(NULL);
    $this->metVocabService->method('lookupByUri')->willReturn(NULL);

    $sections = $this->normalizer->buildSections([
      'related_dataset' => 'no.met.related.dataset',
      'related_information_type' => 'Documentation',
      'related_information_resource' => 'https://example.test/doc',
      'related_information_description' => 'Dataset documentation',
      'data_access_json' => [
        [
          'type' => 'OGC WMS',
          'description' => 'WMS endpoint',
          'resource' => 'https://wms.example.test/service?foo=bar',
        ],
      ],
    ]);

    $fields = array_column($sections, 'field');
    $this->assertContains('data_access_json', $fields);
    $this->assertNotContains('relations', $fields);

    $data_access_section_index = array_search('data_access_json', $fields, TRUE);
    $this->assertNotFalse($data_access_section_index);

    $data_access_section = $sections[(int) $data_access_section_index];
    $this->assertArrayHasKey('related_information_entries', $data_access_section);
    $this->assertCount(1, $data_access_section['related_information_entries']);

    $entry = $data_access_section['related_information_entries'][0];
    $this->assertSame('WMS endpoint', $entry['link_text']);
    $this->assertSame(
      'https://wms.example.test/service?foo=bar&service=WMS&request=GetCapabilities',
      $entry['resource_url'],
    );
  }

  /**
   * Parent/child identifiers keep display IDs separate from route IDs.
   */
  #[Test]
  public function buildParentChildInfoUsesMetadataIdentifierForDisplayAndSolrIdForCatalogRoute(): void {
    $parent_info = $this->normalizer->buildParentChildInfo([
      'id' => 'doi-10-1594-PANGAEA-995890',
      'metadata_identifier' => 'doi:10.1594/PANGAEA.995890',
      'isParent' => TRUE,
      'isChild' => FALSE,
    ]);

    $this->assertSame('doi-10-1594-PANGAEA-995890', $parent_info['catalog_solr_id']);

    $child_info = $this->normalizer->buildParentChildInfo([
      'isParent' => FALSE,
      'isChild' => TRUE,
      'related_dataset' => 'doi:10.1594/PANGAEA.995890',
      'related_dataset_id' => 'doi-10-1594-PANGAEA-995890',
    ]);

    $this->assertSame(
      'doi:10.1594/PANGAEA.995890',
      $child_info['related_dataset']['text'],
    );
  }

  /**
   * Tests the data-center section using representative stored Solr values.
   *
   * @param array<string, mixed> $document
   *   Source fields.
   * @param array<string, string>|null $expected
   *   Expected presentation data, or NULL for an omitted section.
   */
  #[DataProvider('dataCenterDocuments')]
  public function testDataCenter(array $document, ?array $expected): void {
    $sections = array_column($this->normalizer->buildSections($document), NULL, 'field');
    if ($expected === NULL) {
      self::assertArrayNotHasKey('data_center', $sections);
    }
    else {
      self::assertSame($expected, $sections['data_center']['value']);
      self::assertSame('Data center', $sections['data_center']['title']);
    }
  }

  /**
   * Provides real naming patterns and missing/unsafe field cases.
   *
   * @return iterable<string, array>
   *   Source documents and expected normalized data.
   */
  public static function dataCenterDocuments(): iterable {
    $base = [
      'data_center_long_name' => 'Norwegian Meteorological Institute',
      'data_center_short_name' => 'METNO',
      'data_center_name' => 'METNO',
      'data_center_url' => 'https://met.no',
    ];
    $expected = [
      'name' => 'Norwegian Meteorological Institute',
      'short_name' => 'METNO',
      'resource_url' => 'https://met.no',
    ];
    yield 'all fields' => [$base, $expected];
    yield 'short label with spaces' => [
      array_replace($base, ['data_center_short_name' => 'MET Norway']),
      array_replace($expected, ['short_name' => 'MET Norway']),
    ];
    yield 'slash-separated short label' => [
      array_replace($base, ['data_center_short_name' => 'NO/MET/ADC']),
      array_replace($expected, ['short_name' => 'NO/MET/ADC']),
    ];
    yield 'combined fallback' => [
      ['data_center_name' => 'Norwegian Polar Institute', 'data_center_short_name' => 'NPI'],
      ['name' => 'Norwegian Polar Institute', 'short_name' => 'NPI', 'resource_url' => ''],
    ];
    yield 'short only' => [
      ['data_center_short_name' => 'Norwegian Polar Institute'],
      ['name' => 'Norwegian Polar Institute', 'short_name' => '', 'resource_url' => ''],
    ];
    yield 'long only' => [
      ['data_center_long_name' => 'GEUS'],
      ['name' => 'GEUS', 'short_name' => '', 'resource_url' => ''],
    ];
    yield 'full name repeated with whitespace and case' => [
      ['data_center_long_name' => ' Polar  Data Centre ', 'data_center_short_name' => "polar\tdata centre"],
      ['name' => 'Polar Data Centre', 'short_name' => '', 'resource_url' => ''],
    ];
    yield 'short longer than primary' => [
      ['data_center_long_name' => 'IADC', 'data_center_short_name' => 'Italian Arctic Data Center'],
      ['name' => 'IADC', 'short_name' => '', 'resource_url' => ''],
    ];
    yield 'already parenthesized' => [
      ['data_center_long_name' => 'Infrastructure for Research Data (NIRD)', 'data_center_short_name' => 'nird'],
      ['name' => 'Infrastructure for Research Data (NIRD)', 'short_name' => '', 'resource_url' => ''],
    ];
    yield 'acronym inside unrelated word' => [
      array_replace($base, ['data_center_short_name' => 'MET']),
      array_replace($expected, ['short_name' => 'MET']),
    ];
    yield 'array wrapped fields' => [array_map(static fn(string $value): array => [$value], $base), $expected];
    foreach (['javascript:alert(1)', 'ftp://example.org', '/relative', 'not a URL'] as $url) {
      yield 'unlinked ' . $url => [
        array_replace($base, ['data_center_url' => $url]),
        array_replace($expected, ['resource_url' => '']),
      ];
    }
    yield 'HTTP retained' => [
      array_replace($base, ['data_center_url' => 'http://met.no']),
      array_replace($expected, ['resource_url' => 'http://met.no']),
    ];
    yield 'URL only' => [
      ['data_center_url' => 'https://met.no'],
      ['name' => '', 'short_name' => '', 'resource_url' => 'https://met.no'],
    ];
    yield 'absent' => [[], NULL];
    yield 'invalid URL only' => [['data_center_url' => 'javascript:alert(1)'], NULL];
    yield 'empty names' => [
      ['data_center_long_name' => ' ', 'data_center_name' => NULL, 'data_center_short_name' => []],
      NULL,
    ];
  }

  /**
   * Tests data center follows Personnel, or comes first without Personnel.
   */
  public function testDataCenterSectionOrder(): void {
    $document = [
      'data_center_name' => 'METNO',
      'personnel_json' => [['name' => 'Example Person', 'role' => 'Investigator']],
      'data_access_json' => [['type' => 'HTTP', 'resource' => 'https://example.org/data']],
    ];
    self::assertSame(['personnel_json', 'data_center', 'data_access_json'], array_column($this->normalizer->buildSections($document), 'field'));
    unset($document['personnel_json']);
    self::assertSame(['data_center', 'data_access_json'], array_column($this->normalizer->buildSections($document), 'field'));
  }

  /**
   * Create a minimal concept info array for test assertions.
   *
   * @param string $uri
   *   Concept URI.
   * @param string $pref_label
   *   Preferred label.
   * @param string $definition
   *   Concept definition.
   * @param string[] $alt_labels
   *   Alternative labels.
   *
   * @return array<string, mixed>
   *   Concept info payload.
   */
  private function createConcept(string $uri, string $pref_label, string $definition, array $alt_labels = []): array {
    return [
      'uri' => $uri,
      'pref_label' => $pref_label,
      'alt_labels' => $alt_labels,
      'definition' => $definition,
      'group_uri' => 'https://vocab.met.no/mmd/' . rawurlencode($pref_label),
      'group_label' => $pref_label,
      'see_also' => [],
      'broader' => [],
    ];
  }

}
