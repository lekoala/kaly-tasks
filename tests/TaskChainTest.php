<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use InvalidArgumentException;
use Kaly\Tasks\EncodedTask;
use Kaly\Tasks\Store\TaskChain;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class TaskChainTest extends TestCase
{
    /** @return iterable<string,array{string}> */
    public static function malformedChains(): iterable
    {
        yield 'invalid JSON' => ['['];
        yield 'object' => ['{}'];
        yield 'null' => ['null'];
        yield 'scalar step' => ['[42]'];
        yield 'missing fields' => ['[{"name":"a"}]'];
        yield 'wrong field type' => ['[{"name":"a","queue":"default","payload":{}}]'];
        yield 'unknown field' => ['[{"name":"a","queue":"default","payload":"{}","extra":true}]'];
        yield 'invalid payload' => ['[{"name":"a","queue":"default","payload":"["}]'];
        yield 'nul name' => ['[{"name":"a\\u0000","queue":"default","payload":"{}"}]'];
    }

    #[DataProvider('malformedChains')]
    public function testMalformedStoredChainIsRejected(string $json): void
    {
        $this->expectException(UnexpectedValueException::class);
        TaskChain::decode($json);
    }

    public function testNoContinuationUsesSqlNull(): void
    {
        $this->assertNull(TaskChain::encode([]));
        $this->assertSame([], TaskChain::decode(null));
        $this->assertSame([], TaskChain::decode('[]'));
    }

    public function testPublicCodecUsesFlatNamedStepsAndPreservesPayloadText(): void
    {
        $tasks = [new EncodedTask('mail.send', 'mail', '{ "id":42 }')];
        $json = '[{"name":"mail.send","queue":"mail","payload":"{ \\"id\\":42 }"}]';
        $this->assertSame($json, TaskChain::encode($tasks));
        $this->assertEquals($tasks, TaskChain::decode($json));
    }

    public function testPayloadTextAndMaximumRegistryDepthArePreserved(): void
    {
        // The registry accepts 512 JSON levels, including the payload object.
        $payload = '{"data":' . str_repeat('[', 511) . '0' . str_repeat(']', 511) . '}';
        $steps = [new EncodedTask('a', 'courriels-é', $payload)];
        $this->assertEquals($steps, TaskChain::decode(TaskChain::encode($steps)));
    }

    public function testPayloadKeysThatAreInvalidObjectPropertiesAreAccepted(): void
    {
        // TaskRegistry decodes payloads as arrays, so "\0key" is a valid payload key.
        $steps = [new EncodedTask('a', 'default', '{"\\u0000key":"value"}')];
        $this->assertEquals($steps, TaskChain::decode(TaskChain::encode($steps)));
    }

    public function testInvalidUtf8MetadataIsRejectedBeforeStorage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TaskChain::encode([new EncodedTask("a\xFF", 'default', '{}')]);
    }
}
