<?php
/**
 * Shared card renderers. Every card is a link to that item's own
 * detail page (event.php, announcement.php, officer.php).
 * Call these after includes/header.php has been included.
 */

function render_event_card($row) {
    // Events with a Facebook link: first image, title, date, time, description, and a link out to Facebook.
    if (!empty($row['facebook_url'])) {
        $desc = trim((string) ($row['description'] ?? '')) ?: trim((string) ($row['fb_description'] ?? ''));
        $excerpt = mb_strlen($desc) > 140 ? mb_substr($desc, 0, 140) . '…' : $desc;
        $img = !empty($row['cover_image']) ? $row['cover_image'] : ($row['fb_image'] ?? '');
        ?>
        <a href="<?php echo e($row['facebook_url']); ?>" target="_blank" rel="noopener noreferrer" class="card card-link post-card">
            <?php if (!empty($img)): ?>
                <img src="<?php echo asset_v($img); ?>" alt="" class="post-card-img" loading="lazy">
            <?php else: ?>
                <div class="post-card-img post-card-img-empty"><svg class="icon" aria-hidden="true"><use href="#i-calendar"/></svg></div>
            <?php endif; ?>
            <h3><?php echo e($row['title']); ?></h3>
            <p class="card-line"><svg class="icon" aria-hidden="true"><use href="#i-calendar"/></svg><?php echo e(event_range_text($row)); ?></p>
            <?php if (event_time_text($row) !== ''): ?><p class="card-line"><svg class="icon" aria-hidden="true"><use href="#i-clock"/></svg><?php echo e(event_time_text($row)); ?></p><?php endif; ?>
            <?php if ($excerpt !== ''): ?><p><?php echo e($excerpt); ?></p><?php endif; ?>
            <span class="card-cta">View on Facebook →</span>
        </a>
        <?php
        return;
    }
    ?>
    <a href="<?php echo BASE_URL; ?>/event.php?id=<?php echo (int) $row['event_id']; ?>" class="card card-link post-card">
        <?php if (!empty($row['cover_image'])): ?>
            <img src="<?php echo asset_v($row['cover_image']); ?>" alt="" class="post-card-img" loading="lazy">
        <?php else: ?>
            <div class="post-card-img post-card-img-empty"><svg class="icon" aria-hidden="true"><use href="#i-calendar"/></svg></div>
        <?php endif; ?>
        <div class="card-chips"><?php echo event_state_chip($row); ?></div>
        <h3><?php echo e($row['title']); ?></h3>
        <p class="card-line"><svg class="icon" aria-hidden="true"><use href="#i-calendar"/></svg><?php echo e(event_range_text($row)); ?></p>
        <?php if (event_time_text($row) !== ''): ?><p class="card-line"><svg class="icon" aria-hidden="true"><use href="#i-clock"/></svg><?php echo e(event_time_text($row)); ?></p><?php endif; ?>
        <p class="card-line"><svg class="icon" aria-hidden="true"><use href="#i-pin"/></svg><?php echo e($row['venue']); ?></p>
        <span class="card-cta">View full details →</span>
    </a>
    <?php
}

function render_announcement_card($row) {
    $deadline_ts = !empty($row['deadline']) ? strtotime($row['deadline']) : null;
    $excerpt = mb_strlen($row['content']) > 140 ? mb_substr($row['content'], 0, 140) . '…' : $row['content'];
    ?>
    <a href="<?php echo BASE_URL; ?>/announcement.php?id=<?php echo (int) $row['announcement_id']; ?>" class="card card-link post-card">
        <?php if (!empty($row['image'])): ?>
            <img src="<?php echo asset_v($row['image']); ?>" alt="" class="post-card-img" loading="lazy">
        <?php else: ?>
            <div class="post-card-img post-card-img-empty"><svg class="icon" aria-hidden="true"><use href="#i-megaphone"/></svg></div>
        <?php endif; ?>
        <?php if ($deadline_ts): ?>
            <div class="card-chips">
                <?php if ($deadline_ts > time()): ?>
                    <span class="chip chip-warn"><?php echo e(time_left_text($deadline_ts)); ?></span>
                <?php else: ?>
                    <span class="chip chip-muted">Closed</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <h3><?php echo e($row['title']); ?></h3>
        <p><?php echo e($excerpt); ?></p>
        <p class="muted" style="font-size:0.78rem;">Posted <?php echo date('M j, Y', strtotime($row['created_at'])); ?></p>
        <span class="card-cta">Read the full announcement →</span>
    </a>
    <?php
}

