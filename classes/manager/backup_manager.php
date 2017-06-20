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
 * Manager to create & restore backups for courses
 *
 * @package tool_cleanupcourses
 * @copyright  2017 Tobias Reischmann WWU
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace tool_cleanupcourses\manager;

defined('MOODLE_INTERNAL') || die();

// Get the necessary files to perform backup and restore.
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

class backup_manager {

    /**
     * Creates a course backup in a specific cleanup courses backup folder
     * @param int $courseid id of the course the backup should be created for.
     * @return bool tells if the backup was completed successfully.
     */
    public static function create_course_backup($courseid) {
        global $CFG, $DB;
        try {
            $course = get_course($courseid);
            $record = new \stdClass();
            $record->courseid = $courseid;
            $record->fullname = $course->fullname;
            $record->shortname = $course->shortname;
            $recordid = $DB->insert_record('tool_cleanupcourses_backups', $record, true);
            $record->id = $recordid;

            // Build filename.
            $archivefile = date("Y-m-d") . "-ID-{$recordid}-COURSE-{$courseid}.mbz";

            // Path of backup folder.
            $path = $CFG->dataroot . '/cleanupcourses_backups';
            // If the path doesn't exist, make it so!
            if (!is_dir($path)) {
                umask(0000);
                // Create the directory for Backups.
                if (!mkdir($path, $CFG->directorypermissions, true)) {
                    throw new \moodle_exception(get_string('errorbackuppath', 'tool_cleanupcourses'));
                }
            }
            // Perform Backup.
            $bc = new \backup_controller(\backup::TYPE_1COURSE, $courseid, \backup::FORMAT_MOODLE,
                \backup::INTERACTIVE_NO, \backup::MODE_GENERAL, get_admin()->id);
            $bc->execute_plan();  // Execute backup.
            $results = $bc->get_results(); // Get the file information needed.
            /* @var $file \stored_file instance of the backup file*/
            $file = $results['backup_destination'];
            if (!empty($file)) {
                $file->copy_content_to($path . '/' . $archivefile);
            }
            $bc->destroy();
            unset($bc);

            // First check if the file was created.
            if (!file_exists($path . '/' . $archivefile)) {
                return false;
            }

            $record->backupfile = $archivefile;
            $record->backupcreated = time();
            $DB->update_record('tool_cleanupcourses_backups', $record, true);

            return true;
        } catch (\moodle_exception $e) {
            debugging('There was a problem during backup!');
            debugging($e->getMessage());
            return false;
        }
    }

    /**
     * Restores a course backup via a backupid
     * The function copies the backup file from the cleanupcourse backup folder to a temporary folder.
     * It then redirects to the backup/restore.php, which leads the user through the interactive restore process.
     * @param int $backupid id of backup entry.
     * @throws \moodle_exception
     * @throws \restore_controller_exception
     */
    public static function restore_course_backup($backupid) {
        global $DB, $CFG;
        $backuprecord = $DB->get_record('tool_cleanupcourses_backups', array('id' => $backupid));

        // Check if backup tmp dir exists.
        $backuptmpdir = $CFG->tempdir . '/backup';
        if (!check_dir_exists($backuptmpdir, true, true)) {
            throw new \restore_controller_exception('cannot_create_backup_temp_dir');
        }

        // Create the file location in the backup temp.
        $targetfilename = \restore_controller::get_tempdir_name($backuprecord->courseid, get_admin()->id);
        $target = $backuptmpdir . '/' . $targetfilename;
        // Create the location of the actual backup file.
        $source = $CFG->dataroot . '/cleanupcourses_backups/' . $backuprecord->backupfile;
        // Check if the backup file exists.
        if (!file_exists($source)) {
            throw new \moodle_exception('errorbackupfiledoesnotexist', 'tool_cleanupprocess', $source);
        }

        // Copy the file to the backup temp dir.
        copy($source, $target);

        $context = \context_system::instance();
        $restoreurl = new \moodle_url('/backup/restore.php',
            array(
                'contextid' => $context->id,
                'filename' => $targetfilename,
            )
        );
        redirect($restoreurl);

    }
}