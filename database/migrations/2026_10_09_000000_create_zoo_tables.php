<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zoo_hops', function (Blueprint $table) {
            $table->id();
            $table->char('trace', 36)->index();
            $table->string('step', 32);
            $table->string('detail', 200)->default('');
            $table->dateTime('at');
        });
        Schema::create('zoo_heartbeats', function (Blueprint $table) {
            $table->string('name', 32)->primary();
            $table->dateTime('at');
        });
        Schema::create('zoo_probe', function (Blueprint $table) {
            $table->id();
            $table->string('token', 32);
            $table->dateTime('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('zoo_probe');
        Schema::dropIfExists('zoo_heartbeats');
        Schema::dropIfExists('zoo_hops');
    }
};
