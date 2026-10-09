<?php

namespace Tests\Feature;

use App\Zoo\Signature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ZooTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'zoo-test-key-0123456789abcdef';
    private const TRACE = '0f8fad5b-d9cb-469f-a165-70867728950e';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'zoo.webhook_secret' => self::KEY,
            'zoo.public_host' => 'laravel-jobs.s2.zoo.sorv.dev',
            'zoo.public_url' => 'https://laravel-jobs.s2.zoo.sorv.dev',
            'zoo.rails_url' => 'https://rails-queue.s1.zoo.sorv.dev',
            'zoo.panel_origins' => 'https://zoo-control.s1.zoo.sorv.dev, http://localhost:5173',
        ]);
    }

    private function signed(string $method, string $path, string $body = '', string $caller = 'rails-queue', ?int $t = null)
    {
        $server = ['HTTP_X_ZOO_SIGNATURE' => Signature::header(self::KEY, $caller, $method, $path, $body, $t), 'CONTENT_TYPE' => 'application/json'];

        return $this->call($method, $path, [], [], [], $server, $body);
    }

    public function test_health(): void
    {
        $this->getJson('/_zoo/health')->assertOk()
            ->assertJson(['name' => 'laravel-jobs', 'server' => 's2', 'release' => 'unknown', 'env' => 'local'])
            ->assertJsonStructure(['stack', 'uptime_s', 'started_at', 'build' => ['runtime']]);
    }

    public function test_cors_preflight_for_a_listed_origin(): void
    {
        $this->call('OPTIONS', '/_zoo/probe', [], [], [], ['HTTP_ORIGIN' => 'http://localhost:5173'])
            ->assertStatus(204)
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->assertHeader('Access-Control-Allow-Headers', 'Content-Type')
            ->assertHeader('Access-Control-Max-Age', '600')
            ->assertHeader('Vary', 'Origin');
        $this->get('/_zoo/health', ['Origin' => 'https://zoo-control.s1.zoo.sorv.dev'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://zoo-control.s1.zoo.sorv.dev');
    }

    public function test_cors_unlisted_origin_and_verify_get_no_headers(): void
    {
        $this->get('/_zoo/health', ['Origin' => 'https://evil.example'])->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->call('OPTIONS', '/_zoo/trace/'.self::TRACE, [], [], [], ['HTTP_ORIGIN' => 'https://evil.example'])
            ->assertHeaderMissing('Access-Control-Allow-Origin')->assertHeaderMissing('Access-Control-Allow-Methods');
        $this->get('/_zoo/verify', ['Origin' => 'http://localhost:5173'])->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_verify_accepts_a_signed_call(): void
    {
        $this->signed('GET', '/_zoo/verify')->assertOk()->assertExactJson([
            'ok' => true, 'name' => 'laravel-jobs', 'public_url' => 'https://laravel-jobs.s2.zoo.sorv.dev',
            'verified_by' => 'WEBHOOK_SECRET', 'key_fp' => '915a', 'caller' => 'rails-queue', 'server' => 's2', 'release' => 'unknown',
        ]);
    }

    public function test_verify_reasons(): void
    {
        $this->getJson('/_zoo/verify')->assertStatus(401)->assertExactJson(['ok' => false, 'error' => 'missing signature']);
        $this->getJson('/_zoo/verify', ['X-Zoo-Signature' => 'nope'])->assertStatus(401)->assertJson(['error' => 'bad format']);
        $this->signed('GET', '/_zoo/verify', '', 'rails-queue', time() - 301)->assertStatus(401)->assertJson(['error' => 'expired']);
        $this->signed('GET', '/_zoo/verify', '', 'mesh-shop')->assertStatus(401)->assertJson(['error' => 'unknown caller']);
        $sig = Signature::header('other-key', 'rails-queue', 'GET', '/_zoo/verify', '');
        $this->getJson('/_zoo/verify', ['X-Zoo-Signature' => $sig])->assertStatus(401)->assertJson(['error' => 'bad signature']);
        // The signature covers the query string.
        $sig = Signature::header(self::KEY, 'rails-queue', 'GET', '/_zoo/verify', '');
        $this->getJson('/_zoo/verify?x=1', ['X-Zoo-Signature' => $sig])->assertStatus(401)->assertJson(['error' => 'bad signature']);
    }

    public function test_trace_ids_are_validated(): void
    {
        $this->getJson('/_zoo/trace/not-a-uuid')->assertStatus(400);
        $this->getJson('/_zoo/trace/0F8FAD5B-D9CB-469F-A165-70867728950E')->assertStatus(400);
        $this->getJson('/_zoo/trace/'.self::TRACE)->assertOk()->assertExactJson(['trace' => self::TRACE, 'found' => false, 'hops' => []]);
    }

    public function test_signed_ack_records_a_hop(): void
    {
        $body = json_encode(['trace' => self::TRACE]);
        $this->signed('POST', '/api/acks', $body)->assertOk()->assertJson(['ok' => true]);
        $this->getJson('/_zoo/trace/'.self::TRACE)->assertOk()
            ->assertJson(['found' => true, 'hops' => [['step' => 'ack-received', 'detail' => 'signed ack from rails-queue']]]);
    }

    public function test_ack_refuses_unsigned_bad_trace_and_big_bodies(): void
    {
        $this->postJson('/api/acks', ['trace' => self::TRACE])->assertStatus(401);
        $this->signed('POST', '/api/acks', json_encode(['trace' => 'x']))->assertStatus(422);
        $big = json_encode(['trace' => self::TRACE, 'pad' => str_repeat('a', 70000)]);
        $this->signed('POST', '/api/acks', $big)->assertStatus(413);
    }

    public function test_chain_validates_and_rate_limits(): void
    {
        $this->postJson('/_zoo/chain/nope', ['trace' => self::TRACE])->assertStatus(404);
        $this->postJson('/_zoo/chain/ping-pong', ['trace' => 'bad'])->assertStatus(400);
        \Illuminate\Support\Facades\Queue::fake();
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/_zoo/chain/ping-pong', ['trace' => self::TRACE])->assertStatus(202)->assertExactJson(['trace' => self::TRACE, 'started' => true]);
        }
        $this->postJson('/_zoo/chain/ping-pong', ['trace' => self::TRACE])->assertStatus(429);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\PingRails::class, 10);
        $this->getJson('/_zoo/trace/'.self::TRACE)->assertJson(['found' => true, 'hops' => [['step' => 'queued']]]);
    }

    public function test_probe_names_missing_variables(): void
    {
        config(['zoo.webhook_secret' => '', 'database.connections.mysql.url' => null, 'app.key' => null]);
        $res = $this->getJson('/_zoo/probe')->assertOk()->assertJson(['ok' => false, 'name' => 'laravel-jobs']);
        $checks = collect($res->json('checks'))->keyBy('id');
        $this->assertSame('MYSQL_URL is not set', $checks['mysql']['error']);
        $this->assertSame('APP_KEY is not set', $checks['app-key']['error']);
        $this->assertSame(['RAILS_URL', 'WEBHOOK_SECRET'], $checks['peer:rails-queue']['env']);
        $this->assertSame('WEBHOOK_SECRET is not set', $checks['peer:rails-queue']['error']);
        $this->assertContains(['name' => 'WEBHOOK_SECRET', 'missing' => true, 'role' => 'verifies'], $res->json('vars'));
    }

    public function test_probe_refuses_an_ox_generated_app_key(): void
    {
        // ox's Generate: 32 random bytes as 43 URL-safe base64 characters.
        config(['app.key' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')]);
        $checks = collect($this->getJson('/_zoo/probe')->json('checks'))->keyBy('id');
        $this->assertFalse($checks['app-key']['ok']);
        $this->assertStringContainsString('key:generate --show', $checks['app-key']['error']);

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->app->forgetInstance('encrypter');
        $checks = collect($this->getJson('/_zoo/probe')->json('checks'))->keyBy('id');
        $this->assertTrue($checks['app-key']['ok']);
    }
}
