<?php

namespace Tests;

use App\Models\OrganizationSetting;
use Database\Factories\OrganizationFactory;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** The settings row of the default test organization (made together with it, timezone Asia/Manila). */
    protected function settings(): OrganizationSetting
    {
        return OrganizationSetting::withoutGlobalScopes()->where('organization_id', OrganizationFactory::forTests()->id)->firstOrFail();
    }
}
