<?php

namespace Moaines\IllumiSearch\Tests\TestSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Moaines\IllumiSearch\Searchable;

class Post extends Model
{
    use Searchable;

    protected $table = 'posts';

    protected $guarded = [];

    public $timestamps = true;

    protected array $ftsSearchable = [
        'title' => ['weight' => 3],
        'body' => ['weight' => 1],
    ];

    protected $ftsCategory = 'Posts';
}
