<?php
/*
  WPFront User Role Editor Plugin
  Copyright (C) 2014, wpfront.com
  Website: wpfront.com
  Contact: syam@wpfront.com

  WPFront User Role Editor Plugin is distributed under the GNU General Public License, Version 3,
  June 2007. Copyright (C) 2007 Free Software Foundation, Inc., 51 Franklin
  St, Fifth Floor, Boston, MA 02110, USA

  THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
  ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
  WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
  DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
  ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
  (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
  LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
  ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
  (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
  SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

/**
 * Controller for WPFront User Role Editor Comment Capabilities
 *
 * @author Syam Mohan <syam@wpfront.com>
 * @copyright 2014 wpfront.com
 */

namespace WPFront\URE\Comments;

if (!defined('ABSPATH')) {
    exit();
}

use WPFront\URE\WPFront_User_Role_Editor_Roles_Helper;
use WPFront\URE\Options\WPFront_User_Role_Editor_Options;

if (!class_exists('\WPFront\URE\Comments\WPFront_User_Role_Editor_Comment_Capabilities')) {

    /**
     * Comment Capabilities class
     *
     * @author Syam Mohan <syam@wpfront.com>
     * @copyright 2014 wpfront.com
     */
    class WPFront_User_Role_Editor_Comment_Capabilities extends \WPFront\URE\WPFront_User_Role_Editor_Controller
    {

        /**
         * Pre defined capabilities
         *
         * @var array<string,string>
         */
        protected static $pre_defined_capabilities = array(
            'moderate_others_posts_comments' => 'moderate_comments',
            'moderate_post_comments' => 'moderate_comments',
            'moderate_page_comments' => 'moderate_comments'
        );

        /**
         * Post type capability mapping
         *
         * @var array<string,string> post_type => cap
         */
        protected static $post_type_capabilities = array();

        /**
         * Custom capability mapping - merged values
         *
         * @var array<string,string>
         */
        protected static $comment_capabilities = array();

        /**
         * Override - Setup
         *
         * @return void
         */
        protected function setUp()
        {
        }

        /**
         * Init function
         *
         * @return void
         */
        protected function initialize()
        {
            add_action('wp_loaded', array($this, 'wp_loaded'));
        }

        /**
         * Callback - Does everything
         *
         * @return void
         */
        public function wp_loaded()
        {
            $this->set_capabilities();

            if (!is_admin()) {
                return;
            }

            $this->do_role_edit_actions();

            $this->add_capabilities_to_roles();

            add_filter('wpfront_ure_restore_role_custom_caps', array($this, 'restore_role_custom_caps'));
        }

        /**
         * Finds post type capabilities 
         * Sets comment capabilities array
         *
         * @return void
         */
        protected function set_capabilities()
        {
            $post_type_objects = $this->get_post_type_objects();

            /**
             * @var array<string,string>
             */
            $post_type_caps = array();
            foreach ($post_type_objects as $post_type_object) {
                $cap = $this->translate_to_capability($post_type_object);
                self::$post_type_capabilities[$post_type_object->name] = $cap;
                $post_type_caps[$cap] = 'moderate_comments';
            }

            self::$comment_capabilities = array_merge(self::$pre_defined_capabilities, $post_type_caps);
        }

        /**
         * Returns post type objects supporting comments
         *
         * @return \WP_Post_Type[]
         */
        protected function get_post_type_objects()
        {
            $post_type_objs = array();
            /**
             * @var \WP_Post_Type[]
             */
            $post_type_objects = get_post_types(array(), 'objects');
            foreach ($post_type_objects as $post_type_object) {
                $support_comments = post_type_supports($post_type_object->name, 'comments');
                if ($support_comments) {
                    $post_type_objs[] = $post_type_object;
                }
            }

            return $post_type_objs;
        }

        /**
         * Returns capability name from post type object
         *
         * @param \WP_Post_Type $post_type_obj
         * @return string
         */
        protected function translate_to_capability($post_type_obj)
        {
            $cap_type = $post_type_obj->capability_type;
            if(is_array($post_type_obj->capability_type)) { //@phpstan-ignore-line
                $cap_type = $post_type_obj->capability_type[0];
                if(count($post_type_obj->capability_type) > 1) {
                    $cap_type = $post_type_obj->capability_type[1];
                }
            }

            return "moderate_{$cap_type}_comments";
        }

        /**
         * Sets hooks for role edit page
         *
         * @return void
         */
        protected function do_role_edit_actions()
        {
            WPFront_User_Role_Editor_Roles_Helper::add_capability_group('comments', __('Comments', 'wpfront-user-role-editor'));

            $comment_capabilities = array_keys(self::$comment_capabilities);
            foreach ($comment_capabilities as $cap) {
                WPFront_User_Role_Editor_Roles_Helper::add_new_capability_to_group('comments', $cap);

                add_filter("wpfront_ure_capability_{$cap}_ui_help_link", array($this, 'cap_help_link'), 10, 2);
                $this->set_functionality_enabled_filter($cap);
            }
        }

        /**
         * Sets the functionality enabled filter for a cap
         *
         * @param string $cap
         * @return void
         */
        protected function set_functionality_enabled_filter($cap)
        {
            add_filter("wpfront_ure_capability_{$cap}_functionality_enabled", '__return_false');
        }

        /**
         * Sets the default capability state based on mapping.
         *
         * @return void
         */
        public function add_capabilities_to_roles()
        {
            $option_key = 'comment_capabilities_processed';
            $processed = WPFront_User_Role_Editor_Options::instance()->get_option($option_key);

            if (!is_array($processed)) {
                $processed = array();
            }

            global $wp_roles;
            $role_objects = array_values($wp_roles->role_objects);
            $update = false;

            foreach (self::$comment_capabilities as $comment_cap => $dep_cap) {
                if (isset($processed[$comment_cap])) {
                    continue;
                }

                $flag = false;
                foreach ($role_objects as $role) {
                    if ($role->has_cap($dep_cap) && !$role->has_cap($comment_cap)) {
                        $role->add_cap($comment_cap);
                        $flag = true;
                    }
                }

                if(!$flag) {
                    $processed[$comment_cap] = true;
                }
                $update = true;
            }

            if ($update) {
                WPFront_User_Role_Editor_Options::instance()->set_option($option_key, $processed);
            }
        }

        /**
         * Callback for restore functionality
         *
         * @param array<string,string> $custom_caps
         * @return array<string,string>
         */
        public function restore_role_custom_caps($custom_caps)
        {
            foreach (self::$comment_capabilities as $key => $value) {
                $custom_caps[$key] = $value;
            }

            return $custom_caps;
        }

        /**
         * Callback for help link
         *
         * @param string $help_link
         * @param string $cap
         * @return string
         */
        public function cap_help_link($help_link, $cap)
        {
            if (isset(self::$pre_defined_capabilities[$cap])) {
                return WPFront_User_Role_Editor_Roles_Helper::get_wpfront_help_link($cap);
            }

            return $help_link;
        }

        /**
         * Debug setting method
         *
         * @return array<string,mixed>
         */
        public static function get_debug_setting()
        {
            return array('key' => 'comment-capabilities', 'label' => 'Comments Custom Capabilities', 'position' => 45, 'description' => 'Disables all comment custom capabilities.');
        }
    }

    WPFront_User_Role_Editor_Comment_Capabilities::load();
}
