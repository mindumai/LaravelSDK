<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Post — a blog post authored by a user.
 */
class Post extends Model
{
    use SoftDeletes;

    protected $table = 'posts';

    protected $primaryKey = 'id';

    protected $fillable = [
        'title',
        'slug',
        'body',
        'author_id',
        'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];
}
