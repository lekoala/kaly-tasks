<?php

declare(strict_types=1);

namespace Kaly\Tasks\Store;

use InvalidArgumentException;
use JsonException;
use Kaly\Tasks\EncodedTask;
use UnexpectedValueException;

/**
 * The public codec for stored continuations, shared by SQL and custom stores.
 *
 * The JSON is a flat list of objects with name, queue and payload strings.
 * Payload text is preserved verbatim; no PHP class names are persisted.
 * Continuation steps cannot carry deduplication keys or nested continuations.
 */
final class TaskChain
{
    // TaskRegistry encodes at depth 512; json_decode counts one additional level.
    private const PAYLOAD_DECODE_DEPTH = 513;

    /**
     * @param list<EncodedTask> $tasks
     * @return string|null JSON for storage, or SQL NULL for an empty continuation
     * @throws InvalidArgumentException If any continuation step is invalid
     */
    public static function encode(array $tasks): ?string
    {
        if (!array_is_list($tasks)) {
            throw new InvalidArgumentException('A task continuation must be a list');
        }
        if ($tasks === []) {
            return null;
        }
        $steps = [];
        foreach ($tasks as $task) {
            if (!$task instanceof EncodedTask || $task->deduplicationKey !== null || $task->chain !== []) {
                throw new InvalidArgumentException('Continuation steps must be encoded tasks without deduplication keys or nested chains');
            }
            StoreValues::assertNoNul($task->name, 'The task name');
            StoreValues::assertNoNul($task->queue, 'The queue name');
            if ($task->name === '' || $task->queue === '') {
                throw new InvalidArgumentException('Continuation steps need a task name and a queue');
            }
            try {
                // Decode like TaskRegistry: objects may have keys that stdClass rejects, such as "\0key"
                json_decode($task->payload, true, self::PAYLOAD_DECODE_DEPTH, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new InvalidArgumentException('A continuation payload must be a JSON object', 0, $error);
            }
            if (!str_starts_with(ltrim($task->payload), '{')) {
                throw new InvalidArgumentException('A continuation payload must be a JSON object');
            }
            $steps[] = ['name' => $task->name, 'queue' => $task->queue, 'payload' => $task->payload];
        }
        try {
            return json_encode($steps, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('The task continuation cannot be encoded', 0, $error);
        }
    }

    /**
     * @return list<EncodedTask>
     * @throws UnexpectedValueException If the stored continuation is malformed
     */
    public static function decode(?string $json): array
    {
        if ($json === null) {
            return [];
        }
        try {
            $steps = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($steps) || !array_is_list($steps) || !str_starts_with(ltrim($json), '[')) {
                throw new UnexpectedValueException('The stored task continuation must be a JSON list');
            }
            $tasks = [];
            foreach ($steps as $step) {
                if (
                    !is_array($step)
                    || count($step) !== 3
                    || !is_string($step['name'] ?? null)
                    || !is_string($step['queue'] ?? null)
                    || !is_string($step['payload'] ?? null)
                ) {
                    throw new UnexpectedValueException('The stored task continuation has an invalid step');
                }
                $tasks[] = new EncodedTask($step['name'], $step['queue'], $step['payload']);
            }
            self::encode($tasks);
            return $tasks;
        } catch (JsonException|InvalidArgumentException $error) {
            throw new UnexpectedValueException('The stored task continuation is invalid', 0, $error);
        }
    }
}
