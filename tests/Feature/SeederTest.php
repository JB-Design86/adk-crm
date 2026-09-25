<?php

use App\Models\BlocklistEntry;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\TestDataSeeder;

it('spielt in der Betriebsumgebung keine Testdaten ein', function () {
    app()['env'] = 'production';

    expect(fn () => app(TestDataSeeder::class)->run())->toThrow(RuntimeException::class, 'Betriebsumgebung');

    app()['env'] = 'testing';
    expect(User::count())->toBe(0)->and(Organization::count())->toBe(0);
});

it('erzeugt die vereinbarten Testdaten', function () {
    $this->seed(TestDataSeeder::class);

    expect(Organization::count())->toBe(300)
        ->and(Organization::distinct()->count('industry'))->toBe(10)
        ->and(Organization::distinct()->pluck('priority')->sort()->values()->all())->toBe(['A', 'B', 'C'])
        ->and(Lead::whereNull('organization_id')->count())->toBe(30)
        ->and(Lead::whereNull('organization_id')->where('channel', 'cold_call')->count())->toBe(0)
        ->and(BlocklistEntry::count())->toBe(5)
        ->and(User::where('role', 'admin')->count())->toBe(1)
        ->and(User::where('role', 'staff')->count())->toBe(1);

    $statuses = Lead::distinct()->pluck('status')->all();
    expect($statuses)->toContain('new', 'not_reached', 'interested', 'documents_sent', 'appointment', 'later', 'no_interest', 'no_need', 'objection', 'wrong_data', 'handed_over');
});
