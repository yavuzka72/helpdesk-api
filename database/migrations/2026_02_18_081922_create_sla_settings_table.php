<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sla_settings', function (Blueprint $table): void {
            $table->id();
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->unique();
            $table->integer('response_minutes');
            $table->integer('resolution_minutes');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sla_settings');
    }
};
