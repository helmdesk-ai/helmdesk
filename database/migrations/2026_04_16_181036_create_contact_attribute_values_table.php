<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_attribute_values', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();
            $table->ulid('workspace_id');
            $table->ulid('contact_id');
            $table->ulid('definition_id');
            $table->json('value_json');
            $table->string('source', 20)->default('manual');
            $table->float('confidence')->nullable();
            $table->ulid('updated_by_user_id')->nullable();

            $table->unique(['workspace_id', 'contact_id', 'definition_id']);
            $table->index(['workspace_id', 'definition_id']);
            $table->index(['contact_id', 'workspace_id']);
            $table->index(['definition_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_attribute_values');
    }
};
