<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

class Suggestion_Engine {

    private const ORPHAN_BOOST       = 0.10;
    private const ALREADY_LINKED_PEN = 0.50;

    public function get_suggestions( int $post_id, array $args = [] ): array {
        $opts     = get_option( "jnk_linkweave_settings", [] );
        $defaults = [
            "limit"     => max( 1, min( 50, (int) ( $opts["suggestions_per_post"] ?? 10 ) ) ),
            "min_score" => max( 0.0, min( 1.0, (float) ( $opts["min_relevance_score"] ?? 0.25 ) ) ),
            "mode"      => $this->default_mode(),
        ];
        $args = wp_parse_args( $args, $defaults );

        $post = get_post( $post_id );
        if ( ! $post ) {
            return [];
        }

        $cache_key = $this->cache_key( $post_id, $post->post_modified_gmt, $args );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            $cached["cached"] = true;
            return $cached;
        }

        $source = $this->get_vector_row( $post_id );
        if ( ! $source && "publish" !== $post->post_status ) {
            // Drafts are indexed when they are published; until then rank them on
            // their saved text, so the sidebar helps while the post is written.
            $source = $this->draft_source( $post, (string) $post->post_content );
        }
        if ( ! $source ) {
            return $this->empty_response( $post_id, $args["mode"] );
        }

