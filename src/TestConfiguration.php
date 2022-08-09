<?php

namespace Fan\Laty;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Illuminate\Support\Str;

class TestConfiguration implements ConfigurationInterface
{
  const DEFAULT_CONFIG_SCHEMAS = ['system_settings', 'logging'];
  private $configSchemas = [];
  private $prefixes = [];
  private $configs = [];

  public function __construct(array $prefixes, array $config_schemas)
  {
    $this->prefixes = $prefixes;
    $this->configSchemas = $config_schemas;
  }

  /**
   * Creates a TestConfiguration instance based on prefixes and command line arguments
   *
   * @param array $prefixes
   *          prefixes to include
   * @param array $config_paths
   *          base paths to search configs
   * @param array $overrides
   *          array with global override settings (usually $argv)
   *
   * @return array populated configuration array
   */
  public static function getConfig(
    array $prefixes,
    array $config_paths,
    array $overrides = null,
    array $config_schemas = []
  ) {
    $finder = new Finder();

    $config_files = $finder
      ->files()
      ->followLinks()
      ->name('*.yml');
    foreach ($config_paths as $path) {
      $config_files = $config_files->in($path);
    }

    $configs = [];
    foreach ($config_files as $config_file) {
      $configs[] = Yaml::parse($config_file->getContents());
    }

    if ($overrides) {
      $overrides = static::flagFilter($overrides);

      foreach ($prefixes as $prefix) {
        $configs[] = [
          $prefix => $overrides,
        ];
      }
    }

    $processor = new Processor();
    $configuration = new TestConfiguration($prefixes, $config_schemas);

    return $processor->processConfiguration($configuration, $configs);
  }

  protected static function flagFilter(array $config)
  {
    if (isset($config['logging']) && isset($config['logging']['query'])) {
      $config['logging']['query'] = filter_var(
        $config['logging']['query'],
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
      );
    }

    return $config;
  }

  /**
   * Builds the config tree for all available test prefixes
   *
   * @return \Symfony\Component\Config\Definition\Builder\TreeBuilder
   */
  public function getConfigTreeBuilder()
  {
    $treeBuilder = new TreeBuilder('tests');

    $node = $treeBuilder->getRootNode();

    foreach ($this->prefixes as $prefix) {
      $node = $node
        ->children()
        ->arrayNode($prefix)
        ->children();
      // alias holder
      $node = $node->variableNode('___meta')->end();
      foreach ($this->getConfigSchemas($prefix) as $config_prefix) {
        $node = $this->{'getConfigSchemaFor' . Str::studly($config_prefix)}(
          $node
        );
      }
      $node = $node->end()->end();
    }

    return $treeBuilder;
  }

  public function getConfigSchemas($prefix = null)
  {
    $schemas = $this->configSchemas;
    if (empty($schemas)) {
      $schemas = self::DEFAULT_CONFIG_SCHEMAS;
    }

    if ($prefix) {
      array_splice($schemas, 1, 0, [$prefix]);
    }

    return $schemas;
  }

  /**
   * system settings
   */
  public function getConfigSchemaForSystemSettings(NodeBuilder $node)
  {
    $node = $node
      ->scalarNode('base_url')
      ->end()
      ->scalarNode('test_filter')
      ->end()
      ->scalarNode('test_regex')
      ->end();

    return $node;
  }

  /**
   * API Actions are a curl test set
   */
  public function getConfigSchemaForControllerActions(NodeBuilder $node)
  {
    return $this->getConfigSchemaForControllerTestSet($node);
  }

