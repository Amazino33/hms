<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1A — quick-choice chips ("Cold", "Extra pepper") offered per
     * category. An order item keeps a snapshot of the chosen LABELS
     * (order_items.chips), never these ids, so renaming an option later
     * never rewrites history.
     *
     * The two starter groups are seeded here rather than in a seeder,
     * because deploy.sh runs migrations but not seeders: this is the only
     * way they reach production. They attach to the categories that exist
     * at that moment; the owner manages everything from admin afterwards.
     */
    public function up(): void
    {
        Schema::create('chip_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('selection')->default('single'); // single | multiple
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('chip_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chip_group_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('category_chip_group', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chip_group_id')->constrained()->cascadeOnDelete();
            $table->primary(['category_id', 'chip_group_id']);
        });

        $now = now();

        foreach ([
            ['name' => 'Temperature', 'selection' => 'single', 'sort_order' => 1, 'category_type' => 'drink', 'options' => ['Cold', 'Not cold']],
            ['name' => 'Food extras', 'selection' => 'multiple', 'sort_order' => 2, 'category_type' => 'food', 'options' => ['Extra pepper', 'Less pepper', 'No onions']],
        ] as $group) {
            $groupId = DB::table('chip_groups')->insertGetId([
                'name' => $group['name'],
                'selection' => $group['selection'],
                'active' => true,
                'sort_order' => $group['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($group['options'] as $i => $label) {
                DB::table('chip_options')->insert([
                    'chip_group_id' => $groupId,
                    'label' => $label,
                    'active' => true,
                    'sort_order' => $i + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $categoryIds = DB::table('categories')->where('type', $group['category_type'])->pluck('id');

            DB::table('category_chip_group')->insert(
                $categoryIds->map(fn ($id) => ['category_id' => $id, 'chip_group_id' => $groupId])->all()
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('category_chip_group');
        Schema::dropIfExists('chip_options');
        Schema::dropIfExists('chip_groups');
    }
};
