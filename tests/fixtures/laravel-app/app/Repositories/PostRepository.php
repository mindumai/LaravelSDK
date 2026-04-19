<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Post;

/**
 * Repository for the Post model. Inherits CRUD from BaseRepository.
 */
class PostRepository extends BaseRepository
{
    public function model(): string
    {
        return Post::class;
    }
}
