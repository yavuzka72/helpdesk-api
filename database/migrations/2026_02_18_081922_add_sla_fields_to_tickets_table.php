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
        Schema::table('tickets', function (Blueprint $table) {
            $table->dateTime('response_due_at')->nullable()->after('status');
            $table->dateTime('resolution_due_at')->nullable()->after('response_due_at');
            $table->boolean('is_sla_breached')->default(false)->after('resolution_due_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['response_due_at', 'resolution_due_at', 'is_sla_breached']);
        });
    }
};
