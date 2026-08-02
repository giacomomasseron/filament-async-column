<?php

namespace GiacomoMasseroni\AsyncColumn\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $title
 * @property int $author_id
 */
class Post extends Model
{
    protected $guarded = [];
}
