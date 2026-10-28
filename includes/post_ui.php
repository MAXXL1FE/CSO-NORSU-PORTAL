<?php
/**
 * Facebook-style posts for events and announcements.
 *
 *  - post_images table helpers (many photos per post)
 *  - render_post_gallery()  photo collage (1 to 5+ photos) with "+N"
 *  - render_fb_post()       the post card members see
 *  - render_composer()      the "Create post" popup staff use
 *
 * Render functions run after includes/header.php (they use asset_v()).
 */

if (!defined('POST_MAX_IMAGES')) define('POST_MAX_IMAGES', 10);

/** SQL fragment: author columns, aliased so they never clash with the post's own columns. */
function post_author_select() {
    return "u.user_id AS author_id, u.first_name AS author_first, u.middle_name AS author_middle,
            u.last_name AS author_last, u.suffix AS author_suffix, u.profile_photo AS author_photo,
            u.role AS author_role, u.committee_role AS author_committee";
}

// ---------------------------------------------------------------
// Images: storage helpers
// ---------------------------------------------------------------

/** [post_id => [ ['id' => image_id, 'path' => '/assets/...'], ... ]] for many posts in one query. */
function post_images_map($conn, $type, array $ids) {
    $map = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return $map;
    $in = implode(',', $ids); // integers only
    $stmt = $conn->prepare("SELECT image_id, post_id, image_path FROM post_images WHERE post_type = ? AND post_id IN ($in) ORDER BY sort_order ASC, image_id ASC");
    if (!$stmt) return $map;
    $stmt->bind_param('s', $type);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $map[(int) $r['post_id']][] = ['id' => (int) $r['image_id'], 'path' => $r['image_path']];
    }
    return $map;
}

/** Images of one post. Falls back to the old single-image column for rows saved before multi-photo posts existed. */
function post_images_for($conn, $type, $id, $legacy_path = null) {
    $map = post_images_map($conn, $type, [$id]);
    $list = $map[(int) $id] ?? [];
    if (!$list && !empty($legacy_path)) $list[] = ['id' => 0, 'path' => $legacy_path];
    return $list;
}

/** Keeps events.cover_image / announcements.image pointing at the first photo, so thumbnails elsewhere keep working. */
function sync_post_cover($conn, $type, $post_id) {
    $first = null;
    $s = $conn->prepare("SELECT image_path FROM post_images WHERE post_type = ? AND post_id = ? ORDER BY sort_order ASC, image_id ASC LIMIT 1");
    if (!$s) return;
    $s->bind_param('si', $type, $post_id);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    if ($row) $first = $row['image_path'];
    if ($type === 'event') {
        $u = $conn->prepare("UPDATE events SET cover_image = ? WHERE event_id = ?");
    } else {
        $u = $conn->prepare("UPDATE announcements SET image = ? WHERE announcement_id = ?");
    }
    if (!$u) return;
    $u->bind_param('si', $first, $post_id);
    $u->execute();
}

/**
 * Saves every photo sent in $_FILES[$field] (a multiple file input) for one post.
 * Returns ['ok' => bool, 'error' => string|null, 'saved' => int]; photos that are fine
 * are kept even when another one in the same post fails.
 */
