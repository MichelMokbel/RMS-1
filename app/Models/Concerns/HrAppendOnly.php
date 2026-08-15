<?php

namespace App\Models\Concerns;

trait HrAppendOnly
{
    protected static function bootHrAppendOnly(): void
    {
        static::updating(fn () => throw new \LogicException(class_basename(static::class).' records are append-only.'));
        static::deleting(fn () => throw new \LogicException(class_basename(static::class).' records are append-only.'));
    }
}
