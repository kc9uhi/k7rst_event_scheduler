<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/assigned_call.php';
require_once __DIR__ . '/wl_link.php';

log_msg(DEBUG_VERBOSE, "Session start - Current session data: " . json_encode($_SESSION));

if (isset($_GET['logout'])) {
	auth_logout();
    exit; // Stop further script execution
}

$session_timeout_minutes = round(ini_get('session.gc_maxlifetime') / 60);

// Who is it, what can they do?
$logged_in_call 	= auth_get_callsign();
$logged_in_name 	= auth_get_name();
$edit_authorized 	= auth_is_authenticated();
$browse_authorized 	= auth_is_browse_only();
$admin_authorized 	= auth_is_admin();

// Initialize variables
$start_date = '';
$end_date = '';
$time_slots = array_keys(TIME_OPTS); // all time slots selected by default
$days_of_week = array_keys(DAY_OPTS); // all days of week selected by default
$bands_list = BANDS_LIST; // all bands selected by default
$modes_list = MODES_LIST; // all modes selected by default

$event_start_date = EVENT_START_DATE;
$event_start_time = EVENT_START_TIME;
$event_end_date = EVENT_END_DATE;
$event_end_time = EVENT_END_TIME;

// Compute today's date in UTC and whether to show the Today button
$today_utc = gmdate('Y-m-d');
$show_today_button = (
    $today_utc >= $event_start_date &&
    $today_utc <= $event_end_date
);

// default values for show selections, gated by browse_only
// these are overridden by filter form post below
if (!auth_is_authenticated()) {
	log_msg(DEBUG_VERBOSE,"setting browse_only defaults");
	$show_all_ops = true;
	$show_open_slots = false;
} else {
	log_msg(DEBUG_VERBOSE,"setting normal defaults");
	$show_all_ops = false;
	$show_open_slots = true;
}

// collect filter form data
// TODO $start_time, $end_time not currently used, but let's keep them.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $start_date = $_POST['start_date'] ?? '';
	$start_time = ($start_date == $event_start_date ? $event_start_time : "00:00:00");
    $end_date = $_POST['end_date'] ?? '';
	$end_time = ($end_date == $event_end_date ? $event_end_time : "23:59:59");
    $time_slots = $_POST['time_slots'] ?? [];
    $days_of_week = $_POST['days_of_week'] ?? [];
    $bands_list = $_POST['bands_list'] ?? [];
    $modes_list = $_POST['modes_list'] ?? [];

	$show_all_ops = ($_POST['show_me_or_all'] ?? '') == 'show_all_ops';
	$show_open_slots = ($_POST['show_open_slots'] ?? '') == 'show_open_slots';

    log_msg(DEBUG_DEBUG, "Incoming POST: " . json_encode($_POST));
}

$db_conn = get_event_db_connection_from_master(EVENT_NAME);

$table_rows = [];

$club_station_conflict = 0;
$band_mode_conflict = 0;
$required_club_station_missing = 0;

