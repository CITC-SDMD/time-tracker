<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

// single row, id = 1: what only the platform decides for every organization (superadmins with the
// platform.settings permission). Today that is the oldest desktop app version that may still sync.
#[Fillable(['min_agent_version'])]
class PlatformSetting extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    public static function current(): self
    {
        return self::findOrFail(1);
    }
}
