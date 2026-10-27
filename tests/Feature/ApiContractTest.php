<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiContractTest extends TestCase
{
    public function test_openapi_and_internal_api_operations_remain_in_sync(): void
    {
        $spec = json_decode(file_get_contents(base_path('docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $documented = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $documented[] = strtoupper($method).' '.$path;
                }
            }
        }
        $actual = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/')) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $actual[] = $method.' '.substr($route->uri(), strlen('api/v1'));
                }
            }
        }
        sort($documented);
        sort($actual);
        $this->assertSame($actual, $documented);
    }
}
