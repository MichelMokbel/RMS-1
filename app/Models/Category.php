<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Category extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'parent_id',
    ];

    /**
     * Parent category relationship.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Child categories relationship.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function ancestorsAndSelf(): Collection
    {
        $nodes = collect([$this]);
        $parent = $this->parent;

        while ($parent) {
            $nodes->prepend($parent);
            $parent = $parent->parent;
        }

        return $nodes;
    }

    public function fullName(string $separator = ' > '): string
    {
        return $this->ancestorsAndSelf()
            ->pluck('name')
            ->filter()
            ->implode($separator);
    }

    public function descendantIdsAndSelf(): Collection
    {
        $ids = collect([(int) $this->id]);
        $pending = [(int) $this->id];

        while ($pending !== []) {
            $children = static::query()
                ->whereIn('parent_id', $pending)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values();

            $newChildren = $children
                ->reject(fn (int $id) => $ids->contains($id))
                ->values();

            if ($newChildren->isEmpty()) {
                break;
            }

            $ids = $ids->concat($newChildren)->values();
            $pending = $newChildren->all();
        }

        return $ids;
    }

    /**
     * Scope for ordering categories alphabetically.
     */
    public function scopeAlphabetical(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    /**
     * Detect if assigning the given parent would create a cycle.
     */
    public function wouldCreateCycle(?int $candidateParentId): bool
    {
        if (! $candidateParentId || $candidateParentId === $this->id) {
            return $candidateParentId === $this->id;
        }

        $parent = static::find($candidateParentId);

        while ($parent) {
            if ($parent->id === $this->id) {
                return true;
            }

            $parent = $parent->parent;
        }

        return false;
    }

    /**
     * Determine whether the category is referenced elsewhere.
     */
    public function isInUse(): bool
    {
        return $this->children()->exists();
    }
}
