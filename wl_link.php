<?php
    $config = new stdClass();

    // Notification service to use for admin messages  'discord'|'pushover'
    $config->notification_type = '';

    // Discord webhook url
    // Edit Channel -> Integrations -> Webhooks
    $config->discord_admin_url = '<Discord channel webhook URL>';

    //pushover api tokens
    $config->pushover_token = '<Pushover API token>';
    $config->pushover_user = '<Pushover user key>';


###########################################
###########################################
###########################################

    // Brick\Geo GIS geometry library
    // https://github.com/brick/geo
    require_once('external_lib/autoload.php');
    use Brick\Geo\Engine\PdoEngine;
    use Brick\Geo\Engine\GeosEngine;
    use Brick\Geo\Point;
    use Brick\Geo\Io\GeoJsonReader;

    function wl_bridge_opcheck($key, $op_call, $station_call) {
        $message = '';
        // check if operator is a member of the event call
        if (!wl_check_membership($key, $op_call)) {
            //not in event call memberlist, alert wavelog admin
            wl_admin_alert("Operator $op_call not a member of Event Station $station_call");
            $message = "Operator not found in logger for station $station_call. Admin notified. ";
        }
        return array('status' => 'success', 'info' => $message);
    }

    function wl_bridge_gridcheck($key, $operator_call, $station_call, $clubstation_grid, $club_station, $notes) {
        $callsign_data = FALSE;
        $message = '';
        // check for station location matching operator's intended operating grid square
        $grid = $clubstation_grid;  //if clubstation_grid is provided, OP will be not at 'home'
            
        if ($grid == '') {  // no clubstation_grid, check if alt grid is in notes
            preg_match('/([A-R]{2}[0-9]{2}[A-X]{2})/i', $notes, $matches);
            if (!empty($matches)) { // found a grid in the notes
                $grid = $matches[0];                }
        }
        if($grid == '') { //no clubstation_grid or grid in notes, check callsign cache
            $dinfo = get_event_connection_info_from_master(EVENT_NAME);
            $db = connect_to_event_db($dinfo);
            $ret = $db->query("SELECT * FROM `gridcache` WHERE `callsign`='".strtoupper($operator_call)."' LIMIT 1");
            if ($ret->num_rows > 0) {
                $row = $ret->fetch_assoc();
                $grid = $row['grid'];
            }
            $dbopen = true;
        }
        if($grid == '') {  // still no grid, try looking up the OP callsign for a grid
            $callsign_data = wl_lookup_callsign($key, $operator_call);
            if ($callsign_data !== FALSE) {
                $grid = $callsign_data['grid'] ?? $callsign_data['callbook']['grid'];
                // add to cache
                log_msg(DEBUG_VERBOSE, "adding $grid to cache for $operator_call");
                $db->query("INSERT INTO `gridcache` (`callsign`,`grid`) VALUES ('".strtoupper($operator_call)."','$grid')");
            }
        }
        if(!empty($dbopen)) $db->close();

        if ($grid == '') { // not going to happen. no sense continuing. return.
            wl_admin_alert("$operator_call has no grid info.\nNotes: $notes\n");
            $message .= "Unable to determine operating gridsquare. Admin notified. ";
            return json_encode(['status' => 'fail', 'info' => $message]);
        }
        $grid = strtoupper($grid);

        $stations = wl_get_locations($key);
            
        $stationmatch = FALSE;
        if (!empty($stations)) {
            // check if existing stations matches grid
            foreach($stations as $loc) {
                if (strtoupper($loc['station_gridsquare']) == $grid) { $stationmatch = TRUE; break; }
            }
        }
        if ($stationmatch === FALSE) {   // no matching station found, make a new one
            log_msg(DEBUG_VERBOSE, "creating station for grid $grid");
            $station_call = strtoupper($station_call);
            wl_admin_alert("No location found\n operator: $operator_call\n event call: $station_call\n grid:$grid\n Creating new location");
            $stnname = substr($grid,0,-2) . strtolower(substr($grid,-2));
            if (!empty($club_station)) {
                $stnname = $club_station . '-' . $stnname;
            }
            if(empty($callsign_data)) $callsign_data = wl_lookup_callsign($key, $operator_call);
            wl_station_create($key, $station_call, $callsign_data, $grid, $stnname);
        }
        return array('status' => 'success', 'info' => $message);
    }


    /* ==============================
    *  wl_lookup_callsign
    * 
    *  get callbook info via wavelog for given callsign
    *
    *  @param (string) $callsign -- callsign to look up
    *  @param (string) $key -- wavelog api key
    *
    * =============================== */
    function wl_lookup_callsign($key, $callsign) {
        global $config;
        $payload = json_encode([
            'key' => $key,
            'callsign' => $callsign,
            'callbook' => 'true'
        ]);
        $ch = curl_init(WL_API_URL . '/api/private_lookup');
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 7);
        $response = curl_exec($ch);
        if ($response !== FALSE) {
            return json_decode($response, TRUE);
        } else {
            return FALSE;
        }
    }


    /* ==============================
    *  wl_get_locations
    * 
    *  get station logbook locations
    *  station call is defined by wavelog api key
    *
    *  @param (string) $key -- wavelog api key
    *
    * =============================== */
    function wl_get_locations($key) {
        global $config;
        $ch = curl_init(WL_API_URL . '/api/station_info/' . $key);
        curl_setopt($ch, CURLOPT_HTTPGET, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        if (!empty($response)) {
            return json_decode($response, true);
        } else {
            return FALSE;
        }
    }


    /* ==============================
    *  wl_check_membership
    * 
    *  check if callsign is member of clubstation
    *  clubstation is defined by wavelog api key
    *
    *  @param (string) $callsign -- callsign to check
    *  @param (string) $key -- wavelog api key
    *
    * =============================== */
    function wl_check_membership($key, $callsign) {
        global $config;
        $ch = curl_init(WL_API_URL . '/api/list_clubmembers');
        $payload = json_encode([
            'key' => $key
        ]);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        if ($response !== FALSE) {
            $data = json_decode($response, TRUE);
            if ($data['status'] == 'successful') {
                foreach($data['members'] as $member) {
                    if (strtoupper($member['callsign']) == strtoupper($callsign)) {
                        return TRUE;
                    }
                }
            }
        }
        return FALSE;
    }


    /* ==============================
    *  wl_admin_alert
    * 
    *  wrapper function to send alert to admin team
    *
    *  @param (string) $msg -- message to send
    *
    * =============================== */
    function wl_admin_alert($msg) {
        global $config;
        switch ($config->notification_type) {
            case 'discord':
                wl_msg_discord($msg);
                break;
            case 'pushover':
                wl_msg_pushover($msg);
        return;
        }
    }


    /* ==============================
    *  wl_msg_pushover
    * 
    *  send message via pushover
    *  https://pushover.net/
    *
    *  @param (string) $msg -- message to send
    *
    * =============================== */
    function wl_msg_pushover($msg) {
        global $config;
        $payload = json_encode([
            'token' => $config->pushover_token,
            'user' => $config->pushover_user,
            'message' => $msg
        ]);
        $ch = curl_init("https://api.pushover.net/1/messages.json");
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SAFE_UPLOAD, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_exec($ch);
    }


    /* ==============================
    *  wl_msg_discord
    * 
    *  send message to discord channel
    *
    *  @param (string) $msg -- message to send
    *
    * =============================== */
    function wl_msg_discord($msg) {
        global $config;
        $payload = json_encode([
            'content' => $msg,
            'username' => 'Scheduler Notifications'
        ]);
        $ch = curl_init($config->discord_admin_url);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_exec($ch);
    }


    /* ==============================
    *  wl_get_zone
    * 
    *  get CQ/ITU zone for center of given gridsquare
    *
    *  @param (string) $grid -- gridsquare
    *  @param (string) $type itu|cq -- zone type to lookup
    *
    * =============================== */
    function wl_get_zone($grid, $type) {
        global $config;
        if(extension_loaded('geos')) {
            // use php-geos module if available
            $geometryEngine = new GeosEngine();
        } else {
            // fallback to DB engine
            $info = get_event_connection_info_from_master(EVENT_NAME);
            $pdo = new PDO("mysql:host=" . $info['host'] . ";dbname=" . $info['name'], $info['user'], $info['pass']);
            $geometryEngine = new PdoEngine($pdo);
        }

        list($lat,$lon) = qra2latlong($grid);
        $geogrid = Point::xy($lon, $lat);

        $z = wl_load_itucq_data($type);
        foreach ($z as $zone => $geo) {
            if ($geometryEngine->within($geogrid, $geo) === TRUE) return $zone;
        }
        return '';
    }


    /* ==============================
    *  wl_load_itucq_data
    * 
    *  loads ITU/CQ zone data from geojson
    *  geojson files from wavelog -- https://github.com/wavelog/wavelog
    *
    *  @param (string) $t itu|cq -- zone type to load
    *
    * =============================== */
    function wl_load_itucq_data($t) {
        $reader = new GeoJsonReader();
        $geojson_data = json_decode(file_get_contents("json/".$t."zones.geojson"), TRUE);
        if ($geojson_data && $geojson_data['type'] === 'FeatureCollection' && isset($geojson_data['features'])) {
            $a = $t . '_zone_number';
            foreach ($geojson_data['features'] as $feature) {
                $zone[$feature['properties'][$a]] = $reader->read(json_encode($feature['geometry']));
            }
            return $zone;
        } else {
            return false;
        }
    }


    /* ==============================
    *  wl_station_create
    * 
    *  creates a new station location in wavelog
    *
    *  @param (string) $data -- 'payload' from client
    *  @param (string) $callsign_data -- callbook data for callsign
    *  @param (string) $grid -- derived gridsquare
    *  @param (string) $stnname -- name for new station location
    *
    * =============================== */
    function wl_station_create($key, $station_call, $callsign_data, $grid, $stnname) {
        global $config;
        // check for empty callsign data
        if (empty($callsign_data['callbook'])) {
            $r = wl_get_census($grid);
            if($r !== FALSE) {
                $callsign_data['callbook'] = $r;
            } else {
                $callsign_data['callbook']['state'] = '';
                $callsign_data['callbook']['us_county'] = '';
                $callsign_data['callbook']['city'] = '';
            }
            $callsign_data['callbook']['cqzone'] = wl_get_zone($grid, 'cq');
            $callsign_data['callbook']['ituzone'] = wl_get_zone($grid, 'itu');
        }
        if (empty($callsign_data['dxcc_id'])) {
            switch ($callsign_data['callbook']['state']) {
                case 'HI':
                    $callsign_data['dxcc_id'] = 110;
                    break;
                case 'AK':
                    $callsign_data['dxcc_id'] = 6;
                    break;
                default:
                    $callsign_data['dxcc_id'] = 291;  //default USA
            }
        }

        $payload = json_encode([
			'station_profile_name'  => $stnname,
			'station_gridsquare'    => $grid,
			'station_city'          => $callsign_data['callbook']['city'],
			'station_callsign'      => $station_call,
			'station_power'         => 100,
			'station_dxcc'          => $callsign_data['dxcc_id'],
			'station_cq'            => $callsign_data['callbook']['cqzone'],
			'station_itu'           => $callsign_data['callbook']['ituzone'],
			'state'                 => $callsign_data['callbook']['state'],
			'station_cnty'          => $callsign_data['callbook']['us_county'],
            'qrzrealtime'           => "-1",
            'hrdlogrealtime'        => "-1",
            'link_active_logbook'   => 1
        ]);
        $ch = curl_init(WL_API_URL . '/api/create_station/' . $key);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        curl_exec($ch);
    }


    /* ==============================
    *  wl_get_census
    * 
    *  gets state, county, and city names from US Census Bureau Geocoder
    *  https://geocoding.geo.census.gov/geocoder/
    * 
    *  @param (string) $grid -- gridsquare to lookup
    *
    * =============================== */
    function wl_get_census($grid) {
        list($lat,$lon) = qra2latlong($grid);
        $ch = curl_init('https://geocoding.geo.census.gov/geocoder/geographies/coordinates?benchmark=4&vintage=4&layers=80,82,28&format=json&x='.round($lon,4).'&y='.round($lat,4));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp =  json_decode(curl_exec($ch), TRUE);
        if (!empty($resp)) {
            $r['us_county'] = empty($resp['result']['geographies']['Counties'][0]['BASENAME']) ? '' : $resp['result']['geographies']['Counties'][0]['BASENAME'];
            $r['state'] = empty($resp['result']['geographies']['States'][0]['STUSAB']) ? '' : $resp['result']['geographies']['States'][0]['STUSAB'];
            $r['city'] = empty($resp['result']['geographies']['Incorporated Places'][0]['BASENAME']) ? '': $resp['result']['geographies']['Incorporated Places'][0]['BASENAME'];
        } else {
            $r = array('us_county'=>'', 'state'=>'', 'city'=>'');
        }
        return $r;
    }


    /* qra2latlong function from WaveLog -- https://github.com/wavelog/wavelog
    *  MIT License
    *
    *  Copyright (c) 2019 Peter Goodhall
    *  Copyright (c) 2024 by DF2ET, DJ7NT, HB9HIL, LA8AJA
    *  
    *  Permission is hereby granted, free of charge, to any person obtaining a copy
    *  of this software and associated documentation files (the "Software"), to deal
    *  in the Software without restriction, including without limitation the rights
    *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
    *  copies of the Software, and to permit persons to whom the Software is
    *  furnished to do so, subject to the following conditions:
    *
    *  The above copyright notice and this permission notice shall be included in all
    *  copies or substantial portions of the Software.
    *
    *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
    *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
    *  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
    *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
    *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
    *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
    *  SOFTWARE.
    */
    function qra2latlong($strQRA) {
    	$strQRA = preg_replace('/\s+/', '', $strQRA);
    	if (substr_count($strQRA, ',') > 0) {
		    if (substr_count($strQRA, ',') == 3) {
    			// Handle grid corners
			    $grids = explode(',', $strQRA);
			    $gridlengths = array(strlen($grids[0]), strlen($grids[1]), strlen($grids[2]), strlen($grids[3]));
			    $same = array_count_values($gridlengths);
			    if (count($same) != 1) {
    				return false;
			    }
			    $coords = array(0, 0);
			    for ($i = 0; $i < 4; $i++) {
    				$cornercoords[$i] = qra2latlong($grids[$i]);
				    $coords[0] += $cornercoords[$i][0];
				    $coords[1] += $cornercoords[$i][1];
			    }
			    return array(round($coords[0] / 4), round($coords[1] / 4));
		    } else if (substr_count($strQRA, ',') == 1) {
			    // Handle grid lines
			    $grids = explode(',', $strQRA);
			    if (strlen($grids[0]) != strlen($grids[1])) {
    				return false;
			    }
			    $coords = array(0, 0);
			    for ($i = 0; $i < 2; $i++) {
    				$linecoords[$i] = qra2latlong($grids[$i]);
			    }
			    if ($linecoords[0][0] != $linecoords[1][0]) {
    				$coords[0] = round((($linecoords[0][0] + $linecoords[1][0]) / 2), 1);
			    } else {
    				$coords[0] = round($linecoords[0][0], 1);
			    }
			    if ($linecoords[0][1] != $linecoords[1][1]) {
    				$coords[1] = round(($linecoords[0][1] + $linecoords[1][1]) / 2);
			    } else {
    				$coords[1] = round($linecoords[0][1]);
			    }
			    return $coords;
		    } else {
    			return false;
		    }
	    }

	    if ((strlen($strQRA) % 2 == 0) && (strlen($strQRA) <= 10)) {	// Check if QRA is EVEN (the % 2 does that) and smaller/equal 8
    		$strQRA = strtoupper($strQRA);
		    if (strlen($strQRA) == 2)  $strQRA .= "55";	// Only 2 Chars? Fill with center "55"
		    if (strlen($strQRA) == 4)  $strQRA .= "LL";	// Only 4 Chars? Fill with center "LL" as only A-R allowed
		    if (strlen($strQRA) == 6)  $strQRA .= "55";	// Only 6 Chars? Fill with center "55"
		    if (strlen($strQRA) == 8)  $strQRA .= "LL";	// Only 8 Chars? Fill with center "LL" as only A-R allowed

		    if (!preg_match('/^[A-R]{2}[0-9]{2}[A-X]{2}[0-9]{2}[A-X]{2}$/', $strQRA)) {
    			return false;
		    }

		    list($a, $b, $c, $d, $e, $f, $g, $h, $i, $j) = str_split($strQRA, 1);	// Maidenhead is always alternating. e.g. "AA00AA00AA00" - doesn't matter how deep. 2 chars, 2 numbers, etc.
		    $a = ord($a) - ord('A');
		    $b = ord($b) - ord('A');
		    $c = ord($c) - ord('0');
		    $d = ord($d) - ord('0');
		    $e = ord($e) - ord('A');
		    $f = ord($f) - ord('A');
		    $g = ord($g) - ord('0');
		    $h = ord($h) - ord('0');
		    $i = ord($i) - ord('A');
		    $j = ord($j) - ord('A');

		    $nLong = ($a * 20) + ($c * 2) + ($e / 12) + ($g / 120) + ($i / 2880) - 180;
		    $nLat = ($b * 10) + $d + ($f / 24) + ($h / 240) + ($j / 5760) - 90;

		    $arLatLong = array($nLat, $nLong);
		    return $arLatLong;
	    } else {
    		return array(0, 0);
    	}
    }
    /* End of qra2latlong */