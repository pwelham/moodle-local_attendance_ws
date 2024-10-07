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
 * @author     Emir Kamel
 * @copyright  2024, Oxford Brookes University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/obu_metalinking/lib.php');
require_once($CFG->dirroot . '/local/obu_group_manager/lib.php');
require_once($CFG->dirroot . "/course/modlib.php");

function local_attendance_ws_password_hash($slotid, $roomid, $eventdate, $length, $salt = ""): string
{
    $combination = $slotid . "_" .  $roomid . "_" . $eventdate . "_" . $salt;

    $hash = hash('sha256', $combination);
    $base64 = base64_encode($hash);

    $password = substr(preg_replace("/[^a-z0-9]/", "", $base64), 0 , $length);

    return str_pad($password, $length, "0");
}

function local_attendance_ws_session_instance_code($slotid, $roomid, $eventdate): string
{
    $combination = $slotid . "_" .  $roomid . "_" . $eventdate;
    $base64 = base64_encode($combination);

    $encode = substr(preg_replace("/[^a-z0-9]/", "", $base64), 0 , 62);

    return $encode;
}

function local_attendance_ws_find_attendance_activity($course, $create=true) {
    global $DB;

    if ($create && !$DB->get_record('attendance', array('course' => $course->id, 'name' => 'Module Attendance'))) {

        list($module, $courseContext) = can_add_moduleinfo($course, 'attendance', 1);

        require_capability('mod/attendance:addinstance', $courseContext);

        // Populate modinfo object.
        $moduleinfo = new stdClass();
        $moduleinfo->modulename = 'attendance';
        $moduleinfo->module = $module->id;
        $moduleinfo->name = 'Module Attendance';

        if($defaultIntro = get_config('local_attendance_ws', 'activity_intro')) {
            $moduleinfo->intro = $defaultIntro;
            $moduleinfo->showdescription = 1;
        }
        else {
            $moduleinfo->intro = '';
            $moduleinfo->showdescription = 0;
        }
        $moduleinfo->introformat = FORMAT_HTML;

        $moduleinfo->section = 1;
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;
        $moduleinfo->cmidnumber = '';
        $moduleinfo->groupmode = VISIBLEGROUPS;
        $moduleinfo->groupingid = 0;

        $moduleinfo->grade = 0;
        $moduleinfo->grade_rescalegrades = null;
        $moduleinfo->gradepass = null;
        $moduleinfo->override_grade = 0;


        // Add the module to the course.
        add_moduleinfo($moduleinfo, $course);
    }

    return $DB->get_record('attendance', array('course' => $course->id, 'name' => 'Module Attendance'));
}

function local_attendance_ws_meta_course_sync($trace, $parentid, $childid) {
    global $DB;

    $childcourse = $DB->get_record('course', array('id' => $childid));
    if (!($childactivity = local_attendance_ws_find_attendance_activity($childcourse, false))) {
        $trace->output("No child attendance activity to migrate");
        return;
    }

    $parentcourse = $DB->get_record('course', array('id' => $parentid));
    $parentactivity = local_attendance_ws_find_attendance_activity($parentcourse);

    local_attendance_ws_change_session_course($trace, $childactivity, $parentactivity);

}

function local_attendance_ws_meta_course_return($trace, $parentid, $childid) {
    global $DB;

    $parentcourse = $DB->get_record('course', array('id' => $parentid));
    if (!($parentactivity = local_attendance_ws_find_attendance_activity($parentcourse, false))) {
        $trace->output("No parent attendance activity to migrate");
        return;
    }

    $childcourse = $DB->get_record('course', array('id' => $childid));
    $childactivity = local_attendance_ws_find_attendance_activity($childcourse);

    local_attendance_ws_change_session_course($trace, $parentactivity, $childactivity);
}

function local_attendance_ws_change_session_course($trace, $fromactivity, $toactivity) {
    global $DB;

    $sql = "
        UPDATE {attendance_sessions} s
        INNER JOIN {groups} g ON s.groupid = g.id
        SET s.attendanceid = ?
        WHERE s.attendanceid = ? AND " . $DB->sql_like('g.idnumber','?');

    $fromcourse = get_course($fromactivity->course);
    $groupidnumber = local_obu_group_manager_get_idnumber_prefix($fromcourse->idnumber) . "%";

    $DB->execute($sql, array((int)$toactivity->id,(int)$fromactivity->id, $groupidnumber));
}