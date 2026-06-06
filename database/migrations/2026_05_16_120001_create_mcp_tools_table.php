<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tools', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();

            $table->ulid('mcp_server_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('input_schema')->nullable();
            $table->json('annotations')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('removed_at')->nullable();

            $table->unique(['mcp_server_id', 'name']);
            $table->index(['mcp_server_id', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tools');
    }
};