  /**
   * default curl test set settings
   */
  public function getConfigSchemaForControllerTestSet(NodeBuilder $node)
  {
    $node = $node
      ->arrayNode('actions')
      ->requiresAtLeastOneElement()
      ->prototype('array')
      ->children()
      ->scalarNode('method')
      ->defaultValue('POST')
      ->end()
      ->scalarNode('uri')
      ->isRequired()
      ->cannotBeEmpty()
      ->end()
      ->variableNode('parameters')
      ->defaultValue([])
      ->end()
      ->variableNode('files')
      ->defaultValue([])
      ->end()
      ->variableNode('server')
      ->defaultValue(['Content-Type' => 'application/json'])
      ->end()
      ->variableNode('content')
      ->defaultValue([])
      ->end()
      ->arrayNode('checks')
      ->addDefaultsIfNotSet()
      ->children()
      ->scalarNode('status_code')
      ->defaultValue('200')
      ->end()
      ->scalarNode('header_regexp')
      ->end()
      ->scalarNode('content_type')
      ->defaultValue('json')
      ->end()
      ->scalarNode('content_regexp')
      ->end()
      ->variableNode('content_decoded')
      ->end()
      ->end()
      ->end()
      ->scalarNode('test_id')
      ->isRequired()
      ->cannotBeEmpty()
      ->end()
      ->end()
      ->end()
      ->end();

    return $node;
  }

  /**
   * API Actions are a curl test set
   */
  public function getConfigSchemaForApiControllerActions(NodeBuilder $node)
  {
    return $this->getConfigSchemaForApiControllerTestSet($node);
  }

  /**
   * default curl test set settings
   */
  public function getConfigSchemaForApiControllerTestSet(NodeBuilder $node)
  {
    $node = $node
      ->arrayNode('actions')
      ->requiresAtLeastOneElement()
      ->prototype('array')
      ->children()
      ->variableNode('users')
      ->defaultValue([])
      ->end()
      ->scalarNode('method')
      ->defaultValue('POST')
      ->end()
      ->scalarNode('uri')
      ->isRequired()
      ->cannotBeEmpty()
      ->end()
      ->variableNode('parameters')
      ->defaultValue([])
      ->end()
      ->variableNode('cookies')
      ->defaultValue([])
      ->end()
      ->variableNode('files')
      ->defaultValue([])
      ->end()
      ->variableNode('server')
      ->defaultValue(['Content-Type' => 'application/json'])
      ->end()
      ->variableNode('content')
      ->defaultValue([])
      ->end()
      ->arrayNode('checks')
      ->addDefaultsIfNotSet()
      ->children()
      ->scalarNode('status_code')
      ->defaultValue('200')
      ->end()
      ->variableNode('headers')
      ->end()
      ->scalarNode('content_type')
      ->defaultValue('json')
      ->end()
      ->variableNode('json')
      ->end()
      ->variableNode('no_json')
      ->end()
      ->variableNode('json_equals')
      ->end()
      ->variableNode('json_structure')
      ->end()
      ->variableNode('json_decoded')
      ->end()
      ->variableNode('no_json_decoded')
      ->end()
      ->variableNode('image_decoded')
      ->end()
      ->variableNode('pdf_decoded')
      ->end()
      ->variableNode('csv_decoded')
      ->end()
      ->variableNode('mail')
      ->end()
      ->variableNode('jobs')
      ->end()
      ->variableNode('events')
      ->end()
      ->variableNode('notification')
      ->end()
      ->variableNode('cache')
      ->end()
      ->end()
      ->end()
      ->scalarNode('test_id')
      ->isRequired()
      ->cannotBeEmpty()
      ->end()
      ->end()
      ->end()
      ->end();

    return $node;
  }

  /**
   * logging settings
   */
  public function getConfigSchemaForLogging(NodeBuilder $node)
  {
    $node = $node
      ->arrayNode('logging')
      ->addDefaultsIfNotSet()
      ->children()
      ->scalarNode('file')
      ->defaultValue('php:stderr')
      ->end()
      ->scalarNode('verbosity')
      ->defaultValue(0)
      ->end()
      ->booleanNode('query')
      ->defaultValue(false)
      ->end()
      ->end()
      ->end();

    return $node;
  }
}
