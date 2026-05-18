<?php

declare(strict_types=1);

namespace Drupal\Tests\agtp_drupal\Unit;

use Agtp\AgtpEndpoint;
use Agtp\EndpointContext;
use Agtp\EndpointResponse;
use Agtp\HandlerRegistry;
use Drupal\agtp_drupal\Registry\AgtpHandlerCollector;
use PHPUnit\Framework\TestCase;

/**
 * Pure-PHP test for AgtpHandlerCollector.
 *
 * The collector is a thin loop over an iterable — there is nothing
 * Drupal-specific in the class itself, so a real Drupal install is
 * not required. The tagged-iterator integration with Drupal's DI is
 * Drupal's responsibility, exercised separately by Kernel tests or
 * by manual installation.
 */
final class AgtpHandlerCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        HandlerRegistry::resetDefault();
    }

    protected function tearDown(): void
    {
        HandlerRegistry::resetDefault();
    }

    public function testCollectsSingleHandler(): void
    {
        $collector = new AgtpHandlerCollector([new CollectorTestHandlerA()]);
        $registered = $collector->collect(HandlerRegistry::default());

        $this->assertCount(1, $registered);
        $this->assertSame('QUERY', $registered[0]->method);
        $this->assertSame('/a', $registered[0]->path);
    }

    public function testCollectsMultipleHandlers(): void
    {
        $collector = new AgtpHandlerCollector([
            new CollectorTestHandlerA(),
            new CollectorTestHandlerB(),
        ]);
        $registered = $collector->collect(HandlerRegistry::default());

        $this->assertCount(2, $registered);
        $methods = array_map(fn($r) => $r->method, $registered);
        sort($methods);
        $this->assertSame(['BOOK', 'QUERY'], $methods);
    }

    public function testCollectsMultipleMethodsFromOneHandler(): void
    {
        $collector = new AgtpHandlerCollector([new CollectorTestMultiHandler()]);
        $registered = $collector->collect(HandlerRegistry::default());

        $this->assertCount(2, $registered);
    }

    public function testEmptyIteratorReturnsEmptyList(): void
    {
        $collector = new AgtpHandlerCollector([]);
        $registered = $collector->collect(HandlerRegistry::default());

        $this->assertSame([], $registered);
    }

    public function testHandlerWithoutAttributeIsIgnored(): void
    {
        $collector = new AgtpHandlerCollector([new CollectorTestHandlerWithoutAttributes()]);
        $registered = $collector->collect(HandlerRegistry::default());

        $this->assertSame([], $registered);
    }
}

final class CollectorTestHandlerA
{
    #[AgtpEndpoint(method: 'QUERY', path: '/a')]
    public function a(EndpointContext $ctx): EndpointResponse
    {
        return new EndpointResponse(body: []);
    }
}

final class CollectorTestHandlerB
{
    #[AgtpEndpoint(method: 'BOOK', path: '/b', requiredScopes: ['booking:write'])]
    public function b(EndpointContext $ctx): EndpointResponse
    {
        return new EndpointResponse(body: []);
    }
}

final class CollectorTestMultiHandler
{
    #[AgtpEndpoint(method: 'QUERY', path: '/list')]
    public function list(EndpointContext $ctx): EndpointResponse
    {
        return new EndpointResponse(body: []);
    }

    #[AgtpEndpoint(method: 'DESCRIBE', path: '/one')]
    public function describe(EndpointContext $ctx): EndpointResponse
    {
        return new EndpointResponse(body: []);
    }
}

final class CollectorTestHandlerWithoutAttributes
{
    public function notAnEndpoint(EndpointContext $ctx): EndpointResponse
    {
        return new EndpointResponse(body: []);
    }
}
