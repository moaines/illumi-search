<?php

namespace Moaines\LaravelFts\Tests\TestSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Moaines\LaravelFts\Searchable;

class Book extends Model
{
    use Searchable;

    protected $table = 'books';

    protected $guarded = [];

    public $timestamps = true;

    protected array $ftsSearchable = [
        'title' => ['weight' => 3],
        'body' => ['weight' => 1],
        'author.name' => ['weight' => 1],
        'comments.body' => ['weight' => 1],
        'fullname' => ['weight' => 2],
    ];

    public function author()
    {
        return $this->belongsTo(Author::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function getFullnameAttribute(): string
    {
        return $this->title . ' by ' . ($this->author?->name ?? 'unknown');
    }
}
