<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Marks a Post as published and returns the updated record.
 * No ShouldQueue — runs synchronously so it counts as a job tool.
 */
class PublishPost
{
    use Dispatchable, Queueable;

    public function __construct(
        public readonly int $postId,
    ) {}

    /**
     * Publish the post and return the updated model.
     */
    public function handle(): Post
    {
        $post = Post::findOrFail($this->postId);
        $post->update(['published_at' => now()]);

        return $post;
    }
}
