<?php
/**
 * Campus map helpers, shared by the public Map page and the admin editor.
 *
 * Hierarchy: buildings -> floors -> rooms. Each level can have hours and an
 * availability window. Students see the status that applies right now:
 * a manual status (maintenance / closed) wins, then the date window, then the hours.
 */

const MAP_STATUS_LABELS = [
    'open'        => 'Open',
    'limited'     => 'Limited',
    'maintenance' => 'Under maintenance',
    'closed'      => 'Closed',
];

const MAP_ROOM_TYPES = [
    'room'     => 'Room',
    'office'   => 'Office',
    'lab'      => 'Laboratory',
    'facility' => 'Facility',
    'restroom' => 'Restroom',
];

const MAP_BUILDING_CATEGORIES = [
    'building' => 'Building',
    'facility' => 'Facility',
    'office'   => 'Office',
    'restroom' => 'Restroom',
    'gate'     => 'Gate',
];

/**
 * How a place uses floors and rooms:
 *  floors     = building > floors > rooms (default)
 *  rooms_only = rooms listed directly, no floors shown (one hidden floor holds them)
 *  none       = no floors and no rooms (amphitheater, lounge, canteen, restroom ...)
 */
const MAP_FLOOR_MODES = [
    'floors'     => 'Floors and rooms',
    'rooms_only' => 'Rooms only (no floors)',
    'none'       => 'No floors or rooms',
];

/** Label of the hidden floor that holds the rooms of a rooms-only place. */
const MAP_HIDDEN_FLOOR = 'Rooms';

/** Names of places that have no floors and no rooms, and of places with rooms but no floors. */
const MAP_NO_FLOOR_NAMES = [
    'Amphi Theater', 'University Bus Shed', 'Student Lounge', 'CTED Restroom', 'CIT Open Court',
    'CIT Canteen', 'University Registrar Cashier', 'Research and Extension Office',
    'CAS Student Lounge', 'Interfaith',
];
const MAP_ROOMS_ONLY_NAMES = [
    'Care Center', 'Student Affairs Office', 'Supply Room',
    'Mathematics and Social Science Department', 'Tech Room', 'AVR',
];

/** The mode that really applies to a building row (gates never have floors). */
function map_floor_mode(array $b) {
    if (($b['category'] ?? '') === 'gate') return 'none';
    $m = $b['floor_mode'] ?? 'floors';
    return isset(MAP_FLOOR_MODES[$m]) ? $m : 'floors';
}

/**
 * Makes a building's floors and rooms match its floor mode.
 * none: floors and rooms are deleted. rooms_only: every room moves onto one hidden floor.
 * Returns the hidden floor id for rooms_only, otherwise 0.
 */
function map_apply_floor_mode($conn, $building_id) {
    $building_id = (int) $building_id;
    $b = $conn->query("SELECT category, floor_mode FROM map_buildings WHERE building_id = " . $building_id)->fetch_assoc();
    if (!$b) return 0;
    $mode = map_floor_mode($b);
    if ($mode === 'none') {
        $conn->query("DELETE FROM map_floors WHERE building_id = " . $building_id);
        return 0;
    }
    if ($mode !== 'rooms_only') return 0;

    $ids = [];
    $res = $conn->query("SELECT floor_id FROM map_floors WHERE building_id = $building_id ORDER BY sort_order, floor_id");
    while ($f = $res->fetch_assoc()) $ids[] = (int) $f['floor_id'];
    if (!$ids) {
        $stmt = $conn->prepare("INSERT INTO map_floors (building_id, label, sort_order) VALUES (?, ?, 0)");
        $label = MAP_HIDDEN_FLOOR;
        $stmt->bind_param('is', $building_id, $label);
        $stmt->execute();
        return (int) $conn->insert_id;
    }
    $keep = array_shift($ids);
    if ($ids) {
        $in = implode(',', $ids);
        $conn->query("UPDATE map_rooms SET floor_id = $keep WHERE floor_id IN ($in)");
        $conn->query("DELETE FROM map_floors WHERE floor_id IN ($in)");
    }
    return $keep;
}

function map_time_label($time) {
    return $time ? date('g:i A', strtotime($time)) : '';
}

function map_date_label($date) {
    return $date ? date('M j, Y', strtotime($date)) : '';
}

/** "8:00 AM to 5:00 PM", or '' when hours are not set. */
function map_hours_label($row) {
    if (empty($row['open_time']) || empty($row['close_time'])) return '';
    return map_time_label($row['open_time']) . ' to ' . map_time_label($row['close_time']);
}