// Process schedule additions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $edit_authorized && isset($_POST['add_selected'])) { 
	if (!isset($_POST['slots'])) { 
		$_SESSION['nothing_to_add_flash'] = true;
		log_msg(DEBUG_WARNING, "add button clicked but nothing selected to add.");
	} else { // some schedule adds have been selected
		log_msg(DEBUG_INFO, "processing schedule add of ".count($_POST['slots'])." slots:");
		foreach ($_POST['slots'] as $slot) {
			$assigned_call = null;
			list($date, $time) = explode('|', $slot);
			// $assigned_call = $_POST['assigned_call'][$slot] ?? ''; // assigned call is not an input field
			$band = $_POST['band'][$slot] ?? null;
			$mode = $_POST['mode'][$slot] ?? null;
			$club_station = $_POST['club_station'][$slot] ?? '';
			$notes = $_POST['notes'][$slot] ?? '';
			log_msg(DEBUG_INFO, "\tadd info: date=" . $date . ", time=" . $time . ", call=" . $logged_in_call . ", band=" . $band . ", mode=" . $mode . ", club_station=" . $club_station . ", notes=" . $notes);
			// Check club station conflict: more than one op in given date/time/club_station
			if (!empty($club_station)) {
				$club_station_conflict_check = db_check_club_station_in_use($db_conn, $date, $time, $club_station);
				if ($club_station_conflict_check) {
					$club_station_conflict += 1;
					$_SESSION['club_station_conflict_flash'] = true;
					log_msg(DEBUG_INFO, "club_station_conflict: date=" . $date . ", time=" . $time . ", call=" . $logged_in_call . ", band=" . $band . ", mode=" . $mode . ", club_station=" . $club_station . ", notes=" . $notes);
				}
			}
			// For some events (e.g. Field Day) "club_station" selection is required
			if (empty($club_station) && CLUB_STATION_REQUIRED) {
				$_SESSION['required_club_station_missing_flash'] = true;
				log_msg(DEBUG_INFO, "required_club_station_missing: date=" . $date . ", time=" . $time . ", call=" . $logged_in_call . ", band=" . $band . ", mode=" . $mode . ", club_station=" . $club_station . ", notes=" . $notes);
				$required_club_station_missing += 1;
			}

			// Check band/mode conflict: more than one op in given date/time/band/mode
			$band_mode_conflict_check = db_check_band_mode_conflict($db_conn, $date, $time, $band, $mode);
			if ($band_mode_conflict_check) {
				$band_mode_conflict += 1;
				$_SESSION['band_mode_conflict_flash'] = true;
				log_msg(DEBUG_INFO, "band_mode_conflict: date=" . $date . ", time=" . $time . ", call=" . $logged_in_call . ", band=" . $band . ", mode=" . $mode . ", club_station=" . $club_station . ", notes=" . $notes);
			}

			if(EVENT_CALLSIGNS_REQUIRED) {
				$assigned_call = choose_assigned_call($date, $time, $logged_in_call, $band, $mode);
				if ($assigned_call === null) {
					// all calls are in use in this slot, must reject
					$_SESSION['slot_full_flash'] = true;
					log_msg(DEBUG_INFO, "No callsign available for {$date} {$time} (slot full).");
				} else {
					log_msg(DEBUG_VERBOSE, "assigned_call is {$assigned_call} for {$date} {$time} {$logged_in_call}.");
				}
			}

			//TODO unclear why this is based on conflict count rather than just a flag for this row
			if ((!EVENT_CALLSIGNS_REQUIRED || $assigned_call) 
				&& !$band_mode_conflict 
				&& !$club_station_conflict 
				&& !$required_club_station_missing) {
				// it may have been awhile since DB connection; check and re-establish if necessary
				if (!mysqli_ping($db_conn)) {
					mysqli_close($db_conn);
					$db_conn = get_event_db_connection_from_master(EVENT_NAME);
				}
				db_add_schedule_line($db_conn, $date, $time, $logged_in_call, $logged_in_name, $band, $mode, $assigned_call, $club_station, $notes);
				if(WL_WEBHOOK_EN & (1 << 0)) {  //bit 0 enables 'opcheck' webhook
					$wl_return = wl_bridge_opcheck(WL_EVENTCALL_APIKEY["$assigned_call"], $logged_in_call, $assigned_call);
					if (!empty($wl_return['info'])) $_SESSION['wavelog_info_flash'] = $wl_return['info'];
				}
				if (WL_WEBHOOK_EN & (1 << 1)) { // bit 1 enables 'gridcheck' webhook
					if (!empty($club_station)) {
						$clubstation_grid = is_array(CLUB_STATIONS[$club_station]) ? CLUB_STATIONS[$club_station]['grid'] : '';
						$clubstation_name = is_array(CLUB_STATIONS[$club_station]) ? CLUB_STATIONS[$club_station]['display_name'] : $club_station;
					} else {
						$clubstation_grid = '';
						$clubstation_name = '';
					}
					$wl_return = wl_bridge_gridcheck(WL_EVENTCALL_APIKEY["$assigned_call"], $logged_in_call, $assigned_call, $clubstation_grid, $clubstation_name, $notes);
					if (!empty($wl_return['info'])) $_SESSION['wavelog_info_flash'] = empty($_SESSION['wavelog_info_flash']) ? $wl_return['info'] : $_SESSION['wavelog_info_flash'] . ' ' . $wl_return['info'];
				}
			}
		}
	}	
} 

// Process schedule deletions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $edit_authorized && isset($_POST['delete_selected'])) { 
	if (!isset($_POST['delete_slots'])) { 
		log_msg(DEBUG_WARNING, "delete button clicked but nothing selected to delete.");
		$_SESSION['nothing_to_delete_flash'] = true;
	} else { // schedule deletes have been selected
		log_msg(DEBUG_INFO, "processing schedule delete of ".count($_POST['delete_slots'])." slots:");
		foreach ($_POST['delete_slots'] as $slot) {
			list($date, $time, $band, $mode) = explode('|', $slot);
			log_msg(DEBUG_VERBOSE, "processing delete: $date $time $band $mode $logged_in_call");
			$deleted = db_delete_schedule_line($db_conn, $date, $time, $band, $mode, $logged_in_call);
			log_msg(DEBUG_VERBOSE, "delete result: " . $deleted);
		}
	}
}

// build the table to be displayed, optionally with add/delete buttons (if authorized)
// this code runs for POST or the initial GET

if ($start_date && !$end_date) $end_date = $start_date;
if (!$start_date && $end_date) $start_date = $end_date;
if (!$start_date && !$end_date) {
	$start_date = $event_start_date;
	$end_date = $event_end_date;
}

// On initial GET, default to today's date as start date. Subsequent POSTs can override. 
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $today_utc > $start_date && $today_utc <= $end_date) {
	$start_date = $today_utc;
}

log_msg(DEBUG_INFO, "start/end dates: " . $start_date . " " . $end_date);

