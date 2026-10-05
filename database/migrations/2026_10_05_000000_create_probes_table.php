<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('probes', function (Blueprint $table): void {
            $table->id();
            // The sequence number the writer asked for. Unique, so a replayed
            // request cannot invent a duplicate and hide a lost row.
            $table->unsignedBigInteger('seq')->unique();
            $table->string('label')->default('write');
            $table->string('host')->nullable();
            $table->string('machine_id')->nullable();
            $table->timestamp('written_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('probes');
    }
};
