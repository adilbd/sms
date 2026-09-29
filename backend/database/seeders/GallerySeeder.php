<?php

namespace Database\Seeders;

use App\Models\Gallery;
use App\Models\GalleryItem;
use App\Models\Media;
use App\Support\YouTube;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sample photo and video galleries for a Class 1-12 Bangladeshi school, so a fresh
 * install already has something to show on /gallery. Placeholder images are drawn
 * with GD (no network access during seeding) and registered in the media library, the
 * same way an admin's upload would be. Matched and skipped by slug, and by an existing
 * item count, so re-running this seeder never duplicates galleries or their items.
 */
class GallerySeeder extends Seeder
{
    /**
     * These are placeholder ids, not real YouTube videos — they don't resolve to
     * anything. Replace them with the school's own uploaded videos before going live.
     */
    private const SPORTS_DAY_VIDEO_ID = 'SchoolVid01';

    private const VICTORY_DAY_VIDEO_ID = 'SchoolVid02';

    public function run(): void
    {
        foreach ($this->galleries() as $definition) {
            $gallery = Gallery::updateOrCreate(['slug' => $definition['slug']], [
                'title' => $definition['title'],
                'description' => $definition['description'],
                'is_published' => true,
                'published_at' => now()->subDays($definition['daysAgo']),
            ]);

            // Already seeded on an earlier run.
            if ($gallery->items()->exists()) {
                continue;
            }

            $sortOrder = 0;

            foreach ($definition['images'] as $index => $image) {
                $media = $this->placeholderMedia($definition['slug'], $index, $image['label']);

                $gallery->items()->create([
                    'type' => GalleryItem::TYPE_IMAGE,
                    'media_id' => $media->id,
                    'caption' => $image['caption'],
                    'sort_order' => $sortOrder++,
                ]);

                if ($index === 0) {
                    $gallery->update(['cover_media_id' => $media->id]);
                }
            }

            foreach ($definition['videos'] ?? [] as $video) {
                $gallery->items()->create([
                    'type' => GalleryItem::TYPE_VIDEO,
                    'youtube_url' => $video['url'],
                    'youtube_id' => YouTube::extractId($video['url']),
                    'caption' => $video['caption'],
                    'sort_order' => $sortOrder++,
                ]);
            }
        }
    }

    /**
     * Draws an 800x600 JPEG with the gallery's slug and a label on a colored
     * background, and registers it in the media library, exactly like an admin's
     * upload. The file (and its media row) are reused on a re-run.
     */
    private function placeholderMedia(string $gallerySlug, int $index, string $label): Media
    {
        $path = sprintf('media/seed/%s-%d.jpg', $gallerySlug, $index);

        if (! Storage::disk('public')->exists($path)) {
            $width = 800;
            $height = 600;
            $colors = [[37, 99, 235], [22, 163, 74], [217, 119, 6], [190, 24, 93]];
            [$r, $g, $b] = $colors[($index + crc32($gallerySlug)) % count($colors)];

            $image = imagecreatetruecolor($width, $height);
            $background = imagecolorallocate($image, $r, $g, $b);
            imagefilledrectangle($image, 0, 0, $width, $height, $background);

            $white = imagecolorallocate($image, 255, 255, 255);
            imagestring($image, 5, 20, $height - 40, Str::limit($label, 60, ''), $white);

            ob_start();
            imagejpeg($image, null, 85);
            $contents = ob_get_clean();
            imagedestroy($image);

            Storage::disk('public')->put($path, $contents);
        }

        return Media::firstOrCreate(['path' => $path], [
            'disk' => 'public',
            'original_name' => basename($path),
            'mime_type' => 'image/jpeg',
            'size' => Storage::disk('public')->size($path),
            'width' => 800,
            'height' => 600,
            'alt' => $label,
        ]);
    }

    private function galleries(): array
    {
        return [
            [
                'slug' => 'annual-sports-day',
                'title' => 'বার্ষিক ক্রীড়া প্রতিযোগিতা',
                'description' => 'শিক্ষার্থীদের অংশগ্রহণে অনুষ্ঠিত বার্ষিক ক্রীড়া প্রতিযোগিতার আলোকচিত্র ও ভিডিও।',
                'daysAgo' => 20,
                'images' => [
                    ['label' => 'Opening march-past', 'caption' => 'উদ্বোধনী কুচকাওয়াজ'],
                    ['label' => '100m sprint final', 'caption' => '১০০ মিটার দৌড় প্রতিযোগিতা'],
                    ['label' => 'Tug of war', 'caption' => 'দড়ি টানাটানি প্রতিযোগিতা'],
                    ['label' => 'Prize distribution', 'caption' => 'পুরস্কার বিতরণী অনুষ্ঠান'],
                ],
                'videos' => [
                    ['url' => 'https://www.youtube.com/watch?v='.self::SPORTS_DAY_VIDEO_ID, 'caption' => 'ক্রীড়া প্রতিযোগিতার হাইলাইটস'],
                ],
            ],
            [
                'slug' => 'victory-day-celebration',
                'title' => 'মহান বিজয় দিবস উদযাপন',
                'description' => '১৬ ডিসেম্বর মহান বিজয় দিবস উপলক্ষে বিদ্যালয়ে আয়োজিত অনুষ্ঠানের ছবি ও ভিডিও।',
                'daysAgo' => 35,
                'images' => [
                    ['label' => 'Flag hoisting', 'caption' => 'জাতীয় পতাকা উত্তোলন'],
                    ['label' => 'Cultural program', 'caption' => 'সাংস্কৃতিক অনুষ্ঠান'],
                    ['label' => 'Freedom fighters honored', 'caption' => 'মুক্তিযোদ্ধাদের সংবর্ধনা'],
                ],
                'videos' => [
                    ['url' => 'https://youtu.be/'.self::VICTORY_DAY_VIDEO_ID, 'caption' => 'বিজয় দিবসের অনুষ্ঠান'],
                ],
            ],
            [
                'slug' => 'science-fair',
                'title' => 'বিজ্ঞান মেলা',
                'description' => 'শিক্ষার্থীদের তৈরি বিভিন্ন বিজ্ঞান প্রকল্পের প্রদর্শনী।',
                'daysAgo' => 50,
                'images' => [
                    ['label' => 'Robotics project', 'caption' => 'রোবোটিক্স প্রকল্প প্রদর্শনী'],
                    ['label' => 'Solar model', 'caption' => 'সৌরজগতের মডেল'],
                    ['label' => 'Chemistry demo', 'caption' => 'রসায়ন পরীক্ষণ প্রদর্শনী'],
                    ['label' => 'Best project award', 'caption' => 'সেরা প্রকল্প পুরস্কার'],
                ],
            ],
            [
                'slug' => 'campus',
                'title' => 'ক্যাম্পাস',
                'description' => 'বিদ্যালয়ের শ্রেণিকক্ষ, খেলার মাঠ ও গ্রন্থাগারসহ ক্যাম্পাসের বিভিন্ন অংশ।',
                'daysAgo' => 90,
                'images' => [
                    ['label' => 'Main building', 'caption' => 'মূল একাডেমিক ভবন'],
                    ['label' => 'Playground', 'caption' => 'খেলার মাঠ'],
                    ['label' => 'Library', 'caption' => 'গ্রন্থাগার'],
                ],
            ],
        ];
    }
}
