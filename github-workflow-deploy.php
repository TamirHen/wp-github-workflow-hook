<?php

/**
 * @package GitHub Workflow Deploy
 */

/*
Plugin Name: GitHub Workflow Deploy
Plugin URI: https://github.com/TamirHen/wp-github-workflow-hook
Description: WordPress plugin for triggering a GitHub action to deploy a static site

Version: 1.0.1
Author: Tamir Hen
License: GPLv3 or later
Text Domain: github-workflow-hook
*/

/*
This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

defined('ABSPATH') or die('You do not have access to this file');

class github_workflow_deploy
{

    /**
     * Constructor
     *
     * @since 1.0.0
     **/
    public function __construct()
    {

        // Stop crons on uninstall
        register_deactivation_hook(__FILE__, array($this, 'deactivate_scheduled_cron'));

        add_action('wp_loaded', array($this, 'create_plugin_capabilities'));
        // Hook into the admin menu
        add_action('admin_menu', array($this, 'create_plugin_settings_page'));

        // Add Settings and Fields
        add_action('admin_init', array($this, 'setup_sections'));
        add_action('admin_init', array($this, 'setup_schedule_fields'));
        add_action('admin_init', array($this, 'setup_developer_fields'));
        add_action('admin_footer', array($this, 'run_the_mighty_javascript'));
        add_action('admin_bar_menu', array($this, 'add_to_admin_bar'), 90);

        // Listen to cron scheduler option updates
        add_action('update_option_enable_scheduled_builds', array($this, 'build_schedule_options_updated'), 10, 3);
        add_action('update_option_select_schedule_builds', array($this, 'build_schedule_options_updated'), 10, 3);
        add_action('update_option_select_time_build', array($this, 'build_schedule_options_updated'), 10, 3);

        // Trigger cron scheduler every WP load
        add_action('wp', array($this, 'set_build_schedule_cron'));

        // Add custom schedules
        add_filter('cron_schedules', array($this, 'custom_cron_intervals'));

        // Link event to function
        add_action('scheduled_build', array($this, 'fire_github_deploy'));

        // add actions for deploying on post/page update and publish
        add_action('publish_future_post', array($this, ' vb_webhook_future_post'), 10);
        add_action('transition_post_status', array($this, 'vb_webhook_post'), 10, 3);
    }

    /**
     * Gets the webhook address by constant or by settings, in that order
     * @return ?string
     */
    public function get_webhook_address()
    {
        return "https://api.github.com/repos/".get_option('github_username')."/".get_option('github_repo')."/actions/workflows/".get_option('github_workflow_id_or_file_name');
    }

    /**
     * Gets the GitHub access_token
     * @return ?string
     */
    public function get_github_access_token()
    {
        return get_option('github_access_token');
    }

    /**
     * Gets the GitHub deploy branch
     * @return ?string
     */
    public function get_github_deploy_branch()
    {
        return get_option('github_deploy_branch');
    }

    /**
     * Main Plugin Page markup
     *
     * @since 1.0.0
     **/
    public function plugin_settings_page_content()
    { ?>
        <div class="wrap">
            <h2><?php _e('Deploy Website', 'github-workflow-deploy'); ?></h2>
            <hr>
            <h3><?php _e('Build Website', 'github-workflow-deploy'); ?></h3>
            <button id="build_button" class="button button-primary" name="submit" type="submit">
                <?php _e('Build Site', 'github-workflow-deploy'); ?>
            </button>
            <br>
            <p id="build_status" style="font-size: 12px; margin: 16px 0;">
            <p id="run_status" style="font-size: 12px; margin: 16px 0;">
            </p>
        </div>
        <?php
    }

    /**
     * Schedule Builds (subpage) markup
     *
     * @since 1.0.0
     **/
    public function plugin_settings_schedule_content()
    { ?>
        <div class="wrap">
            <h1><?php _e('Schedule Deploys', 'github-workflow-deploy'); ?></h1>
            <p><?php _e('This section allows regular deploys to be scheduled.', 'github-workflow-deploy'); ?></p>
            <p>
                <?php _e('Please make sure you schedule your deployments according to your ', 'github-workflow-deploy'); ?>
                <strong><?php _e('site\'s timezone', 'github-workflow-deploy'); ?></strong>
                <?php _e(' (and not your computer\'s).', 'github-workflow-deploy'); ?>
                <br>
                <?php _e('To double check your site\'s timezone go to ', 'github-workflow-deploy'); ?>
                <a href="/wp-admin/options-general.php"><?php _e('Settings -> General', 'github-workflow-deploy'); ?></a>
            </p>
            <hr>

            <?php
            if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
                $this->admin_notice();
            } ?>

            <form method="POST" action="options.php">
                <?php
                settings_fields('schedule_deploy');
                do_settings_sections('schedule_deploy');
                submit_button();
                ?>
            </form>
        </div> <?php
    }

    /**
     * Settings (subpage) markup
     *
     * @since 1.0.0
     **/
    public function plugin_settings_developer_content()
    { ?>
        <div class="wrap">
            <h1><?php _e('Settings', 'github-workflow-deploy'); ?></h1>
            <hr>

            <?php
            if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
                $this->admin_notice();
            } ?>
            <form method="POST" action="options.php">
                <?php
                settings_fields('developer_webhook_fields');
                do_settings_sections('developer_webhook_fields');
                submit_button();
                ?>
            </form>

            <footer>
                <h3><?php _e('Extra Info', 'github-workflow-deploy'); ?></h3>
                <p>
                    <a href="https://github.com/TamirHen/wp-github-workflow-hook"><?php _e('Plugin repository on Github', 'github-workflow-deploy'); ?></a>
                </p>
            </footer>

        </div> <?php
    }

    /**
     * The Mighty JavaScript
     *
     * @since 1.0.0
     **/
    public function run_the_mighty_javascript()
    {
        ?>
        <script type="text/javascript">
            console.log('run_the_mighty_javascript');
            jQuery(document).ready(function ($) {
                var _this = this;
                $(".deploy_page_developer_webhook_fields td > input").css({"width": "100%", "max-width": "400px"});

                const webhook_url = '<?php echo($this->get_webhook_address()) ?>';
                const github_access_token = '<?php echo($this->get_github_access_token()) ?>';
                const github_deploy_branch = '<?php echo($this->get_github_deploy_branch()) ?>';

                function sleep(ms) {
                    return new Promise(resolve => setTimeout(resolve, ms));
                }

                function githubDeploy() {
                    return $.ajax({
                        type: "POST",
                        url: `${webhook_url}/dispatches`,
                        dataType: "json",
                        data: JSON.stringify({"ref": github_deploy_branch}),
                        headers: { Authorization: `Bearer ${github_access_token}` }
                    })
                }

                function githubWorkflowStatus() {
                    return $.ajax({
                        type: "GET",
                        url: `${webhook_url}/runs`,
                        dataType: "json",
                        headers: { Authorization: `Bearer ${github_access_token}` }
                    })
                }

                $("#build_button").on("click", function (e) {
                    e.preventDefault();
                    $("#build_status").html(null);
                    $("#run_status").html(null);
                    const $button = $(this);
                    let buildConclusion = null;
                    githubDeploy().done(async function (res) {
                        $button.attr('disabled', true);
                        $("#build_status").html('Building in progress. Do not refresh this page if you wanna track deployment status.');
                        while (!buildConclusion) {
                            await sleep(5000) // 5 seconds
                            const status = githubWorkflowStatus()
                                .done(res => {
                                    const workflow_run = res.workflow_runs[0]
                                    if (workflow_run.status !== "queued" && workflow_run.status !== "in_progress") {
                                        $("#build_status").html(`Website build finished with status: ${workflow_run.status}`);
                                        $("#run_status").html(`Deployment status: ${workflow_run.conclusion}`);
                                        buildConclusion = workflow_run.conclusion
                                        return
                                    }
                                    $("#run_status").html(`Deployment status: ${workflow_run.status}`);
                                })
                                .fail(err => {
                                    console.error("error res => ", this)
                                    $("#build_status").html('There was an error tracking the build. Please wait a few minutes and check manually if the build was successful.', this);
                                    buildConclusion = 'unknown'
                                })
                        }
                        // sleep 5 seconds to allow api get updated
                        await sleep(5000)
                        $button.attr('disabled', false);
                    })
                        .fail(function (err) {
                            console.error("error res => ", this)
                            $("#build_status").html('There seems to be an error with the build.', this);
                        })
                });

                $(document).on('click', '#wp-admin-bar-github-deploy-button', function (e) {
                    e.preventDefault();

                    const $button = $(this),
                        $buttonContent = $button.find('.ab-item:first');

                    if ($button.hasClass('deploying') || $button.hasClass('running')) {
                        return false;
                    }

                    $button.addClass('running').css('opacity', '0.5');

                    githubDeploy().done(function () {
                        var $badge = $('#admin-bar-vercel-deploy-status-badge');

                        $button.removeClass('running');
                        $button.addClass('deploying');

                        $buttonContent.find('.ab-label').text('Deploying…');

                        if ($badge.length) {
                            if (!$badge.data('original-src')) {
                                $badge.data('original-src', $badge.attr('src'));
                            }

                            $badge.attr('src', $badge.data('original-src') + '?updated=' + Date.now());
                        }
                    })
                        .fail(function () {
                            $button.removeClass('running').css('opacity', '1');
                            $buttonContent.find('.dashicons-hammer')
                                .removeClass('dashicons-hammer').addClass('dashicons-warning');

                            console.error("error res => ", this)
                        })
                });
            });
        </script> <?php
    }

    public function create_plugin_capabilities()
    {
        $role = get_role('administrator');
        $role->add_cap('deploy_capability', true);
        $role->add_cap('adjust_settings_capability', true);
    }

    /**
     * Plugin Menu Items Setup
     *
     * @since 1.0.0
     **/
    public function create_plugin_settings_page()
    {
        if (current_user_can('deploy_capability')) {
            $page_title = __('Manual Deploy', 'github-workflow-deploy');
            $menu_title = __('Deploy', 'github-workflow-deploy');
            $capability = 'deploy_capability';
            $slug = 'manual_deploy';
            $callback = array($this, 'plugin_settings_page_content');
            $icon = 'dashicons-admin-plugins';
            $position = 2;

            add_menu_page($page_title, $menu_title, $capability, $slug, $callback, $icon, $position);
        }

        if (current_user_can('adjust_settings_capability')) {
            $sub_page_title = __('Schedule Builds', 'github-workflow-deploy');
            $sub_menu_title = __('Schedule Builds', 'github-workflow-deploy');
            $sub_capability = 'adjust_settings_capability';
            $sub_slug = 'schedule_deploy';
            $sub_callback = array($this, 'plugin_settings_schedule_content');

            add_submenu_page($slug, $sub_page_title, $sub_menu_title, $sub_capability, $sub_slug, $sub_callback);
        }

        if (current_user_can('adjust_settings_capability')) {
            $sub_page_title = __('Settings', 'github-workflow-deploy');
            $sub_menu_title = __('Settings', 'github-workflow-deploy');
            $sub_capability = 'adjust_settings_capability';
            $sub_slug = 'developer_webhook_fields';
            $sub_callback = array($this, 'plugin_settings_developer_content');

            add_submenu_page($slug, $sub_page_title, $sub_menu_title, $sub_capability, $sub_slug, $sub_callback);
        }


    }

    /**
     * Custom CRON Intervals
     *
     * cron_schedules code reference:
     * @link https://developer.wordpress.org/reference/hooks/cron_schedules/
     *
     * @since 1.0.0
     **/
    public function custom_cron_intervals($schedules)
    {
        $schedules['weekly'] = array(
            'interval' => 604800,
            'display' => __('Once Weekly', 'github-workflow-deploy')
        );
        $schedules['monthly'] = array(
            'interval' => 2635200,
            'display' => __('Once a month', 'github-workflow-deploy')
        );

        return $schedules;
    }

    /**
     * Notify Admin on Successful Plugin Update
     *
     * @since 1.0.0
     **/
    public function admin_notice()
    { ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('Your settings have been updated!', 'github-workflow-deploy'); ?></p>
        </div>
        <?php
    }

    /**
     * Setup Sections
     *
     * @since 1.0.0
     **/
    public function setup_sections()
    {
        add_settings_section('schedule_section', __('Scheduling Settings', 'github-workflow-deploy'), array($this, 'section_callback'), 'schedule_deploy');
        add_settings_section('developer_settings_section', __('GitHub Settings', 'github-workflow-deploy'), array($this, 'section_callback'), 'developer_webhook_fields');
        add_settings_section('webhook_settings_section', __('Webhook Settings', 'github-workflow-deploy'), array($this, 'section_callback'), 'developer_webhook_fields');
    }

    /**
     * Check it wont break on build and deploy
     *
     * @since 1.0.0
     **/
    public function section_callback($arguments)
    {
        switch ($arguments['id']) {
            case 'developer_settings_section':
                echo __('A Deploy hook URL is required to run this plugin', 'github-workflow-deploy');
                break;
        }
    }

    /**
     * Fields used for schedule input data
     *
     * Based off this article:
     * @link https://www.smashingmagazine.com/2016/04/three-approaches-to-adding-configurable-fields-to-your-plugin/
     *
     * @since 1.0.0
     **/
    public function setup_schedule_fields()
    {
        $fields = array(
            array(
                'uid' => 'enable_scheduled_builds',
                'label' => __('Enable Scheduled Events', 'github-workflow-deploy'),
                'section' => 'schedule_section',
                'type' => 'checkbox',
                'options' => array(
                    'enable' => __('Enable', 'github-workflow-deploy'),
                ),
                'default' => array()
            ),
            array(
                'uid' => 'select_time_build',
                'label' => __('Select Time to Deploy', 'github-workflow-deploy'),
                'section' => 'schedule_section',
                'type' => 'time',
                'default' => '00:00'
            ),
            array(
                'uid' => 'select_schedule_builds',
                'label' => __('Select Build Schedule', 'github-workflow-deploy'),
                'section' => 'schedule_section',
                'type' => 'select',
                'options' => array(
                    'daily' => __('Daily', 'github-workflow-deploy'),
                    'weekly' => __('Weekly', 'github-workflow-deploy'),
                    'monthly' => __('Monthly', 'github-workflow-deploy'),
                ),
                'default' => array('week')
            )
        );
        foreach ($fields as $field) {
            add_settings_field($field['uid'], $field['label'], array($this, 'field_callback'), 'schedule_deploy', $field['section'], $field);
            register_setting('schedule_deploy', $field['uid']);
        }
    }

    /**
     * Fields used for developer input data
     *
     * @since 1.0.0
     **/
    public function setup_developer_fields()
    {
        $fields = array(
            array(
                'uid' => 'github_username',
                'label' => __('GitHub Username', 'github-workflow-deploy'),
                'section' => 'developer_settings_section',
                'type' => 'text',
                'placeholder' => '',
                'default' => ''
            ),
            array(
                'uid' => 'github_repo',
                'label' => __('GitHub Repository', 'github-workflow-deploy'),
                'section' => 'developer_settings_section',
                'type' => 'text',
                'placeholder' => '',
                'default' => ''
            ),
            array(
                'uid' => 'github_workflow_id_or_file_name',
                'label' => __('GitHub Workflow ID or File Name', 'github-workflow-deploy'),
                'section' => 'developer_settings_section',
                'type' => 'text',
                'placeholder' => '',
                'default' => ''
            ),
            array(
                'uid' => 'github_access_token',
                'label' => __('GitHub Access Token', 'github-workflow-deploy'),
                'section' => 'developer_settings_section',
                'type' => 'text',
                'placeholder' => '',
                'default' => ''
            ),
            array(
                'uid' => 'github_deploy_branch',
                'label' => __('GitHub Deploy Branch', 'github-workflow-deploy'),
                'section' => 'developer_settings_section',
                'type' => 'text',
                'placeholder' => '',
                'default' => 'main'
            ),
            array(
                'uid' => 'enable_on_post_update',
                'label' => __('Activate deploy on post update', 'github-workflow-deploy'),
                'section' => 'webhook_settings_section',
                'type' => 'checkbox',
                'options' => array(
                    'enable' => __('Enable', 'github-workflow-deploy'),
                ),
                'default' => array()
            ),


        );
        foreach ($fields as $field) {
            add_settings_field(
                $field['uid'],
                $field['label'],
                $field['callback'] ?? array($this, 'field_callback'),
                'developer_webhook_fields',
                $field['section'],
                $field
            );
            register_setting('developer_webhook_fields', $field['uid']);
        }
    }

    /**
     * Field callback for handling multiple field types
     *
     * @param $arguments
     **@since 1.0.0
     */
    public function field_callback($arguments)
    {

        $value = get_option($arguments['uid']);

        if (!$value) {
            $value = $arguments['default'];
        }

        switch ($arguments['type']) {
            case 'text':
            case 'password':
            case 'number':
                printf('<input name="%1$s" id="%1$s" type="%2$s" placeholder="%3$s" value="%4$s" />', $arguments['uid'], $arguments['type'], $arguments['placeholder'], $value);
                break;
            case 'time':
                printf('<input name="%1$s" id="%1$s" type="time" value="%2$s" />', $arguments['uid'], $value);
                break;
            case 'textarea':
                printf('<textarea name="%1$s" id="%1$s" placeholder="%2$s" rows="5" cols="50">%3$s</textarea>', $arguments['uid'], $arguments['placeholder'], $value);
                break;
            case 'select':
            case 'multiselect':
                if (!empty ($arguments['options']) && is_array($arguments['options'])) {
                    $attributes = '';
                    $options_markup = '';
                    foreach ($arguments['options'] as $key => $label) {
                        $options_markup .= sprintf('<option value="%s" %s>%s</option>', $key, selected($value[array_search($key, $value, true)], $key, false), $label);
                    }
                    if ($arguments['type'] === 'multiselect') {
                        $attributes = ' multiple="multiple" ';
                    }
                    printf('<select name="%1$s[]" id="%1$s" %2$s>%3$s</select>', $arguments['uid'], $attributes, $options_markup);
                }
                break;
            case 'radio':
            case 'checkbox':
                if (!empty ($arguments['options']) && is_array($arguments['options'])) {
                    $options_markup = '';
                    $iterator = 0;
                    foreach ($arguments['options'] as $key => $label) {
                        $iterator++;
                        $options_markup .= sprintf('<label for="%1$s_%6$s"><input id="%1$s_%6$s" name="%1$s[]" type="%2$s" value="%3$s" %4$s /> %5$s</label><br/>', $arguments['uid'], $arguments['type'], $key, checked(count($value) > 0 ? $value[array_search($key, $value, true)] : false, $key, false), $label, $iterator);
                    }
                    printf('<fieldset>%s</fieldset>', $options_markup);
                }
                break;
        }
    }

    /**
     * Add Deploy Button and Deployment Status to admin bar
     *
     * @since 1.0.0
     **/
    public function add_to_admin_bar($admin_bar)
    {
        if (current_user_can('deploy_capability')) {
            $webhook_address = get_option('webhook_address');
            if ($webhook_address) {
                $button = array(
                    'id' => 'github-deploy-button',
                    'title' => '<div style="cursor: pointer;"><span class="ab-icon dashicons dashicons-hammer"></span> <span class="ab-label">' . __('Deploy Site', 'github-workflow-deploy') . '</span></div>'
                );
                $admin_bar->add_node($button);
            }
        }
    }

    /**
     *
     * Manage the cron jobs for triggering builds
     *
     * Check if scheduled builds have been enabled, and pass to
     * the enable function. Or disable.
     *
     * @since 1.0.0
     **/
    public function build_schedule_options_updated()
    {
        $enable_builds = get_option('enable_scheduled_builds');
        if ($enable_builds) {
            // Clean any previous setting
            $this->deactivate_scheduled_cron();
            // Reset schedule
            $this->set_build_schedule_cron();
        } else {
            $this->deactivate_scheduled_cron();
        }
    }

    /**
     *
     * Activate cron job to trigger build
     *
     * @since 1.0.0
     **/
    public function set_build_schedule_cron()
    {
        $enable_builds = get_option('enable_scheduled_builds');
        if ($enable_builds) {
            if (!wp_next_scheduled('scheduled_build')) {
                $schedule = get_option('select_schedule_builds');
                $set_time = get_option('select_time_build');
                $timestamp = strtotime($set_time);
                wp_schedule_event($timestamp, $schedule[0], 'scheduled_build');
            }
        } else {
            $this->deactivate_scheduled_cron();
        }
    }

    /**
     *
     * Remove cron jobs set by this plugin
     *
     * @since 1.0.0
     **/
    public function deactivate_scheduled_cron()
    {
        // find out when the last event was scheduled
        $timestamp = wp_next_scheduled('scheduled_build');
        // unschedule previous event if any
        wp_unschedule_event($timestamp, 'scheduled_build');
    }

    /**
     *
     * Trigger deploy
     *
     * @since 1.0.0
     **/
    public function fire_github_deploy()
    {
        $webhook_url = $this->get_webhook_address();
        $github_access_token = $this->get_github_access_token();
        $github_deploy_branch = $this->get_github_deploy_branch();
        if ($webhook_url) {
            $body = [
                'ref' => $github_deploy_branch
            ];
            $body = wp_json_encode( $body );
            $options = [
                'method' => 'POST',
                'headers' => [
                    'Authorization' => 'Bearer ' . $github_access_token
                ],
                'body' => $body,
                'data_format' => 'body',
            ];
            return wp_remote_post($webhook_url . "/dispatches", $options);
        }
        return false;
    }

    public function vb_webhook_post($new_status, $old_status, $post)
    {
        $enable_builds = get_option('enable_on_post_update');
        // We want to avoid triggering webhook by REST API (called by Gutenberg) not to trigger it twice.
        $rest = defined('REST_REQUEST') && REST_REQUEST;
        // We only want to trigger the webhook only if we transition from or to publish state.
        if ($enable_builds && !$rest && ($new_status === 'publish' || $old_status === 'publish')) {
            $this->fire_github_deploy();
        }
    }

    public function vb_webhook_future_post($post_id)
    {
        $enable_builds = get_option('enable_on_post_update');
        if ($enable_builds) {
            $this->fire_github_deploy();
        }
    }
}

new github_workflow_deploy;
