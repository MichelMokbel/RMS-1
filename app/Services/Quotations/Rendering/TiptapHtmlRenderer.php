<?php

namespace App\Services\Quotations\Rendering;

final class TiptapHtmlRenderer
{
    /**
     * @param  array<string, callable(array, string): string>  $nodeRenderers
     */
    public function render(mixed $document, string $direction = 'auto', array $nodeRenderers = []): string
    {
        if (! is_array($document)) {
            return '';
        }

        return $this->node($document, $direction, 0, $nodeRenderers);
    }

    /**
     * @param  array<string, callable(array, string): string>  $nodeRenderers
     */
    private function node(array $node, string $direction, int $depth, array $nodeRenderers): string
    {
        if ($depth > 24) {
            return '';
        }

        $type = $node['type'] ?? null;
        $direction = $this->direction($node['attrs']['direction'] ?? null, $direction);
        if (is_string($type) && isset($nodeRenderers[$type])) {
            return (string) $nodeRenderers[$type]($node, $direction);
        }

        $children = $this->children($node['content'] ?? [], $direction, $depth + 1, $nodeRenderers);

        return match ($type) {
            'doc' => $children,
            'paragraph' => $this->container('p', $node, $children, $direction),
            'heading' => $this->heading($node, $children, $direction),
            'bulletList' => $this->container('ul', $node, $children, $direction),
            'orderedList' => $this->container('ol', $node, $children, $direction),
            'listItem' => $this->container('li', $node, $children, $direction),
            'blockquote' => $this->container('blockquote', $node, $children, $direction),
            'hardBreak' => '<br />',
            'text' => $this->textNode($node),
            default => '',
        };
    }

    /**
     * @param  array<string, callable(array, string): string>  $nodeRenderers
     */
    private function children(mixed $children, string $direction, int $depth, array $nodeRenderers): string
    {
        if (! is_array($children)) {
            return '';
        }

        $html = '';
        foreach (array_slice(array_values($children), 0, 2000) as $child) {
            if (is_array($child)) {
                $html .= $this->node($child, $direction, $depth, $nodeRenderers);
            }
        }

        return $html;
    }

    private function heading(array $node, string $children, string $direction): string
    {
        $level = min(3, max(1, (int) ($node['attrs']['level'] ?? 2)));

        return $this->container('h'.$level, $node, $children, $direction);
    }

    private function container(string $tag, array $node, string $children, string $direction): string
    {
        $alignment = $node['attrs']['textAlign'] ?? null;
        $alignment = in_array($alignment, ['left', 'center', 'right', 'justify'], true) ? $alignment : null;
        $styles = ['direction:'.e($direction)];
        if ($alignment !== null) {
            $styles[] = 'text-align:'.e($alignment);
        }

        $attributes = ' dir="'.e($direction).'" style="'.implode(';', $styles).'"';

        return "<{$tag}{$attributes}>{$children}</{$tag}>";
    }

    private function textNode(array $node): string
    {
        $text = e(is_scalar($node['text'] ?? null) ? (string) $node['text'] : '');
        $marks = is_array($node['marks'] ?? null) ? array_values($node['marks']) : [];

        foreach (array_reverse(array_slice($marks, 0, 12)) as $mark) {
            if (! is_array($mark)) {
                continue;
            }

            $text = match ($mark['type'] ?? null) {
                'bold' => '<strong>'.$text.'</strong>',
                'italic' => '<em>'.$text.'</em>',
                'underline' => '<u>'.$text.'</u>',
                'link' => $this->link($text, $mark['attrs']['href'] ?? null),
                default => $text,
            };
        }

        return $text;
    }

    private function link(string $text, mixed $href): string
    {
        if (! is_string($href) || strlen($href) > 2048) {
            return $text;
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)) {
            return $text;
        }

        return '<a href="'.e($href).'" rel="noopener noreferrer">'.$text.'</a>';
    }

    private function direction(mixed $value, string $fallback): string
    {
        return in_array($value, ['auto', 'ltr', 'rtl'], true) ? $value : $fallback;
    }
}
