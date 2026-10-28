<?php
/**
 * Loading placeholders that copy each page's real layout.
 *
 * Every placeholder reuses the page's own CSS classes (section, container, card-grid,
 * post-card, detail-layout, stat-grid, panel, signin-card, ...), so the grid, spacing and
 * card sizes match the page that follows. Only the content inside is replaced by grey blocks.
 */

function skeleton_variant() {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $file   = basename($script);
    if (strpos($script, '/dashboard/') !== false) {
        if ($file === 'map.php') return 'dash-map';
        if ($file === 'admin.php') return 'dash-stats';
        if (in_array($file, ['profile.php', 'reset_codes.php', 'info.php', 'member.php'], true)) return 'dash-form';
        return 'dash-table';
    }
    if (in_array($file, ['login.php'], true))    return 'login';
    if (in_array($file, ['register.php'], true)) return 'register';
    if (in_array($file, ['forgot_password.php', 'verify_code.php', 'reset_password.php'], true)) return 'simple-auth';
    if ($file === 'admin_login.php') return 'admin-login';
    if ($file === 'faculty_login.php') return 'admin-login';
    $map = [
        'home.php' => 'home', 'index.php' => 'home',
        'events.php' => 'events', 'event.php' => 'detail', 'announcement.php' => 'detail',
        'announcements.php' => 'feed', 'map.php' => 'map',
        'directory.php' => 'directory', 'officers.php' => 'officers', 'officer.php' => 'detail',
        'members.php' => 'members', 'curriculum.php' => 'curriculum',
        'instructions.php' => 'doc', 'terms.php' => 'doc', 'unauthorized.php' => 'doc', 'checkin.php' => 'simple-auth',
    ];
    return $map[$file] ?? 'doc';
}

/* ---------- building blocks ---------- */
function sk_bar($w = '100%', $h = '14px', $extra = '') {
    return '<span class="sk" style="width:' . $w . ';height:' . $h . ';' . $extra . '"></span>';
}
function sk_lines(array $widths, $h = '13px', $gap = '12px') {
    $out = '<div style="display:grid;gap:' . $gap . ';">';
    foreach ($widths as $w) $out .= sk_bar($w, $h);
    return $out . '</div>';
}
function sk_topbar() {
    return '<div class="sk-topbar">'
        . sk_bar('36px', '36px', 'border-radius:50%;flex:none;')
        . '<div style="display:grid;gap:6px;">' . sk_bar('120px', '12px') . sk_bar('170px', '9px') . '</div>'
        . '<span style="flex:1;"></span>'
        . sk_bar('36px', '36px', 'border-radius:50%;')
        . sk_bar('150px', '40px', 'border-radius:999px;')
        . '</div>';
}

/* ---------- pages ---------- */
function skv_home() {
    $cards = '';
    for ($i = 0; $i < 3; $i++) {
        $cards .= '<a class="card card-link post-card" style="pointer-events:none;">' . sk_bar('100%', '170px', 'border-radius:0;')
            . '<div style="padding:16px 20px 18px;">' . sk_bar('70%', '16px') . '<div style="height:10px"></div>'
            . sk_lines(['85%', '60%'], '12px', '10px') . '</div></a>';
    }
    $officers = '';
    for ($i = 0; $i < 4; $i++) {
        $officers .= '<div class="card officer-card">' . sk_bar('88px', '88px', 'border-radius:50%;margin:0 auto 12px;')
            . sk_bar('60%', '14px', 'margin:0 auto 8px;') . sk_bar('45%', '11px', 'margin:0 auto;') . '</div>';
    }
    $stats = '';
    for ($i = 0; $i < 4; $i++) {
        $stats .= '<div class="card cso-stat">' . sk_bar('50%', '28px', 'margin:0 auto 8px;') . sk_bar('70%', '11px', 'margin:0 auto;') . '</div>';
    }
    echo '<section class="hero-banner"><span class="sk" style="width:100%;max-width:1120px;aspect-ratio:1600/592;height:auto;border-radius:14px;"></span></section>';
    echo '<section class="hero-cta"><div class="container">' . sk_bar('260px', '22px', 'margin-bottom:16px;')
        . '<div class="hero-buttons">' . sk_bar('140px', '50px', 'border-radius:8px;') . sk_bar('150px', '50px', 'border-radius:8px;') . '</div></div></section>';
    echo '<section class="section"><div class="container"><div class="about-block">'
        . '<span class="sk" style="width:100%;aspect-ratio:1;border-radius:14px;"></span>'
        . '<div class="about-block-text">' . sk_bar('40%', '26px', 'margin-bottom:12px;') . sk_lines(['100%', '96%', '88%', '70%'], '13px', '11px') . '</div>'
        . '</div><div class="cso-stats" style="margin-top:28px;">' . $stats . '</div></div></section>';
    echo '<section class="section alt"><div class="container"><div class="section-head">' . sk_bar('240px', '24px') . sk_bar('150px', '13px') . '</div>'
        . '<div class="card-grid">' . $cards . '</div></div></section>';
    echo '<section class="section"><div class="container"><div class="section-head">' . sk_bar('260px', '24px') . sk_bar('180px', '13px') . '</div>'
        . '<div class="card-grid">' . $cards . '</div></div></section>';
    echo '<section class="section alt"><div class="container"><div class="section-head">' . sk_bar('180px', '24px') . '</div>'
        . '<div class="card-grid">' . $officers . '</div></div></section>';
}

