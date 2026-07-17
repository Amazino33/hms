<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exported_by')->constrained('users');
            $table->string('report')->comment('e.g. dashboard, explorer:sales, explorer:products');
            $table->string('format')->comment('csv|pdf');
            $table->date('range_start');
            $table->date('range_end');
            $table->timestamp('exported_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
