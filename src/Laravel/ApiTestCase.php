<?php

namespace Fan\Laty\Laravel;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Fan\Laty\TestConfiguration;
use Mockery;

abstract class ApiTestCase extends BaseTestCase
{
  public static $test_configuration = [];

  private $dumper;

  public function setUp(): void
  {
    parent::setUp();

    $this->dumper = new CliDumper();
  }

  public function tearDown(): void
  {
    $refl = new \ReflectionObject($this);
    foreach ($refl->getProperties() as $prop) {
      if (!$prop->isStatic() && 0 !== strpos($prop->class, 'PHPUnit_')) {
        $prop->setAccessible(true);
        $prop->setValue($this, null);
      }
    }

    parent::tearDown();
  }

  /**
   * Recursive config handler
   *
   * @param string $key
   *          key to fetch
   * @return mixed
   */
  protected function getConfig($key = null)
  {
    if (!isset(static::$test_configuration[static::getConfigPrefix()])) {
      parse_str(
        implode(
          '&',
          array_filter($GLOBALS['argv'], function ($i) {
            if (
              in_array($i, [
                'never',
                'tests/Api',
                'tests/coverage',
                'tests/result/junit.xml',
                'tests/coverage.cobertura.xml',
              ])
            ) {
              return false;
            }
            return !preg_match(
              '/^(phpunit|[a-zA-Z]+Test|[\-]|[\-]{2}[\-a-zA-Z]+|\w+\.xml)$/',
              basename($i)
            );
          })
        ),
        $cli_config
      );
      static::$test_configuration = TestConfiguration::getConfig(
        [static::getConfigPrefix()],
        static::getConfigPaths(),
        $cli_config,
        static::getConfigSchemas()
      );
    }
    if (isset(static::$test_configuration[static::getConfigPrefix()][$key])) {
      return static::$test_configuration[static::getConfigPrefix()][$key];
    } else {
      return [];
    }
  }

  /**
   *
   * @see https://stackoverflow.com/questions/4440626/how-can-i-validate-regex
   * @param string $regex
   *          regex to check
   * @return boolean
   */
  protected function isValidRegex($regex)
  {
    if ($regex === []) {
      return false;
    }
    return !(@preg_match($regex, '') === false);
  }

  /**
   * Basic logging
   *
   * Environment variables:
   * - file path|php://stderr|php://stdout (default: php://stderr)
   * - verbosity 0-9 (default: 0)
   *
   * @param mixed $message
   * @param integer $level
   *          0-9 (0 nothing, 9 lots)
   */
  protected function log($message, $level = 1)
  {
    $settings = $this->getConfig('logging');

    if ($level <= $settings['verbosity']) {
      // file_put_contents($settings['file'], $message . PHP_EOL, FILE_APPEND);
      $this->dumper->dump(
        (new VarCloner())->cloneVar($message),
        $settings['file']
      );
    }
  }

  /**
   * Generate all test data packaged for controller
   *
   * @throws Exception
   * @return array
   */
  protected function apiControllerAutomatedProvider()
  {
    $data = [];
    $env_test_regex = $this->getConfig('test_regex');
    $env_test_id = $this->getConfig('test_filter');

    if (
      !empty($env_test_regex) &&
      !$this->isValidRegex((string) $env_test_regex)
    ) {
      throw new \Exception(
        'Invalid Regex provided via test_regex config variable'
      );
    }

    foreach ($this->getConfig('actions') as $idx => $test) {
      $test_id = $test['test_id'];

      if (
        (!empty($env_test_id) && $env_test_id != $test_id) ||
        (!empty($env_test_regex) && !preg_match($env_test_regex, $test_id))
      ) {
        continue;
      }

      // package test array for method call. sf2 config doesn't preserve array order
      foreach (
        [
          'users',
          'method',
          'uri',
          'parameters',
          'cookies',
          'files',
          'server',
          'content',
        ]
        as $arg
      ) {
        $data[$idx][$arg] = isset($test[$arg]) ? $test[$arg] : null;
      }

      // organise checks
      foreach (
        [
          'status_code',
          'headers',
          'content_type',
          'json',
          'no_json',
          'json_equals',
          'json_structure',
          'json_decoded',
          'no_json_decoded',
          'image_decoded',
          'pdf_decoded',
          'csv_decoded',
          'mail',
          'jobs',
          'events',
          'notification',
          'cache',
        ]
        as $check_ordered
      ) {
        $data[$idx]['checks'][$check_ordered] = isset(
          $test['checks'][$check_ordered]
        )
          ? $test['checks'][$check_ordered]
          : null;
      }

      $data[$idx]['test_id'] = $test['test_id'];
    }

    return $data;
  }