function skv_events() {
    $tiles = '';
    for ($i = 0; $i < 6; $i++) {
        $tiles .= '<div class="event-tile" style="pointer-events:none;">' . sk_bar('100%', 'auto', 'aspect-ratio:4/3;border-radius:0;')
            . '<div class="event-tile-body">' . sk_bar('75%', '17px') . sk_bar('50%', '12px') . sk_bar('90%', '12px') . '</div></div>';
    }
    echo '<nav class="crumbs" aria-hidden="true">' . sk_bar('16px', '16px', 'border-radius:4px;') . sk_bar('12px', '12px') . sk_bar('60px', '12px') . '</nav>';
    echo '<section class="section" style="padding-top:12px;"><div class="container">'
        . sk_bar('180px', '36px', 'margin:4px 0 18px;')
        . '<h2 class="rule-heading" style="visibility:hidden;margin:22px 0 18px;"><span>&nbsp;</span></h2>'
        . '<div class="list-controls"><span class="sk" style="width:250px;height:38px;border-radius:999px;"></span>'
        . '<span class="sk" style="width:260px;height:40px;"></span></div>'
        . '<div class="event-grid" style="margin-top:18px;">' . $tiles . '</div></div></section>';
}

function skv_detail() {
    $gallery = sk_bar('100%', 'auto', 'aspect-ratio:16/9;max-height:480px;border-radius:14px;margin-bottom:24px;');
    echo '<section class="section detail-section"><div class="container"><div class="detail-layout">'
        . '<article class="detail-main">' . $gallery
        . '<div class="detail-chips">' . sk_bar('90px', '24px', 'border-radius:999px;') . sk_bar('110px', '24px', 'border-radius:999px;') . '</div>'
        . '<div style="margin:14px 0 12px;">' . sk_bar('78%', '34px') . '</div>'
        . '<div style="margin-bottom:26px;">' . sk_bar('45%', '12px') . '</div>'
        . sk_bar('40%', '20px', 'margin-bottom:14px;')
        . sk_lines(['100%', '98%', '92%', '96%', '60%'], '13px', '12px')
        . '</article>'
        . '<aside class="detail-aside"><div class="side-card" style="display:grid;gap:14px;padding:20px;">'
        . sk_bar('45%', '18px') . sk_lines(['90%', '75%', '82%'], '12px', '14px')
        . sk_bar('100%', '44px', 'border-radius:8px;margin-top:6px;')
        . '</div></aside>'
        . '</div></div></section>';
}

function skv_feed() {
    $post = '<article class="fbpost" style="pointer-events:none;"><div class="fbpost-head" style="display:flex;gap:12px;align-items:center;padding:18px 18px 8px 22px;">'
        . sk_bar('42px', '42px', 'border-radius:50%;flex:none;') . '<div style="display:grid;gap:7px;flex:1;">' . sk_bar('40%', '13px') . sk_bar('25%', '10px') . '</div></div>'
        . '<div style="padding:10px 22px 16px;display:grid;gap:12px;">' . sk_bar('55%', '18px') . sk_lines(['96%', '88%', '64%'], '12px', '10px') . '</div>'
        . '<div style="padding:0 0 0;">' . sk_bar('100%', '260px', 'border-radius:0;') . '</div></article>';
    echo '<section class="section"><div class="container">'
        . sk_bar('200px', '30px', 'margin-bottom:12px;') . sk_bar('55%', '13px', 'margin-bottom:22px;')
        . '<div class="sk" style="width:100%;height:44px;border-radius:10px;max-width:680px;margin:0 0 22px;"></div>'
        . '<div class="feed">' . $post . $post . $post . '</div></div></section>';
}

