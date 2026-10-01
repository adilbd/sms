<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * A node in an admin-managed navigation tree (currently only the "header" location).
 * See docs/architecture-guidelines.md; this module follows the Subjects pattern.
 */
class MenuItem extends Model
{
    /** @use HasFactory<\Database\Factories\MenuItemFactory> */
    use HasFactory;

    public const LOCATION_HEADER = 'header';

    public const LOCATIONS = [self::LOCATION_HEADER];

    public const TYPE_PAGE = 'page';

    public const TYPE_ROUTE = 'route';

    public const TYPE_URL = 'url';

    public const TYPE_HEADING = 'heading';

    public const TYPES = [self::TYPE_PAGE, self::TYPE_ROUTE, self::TYPE_URL, self::TYPE_HEADING];

    /**
     * Routes an item may point to. Limited to public routes that take no parameters.
     */
    public const ROUTES = [
        'home', 'about', 'admissions', 'news.index', 'events.index', 'gallery.index', 'results.index', 'contact', 'portal.login',
        'staff.head', 'staff.assistant_head', 'staff.teachers', 'staff.employees',
        'staff.ex_heads', 'staff.ex_teachers', 'staff.ex_employees',
    ];

    /**
     * A top-level item is depth 1. Matches vhbub's deepest path (heading > heading > page).
     */
    public const MAX_DEPTH = 3;

    protected $fillable = [
        'location',
        'parent_id',
        'label',
        'type',
        'page_id',
        'route_name',
        'url',
        'sort_order',
        'is_active',
        'open_in_new_tab',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'open_in_new_tab' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('menu.header'));
        static::deleted(fn () => Cache::forget('menu.header'));
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Resolve the link target for this item's type. Doesn't check whether a page is
     * published; MenuService::headerTree() decides visibility for the public menu.
     */
    public function href(): ?string
    {
        return match ($this->type) {
            self::TYPE_PAGE => $this->page?->url(),
            self::TYPE_ROUTE => $this->route_name ? route($this->route_name) : null,
            self::TYPE_URL => $this->url,
            default => null,
        };
    }
}
