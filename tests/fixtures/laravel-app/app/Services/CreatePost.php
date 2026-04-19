<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Post;

/**
 * Creates a new Post from validated input.
 */
class CreatePost extends BaseService
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255',
            'body' => 'required|string',
            'author_id' => 'required|integer|exists:users,id',
        ];
    }

    /**
     * Execute the service — creates a Post record and returns it.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data): Post
    {
        return Post::create($data);
    }
}
