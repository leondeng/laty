# laty
Laravel Api Test in YAML

## Highlights
- fast Api spec composing: concise yaml format spec to describe an Api about request and response
- diverse types of content checking: JSON, HTML, CSV, even PDF
- focusing TDD via test id filtering: shortcut for best productivity
- auto database transaction and queries log: keep database clean, and still be able to view all queries
- offline Job and Mail bypassing: mock anything irrelevant to Api logic, keep it fast

## Installation
```
composer require fan/laty --dev
```

## Setup
- create `tests\Api\specs\base.yml`
```
api_controller_actions:
  base_url: https://localhost/api
  logging:
    file: php://stderr
    verbosity: 0
    query: false
```
- create `tests\Api\WebServiceTest.php`
```
<?php

namespace Tests\Api;

use Fan\Laty\Laravel\ApiTestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Passport;
use App\Models\User;

class WebServiceTest extends ApiTestCase
{
    public function webServiceAutomatedProvider()
    {
        return $this->apiControllerAutomatedProvider(static::getConfigPrefix());
    }

    /**
    * @param array $seeds database seeds
    * @param string $method HTTP method
    * @param string $uri URI
    * @param array $parameters request parameters
    * @param array $cookies request cookies
    * @param array $files files posted
    * @param array $server server parameters
    * @param string $content data posted
    * @param unknown $checks
    * @dataProvider webServiceAutomatedProvider
    */
    public function testWebServices(
        $seeds,
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
        return $this->apiControllerAutomatedTest(
            $seeds,
            $method,
            $uri,
            $parameters,
            $cookies,
            $files,
            $server,
            $content,
            $checks,
            $test_id
        );
    }

    protected static function getConfigPaths()
    {
        return array(__DIR__.'/specs/');
    }

    protected static function getConfigPrefix()
    {
        return 'api_controller_actions';
    }

    protected function authenticateUser($user_name, $server)
    {
        $user = $this->getUser($user_name);

        if (! is_null($user)) {
            // if you are using Passport for authentication
            Passport::actingAs($user);
        }

        return $server;
    }

    private function getUser($user_name)
    {
        return User::find($user_name);
    }

    protected function getAuthenticatedUser()
    {
        return Auth::user();
    }

    protected function prepareCache(array $checks)
    {
        // add your cache layer mock here
    }

    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
```
- add script to `composer.json` scripts
```
    "test:api": [
        "php vendor/bin/phpunit tests/Api"
    ]
```

## The first Api spec
- create `tests/Api/specs/users.yml`
```
api_controller_actions:
  actions:
    -
      test_id: users/show/0 # test id, can be used for focusing TDD
      method: GET # HTTP request method
      uri: /user # Api endpoint
      checks:
        status_code: 401 # check the status code
```
- run all specs
```
$composer test:api
> php vendor/bin/phpunit tests/Api
PHPUnit 9.5.21 #StandWithUkraine

.                                                                   1 / 1 (100%)

Time: 00:00.270, Memory: 18.00 MB

OK (1 tests, 1 assertions)
```
- focusing TDD
```
composer test:api test_filter=users/show/0 "logging[verbosity]=6"
> php vendor/bin/phpunit tests/Api 'test_filter=users/show/0' 'logging[verbosity]=6'
PHPUnit 9.5.21 #StandWithUkraine

"Sending request data "[]" to "https://localhost/api/user""
"""
Received response data "HTTP/1.1 401 Unauthorized\r\n
Access-Control-Allow-Origin: *\r\n
Cache-Control:               no-cache, private\r\n
Content-Type:                application/json\r\n
Date:                        Mon, 15 Aug 2022 03:01:33 GMT\r\n
\r\n
{"message":"Unauthenticated."}"
"""
.                                                                   1 / 1 (100%)

Time: 00:00.328, Memory: 24.00 MB

OK (1 test, 1 assertion)
```

## Authenticate and Json Check
- update the users.yml to
```
api_controller_actions:
  ___meta:
    users:
      - &test_user_1 1 # define a user by id
  actions:
    -
      test_id: users/show/0
      method: GET
      uri: /user
      checks:
        status_code: 401
    -
      test_id: users/show/1
      method: GET
      uri: /user
      users:
      - *test_user_1 # use user id for Passport actAs
      checks:
        json_decoded: # decode response json and check
          email: test@example.com
        no_json_decoded: # make sure what you unwanted is not there
          id: ~
```

## Check Queries Example
```
> php vendor/bin/phpunit tests/Api 'test_filter=comments/show/1' 'logging[query]=true'
PHPUnit 9.5.21 #StandWithUkraine

array:2 [
  0 => array:3 [
    "query" => "select * from `users` where `id` = ? limit 1"
    "bindings" => array:1 [
      0 => "11"
    ]
    "time" => 3.0
  ]
  1 => array:3 [
    "query" => "select * from `comments` where `id` = ? and `comments`.`deleted_at` is null limit 1"
    "bindings" => array:1 [
      0 => "1"
    ]
    "time" => 2.87
  ]
]
.                                                                   1 / 1 (100%)

Time: 00:00.290, Memory: 26.00 MB

OK (1 test, 2 assertions)
```

## More Content Type Check Examples
- CSV check
```
    -
      test_id: orders/export/1
      users:
      - *test_user_1
      method: POST
      uri: /orders/export/csv
      content:
        orders:
        -
          uuid: %uuid1%
      checks:
        content_type: text/csv
        csv_decoded:
          r0.0: Order No.
          r0.1: Customer Name
          r0.2: Legal Name
```
- PDF check
```
    -
      test_id: print_jobs/pdf/1
      users:
      - *test_user_1
      method: GET
      uri: print_jobs/1/pdf
      checks:
        content_type: application/pdf
        pdf_decoded:
          Stripped: /^PRINTJOBEXAMPLE\d*CUSTOMERNAME.*TIME(\d{10}).*BRANDNAME$/
```