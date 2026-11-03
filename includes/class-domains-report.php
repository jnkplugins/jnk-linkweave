<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own tables.

/**
 * Sites you link to (free): links to other sites, grouped by domain.
 *
 * Read from the external_links table the link graph fills (Cron), so it costs
 * no extra pass over the posts. Per domain: how many links, from how many posts,
 * and what share carries rel="nofollow" / "sponsored" / "ugc". One domain opens
 * into its links. Whether those links still work is the Pro link check.
 */
class Domains_Report {

    private static function table(): string {
        return Cron::external_table();
    }

    /** @return array{links:int,domains:int,posts:int,nofollow:int,sponsored:int,checked:int,broken:int} */
    public static function summary(): array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS links, COUNT(DISTINCT domain) AS domains, COUNT(DISTINCT source_post_id) AS posts,
                    COALESCE(SUM(nofollow),0) AS nofollow, COALESCE(SUM(sponsored),0) AS sponsored,
                    COALESCE(SUM(last_checked IS NOT NULL),0) AS checked, COALESCE(SUM(is_broken = 1),0) AS broken
             FROM %i",
            self::table()
        ) );
        $out = [];
        foreach ( [ "links", "domains", "posts", "nofollow", "sponsored", "checked", "broken" ] as $k ) {
            $out[ $k ] = $row ? (int) $row->$k : 0;
        }
        return $out;
    }

    /**
     * One row per domain, most links first.
     *
     * @return array<int,array{domain:string,links:int,posts:int,nofollow:int,sponsored:int,ugc:int,broken:int,unreachable:int,checked:int}>
     */
    public static function rows( string $search = "", int $limit = 500 ): array {
        global $wpdb;
        $where = "";
        $args  = [ self::table() ];
        if ( "" !== $search ) {
            $where  = "WHERE domain LIKE %s";
            $args[] = "%" . $wpdb->esc_like( strtolower( $search ) ) . "%";
        }
        $args[] = max( 1, $limit );
        $rows   = $wpdb->get_results( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where holds placeholders only.
            "SELECT domain, COUNT(*) AS links, COUNT(DISTINCT source_post_id) AS posts, SUM(nofollow) AS nofollow, SUM(sponsored) AS sponsored, SUM(ugc) AS ugc,
                    SUM(is_broken = 1) AS broken, SUM(is_broken = 2) AS unreachable, SUM(last_checked IS NOT NULL) AS checked
             FROM %i {$where} GROUP BY domain ORDER BY links DESC, domain ASC LIMIT %d",
            $args
        ) );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [
                "domain"      => (string) $r->domain,
                "links"       => (int) $r->links,
                "posts"       => (int) $r->posts,
                "nofollow"    => (int) $r->nofollow,
                "sponsored"   => (int) $r->sponsored,
                "ugc"         => (int) $r->ugc,
                "broken"      => (int) $r->broken,
                "unreachable" => (int) $r->unreachable,
                "checked"     => (int) $r->checked,
            ];
        }
        return $out;
    }

    /**
     * The links to one domain, grouped by post.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function links( string $domain, int $limit = 1000 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, source_post_id, url, anchor_text, nofollow, sponsored, ugc, is_broken, http_code, last_checked FROM %i WHERE domain = %s ORDER BY source_post_id, id LIMIT %d",
            self::table(),
            strtolower( $domain ),
            max( 1, $limit )
        ) );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [
                "id"           => (int) $r->id,
                "source_id"    => (int) $r->source_post_id,
                "source_title" => REST_API::plain_title( (int) $r->source_post_id ) ?: sprintf( "#%d", (int) $r->source_post_id ),
                "source_edit"  => (string) get_edit_post_link( (int) $r->source_post_id, "raw" ),
                "url"          => (string) $r->url,
                "anchor_text"  => (string) $r->anchor_text,
                "rel"          => array_keys( array_filter( [ "nofollow" => (int) $r->nofollow, "sponsored" => (int) $r->sponsored, "ugc" => (int) $r->ugc ] ) ),
                "is_broken"    => (int) $r->is_broken,
                "http_code"    => (int) $r->http_code,
                "last_checked" => $r->last_checked ? (string) $r->last_checked : "",
            ];
        }
        return $out;
    }

    /** A share as a whole percent, "0%" for none. */
    public static function share( int $part, int $whole ): string {
        if ( $whole <= 0 || $part <= 0 ) {
            return number_format_i18n( 0 ) . "%";
        }
        return number_format_i18n( (int) round( 100 * $part / $whole ) ) . "%";
    }
}