function save_post_images($conn, $type, $post_id, $subdir, $field = 'images') {
    $result = ['ok' => true, 'error' => null, 'saved' => 0];
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field]['name'])) return $result;

    $c = $conn->prepare("SELECT COUNT(*) AS c, COALESCE(MAX(sort_order), -1) AS m FROM post_images WHERE post_type = ? AND post_id = ?");
    $c->bind_param('si', $type, $post_id);
    $c->execute();
    $info = $c->get_result()->fetch_assoc();
    $existing = (int) $info['c'];
    $next = (int) $info['m'] + 1;

    $ins = $conn->prepare("INSERT INTO post_images (post_type, post_id, image_path, sort_order) VALUES (?, ?, ?, ?)");
    if (!$ins) return ['ok' => false, 'error' => 'Photos could not be saved — the post_images table is missing. Reload the page so the database updates itself, then try again.', 'saved' => 0];

    foreach ($_FILES[$field]['name'] as $i => $unused) {
        $file = [
            'name'     => $_FILES[$field]['name'][$i],
            'type'     => $_FILES[$field]['type'][$i],
            'tmp_name' => $_FILES[$field]['tmp_name'][$i],
            'error'    => $_FILES[$field]['error'][$i],
            'size'     => $_FILES[$field]['size'][$i],
        ];
        if ($file['error'] === UPLOAD_ERR_NO_FILE) continue;
        if ($existing + $result['saved'] >= POST_MAX_IMAGES) {
            $result['ok'] = false;
            $result['error'] = 'A post holds up to ' . POST_MAX_IMAGES . ' photos — the extra ones were skipped.';
            break;
        }
        $up = handle_image_upload_file($file, $subdir);
        if (!$up['ok']) {
            $result['ok'] = false;
            $result['error'] = $up['error'];
            continue;
        }
        if (empty($up['path'])) continue;
        $ins->bind_param('sisi', $type, $post_id, $up['path'], $next);
        $ins->execute();
        $next++;
        $result['saved']++;
    }
    sync_post_cover($conn, $type, $post_id);
    return $result;
}

/** Deletes chosen photos (rows + files) of one post. */
function remove_post_images($conn, $type, $post_id, array $image_ids) {
    foreach ($image_ids as $iid) {
        $iid = (int) $iid;
        if ($iid <= 0) continue;
        $s = $conn->prepare("SELECT image_path FROM post_images WHERE image_id = ? AND post_type = ? AND post_id = ?");
        $s->bind_param('isi', $iid, $type, $post_id);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        if (!$row) continue;
        delete_uploaded_image($row['image_path']);
        $d = $conn->prepare("DELETE FROM post_images WHERE image_id = ?");
        $d->bind_param('i', $iid);
        $d->execute();
    }
    sync_post_cover($conn, $type, $post_id);
}

/** Deletes every photo (rows + files) of a post that is being deleted. */
function delete_post_images_all($conn, $type, $post_id) {
    $s = $conn->prepare("SELECT image_path FROM post_images WHERE post_type = ? AND post_id = ?");
    if (!$s) return;
    $s->bind_param('si', $type, $post_id);
    $s->execute();
    $res = $s->get_result();
    while ($r = $res->fetch_assoc()) delete_uploaded_image($r['image_path']);
    $d = $conn->prepare("DELETE FROM post_images WHERE post_type = ? AND post_id = ?");
    if ($d) {
        $d->bind_param('si', $type, $post_id);
        $d->execute();
    }
}

// ---------------------------------------------------------------
// Display helpers
// ---------------------------------------------------------------

/** "Just now", "5 mins ago", "3 hrs ago", "5 days ago", then a date. */
function time_ago($ts) {
    $d = time() - $ts;
    if ($d < 60) return 'Just now';
    if ($d < 3600) { $m = (int) floor($d / 60); return $m . ' min' . ($m > 1 ? 's' : '') . ' ago'; }
    if ($d < 86400) { $h = (int) floor($d / 3600); return $h . ' hr' . ($h > 1 ? 's' : '') . ' ago'; }
    if ($d < 7 * 86400) { $n = (int) floor($d / 86400); return $n . ' day' . ($n > 1 ? 's' : '') . ' ago'; }
    if (date('Y', $ts) === date('Y')) return date('F j \a\t g:i A', $ts);
    return date('F j, Y', $ts);
}

/** Round profile picture (photo or initials). */
function render_post_avatar($photo, $first, $last, $class = 'pf-avatar') {
    if (!empty($photo)) {
        echo '<img src="' . e(asset_v($photo)) . '" alt="" class="' . e($class) . '" loading="lazy">';
    } else {
        echo '<span class="' . e($class) . ' pf-avatar-fallback" aria-hidden="true">' . e(initials((string) $first, (string) $last)) . '</span>';
    }
}