$dates = [];
$cur = strtotime($start_date);
$end = strtotime($end_date);
while ($cur <= $end) {
	$day = date('w', $cur);
	if (in_array('all', $days_of_week) || in_array((string)$day, $days_of_week)) {
		$dates[] = date('Y-m-d', $cur);
	}
	$cur = strtotime('+1 day', $cur);
}

$times = [];
if (in_array('all', $time_slots)) {
	foreach (TIMES_BY_SLOT as $block) $times = array_merge($times, $block);
} else {
	foreach ($time_slots as $slot) {
		if (isset(TIMES_BY_SLOT[$slot])) {
			$times = array_merge($times, TIMES_BY_SLOT[$slot]);
		}
	}
}

log_msg(DEBUG_VERBOSE, "dates[]: " . json_encode($dates));
log_msg(DEBUG_VERBOSE, "times[]: " . json_encode($times));
log_msg(DEBUG_VERBOSE, "bands[]: " . json_encode($bands_list));
log_msg(DEBUG_VERBOSE, "modes[]: " . json_encode($modes_list));

// it may have been awhile since DB connection; check and re-establish if necessary
if (!mysqli_ping($db_conn)) {
	mysqli_close($db_conn);
	$db_conn = get_event_db_connection_from_master(EVENT_NAME);
}

$use_counts = null; // accumulator for usage counts of CALL, BAND, MODE, etc
foreach ($dates as $date) {
	foreach ($times as $time) {
		if ($date == $event_start_date && $time < $event_start_time) continue;
		if ($date == $event_end_date && $time >= $event_end_time) continue;
		$r = db_get_schedule_for_date_time($db_conn, $date, $time);
		$none_are_me = true;
		$schedule_count_in_this_slot = $r->num_rows;
		while ($r->num_rows > 0 && $row = $r->fetch_assoc()) {
			$op = $row ? strtoupper($row['op_call']) : null;
			$this_is_me = ($op === $logged_in_call);
			if ($this_is_me) $none_are_me = false;
			$name = $row ? $row['op_name'] : null;
			$band = $row ? $row['band'] : null;
			$mode = $row ? $row['mode'] : null;
			log_msg(DEBUG_VERBOSE, "Testing schedule line on filters: $date $time $band $mode");
			$assigned_call = $row['assigned_call'] ?? '';
			$club_station = $row['club_station'] ?? '';
			$notes = $row['notes'] ?? '';
			$filtering_this_line = false;
			// apply band and mode filters
			if (!in_array($band, $bands_list) || !in_array($mode, $modes_list)) $filtering_this_line = true;
			// skip open slots when showing only scheduled
			elseif (!$show_open_slots && !$op) $filtering_this_line = true;  
			// skip lines for other ops when showing only mine
			elseif (!$show_all_ops && !$this_is_me) $filtering_this_line = true;
			if ($filtering_this_line) {
				log_msg(DEBUG_VERBOSE, "Schedule line got filtered out");
			} else {
				// Not filtered out - put it in the table to display
				log_msg(DEBUG_VERBOSE, "Schedule line was not filtered");
				$table_rows[] = compact('date', 'time', 'band', 'mode', 'assigned_call', 'op', 'name', 'club_station', 'notes');
				accumulate_use_count($use_counts, 'EVENT CALL', $assigned_call);
				accumulate_use_count($use_counts, 'OP', $op);
				accumulate_use_count($use_counts, 'BAND', $band);
				accumulate_use_count($use_counts, 'MODE', $mode);
			}
		}

		// add an unscheduled row if current call is not scheduled in this slot, for the possibility of adding "me",
		// because we can schedule multiple ops in one slot (other mode and/or other special event callsign).
		// Or add an open line for otherwise empty slots when "complete_schedule" is requested.

		// If we're showing open slots and I'm not in current slot, offer me an open slot here
		$offer_open_slot = ($show_open_slots && $none_are_me); 
		if ($offer_open_slot) log_msg(DEBUG_DEBUG, "tentative offer of open slot because I'm not scheduled");

		// Except if event callsigns are required and all are used, 
		// or club station is required and all are used
		// in which case no open slot is offered.
		if (EVENT_CALLSIGNS_REQUIRED && !EVENT_CALL_REUSE && $schedule_count_in_this_slot >= count(EVENT_CALLSIGNS)) {
			$offer_open_slot = false;
			log_msg(DEBUG_DEBUG, "event callsigns all used up, no offer of open slot");
		}
		if (CLUB_STATION_REQUIRED && $schedule_count_in_this_slot >= count(CLUB_STATIONS)) {
			$offer_open_slot = false;
			log_msg(DEBUG_DEBUG, "club stations all used up, no offer of open slot");
		}
		if ($offer_open_slot) {
			log_msg(DEBUG_VERBOSE, "Adding an open line for this slot");
			$assigned_call = $band = $mode = $op = $name = $club_station = $notes = null;
			$table_rows[] = compact('date', 'time', 'band', 'mode', 'assigned_call', 'op', 'name', 'club_station', 'notes');
		} else {
			log_msg(DEBUG_VERBOSE, "Won't show an open line for this slot");
		}
	}
}
log_msg(DEBUG_DEBUG, "Formatting page with result: " . json_encode($table_rows));

