<?php

namespace App\Services\HR\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

trait FiltersModelAttributes
{
    /**
     * Keep service payloads forward-compatible while rejecting fields that are
     * not present in the installed HR schema.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function modelAttributes(Model|string $model, array $attributes): array
    {
        $instance = is_string($model) ? new $model : $model;
        $columns = Schema::getColumnListing($instance->getTable());

        return array_intersect_key($attributes, array_flip($columns));
    }
}
