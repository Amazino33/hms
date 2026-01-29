<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class OptimizeApp extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:optimize 
                            {--clear : Clear all caches before optimizing}
                            {--full : Run full optimization including config and routes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Optimize the HMS application for better performance';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🚀 Starting HMS Performance Optimization...');
        $this->newLine();

        // Clear caches if requested
        if ($this->option('clear')) {
            $this->clearCaches();
        }

        // Run optimizations
        $this->optimizeCaches();
        
        if ($this->option('full')) {
            $this->fullOptimization();
        }

        $this->newLine();
        $this->info('✅ Optimization complete!');
        $this->newLine();
        
        $this->displayOptimizationTips();

        return Command::SUCCESS;
    }

    /**
     * Clear all application caches
     */
    protected function clearCaches(): void
    {
        $this->info('🧹 Clearing caches...');
        
        $commands = [
            'cache:clear' => 'Application cache',
            'config:clear' => 'Configuration cache',
            'route:clear' => 'Route cache',
            'view:clear' => 'Compiled views',
            'event:clear' => 'Event cache',
        ];

        foreach ($commands as $command => $description) {
            $this->line("  - Clearing {$description}...");
            Artisan::call($command);
        }

        $this->info('✓ Caches cleared');
        $this->newLine();
    }

    /**
     * Run cache optimizations
     */
    protected function optimizeCaches(): void
    {
        $this->info('⚡ Optimizing caches...');

        $commands = [
            'config:cache' => 'Configuration',
            'route:cache' => 'Routes',
            'view:cache' => 'Views',
            'event:cache' => 'Events',
            'icons:cache' => 'Icons (Filament)',
            'filament:cache-components' => 'Filament components',
        ];

        foreach ($commands as $command => $description) {
            try {
                $this->line("  - Caching {$description}...");
                Artisan::call($command, [], $this->getOutput());
            } catch (\Exception $e) {
                $this->warn("  ⚠ Skipped {$description} (command not available)");
            }
        }

        $this->info('✓ Caches optimized');
        $this->newLine();
    }

    /**
     * Run full optimization
     */
    protected function fullOptimization(): void
    {
        $this->info('🔧 Running full optimization...');

        try {
            $this->line('  - Running Laravel optimize...');
            Artisan::call('optimize', [], $this->getOutput());
            
            $this->line('  - Optimizing autoloader...');
            exec('composer dump-autoload -o');
            
            $this->info('✓ Full optimization complete');
        } catch (\Exception $e) {
            $this->error('Full optimization failed: ' . $e->getMessage());
        }

        $this->newLine();
    }

    /**
     * Display optimization tips
     */
    protected function displayOptimizationTips(): void
    {
        $this->info('💡 Performance Tips:');
        $this->line('');
        $this->line('  1. Run "npm run build" to compile assets for production');
        $this->line('  2. Enable Redis for caching in production (faster than file cache)');
        $this->line('  3. Use "php artisan queue:work" to process background jobs');
        $this->line('  4. Enable OPcache in your PHP configuration');
        $this->line('  5. Consider using Laravel Octane for even better performance');
        $this->line('');
        $this->line('  Quick commands:');
        $this->line('  - "npm run build" - Build production assets');
        $this->line('  - "php artisan app:optimize --clear" - Clear and reoptimize');
        $this->line('  - "php artisan app:optimize --full" - Full optimization');
    }
}
