<?php
declare(strict_types=1);

namespace RadioWebsite\Contracts;

interface RadioSourceInterface
{
    public function playbackInfo(): array;

    public function recentlyPlayed(int $limit = 5): array;

    /** @return array{content:string, content_type:string}|null */
    public function artwork(string $which = 'current'): ?array;
}
