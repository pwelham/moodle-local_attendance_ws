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
function attendance_hash($slotid, $roomid, $eventdate, $length, $salt = ""): string
{
    $combination = $slotid . "_" .  $roomid . "_" . $eventdate . "_" . $salt;

    $hash = hash('sha256', $combination);
    $base64 = base64_encode($hash);

    $password = substr(preg_replace("/[^a-z0-9]/", "", $base64), 0 , $length);

    return str_pad($password, $length, "0");
}