<?php

namespace App\Models;

use App\Enums\IdentityType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property string $contact_id
 * @property IdentityType $type
 * @property string $namespace
 * @property string $value
 * @property string|null $display_value
 * @property mixed $use_factory
 * @property int|null $contacts_count
 * @property-read Contact $contact
 *
 * @method static \Database\Factories\ContactIdentityFactory<self> factory($count = null, $state = [])
 */
class ContactIdentity extends Model
{
    /**
     * 联系人身份标识模型，保存邮箱、手机号、会话 token 或外部 ID 等可匹配身份。
     */
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'contact_identities';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => IdentityType::class,
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
