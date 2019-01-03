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
 * Mobile web service function tests.
 *
 * @package mod_forumng
 * @copyright 2018 The Open University
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tests\mod_forumng;

use \mod_forumng\output\mobile;
use \mod_forumng\local\external\more_discussions;
use \mod_forumng\local\external\more_posts;
use \mod_forumng\local\external\add_discussion;

defined('MOODLE_INTERNAL') || die();

/**
 * Mobile web service function tests.
 */
class mobile_testcase extends \advanced_testcase {

    /**
     * Test the basic functionality of the forum view page.
     */
    public function test_mobile_forumng_view_basic() {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        $forumnggenerator = $generator->get_plugin_generator('mod_forumng');
        $forum = $forumnggenerator->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('forumng', $forum->id);

        // Request initial page.
        $args = [
            'cmid' => $cm->id,
            'courseid' => $course->id,
            'userid' => $student->id
        ];
        $result = mobile::forumng_view($args);

        // Basic forum with no discussions.
        $this->assertEquals([], $result['files']);
        $otherdata = $result['otherdata'];
        $this->assertEquals(-1, $otherdata['defaultgroup']);
        $this->assertEquals(1, count($otherdata['discussions']));
        $this->assertEquals(0, $otherdata['totaldiscussions']);
        $this->assertEquals(1, $otherdata['page']);
        $template = $result['templates'][0];
        $this->assertEquals('main', $template['id']);
        $this->assertContains('Test forum 1', $template['html']);

        // Add a discussion.
        $record = [];
        $record['course'] = $course->id;
        $record['forum'] = $forum->id;
        $record['userid'] = $student->id;
        $forumnggenerator->create_discussion($record);
        $result = mobile::forumng_view($args);
        $otherdata = $result['otherdata'];
        $this->assertEquals(-1, $otherdata['defaultgroup']);
        $this->assertEquals(1, count($otherdata['discussions']));
        $this->assertEquals(1, $otherdata['totaldiscussions']);
        $this->assertEquals(1, $otherdata['page']);
    }

    /**
     * Test the more_discussions webservice functionality.
     */
    public function test_mobile_forumng_more_discussions() {
        global $CFG;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        $forumnggenerator = $generator->get_plugin_generator('mod_forumng');
        $forum = $forumnggenerator->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('forumng', $forum->id);
        // Set the discussions per page to a low number as we want page 2 results.
        $CFG->forumng_discussionsperpage = 2;

        // Add discussions.
        $record = [];
        $record['course'] = $course->id;
        $record['forum'] = $forum->id;
        $record['userid'] = $student->id;
        $forumnggenerator->create_discussion($record);
        $forumnggenerator->create_discussion($record);
        $forumnggenerator->create_discussion($record);

        // Get the second page of discussions from the webservice function.
        $pageno = 2;
        $result = more_discussions::more_discussions($cm->id, \mod_forumng::NO_GROUPS, $pageno);
        // The second page only has one discussion (the oldest) so has subject no of 1.
        $this->assertCount(1, $result);
        $this->assertEquals('Subject for discussion 1', $result[0]->subject);
    }

    /**
     * Test the more_posts webservice functionality.
     */
    public function test_mobile_forumng_more_posts() {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        $forumnggenerator = $generator->get_plugin_generator('mod_forumng');
        $forum = $forumnggenerator->create_instance(['course' => $course->id]);

        // Add discussion and posts
        $record = [];
        $record['course'] = $course->id;
        $record['forum'] = $forum->id;
        $record['userid'] = $student->id;
        list($discussionid, $postid) = $forumnggenerator->create_discussion($record);
        $record = [];
        $record['discussionid'] = $discussionid;
        $record['userid'] = $student->id;
        $record['parentpostid'] = $postid;
        $record['subject'] = '';
        // We need 6 posts as the mobile::NUMBER_POSTS is set to 5.
        $post1 = $forumnggenerator->create_post($record);
        $post2 = $forumnggenerator->create_post($record);
        $post3 = $forumnggenerator->create_post($record);
        $post4 = $forumnggenerator->create_post($record);
        $post5 = $forumnggenerator->create_post($record);
        $post6 = $forumnggenerator->create_post($record);

        // Get the second chunk of posts from the webservice function.
        // It would be possible to set from to 0 and get mobile::NUMBER_POSTS returned,
        // but this more accurately reflects what happens as a user scrolls down
        // the page to get more posts.
        $from = 5;
        $result = more_posts::more_posts($discussionid, $from);
        $this->assertCount(1, $result);
        $this->assertEquals('Forum message post 6', $result[0]->message);

        // Check the system copes with a from that is incorrect and too large a number.
        $from = 9;
        $result = more_posts::more_posts($discussionid, $from);
        $this->assertCount(0, $result);
    }

    /**
     * Test the add_discussion webservice functionality.
     */
    public function test_mobile_forumng_add_discussion() {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        $forumnggenerator = $generator->get_plugin_generator('mod_forumng');
        $forum = $forumnggenerator->create_instance(['course' => $course->id]);
        $discussion = 0;
        $group = -1;
        $subject = 'Test subject';
        $message = 'Test message';
        $filerecord = ['filename' => 'basepic.jpg'];
        $file = self::create_draft_file($filerecord);
        $draftarea = $file->get_itemid();

        // Add a new discussion via the WS.
        $result = add_discussion::add_discussion($forum->id, $discussion, $group, $subject, $message, $draftarea);
        $this->assertTrue($result['success']);
        $this->assertEmpty($result['errormsg']);
        $discussionid = $result['discussion'];
        // Check the new discussion exists.
        $discussion = \mod_forumng_discussion::get_from_id($discussionid, 0);
        $this->assertEquals($subject, $discussion->get_subject());
        // Check the attachment.
        $attachmentnames = $discussion->get_root_post()->get_attachment_names();
        $this->assertEquals('basepic.jpg', $attachmentnames[0]);
    }

    /**
     * Helpter function to create draft files
     *
     * @param  array  $filedata data for the file record (to not use defaults)
     * @return stored_file the stored file instance
     */
    public static function create_draft_file($filedata = array()) {
        global $USER;

        $fs = get_file_storage();

        $filerecord = array(
                'component' => 'user',
                'filearea'  => 'draft',
                'itemid'    => isset($filedata['itemid']) ? $filedata['itemid'] : file_get_unused_draft_itemid(),
                'author'    => isset($filedata['author']) ? $filedata['author'] : fullname($USER),
                'filepath'  => isset($filedata['filepath']) ? $filedata['filepath'] : '/',
                'filename'  => isset($filedata['filename']) ? $filedata['filename'] : 'file.txt',
        );

        if (isset($filedata['contextid'])) {
            $filerecord['contextid'] = $filedata['contextid'];
        } else {
            $usercontext = \context_user::instance($USER->id);
            $filerecord['contextid'] = $usercontext->id;
        }
        $source = isset($filedata['source']) ? $filedata['source'] : serialize((object)array('source' => 'From string'));
        $content = isset($filedata['content']) ? $filedata['content'] : 'some content here';

        $file = $fs->create_file_from_string($filerecord, $content);
        $file->set_source($source);

        return $file;
    }
}
