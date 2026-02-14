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

Artisan::command('queue:status', function () {
    $totalJobs = \DB::table('jobs')->count();
    $failedJobs = \DB::table('failed_jobs')->count();

    $this->info("Queue Status:");
    $this->line("  Total pending jobs: {$totalJobs}");
    $this->line("  Failed jobs: {$failedJobs}");

    if ($totalJobs > 0) {
        $oldestJob = \DB::table('jobs')->orderBy('created_at')->first();
        $this->line("  Oldest job created: {$oldestJob->created_at}");

        $this->warn("You have {$totalJobs} pending jobs. This suggests your queue worker is not running.");
        $this->comment("Run: php artisan queue:work --once");
        $this->comment("Or start a worker: php artisan queue:work");
    } else {
        $this->info("✅ Queue is clean - no pending jobs!");
    }

    return \Illuminate\Console\Command::SUCCESS;
})->purpose('Check queue status and pending jobs');

Artisan::command('queue:clear', function () {
    if (!$this->confirm('Are you sure you want to clear all pending jobs? This cannot be undone!')) {
        return;
    }

    $count = \DB::table('jobs')->count();
    \DB::table('jobs')->truncate();

    $this->info("Cleared {$count} pending jobs from the queue.");

    return \Illuminate\Console\Command::SUCCESS;
})->purpose('Clear all pending jobs from the queue (dangerous!)');
