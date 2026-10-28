<?php
/**
 * Facebook-style profile header: wide cover photo, avatar overlapping the
 * bottom-left with a status-note bubble, name + verified badge, a meta line
 * and an action row on the right.
 *
 * $u    — users row (needs first/last name, role, profile_photo, cover_photo, status_note)
 * $opts — editable (bool: show upload / note controls), meta (string), actions (html string)
 *
 * Call after includes/header.php (needs asset_v() and the icon sprite).
 */
function render_profile_header(array $u, array $opts = []) {
    $editable = !empty($opts['editable']);
    $meta     = $opts['meta'] ?? '';
    $actions  = $opts['actions'] ?? '';
    $cover    = $u['cover_photo'] ?? null;
    $photo    = $u['profile_photo'] ?? null;
    $note     = trim((string) ($u['status_note'] ?? ''));
    $verified = in_array($u['role'] ?? '', ['admin', 'officer', 'moderator'], true);
    ?>
    <section class="fb-profile">
        <div class="fb-cover<?php echo $cover ? '' : ' fb-cover-default'; ?>">
            <?php if ($cover): ?>
                <img src="<?php echo asset_v($cover); ?>" alt="" class="fb-cover-img" onerror="this.remove(); this.parentElement.classList.add('fb-cover-default')">
            <?php else: ?>
                <svg class="fb-cover-art" viewBox="0 0 800 220" preserveAspectRatio="xMidYMid slice" fill="none" aria-hidden="true">
                    <g stroke="currentColor" stroke-width="1.2" stroke-linecap="round">
                        <path d="M0 60h150l36 36h140"/><path d="M0 150h110l30-30h190"/><path d="M800 50H640l-38 38H480"/>
                        <path d="M800 160H690l-30 30H520"/><path d="M380 0v40l24 24"/><path d="M560 220v-40l-24-24"/>
                    </g>
                    <g fill="currentColor"><circle cx="326" cy="96" r="4"/><circle cx="330" cy="120" r="4"/><circle cx="480" cy="88" r="4"/><circle cx="520" cy="156" r="4"/></g>
                </svg>
            <?php endif; ?>

            <?php if ($editable): ?>
                <form method="POST" enctype="multipart/form-data" class="fb-upload-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="profile_action" value="cover">
                    <input type="file" name="cover_photo" id="coverInput" accept="image/png,image/jpeg,image/webp,image/gif" hidden onchange="this.form.submit()">
                    <label for="coverInput" class="fb-edit-cover"><svg class="icon" aria-hidden="true"><use href="#i-camera"/></svg>Edit cover photo</label>
                </form>
            <?php endif; ?>
        </div>

        <div class="fb-bar">
            <div class="fb-avatar-wrap">
                <?php if ($editable): ?>
                    <button type="button" class="fb-note<?php echo $note === '' ? ' fb-note-empty' : ''; ?>" data-note-toggle aria-expanded="false" aria-controls="noteForm">
                        <?php if ($note !== ''): ?>
                            <span class="fb-note-text"><?php echo e($note); ?></span>
                        <?php else: ?>
                            <span class="fb-note-text"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>Add note</span>
                        <?php endif; ?>
                    </button>
                    <form method="POST" class="fb-note-form" id="noteForm" hidden>
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="profile_action" value="note">
                        <input aria-label="Share a thought (60 characters)" type="text" name="status_note" maxlength="60" placeholder="Share a thought (60 characters)" value="<?php echo e($note); ?>">
                        <div class="fb-note-buttons">
                            <button type="submit" class="btn btn-primary btn-sm">Save</button>
                            <?php if ($note !== ''): ?><button type="submit" name="clear_note" value="1" class="btn btn-outline btn-sm">Clear</button><?php endif; ?>
                        </div>
                    </form>
                <?php elseif ($note !== ''): ?>
                    <div class="fb-note fb-note-static"><span class="fb-note-text"><?php echo e($note); ?></span></div>
                <?php endif; ?>

                <?php if ($photo): ?>
                    <img src="<?php echo asset_v($photo); ?>" alt="<?php echo e(full_name($u)); ?>" class="fb-avatar">
                <?php else: ?>
                    <span class="fb-avatar fb-avatar-fallback"><?php echo e(initials($u['first_name'] ?? '', $u['last_name'] ?? '')); ?></span>
                <?php endif; ?>

                <?php if ($editable): ?>
                    <form method="POST" enctype="multipart/form-data" class="fb-avatar-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="profile_action" value="avatar">
                        <input type="file" name="profile_photo" id="avatarInput" accept="image/png,image/jpeg,image/webp,image/gif" hidden onchange="this.form.submit()">
                        <label for="avatarInput" class="fb-avatar-edit" title="Change profile photo" aria-label="Change profile photo"><svg class="icon" aria-hidden="true"><use href="#i-camera"/></svg></label>
                    </form>
                <?php endif; ?>
            </div>

            <div class="fb-info">
                <h1 class="fb-name">
                    <?php echo e(full_name($u)); ?>
                    <?php echo verified_badge_html($u['verified_as'] ?? 'none'); ?>
                    <?php if ($verified): ?><svg class="icon icon-verified" role="img" aria-label="Verified CSO <?php echo e($u['role']); ?>"><title>Verified CSO <?php echo e($u['role']); ?></title><use href="#i-verified"/></svg><?php endif; ?>
                </h1>
                <?php if ($meta !== ''): ?><p class="fb-meta"><?php echo $meta; ?></p><?php endif; ?>
            </div>

            <?php if ($actions !== ''): ?><div class="fb-actions"><?php echo $actions; ?></div><?php endif; ?>
        </div>
    </section>
    <?php
}
