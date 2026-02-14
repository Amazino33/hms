<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Jobs\CronQueueTestJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('test:queue', function () {
    $this->info('Dispatching test queue job...');

    // Dispatch the test job
    CronQueueTestJob::dispatch();

    $this->info('Job dispatched successfully!');
    $this->info('Check your logs and database jobs table to see if it was processed.');

    // Check jobs table
    $pendingJobs = \DB::table('jobs')->count();
    $this->info("Pending jobs in queue: {$pendingJobs}");

    return \Illuminate\Console\Command::SUCCESS;
})->purpose('Test if queue is working by dispatching a test job');
