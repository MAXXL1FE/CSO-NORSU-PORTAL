<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/map_lib.php';
require_role(['admin', 'officer', 'moderator']);

$uid = (int) $_SESSION['user_id'];

/* ---------- cleaning ---------- */

function map_clean_time($v) {
    $v = trim((string) $v);
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v . ':00' : null;
}
function map_clean_date($v) {
    $v = trim((string) $v);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return null;
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
}
function map_clean_text($v, $max) {
    $v = trim(strip_tags((string) $v));
    return $v === '' ? null : substr($v, 0, $max);
}
/** Common fields for a building or room. Returns [] when the input is not valid. */
function map_clean_common($p) {
    $name   = map_clean_text($p['name'] ?? '', 150);
    $status = $p['status'] ?? '';
    if ($name === null || !isset(MAP_STATUS_LABELS[$status])) return [];
    $open  = map_clean_time($p['open_time'] ?? '');
    $close = map_clean_time($p['close_time'] ?? '');
    $from  = map_clean_date($p['date_opened'] ?? '');
    $until = map_clean_date($p['date_closed'] ?? '');
    if (($open === null) !== ($close === null)) return [];
    if ($from && $until && $until < $from) return [];
    return [
        'name' => $name, 'status' => $status,
        'status_note' => map_clean_text($p['status_note'] ?? '', 160),
        'open_time' => $open, 'close_time' => $close,
        'date_opened' => $from, 'date_closed' => $until,
    ];
}
function map_clean_status_note($p) {
    $status = $p['status'] ?? '';
    if (!isset(MAP_STATUS_LABELS[$status])) return null;
    return [$status, map_clean_text($p['status_note'] ?? '', 160)];
}


/** Splits pasted text on new lines, commas and semicolons into trimmed, non-empty pieces. */
function map_split_list($text) {
    $parts = preg_split('/[\r\n,;]+/', (string) $text);
    return array_values(array_filter(array_map('trim', $parts), fn($x) => $x !== ''));
}
/** 1st, 2nd, 3rd, 4th ... */
function map_ordinal($n) {
    $n = (int) $n;
    if (in_array($n % 100, [11, 12, 13], true)) return $n . 'th';
    switch ($n % 10) { case 1: return $n . 'st'; case 2: return $n . 'nd'; case 3: return $n . 'rd'; }
    return $n . 'th';
}
/**
 * Room names from pasted text. One per line or separated by commas.
 * A range such as "201-210" or "Room 1-5" becomes one room per number.
 * An optional type can follow a pipe, for example "Lab 1-3 | lab".
 * Returns a list of [name, room_type|null].
 */
function map_expand_rooms($text, $max = 100) {
    $out = [];
    foreach (preg_split('/[\r\n;]+/', (string) $text) as $line) {
        $type = null;
        if (strpos($line, '|') !== false) {
            [$line, $t] = array_map('trim', explode('|', $line, 2));
            $type = isset(MAP_ROOM_TYPES[strtolower($t)]) ? strtolower($t) : null;
        }
        foreach (explode(',', $line) as $tok) {
            $tok = trim($tok);
            if ($tok === '') continue;
            if (preg_match('/^(.*?)(\d+)\s*-\s*(\d+)$/u', $tok, $m) && (int) $m[3] >= (int) $m[2] && (int) $m[3] - (int) $m[2] < 100) {
                $width = strlen($m[2]) > 1 && $m[2][0] === '0' ? strlen($m[2]) : 0;   // keeps 01-09 as 01, 02 ...
                for ($i = (int) $m[2]; $i <= (int) $m[3]; $i++) {
                    $out[] = [trim($m[1] . ($width ? str_pad((string) $i, $width, '0', STR_PAD_LEFT) : $i)), $type];
                }
            } else {
                $out[] = [$tok, $type];
            }
            if (count($out) >= $max) return array_slice($out, 0, $max);
        }
    }
    return $out;
}
/** Floor labels from pasted text: names ("Ground, Mezzanine") and number ranges ("2-5" becomes 2nd to 5th). */
function map_expand_floors($text, $max = 30) {
    $out = [];
    foreach (map_split_list($text) as $tok) {
        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $tok, $m) && (int) $m[2] >= (int) $m[1] && (int) $m[2] - (int) $m[1] < 30) {
            for ($i = (int) $m[1]; $i <= (int) $m[2]; $i++) $out[] = map_ordinal($i);
        } elseif (preg_match('/^\d+$/', $tok)) {
            $out[] = map_ordinal($tok);
        } else {
            $out[] = substr(strip_tags($tok), 0, 40);
        }
        if (count($out) >= $max) return array_slice($out, 0, $max);
    }
    return $out;
}

/* ---------- save actions ---------- */

