<?php

namespace Elastic\Apm\PhpAgent\Tests;

use Elastic\Apm\PhpAgent\Agent;
use Elastic\Apm\PhpAgent\Config;
use Elastic\Apm\PhpAgent\Exception\RuntimeException;
use Elastic\Apm\PhpAgent\Model\Context\DbContext;
use Elastic\Apm\PhpAgent\Model\Context\SpanContext;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class AgentTest extends TestCase
{
    private array $history = [];

    private function createAgent(array $queue, array $options = []): Agent
    {
        $handler = HandlerStack::create(new MockHandler($queue));
        $handler->push(Middleware::history($this->history));
        $client = new Client(['handler' => $handler] + $options);

        return new Agent(new Config('UnitTest', '10.0', 'https://apm.example.test/', 'test-token', client: $client));
    }

    public function testSendsCorrelatedEventsAsNdjson(): void
    {
        $agent = $this->createAgent([new Response(202)]);
        $id = '0123456789abcdef';
        $server = $_SERVER;
        try {
            $_SERVER['HTTPS'] = 'on';
            $_SERVER['HTTP_HOST'] = 'web.example.test';
            $_SERVER['REQUEST_URI'] = '/index?q=test';
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $agent->startTransaction('index', 'request', $id);
        } finally {
            $_SERVER = $server;
        }
        $agent->getTransaction()->setSampled(true);
        $outer = $agent->startTrace('outer', 'app');
        $inner = $agent->startTrace('query', 'db');
        $agent->stopTrace($inner->getId(), new SpanContext([
            'db' => new DbContext(['type' => 'sql', 'statement' => "SELECT\n1"]),
        ]));
        $agent->notifyException(new \Exception("invalid byte \xFF"));
        $agent->stopTrace();
        $agent->stopTransaction('HTTP 2xx');

        self::assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://apm.example.test/intake/v2/events', (string) $request->getUri());
        self::assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
        self::assertSame('application/x-ndjson', $request->getHeaderLine('Content-Type'));
        self::assertNotEmpty($request->getHeaderLine('User-Agent'));

        $lines = explode("\n", (string) $request->getBody());
        self::assertSame('', array_pop($lines));
        self::assertCount(5, $lines);
        [$metadata, $query, $error, $span, $transaction] = array_map(
            static fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines
        );
        $query = $query['span'];
        $span = $span['span'];
        $error = $error['error'];
        $transaction = $transaction['transaction'];

        self::assertSame('UnitTest', $metadata['metadata']['service']['name']);
        self::assertSame($id, $transaction['id']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $transaction['trace_id']);
        self::assertSame('HTTP 2xx', $transaction['result']);
        self::assertSame(2, $transaction['span_count']['started']);
        self::assertSame('web.example.test', $transaction['context']['request']['url']['hostname']);
        self::assertSame(443, $transaction['context']['request']['url']['port']);
        self::assertSame("SELECT\n1", $query['context']['db']['statement']);
        self::assertSame($id, $span['parent_id']);
        self::assertSame($outer->getId(), $query['parent_id']);
        self::assertSame($outer->getId(), $error['parent_id']);
        self::assertSame(['type' => 'request', 'sampled' => true], $error['transaction']);
        self::assertTrue(array_is_list($error['exception']['stacktrace']));
        self::assertSame("invalid byte \u{FFFD}", $error['exception']['message']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $span['id']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $error['id']);
        foreach ([$query, $span, $error] as $event) {
            self::assertSame($id, $event['transaction_id']);
            self::assertSame($transaction['trace_id'], $event['trace_id']);
            self::assertGreaterThan(0, $event['timestamp']);
        }

        $this->expectException(RuntimeException::class);
        $agent->getTransaction();
    }

    public function testUsesInjectedClientOptionsAndExplicitRequest(): void
    {
        $agent = $this->createAgent([new Response(503), new Response(204)], ['http_errors' => false]);
        $request = new Request('POST', 'https://apm.example.test/custom', [], 'explicit');

        self::assertFalse($agent->send($request, ['timeout' => 2]));
        self::assertTrue($agent->send($request));
        self::assertSame(2, $this->history[0]['options']['timeout']);
        self::assertSame('explicit', (string) $this->history[0]['request']->getBody());
    }

    public function testHttpExceptionPreservesBatchForRetry(): void
    {
        $agent = $this->createAgent([new Response(503), new Response(202)]);
        $agent->startTransaction('job', 'worker');
        $id = $agent->getTransaction()->getId();
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $id);

        try {
            $agent->stopTransaction();
            self::fail('Expected Guzzle to propagate HTTP 503');
        } catch (ServerException $error) {
            self::assertSame(503, $error->getResponse()->getStatusCode());
        }

        self::assertSame($id, $agent->getTransaction()->getId());
        self::assertTrue($agent->send());
        self::assertSame(
            (string) $this->history[0]['request']->getBody(),
            (string) $this->history[1]['request']->getBody()
        );
    }

    public function testPropagatesConnectionFailure(): void
    {
        $failure = new ConnectException('Connection failed', new Request('POST', 'https://apm.example.test/'));
        $agent = $this->createAgent([$failure]);
        $agent->startTransaction('job', 'worker');

        $this->expectExceptionObject($failure);
        $agent->stopTransaction();
    }
}
