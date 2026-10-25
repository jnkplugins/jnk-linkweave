<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads corpus stats from the custom index table.

/**
 * BM25 relevance scoring for internal-link suggestions (free core).
 *
 * BM25 improves on plain TF-IDF cosine by (a) saturating term frequency — the
 * 10th occurrence of a word adds far less than the 2nd — and (b) normalizing for
 * document length so long posts don't dominate. Those two effects are what make
 * BM25 the standard retrieval ranking.
 *
 * We reuse the values the index already stores: vector[term] = tf_norm * idf and
 * word_count per post, so the raw term count is recoverable
 * (rawcount = vector[term] / idf[term] * word_count) — no reindex needed. The
 * stored IDF doubles as the per-term weight.
 */
class Bm25 {

    private const K1 = 1.2;  // term-frequency saturation
    private const B  = 0.75; // length-normalization strength

    /** A term in more than this share of posts carries no weight. */
    private const COMMON_SHARE = 0.35;
    /** Below this many indexed posts every term counts: shares are too noisy. */
    private const COMMON_MIN_DOCS = 20;

    private static ?array $idf   = null;
    private static float $avgdl  = 0.0;
    private static int $docs     = 0;

    /** Load corpus stats once per request. */
    public static function prepare(): void {
        if ( null !== self::$idf ) {
            return;
        }
        $idf = get_option( "jnk_linkweave_idf", [] );
        self::$idf = is_array( $idf ) ? $idf : [];

        global $wpdb;
        // %i (identifier placeholder, WP 6.2+) safely quotes the table name.
        self::$avgdl = (float) $wpdb->get_var(
            $wpdb->prepare( "SELECT AVG(word_count) FROM %i", Index_Builder::table() )
        );
        self::$docs = Index_Builder::indexed_count();
    }

    /** Forget the per-request corpus stats (tests, and after a rebuild). */
    public static function reset(): void {
        self::$idf   = null;
        self::$avgdl = 0.0;
        self::$docs  = 0;
    }

    /**
     * True for a word that appears in so many posts it says nothing about a topic.
     * On a movie site "free", "online" and "watch" are in nearly every post; with
     * a positive IDF they still decided which posts were "related" (24 Oct 2026).
     * idf = ln(N / (1 + df)), so df / N = 1 / e^idf - 1 / N.
     */
    public static function is_common( string $term ): bool {
        self::prepare();
        if ( self::$docs < self::COMMON_MIN_DOCS || ! array_key_exists( $term, self::$idf ) ) {
            return false;
        }
        $share = 1 / exp( (float) self::$idf[ $term ] ) - 1 / self::$docs;
        return $share > self::COMMON_SHARE;
    }

    /**
     * Score a candidate document against a source post's term set.
     *
     * @param array<string,mixed> $source_terms presence set of the source's terms
     *        (keys used; values ignored) — standard BM25 counts each query term once.
     * @param array<string,float>  $cand_vector  candidate's stored tf-idf vector
     * @param int                  $cand_words   candidate's word_count (|D|)
     */
    public static function score( array $source_terms, array $cand_vector, int $cand_words ): float {
        self::prepare();
        if ( self::$avgdl <= 0 || $cand_words <= 0 ) {
            return 0.0;
        }
        $len_norm = 1 - self::B + self::B * ( $cand_words / self::$avgdl );

        $score = 0.0;
        foreach ( $cand_vector as $term => $weighted ) {
            if ( ! isset( $source_terms[ $term ] ) || self::is_common( (string) $term ) ) {
                continue; // not in the source "query", or in a large share of all posts
            }
            // Use the SAME per-term IDF the indexer used to build the vector, so the
            // raw-count recovery below is exact: index_post() falls back to log(2)
            // for terms absent from the IDF map (which is empty until a full rebuild
            // runs), so we mirror that fallback here instead of dropping the term.
            $idf = array_key_exists( $term, self::$idf ) ? (float) self::$idf[ $term ] : M_LN2;
            if ( $idf <= 0 ) {
                continue; // non-discriminative (appears in ~every doc)
            }
            // Recover the raw term count from the stored tf-idf value.
            $f = ( $weighted / $idf ) * $cand_words;
            if ( $f <= 0 ) {
                continue;
            }
            $score += $idf * ( $f * ( self::K1 + 1 ) ) / ( $f + self::K1 * $len_norm );
        }
        return $score;
    }
}
