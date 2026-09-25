<?php

namespace Database\Seeders;

use App\Models\Post;
use App\Support\PostBody;
use Illuminate\Database\Seeder;

class PublicContentSeeder extends Seeder
{
    public function run(): void
    {
        $news = [
            ['Students Win Regional Science Fair', 'Our Grade 9 team took first place with a low-cost water purification project.', 3],
            ['New Library and Reading Room Opens', 'A bright new space with over 5,000 books, quiet study areas and digital resources.', 10],
            ['Annual Sports Day Results', 'Congratulations to all houses on a day of great teamwork and personal bests.', 21],
            ['Admissions Open for the New Academic Year', 'Applications are now open for all classes. Book a school tour today.', 30],
        ];

        foreach ($news as [$title, $excerpt, $daysAgo]) {
            Post::updateOrCreate(['slug' => \Illuminate\Support\Str::slug($title)], [
                'type' => Post::TYPE_NEWS,
                'title' => $title,
                'excerpt' => $excerpt,
                'body' => PostBody::sanitize(
                    "<p>{$excerpt}</p><h2>Highlights</h2><ul><li>Outstanding effort from students and staff</li>".
                    '<li>Parents and families joined the celebration</li>'.
                    '<li>More updates to follow in the coming weeks</li></ul>'.
                    '<p>Thank you to everyone in our school community who made this possible.</p>'
                ),
                'is_published' => true,
                'published_at' => now()->subDays($daysAgo),
            ]);
        }

        $events = [
            ['Open Day for Prospective Families', 'Tour the campus, meet teachers and learn about our curriculum.', 7, 'Main Hall'],
            ['Parent–Teacher Meetings', 'Discuss your child\'s progress with class and subject teachers.', 14, 'Classrooms'],
            ['Winter Music Concert', 'An evening of performances by our choir, band and soloists.', 28, 'School Auditorium'],
            ['Inter-School Debate Competition', 'Our debate club hosted six schools for a day of lively discussion.', -12, 'Library'],
        ];

        foreach ($events as [$title, $excerpt, $inDays, $location]) {
            $start = now()->addDays($inDays)->setTime(10, 0);

            Post::updateOrCreate(['slug' => \Illuminate\Support\Str::slug($title)], [
                'type' => Post::TYPE_EVENT,
                'title' => $title,
                'excerpt' => $excerpt,
                'body' => PostBody::sanitize(
                    "<p>{$excerpt}</p><p>All families are welcome. Please arrive 15 minutes early.</p>".
                    '<p><strong>Contact the school office</strong> if you have any questions.</p>'
                ),
                'event_starts_at' => $start,
                'event_ends_at' => $start->copy()->addHours(3),
                'location' => $location,
                'is_published' => true,
                'published_at' => now()->subDays(20),
            ]);
        }

        // A draft that must never appear publicly.
        Post::updateOrCreate(['slug' => 'draft-upcoming-announcement'], [
            'type' => Post::TYPE_NEWS,
            'title' => 'Draft: Upcoming Announcement',
            'body' => 'Not ready yet.',
            'is_published' => false,
        ]);
    }
}
