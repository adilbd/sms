<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * A current or former teaching/non-teaching staff member, shown on the public
 * স্কুল প্রশাসন (School Administration) pages. Replaces the old placeholder Teacher
 * model. See docs/architecture-guidelines.md; this module follows the Subjects pattern.
 */
class Staff extends Model
{
    /** @use HasFactory<\Database\Factories\StaffFactory> */
    use HasFactory, SoftDeletes;

    // Eloquent's inflector treats "staff" oddly in places; set explicitly to be safe.
    protected $table = 'staff';

    public const CATEGORY_TEACHER = 'teacher';

    public const CATEGORY_STAFF = 'staff';

    public const CATEGORIES = [self::CATEGORY_TEACHER, self::CATEGORY_STAFF];

    public const POSITION_HEAD = 'head';

    public const POSITION_ASSISTANT_HEAD = 'assistant_head';

    public const POSITION_TEACHER = 'teacher';

    public const POSITION_STAFF = 'staff';

    public const POSITIONS = [
        self::POSITION_HEAD, self::POSITION_ASSISTANT_HEAD, self::POSITION_TEACHER, self::POSITION_STAFF,
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    public const STATUS_TRANSFERRED = 'transferred';

    public const STATUS_RESIGNED = 'resigned';

    public const STATUS_DECEASED = 'deceased';

    public const STATUSES = [
        self::STATUS_ACTIVE, self::STATUS_RETIRED, self::STATUS_TRANSFERRED, self::STATUS_RESIGNED, self::STATUS_DECEASED,
    ];

    public const GENDERS = ['male', 'female', 'other'];

    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'];

    protected $fillable = [
        'user_id',
        'employee_id',
        'name_en',
        'name_bn',
        'category',
        'position',
        'designation',
        'subject',
        'mpo_index',
        'joining_date',
        'leaving_date',
        'status',
        'gender',
        'religion',
        'date_of_birth',
        'blood_group',
        'nationality',
        'nid',
        'mobile',
        'email',
        'show_contact',
        'present_address',
        'permanent_address',
        'district',
        'photo',
        'bio',
        'sort_order',
        'is_published',
    ];

    /** Mirrors the column defaults in the staff migration. */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'nationality' => 'Bangladeshi',
        'show_contact' => false,
        'sort_order' => 0,
        'is_published' => true,
    ];

    protected $casts = [
        'joining_date' => 'date',
        'leaving_date' => 'date',
        'date_of_birth' => 'date',
        'show_contact' => 'boolean',
        'is_published' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('sitemap.xml'));
        static::deleted(fn () => Cache::forget('sitemap.xml'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function educations(): HasMany
    {
        return $this->hasMany(StaffEducation::class)->orderBy('sort_order')->orderBy('id');
    }

    public function trainings(): HasMany
    {
        return $this->hasMany(StaffTraining::class)->orderBy('sort_order')->orderBy('id');
    }

    public function shifts(): BelongsToMany
    {
        return $this->belongsToMany(Shift::class, 'shift_staff');
    }

    public function subjectAssignments(): HasMany
    {
        return $this->hasMany(SubjectAssignment::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeFormer(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_ACTIVE);
    }

    public function isFormer(): bool
    {
        return $this->status !== self::STATUS_ACTIVE;
    }

    public function name(): string
    {
        return $this->name_en ?: $this->name_bn;
    }

    public function photoUrl(): ?string
    {
        return $this->photo ? Storage::disk('public')->url($this->photo) : null;
    }

    public function url(): string
    {
        return route('staff.show', $this->id);
    }

    public function seoTitle(): string
    {
        return $this->designation ? "{$this->name()} — {$this->designation}" : $this->name();
    }

    public function seoDescription(): string
    {
        return $this->designation
            ? "{$this->name()}, {$this->designation} at our school."
            : "{$this->name()}'s profile.";
    }
}
