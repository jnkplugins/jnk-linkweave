<?php
defined( "ABSPATH" ) || exit;

class JnkLinkweave_Deactivator {
    public static function deactivate(): void {
        wp_clear_scheduled_hook( "jnk_linkweave_daily_scan" );
        // Action Scheduler's WP-Cron runner uses an "every_minute" schedule that only
        // exists while Action Scheduler is loaded. Once this plugin (which loads it) is
        // off, WP-Cron fails to reschedule that event and logs "invalid_schedule".
        // Removing it is safe: Action Scheduler re-adds it on its next load, for us or
        // for any other plugin that bundles it.
        //
        // wp_unschedule_hook(), not wp_clear_scheduled_hook(): Action Scheduler
        // schedules the event with the argument ["WP Cron"], and
        // wp_clear_scheduled_hook( $hook ) only removes instances whose arguments are
        // empty, so it silently removed nothing.
        //
        // The flag lets any request still running the plugin refuse to put the event
        // back (Cron::guard_action_scheduler_queue / strip_queue_after_deactivation),
        // and the shutdown pass removes anything written back during this request.
        update_option( "jnk_linkweave_deactivated_at", time(), false );
        wp_unschedule_hook( "action_scheduler_run_queue" );
        add_action( "shutdown", static function () {
            wp_unschedule_hook( "action_scheduler_run_queue" );
        }, PHP_INT_MAX );
    }
}
