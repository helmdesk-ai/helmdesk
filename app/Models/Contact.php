<?php

namespace App\Models;

use App\Enums\ContactSource;
use App\Enums\ContactType;
use App\Enums\IdentityType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Laravel\Scout\Searchable;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property string $workspace_id
 * @property ContactType $type
 * @property ContactSource $source
 * @property string|null $name
 * @property string $avatar_url
 * @property Carbon|null $avatar_synced_at
 * @property string|null $locale
 * @property string|null $timezone
 * @property string|null $country
 * @property string|null $city
 * @property string|null $primary_email
 * @property string|null $primary_phone
 * @property array|null $ai_context
 * @property string|null $note
 * @property bool $is_important
 * @property Carbon|null $important_at
 * @property string|null $important_by_user_id
 * @property string|null $important_source
 * @property Carbon|null $last_seen_at
 * @property mixed $use_factory
 * @property int|null $workspaces_count
 * @property int|null $identities_count
 * @property int|null $activity_logs_count
 * @property int|null $conversations_count
 * @property int|null $custom_attribute_values_count
 * @property int|null $tags_count
 * @property-read Workspace $workspace
 * @property-read Collection|ContactIdentity[] $identities
 * @property-read Collection|ContactActivityLog[] $activityLogs
 * @property-read Collection|Conversation[] $conversations
 * @property-read Collection|ContactAttributeValue[] $customAttributeValues
 * @property-read Collection|Tag[] $tags
 *
 * @method static \Database\Factories\ContactFactory<self> factory($count = null, $state = [])
 */
class Contact extends Model
{
    /**
     * 联系人主模型，承载访客沉淀后的资料、身份汇总和接待上下文。
     */
    use HasFactory, HasUlids, Searchable, SoftDeletes;

    public const DEFAULT_AVATAR_URL = '/images/default-avatar.svg';

    protected $table = 'contacts';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ContactType::class,
            'source' => ContactSource::class,
            'ai_context' => 'array',
            'is_important' => 'boolean',
            'important_at' => 'datetime',
            'avatar_synced_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function identities(): HasMany
    {
        return $this->hasMany(ContactIdentity::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ContactActivityLog::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function customAttributeValues(): HasMany
    {
        return $this->hasMany(ContactAttributeValue::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'contact_tag_assignments')
            ->withPivot('source', 'assigned_by_user_id', 'created_at');
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $this->loadMissing(['identities', 'tags']);

        $identityValues = $this->identities
            ->whereNotNull('display_value')
            ->pluck('display_value')
            ->implode(' ');

        $tagNames = $this->tags
            ->whereNull('deleted_at')
            ->pluck('name')
            ->implode(' ');

        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'type' => $this->type->value,
            'source' => $this->source->value,
            'name' => $this->name ?? '',
            'identities' => $identityValues,
            'tags' => $tagNames,
        ];
    }

    public function displayName(): string
    {
        if (filled($this->name)) {
            return (string) $this->name;
        }

        $suffix = strtoupper(substr((string) $this->id, -4));

        return __('contact.anonymous_visitor_with_suffix', ['suffix' => $suffix]);
    }

    public function syncPrimaryFields(): void
    {
        $this->primary_email = $this->identities()
            ->where('type', IdentityType::Email)
            ->oldest()
            ->value('value');

        $this->primary_phone = $this->identities()
            ->where('type', IdentityType::Phone)
            ->oldest()
            ->value('value');

        $this->saveQuietly();
        $this->searchable();
    }
}
