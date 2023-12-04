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
require_once($CFG->dirroot . "/mod/attendance/renderhelpers.php");
require_once($CFG->dirroot . "/mod/attendance/classes/structure.php");

class local_attendance_ws_external extends external_api {

	public static function add_session_parameters() {
		return new external_function_parameters(
			array(
				'idnumber' => new external_value(PARAM_TEXT, 'Course ID number'),
				'group' => new external_value(PARAM_TEXT, 'Group'),
				'start' => new external_value(PARAM_INT, 'Session start time'),
				'duration' => new external_value(PARAM_INT, 'Session duration')
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

	public static function add_session($idnumber, $group, $start, $duration) {
		global $DB;

		// Context validation
		self::validate_context(context_system::instance());

		// Parameter validation
		$params = self::validate_parameters(
			self::add_session_parameters(), array(
				'idnumber' => $idnumber,
				'group' => $group,
				'start' => $start,
				'duration' => $duration
			)
		);

		if (strlen($params['idnumber']) < 1) {
			return array('result' => -1);
		}

		if (!($course = $DB->get_record('course', array('idnumber' => $params['idnumber'])))) {
			return array('result' => -2);
		}
		
		if (!($attendance = $DB->get_record('attendance', array('course' => $course->id, 'name' => 'Module attendance')))) {
			return array('result' => -3);
		}

		if (!($cm = get_coursemodule_from_instance('attendance', $attendance->id, 0, false))) {
			return array('result' => -4);
		}

		// Capability checking
		$context = context_module::instance($cm->id);
		require_capability('mod/attendance:manageattendances', $context);

		$session = new stdClass();
		$session->attendanceid = $attendance->id;
		$session->groupid = 0;
		$session->sessdate = $params['start'];
		$session->duration = $params['duration'];
		$session->lasttaken = null;
		$session->lasttakenby = 0;
		$session->timemodified = time();
		if ($params['group'] == 0) {
			$session->description = '';
		} else {
			$session->description = 'Group ' . $params['group'];
		}
 		$session->descriptionformat = 1;
		$session->statusset = 0;
		if (!empty(get_config('attendance', 'studentscanmark'))) { // Students will be able to mark their own attendance
			$session->studentscanmark = 1;
		} else {
			$session->studentscanmark = 0;
		}
		$session->caleventid = 0;

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
				'duration' => new external_value(PARAM_INT, 'Session duration')
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

	public static function update_session($sessionid, $start, $duration) {
		global $DB;

		// Context validation
		self::validate_context(context_system::instance());

		// Parameter validation
		$params = self::validate_parameters(
			self::update_session_parameters(), array(
				'sessionid' => $sessionid,
				'start' => $start,
				'duration' => $duration
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
		$session->timemodified = time();
		$DB->update_record('attendance_sessions', $session);

		if ($session->caleventid) {
			attendance_update_calendar_event($session->caleventid, $session->duration, $session->sessdate);
		}

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

		// Context validation
		self::validate_context(context_system::instance());

		// Parameter validation
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
                'modulelist' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Module List'))
            )
        );
    }

    public static function get_settings(){
        $enabled = get_config('local_attendance_ws', 'enable');
        $modulelist = get_config('local_attendance_ws', 'module_list');
        $modulesarray = explode(",", $modulelist);

        return array('enabled' => $enabled, 'modulelist' => $modulesarray);
    }
}
