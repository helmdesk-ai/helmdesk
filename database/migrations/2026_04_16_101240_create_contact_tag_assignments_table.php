<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_tag_assignments', function (Blueprint $table) {
            $table->ulid('tag_id');
            $table->ulid('contact_id');
            $table->ulid('assigned_by_user_id')->nullable();
            $table->string('source')->default('manual');
            $table->timestamp('created_at')->nullable();

            $table->unique(['tag_id', 'contact_id']);
            $table->index('contact_id');
            $table->index(['contact_id', 'tag_id'], 'cta_contact_tag_idx');
            $table->index(['tag_id', 'created_at'], 'cta_tag_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_tag_assignments');
    }
};
