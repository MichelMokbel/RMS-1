<?php

namespace App\Services\Help;

use App\Models\HelpArticle;
use App\Models\HelpArticleAsset;
use App\Models\HelpArticleStep;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class HelpCaptureService
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function scenarios(): Collection
    {
        return collect(config('help.capture_scenarios', []))
            ->filter(fn (array $scenario): bool => (bool) ($scenario['enabled'] ?? true))
            ->map(function (array $scenario, string $key): array {
                $article = HelpArticle::query()->where('slug', $scenario['article'])->first();
                $step = $article?->steps()->where('sort_order', (int) ($scenario['step'] ?? 0))->first();
                $user = User::query()->where('username', $scenario['user'])->first();

                return [
                    'key' => $key,
                    'article' => $article,
                    'step' => $step,
                    'user' => $user,
                    'route' => $scenario['route'] ?? null,
                    'route_params' => $scenario['route_params'] ?? [],
                    'viewport' => $scenario['viewport'] ?? ['width' => 1440, 'height' => 960],
                    'wait_for' => $scenario['wait_for'] ?? null,
                    'actions' => array_values($scenario['actions'] ?? []),
                    'mask_selectors' => array_values($scenario['mask_selectors'] ?? []),
                    'full_page' => (bool) ($scenario['full_page'] ?? false),
                    'output' => $scenario['output'] ?? null,
                ];
            })
            ->values();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildManifest(): array
    {
        return $this->scenarios()
            ->map(function (array $scenario): array {
                /** @var User|null $user */
                $user = $scenario['user'];
                /** @var HelpArticleStep|null $step */
                $step = $scenario['step'];
                $output = (string) $scenario['output'];
                $routeParams = $this->resolveRouteParams((array) ($scenario['route_params'] ?? []));

                if (! $user || ! $step || ! \Route::has((string) $scenario['route'])) {
                    throw new \RuntimeException('Invalid help capture scenario: '.$scenario['key']);
                }

                return [
                    'key' => $scenario['key'],
                    'login_url' => route('login'),
                    'url' => route((string) $scenario['route'], $routeParams),
                    'username' => $user->username,
                    'password' => 'password',
                    'actions' => $scenario['actions'],
                    'wait_for' => $scenario['wait_for'],
                    'mask_selectors' => $scenario['mask_selectors'],
                    'viewport' => $scenario['viewport'],
                    'full_page' => $scenario['full_page'],
                    'output_relative_path' => $output,
                    'output_absolute_path' => Storage::disk('public')->path($output),
                    'asset_key' => $step->image_key,
                ];
            })
            ->all();
    }

    public function syncCapturedAssets(): int
    {
        $synced = 0;

        foreach ($this->scenarios() as $scenario) {
            /** @var HelpArticleStep|null $step */
            $step = $scenario['step'];
            $relativePath = (string) $scenario['output'];
            $absolutePath = Storage::disk('public')->path($relativePath);

            if (! $step || ! $step->image_key || ! is_file($absolutePath)) {
                continue;
            }

            $asset = HelpArticleAsset::query()->where('key', $step->image_key)->first();
            if (! $asset) {
                continue;
            }

            $asset->forceFill([
                'disk' => 'public',
                'path' => $relativePath,
                'checksum' => sha1_file($absolutePath) ?: null,
                'captured_at' => now(),
                'meta' => array_merge($asset->meta ?? [], [
                    'scenario_key' => $scenario['key'],
                    'source_route' => $scenario['route'],
                    'source_route_params' => $scenario['route_params'] ?? [],
                    'viewport' => $scenario['viewport'],
                ]),
            ])->save();

            $synced++;
        }

        return $synced;
    }

    /**
     * @return array<int, string>
     */
    public function validateManifest(): array
    {
        $errors = [];

        foreach ($this->scenarios() as $scenario) {
            if (! $scenario['article']) {
                $errors[] = 'Missing article for scenario '.$scenario['key'];
            }

            if (! $scenario['step']) {
                $errors[] = 'Missing step for scenario '.$scenario['key'];
            }

            if (! $scenario['user']) {
                $errors[] = 'Missing demo user for scenario '.$scenario['key'];
            }

            if (! \Route::has((string) ($scenario['route'] ?? ''))) {
                $errors[] = 'Missing route for scenario '.$scenario['key'];
            }

            if (! Arr::get($scenario, 'output')) {
                $errors[] = 'Missing output path for scenario '.$scenario['key'];
            }

            try {
                $this->resolveRouteParams((array) ($scenario['route_params'] ?? []));
            } catch (\Throwable $exception) {
                $errors[] = 'Invalid route params for scenario '.$scenario['key'].': '.$exception->getMessage();
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $routeParams
     * @return array<string, mixed>
     */
    private function resolveRouteParams(array $routeParams): array
    {
        return collect($routeParams)
            ->map(fn ($value) => $this->resolveRouteParamValue($value))
            ->all();
    }

    private function resolveRouteParamValue(mixed $value): mixed
    {
        if (! is_array($value) || ! isset($value['lookup_model'])) {
            return $value;
        }

        $modelClass = (string) $value['lookup_model'];
        $lookupColumn = (string) ($value['lookup_column'] ?? 'id');
        $lookupValue = $value['lookup_value'] ?? null;
        $routeKey = (string) ($value['route_key'] ?? 'id');

        if ($modelClass === '' || ! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            throw new \RuntimeException('Unsupported lookup model.');
        }

        /** @var Model|null $record */
        $record = $modelClass::query()->where($lookupColumn, $lookupValue)->first();

        if (! $record) {
            throw new \RuntimeException("No lookup record found for {$modelClass}.{$lookupColumn}.");
        }

        return data_get($record, $routeKey);
    }
}
