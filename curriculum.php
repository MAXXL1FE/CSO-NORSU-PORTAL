<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

/* Public curriculum page. Subjects come from includes/curriculum_data.php. */
$years = require __DIR__ . '/includes/curriculum_data.php';

$page_title = 'Curriculum';
require_once __DIR__ . '/includes/header.php';
?>

<?php
$n_years = count($years); $n_subjects = 0; $n_units = 0;
foreach ($years as $sems) { foreach ($sems as $list) { $n_subjects += count($list); $n_units += array_sum(array_column($list, 'units')); } }
?>
<div class="home-page people-page">

<section class="curr-hero" aria-label="Curriculum">
    <svg class="curr-hero-art" viewBox="0 0 1200 420" preserveAspectRatio="xMidYMid slice" fill="none" aria-hidden="true" focusable="false">
        <defs>
            <pattern id="currDots" width="28" height="28" patternUnits="userSpaceOnUse"><circle cx="1" cy="1" r="1" fill="currentColor" opacity=".45"/></pattern>
        </defs>
        <rect width="1200" height="420" fill="url(#currDots)"/>
        <g stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round">
            <path d="M0 90h210l46 46h190"/><path d="M0 250h150l40-40h250"/><path d="M1200 70H980l-50 50H760"/>
            <path d="M1200 250h-170l-44 44H800"/><path d="M560 0v54l30 30h120"/><path d="M820 420v-60l-30-30"/>
        </g>
        <g fill="currentColor"><circle cx="446" cy="136" r="5"/><circle cx="440" cy="210" r="5"/><circle cx="760" cy="120" r="5"/><circle cx="800" cy="330" r="5"/><circle cx="710" cy="84" r="5"/></g>
        <g stroke="currentColor" stroke-width="1.3" opacity=".7"><rect x="930" y="160" width="46" height="46"/><rect x="1004" y="160" width="46" height="46"/><rect x="1078" y="160" width="46" height="46"/></g>
    </svg>
    <div class="container">
        <div class="curr-hero-inner">
            <div class="curr-hero-text">
                <span class="curr-hero-eyebrow">Computer Science Organization &middot; NORSU</span>
                <h1 class="curr-hero-title">Curriculum</h1>
                <p class="curr-hero-sub">The Bachelor of Science in Computer Science (BSCS) program, with the subjects for each year level. Check this page before you enroll for the term.</p>
                <ul class="curr-hero-chips" aria-label="Program summary">
                    <li>BS Computer Science</li>
                    <li><?php echo (int) $n_years; ?> year levels</li>
                    <li><?php echo (int) $n_units; ?> units</li>
                </ul>
                <div class="curr-hero-actions">
                    <a href="#curriculum" class="btn btn-primary">View subjects</a>
                    <button type="button" class="btn btn-outline no-print" onclick="window.print()">Print</button>
                </div>
            </div>
            <div class="curr-hero-seal" aria-hidden="true">
                <img src="<?php echo asset_v('/assets/images/logo.png'); ?>" alt="" onerror="this.parentElement.style.display='none'">
            </div>
        </div>
    </div>
</section>

<section class="curr-stats-wrap" aria-label="Curriculum at a glance">
    <div class="container">
        <div class="curr-stats">
            <div class="curr-stat"><strong><?php echo (int) $n_years; ?></strong><span>Year levels</span></div>
            <div class="curr-stat"><strong><?php echo (int) $n_subjects; ?></strong><span>Subjects</span></div>
            <div class="curr-stat"><strong><?php echo (int) $n_units; ?></strong><span>Total units</span></div>
        </div>
    </div>
</section>

<section id="curriculum" class="section curr-page">
    <div class="container">
<div class="section-head"><div><span class="eyebrow">Program</span><h2>BSCS Curriculum</h2><p>Subjects are listed by year level. Units are shown beside each subject.</p></div></div>

<div class="curr-box">
    <div class="curr-box-head">
        <h3>Bachelor of Science in Computer Science</h3>
        
    </div>
    <div class="curr-years">
        <?php $yi = 0; foreach ($years as $year => $semesters): $yi++; ?>
            <?php
                $year_units = 0; $year_subjects = 0;
                foreach ($semesters as $sem_list) { $year_units += array_sum(array_column($sem_list, 'units')); $year_subjects += count($sem_list); }
            ?>
            <details class="curr-year" id="<?php echo e('year-' . preg_replace('/\D/', '', $year)); ?>"<?php echo $yi === 1 ? ' open' : ''; ?>>
                <summary class="curr-year-sum">
                    <span class="curr-year-no" aria-hidden="true"><?php echo e(preg_replace('/\D/', '', $year) ?: $yi); ?></span>
                    <span class="curr-year-name"><?php echo e($year); ?></span>
                    <span class="curr-year-meta"><?php echo (int) $year_subjects; ?> subjects &middot; <?php echo (int) $year_units; ?> units</span>
                    <svg class="icon curr-chev" aria-hidden="true"><use href="#i-chevron"/></svg>
                </summary>
                <div class="curr-year-body">
                <?php if (!$semesters): ?>
                    <p class="muted">Subjects to be added.</p>
                <?php endif; ?>
                <?php foreach ($semesters as $sem => $subjects): ?>
                    <?php $units = array_sum(array_column($subjects, 'units')); ?>
                    <div class="curr-sem">
                        <div class="curr-sem-head">
                            <span class="curr-sem-name"><?php echo e($sem); ?></span>
                            <span class="curr-sem-units"><?php echo (int) $units; ?> units</span>
                        </div>
                        <div class="table-wrap">
                            <table class="data-table curr-table">
                                <caption class="sr-only"><?php echo e($year . ', ' . $sem); ?></caption>
                                <thead>
                                    <tr>
                                        <th scope="col">Subject</th>
                                        <th scope="col">Prerequisite</th>
                                        <th scope="col" class="curr-num">Units</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($subjects as $s): ?>
                                        <tr>
                                            <td><strong><?php echo e($s['code']); ?></strong> &ndash; <?php echo e($s['title']); ?></td>
                                            <td class="muted"><?php echo !empty($s['prereq']) ? e($s['prereq']) : '&mdash;'; ?></td>
                                            <td class="curr-num"><?php echo (int) $s['units']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th scope="row" colspan="2">Total units</th>
                                        <td class="curr-num"><?php echo (int) $units; ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
</div>
    </div>
</section>

</div><!-- /.home-page -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>