$back_b = 0;        // building to show after saving
$back_anchor = '';  // element to scroll to

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // Quick status change for a building: status and note only.
    if ($action === 'quick_building') {
        $id = (int) ($_POST['building_id'] ?? 0);
        $q  = map_clean_status_note($_POST);
        if ($q && $id) {
            $stmt = $conn->prepare("UPDATE map_buildings SET status = ?, status_note = ?, updated_by = ? WHERE building_id = ?");
            $stmt->bind_param('ssii', $q[0], $q[1], $uid, $id);
            $stmt->execute();
            set_flash('success', 'Status saved. Students see it now.');
        } else {
            set_flash('error', 'Choose a status.');
        }
        $back_b = $id; $back_anchor = 'building';
    }

    // Quick status change for a room: status and note only.
    if ($action === 'quick_room') {
        $rid = (int) ($_POST['room_id'] ?? 0);
        $q   = map_clean_status_note($_POST);
        $r = $conn->query("SELECT f.building_id FROM map_rooms r JOIN map_floors f ON f.floor_id = r.floor_id WHERE r.room_id = " . $rid)->fetch_assoc();
        if ($q && $rid) {
            $stmt = $conn->prepare("UPDATE map_rooms SET status = ?, status_note = ?, updated_by = ? WHERE room_id = ?");
            $stmt->bind_param('ssii', $q[0], $q[1], $uid, $rid);
            $stmt->execute();
            set_flash('success', 'Room saved. Students see it now.');
        } else {
            set_flash('error', 'Choose a status.');
        }
        $back_b = (int) ($r['building_id'] ?? 0); $back_anchor = 'room-' . $rid;
    }

    // Full details for a building (name, code, category, pin, hours, dates, status).
    if ($action === 'create_building' || $action === 'update_building') {
        $v = map_clean_common($_POST);
        $code = map_clean_text($_POST['code'] ?? '', 20);
        $cat  = $_POST['category'] ?? 'building';
        if (!isset(MAP_BUILDING_CATEGORIES[$cat])) $cat = 'building';
        $fm = $_POST['floor_mode'] ?? 'floors';
        if (!isset(MAP_FLOOR_MODES[$fm]) || $cat === 'gate') $fm = $cat === 'gate' ? 'none' : 'floors';
        $x = max(0, min(100, (float) ($_POST['pos_x'] ?? 50)));
        $y = max(0, min(100, (float) ($_POST['pos_y'] ?? 50)));
        if (!$v) {
            set_flash('error', 'Check the name and status. Set both opening and closing time, or neither.');
        } elseif ($action === 'create_building') {
            $stmt = $conn->prepare("INSERT INTO map_buildings (name, code, category, floor_mode, pos_x, pos_y, status, status_note, open_time, close_time, date_opened, date_closed, updated_by)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $types = 'ssss' . 'dd' . str_repeat('s', 6) . 'i';
            $stmt->bind_param($types, $v['name'], $code, $cat, $fm, $x, $y, $v['status'], $v['status_note'],
                              $v['open_time'], $v['close_time'], $v['date_opened'], $v['date_closed'], $uid);
            $stmt->execute();
            $back_b = (int) $conn->insert_id;
            map_apply_floor_mode($conn, $back_b);
            set_flash('success', $fm === 'floors' ? 'Building added. Now add its floors and rooms.' : 'Place added.');
        } else {
            $id = (int) ($_POST['building_id'] ?? 0);
            $before = $conn->query("SELECT floor_mode FROM map_buildings WHERE building_id = " . $id)->fetch_assoc();
            $stmt = $conn->prepare("UPDATE map_buildings SET name = ?, code = ?, category = ?, floor_mode = ?, pos_x = ?, pos_y = ?, status = ?, status_note = ?,
                                    open_time = ?, close_time = ?, date_opened = ?, date_closed = ?, updated_by = ? WHERE building_id = ?");
            $types = 'ssss' . 'dd' . str_repeat('s', 6) . 'ii';
            $stmt->bind_param($types, $v['name'], $code, $cat, $fm, $x, $y, $v['status'], $v['status_note'],
                              $v['open_time'], $v['close_time'], $v['date_opened'], $v['date_closed'], $uid, $id);
            $stmt->execute();
            if (($before['floor_mode'] ?? 'floors') !== $fm) map_apply_floor_mode($conn, $id);
            $back_b = $id;
            set_flash('success', 'Building saved.');
        }
        $back_anchor = 'building';
    }

    if ($action === 'delete_building') {
        $id = (int) ($_POST['building_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM map_buildings WHERE building_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        set_flash('success', 'Building removed, with its floors and rooms.');
        $back_b = 0;
    }

    // Floors
    if ($action === 'create_floor' || $action === 'update_floor') {
        $label = map_clean_text($_POST['label'] ?? '', 40);
        $sort  = (int) ($_POST['sort_order'] ?? 0);
        if ($action === 'create_floor') {
            $bid = (int) ($_POST['building_id'] ?? 0);
            $gate = $conn->query("SELECT category, floor_mode FROM map_buildings WHERE building_id = " . $bid)->fetch_assoc();
            if (!$gate || map_floor_mode($gate) !== 'floors') {
                set_flash('error', 'This place does not use floors.');
            } elseif ($label === null) {
                set_flash('error', 'Enter a floor name, such as Ground, 2nd or Mezzanine.');
            } else {
                $stmt = $conn->prepare("INSERT INTO map_floors (building_id, label, sort_order) VALUES (?, ?, ?)");
                $stmt->bind_param('isi', $bid, $label, $sort);
                $stmt->execute();
                set_flash('success', 'Floor added.');
            }
            $back_b = $bid; $back_anchor = 'floors';
        } else {
            $fid = (int) ($_POST['floor_id'] ?? 0);
            if ($label === null) {
                set_flash('error', 'Enter a floor name.');
            } else {
                $stmt = $conn->prepare("UPDATE map_floors SET label = ?, sort_order = ? WHERE floor_id = ?");
                $stmt->bind_param('sii', $label, $sort, $fid);
                $stmt->execute();
                set_flash('success', 'Floor saved.');
            }
            $r = $conn->query("SELECT building_id FROM map_floors WHERE floor_id = " . $fid)->fetch_assoc();
            $back_b = (int) ($r['building_id'] ?? 0); $back_anchor = 'floors';
        }
    }

    if ($action === 'delete_floor') {
        $fid = (int) ($_POST['floor_id'] ?? 0);
        $r = $conn->query("SELECT f.building_id, b.category, b.floor_mode FROM map_floors f JOIN map_buildings b ON b.building_id = f.building_id WHERE f.floor_id = " . $fid)->fetch_assoc();
        $back_b = (int) ($r['building_id'] ?? 0); $back_anchor = 'floors';
        if ($r && map_floor_mode($r) !== 'floors') {
            set_flash('error', 'This place does not use floors.');
        } else {
            $stmt = $conn->prepare("DELETE FROM map_floors WHERE floor_id = ?");
            $stmt->bind_param('i', $fid);
            $stmt->execute();
            set_flash('success', 'Floor removed, with its rooms.');
        }
    }

    // Rooms, full details (hours and dates)
    if ($action === 'create_room' || $action === 'update_room') {
        $v = map_clean_common($_POST);
        $type = $_POST['room_type'] ?? 'room';
        if (!isset(MAP_ROOM_TYPES[$type])) $type = 'room';
        if ($action === 'create_room') {
            $fid = (int) ($_POST['floor_id'] ?? 0);
            if (!$v) {
                set_flash('error', 'Enter a room name and status. Set both opening and closing time, or neither.');
            } else {
                $stmt = $conn->prepare("INSERT INTO map_rooms (floor_id, name, room_type, status, status_note, open_time, close_time, date_opened, date_closed, updated_by)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $types = 'i' . str_repeat('s', 8) . 'i';
                $stmt->bind_param($types, $fid, $v['name'], $type, $v['status'], $v['status_note'],
                                  $v['open_time'], $v['close_time'], $v['date_opened'], $v['date_closed'], $uid);
                $stmt->execute();
                set_flash('success', 'Room added.');
            }
            $r = $conn->query("SELECT building_id FROM map_floors WHERE floor_id = " . $fid)->fetch_assoc();
            $back_b = (int) ($r['building_id'] ?? 0); $back_anchor = 'floor-' . $fid;
        } else {
            $rid = (int) ($_POST['room_id'] ?? 0);
            if (!$v) {
                set_flash('error', 'Enter a room name and status. Set both opening and closing time, or neither.');
            } else {
                $stmt = $conn->prepare("UPDATE map_rooms SET name = ?, room_type = ?, status = ?, status_note = ?, open_time = ?, close_time = ?,
                                        date_opened = ?, date_closed = ?, updated_by = ? WHERE room_id = ?");
                $types = str_repeat('s', 8) . 'ii';
                $stmt->bind_param($types, $v['name'], $type, $v['status'], $v['status_note'], $v['open_time'], $v['close_time'],
                                  $v['date_opened'], $v['date_closed'], $uid, $rid);
                $stmt->execute();
                set_flash('success', 'Room saved.');
            }
            $r = $conn->query("SELECT f.building_id FROM map_rooms r JOIN map_floors f ON f.floor_id = r.floor_id WHERE r.room_id = " . $rid)->fetch_assoc();
            $back_b = (int) ($r['building_id'] ?? 0); $back_anchor = 'room-' . $rid;
        }
    }

    if ($action === 'delete_room') {
        $rid = (int) ($_POST['room_id'] ?? 0);
        $r = $conn->query("SELECT f.building_id FROM map_rooms r JOIN map_floors f ON f.floor_id = r.floor_id WHERE r.room_id = " . $rid)->fetch_assoc();
        $back_b = (int) ($r['building_id'] ?? 0); $back_anchor = 'floors';
        $stmt = $conn->prepare("DELETE FROM map_rooms WHERE room_id = ?");
        $stmt->bind_param('i', $rid);
        $stmt->execute();
        set_flash('success', 'Room removed.');
    }

    // Add many floors at once, for example "Ground, 2-5, Rooftop".
    if ($action === 'bulk_floors') {
        $bid = (int) ($_POST['building_id'] ?? 0);
        $gate = $conn->query("SELECT category, floor_mode FROM map_buildings WHERE building_id = " . $bid)->fetch_assoc();
        $labels = map_expand_floors($_POST['floors'] ?? '');
        if (!$gate || map_floor_mode($gate) !== 'floors') {
            set_flash('error', 'This place does not use floors.');
        } elseif (!$labels) {
            set_flash('error', 'Type the floors to add, such as Ground, 2-5, Rooftop.');
        } else {
            $have = [];
            $res = $conn->query("SELECT label, sort_order FROM map_floors WHERE building_id = " . $bid);
            $sort = 0;
            while ($row = $res->fetch_assoc()) { $have[strtolower($row['label'])] = true; $sort = max($sort, (int) $row['sort_order']); }
            $stmt = $conn->prepare("INSERT INTO map_floors (building_id, label, sort_order) VALUES (?, ?, ?)");
            $added = 0; $skipped = 0;
            foreach ($labels as $label) {
                if (isset($have[strtolower($label)])) { $skipped++; continue; }
                $sort += 10;
                $stmt->bind_param('isi', $bid, $label, $sort);
                $stmt->execute();
                $have[strtolower($label)] = true;
                $added++;
            }
            set_flash($added ? 'success' : 'error', $added
                ? $added . ' floor' . ($added === 1 ? '' : 's') . ' added.' . ($skipped ? " $skipped already existed and were skipped." : '')
                : 'Those floors already exist.');
        }
        $back_b = $bid; $back_anchor = 'floors';
    }

    // Add many rooms to one floor, for example "201-210" or "Lab 1-3 | lab".
    if ($action === 'bulk_rooms') {
        $fid    = (int) ($_POST['floor_id'] ?? 0);
        $status = $_POST['status'] ?? 'open';
        $deftype = $_POST['room_type'] ?? 'room';
        if (!isset(MAP_ROOM_TYPES[$deftype])) $deftype = 'room';
        $note   = map_clean_text($_POST['status_note'] ?? '', 160);
        $rooms  = map_expand_rooms($_POST['rooms'] ?? '');
        $floor  = $conn->query("SELECT building_id FROM map_floors WHERE floor_id = " . $fid)->fetch_assoc();
        if (!$floor) {
            set_flash('error', 'Floor not found.');
        } elseif (!isset(MAP_STATUS_LABELS[$status]) || !$rooms) {
            set_flash('error', 'Type the rooms to add, such as 201-210, and choose a status.');
        } else {
            $have = [];
            $res = $conn->query("SELECT name FROM map_rooms WHERE floor_id = " . $fid);
            while ($row = $res->fetch_assoc()) $have[strtolower($row['name'])] = true;
            $stmt = $conn->prepare("INSERT INTO map_rooms (floor_id, name, room_type, status, status_note, updated_by) VALUES (?, ?, ?, ?, ?, ?)");
            $added = 0; $skipped = 0;
            foreach ($rooms as [$name, $t]) {
                $name = map_clean_text($name, 150);
                if ($name === null || isset($have[strtolower($name)])) { $skipped++; continue; }
                $type = $t ?? $deftype;
                $stmt->bind_param('issssi', $fid, $name, $type, $status, $note, $uid);
                $stmt->execute();
                $have[strtolower($name)] = true;
                $added++;
            }
            set_flash($added ? 'success' : 'error', $added
                ? $added . ' room' . ($added === 1 ? '' : 's') . ' added.' . ($skipped ? " $skipped already existed and were skipped." : '')
                : 'Those rooms already exist on this floor.');
        }
        $back_b = (int) ($floor['building_id'] ?? 0); $back_anchor = 'floor-' . $fid;
    }

    // Change or remove several rooms on one floor in one go.
    if ($action === 'bulk_room_update') {
        $fid = (int) ($_POST['floor_id'] ?? 0);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['room_ids'] ?? [])))));
        $floor = $conn->query("SELECT building_id FROM map_floors WHERE floor_id = " . $fid)->fetch_assoc();
        $op = $_POST['bulk_op'] ?? 'status';
        if (!$floor || !$ids) {
            set_flash('error', 'Tick the rooms you want to change first.');
        } else {
            $in = implode(',', $ids);   // integers only
            if ($op === 'delete') {
                $conn->query("DELETE FROM map_rooms WHERE floor_id = $fid AND room_id IN ($in)");
                set_flash('success', $conn->affected_rows . ' room' . ($conn->affected_rows === 1 ? '' : 's') . ' removed.');
            } else {
                $q = map_clean_status_note($_POST);
                if (!$q) {
                    set_flash('error', 'Choose a status.');
                } else {
                    $stmt = $conn->prepare("UPDATE map_rooms SET status = ?, status_note = ?, updated_by = ? WHERE floor_id = ? AND room_id IN ($in)");
                    $stmt->bind_param('ssii', $q[0], $q[1], $uid, $fid);
                    $stmt->execute();
                    set_flash('success', $stmt->affected_rows . ' room' . ($stmt->affected_rows === 1 ? '' : 's') . ' updated. Students see it now.');
                }
            }
        }
        $back_b = (int) ($floor['building_id'] ?? 0); $back_anchor = 'floor-' . $fid;
    }

    $target = BASE_URL . '/dashboard/map.php' . ($back_b ? '?b=' . $back_b : '') . ($back_anchor ? '#' . $back_anchor : '');
    header('Location: ' . $target);
    exit;
}

