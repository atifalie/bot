<?php

namespace App\Bot\Validation;

readonly class ValidationResult
{
    public function __construct(
        public bool $passed,
        public array $failedChecks = [],
    ) {}

    public function summary(): string
    {
        if ($this->passed) {
            return 'All validation gates passed.';
        }

        return 'Validation FAILED: '.implode(', ', $this->failedChecks);
    }
}