?>


<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo htmlspecialchars(EVENT_DISPLAY_NAME) ?> Operator Scheduling</title>
    <link rel="icon" href="img/cropped-RST-Logo-1-32x32.jpg">
	<link rel="stylesheet" href="scheduler.css">

	<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>

<body>
<div class="header-wrap">
  <img src="img/RST-header-768x144.jpg" alt="Radio Society of Tucson K7RST"
       title="<?= htmlspecialchars(EVENT_DISPLAY_NAME . ' scheduler, version ' . APP_VERSION) ?>" />
  <div class="vertical-label">VER<?= APP_VERSION ?></div>
</div>

<?php
?>

<h2><?php echo htmlspecialchars(EVENT_DISPLAY_NAME) ?> Operator Scheduling 
<a href="how-do-i-use-this.php" target="_blank">(How do I use this?)</a>
<!-- visualizer removed for now 
<a href="visualizer.php">(Switch to "Visualizer" grid)</a> 
-->
</h2>

<?php if (!empty($_SESSION['nothing_to_add_flash'])): ?>
    <div id="nothing-to-add-flash" style="
	    background-color: #f8d7da;
    	color: #721c24;
    	padding: 10px;
    	border: 1px solid #f5c6cb;
    	margin-bottom: 1em;
    	border-radius: 4px;
    	max-width: 600px;">        
		❌ Add slots button clicked but no slots selected for adding.
    </div>
<?php unset($_SESSION['nothing_to_add_flash']); endif; ?>

<?php if (!empty($_SESSION['nothing_to_delete_flash'])): ?>
    <div id="nothing-to-delete-flash" style="
	    background-color: #f8d7da;
    	color: #721c24;
    	padding: 10px;
    	border: 1px solid #f5c6cb;
    	margin-bottom: 1em;
    	border-radius: 4px;
    	max-width: 600px;">        
		❌ Delete slots button clicked but no slots selected for deleting.
    </div>
<?php unset($_SESSION['nothing_to_delete_flash']); endif; ?>

<?php if (!empty($_SESSION['band_mode_conflict_flash'])): ?>
    <div id="band-mode-conflict-flash" style="
	    background-color: #f8d7da;
    	color: #721c24;
    	padding: 10px;
    	border: 1px solid #f5c6cb;
    	margin-bottom: 1em;
    	border-radius: 4px;
    	max-width: 600px;">        
		❌ Band/mode conflict: only one op per date/time/band/mode.
    </div>
<?php unset($_SESSION['band_mode_conflict_flash']); endif; ?>

<?php if (!empty($_SESSION['club_station_conflict_flash'])): ?>
    <div id="club-station-conflict-flash" style="
	    background-color: #f8d7da;
    	color: #721c24;
    	padding: 10px;
    	border: 1px solid #f5c6cb;
    	margin-bottom: 1em;
    	border-radius: 4px;
    	max-width: 600px;">        
		❌ Club station conflict: only one op can use a given club station at a time.
    </div>
<?php unset($_SESSION['club_station_conflict_flash']); endif; ?>

<?php if (!empty($_SESSION['required_club_station_missing_flash'])): ?>
    <div id="required-club-station-missing-flash" style="
	    background-color: #f8d7da;
    	color: #721c24;
    	padding: 10px;
    	border: 1px solid #f5c6cb;
    	margin-bottom: 1em;
    	border-radius: 4px;
    	max-width: 600px;">        
		❌ Required club station missing: club station selection is required on schedule additions.
    </div>
<?php unset($_SESSION['required_club_station_missing_flash']); endif; ?>

<?php if (!empty($_SESSION['slot_full_flash'])): ?>
    <div id="slot-full-flash" style="
	    background-color: #f8d7da;
    	color: #721c24;
    	padding: 10px;
    	border: 1px solid #f5c6cb;
    	margin-bottom: 1em;
    	border-radius: 4px;
    	max-width: 600px;">        
		❌ Slot full: all event callsigns are in use for this time slot.
    </div>
<?php unset($_SESSION['slot_full_flash']); endif; ?>

<?php if (!empty($_SESSION['wavelog_info_flash'])): ?>
    <div id="wavelog-info-flash" style="
	    background-color: #f8d7da;
    	color: #721c24;
    	padding: 10px;
    	border: 1px solid #f5c6cb;
    	margin-bottom: 1em;
    	border-radius: 4px;
    	max-width: 600px;">        
		❌ <?php echo htmlspecialchars($_SESSION['wavelog_info_flash']); ?>
    </div>
<?php unset($_SESSION['wavelog_info_flash']); endif; ?>

