<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;

class Tokenizer {

    /**
     * Scripts written without spaces between words, or with one syllable per
     * character: Chinese (Han), Japanese (Han, Hiragana, Katakana) and Korean
     * (Hangul). Also the marks that sit inside their words: 々 〆 and the
     * Katakana long-vowel mark ー (Unicode files these under "Common").
     */
    public const CJK_CLASS = "\\p{Han}\\p{Hiragana}\\p{Katakana}\\p{Hangul}\\x{3005}\\x{3006}\\x{30FC}";

    /**
     * Scripts whose vowels are signs attached to a letter (Devanagari, Bengali,
     * Gurmukhi, Gujarati, Tamil, Telugu, Kannada, Malayalam, Sinhala, Thai and
     * neighbours). A word in them is a few letters plus marks, so "घर" (home)
     * is two characters long and a real word.
     */
    public const INDIC_CLASS = "\\p{Devanagari}\\p{Bengali}\\p{Gurmukhi}\\p{Gujarati}\\p{Oriya}\\p{Tamil}\\p{Telugu}\\p{Kannada}\\p{Malayalam}\\p{Sinhala}\\p{Thai}\\p{Lao}\\p{Khmer}\\p{Myanmar}\\p{Tibetan}";

    /**
     * Convert raw post content (HTML/blocks/shortcodes) into a list of normalized tokens.
     *
     * Words in Latin, Cyrillic, Arabic, Devanagari and other spaced scripts are
     * split on spaces and punctuation, stemmed (English) and kept at 3 to 40
     * characters. Runs of Chinese, Japanese or Korean characters become
     * overlapping two-character pieces instead ("내부링크" -> 내부, 부링, 링크):
     * there are no spaces to split on in Chinese and Japanese, and most Korean
     * nouns are two syllables long, so the old 3-character floor dropped them.
     *
     * @return string[] Ordered list of stemmed tokens with stopwords removed.
     */
    public static function tokenize( string $content, string $language = "en" ): array {
        $tokens = self::words( $content, $language, false );
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
        $counts = [];
        foreach ( self::words( $content, $language, true ) as [ $token, $stem ] ) {
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

    /**
     * How many words $tokens stand for, for the "too short to index" minimum.
     * A two-character Chinese, Japanese or Korean piece counts as half a word:
     * a run of n characters gives n - 1 pieces, and words in these languages
     * average about two characters (a Korean word with its ending, about three).
     */
    public static function word_count( array $tokens ): int {
        if ( ! self::is_cjk( implode( " ", $tokens ) ) ) {
            return count( $tokens );
        }
        $words = 0;
        $cjk   = 0;
        foreach ( $tokens as $token ) {
            if ( self::is_cjk( (string) $token ) ) {
                $cjk++;
            } else {
                $words++;
            }
        }
        return $words + (int) ceil( $cjk / 2 );
    }

    /** True when $text contains a Chinese, Japanese or Korean character. */
    public static function is_cjk( string $text ): bool {
        return 1 === preg_match( "/[" . self::CJK_CLASS . "]/u", $text );
    }

    /** True when $text contains Chinese, Japanese, Korean or Indic script. */
    public static function is_cjk_or_indic( string $text ): bool {
        return 1 === preg_match( "/[" . self::CJK_CLASS . self::INDIC_CLASS . "]/u", $text );
    }

    /**
     * Length for "is this phrase long enough to mean something" checks. A
     * Chinese, Japanese or Korean character carries about as much as two Latin
     * letters (most Korean nouns are two syllables), so it counts twice.
     */
    public static function text_weight( string $text ): int {
        $len = mb_strlen( $text, "UTF-8" );
        if ( ! self::is_cjk( $text ) ) {
            return $len;
        }
        return $len + (int) preg_match_all( "/[" . self::CJK_CLASS . "]/u", $text );
    }

    /**
     * The Chinese, Japanese and Korean runs of $content, lowercased, each as a
     * list of characters. Used to turn the two-character pieces two posts share
     * back into the longer words they came from, for display.
     *
     * @return array<int,string[]>
     */
    public static function cjk_runs( string $content ): array {
        if ( ! self::is_cjk( $content ) ) {
            return [];
        }
        $text = self::normalise( $content );
        if ( ! self::is_cjk( $text ) ) {
            return [];
        }
        preg_match_all( "/[" . self::CJK_CLASS . "]{2,}/u", $text, $m );
        $runs = [];
        foreach ( array_unique( $m[0] ) as $run ) {
            $runs[] = (array) preg_split( "//u", $run, -1, PREG_SPLIT_NO_EMPTY );
        }
        return $runs;
    }

    /**
     * Readable labels for shared two-character pieces: each piece maps to the
     * longest stretch of $runs in which every neighbouring pair is shared, so
     * 내부 + 부링 + 링크 shows as "내부링크" rather than three fragments.
     *
     * @param array<int,string[]> $runs   from cjk_runs()
     * @param array<string,mixed> $shared pieces both posts contain (keys used)
     * @return array<string,string> piece => label
     */
    public static function cjk_labels( array $runs, array $shared ): array {
        $labels = [];
        foreach ( $runs as $chars ) {
            $n = count( $chars );
            $i = 0;
            while ( $i < $n - 1 ) {
                if ( ! isset( $shared[ $chars[ $i ] . $chars[ $i + 1 ] ] ) ) {
                    $i++;
                    continue;
                }
                $j = $i + 1;
                while ( $j < $n - 1 && isset( $shared[ $chars[ $j ] . $chars[ $j + 1 ] ] ) ) {
                    $j++;
                }
                $label = self::trim_kana( implode( "", array_slice( $chars, $i, $j - $i + 1 ) ) );
                for ( $k = $i; $k < $j; $k++ ) {
                    $piece = $chars[ $k ] . $chars[ $k + 1 ];
                    if ( ! isset( $labels[ $piece ] ) || mb_strlen( $labels[ $piece ] ) < mb_strlen( $label ) ) {
                        $labels[ $piece ] = $label;
                    }
                }
                $i = $j;
            }
        }
        return $labels;
    }

    /**
     * A Japanese label without the particles and endings glued to its ends
     * ("の記事から" -> "記事", "ページの" -> "ページ"). Kept as it is when that
     * would leave less than two characters (a word written in Hiragana).
     */
    private static function trim_kana( string $label ): string {
        $trimmed = (string) preg_replace( "/^\\p{Hiragana}+|\\p{Hiragana}+$/u", "", $label );
        return mb_strlen( $trimmed ) >= 2 ? $trimmed : $label;
    }

    /**
     * Every kept word of $content, in order: its stem, or with $pairs the pair
     * [ word as written (lowercased), stem ].
     *
     * @return array<int,string|array{0:string,1:string}>
     */
    private static function words( string $content, string $language, bool $pairs ): array {
        $text = self::normalise( $content );

        // \p{M}: a vowel sign or virama is part of its word. Splitting on them
        // cut "देखें" (watch) into "द", "ख" and "ऑनलाइन" into "ऑनल" and "इन".
        $raw = preg_split( "/[^\\p{L}\\p{M}\\p{N}]+/u", $text, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $raw ) ) {
            return [];
        }

        $ignored    = Stopwords::ignored_stems( $language );
        $cjk_ignore = null;
        $has_cjk    = self::is_cjk( $text ); // one check for the whole text keeps other languages as fast as before
        $out        = [];
        foreach ( $raw as $token ) {
            if ( ! $has_cjk || ! self::is_cjk( $token ) ) {
                $stem = self::keep_word( $token, $language, $ignored );
                if ( "" !== $stem ) {
                    $out[] = $pairs ? [ $token, $stem ] : $stem;
                }
                continue;
            }
            if ( null === $cjk_ignore ) {
                $cjk_ignore = array_values( array_filter( Stopwords::custom_words(), [ __CLASS__, "is_cjk" ] ) );
                usort( $cjk_ignore, static fn( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) );
            }
            // Latin letters glued to CJK ("wordpress플러그인") are a word of their own.
            $parts = preg_split( "/([" . self::CJK_CLASS . "]+)/u", $token, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
            foreach ( (array) $parts as $part ) {
                if ( ! self::is_cjk( $part ) ) {
                    $stem = self::keep_word( $part, $language, $ignored );
                    if ( "" !== $stem ) {
                        $out[] = $pairs ? [ $part, $stem ] : $stem;
                    }
                    continue;
                }
                // The owner's "Words to ignore" are cut out of the run first, so
                // their pieces go but the same characters in other words stay.
                $runs = $cjk_ignore ? explode( "\0", str_replace( $cjk_ignore, "\0", $part ) ) : [ $part ];
                foreach ( $runs as $run ) {
                    foreach ( self::bigrams( $run ) as $piece ) {
                        if ( ! Stopwords::is_stopword( $piece, $language ) ) {
                            $out[] = $pairs ? [ $piece, $piece ] : $piece;
                        }
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Overlapping two-character pieces of a run: "검색엔진" -> 검색, 색엔, 엔진.
     * A run of one character gives nothing: alone, it is almost always a
     * particle or a counter (の, を, 수, 것), not a topic.
     *
     * @return string[]
     */
    private static function bigrams( string $run ): array {
        $chars = preg_split( "//u", $run, -1, PREG_SPLIT_NO_EMPTY );
        $n     = is_array( $chars ) ? count( $chars ) : 0;
        $out   = [];
        for ( $i = 0; $i < $n - 1; $i++ ) {
            $out[] = $chars[ $i ] . $chars[ $i + 1 ];
        }
        return $out;
    }

    /** The stem of a spaced-script word, or "" when the word is not kept. */
    private static function keep_word( string $token, string $language, array $ignored ): string {
        $len = function_exists( "mb_strlen" ) ? mb_strlen( $token, "UTF-8" ) : strlen( $token );
        // Two characters are enough in Indic scripts ("घर", "मैच"); their short
        // function words (के, है, से) are in the stopword list instead.
        $min = ( $len === 2 && preg_match( "/[" . self::INDIC_CLASS . "]/u", $token ) ) ? 2 : 3;
        if ( $len < $min || $len > 40 ) {
            return "";
        }
        if ( Stopwords::is_stopword( $token, $language ) ) {
            return "";
        }
        $stem = self::stem( $token, $language );
        if ( "" === $stem || isset( $ignored[ $stem ] ) || isset( self::HI_AUXILIARY_STEMS[ $stem ] ) ) {
            return "";
        }
        // A Hindi word whose stem is a function word's ("आपने" -> "आप").
        if ( $stem !== $token && "\xE0" === substr( $stem, 0, 1 ) && isset( Stopwords::hindi_function_stems()[ $stem ] ) ) {
            return "";
        }
        return $stem;
    }

    /** Plain, lowercased text; full-width Latin letters and digits folded to ASCII. */
    private static function normalise( string $content ): string {
        $text = self::to_plain_text( $content );
        if ( preg_match( "/[\\x{FF01}-\\x{FF5E}]/u", $text ) ) {
            $text = (string) preg_replace_callback(
                "/[\\x{FF01}-\\x{FF5E}]/u",
                static fn( $m ) => chr( mb_ord( $m[0], "UTF-8" ) - 0xFEE0 ),
                $text
            );
        }
        return function_exists( "mb_strtolower" )
            ? mb_strtolower( $text, "UTF-8" )
            : strtolower( $text );
    }

    private static function to_plain_text( string $content ): string {
        if ( function_exists( "do_blocks" ) && false !== strpos( $content, "<!-- wp:" ) ) {
            $content = do_blocks( $content );
        }
        $content = strip_shortcodes( $content );
        // A heading, paragraph, list item, cell or line break ends a word:
        // "WordPress</h2><p>Caching" was read as one word "wordpresscaching".
        if ( false !== strpos( $content, "<" ) ) {
            $content = (string) preg_replace( "~<(?=/?(?:" . self::BLOCK_TAGS . "|br|hr|img)\\b)~i", " <", $content );
        }
        $content = wp_strip_all_tags( $content, true );
        $content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, "UTF-8" );
        // One spelling for letters that Unicode can write two ways (ज़ as one
        // code point or as ज plus a nukta), so both forms are the same word.
        if ( class_exists( "Normalizer" ) && preg_match( "/[" . self::INDIC_CLASS . "]/u", $content ) ) {
            $content = (string) \Normalizer::normalize( $content, \Normalizer::FORM_C );
        }
        return $content;
    }

    /**
     * Light Hindi stemmer (Ramanathan and Rao, as in Lucene's HindiStemmer):
     * drop the longest known inflection ending, keeping at least two
     * characters. The nukta and the chandrabindu are folded first, since
     * writers use them inconsistently (ज़/ज, कहाँ/कहां).
     */
    private static function stem_hindi( string $token ): string {
        $token = str_replace( [ "\u{093C}", "\u{0901}", "\u{200C}", "\u{200D}" ], [ "", "\u{0902}", "", "" ], $token );
        $len   = mb_strlen( $token, "UTF-8" );
        foreach ( self::HI_SUFFIXES as $suffix ) {
            $sl = mb_strlen( $suffix, "UTF-8" );
            if ( $len > $sl + 1 && str_ends_with( $token, $suffix ) ) {
                return (string) mb_substr( $token, 0, $len - $sl, "UTF-8" );
            }
        }
        return $token;
    }

    /**
     * Stems of Hindi helper verbs in all their forms: do, be, stay, go, can,
     * take, give (करने, करते, होता, रहा, जाता, सकते...). Like "do", "be" and
     * "can" in the English stopword list.
     */
    private const HI_AUXILIARY_STEMS = [ "कर" => 1, "हो" => 1, "रह" => 1, "जा" => 1, "सक" => 1, "ले" => 1, "दे" => 1 ];

    /** Hindi inflection endings, longest first (with the nukta and chandrabindu folded). */
    private const HI_SUFFIXES = [
        "ाएंगी", "ाएंगे", "ाऊंगी", "ाऊंगा", "ाइयां", "ाइयों",
        "ाएगी", "ाएगा", "ाओगी", "ाओगे", "एंगी", "ेंगी", "एंगे", "ेंगे", "ूंगी", "ूंगा", "ातीं", "नाओं", "नाएं", "ताओं", "ताएं", "ियां", "ियों",
        "ाकर", "ाइए", "ाईं", "ाया", "ेगी", "ेगा", "ोगी", "ोगे", "ाने", "ाना", "ाते", "ाती", "ाता", "तीं", "ाओं", "ाएं", "ुओं", "ुएं", "ुआं",
        "कर", "ाओ", "िए", "ाई", "ाए", "ने", "नी", "ना", "ते", "ीं", "ती", "ता", "ां", "ों", "ें",
        "ो", "े", "ू", "ु", "ी", "ि", "ा",
    ];

    /** Elements whose edges are word breaks. */
    private const BLOCK_TAGS = "p|div|h[1-6]|li|ul|ol|dl|dt|dd|tr|td|th|table|thead|tbody|blockquote|pre|section|article|header|footer|aside|nav|figure|figcaption|address";

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
        // Hindi is stemmed whatever the language setting says (it is "en" on
        // Hindi sites too): "खिलाड़ी" and "खिलाड़ियों" (player, players) are one word.
        // Devanagari letters are three bytes starting 0xE0: check that byte first
        // so English words skip the regex.
        if ( "\xE0" === substr( $token, 0, 1 ) && preg_match( "/^\\p{Devanagari}/u", $token ) ) {
            return self::stem_hindi( $token );
        }
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