/**
 * Photo collage, Facebook style: 1 photo large, 2 side by side, 3 = one big + two,
 * 4 = 2x2, 5+ = 2 over 3 with a "+N" on the last tile. Clicking opens the lightbox
 * (assets/js/app.js); photos beyond the 5 shown stay in the lightbox.
 */
function render_post_gallery(array $images, $alt = '') {
    $n = count($images);
    if ($n === 0) return;
    $shown = min($n, 5);
    ?>
    <div class="pg pg-<?php echo $shown; ?>" data-gallery>
        <?php foreach ($images as $i => $img):
            $url = asset_v($img['path']);
            $visible = $i < $shown;
            $extra = $n - $shown;
            ?>
            <a href="<?php echo e($url); ?>" class="pg-item"<?php echo $visible ? '' : ' hidden'; ?> data-index="<?php echo (int) $i; ?>" aria-label="Open photo <?php echo (int) ($i + 1); ?> of <?php echo (int) $n; ?>">
                <?php if ($visible): ?><img src="<?php echo e($url); ?>" alt="<?php echo e($alt); ?>" loading="lazy"><?php endif; ?>
                <?php if ($visible && $i === $shown - 1 && $extra > 0): ?><span class="pg-more">+<?php echo (int) $extra; ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
}

/**
 * One Facebook-style post card.
 *   $type   'event' | 'announcement'
 *   $row    the post row + the post_author_select() columns
 *   $opts   ['full' => bool, 'edit_url' => string, 'delete_fields' => [name => value]]
 *           (edit_url / delete_fields add the "..." menu for staff)
 */
