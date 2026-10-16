<?php

declare(strict_types=1);

namespace Kaly\Tasks;

use BackedEnum;
use InvalidArgumentException;
use JsonException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;
use UnexpectedValueException;

/**
 * The persistable task types: a whitelist mapping stable names to classes.
 *
 * A persistable task stores its constructor arguments as JSON. Every
 * constructor parameter must be a public promoted property typed `int`,
 * `float`, `string`, `bool`, `array` (of scalars, null and arrays) or a backed
 * enum, nullable or not. Registration validates this once; nothing else is
 * ever stored or rebuilt, and no PHP class name is persisted.
 *
 * Stored payloads are a public contract until every stored task has been
 * processed or migrated: adding an optional parameter is compatible, renaming
 * or retyping one is not. Register an incompatible change under a new name.
 */
final class TaskRegistry
{
    private const NAME_PATTERN = '/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/';
    private const SCALAR_TYPES = ['int', 'float', 'string', 'bool', 'array'];
    // The JSON nesting limit, payload object included
    private const MAX_DEPTH = 512;

    /**
     * @var array<string,TaskType> By name
     */
    private array $byName = [];

    /**
     * @var array<class-string,TaskType>
     */
    private array $byClass = [];

    /**
     * @param class-string $class
     * @param string|null $deduplicateBy A non-nullable string or int parameter: a task whose
     *  value is already stored under this name is not stored again
     */
    public function register(
        string $name,
        string $class,
        string $queue = 'default',
        RetryPolicy $retry = new RetryPolicy(),
        ?string $deduplicateBy = null,
    ): self {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException("Task name '{$name}' must be lowercase words separated by dots, dashes or underscores");
        }
        if (isset($this->byName[$name])) {
            throw new InvalidArgumentException("Task name '{$name}' is already registered");
        }
        if (isset($this->byClass[$class])) {
            throw new InvalidArgumentException("Task {$class} is already registered as '{$this->byClass[$class]->name}'");
        }
        if ($queue === '') {
            throw new InvalidArgumentException("Task '{$name}' needs a queue name");
        }

        $type = new TaskType($name, $class, $queue, $retry, self::parameters($class), $deduplicateBy);
        if ($deduplicateBy !== null && !self::isDeduplicationParameter($type, $deduplicateBy)) {
            throw new InvalidArgumentException(
                "Task {$class} deduplicates by '{$deduplicateBy}', which must be a non-nullable string or int parameter",
            );
        }

