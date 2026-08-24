<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX

	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Portions created by the Initial Developer are Copyright (C) 2008-2025
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
	Joseph Nadiv <ynadiv@corpit.xyz>
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (permission_exists('school_bell_view')) {
		//access granted
	} else {
		echo "access denied";
		exit;
	}
	
	$uuid = uuid();

//get the domain and user UUIDs
	$domain_uuid = $_SESSION['domain_uuid'] ?? '';
	$domain_name = $_SESSION['domain_name'] ?? '';
	$conference_name = $uuid."@".$domain_name;
	$conference_profile = "page";
	$conference_bridge = $conference_name."@".$conference_profile;


//Handle POST
//authorized commands

	$action = $_REQUEST['action'] ?? null;
	if ($action == 'hangup') {

		//verify submitted call uuids
			$calls = [];
			if (is_array($_POST['calls']) && @sizeof($_POST['calls']) != 0) {
				foreach ($_POST['calls'] as $call) {
					if ($call['checked'] == 'true') {
						$calls[] = $call['conference_name'];
					}
				}
			}

		//iterate through calls
			if (count($calls) > 0) {

				//setup the event socket connection
					$event_socket = event_socket::create();

				//execute hangup command
					if ($event_socket->is_connected()) foreach ($calls as $conference_name) {
						event_socket::async("conference ".$conference_name." hup all");
					}

				//set message
					message::add('Calls Ended: '.count($calls),'positive');

			}

		//redirect
			header('Location: school_bell_broadcast.php');
			exit;

	}
	elseif ($action == 'play') {		
		$target_extensions = $_POST['target_extensions'];
		$playback_file = $_SESSION['switch']['recordings']['dir'] . '/' . $_SESSION['domain_name'] . '/' . $_POST['playback_file'];

		$event_socket = event_socket::create();
		
		//Parse out the target extensions
		if (is_array($target_extensions)) {
			foreach($target_extensions as $ext)
				$destinations[] = event_socket::command("api user_data ".$ext."@".$domain_name." attr id");
		}

		//originate the intercoms to the call
		foreach($destinations as $ext) {
			$cmd_string = event_socket::async('bgapi originate {hangup_after_bridge=false}user/'.$ext.'@'.$domain_name.' conference:'.$conference_bridge.'+flags{mute} inline');
			$destination_count++;
		}

		//Get length of playback file
		$recording_length = round(shell_exec("sox --i -D " . $playback_file));

		//send main call to the conference room
			if ($destination_count > 0) {
				$response = event_socket::command("api sched_api +2 none conference ".$conference_name." play ".$playback_file);
				//wait for recording to finish then end page/conference
				$response = event_socket::command("api sched_api +".((int) $recording_length + 4)." none conference ".$conference_name." hup all");
			}

		//Add the call to the database
			$sql = "INSERT INTO v_school_bell_active (conference_name, target_extensions, playback_file, start_epoch, domain_uuid) ";
			$sql .= "VALUES (:conference_name, :target_extensions, :playback_file, :start_epoch, :domain_uuid)";
			$params['conference_name'] = $uuid;
			$params['target_extensions'] = implode(',', $target_extensions);
			$params['playback_file'] = $playback_file;
			$params['start_epoch'] = time();
			$params['domain_uuid'] = $domain_uuid;
			$database->execute($sql, $params);

	}
	unset($sql, $params);

	if ($action != null) {
		header("Location: school_bell_broadcast.php");
		return;
	}

//Currently playing array
    $now_playing = [];

//get database broadcasts
    $sql = "SELECT * FROM v_school_bell_active ";
    $sql .= "WHERE domain_uuid = :domain_uuid ";
    $params['domain_uuid'] = $domain_uuid;
    $rows = $database->select($sql, $params, 'all');
	unset($sql, $params);