        $response = $this->rank( $post, $source, (string) $post->post_content, $args );
        set_transient( $cache_key, $response, 6 * HOUR_IN_SECONDS );
        return $response;
    }

    /**
     * Suggestions for text that may not be saved yet: the pre-publish check sends
     * what is in the editor right now. Not cached (the text changes every call).
     *
     * @return array Same shape as get_suggestions().
     */
    public function suggestions_for_content( int $post_id, string $content, array $args = [] ): array {
        $opts = get_option( "jnk_linkweave_settings", [] );
        $args = wp_parse_args( $args, [
            "limit"     => max( 1, min( 50, (int) ( $opts["suggestions_per_post"] ?? 10 ) ) ),
            "min_score" => max( 0.0, min( 1.0, (float) ( $opts["min_relevance_score"] ?? 0.25 ) ) ),
            "mode"      => "",
        ] );
        $post = get_post( $post_id );
        if ( ! $post ) {
            return [];
        }
        $source = $this->draft_source( $post, $content );
        if ( ! $source ) {
            $response = $this->empty_response( $post_id, (string) $args["mode"] );
            return $response;
        }
        return $this->rank( $post, $source, $content, $args );
    }

    /**
     * A source vector built from text instead of the index, or null when the text
     * is too short, the post type is not indexed or the index is empty.
     */
    private function draft_source( \WP_Post $post, string $content ): ?array {
        $opts       = get_option( "jnk_linkweave_settings", [] );
        $post_types = (array) apply_filters( "jnk_linkweave_suggestion_post_types", $opts["post_types"] ?? [ "post", "page" ] );
        if ( ! in_array( $post->post_type, $post_types, true ) || 0 === Index_Builder::indexed_count() ) {
            return null;
        }
        $min_words = (int) apply_filters( "jnk_linkweave_min_post_length", (int) ( $opts["min_post_length"] ?? 40 ) );
        $built     = Index_Builder::vector_for_content( $content, (string) ( $opts["language"] ?? "en" ) );
        if ( $built["words"] < $min_words || ! $built["vector"] ) {
            return null;
        }
        return [
            "post_id"   => (int) $post->ID,
            "post_type" => (string) $post->post_type,
            "vector"    => $built["vector"],
            "norm"      => $built["norm"],
            "words"     => $built["words"],
        ];
    }

    /** Score every candidate against $source and build the response. */
    private function rank( \WP_Post $post, array $source, string $content, array $args ): array {
        $post_id       = (int) $post->ID;
        $opts          = get_option( "jnk_linkweave_settings", [] );
        $candidates    = $this->fetch_candidates( $post_id, $source["post_type"] );
        $orphan_ids    = $this->orphan_ids();
        $linked_target = $this->already_linked_targets( $post_id );

        // Base relevance: BM25 (default — saturates term frequency + normalizes
        // for length) or the original TF-IDF cosine. BM25 scores are unbounded,
        // so we max-normalize them to 0–1 first, keeping the orphan/keyword/GSC
        // boosts and min_score threshold (all calibrated for 0–1) working as-is.
        $use_bm25    = ( "bm25" === $this->ranking_mode() );
        $source_terms = $use_bm25 ? $source["vector"] : [];

        $base = [];
        foreach ( $candidates as $row ) {
            $s = $use_bm25
                ? Bm25::score( $source_terms, $row["vector"], (int) $row["words"] )
                : $this->cosine( $source, $row );
            if ( $s > 0 ) {
                $base[] = [ "row" => $row, "score" => $s ];
            }
        }
        if ( $use_bm25 && ! empty( $base ) ) {
            $max = 0.0;
            foreach ( $base as $b ) {
                $max = max( $max, $b["score"] );
            }
            if ( $max > 0 ) {
                foreach ( $base as $i => $b ) {
                    $base[ $i ]["score"] = $b["score"] / $max;
                }
            }
        }

        // Stem => the word the author actually wrote, so shared terms are readable.
        $surface = Tokenizer::surface_forms(
            $content,
            (string) ( $opts["language"] ?? "en" )
        );

        $scored = [];
        foreach ( $base as $b ) {
            $row   = $b["row"];
            $score = $b["score"];
            if ( isset( $orphan_ids[ $row["post_id"] ] ) ) {
                $score += self::ORPHAN_BOOST;
            }
            if ( isset( $linked_target[ $row["post_id"] ] ) ) {
                $score -= self::ALREADY_LINKED_PEN;
            }
            $score = (float) apply_filters( "jnk_linkweave_suggestion_score", $score, $post_id, (int) $row["post_id"] );
            if ( $score < (float) $args["min_score"] ) {
                continue;
            }
            $scored[] = [
                "target_id"     => (int) $row["post_id"],
                "score"         => $score,
                "matched_terms" => $this->matched_terms( $source["vector"], $row["vector"], 5, $surface ),
            ];
        }

        usort( $scored, static fn( $a, $b ) => $b["score"] <=> $a["score"] );

        // LLM re-ranking is a premium feature. The Pro module attaches its BYOK
        // adapter to this filter; in the free build the filter is a no-op and the
        // TF-IDF / cosine order is returned unchanged.
        $scored = (array) apply_filters( "jnk_linkweave_rerank", $scored, $post_id, (string) $args["mode"] );

        $top      = array_slice( $scored, 0, (int) $args["limit"] );
        $hydrated = $this->hydrate( $top );

        $response = [
            "post_id"      => $post_id,
            "mode"         => $this->reported_mode( (string) $args["mode"] ),
            "suggestions"  => $hydrated,
            "generated_at" => gmdate( "c" ),
            "cached"       => false,
        ];

        // This post IS indexed, so an empty list means nothing cleared the relevance
        // threshold — a different problem from "no index", and one the sidebar has to
        // describe differently or it sends people to rebuild an index that is fine.
        if ( empty( $hydrated ) ) {
            $response["reason"] = [
                "code"      => "no_matches",
                "words"     => (int) ( $source["words"] ?? 0 ),
                "min_words" => 0,
                "indexed"   => 0,
                "min_score" => (float) $args["min_score"],
            ];
        }

        return $response;
    }

    /**
     * Inbound-link opportunities: existing posts that are topically similar to
     * $post_id but do NOT yet link to it — i.e. "these posts should link here".
     *
     * The reverse of get_suggestions(): the candidate set is the same, but we
     * exclude posts that ALREADY link *to* $post_id (inbound) rather than posts
     * $post_id links *out* to. It uses the SAME ranking as get_suggestions (BM25
     * by default, max-normalized to 0–1) so the two editor panels stay consistent
     * — otherwise the suggestions list could be full while this panel reads empty.
     */
    public function inbound_opportunities( int $post_id, array $args = [] ): array {
        $opts = get_option( "jnk_linkweave_settings", [] );
        $args = wp_parse_args( $args, [
            "limit"     => max( 1, min( 50, (int) ( $opts["suggestions_per_post"] ?? 10 ) ) ),
            "min_score" => max( 0.0, min( 1.0, (float) ( $opts["min_relevance_score"] ?? 0.25 ) ) ),
        ] );

        $target = $this->get_vector_row( $post_id );
        if ( ! $target ) {
            // Say why, as the suggestions panel does: "not indexed yet" and "nothing
            // related" need different advice.
            return [ "post_id" => $post_id, "opportunities" => [], "reason" => $this->diagnose( $post_id ) ];
        }

        $candidates     = $this->fetch_candidates( $post_id, $target["post_type"] );
        $already_source = $this->inbound_sources( $post_id );

        $use_bm25     = ( "bm25" === $this->ranking_mode() );
        $target_terms = $use_bm25 ? $target["vector"] : [];

        $base = [];
        foreach ( $candidates as $row ) {
            if ( isset( $already_source[ $row["post_id"] ] ) ) {
                continue; // this post already links here
            }
            $s = $use_bm25
                ? Bm25::score( $target_terms, $row["vector"], (int) $row["words"] )
                : $this->cosine( $target, $row );
            if ( $s > 0 ) {
                $base[] = [ "row" => $row, "score" => $s ];
            }
        }
        if ( $use_bm25 && ! empty( $base ) ) {
            $max = 0.0;
            foreach ( $base as $b ) {
                $max = max( $max, $b["score"] );
            }
            if ( $max > 0 ) {
                foreach ( $base as $i => $b ) {
                    $base[ $i ]["score"] = $b["score"] / $max;
                }
            }
        }

        // Stems ("cach", "redi") read like a rendering fault; show the words the
        // author actually wrote, as the suggestions panel already does.
        $surface = Tokenizer::surface_forms(
            (string) get_post_field( "post_content", $post_id ),
            (string) ( $opts["language"] ?? "en" )
        );

        $scored = [];
        foreach ( $base as $b ) {
            if ( $b["score"] < (float) $args["min_score"] ) {
                continue;
            }
            $scored[] = [
                "target_id"     => (int) $b["row"]["post_id"], // the would-be *source*
                "score"         => $b["score"],
                "matched_terms" => $this->matched_terms( $target["vector"], $b["row"]["vector"], 5, $surface ),
            ];
        }
        usort( $scored, static fn( $a, $b ) => $b["score"] <=> $a["score"] );

        return [
            "post_id"       => $post_id,
            "opportunities" => $this->hydrate( array_slice( $scored, 0, (int) $args["limit"] ) ),
        ];
    }

    /** Post IDs that already link *to* $target_id (inbound sources). */
    private function inbound_sources( int $target_id ): array {
        global $wpdb;
        $links = $wpdb->prefix . "jnk_linkweave_links";
        $ids   = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT source_post_id FROM %i WHERE target_post_id = %d",
            $links,
            $target_id
        ) );
        return array_flip( array_map( "intval", (array) $ids ) );
    }

    /**
     * Announce that a suggestion was inserted in the editor.
     *
     * This used to write a row into the link graph straight away, before the post
     * was saved. An insert that was undone, never saved, or saved into a draft still
     * counted in Orphans, Inbound Counts and the Anchor Text report. The graph now
     * reflects saved, published content only: it is refreshed from post_content when
     * the post is saved (see Cron::refresh_links_for_post()).
     *
     * @return int Always 0 (no graph row is written here).
     */
    public function record_insertion( int $source_id, int $target_id, string $anchor ): int {
        do_action( "jnk_linkweave_suggestion_inserted", $source_id, $target_id, $anchor );
        return 0;
    }

    private function cosine( array $a, array $b ): float {
        $dot = 0.0;
        foreach ( $a["vector"] as $term => $score ) {
            if ( isset( $b["vector"][ $term ] ) ) {
                $dot += $score * $b["vector"][ $term ];
            }
        }
        $denom = max( $a["norm"], 1e-9 ) * max( $b["norm"], 1e-9 );
        return $dot / $denom;
    }

    /**
     * Terms two posts share, rendered as words rather than stems.
     *
     * $surface maps stem => the word that produced it in the source post. Matching
     * still happens on stems; only the label changes, so "dissolv" reads as
     * "dissolved" and "ran" as "range".
     */
    private function matched_terms( array $a, array $b, int $limit = 5, array $surface = [] ): array {
        $shared = [];
        foreach ( $a as $term => $score ) {
            if ( isset( $b[ $term ] ) ) {
                $shared[ $term ] = $score * $b[ $term ];
            }
        }
        arsort( $shared );

        $out = [];
        foreach ( array_keys( $shared ) as $stem ) {
            $word = (string) ( $surface[ $stem ] ?? $stem );
            // The label explains the match to a person, so skip words that explain
            // nothing ("two", "new", "use"). Matching itself is unchanged.
            if ( mb_strlen( $word ) < 4 || isset( self::GENERIC_LABEL_WORDS[ mb_strtolower( $word ) ] ) ) {
                continue;
            }
            // Keep the list readable: never repeat a word two stems collapsed onto.
            if ( ! in_array( $word, $out, true ) ) {
                $out[] = $word;
            }
            if ( count( $out ) >= $limit ) {
                break;
            }
        }
        return $out;
    }

    /** Everyday words that are not stopwords but say nothing about a topic. */
    private const GENERIC_LABEL_WORDS = [
        "also" => 1, "back" => 1, "best" => 1, "better" => 1, "check" => 1, "come" => 1, "done" => 1,
        "each" => 1, "easy" => 1, "even" => 1, "every" => 1, "find" => 1, "first" => 1, "good" => 1,
        "great" => 1, "help" => 1, "here" => 1, "just" => 1, "keep" => 1, "know" => 1, "last" => 1,
        "like" => 1, "little" => 1, "long" => 1, "look" => 1, "make" => 1, "many" => 1, "month" => 1,
        "more" => 1, "most" => 1, "much" => 1, "need" => 1, "next" => 1, "often" => 1, "only" => 1,
        "part" => 1, "plan" => 1, "quick" => 1, "real" => 1, "right" => 1, "same" => 1, "show" => 1,
        "small" => 1, "start" => 1, "still" => 1, "sure" => 1, "take" => 1, "than" => 1, "thing" => 1,
        "things" => 1, "time" => 1, "times" => 1, "today" => 1, "used" => 1, "uses" => 1, "using" => 1,
        "want" => 1, "week" => 1, "well" => 1, "work" => 1, "works" => 1, "year" => 1, "years" => 1,
        // Everyday verbs and filler that say nothing about what two posts share.
        "says" => 1, "said" => 1, "shows" => 1, "showed" => 1, "shown" => 1, "needs" => 1, "needed" => 1,
        "follow" => 1, "follows" => 1, "gets" => 1, "goes" => 1, "gives" => 1, "given" => 1, "makes" => 1,
        "made" => 1, "takes" => 1, "taken" => 1, "puts" => 1, "keeps" => 1, "kept" => 1, "uses" => 1,
        "looks" => 1, "tells" => 1, "told" => 1, "asks" => 1, "asked" => 1, "wants" => 1, "comes" => 1,
        "came" => 1, "means" => 1, "lets" => 1, "seems" => 1, "feels" => 1, "helps" => 1, "helped" => 1,
        "starts" => 1, "tries" => 1, "tried" => 1, "finds" => 1, "found" => 1, "thinks" => 1, "knows" => 1,
        "knew" => 1, "going" => 1, "doing" => 1, "getting" => 1, "making" => 1, "taking" => 1, "people" => 1,
        "person" => 1, "ways" => 1, "lots" => 1, "kind" => 1, "sort" => 1, "something" => 1, "anything" => 1,
        "everything" => 1, "nothing" => 1, "someone" => 1, "really" => 1, "quite" => 1, "rather" => 1,
        "maybe" => 1, "almost" => 1, "enough" => 1, "another" => 1, "other" => 1, "others" => 1,
        "either" => 1, "whole" => 1, "less" => 1, "least" => 1, "able" => 1, "around" => 1, "away" => 1,
        "without" => 1, "within" => 1, "before" => 1, "after" => 1, "again" => 1, "ever" => 1, "never" => 1,
        "always" => 1, "usually" => 1, "often" => 1, "simply" => 1, "just" => 1, "actually" => 1,
        "example" => 1, "thing" => 1, "stuff" => 1, "fine" => 1, "okay" => 1,
    ];

    private function get_vector_row( int $post_id ): ?array {
        global $wpdb;
        $table = Index_Builder::table();
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT post_id, post_type, vector, vector_norm FROM %i WHERE post_id = %d", $table, $post_id ),
            ARRAY_A
        );
        if ( ! $row ) {
            return null;
        }
        $vector = json_decode( (string) $row["vector"], true );
        if ( ! is_array( $vector ) ) {
            return null;
        }
        return [
            "post_id"   => (int) $row["post_id"],
            "post_type" => (string) $row["post_type"],
            "vector"    => $vector,
            "norm"      => (float) $row["vector_norm"],
        ];
    }

    private function fetch_candidates( int $exclude_id, string $post_type ): array {
        global $wpdb;
        $table = Index_Builder::table();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, vector, vector_norm, word_count FROM %i WHERE post_id != %d AND post_type = %s",
                $table,
                $exclude_id,
                $post_type
            ),
            ARRAY_A
        );
        $out = [];
        foreach ( $rows as $row ) {
            $vector = json_decode( (string) $row["vector"], true );
            if ( ! is_array( $vector ) ) {
                continue;
            }
            $out[] = [
                "post_id" => (int) $row["post_id"],
                "vector"  => $vector,
                "norm"    => (float) $row["vector_norm"],
                "words"   => (int) $row["word_count"],
            ];
        }
        return $out;
    }

    private function orphan_ids(): array {
        global $wpdb;
        $links = $wpdb->prefix . "jnk_linkweave_links";
        $ids   = $wpdb->get_col( $wpdb->prepare(
            "SELECT i.post_id FROM %i i
             LEFT JOIN %i l ON l.target_post_id = i.post_id
             WHERE l.id IS NULL",
            Index_Builder::table(),
            $links
        ) );
        return array_flip( array_map( "intval", (array) $ids ) );
    }

    private function already_linked_targets( int $source_id ): array {
        global $wpdb;
        $links = $wpdb->prefix . "jnk_linkweave_links";
        $ids   = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT target_post_id FROM %i WHERE source_post_id = %d",
            $links,
            $source_id
        ) );
        return array_flip( array_map( "intval", (array) $ids ) );
    }

    private function hydrate( array $scored ): array {
        if ( empty( $scored ) ) {
            return [];
        }
        $ids   = array_map( static fn( $s ) => (int) $s["target_id"], $scored );
        $posts = get_posts( [
            "post__in"       => $ids,
            "post_type"      => "any",
            "post_status"    => "publish",
            "posts_per_page" => count( $ids ),
            "orderby"        => "post__in",
        ] );
        $by_id = [];
        foreach ( $posts as $p ) {
            $by_id[ $p->ID ] = $p;
        }
        $out = [];
        foreach ( $scored as $s ) {
            $p = $by_id[ $s["target_id"] ] ?? null;
            if ( ! $p ) {
                continue;
            }
            $out[] = [
                "target_id"     => (int) $p->ID,
                "title"         => get_the_title( $p ),
                "url"           => get_permalink( $p ),
                // Pass a literal ellipsis rather than letting wp_trim_words() use its
                // default "&hellip;" — the editor sidebar renders this as React text,
                // which never decodes HTML entities, so the default leaks the raw
                // "&hellip;" into the UI.
                "excerpt"       => wp_trim_words( wp_strip_all_tags( $p->post_excerpt ?: $p->post_content ), 30, '…' ),
                "score"         => round( (float) $s["score"], 4 ),
                "matched_terms" => $s["matched_terms"] ?? [],
            ];
        }
        return $out;
    }

    private function default_mode(): string {
        $opts = get_option( "jnk_linkweave_settings", [] );
        return ( ( $opts["llm_mode"] ?? "off" ) !== "off" ) ? "llm" : "tfidf";
    }

    /**
     * The ranking a response was actually produced with. "tfidf" used to be the
     * label for every non-LLM request, so a BM25 site was told its list was TF-IDF.
     */
    private function reported_mode( string $mode ): string {
        // "llm" only when something is actually there to re-rank. With Pro locked
        // (licence lapsed) the setting may still say AI while no re-ranker runs.
        return ( "llm" === $mode && has_filter( "jnk_linkweave_rerank" ) ) ? "llm" : $this->ranking_mode();
    }

    /** Base ranking function: "bm25" (default) or "tfidf" (cosine). */
    private function ranking_mode(): string {
        $opts = get_option( "jnk_linkweave_settings", [] );
        $mode = (string) ( $opts["ranking"] ?? "bm25" );
        return in_array( $mode, [ "bm25", "tfidf" ], true ) ? $mode : "bm25";
    }

    private function cache_key( int $post_id, string $modified_gmt, array $args ): string {
        // Extension point: premium ranking signals (GSC / focus-keyword boosts)
        // change scores without changing the post's modified time, so they fold a
        // "salt" in here and bump it on import/settings change to invalidate the
        // 6h suggestion cache immediately. Empty in the free build — no effect.
        $salt = (string) apply_filters( "jnk_linkweave_suggestion_cache_salt", "" );
        // Include the ranking mode so switching BM25 <-> TF-IDF invalidates cached
        // suggestions immediately instead of after the 6h TTL.
        $salt .= "|" . $this->ranking_mode();
        // Index and link-graph changes (a rebuild, another post indexed, a link saved)
        // change the candidates and the already-linked penalty without touching this
        // post, so a list cached before them was served for up to 6 hours.
        $salt .= "|g" . (int) get_option( "jnk_linkweave_graph_version", 0 );
        // A plugin update can change what a cached list shows (labels, fields), so
        // lists cached by the previous version are never served after an update.
        $salt .= "|v" . ( defined( "JNK_LINKWEAVE_VERSION" ) ? JNK_LINKWEAVE_VERSION : "" );
        return "jnk_linkweave_sug_" . $post_id . "_" . substr( hash( "sha256", $modified_gmt . wp_json_encode( $args ) . $salt ), 0, 16 );
    }

    private function empty_response( int $post_id, string $mode ): array {
        return [
            "post_id"      => $post_id,
            "mode"         => $this->reported_mode( $mode ),
            "suggestions"  => [],
            "generated_at" => gmdate( "c" ),
            "cached"       => false,
            "reason"       => $this->diagnose( $post_id ),
        ];
    }

    /**
     * Explain why a post has no suggestions.
     *
     * The editor sidebar used to assume one cause — "the index has not been built"
     * — and offered a Build-the-index button for every empty result. On a site whose
     * index is already complete that advice is wrong and the button cannot help, so a
     * post that is simply too short to index sent people round a loop. Return the
     * actual cause so the sidebar can say something true.
     *
     * @return array{code:string, words:int, min_words:int, indexed:int}
     */
    private function diagnose( int $post_id ): array {
        global $wpdb;

        $opts      = get_option( "jnk_linkweave_settings", [] );
        $min_words = (int) apply_filters(
            "jnk_linkweave_min_post_length",
            (int) ( $opts["min_post_length"] ?? 40 )
        );
        $table   = $wpdb->prefix . "jnk_linkweave_index";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom index table; count is cheap and uncached by design.
        $indexed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        $out = [ "code" => "not_indexed", "words" => 0, "min_words" => $min_words, "indexed" => $indexed ];

        if ( 0 === $indexed ) {
            $out["code"] = "index_empty";
            return $out;
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return $out;
        }

        $post_types = (array) apply_filters(
            "jnk_linkweave_suggestion_post_types",
            $opts["post_types"] ?? [ "post", "page" ]
        );
        if ( ! in_array( $post->post_type, $post_types, true ) ) {
            $out["code"] = "type_excluded";
            return $out;
        }

        $tokens        = Tokenizer::tokenize( wp_strip_all_tags( (string) $post->post_content ), (string) ( $opts["language"] ?? "en" ) );
        $out["words"]  = count( $tokens );

        // Drafts, pending and scheduled posts are indexed when they are published, so
        // "normally picked up within a minute" was untrue for them.
        if ( "publish" !== $post->post_status ) {
            $out["code"] = $out["words"] < $min_words ? "too_short" : "not_published";
            return $out;
        }

        if ( $out["words"] < $min_words ) {
            $out["code"] = "too_short";
        }

        return $out;
    }
}
