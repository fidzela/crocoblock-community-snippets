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
 * Controller for WPFront User Role Editor User Switching
 *
 * @author Syam Mohan
 * @copyright 2014 wpfront.com
 */

namespace WPFront\URE\User_Switching;

if (!defined('ABSPATH')) {
    exit();
}

use \WPFront\URE\WPFront_User_Role_Editor_Utils as Utils;
use WPFront\URE\WPFront_User_Role_Editor_Roles_Helper as RolesHelper;

if (!class_exists('\WPFront\URE\User_Switching\WPFront_User_Role_Editor_User_Switching')) {

    /**
     * User Switching Class
     *
     * @author Syam Mohan
     * @copyright 2014 wpfront.com
     */
    class WPFront_User_Role_Editor_User_Switching extends \WPFront\URE\WPFront_User_Role_Editor_Controller {

        const CAP = 'switch_users';

        protected function setUp() {
            $this->_setUp(self::CAP);
        }

        protected function initialize() {
            add_action('init', array($this, 'switch_user'), 1);
            add_action('admin_bar_menu', array($this, 'admin_bar_menu_actions'), PHP_INT_MAX);
            add_action('get_footer', array($this, 'switch_back_user_floating_link'));
            add_action('wp_logout', array($this, 'clear_cookie_values'));
            add_action('wp_login', array($this, 'clear_cookie_values'));
            add_action('bbp_template_after_user_details', array($this, 'bbpress_switch_to_link'));
            add_action('bp_member_header_actions', array($this, 'buddypress_switch_to_button'));
            add_action('bp_directory_members_actions', array($this, 'buddypress_switch_to_button'));

            add_filter('wpfront_ure_administrator_caps_to_process', function ($caps) {
                $caps[] = self::CAP;
                return $caps;
            });

            if (!$this->in_admin_ui()) {
                return;
            }

            RolesHelper::add_capability_group('users', 'Users');
            RolesHelper::add_new_capability_to_group('users', self::CAP);
            add_filter("wpfront_ure_capability_" . self::CAP . "_ui_help_link", array($this, 'cap_help_link'), 10, 2);
            
            if (is_multisite()) {
                add_filter('ms_user_row_actions', array($this, 'user_row_actions'), 10, 2);
            }
            add_filter('user_row_actions', array($this, 'user_row_actions'), 10, 2);

            add_action('personal_options', array($this, 'user_profile_actions'));
        }

        public function user_row_actions($actions, $user) {
            if(!$this->can_switch_to_user($user)) {
                return $actions;
            }

            $actions['ure_switch_to'] = sprintf('<a href="%s">%s</a>', $this->get_switch_to_url($user->ID), __('Switch To', 'wpfront-user-role-editor'));

            return $actions;
        }

        public function user_profile_actions($user) {
            if(!$this->can_switch_to_user($user)) {
                return;
            }

            ?>
            <tr>
                <th scope="row"><?php echo __('User Switching', 'wpfront-user-role-editor'); ?></th>
                <td><a href="<?php echo $this->get_switch_to_url($user->ID); ?>"><?php echo __('Switch To', 'wpfront-user-role-editor') ?></a></td>
            </tr>
            <?php
        }

        public function admin_bar_menu_actions($wp_admin_bar) {
            $user_id = $this->get_last_session_user_id();
            if (empty($user_id)) {
                return;
            }

            $old_user = get_userdata($user_id);
            if (empty($old_user)) {
                return;
            }

            $wp_admin_bar->add_node(array(
                'id' => 'ure-switch-actions',
                'title' => sprintf(__('User Switching Active (%d)', 'wpfront-user-role-editor'), count($this->get_switched_users_stack())),
            ));

            $wp_admin_bar->add_node(array(
                'id' => 'ure-switch-back',
                'parent' => 'ure-switch-actions',
                'title' => esc_html(sprintf(
                                __('Switch Back to %1$s (%2$s)', 'wpfront-user-role-editor'),
                                $old_user->display_name,
                                $old_user->user_login
                )),
                'href' => $this->get_switch_back_url()
            ));

            add_action('admin_footer', array($this, 'admin_bar_user_switching_element_style'));
            add_action('wp_footer', array($this, 'admin_bar_user_switching_element_style'));

            $user_id = $this->get_first_session_user_id();
            if (empty($user_id)) {
                return;
            }

            $old_user = get_userdata($user_id);
            if (empty($old_user)) {
                return;
            }

            $wp_admin_bar->add_node(array(
                'id' => 'ure-clear-users-stack',
                'parent' => 'ure-switch-actions',
                'title' => __('Clear All Sessions', 'wpfront-user-role-editor'),
                'href' => $this->get_switch_back_url(true)
            ));
        }

        public function admin_bar_user_switching_element_style() {
            ?>
            <style>
                #wpadminbar #wp-admin-bar-ure-switch-actions:not(:hover){
                    background-color : #740; 
                }
            </style>
            <?php
        }

        public function switch_back_user_floating_link() {
            if (!is_user_logged_in()) {
                return;
            }

            if (is_admin_bar_showing()) {
                return;
            }

            $user_id = $this->get_last_session_user_id();
            if (empty($user_id)) {
                return;
            }

            $old_user = get_userdata($user_id);
            if (empty($old_user)) {
                return;
            }

            wp_enqueue_style('dashicons');

            $title = sprintf(
                    __('Switch Back to %1$s (%2$s)', 'wpfront-user-role-editor'),
                    $old_user->display_name,
                    $old_user->user_login);
            ?>
            <a id="switch-back-user-floating-link" title="<?php echo esc_attr($title); ?>" href="<?php echo $this->get_switch_back_url(); ?>">
            </a>
            <style>
                #switch-back-user-floating-link{
                    position: fixed; 
                    left: 10px;
                    top: 10px; 
                    z-index: 99999;
                    text-decoration:none;
                    font-size: 3em;
                }

                #switch-back-user-floating-link:before{
                    content: "\f518";
                    font-family: 'Dashicons';
                }
            </style>
            <?php
        }

        public function switch_user() {
            if (!isset($_GET['ure_switch_action'])) {
                return;
            }

            if (!is_user_logged_in()) {
                return;
            }

            switch ($_GET['ure_switch_action']) {

                case 'switch_to':
                    if (empty($_GET['user_id'])) {
                        return;
                    }

                    $user_id = intval($_GET['user_id']);
                    check_admin_referer("switch_to_user_{$user_id}");

                    if(!$this->can_switch_to_user($user_id)) {
                        wp_die(__('Permission denied.', 'wpfront-user-role-editor'), 403);
                    }

                    $current_user_id = get_current_user_id();
                    $this->set_cookie_values($current_user_id);

                    wp_clear_auth_cookie();
                    wp_set_auth_cookie($user_id);
                    wp_set_current_user($user_id);

                    $this->forget_woocommerce_session();

                    if (current_user_can('read')) {
                        wp_redirect(admin_url());
                    } else {
                        wp_redirect(home_url());
                    }
                    exit;

                    break;

                case 'switch_back':
                case 'clear':
                    $user_id = get_current_user_id();
                    check_admin_referer("switch_back_user_{$user_id}");

                    $remember = $this->is_remember_session_enabled();

                    if ($_GET['ure_switch_action'] === 'switch_back') {
                        $user_id = $this->pop_switched_users_stack();
                    } else {
                        $user_id = $this->get_first_session_user_id();
                        $this->clear_cookie_values();
                    }

                    if (!empty($user_id) && !empty(get_userdata($user_id))) {
                        wp_clear_auth_cookie();

                        if (empty($this->get_last_session_user_id())) {
                            wp_set_auth_cookie($user_id, $remember);
                        } else {
                            wp_set_auth_cookie($user_id);
                        }

                        wp_set_current_user($user_id);

                        $this->forget_woocommerce_session();
                    }

                    if (!current_user_can(self::CAP)) {
                        wp_clear_auth_cookie();
                        $this->clear_cookie_values();
                    }

                    if (current_user_can('read')) {
                        wp_redirect(admin_url());
                    } else {
                        wp_redirect(home_url());
                    }
                    exit;

                    break;
            }
        }

        protected function get_switch_to_url($user_id, $ctx = 'users.php') {
            $url = admin_url('users.php');
            $url = add_query_arg(array(
                'ure_switch_action' => 'switch_to',
                'user_id' => $user_id,
                    ), $url);

            return wp_nonce_url($url, "switch_to_user_{$user_id}");
        }

        protected function get_switch_back_url($first = false) {
            $action = 'switch_back';
            if ($first) {
                $action = 'clear';
            }

            $url = admin_url();
            $url = add_query_arg(array(
                'ure_switch_action' => $action
                    ), $url);

            $user_id = get_current_user_id();
            return wp_nonce_url($url, "switch_back_user_{$user_id}");
        }

        protected function current_user_is_remembered() {
            $cookie_life = apply_filters('auth_cookie_expiration', 172800, get_current_user_id(), false);
            $current = wp_parse_auth_cookie('', 'logged_in');

            if (empty($current)) {
                return false;
            }
            return (intval($current['expiration']) - time() > $cookie_life);
        }

        protected function is_remember_session_enabled() {
            $c_name = $this->get_switched_users_stack_cookie_name();
            if (isset($_COOKIE[$c_name])) {
                $users = $_COOKIE[$c_name];
                $users = Utils::decrypt($users);

                if (empty($users)) {
                    return false;
                }

                $users = explode('-', $users);
                if (isset($users[3])) {
                    return $users[3] === 'remember';
                }
            }

            return false;
        }

        protected function set_cookie_values($user_id) {
            $this->set_switched_users_stack($user_id);
        }

        public function clear_cookie_values() {
            $this->set_switched_users_stack([]);
        }

        protected function get_switched_users_stack() {
            if (!is_user_logged_in()) {
                return array();
            }

            $c_name = $this->get_switched_users_stack_cookie_name();

            if (isset($_COOKIE[$c_name])) {
                $found = false;
                $users = wp_cache_get($c_name, '', false, $found);
                if ($found) {
                    return $users;
                }

                $users = $_COOKIE[$c_name];

                $users = Utils::decrypt($users);

                if (empty($users)) {
                    return array();
                }

                $users = explode('-', $users);

                if (COOKIEHASH !== $users[0]) {
                    return array();
                }

                $time = $users[1];
                if ((time() - $time) > 43200) {  //12 hours
                    return array();
                }

                $users = $users[2];

                $users = explode(',', $users);

                wp_cache_set($c_name, $users);

                return $users;
            }

            return array();
        }

        protected function pop_switched_users_stack() {
            $users = $this->get_switched_users_stack();

            if (empty($users)) {
                $this->clear_cookie_values();
                return null;
            }

            $user_id = intval(array_pop($users));
            $this->set_switched_users_stack($users);
            return $user_id;
        }

        protected function set_switched_users_stack($user_id) {
            if (!is_user_logged_in()) {
                $user_id = array();
            }

            if (is_array($user_id)) {
                $users = $user_id;
            } else {
                $users = $this->get_switched_users_stack();
                $users[] = $user_id;
            }

            $c_name = $this->get_switched_users_stack_cookie_name();

            if (empty($users)) {
                if(isset($_COOKIE[$c_name])) {
                    setcookie($c_name, '', time() - YEAR_IN_SECONDS, SITECOOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
                }

                wp_cache_delete($c_name);

                unset($_COOKIE[$c_name]);
            } else {
                wp_cache_set($c_name, $users);

                if (!isset($_COOKIE[$c_name])) {
                    $remember = $this->current_user_is_remembered();
                } else {
                    $remember = $this->is_remember_session_enabled();
                }

                $remember = $remember ? 'remember' : '';
                $users = implode(',', $users);
                $users = COOKIEHASH . '-' . time() . '-' . $users . '-' . $remember;

                $users = Utils::encrypt($users);

                setcookie($c_name, $users, 0, SITECOOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
            }
        }

        protected function get_last_session_user_id() {
            $users = $this->get_switched_users_stack();

            if (empty($users)) {
                return null;
            }

            return intval(end($users));
        }

        protected function get_first_session_user_id() {
            $users = $this->get_switched_users_stack();

            if (empty($users)) {
                return null;
            }
            return intval(reset($users));
        }

        protected function get_switched_users_stack_cookie_name() {
            return 'wpfront_ure_user_switching_stack_' . COOKIEHASH;
        }

        public function cap_help_link($help_link, $cap) {
            return RolesHelper::get_wpfront_help_link($cap);
        }

        public function bbpress_switch_to_link() {
            $user_id = bbp_get_user_id();
            if(!$this->can_switch_to_user($user_id)) {
                return;
            }

            echo sprintf('<a href="%s">%s</a>', $this->get_switch_to_url($user_id), __('Switch To', 'wpfront-user-role-editor'));
        }

        public function buddypress_switch_to_button() {
            $user_id = 0;

            if (bp_is_user()) {
                $user_id = bp_displayed_user_id();
            } elseif (bp_is_members_directory()) {
                $user_id = bp_get_member_user_id();
            }

            if (empty($user_id)) {
                return;
            }

            if(!$this->can_switch_to_user($user_id)) {
                return;
            }

            echo bp_get_button(array(
                'id' => 'ure_switch_action',
                'link_href' => esc_url($this->get_switch_to_url($user_id)),
                'link_text' => __('Switch To', 'wpfront-user-role-editor'),
                'wrapper_id' => 'ure_switch_to',
            ));
        }

        protected function forget_woocommerce_session() {
            if (!function_exists('WC')) {
                return;
            }

            $wc = WC();

            if (empty($wc->session)) {
                return;
            }

            if (!$wc->session instanceof object || !method_exists($wc->session, 'forget_session')) {// @phpstan-ignore-line
                return;
            }

            $wc->session->forget_session();// @phpstan-ignore-line
        }

        /**
         * Checks whether current user has permission to switch to the given user.
         *
         * @param int|\WP_User $user
         * @return boolean
         */
        protected function can_switch_to_user($user) 
        {
            if(is_int($user)) {
                $user_id = $user;
            } else {
                $user_id = $user->ID;
            }

            if (empty($user_id)) {
                return false;
            }

            if (is_int($user) && empty(get_userdata($user_id))) {
                return false;
            }

            if(!is_user_logged_in()) {
                return false;
            }

            if (is_multisite() && !is_super_admin()) {
                return false;
            }
            
            if (!current_user_can(self::CAP)) {
                return false;
            }

            if (!current_user_can('edit_user', $user_id)) {
                return false;
            }

            if ($user_id == get_current_user_id()) {
                return false;
            }

            return true;
        }

        public static function get_debug_setting() {
            return array('key' => 'user-switching', 'label' => 'User Switching', 'position' => 195, 'description' => 'Disables switching between users functionality.');
        }

    }

    WPFront_User_Role_Editor_User_Switching::load();
}

