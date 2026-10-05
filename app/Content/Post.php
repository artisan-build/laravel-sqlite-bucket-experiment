<?php

declare(strict_types=1);

namespace App\Content;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A compiled post. Read-only by construction: the underlying connection is
 * opened with SQLITE_OPEN_READONLY, so `save()` throws rather than lying.
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string $date
 * @property string $summary
 * @property string $html
 */
final class Post extends Model
{
    protected $connection = 'content';

    protected $table = 'posts';

    public $timestamps = false;

    /** @return Builder<self> */
    public static function published(): Builder
    {
        return self::query()->orderByDesc('date')->orderByDesc('id');
    }

    public static function findBySlug(string $slug): ?self
    {
        return self::query()->where('slug', $slug)->first();
    }
}
