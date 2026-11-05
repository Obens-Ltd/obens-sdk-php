<?php

declare(strict_types=1);

namespace Obens\Sdk;

/** A fixed value in a mapper spec; see Mapper::constant(). */
final class Constant
{
    public function __construct(public readonly mixed $value)
    {
    }
}
