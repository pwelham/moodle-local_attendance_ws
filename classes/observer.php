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
class local_attendance_observer
{

    /**
     * Enrol instance created
     *
     * @param \core\event\enrol_instance_created $event
     * @return void
     */
    public static function enrol_instance_created(\core\event\enrol_instance_created $event)
    {
        $enabled = get_config('local_attendance_ws', 'enableevents');
        if (!$enabled) {
            return;
        }

        $instance = $event->get_record_snapshot('enrol', $event->objectid);
        if (strcasecmp($instance->enrol, 'meta') != 0) {
            return;
        }

        $task = new \local_attendance_ws\task\synchronize();
        $task->set_custom_data(['courseid' => $instance->courseid]);

        \core\task\manager::queue_adhoc_task($task);
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

        global $DB;

        $instance = $event->get_record_snapshot('enrol', $event->objectid);

        if (strcasecmp($instance->enrol, 'meta') == 0) {
            $course = get_course($instance->courseid);

            // TODO :
        }
    }
}
