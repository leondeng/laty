<?php

namespace Fan\Laty\Laravel;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class FunctionalTestCase extends BaseTestCase
{
  /**
   * The base URL to use while testing the application.
   *
   * @var string
   */
  protected $baseUrl = 'http://localhost';

  protected $settings = [];

  public function setUp(): void
  {
    parent::setUp();

    $this->initSettings();

    if ($this->logQuery()) {
      DB::enableQueryLog();
    }
  }

  protected function initSettings()
  {
    global $argv, $argc;

    foreach ($argv as $index => $setting) {
      if ($index < 2) {
        continue;
      }

      parse_str($setting, $settings);

      foreach ($settings as $key => $value) {
        if (isset($this->settings[$key])) {
          $this->settings[$key] += (array) $value;
        } else {
          $this->settings += (array) $settings;
        }
      }
    }
  }

  public function tearDown(): void
  {
    if ($this->logQuery()) {
      dump(DB::getQueryLog());
    }

    parent::tearDown();
  }

  protected function runTest()
  {
    if (!$this->noTransaction()) {
      $this->app->make('db')->beginTransaction();
    }

    parent::runTest();

    if (!$this->noTransaction()) {
      $this->app->make('db')->rollBack();
    }
  }

  protected function logQuery()
  {
    return array_key_exists('log-query', $this->settings);
  }

  protected function noTransaction()
  {
    return array_key_exists('no-transaction', $this->settings);
  }
}