/* ---------- load ---------- */

$buildings = [];
$res = $conn->query("SELECT * FROM map_buildings ORDER BY FIELD(category,'building','facility','office','restroom','gate'), name");
while ($row = $res->fetch_assoc()) $buildings[(int) $row['building_id']] = $row;

$sel_id = (int) ($_GET['b'] ?? 0);
if (!isset($buildings[$sel_id])) $sel_id = $buildings ? array_key_first($buildings) : 0;
$sel = $sel_id ? $buildings[$sel_id] : null;

$floors = [];
$rooms_by_floor = [];
$sel_mode = $sel ? map_floor_mode($sel) : 'floors';
if ($sel && $sel_mode !== 'floors') map_apply_floor_mode($conn, $sel_id);
if ($sel) {
    $stmt = $conn->prepare("SELECT * FROM map_floors WHERE building_id = ? ORDER BY sort_order, label");
    $stmt->bind_param('i', $sel_id);
    $stmt->execute();
    $floors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if ($floors) {
        $ids = implode(',', array_map(fn($f) => (int) $f['floor_id'], $floors));
        $rr = $conn->query("SELECT * FROM map_rooms WHERE floor_id IN ($ids) ORDER BY name");
        while ($r = $rr->fetch_assoc()) $rooms_by_floor[(int) $r['floor_id']][] = $r;
    }
}

