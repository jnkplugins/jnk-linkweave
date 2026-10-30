<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;

/**
 * Human-friendly timestamp formatting shared across the admin UI.
 */
class Dates {

    /**
     * Format a timestamp in the site's date and time format, optionally with a
     * relative suffix, e.g. "July 23, 2026 7:59 pm (13 days ago)".
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
        // The site's own date and time format (Settings > General): a fixed US
        // format read "10월 29, 2026 2:17 오후" on a Korean site.
        $format = trim( get_option( "date_format", "" ) . " " . get_option( "time_format", "" ) );
        if ( "" === $format ) {
            $format = "M j, Y g:i A";
        }
        $abs = function_exists( "wp_date" ) ? wp_date( $format, $ts ) : date_i18n( $format, $ts );
        return $relative ? $abs . " (" . self::relative_from_ts( $ts ) . ")" : $abs;
    }

    private static function relative_from_ts( int $ts ): string {
        $now = time();
        if ( $ts >= $now ) {
            /* translators: %s: human-readable time difference, e.g. "3 months". */
            $pattern = __( "in %s", "jnk-linkweave" );
            if ( "in %s" === $pattern ) {
                // No Linkweave translation for this language: WordPress's own
                // words, which every language WordPress ships has.
                // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core's string on purpose.
                $pattern = __( "%s from now" );
            }
            return sprintf( $pattern, human_time_diff( $now, $ts ) );
        }
        /* translators: %s: human-readable time difference, e.g. "13 days". */
        $pattern = __( "%s ago", "jnk-linkweave" );
        if ( "%s ago" === $pattern ) {
            // An Arabic site read "(37 ثانية ago)": the number translated by
            // WordPress, "ago" not. Fall back to WordPress's own "%s ago".
            // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core's string on purpose.
            $pattern = __( "%s ago" );
        }
        return sprintf( $pattern, human_time_diff( $ts, $now ) );
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
