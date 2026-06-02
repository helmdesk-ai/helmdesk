<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_groups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();

            $table->ulid('knowledge_base_id');
            $table->ulid('parent_id')->nullable();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->index(['knowledge_base_id', 'parent_id', 'sort_order'], 'idx_kb_group_tree');
            $table->index(['parent_id', 'sort_order'], 'idx_kb_group_parent_sort');
            $table->index(['knowledge_base_id', 'is_default'], 'idx_kb_group_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_groups');
    }
};