$edit_b = (int) ($_GET['edit_b'] ?? 0);
$edit_r = (int) ($_GET['edit_r'] ?? 0);

/* ---------- form pieces ---------- */

/** Hours and availability dates. Used only in the "more options" areas. */
function map_status_fields($v, $p) {
    $st = $v['status'] ?? 'open';
    $o = !empty($v['open_time'])  ? substr($v['open_time'], 0, 5)  : '';
    $c = !empty($v['close_time']) ? substr($v['close_time'], 0, 5) : '';
    ob_start(); ?>
    <div class="form-row form-row-split">
        <div><label for="<?php echo $p; ?>status">Status</label>
            <select id="<?php echo $p; ?>status" name="status" required>
                <?php foreach (MAP_STATUS_LABELS as $k => $label): ?><option value="<?php echo $k; ?>" <?php echo $st === $k ? 'selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?>
            </select></div>
        <div><label for="<?php echo $p; ?>note">Note for students</label>
            <input type="text" id="<?php echo $p; ?>note" name="status_note" maxlength="160" value="<?php echo e($v['status_note'] ?? ''); ?>" placeholder="e.g. Aircon under repair"></div>
    </div>
    <div class="form-row form-row-split">
        <div><label for="<?php echo $p; ?>open">Opens at</label><input type="time" id="<?php echo $p; ?>open" name="open_time" value="<?php echo e($o); ?>"></div>
        <div><label for="<?php echo $p; ?>close">Closes at</label><input type="time" id="<?php echo $p; ?>close" name="close_time" value="<?php echo e($c); ?>"></div>
    </div>
    <div class="form-row form-row-split">
        <div><label for="<?php echo $p; ?>from">Available from</label><input type="date" id="<?php echo $p; ?>from" name="date_opened" value="<?php echo e($v['date_opened'] ?? ''); ?>"></div>
        <div><label for="<?php echo $p; ?>until">Available until</label><input type="date" id="<?php echo $p; ?>until" name="date_closed" value="<?php echo e($v['date_closed'] ?? ''); ?>"></div>
    </div>
    <p class="muted mq-hint">Leave hours and dates empty if the place is always available.</p>
    <?php return ob_get_clean();
}

