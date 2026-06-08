<?php

use App\Enums\KnowledgeChunkingStrategy;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('knowledge.embedding_model_id', null);
        $this->migrator->add('knowledge.embedding_dimension', null);
        $this->migrator->add('knowledge.vector_index_enabled', false);
        $this->migrator->add('knowledge.raptor_index_enabled', false);
        $this->migrator->add('knowledge.chunking_strategy', KnowledgeChunkingStrategy::Fixed->value);
        $this->migrator->add('knowledge.chunk_max_tokens', 512);
        $this->migrator->add('knowledge.chunk_overlap_tokens', 64);
    }

    public function down(): void
    {
        $this->migrator->delete('knowledge.embedding_model_id');
        $this->migrator->delete('knowledge.embedding_dimension');
        $this->migrator->delete('knowledge.vector_index_enabled');
        $this->migrator->delete('knowledge.raptor_index_enabled');
        $this->migrator->delete('knowledge.chunking_strategy');
        $this->migrator->delete('knowledge.chunk_max_tokens');
        $this->migrator->delete('knowledge.chunk_overlap_tokens');
    }
};
