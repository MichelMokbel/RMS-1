<?php

namespace App\Services\Ai;

interface AiProviderInterface
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function generateStructured(array $messages, array $schema): array;
}