//Check if database broadcasts are still playing
    $esl = event_socket::create();
	if ($esl->is_connected()) {
		foreach($rows as $row){
            $cmd = "api conference ".$row['conference_name']."@".$domain_name." list count";
		    $response = event_socket::command($cmd);
            if (substr($response, 0, 1) == '-') {
                $sql = "DELETE FROM v_school_bell_active WHERE conference_name = :conference_name";
                $params['conference_name'] = $row['conference_name'];
                $database->select($sql, $params);
				unset($sql, $params);
            } else {
                $now_playing[] = $row;
            }		
        }
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//includes and title
	$document['title'] = "School Bell Broadcast";
	require_once "resources/header.php";

//initialize the destinations object
	$destination = new destinations;
	
//get the extensions
	$sql = "select extension, effective_caller_id_name from v_extensions ";
	$sql .= "where domain_uuid = :domain_uuid ";
	$sql .= "order by extension asc ";
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	$database = new database;
	$extensions = $database->select($sql, $parameters, 'all');
	unset($sql, $parameters);

	//get the recordings
	$sql = "select recording_name, recording_filename from v_recordings ";
	$sql .= "where domain_uuid = :domain_uuid ";
	$sql .= "order by recording_name asc ";
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	$database = new database;
	$recordings = $database->select($sql, $parameters, 'all');
	unset($sql, $parameters);

    echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>School Bell Broadcast</b><div class='count'>".number_format(count($now_playing))."</div></div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

//Display the currently playing as table with stop button at end of row
	echo "<div id='school_bells_now_playing'></div>\n";

	echo "<form id='form_list' method='post' action=''>\n";
	echo "<input type='hidden' id='action' name='action' value=''>\n";

	echo "<div class='card'>\n";
	echo "	<table id='tbl_school_bells_now_playing' class='list'>\n";
	echo "	<tr class='list-header'>\n";
	
	echo "		<th class='checkbox'>\n";
	echo "			<input type='checkbox' id='checkbox_all' name='checkbox_all' onclick='if (this.checked) { refresh_stop(); } else { refresh_start(); } list_all_toggle();' ".(empty($rows) ? "style='visibility: hidden;'" : null).">\n";
	echo "		</th>\n";
	
	echo "		<th class='hide-small'>Call Name</th>\n";
	echo "		<th>Elapsed Time</th>\n";
	echo "		<th>Extenstions</th>\n";
	echo "		<th>Playing</th>\n";

	echo "		<th>&nbsp;</th>\n"; //Hangup button

	echo "	</tr>\n";

	if (is_array($now_playing)) {
		$x = 0;
		foreach ($now_playing as $row) {

			//set the php variables
				foreach ($row as $key => $value) {
					$$key = $value;
				}

			//calculate elapsed seconds
				$elapsed_seconds = time() - $start_epoch;

			//convert seconds to hours, minutes, and seconds
				$hours = floor($elapsed_seconds / 3600);
				$minutes = floor(($elapsed_seconds % 3600) / 60);
				$seconds = $elapsed_seconds % 60;

			//format the elapsed time as HH:MM:SS
				$elapsed_time = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);

			//reduce too long app data
				$target_extensions = implode(',', $target_extensions);
				if(strlen($target_extensions) > 80) {
					$target_extensions = substr($target_extensions, 0, 80) . '...';
				}

			//send the html
				echo "	<tr class='list-row'>\n";
				echo "		<td class='checkbox'>\n";
				echo "			<input type='checkbox' name='calls[$x][checked]' id='checkbox_".$x."' value='true' onclick=\"if (this.checked) { refresh_stop(); } else { document.getElementById('checkbox_all').checked = false; }\">\n";
				echo "			<input type='hidden' name='calls[$x][conference_name]' value='".escape($conference_name)."' />\n";
				echo "		</td>\n";
				echo "		<td>".escape($conference_name)."</td>\n";
				echo "		<td>".escape($elapsed_time)."</td>\n";
				echo "		<td>".escape($target_extensions)."</td>\n";
				echo "		<td>".escape($playback_file)."</td>\n";
				echo "		<td class='button right' style='padding-right: 0;'>\n";
				//hangup
				echo button::create(['type'=>'button','label'=>'Hangup','icon'=>'phone-slash','collapse'=>'hide-lg-dn','onclick'=>"if (confirm('Are you sure?')) { list_self_check('checkbox_".$x."'); list_action_set('hangup'); list_form_submit('form_list'); } else { this.blur(); return false; }",'onmouseover'=>'refresh_stop()','onmouseout'=>'refresh_start()']);
				echo "	</td>\n";
				echo "	</tr>\n";

			//increment counter
				$x++;
		}
	}

	echo "	</table>\n";
	echo "</div>\n";
	echo "</form>\n";


//Last row has a multiselect with the extensions, a recordings select, and a Play button
	echo "<div id='school_bells_play'></div>\n";

	echo "<form id='form_list' method='post' action=''>\n";
	echo "<input type='hidden' id='action' name='action' value='play'>\n";
	echo "	<table id='tbl_school_bells_play' class='list'>\n";
	echo "	<tr class='list-header'>\n";
	echo "		<th>Extensions</th>\n";
	echo "		<th>Play Stream</th>\n";
	echo "		<th>&nbsp;</th>\n"; //button
	echo "	</tr>\n";


	echo "<tr>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "<select name='target_extensions[]' id='target_extensions[]' class='formfld' multiple>\n";
	echo "	<option></option>\n";
		//extensions
		if (is_array($extensions)) {
			echo "<optgroup label='Extensions'>\n";
			foreach ($extensions as $row) {
				$extension_number = $row["extension"];
				$extension_name = $row["effective_caller_id_name"];
				echo "	<option value='".escape($extension_number)."'>".escape($extension_number)."-".escape($extension_name)."</option>\n";
			}
			echo "</optgroup>\n";
		}
	echo "	</select>\n";
	echo "</td>\n";

	echo "<td class='vtable' style='position: relative;' align='left'>\n";
	echo "<select name='playback_file' id='playback_file' class='formfld'>\n";
	echo "	<option></option>\n";
		//recordings
		if (is_array($recordings)) {
			echo "<optgroup label='Recordings'>\n";
			foreach ($recordings as $row) {
				$recording_name = $row["recording_name"];
				$recording_filename = $row["recording_filename"];
				echo "	<option value='".escape($recording_filename)."'>".escape($recording_name)."</option>\n";
			}
			echo "</optgroup>\n";
		}
	echo "	</select>\n";
	echo "</td>\n";

	echo "		<td class='button right' style='padding-right: 0;'>\n";
				//Play Button
	echo button::create(['type'=>'button','title'=>'Play','icon'=>$settings->get('theme', 'button_icon_play'),'type'=>'submit']);
	echo "	</td>\n";

	echo "	</tr>\n";
	echo "	</table>\n";
	echo "</div>\n";
	echo "<input type='hidden' name='action' value='play'>";
	echo "</form>\n";

	


//include the footer
	require_once "resources/footer.php";
/* 
TODO: Do we want to break out during a call?
Multi Select Extensions
*/

?>
