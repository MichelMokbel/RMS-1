<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HelpArticle extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'module',
        'summary',
        'body_markdown',
        'prerequisites',
        'keywords',
        'target_route',
        'target_route_params',
        'locale',
        'status',
        'visibility_mode',
        'allowed_roles',
        'allowed_permissions',
        'sort_order',
        'meta',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'prerequisites' => 'array',
            'keywords' => 'array',
            'target_route_params' => 'array',
            'allowed_roles' => 'array',
            'allowed_permissions' => 'array',
            'meta' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function steps(): HasMany
    {
        return $this->hasMany(HelpArticleStep::class, 'article_id')->orderBy('sort_order')->orderBy('id');
    }

    public function faqs(): HasMany
    {
        return $this->hasMany(HelpArticleFaq::class, 'article_id')->orderBy('sort_order')->orderBy('id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(HelpArticleAsset::class, 'article_id')->orderBy('id');
    }

    public function heroAsset(): HasOne
    {
        return $this->hasOne(HelpArticleAsset::class, 'article_id')->oldestOfMany();
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isVisibleTo(?User $user): bool
    {
        if (! $user || ! $this->isPublished()) {
            return false;
        }

        if (($this->visibility_mode ?? 'all') === 'all') {
            return true;
        }

        $allowedRoles = collect($this->allowed_roles ?? [])->filter()->values();
        $allowedPermissions = collect($this->allowed_permissions ?? [])->filter()->values();

        if ($allowedRoles->isEmpty() && $allowedPermissions->isEmpty()) {
            return true;
        }

        if ($allowedRoles->isNotEmpty() && $user->hasAnyRole($allowedRoles->all())) {
            return true;
        }

        foreach ($allowedPermissions as $permission) {
            if ($user->can((string) $permission)) {
                return true;
            }
        }

        return false;
    }

    public function targetUrl(): ?string
    {
        if (! $this->target_route || ! \Route::has($this->target_route)) {
            return null;
        }

        try {
            return route($this->target_route, $this->target_route_params ?? []);
        } catch (\Throwable) {
            return null;
        }
    }
};