/** "Available from Jan 5, 2027 until Mar 2, 2027", or '' when no window is set. */
function map_window_label($row) {
    $from = $row['date_opened'] ?? null;
    $until = $row['date_closed'] ?? null;
    if (!$from && !$until) return '';
    $parts = [];
    if ($from) $parts[] = 'from ' . map_date_label($from);
    if ($until) $parts[] = 'until ' . map_date_label($until);
    return 'Available ' . implode(' ', $parts);
}

function map_result($key, $detail = '') {
    return ['key' => $key, 'label' => MAP_STATUS_LABELS[$key], 'detail' => (string) $detail];
}

/** What a place's status is right now, in Manila time. */
function map_availability(array $row) {
    $now   = new DateTime('now', new DateTimeZone('Asia/Manila'));
    $today = $now->format('Y-m-d');
    $time  = $now->format('H:i:s');
    $manual = $row['status'] ?? 'open';

    if ($manual === 'closed' || $manual === 'maintenance') {
        return map_result($manual, $row['status_note'] ?? '');
    }
    if (!empty($row['date_opened']) && $today < $row['date_opened']) {
        return map_result('closed', 'Available from ' . map_date_label($row['date_opened']));
    }
    if (!empty($row['date_closed']) && $today > $row['date_closed']) {
        return map_result('closed', 'No longer available after ' . map_date_label($row['date_closed']));
    }

    $hours = map_hours_label($row);
    if ($hours !== '') {
        $o = $row['open_time'];
        $c = $row['close_time'];
        // Hours that cross midnight (for example 6:00 PM to 2:00 AM) are handled too.
        $inside = ($o <= $c) ? ($time >= $o && $time <= $c) : ($time >= $o || $time <= $c);
        if (!$inside) return map_result('closed', 'Hours: ' . $hours);
    }

    if ($manual === 'limited') {
        return map_result('limited', $row['status_note'] ?: 'Limited availability');
    }
    return map_result('open', $hours !== '' ? 'Hours: ' . $hours : ($row['status_note'] ?? ''));
}

/** A room follows its building: if the building is closed or under maintenance, so is the room. */
function map_room_availability(array $room, array $building_av) {
    if (in_array($building_av['key'], ['closed', 'maintenance'], true)) {
        return map_result($building_av['key'], 'Building: ' . $building_av['detail']);
    }
    return map_availability($room);
}

/**
 * Everything the public map needs, in one structure:
 * buildings (with status) > floors > rooms (with status).
 */
function map_load_all($conn) {
    $floors_by_building = [];
    $rooms_by_floor     = [];

    $res = $conn->query("SELECT * FROM map_floors ORDER BY sort_order, label");
    while ($f = $res->fetch_assoc()) {
        $floors_by_building[$f['building_id']][] = $f;
    }
    $res = $conn->query("SELECT * FROM map_rooms ORDER BY name");
    while ($r = $res->fetch_assoc()) {
        $rooms_by_floor[$r['floor_id']][] = $r;
    }
    $res = $conn->query("SELECT * FROM map_buildings ORDER BY FIELD(category,'building','facility','office','restroom','gate'), name");

    $out = [];
    while ($b = $res->fetch_assoc()) {
        $bav = map_availability($b);
        $floors = [];
        $flat_rooms = [];
        $mode = map_floor_mode($b);
        // Gates and "no floors or rooms" places show neither.
        $floor_rows = $mode === 'none' ? [] : ($floors_by_building[$b['building_id']] ?? []);
        foreach ($floor_rows as $f) {
            $rooms = [];
            foreach ($rooms_by_floor[$f['floor_id']] ?? [] as $r) {
                $rav = map_room_availability($r, $bav);
                $rooms[] = [
                    'id'     => (int) $r['room_id'],
                    'name'   => $r['name'],
                    'type'   => MAP_ROOM_TYPES[$r['room_type']] ?? 'Room',
                    'status' => $rav['key'],
                    'label'  => $rav['label'],
                    'detail' => $rav['detail'],
                    'hours'  => map_hours_label($r),
                    'window' => map_window_label($r),
                ];
            }
            if ($mode === 'rooms_only') {
                $flat_rooms = array_merge($flat_rooms, $rooms);   // no floor level for these places
            } else {
                $floors[] = ['id' => (int) $f['floor_id'], 'label' => $f['label'], 'rooms' => $rooms];
            }
        }
        $out[] = [
            'id'       => (int) $b['building_id'],
            'name'     => $b['name'],
            'code'     => $b['code'] ?? '',
            'category' => $b['category'],
            'x'        => (float) $b['pos_x'],
            'y'        => (float) $b['pos_y'],
            'status'   => $bav['key'],
            'label'    => $bav['label'],
            'detail'   => $bav['detail'],
            'hours'    => map_hours_label($b),
            'window'   => map_window_label($b),
            'mode'     => $mode,
            'floors'   => $floors,
            'rooms'    => $flat_rooms,
        ];
    }
    return $out;
}
