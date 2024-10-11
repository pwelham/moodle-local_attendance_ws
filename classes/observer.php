<?php
// This file is part of Moodle - http://moodle.org/
//
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

use local_obu_metalinking_events\event\metalinking_groups_created;
use local_obu_attendance_events\event\attendance_sessions_moved;
use local_obu_attendance_events\event\attendance_sessions_restored;

/**
 * Attendance Web Service file description here.
 *
 * @package    attendanc_ws
 * @copyright  2024 p0091841
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG, $DB;
require_once($CFG->dirroot . '/local/attendance_ws/locallib.php');

/**
 * Event observers
 *
 * @package    local_attendance_ws
 * @copyright  2024 Joe Souch
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_attendance_ws_observer
{
    /**
     * Enrol instance created
     *
     * @param metalinking_groups_created $event
     * @return void
     */
    public static function metalinking_groups_created(metalinking_groups_created $event)
    {
        $enabled = get_config('local_attendance_ws', 'enableevents');
        if (!$enabled) {
            return;
        }

        $parentid = $event->other['parentid'];
        $childid = $event->other['childid'];

        $task = new \local_attendance_ws\task\synchronize();
        $task->set_custom_data(array(
            'parentid' => $parentid,
            'childid' => $childid));

        \core\task\manager::queue_adhoc_task($task);

        $attendanceSessionsMoved = attendance_sessions_moved::create_from_metalinked_courses($childid, $parentid);
        $attendanceSessionsMoved->trigger();
    }

    /**
     * Enrol instance deleted
     *
     * @param \core\event\enrol_instance_deleted $event
     * @return void
     */
    public static function enrol_instance_deleted(\core\event\enrol_instance_deleted $event)
    {
        $enabled = get_config('local_attendance_ws', 'enableevents');
        if (!$enabled) {
            return;
        }

        $instance = $event->get_record_snapshot('enrol', $event->objectid);
        if (strcasecmp($instance->enrol, 'meta') != 0) {
            return;
        }

        $parentid = $instance->courseid;
        $childid = $instance->customint1;

        $task = new \local_attendance_ws\task\desynchronize();
        $task->set_custom_data(array(
            'parentid' => $parentid,
            'childid' => $childid));

        \core\task\manager::queue_adhoc_task($task);

        $attendanceSessionsRestored = attendance_sessions_restored::create_from_metalinked_courses($childid, $parentid);
        $attendanceSessionsRestored->trigger();
    }
}