<form method="POST" id="formschedule">
    <div class="section">
		<?php if ($edit_authorized): ?>
			<strong><?= htmlspecialchars($logged_in_call) ?></strong> is logged in.
		<?php else: ?>
			Logged in for browsing only.
		<?php endif; ?>
		<a href="?logout=1" class="logout-button">Log Out</a>
		<?php if ($edit_authorized): ?>
			<?php if (($spot_info = event_lookup_op_schedule(null, null, $logged_in_call))): ?>
				<br><strong>You are on <?= $spot_info["assigned_call"] ?> <?= $spot_info["band"] ?> <?=  $spot_info["mode"] ?> at <?= $spot_info['time'] ?></strong> <a href="self_spot.php" target="_blank" class="selfspot-button">Self Spot</a>
			<?php endif; ?>
		<?php endif ?>
    </div>

	<div class="filter-section">
		<div class="info-icon">
    		&#9432; <!-- Unicode info symbol -->
    		<span class="tooltiptext">
      			Make selections in this box to limit the displayed schedule
				to times, days, bands, and modes of interest. Leave everything
				as defaulted to see the entire schedule.
    		</span>
  		</div>
		<div class="section">
			<strong>Select Date Range to view:</strong>&nbsp; &nbsp;(Event period: <strong><?= htmlspecialchars($event_start_date) ?> <?= htmlspecialchars($event_start_time) ?></strong> to <strong><?= htmlspecialchars($event_end_date) ?> <?= htmlspecialchars($event_end_time) ?> UTC</strong>)<br>
			<label>Start:</label>
			<input type="date" name="start_date" value="<?= htmlspecialchars($start_date ?: $event_start_date) ?>"
				min="<?= htmlspecialchars($event_start_date) ?>" max="<?= htmlspecialchars($event_end_date) ?>" id="start_date" required>
			<label>End:</label>
			<input type="date" name="end_date" value="<?= htmlspecialchars($end_date ?: $start_date ?: $event_end_date) ?>"
				min="<?= htmlspecialchars($event_start_date) ?>" max="<?= htmlspecialchars($event_end_date) ?>" id="end_date" required>
			<?php if ($show_today_button): ?>
				<!-- Today (UTC) button, shown only when today's UTC date is in the event window -->
				<button type="button" id="today_button">Shortcut: set start/end to today/tomorrow</button>
			<?php endif; ?>
			<!-- Error message div -->
			<div id="date_error" style="color: red; display: none;">End date cannot be earlier than the start date.</div>
		</div>

		<script>
		document.getElementById("start_date").addEventListener("change", validateDates);
		document.getElementById("end_date").addEventListener("change", validateDates);

		// Automatically default end date to start date if not set
		window.onload = function() {
			var startDate = document.getElementById("start_date").value;
			var endDate = document.getElementById("end_date").value;
			if (!endDate) {
				document.getElementById("end_date").value = startDate;  // Set end date to start date if not specified
			}
			validateDates(); // Run validation after setting default
		};

		function validateDates() {
			// Get the start and end dates
			var startDate = document.getElementById("start_date").value;
			var endDate = document.getElementById("end_date").value;

			// If end date is earlier than start date, show error
			if (new Date(endDate) < new Date(startDate)) {
				document.getElementById("date_error").style.display = "block";
			} else {
				document.getElementById("date_error").style.display = "none";
			}
		}
		document.addEventListener('DOMContentLoaded', function () {
			var todayBtn = document.getElementById('today_button');
			if (!todayBtn) return;  // Button not rendered if outside event window

			todayBtn.addEventListener('click', function () {
				var today = "<?= htmlspecialchars($today_utc, ENT_QUOTES) ?>";
				var startInput = document.getElementById('start_date');
				var endInput   = document.getElementById('end_date');

				if (startInput) startInput.value = today;
				if (endInput) {
					const d = new Date(today);         // parse "YYYY-MM-DD"
					d.setDate(d.getDate() + 1);        // add one day
					const tomorrow = d.toISOString().split('T')[0];  // back to "YYYY-MM-DD"
					endInput.value = tomorrow;
				}

				// Clear any existing date error when we set a valid range
				var err = document.getElementById('date_error');
				if (err) err.style.display = 'none';
			});
		});
		</script>
		
		<!-- See JavaScript handlers at the bottom for how dynamic checkbox behavior is handled in these two <div> sections -->
		<div class="section">
			<strong>What parts of the day would you like to view? (UTC)</strong><br>
			<?php
			foreach (TIME_OPTS as $val => $label): ?>
				<?php if (strtoupper($val) === 'ALL'): ?>
					<label><input type="checkbox" class="select-all" data-group="time_slots" name="time_slots[]" value="<?= $val ?>" <?= in_array($val, $time_slots ?? []) ? 'checked' : '' ?>> <?= $label ?></label>
				<?php else: ?>
					<label><input type="checkbox" name="time_slots[]" value="<?= $val ?>" <?= in_array($val, $time_slots ?? []) ? 'checked' : '' ?>> <?= $label ?></label>
				<?php endif; ?>	
			<?php endforeach; ?>
		</div>

		<div class="section">
		<strong>Which days of the week? (UTC)</strong><br>
			<?php
			foreach (DAY_OPTS as $val => $label): ?>
				<?php if (strtoupper(strval($val)) === 'ALL'): ?>
					<label><input type="checkbox" class="select-all" data-group="days_of_week" name="days_of_week[]" value="<?= $val ?>" <?= in_array($val, $days_of_week ?? []) ? 'checked' : '' ?>> <?= $label ?></label>
				<?php else: ?>
					<label><input type="checkbox" name="days_of_week[]" value="<?= $val ?>" <?= in_array($val, $days_of_week ?? []) ? 'checked' : '' ?>> <?= $label ?></label>
				<?php endif; ?>	
		<?php endforeach; ?>
		</div>

		<div class="section">
			<strong>Select bands of interest:</strong><br>
			<?php
			foreach (BANDS_LIST as $band): ?>
				<?php if (strtoupper($band) === 'ALL'): ?>
					<label><input type="checkbox" class="select-all" data-group="bands_list" name="bands_list[]" value="<?= $band ?>" <?= in_array($band, $bands_list ?? []) ? 'checked' : '' ?>> <?= $band ?></label>
				<?php else: ?>
					<label><input type="checkbox" name="bands_list[]" value="<?= $band ?>" <?= in_array($band, $bands_list ?? []) ? 'checked' : '' ?>> <?= $band ?></label>
				<?php endif; ?>	
			<?php endforeach; ?>
		</div>

		<div class="section">
			<strong>Select modes of interest:</strong><br>
			<?php
			foreach (MODES_LIST as $mode): ?>
				<?php if (strtoupper($mode) === 'ALL'): ?>
					<label><input type="checkbox" class="select-all" data-group="modes_list" name="modes_list[]" value="<?= $mode ?>" <?= in_array($mode, $modes_list ?? []) ? 'checked' : '' ?>> <?= $mode ?></label>
				<?php else: ?>
					<label><input type="checkbox" name="modes_list[]" value="<?= $mode ?>" <?= in_array($mode, $modes_list ?? []) ? 'checked' : '' ?>> <?= $mode ?></label>
				<?php endif; ?>	
			<?php endforeach; ?>
		</div>

		<div class="section">
			<strong>Select whose schedule lines to show:</strong><br>
			<label><input type="radio" name="show_me_or_all" value="show_all_ops" <?= $show_all_ops ? 'checked' : '' ?>> All Ops</label>
			<label><input type="radio" name="show_me_or_all" value="show_just_me" <?= !$show_all_ops ? 'checked' : '' ?>> Just my call</label>
			<label><input type="checkbox" name="show_open_slots" value="show_open_slots" <?= $show_open_slots ? 'checked' : '' ?>> Include open slots</label>
		</div>
	</div>
	<br><br>

	<!-- See JavaScript handlers at the bottom for how enter key is handled -->
	<div class="section">
    	<div class="button-container">
        	<input type="submit" name="show_schedule" value="Show Schedule (use filter selections above)" 
				title="Show the schedule according to selections above. Make sure to select 'Include open slots' if you want to be able to add to your schedule.">
			<button type="button" onclick="openClubStationHours()">When are the club stations available?</button>
    	</div>
	</div>