  /**
   * Automatically generate a set of client call to test
   *
   * @param string $method HTTP method
   * @param string $uri URI
   * @param array $parameters request parameters
   * @param array $files files posted
   * @param array $server server parameters
   * @param string $content data posted
   * @param array $checks checks to perform
   */
  protected function apiControllerAutomatedTest(
    $users,
    $method,
    $uri,
    $parameters,
    $cookies,
    $files,
    $server,
    $content,
    $checks,
    $test_id
  ) {
    if (!count($users)) {
      //no users defined, public url
      $this->doRun(
        $method,
        $uri,
        $parameters,
        $cookies,
        $files,
        $server,
        $content,
        $checks
      );
    } else {
      foreach ($users as $index => $user) {
        if ($index) {
          $this->refreshApplication(); // refresh application for extra roles
        }

        $server = $this->authenticateUser($user, $server);
        $this->log(
          sprintf('Test: %s, authencated user: %s', $test_id, $user),
          1
        );

        $this->doRun(
          $method,
          $uri,
          $parameters,
          $cookies,
          $files,
          $server,
          $content,
          $checks
        );
      }
    }
  }

  abstract protected function authenticateUser($user_name, $server);

  abstract protected function getAuthenticatedUser();

  protected function doRun(
    $method,
    $uri,
    $parameters,
    $cookies,
    $files,
    $server,
    $content,
    $checks
  ) {
    $kernel = $this->app->make('Illuminate\Contracts\Http\Kernel');

    $this->currentUri = trim($this->getConfig('base_url').$uri, '/');

    $content = json_encode($content);
    $this->log(
      sprintf('Sending request data "%s" to "%s"', $content, $this->currentUri),
      2
    );

    $headers = array_merge(
      [
        'CONTENT_LENGTH' => mb_strlen($content, '8bit'),
      ],
      $server
    );

    $uploads = $this->prepareUploads($files);

    $request = Request::create(
      $this->currentUri,
      $method,
      $parameters,
      $cookies,
      $uploads,
      $this->transformHeadersToServerVars($headers),
      $content
    );

    $this->prepareMocks($checks);

    $this->prepareCache($checks);

    $dbs = $this->dbsBeginTransaction();

    $settings = $this->getConfig('logging');
    if ($settings['query']) {
      foreach ($dbs as $db) {
        $db->enableQueryLog();
      }
    }

    $response = $kernel->handle($request);
    $testResponse = TestResponse::fromBaseResponse($response);

    $this->log(sprintf('Received response data "%s"', strval($response)), 6);

    $kernel->terminate($request, $response);

    if ($settings['query']) {
      foreach ($dbs as $db) {
        $this->log($db->getQueryLog(), 0);
      }
    }

    foreach ($dbs as $db) {
      $db->rollBack();
      $db->disconnect(); //FIXME: prevent too many db connections error
    }

    if (class_exists('Mockery')) {
      Mockery::close();
    }

    $this->response = $testResponse;

    foreach (array_filter((array) $checks) as $check_name => $check_args) {
      $method = 'check' . Str::studly($check_name);
      $this->$method($check_args);
    }
  }

  protected function dbsBeginTransaction()
  {
    $db = $this->app->make('db');
    $db->beginTransaction();

    return [$db];
  }

  protected function prepareMocks(&$checks)
  {
    if (isset($checks['mail'])) {
      if (is_numeric($checks['mail'])) {
        $this->checkMail($checks['mail']);
        unset($checks['mail']);
      } elseif (is_array($checks['mail'])) {
        Mail::fake();
      }
    }

    if (isset($checks['jobs'])) {
      $this->checkJobs($checks['jobs']);
      unset($checks['jobs']);
    }

    if (isset($checks['events'])) {
      $this->checkEvents($checks['events']);
      unset($checks['events']);
    }

    if (isset($checks['notification'])) {
      $this->checkNotification($checks['notification']);
      unset($checks['notification']);
    }
  }

  private function prepareUploads(array $files)
  {
    $uploads = [];

    foreach ($files as $name => $path) {
      $path = base_path('tests/Api/uploads' . $path);

      $tmpFile = $this->copyTemp($path);

      $mimeType = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmpFile);
      $filename = pathinfo($tmpFile, PATHINFO_BASENAME);

      $file = new UploadedFile(
        $tmpFile,
        $filename,
        // filesize($tmpFile),
        $mimeType,
        null,
        true
      );

      $uploads[$name] = $file;
    }

