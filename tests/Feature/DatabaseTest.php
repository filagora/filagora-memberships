<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('runs on MariaDB against the testing database', function () {
    expect(DB::connection()->getDriverName())->toBe('mariadb')
        ->and(DB::connection()->getDatabaseName())->toBe('testing');
});
