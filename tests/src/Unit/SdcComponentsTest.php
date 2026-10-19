<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit;

use Drupal\Core\Cache\NullBackend;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\Component;
use Drupal\Core\Render\Component\Exception\InvalidComponentException;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Theme\Component\ComponentValidator;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\sdc_devel\DefinitionValidator;
use Drupal\sdc_devel\TwigValidator\TwigValidator;
use Drupal\sdc_devel\TwigValidatorRulePluginManager;
use Drupal\sdc_devel\Validator as DevelValidator;
use Drupal\Tests\UnitTestCase;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Runtime\EscaperRuntime;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Validates SDC contracts, examples, and template behavior without a database.
 */
#[CoversNothing]
#[Group('metsis_drupal')]
final class SdcComponentsTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Tests the installed SDC Devel rules when the optional tool is available.
   */
  public function testDevelopmentReport(): void {
    if (!class_exists(DevelValidator::class)) {
      $this->markTestSkipped('SDC Devel is not installed.');
    }
    $twig = $this->twig();
    $loader = $twig->getLoader();
    $this->assertInstanceOf(ArrayLoader::class, $loader);
    $definitions = [];
    foreach (self::componentNames() as [$name]) {
      $definition = $this->definition($name);
      $definition['_discovered_file_path'] = self::root() . "/components/$name/$name.component.yml";
      $definitions[$definition['id']] = $definition;
      $loader->setTemplate("components/$name/$name.twig", file_get_contents(self::root() . "/components/$name/$name.twig"));
    }
    $component_manager = $this->createMock(ComponentPluginManager::class);
    $component_manager->method('hasDefinition')->willReturn(TRUE);
    $component_manager->method('getDefinition')->willReturnCallback(static fn(string $id): array => $definitions[$id]);
    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->willReturn(TRUE);
    $rules = new TwigValidatorRulePluginManager(
      new \ArrayIterator(['Drupal\sdc_devel' => self::root() . '/web/modules/contrib/sdc_devel/src']),
      new NullBackend('sdc_components_test'),
      $module_handler,
    );
    $this->assertNotEmpty($rules->getDefinitions());
    $core_validator = new ComponentValidator();
    $core_validator->setValidator();
    $validator = new DevelValidator(
      new TwigValidator($twig, $rules, $component_manager),
      new DefinitionValidator($core_validator),
    );
    foreach ($definitions as $id => $definition) {
      $validator->validateComponent($id, new Component(['app_root' => self::root()], $id, $definition));
    }
    $messages = array_map(static fn($message): string => (string) $message->message(), $validator->getMessages());
    $this->assertSame([], $messages, implode("\n", $messages));
  }

  /**
   * Tests every definition, prop example, default, and slot example.
   */
  #[DataProvider('componentNames')]
  public function testSchemaAndExamples(string $name): void {
    $definition = $this->definition($name);
    $validator = new ComponentValidator();
    $validator->setValidator();
    $this->assertTrue($validator->validateDefinition($definition, TRUE));

    $component = new Component(['app_root' => self::root()], $definition['id'], $definition);
    $props = [];
    foreach ($definition['props']['properties'] as $key => $schema) {
      $this->assertNotEmpty($schema['examples'] ?? [], "$name.$key needs examples.");
      $props[$key] = $schema['examples'][0];
      foreach ([...$schema['examples'], ...array_intersect_key($schema, ['default' => TRUE])] as $example) {
        $example_validator = new Validator();
        $value = json_decode(json_encode($example, JSON_THROW_ON_ERROR), FALSE, 512, JSON_THROW_ON_ERROR);
        $example_validator->validate($value, Validator::arrayToObjectRecursive($schema));
        $this->assertTrue($example_validator->isValid(), "$name.$key: " . json_encode($example_validator->getErrors()));
      }
    }
    $this->assertTrue($validator->validateProps($props, $component));
    $this->assertNotEmpty($this->renderComponent($name, $props));

    foreach ($definition['slots'] ?? [] as $key => $slot) {
      $this->assertNotEmpty($slot['examples'] ?? [], "$name.$key needs examples.");
      foreach ($slot['examples'] as $example) {
        $this->assertIsString($example);
        $this->assertNotSame('', $example);
      }
    }
  }

  /**
   * Provides all component names.
   *
   * @return iterable<string, array{string}>
   *   Component names discovered in the module.
   */
  public static function componentNames(): iterable {
    foreach (glob(self::root() . '/components/*/*.component.yml') as $file) {
      $name = basename(dirname($file));
      yield $name => [$name];
    }
  }

  /**
   * Tests omitted and explicit boolean values preserve link behavior.
   */
  public function testPersonLinkTarget(): void {
    $props = ['link_url' => 'https://example.org/profile', 'icon_id' => 'orcid'];
    $this->assertStringContainsString('target="_blank"', $this->renderComponent('metadata_person_link', $props));
    $this->assertStringContainsString('target="_blank"', $this->renderComponent('metadata_person_link', $props + ['target_blank' => TRUE]));
    $html = $this->renderComponent('metadata_person_link', $props + ['target_blank' => FALSE, 'link_text' => '0']);
    $this->assertStringNotContainsString('target=', $html);
    $this->assertStringNotContainsString('rel=', $html);
    $this->assertStringContainsString('>0</span>', $html);
  }

  /**
   * Tests DOI identifier visibility for omitted and explicit booleans.
   */
  public function testDoiIdentifier(): void {
    $props = ['doi_url' => 'https://doi.org/10.1000/182'];
    $this->assertStringContainsString('data-icon="doi"', $this->renderComponent('doi', $props));
    $this->assertStringContainsString('data-icon="doi-black"', $this->renderComponent('doi', $props + ['color' => FALSE]));
    $this->assertStringNotContainsString('class="doi-identifier"', $this->renderComponent('doi', $props));
    $this->assertStringNotContainsString('class="doi-identifier"', $this->renderComponent('doi', $props + ['show_doi_identifier' => FALSE]));
    $this->assertStringContainsString('class="doi-identifier">10.1000/182', $this->renderComponent('doi', $props + ['show_doi_identifier' => TRUE]));
  }

  /**
   * Tests temporal defaults, disabled options, and open-ended notation.
   */
  public function testTemporalExtent(): void {
    $props = ['start_date' => '2021-05-05T11:20:00Z'];
    $html = $this->renderComponent('temporal_extent', $props);
    $this->assertStringContainsString('data-icon="date-time"', $html);
    $this->assertStringNotContainsString('End date:', $html);
    $this->assertStringNotContainsString('temporal-extent-row--stacked', $html);

    $html = $this->renderComponent('temporal_extent', $props + [
      'add_icon' => FALSE,
      'compact_labels' => FALSE,
    ]);
    $this->assertStringNotContainsString('data-icon=', $html);
    $this->assertStringContainsString('temporal-extent-row--stacked', $html);
    $html = $this->renderComponent('temporal_extent', $props + ['short_notation' => TRUE]);
    $this->assertStringContainsString('temporal-extent-short', $html);
    $this->assertStringNotContainsString(' to ', $html);
    $html = $this->renderComponent('temporal_extent', $props + [
      'short_notation' => TRUE,
      'end_date' => '2023-10-01T00:00:00Z',
    ]);
    $this->assertStringContainsString(' to 2023-10-01T00:00:00Z', $html);
  }

  /**
   * Tests schema props are used and user-provided labels remain escaped.
   */
  public function testLabels(): void {
    $this->assertStringContainsString('placeholder="Type your text"', $this->renderComponent('search'));
    $this->assertStringContainsString('placeholder="Search &quot;Arctic&quot;"', $this->renderComponent('search', ['placeholder' => 'Search "Arctic"']));
    $html = $this->renderComponent('cc_license', [
      'license_id' => 'CC-BY-4.0',
      'license_url' => 'https://creativecommons.org/licenses/by/4.0/',
      'icon_id' => 'by',
    ]);
    $this->assertStringContainsString('title="CC-BY-4.0 License"', $html);
    $this->assertStringContainsString('--cc-icon-width: 88;', $html);
  }

  /**
   * Tests nested citation data rejects invalid field values.
   */
  public function testInvalidCitation(): void {
    $definition = $this->definition('dataset_citation');
    $citations = $definition['props']['properties']['citations']['examples'][0];
    $citations[0]['fields'][0]['value'] = ['not a string'];
    $validator = new ComponentValidator();
    $validator->setValidator();
    $component = new Component(['app_root' => self::root()], $definition['id'], $definition);
    $this->expectException(InvalidComponentException::class);
    $validator->validateProps(['citations' => $citations], $component);
  }

  /**
   * Tests both actual callers preserve per-citation DOI icons through the slot.
   */
  public function testCitationComposition(): void {
    $examples = $this->definition('dataset_citation')['props']['properties']['citations']['examples'];
    $citations = [...$examples[0], ...$examples[1], ...$examples[0]];
    $citations[2]['resource_url'] = 'https://doi.org/10.1000/183';
    $citations[0]['fields'][] = ['label' => 'Version', 'value' => '0'];
    $citations[0]['fields'][] = ['label' => 'Empty', 'value' => ''];
    $templates = [
      'templates/metadata/metsis-metadata-document.html.twig',
      'modules/dynamic_landing_pages/templates/dynamic-landing-page.html.twig',
    ];
    foreach ($templates as $template) {
      $twig = $this->twig();
      $html = $twig->createTemplate(file_get_contents(self::root() . '/' . $template))->render([
        'sections' => [
          ['field' => 'dataset_citation_json', 'dataset_citation_entries' => $citations],
        ],
      ]);
      $this->assertSame(2, substr_count($html, 'data-icon="doi"'), $template);
      $this->assertStringContainsString('href="https://doi.org/10.1000/182"', $html);
      $this->assertStringContainsString('href="https://doi.org/10.1000/183"', $html);
      $this->assertStringContainsString('<dd>0</dd>', $html);
      $this->assertStringNotContainsString('<dt>Empty</dt>', $html);
    }
  }

  /**
   * Tests escaped names, subtext, accessible links and URL-only fallback.
   */
  public function testDataCenterRendering(): void {
    $props = [
      'name' => 'Center <script>alert(1)</script>',
      'short_name' => 'DC & Co',
      'resource_url' => 'https://example.org/?a=1&b=2',
    ];
    $html = $this->renderComponent('data_center', $props);
    $this->assertStringContainsString('Center &lt;script&gt;', $html);
    $this->assertStringNotContainsString('<script>', $html);
    $this->assertStringContainsString('DC &amp; Co', $html);
    $this->assertStringContainsString('href="https://example.org/?a=1&amp;b=2"', $html);
    $this->assertStringContainsString('target="_blank"', $html);
    $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    $this->assertStringContainsString('class="visually-hidden">(opens in a new window)</span>', $html);
    $plain = $this->renderComponent('data_center', ['name' => 'Center']);
    $this->assertStringNotContainsString('<a ', $plain);
    $this->assertStringNotContainsString('metsis-data-center__short-name', $plain);
    $this->assertStringNotContainsString('opens in', $plain);
    $this->assertStringContainsString('Data center', $this->renderComponent('data_center', ['resource_url' => 'https://example.org']));
    $this->assertSame('', trim($this->renderComponent('data_center')));
  }

  /**
   * Tests both metadata templates consume the same normalized section.
   */
  public function testDataCenterComposition(): void {
    foreach ([
      'templates/metadata/metsis-metadata-document.html.twig',
      'modules/dynamic_landing_pages/templates/dynamic-landing-page.html.twig',
    ] as $template) {
      $html = $this->twig()->createTemplate(file_get_contents(self::root() . '/' . $template))->render([
        'sections' => [
          [
            'field' => 'data_center',
            'title' => 'Data center',
            'value' => [
              'name' => 'Norwegian Meteorological Institute',
              'short_name' => 'METNO',
              'resource_url' => 'https://met.no',
            ],
          ],
        ],
      ]);
      $this->assertStringContainsString('>Data center</h2>', $html, $template);
      $this->assertStringContainsString('Norwegian Meteorological Institute', $html, $template);
      $this->assertStringContainsString('metsis-data-center__short-name">METNO</span>', $html, $template);
      $this->assertStringContainsString('href="https://met.no"', $html, $template);
      $this->assertStringContainsString('(opens in a new window)', $html, $template);
    }
  }

  /**
   * Loads a definition with the metadata normally supplied by discovery.
   *
   * @param string $name
   *   The component machine name.
   *
   * @return array<string, mixed>
   *   The component definition.
   */
  private function definition(string $name): array {
    return Yaml::parseFile(self::root() . "/components/$name/$name.component.yml") + [
      'id' => 'metsis_drupal:' . $name,
      'machineName' => $name,
      'extension_type' => 'module',
      'provider' => 'metsis_drupal',
      'path' => "components/$name",
      'template' => "$name.twig",
      'library' => [],
    ];
  }

  /**
   * Renders a component using its real Twig template.
   *
   * @param string $name
   *   The component machine name.
   * @param array<string, mixed> $props
   *   Component props.
   */
  private function renderComponent(string $name, array $props = []): string {
    return $this->twig()->render('metsis_drupal:' . $name, $props);
  }

  /**
   * Builds a lightweight Twig environment with Drupal presentation helpers.
   */
  private function twig(): Environment {
    $templates = [];
    foreach (self::componentNames() as [$name]) {
      $templates['metsis_drupal:' . $name] = file_get_contents(self::root() . "/components/$name/$name.twig");
    }
    $twig = new Environment(new ArrayLoader($templates), ['autoescape' => 'html']);
    $twig->getRuntime(EscaperRuntime::class)->addSafeClass(Attribute::class, ['html']);
    $twig->addGlobal('attributes', new Attribute());
    $twig->addFilter(new TwigFilter('t', static fn(string $text): string => $text));
    $twig->addFunction(new TwigFunction('icon', static fn(string $pack, string $id, array $settings): string => '<span data-icon="' . $id . '"></span>', ['is_safe' => ['html']]));
    $twig->addFunction(new TwigFunction('attach_library', static fn(string $library): string => ''));
    return $twig;
  }

  /**
   * Returns the module root.
   */
  private static function root(): string {
    return dirname(__DIR__, 3);
  }

}