function map_room_type_select($current, $p) {
    ob_start(); ?>
    <label for="<?php echo $p; ?>type">Type</label>
    <select id="<?php echo $p; ?>type" name="room_type">
        <?php foreach (MAP_ROOM_TYPES as $k => $label): ?><option value="<?php echo $k; ?>" <?php echo $current === $k ? 'selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?>
    </select>
    <?php return ob_get_clean();
}

function map_status_select($name, $current, $id, $label = 'Status') {
    ob_start(); ?>
    <label class="sr-only" for="<?php echo $id; ?>"><?php echo e($label); ?></label>
    <select id="<?php echo $id; ?>" name="<?php echo $name; ?>" required>
        <?php foreach (MAP_STATUS_LABELS as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $current === $k ? 'selected' : ''; ?>><?php echo e($l); ?></option><?php endforeach; ?>
    </select>
    <?php return ob_get_clean();
}

$page_title = 'Campus Map';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Campus Map</h1>
        <p class="muted">Choose a building, then update its status or its rooms. Students see changes right away.</p>
    </div>
    <a href="<?php echo BASE_URL; ?>/map.php" class="btn btn-outline btn-sm">View public map</a>
</div>

<!-- Step 1: choose a building -->
<section class="panel mq-step">
    <div class="mq-step-head"><span class="mq-num">1</span><h2>Choose a building</h2></div>
    <form method="GET" class="mq-pick">
        <label for="mq-pick" class="sr-only" for="mq-pick">Building</label>
        <select id="mq-pick" name="b" onchange="this.form.submit()">
            <?php if (!$buildings): ?><option value="">No buildings yet</option><?php endif; ?>
            <?php
            $groups = ['building' => 'Buildings', 'facility' => 'Facilities', 'office' => 'Offices', 'restroom' => 'Restrooms', 'gate' => 'Gates'];
            foreach ($groups as $cat => $glabel):
                $in = array_filter($buildings, fn($b) => $b['category'] === $cat);
                if (!$in) continue; ?>
                <optgroup label="<?php echo e($glabel); ?>">
                    <?php foreach ($in as $b): ?>
                        <option value="<?php echo (int) $b['building_id']; ?>" <?php echo $sel_id === (int) $b['building_id'] ? 'selected' : ''; ?>><?php echo e($b['name']); ?></option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn-outline">Show</button></noscript>
    </form>

    <details class="mq-add">
        <summary>+ Add a new building</summary>
        <form method="POST" class="mq-add-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create_building">
            <div class="form-row form-row-split">
                <div><label for="nb-name">Name</label><input type="text" id="nb-name" name="name" required placeholder="e.g. CIT Building"></div>
                <div><label for="nb-cat">Type</label>
                    <select id="nb-cat" name="category">
                        <?php foreach (MAP_BUILDING_CATEGORIES as $k => $l): ?><option value="<?php echo $k; ?>"><?php echo e($l); ?></option><?php endforeach; ?>
                    </select></div>
            </div>
            <div class="form-row">
                <label for="nb-fm">Floors and rooms</label>
                <select id="nb-fm" name="floor_mode">
                    <?php foreach (MAP_FLOOR_MODES as $k => $l): ?><option value="<?php echo $k; ?>"><?php echo e($l); ?></option><?php endforeach; ?>
                </select>
                <p class="muted mq-hint">Choose &ldquo;No floors or rooms&rdquo; for places that are not buildings, such as a lounge, canteen or court.</p>
            </div>
            <?php echo map_status_fields([], 'nb-'); ?>
            <details class="mq-more">
                <summary>More options (short code and map pin)</summary>
                <div class="form-row form-row-split">
                    <div><label for="nb-code">Short code</label><input type="text" id="nb-code" name="code" maxlength="20" placeholder="e.g. CIT"></div>
                    <div class="form-row-split" style="margin:0;">
                        <div><label for="nb-x">Pin left %</label><input type="number" id="nb-x" name="pos_x" min="0" max="100" step="0.01" value="50"></div>
                        <div><label for="nb-y">Pin top %</label><input type="number" id="nb-y" name="pos_y" min="0" max="100" step="0.01" value="50"></div>
                    </div>
                </div>
            </details>
            <button type="submit" class="btn btn-primary">Add building</button>
        </form>
    </details>
</section>

<?php if ($sel): ?>

<!-- Step 2: the building -->
<section class="panel mq-step" id="building">
    <div class="mq-step-head">
        <span class="mq-num">2</span>
        <h2><?php echo e($sel['name']); ?></h2>
        <?php $bav = map_availability($sel); ?>
        <span class="status-badge status-<?php echo e($bav['key']); ?>"><?php echo e($bav['label']); ?></span>
    </div>

    <?php if ($edit_b === $sel_id): ?>
        <form method="POST" class="mq-edit">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_building">
            <input type="hidden" name="building_id" value="<?php echo $sel_id; ?>">
            <div class="form-row form-row-split">
                <div><label for="eb-name">Name</label><input type="text" id="eb-name" name="name" required value="<?php echo e($sel['name']); ?>"></div>
                <div><label for="eb-code">Short code</label><input type="text" id="eb-code" name="code" maxlength="20" value="<?php echo e($sel['code']); ?>"></div>
            </div>
            <div class="form-row form-row-split">
                <div><label for="eb-cat">Type</label>
                    <select id="eb-cat" name="category">
                        <?php foreach (MAP_BUILDING_CATEGORIES as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $sel['category'] === $k ? 'selected' : ''; ?>><?php echo e($l); ?></option><?php endforeach; ?>
                    </select></div>
                <div class="form-row-split" style="margin:0;">
                    <div><label for="eb-x">Pin left %</label><input type="number" id="eb-x" name="pos_x" min="0" max="100" step="0.01" value="<?php echo (float) $sel['pos_x']; ?>"></div>
                    <div><label for="eb-y">Pin top %</label><input type="number" id="eb-y" name="pos_y" min="0" max="100" step="0.01" value="<?php echo (float) $sel['pos_y']; ?>"></div>
                </div>
            </div>
            <div class="form-row">
                <label for="eb-fm">Floors and rooms</label>
                <select id="eb-fm" name="floor_mode">
                    <?php foreach (MAP_FLOOR_MODES as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $sel_mode === $k ? 'selected' : ''; ?>><?php echo e($l); ?></option><?php endforeach; ?>
                </select>
                <p class="muted mq-hint">Switching to &ldquo;No floors or rooms&rdquo; deletes this place&rsquo;s floors and rooms. &ldquo;Rooms only&rdquo; merges its rooms into one list.</p>
            </div>
            <?php echo map_status_fields($sel, 'eb-'); ?>
            <div class="mq-actions">
                <button type="submit" class="btn btn-primary">Save building</button>
                <a href="<?php echo BASE_URL; ?>/dashboard/map.php?b=<?php echo $sel_id; ?>#building" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    <?php else: ?>
        <form method="POST" class="mq-quick">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="quick_building">
            <input type="hidden" name="building_id" value="<?php echo $sel_id; ?>">
            <?php echo map_status_select('status', $sel['status'], 'qb-status', 'Status'); ?>
            <label class="sr-only" for="qb-note">Note for students</label>
            <input type="text" id="qb-note" name="status_note" maxlength="160" value="<?php echo e($sel['status_note'] ?? ''); ?>" placeholder="Note for students (optional)">
            <button type="submit" class="btn btn-primary">Save status</button>
        </form>
        <div class="mq-meta muted">
            <?php if (map_hours_label($sel)): ?><span>Hours: <?php echo e(map_hours_label($sel)); ?></span><?php endif; ?>
            <?php if (map_window_label($sel)): ?><span><?php echo e(map_window_label($sel)); ?></span><?php endif; ?>
            <span>Type: <?php echo e(MAP_BUILDING_CATEGORIES[$sel['category']] ?? ''); ?></span>
            <?php if ($sel_mode !== 'floors'): ?><span><?php echo e(MAP_FLOOR_MODES[$sel_mode]); ?></span><?php endif; ?>
        </div>
        <div class="mq-actions">
            <a href="<?php echo BASE_URL; ?>/dashboard/map.php?b=<?php echo $sel_id; ?>&amp;edit_b=<?php echo $sel_id; ?>#building" class="btn btn-outline btn-sm">Edit name, hours and dates</a>
            <form method="POST" <?php echo confirm_attr('Remove ' . $sel['name'] . ' and all its floors and rooms? This cannot be undone.'); ?>>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="delete_building">
                <input type="hidden" name="building_id" value="<?php echo $sel_id; ?>">
                <button type="submit" class="btn btn-danger btn-sm">Remove building</button>
            </form>
        </div>
    <?php endif; ?>
</section>

<?php if ($sel_mode !== 'none'): ?>
<!-- Step 3: floors and rooms (gates and "no floors or rooms" places have none) -->
<section class="mq-step" id="floors">
    <div class="mq-step-head mq-step-head-plain"><span class="mq-num">3</span><h2><?php echo $sel_mode === 'rooms_only' ? 'Rooms' : 'Floors and rooms'; ?></h2></div>

    <?php if (!$floors): ?>
        <div class="panel mq-empty"><?php echo $sel_mode === 'rooms_only' ? 'No rooms yet.' : 'No floors yet. Add the first floor below.'; ?></div>
    <?php endif; ?>

    <?php foreach ($floors as $f): $fid = (int) $f['floor_id']; $rooms = $rooms_by_floor[$fid] ?? []; ?>
        <div class="panel mq-floor" id="floor-<?php echo $fid; ?>">
            <div class="mq-floor-head">
                <h3><?php echo $sel_mode === 'rooms_only' ? 'Rooms' : 'Floor ' . e($f['label']); ?></h3>
                <span class="muted"><?php echo count($rooms); ?> room<?php echo count($rooms) === 1 ? '' : 's'; ?></span>
                <?php if ($sel_mode === 'floors'): ?>
                <form method="POST" <?php echo confirm_attr('Remove floor ' . $f['label'] . ' and all its rooms?'); ?>>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="delete_floor">
                    <input type="hidden" name="floor_id" value="<?php echo $fid; ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Remove floor</button>
                </form>
                <?php endif; ?>
            </div>

            <?php if ($rooms): ?>
            <form method="POST" id="bulk-<?php echo $fid; ?>" class="mq-bulkbar">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="bulk_room_update">
                <input type="hidden" name="floor_id" value="<?php echo $fid; ?>">
                <label class="mq-room-check"><input type="checkbox" data-tick-all=".mq-tick-<?php echo $fid; ?>" aria-label="Select all rooms on this floor"><span>Select all</span></label>
                <?php echo map_status_select('status', 'open', 'bs-' . $fid, 'Status for selected rooms'); ?>
                <label class="sr-only" for="bn-<?php echo $fid; ?>">Note for selected rooms</label>
                <input type="text" id="bn-<?php echo $fid; ?>" name="status_note" maxlength="160" placeholder="Note (optional)">
                <button type="submit" name="bulk_op" value="status" class="btn btn-primary btn-sm">Apply to selected</button>
                <button type="submit" name="bulk_op" value="delete" class="btn btn-danger btn-sm" onclick="return confirm('Remove the selected rooms?');">Remove selected</button>
            </form>
            <?php endif; ?>

            <?php if ($rooms): ?>
            <ul class="mq-rooms">
                <?php foreach ($rooms as $r): $rid = (int) $r['room_id']; ?>
                    <li class="mq-room" id="room-<?php echo $rid; ?>">
                        <?php if ($edit_r === $rid): ?>
                            <form method="POST" class="mq-edit">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="update_room">
                                <input type="hidden" name="room_id" value="<?php echo $rid; ?>">
                                <div class="form-row form-row-split">
                                    <div><label for="er-name-<?php echo $rid; ?>">Room name or number</label><input type="text" id="er-name-<?php echo $rid; ?>" name="name" required value="<?php echo e($r['name']); ?>"></div>
                                    <div><?php echo map_room_type_select($r['room_type'], 'er-' . $rid . '-'); ?></div>
                                </div>
                                <?php echo map_status_fields($r, 'er-' . $rid . '-'); ?>
                                <div class="mq-actions">
                                    <button type="submit" class="btn btn-primary btn-sm">Save room</button>
                                    <a href="<?php echo BASE_URL; ?>/dashboard/map.php?b=<?php echo $sel_id; ?>#room-<?php echo $rid; ?>" class="btn btn-outline btn-sm">Cancel</a>
                                </div>
                            </form>
                        <?php else: ?>
                            <form method="POST" class="mq-room-row">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="quick_room">
                                <input type="hidden" name="room_id" value="<?php echo $rid; ?>">
                                <div class="mq-room-name">
                                    <label class="mq-room-check"><input type="checkbox" name="room_ids[]" value="<?php echo $rid; ?>" form="bulk-<?php echo $fid; ?>" class="mq-tick-<?php echo $fid; ?>" aria-label="Select <?php echo e($r['name']); ?>"><strong><?php echo e($r['name']); ?></strong></label>
                                    <span class="muted"><?php echo e(MAP_ROOM_TYPES[$r['room_type']] ?? ''); ?><?php echo map_hours_label($r) ? ' · ' . e(map_hours_label($r)) : ''; ?></span>
                                </div>
                                <?php echo map_status_select('status', $r['status'], 'rs-' . $rid, 'Status for ' . $r['name']); ?>
                                <label class="sr-only" for="rn-<?php echo $rid; ?>">Note for <?php echo e($r['name']); ?></label>
                                <input type="text" id="rn-<?php echo $rid; ?>" name="status_note" maxlength="160" value="<?php echo e($r['status_note'] ?? ''); ?>" placeholder="Note (optional)">
                                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                <a href="<?php echo BASE_URL; ?>/dashboard/map.php?b=<?php echo $sel_id; ?>&amp;edit_r=<?php echo $rid; ?>#room-<?php echo $rid; ?>" class="mq-link">Hours and dates</a>
                            </form>
                            <form method="POST" class="mq-room-remove" <?php echo confirm_attr('Remove room ' . $r['name'] . '?'); ?>>
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_room">
                                <input type="hidden" name="room_id" value="<?php echo $rid; ?>">
                                <button type="submit" class="mq-link mq-link-danger">Remove</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
                <p class="muted mq-empty-line"><?php echo $sel_mode === 'rooms_only' ? 'No rooms yet.' : 'No rooms on this floor yet.'; ?></p>
            <?php endif; ?>

            <details class="mq-add">
                <summary>+ Add many rooms<?php echo $sel_mode === 'rooms_only' ? '' : ' to floor ' . e($f['label']); ?></summary>
                <form method="POST" class="mq-add-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="bulk_rooms">
                    <input type="hidden" name="floor_id" value="<?php echo $fid; ?>">
                    <label for="br-rooms-<?php echo $fid; ?>">Rooms (one per line, or separated by commas)</label>
                    <textarea id="br-rooms-<?php echo $fid; ?>" name="rooms" rows="4" required placeholder="201-210&#10;Faculty Room, Dean's Office | office&#10;Lab 1-3 | lab"></textarea>
                    <p class="muted mq-hint">A range like 201-210 makes one room per number. Add &ldquo;| lab&rdquo; (room, office, lab, facility or restroom) to set a type; the type below is used otherwise. Existing names are skipped.</p>
                    <div class="form-row form-row-split">
                        <div><?php echo map_room_type_select('room', 'br-' . $fid . '-'); ?></div>
                        <div><label for="br-status-<?php echo $fid; ?>">Status</label><?php echo map_status_select('status', 'open', 'br-status-' . $fid); ?></div>
                    </div>
                    <button type="submit" class="btn btn-primary">Add rooms</button>
                </form>
            </details>

            <details class="mq-add">
                <summary>+ Add a room<?php echo $sel_mode === 'rooms_only' ? '' : ' to floor ' . e($f['label']); ?></summary>
                <form method="POST" class="mq-add-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create_room">
                    <input type="hidden" name="floor_id" value="<?php echo $fid; ?>">
                    <div class="form-row form-row-split">
                        <div><label for="nr-name-<?php echo $fid; ?>">Room name or number</label><input type="text" id="nr-name-<?php echo $fid; ?>" name="name" required placeholder="e.g. 204"></div>
                        <div><?php echo map_room_type_select('room', 'nr-' . $fid . '-'); ?></div>
                    </div>
                    <div class="form-row form-row-split">
                        <div><label for="nr-status-<?php echo $fid; ?>">Status</label><?php echo map_status_select('status', 'open', 'nr-status-' . $fid); ?></div>
                        <div><label for="nr-note-<?php echo $fid; ?>">Note (optional)</label><input type="text" id="nr-note-<?php echo $fid; ?>" name="status_note" maxlength="160"></div>
                    </div>
                    <button type="submit" class="btn btn-primary">Add room</button>
                </form>
            </details>
        </div>
    <?php endforeach; ?>

    <?php if ($sel_mode === 'floors'): ?>
    <details class="mq-add panel">
        <summary>+ Add many floors to <?php echo e($sel['name']); ?></summary>
        <form method="POST" class="mq-add-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="bulk_floors">
            <input type="hidden" name="building_id" value="<?php echo $sel_id; ?>">
            <label for="bf-floors">Floors (separated by commas or new lines)</label>
            <textarea id="bf-floors" name="floors" rows="3" required placeholder="Ground, 2-5, Rooftop"></textarea>
            <p class="muted mq-hint">&ldquo;2-5&rdquo; adds 2nd, 3rd, 4th and 5th. New floors are placed after the existing ones in the order you type them. Existing names are skipped.</p>
            <button type="submit" class="btn btn-primary">Add floors</button>
        </form>
    </details>

    <details class="mq-add panel">
        <summary>+ Add a floor to <?php echo e($sel['name']); ?></summary>
        <form method="POST" class="mq-add-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create_floor">
            <input type="hidden" name="building_id" value="<?php echo $sel_id; ?>">
            <div class="form-row form-row-split">
                <div><label for="nf-label">Floor name</label><input type="text" id="nf-label" name="label" required maxlength="40" placeholder="e.g. Ground, 2nd, Mezzanine"></div>
                <div><label for="nf-sort">Order (lower shows first)</label><input type="number" id="nf-sort" name="sort_order" value="<?php echo (count($floors) + 1) * 10; ?>"></div>
            </div>
            <button type="submit" class="btn btn-primary">Add floor</button>
        </form>
    </details>
    <?php endif; ?>
</section>

<?php endif; ?>
<?php else: ?>
    <div class="panel mq-empty">No buildings yet. Add the first one above.</div>
<?php endif; ?>

<script>
document.querySelectorAll('[data-tick-all]').forEach(function (all) {
    all.addEventListener('change', function () {
        document.querySelectorAll(all.getAttribute('data-tick-all')).forEach(function (c) { c.checked = all.checked; });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
