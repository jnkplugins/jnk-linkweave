<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;

/**
 * Human-friendly timestamp formatting shared across the admin UI.
 */
class Dates {

    /**
     * Format a timestamp as a friendly US date/time, optionally with a relative
     * suffix, e.g. "Jul 23, 2026 7:59 PM (13 days ago)".
     *
     * @param int|string|null $value     Unix timestamp, MySQL datetime, or ISO-8601.
     * @param bool            $relative  Append "(… ago)" / "(in …)".
     * @param string          $empty     Returned when the value is empty/unparseable.
     */
    public static function human( $value, bool $relative = true, string $empty = "—" ): string {
        $ts = self::timestamp( $value );
        if ( ! $ts ) {
            return $empty;
        }
        $abs = function_exists( "wp_date" )
            ? wp_date( "M j, Y g:i A", $ts )
            : date_i18n( "M j, Y g:i A", $ts );
        return $relative ? $abs . " (" . self::relative_from_ts( $ts ) . ")" : $abs;
    }

    private static function relative_from_ts( int $ts ): string {
        $now = time();
        return $ts >= $now
            /* translators: %s: human-readable time difference, e.g. "3 months". */
            ? sprintf( __( "in %s", "jnk-linkweave" ), human_time_diff( $now, $ts ) )
            /* translators: %s: human-readable time difference, e.g. "13 days". */
            : sprintf( __( "%s ago", "jnk-linkweave" ), human_time_diff( $ts, $now ) );
    }

    private static function timestamp( $value ): int {
        if ( $value === null || $value === "" ) {
            return 0;
        }
        if ( is_numeric( $value ) ) {
            return (int) $value;
        }
        $ts = strtotime( (string) $value );
        if ( ! $ts ) {
            // strtotime can choke on fractional seconds — retry without them.
            $ts = strtotime( preg_replace( "/\\.\\d+/", "", (string) $value ) );
        }
        return $ts ?: 0;
    }
}
