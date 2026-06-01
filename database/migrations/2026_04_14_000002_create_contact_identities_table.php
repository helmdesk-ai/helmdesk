<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_identities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();
            $table->softDeletes();
            $table->ulid('workspace_id');
            $table->ulid('contact_id');
            $table->string('type');
            $table->string('namespace')->default('');
            $table->string('value');
            $table->string('display_value')->nullable();

            $table->index(['contact_id', 'workspace_id']);
        });

        DB::statement('CREATE UNIQUE INDEX contact_identities_unique_active ON contact_identities (workspace_id, type, namespace, value) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS contact_identities_unique_active');
        Schema::dropIfExists('contact_identities');
    }
};
