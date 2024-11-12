<?php

// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/*
 * Attendance web service - external library
 *
 * @package    local_attendance_ws
 * @author     Peter Welham
 * @copyright  2017, Oxford Brookes University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

require_once($CFG->libdir . "/externallib.php");
require_once($CFG->dirroot . "/local/attendance_ws/locallib.php");
require_once($CFG->dirroot . "/mod/attendance/renderhelpers.php");
require_once($CFG->dirroot . "/mod/attendance/classes/structure.php");
require_once($CFG->dirroot . "/mod/attendance/locallib.php");
require_once($CFG->dirroot . "/course/modlib.php");
require_once($CFG->dirroot . "/group/lib.php");
require_once($CFG->dirroot . "/local/obu_metalinking/lib.php");
require_once($CFG->dirroot . "/local/obu_group_manager/lib.php");

class local_attendance_ws_external extends external_api {

	public static function add_session_parameters() {
		return new external_function_parameters(
			array(
				'idnumber' => new external_value(PARAM_TEXT, 'Course ID number'),
                'slotid' => new external_value(PARAM_TEXT, 'Slot ID number'),
                'roomid' => new external_value(PARAM_TEXT, 'Room ID number'),
				'group' => new external_value(PARAM_TEXT, 'Group'),
                'start' => new external_value(PARAM_INT, 'Session start time'),
                'duration' => new external_value(PARAM_INT, 'Session duration'),
                'semesterName' => new external_value(PARAM_TEXT, 'Semester name')
			)
		);
	}

	public static function add_session_returns() {
		return new external_single_structure(
			array(
				'result' => new external_value(PARAM_INT, 'Result')
			)
		);
	}

	public static function add_session($idnumber, $slotid, $roomid, $group, $start, $duration, $semesterName) {
		global $DB;

		self::validate_context(context_system::instance());
		$params = self::validate_parameters(
			self::add_session_parameters(), array(
				'idnumber' => $idnumber,
                'slotid' => $slotid,
                'roomid' => $roomid,
				'group' => $group,
				'start' => $start,
                'duration' => $duration,
                'semesterName' => $semesterName
			)
		);


		if (strlen($params['idnumber']) < 1 || strlen($params['slotid']) < 1 || strlen($params['roomid']) < 1) {
			return array('result' => -1);
		}

		if (!($course = $DB->get_record('course', array('idnumber' => $params['idnumber'])))) {
			return array('result' => -2);
		}

        $teachingcourse = local_obu_metalinking_get_teaching_course($course);
        if (!($attendance = local_attendance_ws_find_attendance_activity($teachingcourse))) {
            return array('result' => -3);
        }

		if (!($cm = get_coursemodule_from_instance('attendance', $attendance->id, 0, false))) {
			return array('result' => -4);
		}

        $pluginconfig = get_config('attendance');

		// Capability checking
		$context = context_module::instance($cm->id);
		require_capability('mod/attendance:manageattendances', $context);

		$session = new stdClass();
		$session->attendanceid = $attendance->id;
        $session->timetableeventid = $params['slotid'];
        $session->roomid = $params['roomid'];
		$session->sessdate = $params['start'];
		$session->duration = $params['duration'];
		$session->lasttaken = null;
		$session->lasttakenby = 0;
		$session->timemodified = time();

        $usergroup = ($params['group'] == '0' || $params['group'] == '')
            ? local_obu_group_manager_create_system_group($course, null, null, null, null, $teachingcourse)
            : local_obu_group_manager_create_system_group($course, null, null, $semesterName, $group, $teachingcourse);

        $session->groupid = $usergroup->id;

        $session->description = "Room(s): " . $params['roomid'];

 		$session->descriptionformat = 1;
		$session->statusset = 0;
        $session->calendarevent = 0;

        $salt = get_config('local_attendance_ws', 'salt');
        $session->studentpassword = local_attendance_ws_password_hash($params['slotid'], $params['roomid'], $params['start'], 6, $salt);
        $session->sessioninstancecode = local_attendance_ws_session_instance_code($params['slotid'], $params['roomid'], $params['start']);

        if (isset($pluginconfig->calendarevent_default)) {
            $session->caleventid = $pluginconfig->calendarevent_default;
        }
        if (isset($pluginconfig->studentscanmark_default)) {
            $session->studentscanmark = $pluginconfig->studentscanmark_default;
        }
        if (isset($pluginconfig->randompassword_default)) {
            $session->randompassword = $pluginconfig->randompassword_default;
        }
        if (isset($pluginconfig->includeqrcode_default)) {
            $session->includeqrcode = $pluginconfig->includeqrcode_default;
        }
        if (isset($pluginconfig->autoassignstatus)) {
            $session->autoassignstatus = $pluginconfig->autoassignstatus;
        }
        if (isset($pluginconfig->allowupdatestatus_default)) {
            $session->allowupdatestatus = $pluginconfig->allowupdatestatus_default;
        }
        if (isset($pluginconfig->rotateqrcode_default)) {
            $session->rotateqrcode = $pluginconfig->rotateqrcode_default;
        }
        if (isset($pluginconfig->automark_default)) {
            $session->automark = $pluginconfig->automark_default;
        }
        if (isset($pluginconfig->studentsearlyopentime)) {
            $session->studentsearlyopentime = $pluginconfig->studentsearlyopentime;
        }
        if (!empty($session->rotateqrcode)) {
            $session->studentpassword = local_attendance_ws_password_hash($params['slotid'], $params['roomid'], $params['start'], 6, $salt);
            $session->rotateqrcodesecret = local_attendance_ws_password_hash($params['slotid'], $params['roomid'], $params['start'], 6, $salt);
        }

		$session->id = $DB->insert_record('attendance_sessions', $session);
		attendance_create_calendar_event($session);

		// Trigger a session added event
		$event = \mod_attendance\event\session_added::create(array(
			'objectid' => $attendance->id,
			'context' => $context,
			'other' => array('info' => construct_session_full_date_time($session->sessdate, $session->duration))
		));
		$event->add_record_snapshot('course_modules', $cm);
		$event->add_record_snapshot('attendance_sessions', $session);
		$event->trigger();

		mod_attendance_notifyqueue::notify_success(get_string('sessiongenerated', 'attendance'));

		return array('result' => $session->id);
	}

