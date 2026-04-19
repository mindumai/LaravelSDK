<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StorePostRequest;
use App\Jobs\PublishPost;
use App\Models\Post;
use Illuminate\Http\JsonResponse;

class PostController extends Controller
{
    /**
     * List all posts.
     */
    public function index(): JsonResponse
    {
        return response()->json(Post::all());
    }

    /**
     * Store a newly created post in storage.
     */
    public function store(StorePostRequest $request): JsonResponse
    {
        $post = Post::create($request->validated());

        PublishPost::dispatch($post->id);

        return response()->json($post, 201);
    }

    /**
     * Show a single post.
     */
    public function show(Post $post): JsonResponse
    {
        return response()->json($post);
    }
}
