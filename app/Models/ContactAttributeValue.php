<?php

namespace App\Models;

use App\Enums\AttributeValueSource;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $workspace_id
 * @property string $contact_id
 * @property string $definition_id
 * @property array $value_json
 * @property \App\Enums\AttributeValueSource $source
 * @property float|null $confidence
 * @property string|null $updated_by_user_id
 * @property mixed $use_factory
 * @property int|null $contacts_count
 * @property int|null $definitions_count
 * @property int|null $updated_by_users_count
 *
 * @property-read \App\Models\Contact $contact
 * @property-read \App\Models\AttributeDefinition $definition
 * @property-read \App\Models\User|null $updatedByUser
 *
 * @method static \Database\Factories\ContactAttributeValueFactory<self> factory($count = null, $state = [])
 */
class ContactAttributeValue extends Model
{
    /**
     * 联系人自定义属性值模型，保存联系人数据字段在当前工作区内的取值。
     */
    use HasFactory, HasUlids;

    protected $table = 'contact_attribute_values';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_json' => 'array',
            'source' => AttributeValueSource::class,
            'confidence' => 'float',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(AttributeDefinition::class, 'definition_id')->withTrashed();
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id')->withTrashed();
    }

    public function value(): mixed
    {
        return data_get($this->value_json, 'value');
    }
}