function skv_map() {
    echo '<div class="page-head" style="margin-top:26px;">' . '<div style="display:grid;gap:10px;">' . sk_bar('240px', '32px') . sk_bar('360px', '13px') . '</div></div>';
    echo '<div class="panel map-finder" style="margin-bottom:18px;">' . sk_bar('260px', '12px', 'margin-bottom:10px;')
        . '<div class="map-finder-row"><span class="sk" style="flex:1 1 280px;height:48px;"></span><span class="sk" style="flex:1 1 220px;height:48px;"></span></div></div>';
    echo '<div class="map-stage">'
        . '<div class="campus-map" style="position:relative;"><span class="sk" style="display:block;width:100%;aspect-ratio:2048/2018;border-radius:14px;"></span></div>'
        . '<section class="map-detail" style="display:grid;gap:14px;">' . sk_bar('55%', '22px') . sk_lines(['90%'], '12px', '10px')
        . '<div class="floor-tabs">' . sk_bar('90px', '34px', 'border-radius:999px;') . sk_bar('90px', '34px', 'border-radius:999px;') . sk_bar('90px', '34px', 'border-radius:999px;') . '</div>'
        . sk_bar('100%', '54px', 'border-radius:10px;') . sk_bar('100%', '54px', 'border-radius:10px;') . sk_bar('100%', '54px', 'border-radius:10px;')
        . '</section></div>';
}

function skv_directory() {
    $cards = '';
    for ($i = 0; $i < 6; $i++) {
        $cards .= '<li class="dir-card">' . sk_bar('76px', '76px', 'border-radius:50%;flex:none;')
            . '<div style="display:grid;gap:8px;flex:1;">' . sk_bar('75%', '16px') . sk_bar('55%', '12px') . sk_bar('40%', '11px') . '</div></li>';
    }
    echo '<div class="page-head" style="margin-top:26px;">' . '<div style="display:grid;gap:10px;">' . sk_bar('300px', '32px') . sk_bar('380px', '13px') . '</div></div>';
    echo '<div class="panel dir-filters"><div class="dir-filter-grid">'
        . '<div style="display:grid;gap:8px;">' . sk_bar('40%', '12px') . sk_bar('100%', '44px', 'border-radius:8px;') . '</div>'
        . '<div style="display:grid;gap:8px;">' . sk_bar('20%', '12px') . sk_bar('100%', '44px', 'border-radius:8px;') . '</div>'
        . '<div style="display:grid;gap:8px;">' . sk_bar('35%', '12px') . sk_bar('100%', '44px', 'border-radius:8px;') . '</div>'
        . '</div><div class="dir-filter-actions">' . sk_bar('130px', '44px', 'border-radius:8px;') . sk_bar('90px', '44px', 'border-radius:8px;') . '</div></div>';
    echo '<div style="margin:0 0 12px;">' . sk_bar('90px', '13px') . '</div>';
    echo '<ul class="dir-grid">' . $cards . '</ul>';
}

function skv_officers() {
    $cards = '';
    for ($i = 0; $i < 6; $i++) {
        $cards .= '<div class="card officer-card">' . sk_bar('88px', '88px', 'border-radius:50%;margin:0 auto 12px;')
            . sk_bar('65%', '16px', 'margin:0 auto 8px;') . sk_bar('45%', '12px', 'margin:0 auto;') . '</div>';
    }
    echo '<section class="home-hero-wrap"><div class="home-hero page-hero"><span class="sk" style="position:absolute;inset:0;border-radius:0;"></span></div></section>';
    echo '<section class="section"><div class="container">' . sk_bar('240px', '30px', 'margin-bottom:18px;')
        . '<div style="display:flex;gap:10px;margin-bottom:22px;">' . sk_bar('100%', '44px', 'border-radius:8px;max-width:520px;') . sk_bar('100px', '44px', 'border-radius:8px;') . '</div>'
        . '<div class="card-grid">' . $cards . '</div></div></section>';
}

