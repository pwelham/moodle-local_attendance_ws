<?php

/**
 * Example URL : /local/attendance_ws/test/syncmetalinkedsessions-task.php?childid=6&parentid=3
 */
require('../../../config.php');

global $CFG;

if(!is_siteadmin()) {
    return;
}

require_once($CFG->dirroot . '/local/attendance_ws/locallib.php');

$childid = required_param('childid', PARAM_INT);
$parentid = required_param('parentid', PARAM_INT);


$trace = new html_progress_trace();
local_attendance_ws_meta_course_sync($trace,
    $parentid,
    $childid);
$trace->finished();