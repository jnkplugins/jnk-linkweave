<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;

class Stopwords {

    /** @var array<string, array<string, true>> */
    private static array $cache = [];

    public static function get( string $language = "en" ): array {
        if ( isset( self::$cache[ $language ] ) ) {
            return self::$cache[ $language ];
        }

        $base   = self::base_list( $language );
        $custom = self::custom_words();

        $merged = array_merge( $base, $custom );
        $merged = apply_filters( "jnk_linkweave_stopwords", $merged, $language );

        $lookup = [];
        foreach ( $merged as $word ) {
            $word = strtolower( trim( (string) $word ) );
            if ( "" !== $word ) {
                $lookup[ $word ] = true;
            }
        }

        return self::$cache[ $language ] = $lookup;
    }

    public static function is_stopword( string $term, string $language = "en" ): bool {
        return isset( self::get( $language )[ $term ] );
    }

    /** @var array<string, array<string, true>> */
    private static array $ignored_stems = [];

    /**
     * The owner's "Words to ignore", one word per entry. An entry with spaces
     * ("watch online") is split: the index works on single words, so the whole
     * entry never matched anything (QA 27 Oct 2026).
     *
     * @return string[]
     */
    public static function custom_words(): array {
        $opts = get_option( "jnk_linkweave_settings", [] );
        if ( ! is_array( $opts ) || empty( $opts["stopwords_custom"] ) || ! is_array( $opts["stopwords_custom"] ) ) {
            return [];
        }
        $words = [];
        foreach ( $opts["stopwords_custom"] as $entry ) {
            foreach ( (array) preg_split( "/\\s+/u", mb_strtolower( trim( (string) $entry ) ), -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
                $words[] = $word;
            }
        }
        return array_values( array_unique( $words ) );
    }

    /**
     * Stems of the owner's ignored words. "watch" was dropped but "watching" and
     * "watched" stayed, because the list was checked before stemming. The
     * tokenizer also drops any word whose stem is listed here.
     *
     * @return array<string, true>
     */
    public static function ignored_stems( string $language = "en" ): array {
        if ( isset( self::$ignored_stems[ $language ] ) ) {
            return self::$ignored_stems[ $language ];
        }
        $stems = [];
        foreach ( self::custom_words() as $word ) {
            // The light stemmer does not always give a word and its plural one
            // stem ("movie", "movies" -> "mov"), so take the stems of the usual
            // endings too.
            foreach ( [ "", "s", "es", "ing", "ed" ] as $ending ) {
                $stem = Tokenizer::stem_word( $word . $ending, $language );
                if ( "" !== $stem ) {
                    $stems[ $stem ] = true;
                }
            }
        }
        return self::$ignored_stems[ $language ] = $stems;
    }

    /** Forget the per-request lists (after the settings change). */
    public static function reset(): void {
        self::$cache         = [];
        self::$ignored_stems = [];
    }

    private static function base_list( string $language ): array {
        switch ( $language ) {
            case "en":
            default:
                return [
                    "a", "about", "above", "after", "again", "against", "all", "am", "an",
                    "and", "any", "are", "aren't", "as", "at", "be", "because", "been",
                    "before", "being", "below", "between", "both", "but", "by", "can", "can't",
                    "cannot", "could", "couldn't", "did", "didn't", "do", "does", "doesn't",
                    "doing", "don't", "down", "during", "each", "few", "for", "from", "further",
                    "had", "hadn't", "has", "hasn't", "have", "haven't", "having", "he", "he'd",
                    "he'll", "he's", "her", "here", "here's", "hers", "herself", "him", "himself",
                    "his", "how", "how's", "i", "i'd", "i'll", "i'm", "i've", "if", "in", "into",
                    "is", "isn't", "it", "it's", "its", "itself", "let's", "me", "more", "most",
                    "mustn't", "my", "myself", "no", "nor", "not", "of", "off", "on", "once",
                    "only", "or", "other", "ought", "our", "ours", "ourselves", "out", "over",
                    "own", "same", "shan't", "she", "she'd", "she'll", "she's", "should",
                    "shouldn't", "so", "some", "such", "than", "that", "that's", "the", "their",
                    "theirs", "them", "themselves", "then", "there", "there's", "these", "they",
                    "they'd", "they'll", "they're", "they've", "this", "those", "through", "to",
                    "too", "under", "until", "up", "very", "was", "wasn't", "we", "we'd", "we'll",
                    "we're", "we've", "were", "weren't", "what", "what's", "when", "when's",
                    "where", "where's", "which", "while", "who", "who's", "whom", "why", "why's",
                    "will", "with", "won't", "would", "wouldn't", "you", "you'd", "you'll", "you're",
                    "you've", "your", "yours", "yourself", "yourselves",
                ];
        }
    }
}