function skv_members() {
    $tiles = '';
    for ($i = 0; $i < 12; $i++) $tiles .= sk_bar('100%', '56px', 'border-radius:10px;');
    echo '<section class="home-hero-wrap"><div class="home-hero page-hero"><span class="sk" style="position:absolute;inset:0;border-radius:0;"></span></div></section>';
    echo '<div class="container" style="padding-top:28px;">';
    echo '<div class="members-search"><span class="sk" style="flex:1 1 260px;height:44px;"></span><span class="sk" style="width:110px;height:44px;"></span></div>';
    echo '<div style="margin:6px 0 14px;">' . sk_bar('90px', '13px') . '</div>';
    echo '<div class="tile-grid">' . $tiles . '</div>';
    echo '</div>';
}

function skv_curriculum() {
    $years = '';
    for ($i = 0; $i < 4; $i++) {
        $years .= '<section class="curr-year">' . sk_bar('60%', '15px', 'margin-bottom:12px;')
            . '<div style="display:grid;gap:10px;">' . sk_bar('100%', '14px') . sk_bar('90%', '14px') . sk_bar('80%', '14px') . '</div></section>';
    }
    echo '<nav class="crumbs" aria-hidden="true">' . sk_bar('16px', '16px', 'border-radius:4px;') . sk_bar('12px', '12px') . sk_bar('80px', '12px') . '</nav>';
    echo '<div class="curr-intro"><div>' . sk_bar('260px', '36px', 'margin:4px 0 16px;') . sk_lines(['100%', '95%', '70%'], '13px', '11px') . '</div>'
        . '<span class="sk" style="width:100%;aspect-ratio:4/3;border-radius:16px;"></span></div>';
    echo '<h2 class="rule-heading" style="visibility:hidden;"><span>&nbsp;</span></h2>';
    echo '<div class="curr-box"><div class="curr-box-head">' . sk_bar('340px', '22px', 'margin-bottom:8px;') . sk_bar('260px', '12px', 'margin-bottom:18px;') . '</div>'
        . '<div class="curr-years">' . $years . '</div></div>';
}

function skv_doc() {
    echo '<section class="section"><div class="container narrow">' . sk_bar('240px', '32px', 'margin-bottom:12px;') . sk_bar('70%', '13px', 'margin-bottom:26px;')
        . '<div style="display:grid;gap:14px;">';
    foreach ([[40, 3], [35, 4], [45, 2]] as $sec) {
        echo sk_bar($sec[0] . '%', '18px', 'margin-top:10px;');
        for ($j = 0; $j < $sec[1]; $j++) echo sk_bar(($j === $sec[1] - 1 ? '65%' : '100%'), '12px');
    }
    echo '</div></div></section>';
}

function skv_simple_auth() {
    echo '<section class="section auth-section"><div class="container narrow">'
        . sk_bar('55%', '32px', 'margin-bottom:10px;') . sk_bar('90%', '13px', 'margin-bottom:26px;')
        . '<div style="display:grid;gap:18px;">'
        . '<div style="display:grid;gap:8px;">' . sk_bar('35%', '13px') . sk_bar('100%', '46px', 'border-radius:8px;') . '</div>'
        . sk_bar('100%', '48px', 'border-radius:8px;')
        . '</div></div></section>';
}

function skv_login() {
    echo '<div class="signin-card">' . skeleton_brand_row()
        . sk_bar('60%', '26px', 'margin-bottom:8px;') . sk_bar('80%', '13px', 'margin-bottom:24px;')
        . '<div style="display:grid;gap:18px;">'
        . '<div style="display:grid;gap:8px;">' . sk_bar('45%', '13px') . sk_bar('100%', '46px', 'border-radius:8px;') . '</div>'
        . '<div style="display:grid;gap:8px;"><div style="display:flex;justify-content:space-between;">' . sk_bar('30%', '13px') . sk_bar('30%', '12px') . '</div>' . sk_bar('100%', '46px', 'border-radius:8px;') . '</div>'
        . '<div style="display:flex;gap:10px;align-items:center;">' . sk_bar('16px', '16px', 'border-radius:4px;flex:none;') . sk_bar('75%', '12px') . '</div>'
        . sk_bar('100%', '48px', 'border-radius:8px;')
        . '</div>'
        . '<div style="display:flex;align-items:center;gap:12px;margin:26px 0 18px;"><span class="sk" style="flex:1;height:1px;border-radius:0;"></span>' . sk_bar('80px', '11px') . '<span class="sk" style="flex:1;height:1px;border-radius:0;"></span></div>'
        . sk_bar('100%', '44px', 'border-radius:8px;')
        . '<div style="display:flex;justify-content:center;gap:12px;margin-top:22px;">' . sk_bar('90px', '12px') . sk_bar('150px', '12px') . '</div>'
        . '</div>';
}

