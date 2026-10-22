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
        $custom = [];
        $opts   = get_option( "jnk_linkweave_settings", [] );
        if ( is_array( $opts ) && ! empty( $opts["stopwords_custom"] ) && is_array( $opts["stopwords_custom"] ) ) {
            $custom = $opts["stopwords_custom"];
        }

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
