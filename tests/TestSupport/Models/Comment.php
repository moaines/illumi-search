<?php

namespace Moaines\IllumiSearch\Tests\TestSupport\Models;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    protected $table = 'comments';

    protected $guarded = [];

    public $timestamps = false;

    public function book()
    {
        return $this->belongsTo(Book::class);
    }
}