<?php if ($table_rows): ?> 

	<table border="1" cellpadding="5">
		<tr>
			<th>Select</th><th>UTC Date</th><th>UTC</th><th>Local</th><th>Assigned Call</th><th>Band</th><th>Mode</th>
			<th>Club Station</th><th>Notes</th><th>Status</th>
		</tr>
		<?php 
		$prev_date_time = ''; 
		$highlight_colors = ['#fff3cd','#cce5ff']; 
		$highlight_color = ''; 
		$highlight_booked_by_you = '#d9fdd3'; 
		$highlight_index = 1; 
		foreach ($table_rows as $r):
			$date = $r['date'];
			$time = $r['time'];
			$fmt_dt = format_display_date_time($date, $time);
			$assigned_call = $r['assigned_call'];
			$op = $r['op'];
			$name = $r['name'];
			$band = $r['band'];
			$mode = $r['mode'];
			$key = "{$date}|{$time}|{$band}|{$mode}";
			$date_object = new DateTime($date);
			$day_of_week = $date_object->format('D');  // 'D' returns a 3-letter abbreviation for the day
			// Combine the day of the week with the original date
			$formatted_date = $day_of_week . ' ' . $date;
			$date_time = $date . '-' . $time;
			if ($date_time != $prev_date_time) {
				$highlight_index = ($highlight_index + 1) % 2;
				$highlight_color = $highlight_colors[$highlight_index];
				$prev_date_time = $date_time;
			}
			$status = $status_msg = 'Open';
			if ($op) {
				$status = ($op === $logged_in_call) ? 'Booked by you' : "Booked by {$op} {$name}";
				$status_msg = "{$op} {$name}";
			}
		?>
		<tr style="<?= $op === $logged_in_call ? 'background-color:' . $highlight_booked_by_you : 'background-color:' . $highlight_color ?>">
			<td>
				<?php if ($status === 'Open' && $edit_authorized): ?>
					<input type="checkbox" name="slots[]" value="<?= $key ?>">
				<?php elseif ($status === "Booked by you" && $edit_authorized): ?>
					<input type="checkbox" name="delete_slots[]" value="<?= $key ?>"> Delete
				<?php else: ?>
					--
				<?php endif; ?>
			</td>
			<td><?= $fmt_dt['dateUTC'] ?></td>
			<td><?= $fmt_dt['timeUTC'] ?></td>
			<td><?= $fmt_dt['timeLocal'] ?></td>
			<?php $link = ($assigned_call ? build_public_log_link(ASSIGNED_CALL: $assigned_call) : ''); ?>
			<td>
			<?php if ($link !== ''): ?>
				<a href="<?= htmlspecialchars($link, ENT_QUOTES) ?>" target="_blank" rel="noopener">
				<?= htmlspecialchars($assigned_call ?? '') ?>
				</a>
			<?php else: ?>
				<?= htmlspecialchars($assigned_call ?? '') ?>
			<?php endif; ?>
			</td>
			<td>
				<?php if ($edit_authorized && $status === 'Open'): ?>
					<!-- Dropdown for Band -->
					<select name="band[<?= $key ?>]">
						<?php foreach ($bands_list as $band): ?>
							<?php if (strtoupper($band) != "ALL"): ?>
								<option value="<?= $band ?>" <?= $band === $r['band'] ? 'selected' : '' ?>><?= $band ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
				<?php else: ?>
					<!-- Display fixed band -->
					<?= htmlspecialchars($r['band'] ?? '') ?: '--' ?>
				<?php endif; ?>
			</td>
			<td>
				<?php if ($edit_authorized && $status === 'Open'): ?>
					<!-- Dropdown for Mode -->
					<select name="mode[<?= $key ?>]">
						<?php foreach ($modes_list as $mode): ?>
							<?php if (strtoupper($mode) != "ALL"): ?>
								<option value="<?= $mode ?>" <?= $mode === $r['mode'] ? 'selected' : '' ?>><?= $mode ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
				<?php else: ?>
					<!-- Display fixed mode -->
					<?= htmlspecialchars($r['mode'] ?? '') ?: '--' ?>
				<?php endif; ?>
			</td>
			<td>
				<?php if ($edit_authorized && $status === 'Open'): ?>
					<!-- Dropdown for Club Station -->
					<select name="club_station[<?= $key ?>]">
						<option value="">(Own Station)</option>
						<?php
						$res = db_check_club_station_for_date_time($db_conn, $r['date'], $r['time']);
						$used = [];
						while ($res && $row2 = $res->fetch_assoc()) {
							if (!empty($row2['club_station'])) $used[] = $row2['club_station'];
						}
						foreach (CLUB_STATIONS as $station_key => $station_info):

							// Legacy format: ["Yuma Hamfest", ...]
							if (!is_array($station_info)) {
								$station_key  = $station_info;   // value/id becomes the string name
								$station_label = $station_info;  // label is the same
							} else {
								// New format: { "yuma_hamfest": { "display_name": "...", ... }, ... }
								$station_label = $station_info['display_name'] ?? $station_key;
							}

							if (!in_array($station_key, $used) && is_club_station_open($station_key, $date, $time)):
						?>
								<option value="<?= htmlspecialchars($station_key) ?>"
										<?= ($station_key === $r['club_station']) ? 'selected' : '' ?>>
									<?= htmlspecialchars($station_label) ?>
								</option>
						<?php
							endif;
						endforeach;
						?>
					</select>
				<?php else: ?>
					<!-- Display fixed club station -->
					<?php
					$cs_key = $r['club_station'] ?? '';
					$cs_display_name = '';

					if ($cs_key !== '') {
						if (isset(CLUB_STATIONS[$cs_key]) && is_array(CLUB_STATIONS[$cs_key])) {
							// new format
							$cs_display_name = CLUB_STATIONS[$cs_key]['display_name'] ?? $cs_key;
						} else {
							// legacy format (value stored is already the name)
							$cs_display_name = $cs_key;
						}
					}
					?>
					<?= htmlspecialchars($cs_display_name ?: '--') ?>
				<?php endif; ?>
			</td>
			<td>
				<?php if ($edit_authorized && $status === 'Open'): ?>
					<!-- Input for Notes -->
					<input type="text" name="notes[<?= $key ?>]" value="<?= htmlspecialchars($r['notes'] ?? '') ?>" size="20">
				<?php else: ?>
					<!-- Display fixed notes -->
					<?= htmlspecialchars($r['notes'] ?? '') ?: '--' ?>
				<?php endif; ?>
			</td>
			<td><?= $status_msg ?></td>
		</tr>
		<?php endforeach; ?>
	</table>

	<?php
	// Display usage counts
	$categories = get_use_categories($use_counts);

	if (defined('SHOW_USAGE_COUNTS') && SHOW_USAGE_COUNTS && !empty($categories)): ?>
		<h2>Allocation Summary</h2>

		<?php foreach ($categories as $category): 
			$counts = get_use_counts($use_counts, $category);
			if (empty($counts)) {
				continue;
			}

			// Optional: if you want TOTAL last, pull it out and re-add at the end
			$totalKey = defined('USE_COUNTS_TOTAL_KEY') ? USE_COUNTS_TOTAL_KEY : '#TOTAL#';
			$totalValue = null;
			if (isset($counts[$totalKey])) {
				$totalValue = $counts[$totalKey];
				unset($counts[$totalKey]);
			}
			?>

			<h3><?php echo htmlspecialchars($category); ?></h3>
			<table border="1" cellpadding="4" cellspacing="0">
				<tr>
					<?php foreach ($counts as $instance => $_): ?>
						<th><?php echo htmlspecialchars($instance); ?></th>
					<?php endforeach; ?>

					<?php if ($totalValue !== null): ?>
						<th><?php echo htmlspecialchars($totalKey); ?></th>
					<?php endif; ?>
				</tr>
				<tr>
					<?php foreach ($counts as $instance => $count): ?>
						<td><?php echo (int) $count; ?></td>
					<?php endforeach; ?>

					<?php if ($totalValue !== null): ?>
						<td><?php echo (int) $totalValue; ?></td>
					<?php endif; ?>
				</tr>
			</table>
			<br>

		<?php endforeach; ?>
	<?php endif; ?>

	<br>
	<?php if ($edit_authorized): ?>
		<script>
			//disable add/delete buttons on form submit. Prevent multiple clicks while processing
			function schedulesubmit(submittype) {
				document.getElementById('btnaddslots').disabled = true;
				document.getElementById('btndelslots').disabled = true;
				document.getElementById('hiddensubmit').name = submittype;
				document.getElementById('processnotification').style.visibility = 'visible';
			}
		</script>
		<input type="button" name="add_selected" value="Add Selected Slots" id="btnaddslots" onclick="schedulesubmit('add_selected'); form.submit();">
		<input type="button" name="delete_selected" value="Delete Selected Slots" id="btndelslots" onclick="schedulesubmit('delete_selected'); form.submit();">
		<input type="hidden" id="hiddensubmit">
		<div id="processnotification" style="background-color: #ffffff; color: #006600; padding: 10px; border: 1px solid #006600; margin-bottom: 1em; border-radius: 4px; max-width: 300px; visibility: hidden;"><b>Processing...</b></div>
	<?php endif; ?>
