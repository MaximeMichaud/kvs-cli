<?php

declare(strict_types=1);

namespace KVS\CLI\Command\System;

use KVS\CLI\Command\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'system:stats',
    description: 'Show KVS site statistics (views, ratings, content)',
    aliases: ['stats']
)]
class StatsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption(
                'videos',
                null,
                InputOption::VALUE_NONE,
                'Show detailed video statistics'
            )
            ->addOption(
                'albums',
                null,
                InputOption::VALUE_NONE,
                'Show detailed album statistics'
            )
            ->addOption(
                'users',
                null,
                InputOption::VALUE_NONE,
                'Show detailed user statistics'
            )
            ->addOption(
                'categories',
                null,
                InputOption::VALUE_NONE,
                'Show category statistics'
            )
            ->addOption(
                'top',
                't',
                InputOption::VALUE_REQUIRED,
                'Number of top items to show',
                '10'
            )
            ->addOption(
                'period',
                'p',
                InputOption::VALUE_REQUIRED,
                'Time period: today, week, month, year, all',
                'all'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->getDatabaseConnection();

        if ($db === null) {
            $this->io()->error('Database connection required for stats');
            return self::FAILURE;
        }

        $this->io()->title('KVS Site Statistics');

        $showVideos = $input->getOption('videos') === true;
        $showAlbums = $input->getOption('albums') === true;
        $showUsers = $input->getOption('users') === true;
        $showCategories = $input->getOption('categories') === true;

        $topOption = $input->getOption('top');
        $top = is_numeric($topOption) ? (int) $topOption : 10;

        /** @var string $period */
        $period = $input->getOption('period');

        // If no specific section requested, show overview
        $showOverview = !$showVideos && !$showAlbums && !$showUsers && !$showCategories;

        if ($showOverview) {
            $this->showOverview($db, $period);
            $this->showTopVideos($db, $top);
            $this->showTopAlbums($db, min($top, 5));
            $this->showRecentActivity($db);
        }

        if ($showVideos) {
            $this->showVideoStats($db, $top, $period);
        }

        if ($showAlbums) {
            $this->showAlbumStats($db, $top, $period);
        }

        if ($showUsers) {
            $this->showUserStats($db, $top, $period);
        }

        if ($showCategories) {
            $this->showCategoryStats($db, $top);
        }

        return self::SUCCESS;
    }

    /**
     * Show overview statistics
     */
    private function showOverview(\PDO $db, string $period): void
    {
        $this->io()->section('Overview');

        $prefix = $this->config->getTablePrefix();

        // Get totals
        $totals = [];

        // Videos
        $stmt = $db->query("SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status_id = 1 THEN 1 ELSE 0 END) as active,
            SUM(video_viewed) as total_views,
            SUM(rating_amount) as total_ratings
            FROM {$prefix}videos WHERE 1=1");
        if ($stmt !== false) {
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $totals['videos'] = $row;
            }
        }

        // Albums
        $stmt = $db->query("SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status_id = 1 THEN 1 ELSE 0 END) as active,
            SUM(album_viewed) as total_views,
            SUM(rating_amount) as total_ratings
            FROM {$prefix}albums WHERE 1=1");
        if ($stmt !== false) {
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $totals['albums'] = $row;
            }
        }

        // Users
        $stmt = $db->query("SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status_id = 2 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status_id = 3 THEN 1 ELSE 0 END) as premium
            FROM {$prefix}users");
        if ($stmt !== false) {
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $totals['users'] = $row;
            }
        }

        // Comments
        $stmt = $db->query("SELECT COUNT(*) as total FROM {$prefix}comments WHERE 1=1");
        if ($stmt !== false) {
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $totals['comments'] = $row;
            }
        }

        // Today's activity
        $todayStart = strtotime('today');
        $weekStart = strtotime('-7 days');

        // Videos today
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM {$prefix}videos WHERE post_date >= ? ");
        $stmt->execute([$todayStart]);
        $todayVideos = 0;
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $todayVideos = (int) ($row['count'] ?? 0);
        }

        // Videos this week
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM {$prefix}videos WHERE post_date >= ? ");
        $stmt->execute([$weekStart]);
        $weekVideos = 0;
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $weekVideos = (int) ($row['count'] ?? 0);
        }

        // Users today
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM {$prefix}users WHERE added_date >= ?");
        $stmt->execute([$todayStart]);
        $todayUsers = 0;
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $todayUsers = (int) ($row['count'] ?? 0);
        }

        // Build table
        $rows = [];

        // Content
        $videoTotal = (int) ($totals['videos']['total'] ?? 0);
        $videoActive = (int) ($totals['videos']['active'] ?? 0);
        $videoViews = (int) ($totals['videos']['total_views'] ?? 0);

        $albumTotal = (int) ($totals['albums']['total'] ?? 0);
        $albumActive = (int) ($totals['albums']['active'] ?? 0);
        $albumViews = (int) ($totals['albums']['total_views'] ?? 0);

        $userTotal = (int) ($totals['users']['total'] ?? 0);
        $userActive = (int) ($totals['users']['active'] ?? 0);
        $userPremium = (int) ($totals['users']['premium'] ?? 0);

        $commentTotal = (int) ($totals['comments']['total'] ?? 0);

        $rows[] = ['<fg=cyan>Videos</>', $this->formatNumber($videoTotal), $this->formatNumber($videoActive), "+{$todayVideos} today"];
        $rows[] = ['<fg=cyan>Albums</>', $this->formatNumber($albumTotal), $this->formatNumber($albumActive), ''];
        $rows[] = ['<fg=cyan>Users</>', $this->formatNumber($userTotal), $this->formatNumber($userActive), "+{$todayUsers} today"];
        $rows[] = ['<fg=cyan>Comments</>', $this->formatNumber($commentTotal), '-', ''];
        $rows[] = ['', '', '', ''];
        $rows[] = ['<fg=yellow>Video Views</>', $this->formatNumber($videoViews), '-', ''];
        $rows[] = ['<fg=yellow>Album Views</>', $this->formatNumber($albumViews), '-', ''];
        $rows[] = ['<fg=yellow>Total Views</>', $this->formatNumber($videoViews + $albumViews), '-', ''];

        $this->renderTable(['Metric', 'Total', 'Active', 'Recent'], $rows);

        // Premium users note
        if ($userPremium > 0) {
            $this->io()->text("<fg=gray>Premium users: {$userPremium}</>");
        }
    }

    /**
     * Show top videos by views
     */
    private function showTopVideos(\PDO $db, int $limit): void
    {
        $this->io()->section('Top Videos (by views)');

        $prefix = $this->config->getTablePrefix();

        $stmt = $db->prepare("SELECT
            video_id, title, dir, video_viewed, rating, rating_amount, duration
            FROM {$prefix}videos
            WHERE status_id = 1             ORDER BY video_viewed DESC
            LIMIT {$limit}");
        $stmt->execute();
        $videos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($videos === []) {
            $this->io()->text('<fg=gray>No videos found</>');
            return;
        }

        $rows = [];
        $rank = 1;
        foreach ($videos as $video) {
            if (!is_array($video)) {
                continue;
            }

            $title = (string) ($video['title'] ?? 'Untitled');
            if (mb_strlen($title) > 40) {
                $title = mb_substr($title, 0, 37) . '...';
            }

            $views = (int) ($video['video_viewed'] ?? 0);
            $rating = (float) ($video['rating'] ?? 0);
            $ratingCount = (int) ($video['rating_amount'] ?? 0);
            $duration = (int) ($video['duration'] ?? 0);

            $ratingStr = $ratingCount > 0 ? sprintf('%.1f (%d)', $rating, $ratingCount) : '-';
            $durationStr = $duration > 0 ? gmdate('H:i:s', $duration) : '-';

            $rows[] = [
                $rank,
                $title,
                $this->formatNumber($views),
                $ratingStr,
                $durationStr,
            ];
            $rank++;
        }

        $this->renderTable(['#', 'Title', 'Views', 'Rating', 'Duration'], $rows);
    }

    /**
     * Show top albums by views
     */
    private function showTopAlbums(\PDO $db, int $limit): void
    {
        $this->io()->section('Top Albums (by views)');

        $prefix = $this->config->getTablePrefix();

        $stmt = $db->prepare("SELECT
            album_id, title, album_viewed, rating, rating_amount, photos_amount
            FROM {$prefix}albums
            WHERE status_id = 1             ORDER BY album_viewed DESC
            LIMIT {$limit}");
        $stmt->execute();
        $albums = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($albums === []) {
            $this->io()->text('<fg=gray>No albums found</>');
            return;
        }

        $rows = [];
        $rank = 1;
        foreach ($albums as $album) {
            if (!is_array($album)) {
                continue;
            }

            $title = (string) ($album['title'] ?? 'Untitled');
            if (mb_strlen($title) > 40) {
                $title = mb_substr($title, 0, 37) . '...';
            }

            $views = (int) ($album['album_viewed'] ?? 0);
            $rating = (float) ($album['rating'] ?? 0);
            $ratingCount = (int) ($album['rating_amount'] ?? 0);
            $photos = (int) ($album['photos_amount'] ?? 0);

            $ratingStr = $ratingCount > 0 ? sprintf('%.1f (%d)', $rating, $ratingCount) : '-';

            $rows[] = [
                $rank,
                $title,
                $this->formatNumber($views),
                $ratingStr,
                $photos . ' photos',
            ];
            $rank++;
        }

        $this->renderTable(['#', 'Title', 'Views', 'Rating', 'Photos'], $rows);
    }

    /**
     * Show recent activity
     */
    private function showRecentActivity(\PDO $db): void
    {
        $this->io()->section('Recent Activity (7 days)');

        $prefix = $this->config->getTablePrefix();
        $weekStart = strtotime('-7 days');

        $rows = [];

        // New videos per day
        $stmt = $db->prepare("SELECT
            DATE(FROM_UNIXTIME(post_date)) as date,
            COUNT(*) as count
            FROM {$prefix}videos
            WHERE post_date >= ?             GROUP BY DATE(FROM_UNIXTIME(post_date))
            ORDER BY date DESC
            LIMIT 7");
        $stmt->execute([$weekStart]);
        $dailyVideos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($dailyVideos !== []) {
            foreach ($dailyVideos as $day) {
                if (!is_array($day)) {
                    continue;
                }
                $date = (string) ($day['date'] ?? '');
                $count = (int) ($day['count'] ?? 0);
                $rows[] = [$date, "+{$count} videos", ''];
            }
        }

        // New users this week
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM {$prefix}users WHERE added_date >= ?");
        $stmt->execute([$weekStart]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $weekUsers = is_array($row) ? (int) ($row['count'] ?? 0) : 0;

        // New comments this week
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM {$prefix}comments WHERE added_date >= ? ");
        $stmt->execute([$weekStart]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $weekComments = is_array($row) ? (int) ($row['count'] ?? 0) : 0;

        if ($rows === []) {
            $rows[] = ['<fg=gray>No new content</>', '', ''];
        }

        $rows[] = ['', '', ''];
        $rows[] = ['<fg=cyan>Week Total</>', "+{$weekUsers} users", "+{$weekComments} comments"];

        $this->renderTable(['Date', 'Content', 'Activity'], $rows);
    }

    /**
     * Show detailed video statistics
     */
    private function showVideoStats(\PDO $db, int $limit, string $period): void
    {
        $this->io()->section('Video Statistics');

        $prefix = $this->config->getTablePrefix();

        // Summary
        $stmt = $db->query("SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status_id = 1 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status_id = 0 THEN 1 ELSE 0 END) as disabled,
            SUM(CASE WHEN has_errors = 1 THEN 1 ELSE 0 END) as errors,
            SUM(video_viewed) as total_views,
            SUM(video_viewed_unique) as unique_views,
            AVG(video_viewed) as avg_views,
            MAX(video_viewed) as max_views,
            SUM(rating_amount) as total_ratings,
            AVG(CASE WHEN rating_amount > 0 THEN rating ELSE NULL END) as avg_rating,
            SUM(comments_count) as total_comments,
            SUM(favourites_count) as total_favourites,
            AVG(duration) as avg_duration,
            SUM(duration) as total_duration
            FROM {$prefix}videos WHERE 1=1");

        if ($stmt !== false) {
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $rows = [
                    ['Total Videos', $this->formatNumber((int) ($row['total'] ?? 0))],
                    ['Active', '<fg=green>' . $this->formatNumber((int) ($row['active'] ?? 0)) . '</>'],
                    ['Disabled', '<fg=yellow>' . $this->formatNumber((int) ($row['disabled'] ?? 0)) . '</>'],
                    ['With Errors', '<fg=red>' . $this->formatNumber((int) ($row['errors'] ?? 0)) . '</>'],
                    ['', ''],
                    ['Total Views', $this->formatNumber((int) ($row['total_views'] ?? 0))],
                    ['Unique Views', $this->formatNumber((int) ($row['unique_views'] ?? 0))],
                    ['Avg Views/Video', $this->formatNumber((int) ($row['avg_views'] ?? 0))],
                    ['Max Views', $this->formatNumber((int) ($row['max_views'] ?? 0))],
                    ['', ''],
                    ['Total Ratings', $this->formatNumber((int) ($row['total_ratings'] ?? 0))],
                    ['Avg Rating', sprintf('%.2f', (float) ($row['avg_rating'] ?? 0))],
                    ['Total Comments', $this->formatNumber((int) ($row['total_comments'] ?? 0))],
                    ['Total Favourites', $this->formatNumber((int) ($row['total_favourites'] ?? 0))],
                    ['', ''],
                    ['Avg Duration', gmdate('H:i:s', (int) ($row['avg_duration'] ?? 0))],
                    ['Total Duration', $this->formatDuration((int) ($row['total_duration'] ?? 0))],
                ];

                $this->renderTable(['Metric', 'Value'], $rows);
            }
        }

        // Top by views
        $this->io()->text('');
        $this->io()->text('<info>Top by Views:</info>');
        $this->showTopVideos($db, $limit);

        // Top by rating
        $this->io()->text('<info>Top by Rating:</info>');
        $stmt = $db->prepare("SELECT
            video_id, title, video_viewed, rating, rating_amount
            FROM {$prefix}videos
            WHERE status_id = 1  AND rating_amount >= 5
            ORDER BY rating DESC, rating_amount DESC
            LIMIT {$limit}");
        $stmt->execute();
        $videos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($videos !== []) {
            $rows = [];
            $rank = 1;
            foreach ($videos as $video) {
                if (!is_array($video)) {
                    continue;
                }
                $title = (string) ($video['title'] ?? 'Untitled');
                if (mb_strlen($title) > 40) {
                    $title = mb_substr($title, 0, 37) . '...';
                }
                $rows[] = [
                    $rank,
                    $title,
                    sprintf('%.2f', (float) ($video['rating'] ?? 0)),
                    (int) ($video['rating_amount'] ?? 0) . ' votes',
                    $this->formatNumber((int) ($video['video_viewed'] ?? 0)) . ' views',
                ];
                $rank++;
            }
            $this->renderTable(['#', 'Title', 'Rating', 'Votes', 'Views'], $rows);
        }
    }

    /**
     * Show detailed album statistics
     */
    private function showAlbumStats(\PDO $db, int $limit, string $period): void
    {
        $this->io()->section('Album Statistics');

        $prefix = $this->config->getTablePrefix();

        $stmt = $db->query("SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status_id = 1 THEN 1 ELSE 0 END) as active,
            SUM(album_viewed) as total_views,
            AVG(album_viewed) as avg_views,
            SUM(rating_amount) as total_ratings,
            AVG(CASE WHEN rating_amount > 0 THEN rating ELSE NULL END) as avg_rating,
            SUM(photos_amount) as total_photos,
            AVG(photos_amount) as avg_photos
            FROM {$prefix}albums WHERE 1=1");

        if ($stmt !== false) {
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $rows = [
                    ['Total Albums', $this->formatNumber((int) ($row['total'] ?? 0))],
                    ['Active', '<fg=green>' . $this->formatNumber((int) ($row['active'] ?? 0)) . '</>'],
                    ['', ''],
                    ['Total Views', $this->formatNumber((int) ($row['total_views'] ?? 0))],
                    ['Avg Views/Album', $this->formatNumber((int) ($row['avg_views'] ?? 0))],
                    ['', ''],
                    ['Total Photos', $this->formatNumber((int) ($row['total_photos'] ?? 0))],
                    ['Avg Photos/Album', $this->formatNumber((int) ($row['avg_photos'] ?? 0))],
                    ['', ''],
                    ['Total Ratings', $this->formatNumber((int) ($row['total_ratings'] ?? 0))],
                    ['Avg Rating', sprintf('%.2f', (float) ($row['avg_rating'] ?? 0))],
                ];

                $this->renderTable(['Metric', 'Value'], $rows);
            }
        }

        $this->showTopAlbums($db, $limit);
    }

    /**
     * Show user statistics
     */
    private function showUserStats(\PDO $db, int $limit, string $period): void
    {
        $this->io()->section('User Statistics');

        $prefix = $this->config->getTablePrefix();

        $stmt = $db->query("SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status_id = 0 THEN 1 ELSE 0 END) as disabled,
            SUM(CASE WHEN status_id = 1 THEN 1 ELSE 0 END) as unconfirmed,
            SUM(CASE WHEN status_id = 2 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status_id = 3 THEN 1 ELSE 0 END) as premium,
            SUM(CASE WHEN status_id = 4 THEN 1 ELSE 0 END) as vip,
            SUM(CASE WHEN status_id = 6 THEN 1 ELSE 0 END) as webmaster,
            SUM(total_videos_count) as total_videos,
            SUM(total_albums_count) as total_albums,
            SUM(comments_count) as total_comments,
            SUM(profile_viewed) as total_profile_views,
            AVG(login_count) as avg_logins
            FROM {$prefix}users");

        if ($stmt !== false) {
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $rows = [
                    ['Total Users', $this->formatNumber((int) ($row['total'] ?? 0))],
                    ['', ''],
                    ['<fg=red>Disabled</>', $this->formatNumber((int) ($row['disabled'] ?? 0))],
                    ['<fg=yellow>Unconfirmed</>', $this->formatNumber((int) ($row['unconfirmed'] ?? 0))],
                    ['<fg=green>Active</>', $this->formatNumber((int) ($row['active'] ?? 0))],
                    ['<fg=cyan>Premium</>', $this->formatNumber((int) ($row['premium'] ?? 0))],
                    ['<fg=magenta>VIP</>', $this->formatNumber((int) ($row['vip'] ?? 0))],
                    ['<fg=blue>Webmaster</>', $this->formatNumber((int) ($row['webmaster'] ?? 0))],
                    ['', ''],
                    ['User Videos', $this->formatNumber((int) ($row['total_videos'] ?? 0))],
                    ['User Albums', $this->formatNumber((int) ($row['total_albums'] ?? 0))],
                    ['User Comments', $this->formatNumber((int) ($row['total_comments'] ?? 0))],
                    ['Profile Views', $this->formatNumber((int) ($row['total_profile_views'] ?? 0))],
                ];

                $this->renderTable(['Status', 'Count'], $rows);
            }
        }

        // Top uploaders
        $this->io()->text('');
        $this->io()->text('<info>Top Uploaders:</info>');

        $stmt = $db->prepare("SELECT
            u.user_id, u.username, u.total_videos_count, u.total_albums_count,
            (SELECT SUM(video_viewed) FROM {$prefix}videos v WHERE v.user_id = u.user_id AND v.status_id = 1) as total_views
            FROM {$prefix}users u
            WHERE u.total_videos_count > 0 OR u.total_albums_count > 0
            ORDER BY (u.total_videos_count + u.total_albums_count) DESC
            LIMIT {$limit}");
        $stmt->execute();
        $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($users !== []) {
            $rows = [];
            $rank = 1;
            foreach ($users as $user) {
                if (!is_array($user)) {
                    continue;
                }
                $rows[] = [
                    $rank,
                    (string) ($user['username'] ?? 'Unknown'),
                    (int) ($user['total_videos_count'] ?? 0) . ' videos',
                    (int) ($user['total_albums_count'] ?? 0) . ' albums',
                    $this->formatNumber((int) ($user['total_views'] ?? 0)) . ' views',
                ];
                $rank++;
            }
            $this->renderTable(['#', 'Username', 'Videos', 'Albums', 'Total Views'], $rows);
        }

        // Recent registrations
        $this->io()->text('');
        $this->io()->text('<info>Recent Registrations (7 days):</info>');

        $weekStart = strtotime('-7 days');
        $stmt = $db->prepare("SELECT
            DATE(FROM_UNIXTIME(added_date)) as date,
            COUNT(*) as count
            FROM {$prefix}users
            WHERE added_date >= ?
            GROUP BY DATE(FROM_UNIXTIME(added_date))
            ORDER BY date DESC");
        $stmt->execute([$weekStart]);
        $daily = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($daily !== []) {
            $rows = [];
            foreach ($daily as $day) {
                if (!is_array($day)) {
                    continue;
                }
                $rows[] = [(string) ($day['date'] ?? ''), '+' . (int) ($day['count'] ?? 0) . ' users'];
            }
            $this->renderTable(['Date', 'Registrations'], $rows);
        } else {
            $this->io()->text('<fg=gray>No recent registrations</>');
        }
    }

    /**
     * Show category statistics
     */
    private function showCategoryStats(\PDO $db, int $limit): void
    {
        $this->io()->section('Category Statistics');

        $prefix = $this->config->getTablePrefix();

        $stmt = $db->prepare("SELECT
            category_id, title, total_videos, today_videos, total_albums, today_albums
            FROM {$prefix}categories
            WHERE status_id = 1
            ORDER BY total_videos DESC
            LIMIT {$limit}");
        $stmt->execute();
        $categories = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($categories === []) {
            $this->io()->text('<fg=gray>No categories found</>');
            return;
        }

        $rows = [];
        $rank = 1;
        foreach ($categories as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $rows[] = [
                $rank,
                (string) ($cat['title'] ?? 'Unknown'),
                $this->formatNumber((int) ($cat['total_videos'] ?? 0)),
                '+' . (int) ($cat['today_videos'] ?? 0),
                $this->formatNumber((int) ($cat['total_albums'] ?? 0)),
            ];
            $rank++;
        }

        $this->renderTable(['#', 'Category', 'Videos', 'Today', 'Albums'], $rows);
    }

    /**
     * Format number with thousands separator
     */
    private function formatNumber(int $number): string
    {
        return number_format($number, 0, '.', ',');
    }

    /**
     * Format duration in human readable format
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 3600) {
            return gmdate('i:s', $seconds);
        }

        if ($seconds < 86400) {
            return gmdate('H:i:s', $seconds);
        }

        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);

        return "{$days}d {$hours}h";
    }
}
