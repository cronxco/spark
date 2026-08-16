<?php

namespace App\Support;

use Illuminate\Support\Str;

class SafeMarkdown
{
    /**
     * Render stored Markdown without permitting raw HTML or unsafe link schemes.
     */
    public static function render(string $markdown): string
    {
        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