function render_fb_post(array $row, $type, array $images = [], array $opts = []) {
    $is_event = ($type === 'event');
    $id = (int) ($is_event ? $row['event_id'] : $row['announcement_id']);
    $detail = BASE_URL . ($is_event ? '/event.php?id=' : '/announcement.php?id=') . $id;

    $body = (string) ($is_event ? ($row['description'] ?? '') : ($row['content'] ?? ''));
    // Long posts show a short preview with "See more"; the full text sits next to it and opens in place (assets/js/app.js).
    $limit = !empty($opts['full']) ? PHP_INT_MAX : 320;
    $preview = $body;
    $too_many_lines = false;
    if ($limit !== PHP_INT_MAX) {
        $lines = preg_split('/\R/', $body);
        if (count($lines) > 5) {
            $too_many_lines = true;
            $preview = implode("\n", array_slice($lines, 0, 5));
        }
        if (mb_strlen($preview) > $limit) $preview = mb_substr($preview, 0, $limit);
        $preview = rtrim($preview);
    }
    $long = ($limit !== PHP_INT_MAX) && ($too_many_lines || mb_strlen($body) > $limit);

    $author = trim(full_name([
        'first_name'  => $row['author_first'] ?? '',
        'middle_name' => $row['author_middle'] ?? '',
        'last_name'   => $row['author_last'] ?? '',
        'suffix'      => $row['author_suffix'] ?? '',
    ]));
    if ($author === '') $author = 'CSO-NORSU';
    $role = $row['author_role'] ?? '';
    $role_label = !empty($row['author_committee']) ? $row['author_committee'] : ($role !== '' ? ucfirst($role) : 'CSO');
    $verified = in_array($role, ['admin', 'officer', 'moderator'], true);
    $created = strtotime($row['created_at']);

    $deadline_ts = (!$is_event && !empty($row['deadline'])) ? strtotime($row['deadline']) : null;

    if (!$images) {
        $legacy = $is_event ? ($row['cover_image'] ?? '') : ($row['image'] ?? '');
        if (!empty($legacy)) $images = [['id' => 0, 'path' => $legacy]];
    }
    ?>
    <article class="fbpost" id="post-<?php echo e($type); ?>-<?php echo $id; ?>">
        <header class="fbpost-head">
            <?php render_post_avatar($row['author_photo'] ?? null, $row['author_first'] ?? '', $row['author_last'] ?? ''); ?>
            <div class="fbpost-who">
                <strong class="fbpost-name"><?php echo e($author); ?><?php if ($verified): ?><svg class="icon icon-verified" role="img" aria-label="Verified CSO <?php echo e($role); ?>"><title>Verified CSO <?php echo e($role); ?></title><use href="#i-verified"/></svg><?php endif; ?></strong>
                <span class="fbpost-sub">
                    <span><?php echo e($role_label); ?></span>
                    <span aria-hidden="true">·</span>
                    <time datetime="<?php echo e(date('c', $created)); ?>" title="<?php echo e(date('l, F j, Y \a\t g:i A', $created)); ?>"><?php echo e(time_ago($created)); ?></time>
                    <span aria-hidden="true">·</span>
                    <svg class="icon" role="img" aria-label="Public"><title>Public</title><use href="#i-globe"/></svg>
                </span>
            </div>
            <?php if (!empty($opts['edit_url']) || !empty($opts['delete_fields'])): ?>
                <details class="fbpost-menu">
                    <summary aria-label="Post options"><svg class="icon" aria-hidden="true"><use href="#i-more"/></svg></summary>
                    <div class="fbpost-menu-list">
                        <?php if (!empty($opts['edit_url'])): ?>
                            <a href="<?php echo e($opts['edit_url']); ?>"><svg class="icon" aria-hidden="true"><use href="#i-pencil"/></svg>Edit post</a>
                        <?php endif; ?>
                        <?php if (!empty($opts['delete_fields'])): ?>
                            <form method="POST" <?php echo confirm_attr('Delete this post? This cannot be undone.'); ?>>
                                <?php echo csrf_field(); ?>
                                <?php foreach ($opts['delete_fields'] as $fname => $fval): ?>
                                    <input type="hidden" name="<?php echo e($fname); ?>" value="<?php echo e($fval); ?>">
                                <?php endforeach; ?>
                                <button type="submit" class="danger"><svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg>Delete post</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>
        </header>

        <div class="fbpost-body">
            <h3 class="fbpost-title"><a href="<?php echo e($detail); ?>"><?php echo e($row['title']); ?></a></h3>
            <?php if ($body !== ''): ?>
                <?php if ($long): ?>
                    <p class="fbpost-text"><span class="fbpost-short"><?php echo nl2br(e($preview)); ?>…</span><span class="fbpost-full" hidden><?php echo nl2br(e($body)); ?></span> <a href="<?php echo e($detail); ?>" class="fbpost-more" role="button" aria-expanded="false" data-see-more>See more</a></p>
                <?php else: ?>
                    <p class="fbpost-text"><?php echo nl2br(e($body)); ?></p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($is_event): ?>
                <div class="fbpost-event">
                    <span class="fbpost-date" aria-hidden="true"><?php if (!empty($row['event_date'])): ?><b><?php echo e(strtoupper(date('M', strtotime($row['event_date'])))); ?></b><i><?php echo e(date('j', strtotime($row['event_date']))); ?></i><?php else: ?><b>TBA</b><i>&ndash;</i><?php endif; ?></span>
                    <span class="fbpost-event-info">
                        <strong><?php echo e(event_range_text($row)); ?></strong>
                        <small><?php echo e(implode(' · ', array_filter([event_time_text($row), $row['venue']], function ($x) { return $x !== '' && $x !== null; }))); ?></small>
                    </span>
                    <span class="fbpost-event-chip"><?php echo event_state_chip($row); ?></span>
                </div>
            <?php elseif ($deadline_ts): ?>
                <div class="card-chips" style="margin-top:10px;">
                    <?php if ($deadline_ts > time()): ?>
                        <span class="chip chip-warn"><?php echo e(time_left_text($deadline_ts)); ?></span>
                    <?php else: ?>
                        <span class="chip chip-muted">Closed</span>
                    <?php endif; ?>
                    <span class="muted" style="font-size:.8rem;">Deadline: <?php echo e(date('M j, Y · g:i A', $deadline_ts)); ?></span>
                </div>
            <?php endif; ?>
        </div>

        <?php render_post_gallery($images, $row['title']); ?>

        <footer class="fbpost-foot">
            <a href="<?php echo e($detail); ?>" class="fbpost-action"><svg class="icon" aria-hidden="true"><use href="#i-info"/></svg><?php echo $is_event ? 'View event details' : 'Read full announcement'; ?></a>
            <?php if (isset($row['reg_count']) && $is_event): ?>
                <span class="fbpost-meta"><svg class="icon" aria-hidden="true"><use href="#i-users"/></svg><?php echo (int) $row['reg_count']; ?> registered</span>
            <?php endif; ?>
            <?php if (count($images) > 1): ?>
                <span class="fbpost-meta"><svg class="icon" aria-hidden="true"><use href="#i-photos"/></svg><?php echo count($images); ?> photos</span>
            <?php endif; ?>
        </footer>
    </article>
    <?php
}

