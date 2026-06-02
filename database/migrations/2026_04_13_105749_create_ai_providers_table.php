<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();

            $table->string('brand');
            $table->string('slug');
            $table->string('name');
            $table->string('protocol');
            $table->string('icon')->nullable();
            $table->text('credentials')->nullable();
            $table->json('credential_fields');
            $table->boolean('is_builtin')->default(false);
            $table->integer('sort_order')->default(0);

            $table->unique('slug');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
