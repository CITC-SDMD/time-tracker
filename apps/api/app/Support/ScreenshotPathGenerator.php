<?php

namespace App\Support;

use App\Models\Screenshot;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

// where the files of a screenshot go on the screenshots disk: org_{organization}/{user}/{yyyy}/{mm}/{dd}/{screenshot id}/,
// so the bucket (or folder) stays browsable and easy to back up (or hand over) per organization, person and day.
class ScreenshotPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return $this->base($media).'/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->base($media).'/conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->base($media).'/responsive/';
    }

    private function base(Media $media): string
    {
        /** @var Screenshot $screenshot */
        $screenshot = $media->model;
        $day = $screenshot->taken_at->utc();

        return 'org_'.$screenshot->organization_id.'/'.$screenshot->user_id.'/'.$day->format('Y/m/d').'/'.$screenshot->id;
    }
}