function skv_register() {
    echo '<div class="signin-card signin-card-wide">' . skeleton_brand_row()
        . sk_bar('55%', '26px', 'margin-bottom:8px;') . sk_bar('70%', '13px', 'margin-bottom:22px;')
        . '<div style="display:grid;gap:16px;">'
        . '<div style="display:grid;gap:8px;">' . sk_bar('40%', '13px') . sk_bar('100%', '44px', 'border-radius:8px;') . '</div>'
        . '<div class="form-row-split" style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">'
        . '<div style="display:grid;gap:8px;">' . sk_bar('40%', '13px') . sk_bar('100%', '44px', 'border-radius:8px;') . '</div>'
        . '<div style="display:grid;gap:8px;">' . sk_bar('50%', '13px') . sk_bar('100%', '44px', 'border-radius:8px;') . '</div></div>'
        . '<div class="form-row-split" style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">'
        . '<div style="display:grid;gap:8px;">' . sk_bar('40%', '13px') . sk_bar('100%', '44px', 'border-radius:8px;') . '</div>'
        . '<div style="display:grid;gap:8px;">' . sk_bar('50%', '13px') . sk_bar('100%', '44px', 'border-radius:8px;') . '</div></div>'
        . '<div style="display:grid;gap:8px;">' . sk_bar('30%', '13px') . sk_bar('100%', '44px', 'border-radius:8px;') . '</div>'
        . sk_bar('100%', '48px', 'border-radius:8px;')
        . '</div></div>';
}

function skv_admin_login() {
    echo '<div class="signin-card">' . skeleton_brand_row()
        . sk_bar('70%', '26px', 'margin-bottom:8px;') . sk_bar('85%', '13px', 'margin-bottom:24px;')
        . '<div style="display:grid;gap:18px;">'
        . '<div style="display:grid;gap:8px;">' . sk_bar('45%', '13px') . sk_bar('100%', '46px', 'border-radius:8px;') . '</div>'
        . '<div style="display:grid;gap:8px;">' . sk_bar('30%', '13px') . sk_bar('100%', '46px', 'border-radius:8px;') . '</div>'
        . sk_bar('100%', '48px', 'border-radius:8px;')
        . '</div></div>';
}

/* Brand row used on the sign-in cards (matches .signin-brand). */
function skeleton_brand_row() {
    return '<div class="signin-brand" style="margin-bottom:26px;">' . sk_bar('48px', '48px', 'border-radius:10px;flex:none;')
        . '<div style="display:grid;gap:6px;">' . sk_bar('110px', '13px') . sk_bar('170px', '10px') . '</div></div>';
}

/* ---------- dashboard ---------- */
function skv_dash_table() {
    $rows = '';
    for ($i = 0; $i < 7; $i++) {
        $rows .= '<tr>'
            . '<td>' . sk_bar('70%', '13px') . '</td><td>' . sk_bar('60%', '12px') . '</td>'
            . '<td>' . sk_bar('55%', '12px') . '</td><td>' . sk_bar('80px', '26px', 'border-radius:999px;') . '</td>'
            . '<td>' . sk_bar('100%', '30px', 'border-radius:8px;') . '</td></tr>';
    }
    echo '<div class="app-main-inner sk-app">'
        . '<div class="page-head"><div style="display:grid;gap:10px;">' . sk_bar('260px', '32px') . sk_bar('340px', '13px') . '</div>'
        . sk_bar('150px', '42px', 'border-radius:8px;') . '</div>'
        . '<div class="panel"><div class="panel-head">' . sk_bar('180px', '18px') . sk_bar('220px', '36px', 'border-radius:8px;') . '</div>'
        . '<div class="table-wrap"><table class="data-table"><thead><tr><th scope="col">&nbsp;</th><th scope="col">&nbsp;</th><th scope="col">&nbsp;</th><th scope="col">&nbsp;</th><th scope="col">&nbsp;</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table></div></div></div>';
}

function skv_dash_stats() {
    $stats = '';
    for ($i = 0; $i < 4; $i++) {
        $stats .= '<div class="stat-card">' . sk_bar('45%', '12px', 'margin-bottom:12px;') . sk_bar('35%', '32px', 'margin-bottom:8px;') . sk_bar('65%', '11px') . '</div>';
    }
    echo '<div class="app-main-inner sk-app">'
        . '<div class="page-head"><div style="display:grid;gap:10px;">' . sk_bar('320px', '32px') . sk_bar('260px', '13px') . '</div></div>'
        . '<div class="stat-grid">' . $stats . '</div>'
        . '<div class="panel"><div class="panel-head">' . sk_bar('200px', '18px') . '</div>' . sk_lines(['100%', '92%', '80%'], '13px', '14px') . '</div>'
        . '</div>';
}

