<?php

namespace Moaines\LaravelFts\Tests\TestSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Moaines\LaravelFts\Searchable;

class Comment extends Model
{
    use Searchable;

    protected $table = 'comments';

    protected $guarded = [];

    public $timestamps = true;

    protected array $ftsSearchable = [
        'content' => true,
        'author_name' => true,
    ];

    protected $ftsCategory = 'Comments';
}
