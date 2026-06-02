<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_page_views', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();

            $table->ulid('conversation_id');
            $table->ulid('contact_id')->nullable();
            $table->text('url');
            $table->string('title')->nullable();
            $table->text('referrer')->nullable();
            $table->timestamp('viewed_at');

            $table->index(['conversation_id', 'viewed_at', 'id'], 'page_views_conversation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_page_views');
    }
};