	public static function update_session_parameters() {
		return new external_function_parameters(
			array(
				'sessionid' => new external_value(PARAM_INT, 'Session ID'),
				'start' => new external_value(PARAM_INT, 'Session start time'),
                'duration' => new external_value(PARAM_INT, 'Session duration'),
                'roomid' => new external_value(PARAM_TEXT, 'Room Ids')
			)
		);
	}

	public static function update_session_returns() {
		return new external_single_structure(
			array(
				'result' => new external_value(PARAM_INT, 'Result')
			)
		);
	}

	public static function update_session($sessionid, $start, $duration, $roomid) {
		global $DB;

		self::validate_context(context_system::instance());
		$params = self::validate_parameters(
			self::update_session_parameters(), array(
				'sessionid' => $sessionid,
				'start' => $start,
                'duration' => $duration,
                'roomid' => $roomid
			)
		);

		if (strlen($params['sessionid']) < 1) {
			return array('result' => -1);
		}

		if (!($session = $DB->get_record('attendance_sessions', array('id' => $params['sessionid'])))) {
			return array('result' => 0);
		}

		if (!($cm = get_coursemodule_from_instance('attendance', $session->attendanceid, 0, false))) {
			return array('result' => -2);
		}

		// Capability checking
		$context = context_module::instance($cm->id);
		require_capability('mod/attendance:manageattendances', $context);

		$session->sessdate = $params['start'];
        $session->duration = $params['duration'];
        $session->roomid = $params['roomid'];
        $session->description = "Room(s): " . $params['roomid'];
		$session->timemodified = time();
		$DB->update_record('attendance_sessions', $session);

		$event = \mod_attendance\event\session_updated::create(array(
			'objectid' => $session->attendanceid,
			'context' => $context,
			'other' => array(
				'info' => construct_session_full_date_time($session->sessdate, $session->duration),
				'sessionid' => $session->id,
				'action' => mod_attendance_sessions_page_params::ACTION_UPDATE
			)
		));
        $event->add_record_snapshot('course_modules', $cm);
        $event->add_record_snapshot('attendance_sessions', $session);
        $event->trigger();

		return array('result' => $params['sessionid']);
	}

	public static function delete_session_parameters() {
		return new external_function_parameters(
			array(
				'sessionid' => new external_value(PARAM_INT, 'Session ID')
			)
		);
	}

	public static function delete_session_returns() {
		return new external_single_structure(
			array(
				'result' => new external_value(PARAM_INT, 'Result')
			)
		);
	}

	public static function delete_session($sessionid) {
		global $DB;

		self::validate_context(context_system::instance());
		$params = self::validate_parameters(
			self::delete_session_parameters(), array(
				'sessionid' => $sessionid
			)
		);

		if (strlen($params['sessionid']) < 1) {
			return array('result' => -1);
		}

		if (!($session = $DB->get_record('attendance_sessions', array('id' => $params['sessionid'])))) {
			return array('result' => 0);
		}

		if (!($cm = get_coursemodule_from_instance('attendance', $session->attendanceid, 0, false))) {
			return array('result' => -2);
		}

		// Capability checking
		$context = context_module::instance($cm->id);
		require_capability('mod/attendance:manageattendances', $context);

		if ($session->caleventid) {
			attendance_delete_calendar_events(array($params['sessionid']));
		}

		$DB->delete_records('attendance_log', array('sessionid' => $params['sessionid']));
		$DB->delete_records('attendance_sessions', array('id' => $params['sessionid']));
		$event = \mod_attendance\event\session_deleted::create(array(
			'objectid' => $session->attendanceid,
			'context' => $context,
			'other' => array('info' => $params['sessionid'])
		));
        $event->add_record_snapshot('course_modules', $cm);
        $event->trigger();

		return array('result' => $params['sessionid']);
	}

    public static function get_settings_parameters() {
        return new external_function_parameters(
            array(
            )
        );
    }

    public static function get_settings_returns() {
        return new external_single_structure(
            array(
                'enabled' => new external_value(PARAM_BOOL, 'Enabled'),
                'modulelist' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Module List')),
            )
        );
    }

    public static function get_settings(){
        $enabled = get_config('local_attendance_ws', 'enable');
        $modulelist = get_config('local_attendance_ws', 'module_list');
        $modulesarray = array_filter(explode(",", str_replace(" ", "", $modulelist)));

        return array('enabled' => $enabled, 'modulelist' => $modulesarray);
    }
}
