<?php

it('keeps every HR form inside a modal', function (): void {
    $root = dirname(__DIR__, 3);
    $views = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/views/livewire/hr')),
        '/\.blade\.php$/',
    );

    expect($views)->not->toBeEmpty();

    foreach ($views as $view) {
        $source = file_get_contents($view->getPathname());
        $relativePath = str_replace($root.'/', '', $view->getPathname());
        preg_match_all(
            '/<flux:modal(?=\s|>)|<\/flux:modal>|<form(?=\s|>)/i',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $modalDepth = 0;

        foreach ($matches[0] as [$token, $offset]) {
            if (strcasecmp($token, '</flux:modal>') === 0) {
                $modalDepth--;
                expect($modalDepth)
                    ->toBeGreaterThanOrEqual(0, "Unexpected modal closing tag in {$relativePath}.");

                continue;
            }

            if (str_starts_with(strtolower($token), '<flux:modal')) {
                $modalDepth++;

                continue;
            }

            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
            expect($modalDepth)
                ->toBeGreaterThan(0, "Floating HR form found in {$relativePath}:{$line}.");
        }

        expect($modalDepth)
            ->toBe(0, "Unclosed modal in {$relativePath}.");
    }
});
