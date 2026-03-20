<?php

namespace App\Support\Help;

use Illuminate\Support\Str;

class MarkdownRenderer
{
    public function render(?string $markdown): string
    {
        $source = trim((string) $markdown);

        if ($source === '') {
            return '';
        }

        return (string) Str::markdown($source, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
};
