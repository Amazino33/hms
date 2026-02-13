<?php

namespace App\Console\Commands;

use App\Jobs\CronQueueTestJob;
use Illuminate\Console\Command;

class TestQueueCommand extends Command
{
    protected $signature = 'test:queue';
    protected $description = 'Test if queue is working by dispatching a test job';

    public function handle()
    {
        $this->info('Dispatching test queue job...');

        // Dispatch the test job
        CronQueueTestJob::dispatch();

        $this->info('Job dispatched successfully!');
        $this->info('Check your logs and database jobs table to see if it was processed.');

        // Check jobs table
        $pendingJobs = \DB::table('jobs')->count();
        $this->info("Pending jobs in queue: {$pendingJobs}");

        return Command::SUCCESS;
    }
}