        $this->byName[$name] = $type;
        $this->byClass[$class] = $type;
        return $this;
    }

    public function type(string $name): ?TaskType
    {
        return $this->byName[$name] ?? null;
    }

    /**
     * @throws InvalidArgumentException If the task class is not registered.
     */
    public function encode(object $task): EncodedTask
    {
        $type = $this->byClass[$task::class] ?? null;
        if ($type === null) {
            throw new InvalidArgumentException('Task ' . $task::class . ' is not registered');
        }

        $data = [];
        foreach ($type->parameters as $parameter => $_) {
            $value = $task->{$parameter};
            if ($value instanceof BackedEnum) {
                $value = $value->value;
            } elseif (is_array($value)) {
                self::assertPlainArray($value, $task::class, $parameter, 2);
            }
            $data[$parameter] = $value;
        }

        try {
            $payload = json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                self::MAX_DEPTH,
            );
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Task ' . $task::class . " cannot be encoded: {$e->getMessage()}", 0, $e);
        }

        $key = $type->deduplicateBy === null ? null : $data[$type->deduplicateBy];
        return new EncodedTask($type->name, $type->queue, $payload, is_int($key) || is_string($key) ? (string) $key : null);
    }

    /**
     * @throws UnexpectedValueException If the name is unknown or the payload does not match the class.
     */
    public function decode(string $name, string $payload): object
    {
        $type = $this->byName[$name] ?? null;
        if ($type === null) {
            throw new UnexpectedValueException("Task '{$name}' is not registered");
        }

        try {
            // json_decode() counts one level more than json_encode() for the same value
            $data = json_decode($payload, true, self::MAX_DEPTH + 1, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnexpectedValueException("Task '{$name}' has an invalid payload: {$e->getMessage()}", 0, $e);
        }
        if (!is_array($data) || $data !== [] && array_is_list($data)) {
            throw new UnexpectedValueException("Task '{$name}' payload must be a JSON object");
        }

        $unknown = array_diff(array_keys($data), array_keys($type->parameters));
        if ($unknown !== []) {
            throw new UnexpectedValueException("Task '{$name}' payload has unknown keys: " . implode(', ', $unknown));
        }

        $arguments = [];
        foreach ($type->parameters as $parameter => $definition) {
            if (!array_key_exists($parameter, $data)) {
                if ($definition['optional']) {
                    continue;
                }
                throw new UnexpectedValueException("Task '{$name}' payload misses '{$parameter}'");
            }
            $arguments[$parameter] = self::decodeValue($name, $parameter, $definition, $data[$parameter]);
        }

        try {
            return new $type->class(...$arguments);
        } catch (Throwable $e) {
            throw new UnexpectedValueException("Task '{$name}' cannot be rebuilt: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Checks a stored value against its declared type, independently of the
     * caller strict_types mode.
     *
     * @param array{optional:bool,nullable:bool,enum:class-string<BackedEnum>|null,scalar:string|null} $definition
     */
    private static function decodeValue(string $name, string $parameter, array $definition, mixed $value): mixed
    {
        if ($value === null) {
            if (!$definition['nullable']) {
                throw new UnexpectedValueException("Task '{$name}' payload has null for non-nullable '{$parameter}'");
            }
            return null;
        }

        $enum = $definition['enum'];
        if ($enum !== null) {
            $case = is_int($value) || is_string($value) ? $enum::tryFrom($value) : null;
            if ($case === null) {
                throw new UnexpectedValueException("Task '{$name}' payload has an invalid {$enum} for '{$parameter}'");
            }
            return $case;
        }

        $valid = match ($definition['scalar']) {
            'int' => is_int($value),
            // JSON may store a whole float as an integer
            'float' => is_float($value) || is_int($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            default => is_array($value),
        };
        if (!$valid) {
            throw new UnexpectedValueException(
                "Task '{$name}' payload has " . get_debug_type($value) . " for {$definition['scalar']} '{$parameter}'",
            );
        }
        return $definition['scalar'] === 'float' && is_int($value) ? (float) $value : $value;
    }

    /**
     * @param class-string $class
     * @return array<string,array{optional:bool,nullable:bool,enum:class-string<BackedEnum>|null,scalar:string|null}>
     */
    private static function parameters(string $class): array
    {
        if (!class_exists($class)) {
            throw new InvalidArgumentException("Task {$class} does not exist");
        }
        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new InvalidArgumentException("Task {$class} cannot be instantiated");
        }
        if (!$reflection->hasMethod('__invoke') || !$reflection->getMethod('__invoke')->isPublic()) {
            throw new InvalidArgumentException("Task {$class} is not invocable");
        }

        $parameters = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $parameters[$parameter->getName()] = self::parameter($class, $parameter);
        }
        return $parameters;
    }

    /**
     * @param class-string $class
     * @return array{optional:bool,nullable:bool,enum:class-string<BackedEnum>|null,scalar:string|null}
     */
    private static function parameter(string $class, ReflectionParameter $parameter): array
    {
        $name = $parameter->getName();
        if (!$parameter->isPromoted() || $parameter->isVariadic()) {
            throw new InvalidArgumentException("Task {$class} parameter '{$name}' must be a promoted property");
        }
        if (!$parameter->getDeclaringClass()?->getProperty($name)->isPublic()) {
            throw new InvalidArgumentException("Task {$class} parameter '{$name}' must be public");
        }

        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType) {
            throw new InvalidArgumentException("Task {$class} parameter '{$name}' must have a single scalar, array or backed enum type");
        }
        $typeName = $type->getName();
        if ($type->isBuiltin()) {
            if (!in_array($typeName, self::SCALAR_TYPES, true)) {
                throw new InvalidArgumentException("Task {$class} parameter '{$name}' cannot be stored as {$typeName}");
            }
            return ['optional' => $parameter->isOptional(), 'nullable' => $type->allowsNull(), 'enum' => null, 'scalar' => $typeName];
        }
        if (!is_subclass_of($typeName, BackedEnum::class)) {
            throw new InvalidArgumentException("Task {$class} parameter '{$name}' cannot be stored as {$typeName}");
        }
        return ['optional' => $parameter->isOptional(), 'nullable' => $type->allowsNull(), 'enum' => $typeName, 'scalar' => null];
    }

    private static function isDeduplicationParameter(TaskType $type, string $parameter): bool
    {
        $definition = $type->parameters[$parameter] ?? null;
        return $definition !== null && !$definition['nullable'] && in_array($definition['scalar'], ['string', 'int'], true);
    }

    /**
     * The depth bound also stops on a recursive array, before json_encode().
     *
     * @param array<mixed> $value
     */
    private static function assertPlainArray(array $value, string $class, string $parameter, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException(
                "Task {$class} parameter '{$parameter}' nests arrays deeper than the JSON limit of " . self::MAX_DEPTH . ' levels',
            );
        }
        foreach ($value as $item) {
            if (is_array($item)) {
                self::assertPlainArray($item, $class, $parameter, $depth + 1);
            } elseif ($item !== null && !is_scalar($item)) {
                throw new InvalidArgumentException("Task {$class} parameter '{$parameter}' must only contain scalars, null and arrays");
            }
        }
    }
}