/**
 * The "Create post" popup (Facebook style), used for creating and editing.
 *   id, heading, button (Post / Save), open (bool), close_url (edit mode: where Close goes)
 *   hidden        [name => value]  e.g. action, announcement_id
 *   title_value, body_name, body_value, body_required, body_placeholder
 *   details_html  required fields that are always shown (escaped by the caller)
 *   tools         [ ['key' => 'deadline', 'label' => 'Add a deadline', 'icon' => 'i-clock', 'html' => '...', 'open' => bool] ]
 *   images        existing photos [ ['id' => 1, 'path' => '...'] ]  (edit mode)
 */
function render_composer(array $o) {
    $first = $_SESSION['first_name'] ?? '';
    $last  = $_SESSION['last_name'] ?? '';
    $name  = trim(full_name([
        'first_name'  => $first,
        'middle_name' => $_SESSION['middle_name'] ?? '',
        'last_name'   => $last,
        'suffix'      => $_SESSION['suffix'] ?? '',
    ]));
    $id = $o['id'];
    $open = !empty($o['open']);
    $close_url = $o['close_url'] ?? '';
    $images = $o['images'] ?? [];
    $tools = $o['tools'] ?? [];
    ?>
    <div class="modal-overlay composer-overlay<?php echo $open ? ' open' : ''; ?>" id="<?php echo e($id); ?>" data-composer-overlay>
        <div class="modal-box composer" role="dialog" aria-modal="true" aria-labelledby="<?php echo e($id); ?>-title" data-composer data-max="<?php echo (int) POST_MAX_IMAGES; ?>" data-close-url="<?php echo e($close_url); ?>">
            <div class="composer-head">
                <h3 id="<?php echo e($id); ?>-title"><?php echo e($o['heading']); ?></h3>
                <?php if ($close_url !== ''): ?>
                    <a href="<?php echo e($close_url); ?>" class="composer-close" aria-label="Close" data-composer-cancel><svg class="icon" aria-hidden="true"><use href="#i-close"/></svg></a>
                <?php else: ?>
                    <button type="button" class="composer-close" aria-label="Close" data-composer-cancel><svg class="icon" aria-hidden="true"><use href="#i-close"/></svg></button>
                <?php endif; ?>
            </div>

            <form method="POST" enctype="multipart/form-data" class="composer-form">
                <?php echo csrf_field(); ?>
                <?php foreach (($o['hidden'] ?? []) as $hn => $hv): ?>
                    <input type="hidden" name="<?php echo e($hn); ?>" value="<?php echo e($hv); ?>">
                <?php endforeach; ?>

                <div class="composer-scroll">
                    <div class="composer-user">
                        <?php render_post_avatar($_SESSION['profile_photo'] ?? null, $first, $last); ?>
                        <div>
                            <strong><?php echo e($name !== '' ? $name : 'You'); ?></strong>
                            <span class="composer-audience"><svg class="icon" aria-hidden="true"><use href="#i-globe"/></svg>Public</span>
                        </div>
                    </div>

                    <label class="composer-label" for="<?php echo e($id); ?>-t">Title</label>
                    <input type="text" id="<?php echo e($id); ?>-t" name="title" class="composer-title" placeholder="<?php echo e($o['title_placeholder'] ?? 'Title'); ?>" maxlength="150" required value="<?php echo e($o['title_value'] ?? ''); ?>" aria-label="Title">
                    <label class="composer-label" for="<?php echo e($id); ?>-b"><?php echo e($o['body_label'] ?? 'Description'); ?></label>
                    <textarea id="<?php echo e($id); ?>-b" name="<?php echo e($o['body_name']); ?>" class="composer-text" rows="3" placeholder="<?php echo e($o['body_placeholder'] ?? ("What's on your mind, " . ($first !== '' ? $first : 'there') . '?')); ?>"<?php echo !empty($o['body_required']) ? ' required' : ''; ?> aria-label="Post text"><?php echo e($o['body_value'] ?? ''); ?></textarea>

                    <?php if (!empty($o['details_html'])): ?>
                        <div class="composer-details"><?php if (!empty($o['details_title'])): ?><div class="composer-details-title"><?php echo e($o['details_title']); ?></div><?php endif; ?><?php echo $o['details_html']; ?></div>
                    <?php endif; ?>

                    <?php foreach ($tools as $t): ?>
                        <div class="composer-section" data-section="<?php echo e($t['key']); ?>"<?php echo !empty($t['open']) ? '' : ' hidden'; ?>>
                            <div class="composer-section-head">
                                <span><svg class="icon" aria-hidden="true"><use href="#<?php echo e($t['icon']); ?>"/></svg><?php echo e($t['label']); ?></span>
                                <button type="button" class="composer-section-x" data-section-off="<?php echo e($t['key']); ?>" aria-label="Remove <?php echo e($t['label']); ?>">&times;</button>
                            </div>
                            <?php echo $t['html']; ?>
                        </div>
                    <?php endforeach; ?>

                    <div class="cx-grid" data-composer-grid>
                        <?php foreach ($images as $img): if (empty($img['id'])) continue; ?>
                            <div class="cx-item" data-existing="<?php echo (int) $img['id']; ?>">
                                <img src="<?php echo e(asset_v($img['path'])); ?>" alt="">
                                <button type="button" class="cx-remove" data-remove-existing="<?php echo (int) $img['id']; ?>" aria-label="Remove photo">&times;</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="composer-hint" data-composer-hint aria-live="polite"></p>
                </div>

                <div class="composer-add">
                    <span class="composer-add-title">Add to your post</span>
                    <div class="composer-tools">
                        <button type="button" class="composer-tool tool-photo" data-composer-pick title="Photos" aria-label="Add photos"><svg class="icon" aria-hidden="true"><use href="#i-photos"/></svg><span class="composer-tool-label">Photos</span></button>
                        <?php foreach ($tools as $t): ?>
                            <button type="button" class="composer-tool tool-<?php echo e($t['key']); ?>" data-section-toggle="<?php echo e($t['key']); ?>" title="<?php echo e($t['label']); ?>" aria-label="<?php echo e($t['label']); ?>" aria-pressed="<?php echo !empty($t['open']) ? 'true' : 'false'; ?>"><svg class="icon" aria-hidden="true"><use href="#<?php echo e($t['icon']); ?>"/></svg><span class="composer-tool-label"><?php echo e($t['label']); ?></span></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <input type="file" name="images[]" multiple accept="image/png,image/jpeg,image/webp,image/gif" hidden data-composer-files>
                <div class="composer-foot">
                    <button type="button" class="btn btn-outline composer-cancel" data-composer-cancel>Cancel</button>
                    <button type="submit" class="btn btn-primary composer-post" disabled><?php echo e($o['button'] ?? 'Post'); ?></button>
                </div>
            </form>
        </div>
    </div>
    <?php
}