<?php endif; ?>

</form>

<?php if ($table_rows): ?> 
	<form method="post" action="export_csv.php">
		<input type="hidden" name="op_call" value="<?= htmlspecialchars($logged_in_call) ?>">
		<input type="hidden" name="table_data" value="<?= htmlspecialchars(json_encode($table_rows)) ?>">
		<button type="submit">⬇️ Download CSV</button>
		<button type="button" onclick="window.print()">🖨️ Print Schedule</button>
	</form>
	<form method="post" action="export_ical.php">
	    <input type="hidden" name="table_data" value="<?= htmlspecialchars(json_encode($table_rows)) ?>">
    	<button type="submit">📅 Export iCal</button>
	</form>

<?php endif; ?>

<script>
function openClubStationHours() {
    window.open(
        'display_club_station_hours.php?popup=1',
        'clubStationHours',
        'width=900,height=700,resizable=yes,scrollbars=yes'
    );
}
</script>

<script>
setTimeout(() => {
    // Hide any/all "-flash" messages after 5 seconds
    const flashElements = document.querySelectorAll('[id$="-flash"]');
    
    // Iterate over the flash elements and hide them after 5 seconds
    flashElements.forEach(flash => {
        flash.style.display = 'none';
    });
}, 5000); // Hide after 5 seconds
</script>