function render_officer_card($row) {
    ?>
    <a href="<?php echo BASE_URL; ?>/officer.php?id=<?php echo (int) $row['user_id']; ?>" class="card card-link officer-card">
        <?php if (!empty($row['profile_photo'])): ?>
            <img src="<?php echo asset_v($row['profile_photo']); ?>" alt="" class="officer-photo" loading="lazy">
        <?php else: ?>
            <span class="officer-photo officer-photo-fallback"><?php echo e(initials($row['first_name'], $row['last_name'])); ?></span>
        <?php endif; ?>
        <h3><?php echo e(full_name($row)); ?></h3>
        <?php if (!empty($row['committee_role'])): ?>
            <p style="font-weight:600;"><?php echo e($row['committee_role']); ?></p>
        <?php endif; ?>
        <p><?php echo e($row['course'] . ' — ' . $row['year_level']); ?></p>
        <?php if (isset($row['status'])): ?>
            <span class="status status-<?php echo e($row['status']); ?>"><?php echo e(ucfirst($row['status'])); ?></span>
        <?php endif; ?>
        <span class="card-cta" style="display:block;">View profile →</span>
    </a>
    <?php
}

/**
 * Faculty / adviser card for the Home page. Not a link (faculty have no public
 * profile page) — shows photo, name, position and department.
 */
function render_faculty_card($row, $extra_class = '') {
    ?>
    <div class="card officer-card faculty-card<?php echo $extra_class !== '' ? ' ' . e($extra_class) : ''; ?>">
        <?php if (!empty($row['profile_photo'])): ?>
            <img src="<?php echo asset_v($row['profile_photo']); ?>" alt="" class="officer-photo" loading="lazy">
        <?php else: ?>
            <span class="officer-photo officer-photo-fallback"><?php echo e(initials($row['first_name'], $row['last_name'])); ?></span>
        <?php endif; ?>
        <h3><?php echo e(full_name($row)); ?></h3>
        <?php if (!empty($row['faculty_position'])): ?>
            <p class="faculty-position"><?php echo e($row['faculty_position']); ?></p>
        <?php endif; ?>
        <?php if (!empty($row['course'])): ?>
            <p class="muted" style="font-size:0.85rem;"><?php echo e($row['course']); ?></p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Prints a row of cards that glides sideways in an endless loop.
 *   $cards          array of card HTML strings (from output buffering)
 *   $opts['label']  accessible name           $opts['reverse']  true = glide to the right
 *   $opts['people'] true = narrower person cards
 *   $opts['min']    cards needed in one half so it fills a wide screen
 *   $opts['sec']    seconds each card takes to pass
 *   $opts['enabled'] false (admin switched it off) = a normal still grid, no movement
 *   $opts['static_max'] max cards in the still grid (null = all)
 * The first copy is real; the repeats are hidden from screen readers and the Tab key.
 */
function render_marquee(array $cards, array $opts = []) {
    if (!$cards) return;
    $o = $opts + ['label' => 'Cards (moving)', 'reverse' => false, 'people' => false, 'min' => 7, 'sec' => 6, 'enabled' => true, 'static_max' => null];
    if (!$o['enabled'] || count($cards) < 2) {
        $shown = $o['static_max'] ? array_slice($cards, 0, (int) $o['static_max']) : $cards;
        echo '<div class="container"><div class="card-grid">' . implode('', $shown) . '</div></div>';
        return;
    }
    $reps = (int) ceil($o['min'] / count($cards));
    $dur  = max(24, count($cards) * $reps * (int) $o['sec']);
    $cls  = 'ev-marquee' . ($o['people'] ? ' ev-people' : '') . ($o['reverse'] ? ' is-reverse' : '');
    echo '<div class="' . $cls . '" style="--ev-dur: ' . $dur . 's;" role="region" aria-label="' . e($o['label']) . '"><div class="card-grid ev-track">';
    for ($copy = 0; $copy < 2; $copy++) {
        for ($r = 0; $r < $reps; $r++) {
            foreach ($cards as $html) {
                echo ($copy === 0 && $r === 0) ? $html : preg_replace('/^<(a|div) /', '<$1 aria-hidden="true" tabindex="-1" ', $html, 1);
            }
        }
    }
    echo '</div></div>';
}

/** Runs a card renderer and returns its HTML. */
function card_html(callable $render, ...$args) {
    ob_start();
    $render(...$args);
    return trim(ob_get_clean());
}
