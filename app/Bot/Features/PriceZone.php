<?php

namespace App\Bot\Features;

readonly class PriceZone
{
    public function __construct(
        public float $lo,
        public float $hi,
        public float $mid,
        public int $touches,
    ) {}
}
