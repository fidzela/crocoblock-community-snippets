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
 * Controller for WPFront User Role Editor Post Type Extended Permissions.
 *
 * @author Syam Mohan <syam@wpfront.com>
 * @copyright 2014 wpfront.com
 */

namespace WPFront\URE\Extended_Permissions;

if (!defined('ABSPATH')) {
    exit();
}

use \WPFront\URE\WPFront_User_Role_Editor_Debug;

if (!class_exists('\WPFront\URE\Extended_Permissions\WPFront_User_Role_Editor_Post_Type_Extended_Permissions')) {

    /**
     * Extended Permissions class
     *
     * @author Syam Mohan <syam@wpfront.com>
     * @copyright 2014 wpfront.com
     */
    class WPFront_User_Role_Editor_Post_Type_Extended_Permissions {

        const META_BOX_KEY = 'wpfront-user-role-editor-role-permissions';

        protected static $instance = null;

        /**
         * Singleton instance.
         * 
         * @return WPFront_User_Role_Editor_Post_Type_Extended_Permissions_Pro
         */
        public static function instance() {
            if (self::$instance === null) {
                self::$instance = new WPFront_User_Role_Editor_Post_Type_Extended_Permissions();
            }

            return self::$instance;
        }

        /**
         * Hooks into wpfront_ure_init.
         */
        public static function init() {
            $debug = WPFront_User_Role_Editor_Debug::instance();
            $debug->add_setting(self::get_debug_setting());

            if ($debug->is_disabled('extended-permissions')) {
                return;
            }

            $instance = self::instance();

            add_action('admin_init', array($instance, 'admin_init'));
        }

        public function admin_init() {
            $post_types = get_post_types(array('public' => true));

            foreach ($post_types as $post_type) {
                add_action("add_meta_boxes_{$post_type}", array($this, 'add_meta_boxes'));
            }

            $taxonomies = get_taxonomies(array('public' => true));

            foreach ($taxonomies as $taxonomy) {
                add_action("{$taxonomy}_add_form_fields", array($this, 'add_meta_boxes_taxonomy')); //add new term
                add_action("{$taxonomy}_edit_form", array($this, 'add_meta_boxes_taxonomy'));       //edit term
            }
        }

        public function add_meta_boxes($post) {
            $a = '<a href="https://wpfront.com/user-role-editor-pro/" target="_blank" style="margin-left: 10px">' . __('Upgrade to PRO', 'wpfront-user-role-editor') . '</a>';
            $h = '<a href="https://docs.wpfront.com/user-role-editor/extended-permissions/extended-permissions/" target="_blank" style="margin-left: 10px">' . __('Help', 'wpfront-user-role-editor') . '</a>';
            add_meta_box(self::META_BOX_KEY, '<div>' . __('Role Permissions', 'wpfront-user-role-editor') . "$a $h</div>", array($this, 'meta_box'), $post->post_type);
            }

            /*
             * add meta box to term edit and add new
             */
        public function add_meta_boxes_taxonomy() {
            ?>
            <div class ="form-field">
                <div id="wpfront-user-role-editor-role-permissions" class="postbox">
                    <div class="postbox-header">
                        <?php
                        $a = '<a href="https://wpfront.com/user-role-editor-pro/" target="_blank" style="margin-left: 10px;font-size: 12px;font-weight: 400">' . __('Upgrade to PRO', 'wpfront-user-role-editor') . '</a>';
                        $h = '<a href="https://docs.wpfront.com/user-role-editor/extended-permissions/extended-permissions/" target="_blank" style="margin-left: 10px;font-size: 12px;font-weight: 400">' . __('Help', 'wpfront-user-role-editor') . '</a>';
                        ?>
                        <div><h2 style="margin-left: 15px"><?php echo __('Role Permissions', 'wpfront-user-role-editor') . $a . $h; ?></h2></div>
                    </div>
                    <div class="inside">
                        <?php
                        $this->meta_box();
                        ?>
                    </div>
                </div>
            </div>
                <?php
            }

            public function meta_box($post = null) {
                ?>
                <table style="font-weight: bold;">
                    <tr>
                        <td style="min-width: 220px;"><?php echo __('Login is required to access this post', 'wpfront-user-role-editor'); ?></td>
                        <td><input type="checkbox" disabled="true" /></td>
                    </tr>
                    <tr>
                        <td><?php echo __('Manage access by user roles', 'wpfront-user-role-editor'); ?></td>
                        <td><input type="checkbox" disabled="true" /></td>
                    </tr>
                </table>    
                <?php
            }

            /* Login and redirect back to requested page end */

            public static function get_debug_setting() {
                return array('key' => 'extended-permissions', 'label' => 'Extended Permissions', 'position' => 110, 'description' => 'Disables extended permissions functionality for all post types and taxonomies.');
            }

        }

        add_action('wpfront_ure_init', array(WPFront_User_Role_Editor_Post_Type_Extended_Permissions::class, 'init'));
    }
