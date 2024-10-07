<?php

/**
 * Example URL : /local/attendance_ws/test/syncmetalinkedsessions.php?childid=6&parentid=3
 */
require('../../../config.php');

global $CFG;

$childid = required_param('childid', PARAM_INT);
$parentid = required_param('parentid', PARAM_INT);

$task = new \local_attendance_ws\task\synchronize();
    $task->set_custom_data([
        'childid' => $childid,
        'parentid' => $parentid]);

    \core\task\manager::queue_adhoc_task($task);