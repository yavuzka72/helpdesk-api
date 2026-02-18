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
        Schema::create('service_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_number', 30)->unique();
            $table->foreignId('ticket_id')->nullable()->unique()->constrained('tickets')->nullOnDelete();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('service_type', ['hardware', 'onsite_install', 'maintenance', 'emergency']);
            $table->text('description');
            $table->text('address');
            $table->dateTime('scheduled_date')->nullable();
            $table->enum('status', ['pending', 'approved', 'assigned', 'in_progress', 'completed', 'cancelled'])->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};