    return $uploads;
  }

  private function copyTemp($path)
  {
    if (!file_exists($path)) {
      throw new FileNotFoundException("File does not exist at path {$path}");
    }

    $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . basename($path);

    if (false === copy($path, $tmpPath)) {
      throw new \Exception('Copy file as a temp file failed.');
    }

    return $tmpPath;
  }

  /**
   * checkStatusCode
   *
   * verifies the response code matches the configured code
   *
   * @param int $code
   */
  protected function checkStatusCode($code = 200)
  {
    $this->response->assertStatus((int) $code);
  }

  /**
   * checkHeaders
   *
   * verifies the response contains the given header and equals the optional value
   *
   * @param array $headers
   */
  protected function checkHeaders($headers = [])
  {
    foreach ($headers as $headerName => $value) {
      $this->response->assertHeader($headerName, $value);
    }
  }

  /**
   * checkContentType
   *
   * @param string $mimeType
   */
  protected function checkContentType($mimeType = 'json')
  {
    $message = sprintf('Content type is valid (%s)', $mimeType);

    $body = $this->response->getContent();

    if (in_array($mimeType, ['json', 'application/json'])) {
      $this->shouldReturnJson();
    } elseif (in_array($mimeType, ['html', 'text/html'])) {
      libxml_use_internal_errors(true);
      $doc = new \DOMDocument();
      $this->assertTrue($doc->loadHTML($body), $message);
      libxml_use_internal_errors(false);
    } elseif (
      in_array($mimeType, ['jpeg', 'jpg', 'image/jpeg', 'png', 'image/png'])
    ) {
      $this->assertTrue(false !== imagecreatefromstring($body), $message);
    } elseif (in_array($mimeType, ['pdf', 'application/pdf'])) {
      $finfo = finfo_open();
      $this->assertTrue(strpos(finfo_buffer($finfo, $body), 'PDF') !== false);
    } elseif (in_array($mimeType, ['csv', 'text/csv'])) {
      $lines = explode("\r\n", $body);
      $this->assertTrue(!empty($lines), $message);
      $columns = explode(',', $lines[0]);
      $this->assertTrue(!empty($columns), $message);
    } elseif ($mimeType !== 'application/zip') {
      throw new \Exception(sprintf('Unknown content type "%s"', $mimeType));
    }
  }

  protected function shouldReturnJson()
  {
    return $this->response->decodeResponseJson();
  }

  /**
   * checkJson
   *
   * @param string $jsons
   */
  protected function checkJson($jsons = [])
  {
    foreach ($jsons as $json) {
      $this->response->assertJsonFragment($json);
    }
  }

  /**
   * checkNoJson
   *
   * @param string $jsons
   */
  protected function checkNoJson($jsons = [])
  {
    foreach ($jsons as $json) {
      $this->response->assertJsonMissing($json);
    }
  }

  /**
   * checkJsonEquals
   *
   * @param string $json
   */
  protected function checkJsonEquals($json = [])
  {
    $this->response->assertExactJson($json);
  }

  /**
   * checkJsonStructure
   *
   * @param string $structure
   */
  protected function checkJsonStructure($structure = [])
  {
    $this->response->assertJsonStructure($structure);
  }

  /**
   * checkJsonDecoded
   *
   * @param string $decoded
   */
  protected function checkJsonDecoded($decoded = [])
  {
    $this->seeJsonDecoded($decoded);
  }

  /**
   * checkNoJsonDecoded
   *
   * @param string $decoded
   */
  protected function checkNoJsonDecoded($decoded = [])
  {
    $this->seeJsonDecoded($decoded, true);
  }

  protected function checkImageDecoded($decoded = [])
  {
    $content = $this->decodeImage();
    $this->assertEquals($decoded, $content);
  }

  private function decodeImage()
  {
    $image = imagecreatefromstring($this->response->getContent());

    return [
      'width' => (string) imagesx($image),
      'height' => (string) imagesy($image),
    ];
  }

  protected function checkPdfDecoded($decoded = [])
  {
    $content = $this->decodedPdf();
    $this->_checkDecoded($decoded, $content);
  }

  private function _checkDecoded($decoded, $content)
  {
    $_content = Arr::dot((array) $content);
    $encoded_content = json_encode($content);

    foreach ($decoded as $path => $expected) {
      $this->assertTrue(
        array_key_exists($path, $_content),
        "Unable to find path [{$path}] within [{$encoded_content}]."
      );

      $actual = $_content[$path];

      if ($this->isValidRegex($expected)) {
        $this->assertMatchesRegularExpression(
          $expected,
          (string) $actual,
          "Expected [{$actual}] match [{$expected}] on [{$path}]."
        );
      } else {
        $this->assertTrue(
          $expected == $actual,
          "Expected [{$expected}] on [{$path}], got [{$actual}]."
        );
      }
    }
  }

  private function decodedPdf()
  {
    $parser = new \Smalot\PdfParser\Parser();
    $pdf = $parser->parseContent($this->response->getContent());
    $output = [
      'Details' => $pdf->getDetails(),
      'Text' => $pdf->getText(),
      'Pages' => [],
    ];
    $output['Stripped'] = preg_replace('/[^a-zA-Z0-9]/', '', $output['Text']);

    foreach ($pdf->getPages() as $page_nr => $page) {
      $output['Pages'][$page_nr] = [
        'Details' => $page->getDetails(),
        'Text' => $page->getText(),
      ];
      $output['Pages'][$page_nr]['Stripped'] = preg_replace(
        '/[^a-zA-Z0-9]/',
        '',
        $output['Pages'][$page_nr]['Text']
      );
    }

    return $output;
  }

  protected function checkCsvDecoded($decoded = [])
  {
    $content = $this->decodedCsv();
    $this->_checkDecoded($decoded, $content);
  }

  private function decodedCsv()
  {
    $lines = explode(PHP_EOL, $this->response->getContent());
    $output = [];

    foreach ($lines as $index => $line) {
      $line = substr($line, 1, strlen($line) - 2);
      $output['r' . $index] = explode('","', $line);
    }

    return $output;
  }

  /**
   * Assert that the flattened JSON response has a given value.
   *
   * @param  array|null  $data
   * @param  boolean  $negate
   * @return $this
   */
  public function seeJsonDecoded(array $data, $negate = false)
  {
    $method = $negate ? 'assertFalse' : 'assertTrue';
    $regex_method = $negate ? 'assertDoesNotMatchRegularExpression' : 'assertMatchesRegularExpression';

    $content = json_decode($this->response->getContent(), true);
    $encoded_content = json_encode($content);

    if (is_null($content) || $content === false) {
      return $this->fail(
        'Invalid JSON was returned from the route. Perhaps an exception was thrown?'
      );
    }

    $_content = Arr::dot((array) $content);

    foreach ($data as $path => $expected) {
      $this->{$method}(
        array_key_exists($path, $_content),
        ($negate ? 'Found unexpected' : 'Unable to find') .
          " path [{$path}] within [{$encoded_content}]."
      );

      if ($negate) {
        continue;
      }

      $actual = $_content[$path];

      if ($this->isValidRegex($expected)) {
        $this->{$regex_method}(
          $expected,
          (string) $actual,
          ($negate ? 'Unexpected' : 'Expected') .
            " [{$actual}] match [{$expected}] on [{$path}]."
        );
      } elseif (is_array($expected)) {
        //empty array expected
        $this->{$method}(
          empty($actual),
          ($negate ? 'Unexpected' : 'Expected') . " empty array on [{$path}]."
        );
      } else {
        $this->{$method}(
          $expected == $actual,
          ($negate ? 'Unexpected' : 'Expected') .
            " [{$expected}] on [{$path}], got [{$actual}]."
        );
      }
    }

    return $this;
  }

  public function checkMail($times)
  {
    if (is_numeric($times)) {
      Mail::shouldReceive('send')->times($times);
    }

    if (is_array($times)) {
      foreach ($times as $mailable => $time) {
        Mail::assertQueued($mailable, $time);
      }
    }
  }

  public function checkNotification($times)
  {
    Notification::shouldReceive('send')->times($times);
  }

  public function checkJobs($jobs)
  {
    $this->expectsJobs($jobs);
  }

  public function checkEvents($events)
  {
    $this->expectsEvents($events);
  }

  abstract protected function prepareCache(array $checks);

  public function checkCache($checks)
  {
    foreach ($checks as $m => $key) {
      if ($m === 'hasKey') {
        $this->assertTrue(
          Cache::has($key),
          "No such key [$key] found in cache."
        );
        Cache::forget($key); // remove from cache after checking
      }

      if ($m === 'noKey' || $m === 'cleanKey') {
        $this->assertFalse(Cache::has($key), "Key [$key] found in cache.");
      }
    }
  }

  /**
   * get the prefix to use for config
   *
   * @return string
   */
  protected static function getConfigPrefix()
  {
    throw new \Exception(
      'Please implement getConfigPrefix as a static method in your class.'
    );
  }

  /**
   * get the schemas to use for config
   *
   * @return array
   */
  protected static function getConfigSchemas()
  {
    return [];
  }

  /**
   * get the paths to search for yml specs
   *
   * @return array<string>
   */
  protected static function getConfigPaths()
  {
    throw new \Exception(
      'Please implement getConfigPaths as a static method in your class.'
    );
  }
}
