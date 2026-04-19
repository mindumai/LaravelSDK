<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Minimal base Repository stand-in for the fixture tree.
 * Mimics the Prettus L5 Repository pattern's surface.
 */
abstract class BaseRepository
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): mixed
    {
        return null;
    }

    public function find(mixed $id): mixed
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(array $attributes, mixed $id): mixed
    {
        return null;
    }

    public function delete(mixed $id): int
    {
        return 0;
    }

    /**
     * @return array<int, mixed>
     */
    public function all(): array
    {
        return [];
    }
}
