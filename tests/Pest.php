<?php

declare(strict_types=1);

use Filagora\Memberships\Tests\Switches\CustomRouteTestCase;
use Filagora\Memberships\Tests\Switches\RoutesDisabledTestCase;
use Filagora\Memberships\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(RoutesDisabledTestCase::class)->use(RefreshDatabase::class)->in('Switches/RoutesDisabled');
pest()->extend(CustomRouteTestCase::class)->use(RefreshDatabase::class)->in('Switches/CustomRoute');