<script>
// dynamic behavior for checkbox lists that include an "all" choice
document.addEventListener('DOMContentLoaded', function() {
	// Apply the behavior to all groups (time_slots, days_of_week, etc.)
	document.querySelectorAll('.select-all').forEach((selectAllCheckbox) => {
		selectAllCheckbox.addEventListener('change', function() {
			const groupName = this.getAttribute('data-group');
			const checkboxes = document.querySelectorAll(`input[name="${groupName}[]"]`);

			// If the 'All' checkbox is checked, check all others
			checkboxes.forEach(cb => {
				cb.checked = this.checked;
			});
		});
	});

	// When any other checkbox is clicked, uncheck 'All'
	document.querySelectorAll('input[type="checkbox"]:not(.select-all)').forEach((checkbox) => {
		checkbox.addEventListener('change', function() {
			const groupName = this.getAttribute('name').replace('[]', ''); // e.g., time_slots or days_of_week
			const allCheckbox = document.querySelector(`input[data-group="${groupName}"].select-all`);

			// If any other checkbox is unchecked, uncheck 'All'
			if (this.checked === false) {
				allCheckbox.checked = false;
			}
		});
	});

    // Listen for the Enter key press and make it act like "Show Schedule"
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' && event.target.tagName !== 'TEXTAREA') {
                // Stop the default submit behavior
                event.preventDefault();

                // Trigger the Show Schedule button
                const showBtn = form.querySelector('input[name="show_schedule"]');
                if (showBtn) {
                    showBtn.click();
                }
            }
        });
    }
});
</script>

</body>
</html>
