<?php

namespace App\Models;

use App\Enums\AttributeType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $workspace_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property \App\Enums\AttributeType $type
 * @property array|null $config
 * @property int $display_order
 * @property bool $is_filterable
 * @property bool $is_api_writable
 * @property bool $is_ai_readable
 * @property bool $is_ai_writable
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property mixed $use_factory
 * @property int|null $workspaces_count
 * @property int|null $contact_attribute_values_count
 *
 * @property-read \App\Models\Workspace $workspace
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ContactAttributeValue[] $contactAttributeValues
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AttributeDefinition active()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AttributeDefinition ordered()
 * @method static \Database\Factories\AttributeDefinitionFactory<self> factory($count = null, $state = [])
 */
class AttributeDefinition extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'attribute_definitions';

    protected $guarded = [];

    public const RESERVED_KEYS = [
        'name',
        'type',
        'source',
        'primary_email',
        'primary_phone',
        'locale',
        'timezone',
        'country',
        'city',
        'last_seen_at',
        'email',
        'phone',
        'external_id',
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AttributeType::class,
            'config' => 'array',
            'display_order' => 'integer',
            'is_filterable' => 'boolean',
            'is_api_writable' => 'boolean',
            'is_ai_readable' => 'boolean',
            'is_ai_writable' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function contactAttributeValues(): HasMany
    {
        return $this->hasMany(ContactAttributeValue::class, 'definition_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->withoutTrashed();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('display_order')->orderBy('created_at');
    }

    public function usesOptions(): bool
    {
        return $this->type->usesOptions();
    }
}
