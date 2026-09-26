<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

// One screenshot of a person's main screen (docs/DEVELOPMENT_PLAN.md phase 10). `id` is the uuid v7
// the desktop app made, so a repeated upload is recognised. The picture and its thumbnail are the
// media of the `screenshot` collection, on the private `screenshots` disk (S3 in production, a
// local folder while developing). Nothing here is ever public.
#[Fillable(['id', 'user_id', 'device_id', 'taken_at', 'width', 'height'])]
class Screenshot extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const COLLECTION = 'screenshot';

    public const THUMB_WIDTH = 320;

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::COLLECTION)->singleFile()->useDisk('screenshots');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Max, self::THUMB_WIDTH, self::THUMB_WIDTH)
            ->format('jpg')
            ->quality(70)
            ->performOnCollections(self::COLLECTION);
    }

    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
