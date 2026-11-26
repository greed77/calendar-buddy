<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The app runs behind Cloudflare, which terminates TLS and talks to the
 * container over plain http. These tests make sure generated URLs still use
 * https so pages don't load scripts, styles or Livewire requests over http.
 */
class HttpsBehindProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/scheme', fn () => [
            'secure' => request()->secure(),
            'url' => url('/somewhere'),
            'asset' => asset('favicon.ico'),
        ]);
    }

    public function test_forwarded_https_from_the_proxy_is_trusted(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '172.70.1.1'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'polls.example.com'])
            ->getJson('/_test/scheme')
            ->assertOk()
            ->assertJson([
                'secure' => true,
                'url' => 'https://polls.example.com/somewhere',
                'asset' => 'https://polls.example.com/favicon.ico',
            ]);
    }

    public function test_plain_http_without_proxy_headers_stays_http(): void
    {
        $response = $this->getJson('http://localhost/_test/scheme')->assertOk();

        $this->assertFalse($response->json('secure'));
        $this->assertSame('http://localhost/somewhere', $response->json('url'));
    }

    public function test_production_forces_https_even_without_proxy_headers(): void
    {
        $this->app['env'] = 'production';
        (new AppServiceProvider($this->app))->boot();

        $response = $this->getJson('/_test/scheme')->assertOk();

        $this->assertStringStartsWith('https://', $response->json('url'));
        $this->assertStringStartsWith('https://', $response->json('asset'));
        // Links built outside a request, e.g. in queued invitation mail, too.
        $this->assertStringStartsWith('https://', url('/p/abc'));
    }

    public function test_https_is_not_forced_outside_production(): void
    {
        URL::forceRootUrl('http://localhost');
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('http://', url('/p/abc'));
    }
}
