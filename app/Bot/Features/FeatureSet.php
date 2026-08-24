<?php

namespace App\Bot\Features;

/**
 * Named-column store for computed indicators — the typed equivalent of the
 * pandas "feature dataframe" without any dataframe dependency.
 */
class FeatureSet
{
    /** @var array<string, list<float>> */
    protected array $columns = [];

    public function __construct(protected int $length = 0) {}

    public function length(): int
    {
        return $this->length;
    }

    /** @param list<float> $values */
    public function set(string $name, array $values): void
    {
        $this->columns[$name] = $values;
    }

    public function has(string $name): bool
    {
        return isset($this->columns[$name]);
    }

    /** @return list<float> */
    public function column(string $name): array
    {
        return $this->columns[$name] ?? throw new \InvalidArgumentException("Unknown feature column: {$name}");
    }

    /** Latest raw value (may be NAN when the window is not yet filled). */
    public function last(string $name): ?float
    {
        if (! isset($this->columns[$name]) || $this->columns[$name] === []) {
            return null;
        }

        return $this->columns[$name][count($this->columns[$name]) - 1];
    }
}