function skv_dash_form() {
    echo '<div class="app-main-inner sk-app">'
        . '<div class="page-head"><div style="display:grid;gap:10px;">' . sk_bar('240px', '32px') . sk_bar('380px', '13px') . '</div></div>'
        . '<div class="panel"><div class="panel-head">' . sk_bar('200px', '18px') . '</div>'
        . '<div style="display:grid;gap:18px;">'
        . '<div class="form-row-split" style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">'
        . '<div style="display:grid;gap:8px;">' . sk_bar('40%', '13px') . sk_bar('100%', '44px', 'border-radius:8px;') . '</div>'
        . '<div style="display:grid;gap:8px;">' . sk_bar('40%', '13px') . sk_bar('100%', '44px', 'border-radius:8px;') . '</div></div>'
        . '<div style="display:grid;gap:8px;">' . sk_bar('30%', '13px') . sk_bar('100%', '44px', 'border-radius:8px;') . '</div>'
        . '<div style="display:grid;gap:8px;">' . sk_bar('35%', '13px') . sk_bar('100%', '90px', 'border-radius:8px;') . '</div>'
        . sk_bar('150px', '44px', 'border-radius:8px;')
        . '</div></div></div>';
}

function skv_dash_map() {
    $buildings = '';
    for ($i = 0; $i < 4; $i++) {
        $buildings .= '<div class="panel map-admin" style="display:flex;justify-content:space-between;align-items:center;">'
            . sk_bar('45%', '16px') . sk_bar('110px', '24px', 'border-radius:999px;') . '</div>';
    }
    echo '<div class="app-main-inner sk-app">'
        . '<div class="page-head"><div style="display:grid;gap:10px;">' . sk_bar('240px', '32px') . sk_bar('420px', '13px') . '</div>'
        . sk_bar('150px', '36px', 'border-radius:8px;') . '</div>'
        . '<div class="panel"><div class="panel-head">' . sk_bar('220px', '18px') . '</div>'
        . '<div class="form-row-split" style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">' . sk_bar('100%', '44px', 'border-radius:8px;') . sk_bar('100%', '44px', 'border-radius:8px;') . '</div>'
        . sk_bar('100%', '44px', 'border-radius:8px;margin-top:14px;') . '</div>'
        . $buildings . '</div>';
}

/* ---------- entry point ---------- */
function skeleton_html() {
    $v = skeleton_variant();
    $auth_pages = ['login', 'register', 'simple-auth', 'admin-login'];
    $is_auth = in_array($v, $auth_pages, true);
    $is_app  = strpos($v, 'dash') === 0;
    ?>
    <div id="page-loader" class="page-loader<?php echo $is_auth ? ' sk-auth' : ''; ?><?php echo $is_app ? ' sk-is-app' : ''; ?>" role="status" aria-live="polite" aria-label="Loading">
        <?php if (!$is_auth): ?><?php echo sk_topbar(); ?><?php endif; ?>
        <div class="sk-body">
            <?php if ($is_auth): ?>
                <div class="signin-page">
                    <?php
                    switch ($v) {
                        case 'login':      skv_login(); break;
                        case 'register':   skv_register(); break;
                        case 'admin-login': skv_admin_login(); break;
                        default:           skv_simple_auth();
                    }
                    ?>
                </div>
            <?php else: ?>
                <?php
                switch ($v) {
                    case 'home':       skv_home(); break;
                    case 'events':     skv_events(); break;
                    case 'detail':     skv_detail(); break;
                    case 'feed':       skv_feed(); break;
                    case 'map':        skv_map(); break;
                    case 'directory':  skv_directory(); break;
                    case 'officers':   skv_officers(); break;
                    case 'members':    skv_members(); break;
                    case 'curriculum': skv_curriculum(); break;
                    case 'dash-stats': skv_dash_stats(); break;
                    case 'dash-form':  skv_dash_form(); break;
                    case 'dash-map':   skv_dash_map(); break;
                    case 'dash-table': skv_dash_table(); break;
                    default:           skv_doc();
                }
                ?>
            <?php endif; ?>
        </div>
        <span class="sr-only">Loading…</span>
    </div>
    <?php
}
