<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;

class Tokenizer {

    /**
     * Convert raw post content (HTML/blocks/shortcodes) into a list of normalized tokens.
     *
     * @return string[] Ordered list of stemmed tokens with stopwords removed.
     */
    public static function tokenize( string $content, string $language = "en" ): array {
        $text = self::to_plain_text( $content );

        $text = function_exists( "mb_strtolower" )
            ? mb_strtolower( $text, "UTF-8" )
            : strtolower( $text );

        $raw = preg_split( "/[^\\p{L}\\p{N}]+/u", $text, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $raw ) ) {
            $raw = [];
        }

        $ignored = Stopwords::ignored_stems( $language );
        $tokens  = [];
        foreach ( $raw as $token ) {
            $len = function_exists( "mb_strlen" ) ? mb_strlen( $token, "UTF-8" ) : strlen( $token );
            if ( $len < 3 || $len > 40 ) {
                continue;
            }
            if ( Stopwords::is_stopword( $token, $language ) ) {
                continue;
            }
            $stem = self::stem( $token, $language );
            if ( "" === $stem || isset( $ignored[ $stem ] ) ) {
                continue;
            }
            $tokens[] = $stem;
        }

        return apply_filters( "jnk_linkweave_tokenizer", $tokens, $content, $language );
    }

    /**
     * Map each stem back to the most common word in $content that produced it.
     *
     * The index stores stems, which is right for matching and wrong for reading:
     * showing a writer that two posts share "dissolv" and "ran" looks like a
     * rendering fault, not a keyword. Use this to display the word people actually
     * wrote while still matching on the stem.
     *
     * @return array<string,string> stem => surface form
     */
    public static function surface_forms( string $content, string $language = "en" ): array {
        $text = self::to_plain_text( $content );
        $text = function_exists( "mb_strtolower" )
            ? mb_strtolower( $text, "UTF-8" )
            : strtolower( $text );

        $raw = preg_split( "/[^\\p{L}\\p{N}]+/u", $text, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $raw ) ) {
            return [];
        }

        $ignored = Stopwords::ignored_stems( $language );
        $counts  = [];
        foreach ( $raw as $token ) {
            $len = function_exists( "mb_strlen" ) ? mb_strlen( $token, "UTF-8" ) : strlen( $token );
            if ( $len < 3 || $len > 40 ) {
                continue;
            }
            if ( Stopwords::is_stopword( $token, $language ) ) {
                continue;
            }
            $stem = self::stem( $token, $language );
            if ( "" === $stem || isset( $ignored[ $stem ] ) ) {
                continue;
            }
            if ( ! isset( $counts[ $stem ] ) ) {
                $counts[ $stem ] = [];
            }
            $counts[ $stem ][ $token ] = ( $counts[ $stem ][ $token ] ?? 0 ) + 1;
        }

        $out = [];
        foreach ( $counts as $stem => $words ) {
            arsort( $words );
            $out[ $stem ] = (string) array_key_first( $words );
        }
        return $out;
    }

    private static function to_plain_text( string $content ): string {
        if ( function_exists( "do_blocks" ) && false !== strpos( $content, "<!-- wp:" ) ) {
            $content = do_blocks( $content );
        }
        $content = strip_shortcodes( $content );
        $content = wp_strip_all_tags( $content, true );
        return html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, "UTF-8" );
    }

    /** One word's stem, the way the index stores it. */
    public static function stem_word( string $word, string $language = "en" ): string {
        $word = function_exists( "mb_strtolower" ) ? mb_strtolower( trim( $word ), "UTF-8" ) : strtolower( trim( $word ) );
        return "" === $word ? "" : self::stem( $word, $language );
    }

    /**
     * Light suffix-strip stemmer — collapses common English inflections without
     * the cost (or correctness) of a full Porter implementation.
     */
    private static function stem( string $token, string $language ): string {
        if ( "en" !== $language ) {
            return $token;
        }

        $suffixes = [ "ingly", "edly", "ies", "ied", "ing", "ses", "sed", "ly", "ed", "es", "s" ];
        // Suffixes whose removal can leave an inflection-doubled consonant
        // (run+ning, stop+ped). Only these get "undoubled" below.
        $undouble = [ "ingly" => true, "edly" => true, "ing" => true, "ed" => true ];
        foreach ( $suffixes as $suffix ) {
            $sl = strlen( $suffix );
            if ( strlen( $token ) > $sl + 2 && substr( $token, -$sl ) === $suffix ) {
                $stem = substr( $token, 0, -$sl );
                // Collapse a trailing doubled consonant left by an -ing/-ed strip so
                // inflected forms share one root (running -> runn -> run; runs -> run).
                // Skip l/s/z (and vowels) so call/calls both stay "call".
                if ( isset( $undouble[ $suffix ] ) ) {
                    $n = strlen( $stem );
                    if ( $n >= 3 && $stem[ $n - 1 ] === $stem[ $n - 2 ]
                        && false === strpos( "aeioulsz", $stem[ $n - 1 ] ) ) {
                        $stem = substr( $stem, 0, -1 );
                    }
                }
                return $stem;
            }
        }
        return $token;
    }
}
