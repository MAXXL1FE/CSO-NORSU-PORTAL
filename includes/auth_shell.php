<?php
/**
 * Shared layout for Log in and Create account: one centered, clean card.
 *
 * Usage:
 *   auth_stage_start('login');   // or 'register' (wider card)
 *   ... page content ...
 *   auth_stage_end();
 */
function auth_stage_start($mode) {
    $wide = $mode === 'register' ? ' signin-card-wide' : '';
    ?>
<main class="signin-page">
    <div class="signin-card<?php echo $wide; ?>">
        <div class="signin-brand">
            <img class="signin-logo" src="<?php echo asset_v('/assets/images/logo.png'); ?>" alt="CSO-NORSU logo" width="48" height="48"
                 onerror="this.style.display='none'">
            <div>
                <p class="signin-name">CSO-NORSU</p>
                <p class="signin-org">Computer Science Organization</p>
            </div>
        </div>
    <?php
}

function auth_stage_end() {
    ?>
    </div>
    <button type="button" class="theme-toggle signin-theme" id="themeToggle" aria-label="Switch between light and dark mode" title="Switch theme">
        <svg class="icon i-sun" aria-hidden="true"><use href="#i-sun"/></svg>
        <svg class="icon i-moon" aria-hidden="true"><use href="#i-moon"/></svg>
    </button>
</main>
    <?php
}
