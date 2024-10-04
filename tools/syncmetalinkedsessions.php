<?php

require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->libdir.'/authlib.php');
require_once($CFG->dirroot.'/user/lib.php');
require_once("$CFG->libdir/formslib.php");

admin_externalpage_setup('attendancewstaskssync');

global $PAGE, $OUTPUT;

$PAGE->set_heading("Sync Metalinked Sessions");
echo $OUTPUT->header();

class syncmetalinkedsessions_form extends moodleform {

    public function definition() {
        $mform = $this->_form;

        $mform->addElement('date_selector', 'sessionsafter', 'Sessions after');
        $mform->addRule('sessionsafter', null, 'required', null, 'client');

        $mform->addElement('submit', 'resyncbutton', 'Submit');
    }
}

function reSyncMetalinkedSessions($sessionsafter) : int {
    global $DB;

    $sql = "SELECT DISTINCT
                c.id AS 'childid',
                parent.id AS 'parentid'
                FROM {attendance_sessions} s
                INNER JOIN {attendance} a ON s.attendanceid = a.id
                INNER JOIN {course} c ON a.course = c.id
                INNER JOIN {enrol} e ON e.customint1 = c.id
                INNER JOIN {course} parent ON e.courseid = parent.id
                INNER JOIN {course_categories} cat ON cat.id = parent.category AND cat.idnumber LIKE 'SRS%'
                WHERE e.enrol = 'meta'
                AND parent.shortname LIKE '% (%:%)'
                AND parent.idnumber LIKE '%.%'
                AND s.sessdate > ?";

    $records = $DB->get_records_sql($sql, array($sessionsafter));

    if(count($records) > 0) {
        foreach ($records as $record) {
            $task = new \local_attendance_ws\task\synchronize();
            $task->set_custom_data(['childid' => $record->childid]);
            $task->set_custom_data(['parentid' => $record->parentid]);

            \core\task\manager::queue_adhoc_task($task);
        }
    }

    return count($records);
}

$mform = new syncmetalinkedsessions_form();

if ($data = $mform->get_data()) {
    $count = reSyncMetalinkedSessions($data->sessionsafter);

    \core\notification::info($count . " Ad hoc task(s) created");

} else{
    if ($mform->is_submitted() && !$mform->is_validated()){
        \core\notification::error("Sessions after date required.");
    }
}

$mform->display();

echo $OUTPUT->footer();