<?php

namespace App\Services\AI;

final readonly class ChatMessage
{
    /** @param 'user'|'assistant' $role */
    public function __construct(
        public string $role,
        public string $content,
    ) {}

    public static function user(string $content): self
    {
        return new self('user', $content);
    }

    public static function assistant(string $content): self
    {
        return new self('assistant', $content);
    }
}
