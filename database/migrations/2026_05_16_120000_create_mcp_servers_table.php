<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_servers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();

            $table->string('slug');
            $table->string('name');
            $table->string('transport')->default('streamable_http');
            $table->string('endpoint_url');
            $table->text('credentials')->nullable();
            $table->json('headers')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedSmallInteger('timeout_seconds')->default(30);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_status')->default('pending');
            $table->text('last_sync_error')->nullable();
            $table->integer('sort_order')->default(0);

            $table->unique('slug');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_servers');
    }
};
