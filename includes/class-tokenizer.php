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
     * Scripts written without spaces between words that ICU can cut into words
     * with its dictionaries: Thai, Lao, Khmer and Myanmar. A Thai sentence is one
     * run of letters, so splitting on spaces gave one "word" per sentence, usually
     * over 40 characters and dropped: a Thai post had no words at all and was
     * never indexed (QA 29 Oct 2026).
     */
    public const SEA_CLASS = "\\p{Thai}\\p{Lao}\\p{Khmer}\\p{Myanmar}";

    /** True when $text contains Thai, Lao, Khmer or Myanmar script. */
    public static function is_sea( string $text ): bool {
        return 1 === preg_match( "/[" . self::SEA_CLASS . "]/u", $text );
    }

    /** Can this PHP cut Thai, Lao, Khmer and Myanmar text into words (intl)? */
    public static function can_segment_sea(): bool {
        return (bool) apply_filters( "jnk_linkweave_segment_sea", class_exists( "IntlBreakIterator" ) );
    }

    /**
     * The words of a Thai (Lao, Khmer, Myanmar) run, by ICU's dictionary word
     * breaker: "วิธีทำต้มยำกุ้ง" -> วิธี, ทำ, ต้มยำ, กุ้ง. Null without intl.
     *
     * @return string[]|null
     */
    public static function sea_words( string $run ): ?array {
        static $breaker = null;
        if ( null === $breaker ) {
            $breaker = self::can_segment_sea() ? \IntlBreakIterator::createWordInstance( "th" ) : false;
            $breaker = $breaker ?: false;
        }
        if ( ! $breaker || ! $breaker->setText( $run ) ) {
            return null;
        }
        $words = [];
        $start = $breaker->first();
        for ( $end = $breaker->next(); \IntlBreakIterator::DONE !== $end; $start = $end, $end = $breaker->next() ) {
            // Rule status 0 is spaces and punctuation; numbers and letters are 100 and up.
            if ( $breaker->getRuleStatus() >= 100 ) {
                $words[] = substr( $run, $start, $end - $start );
            }
        }
        return $words;
    }

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

    /**
     * Is this Vietnamese text? A letter only Vietnamese uses (ă đ ơ ư, or a vowel
     * with a dot below or a hook above). One is enough: a search query such as
     * "du lịch sa pa" has a single one.
     */
    public static function is_vietnamese( string $text ): bool {
        return 1 === preg_match( "/[ăđơưĂĐƠƯ\\x{1EA0}-\\x{1EF9}]/u", $text );
    }

    /**
     * Is this Persian text? A letter Persian has and Arabic does not: پ چ ژ گ,
     * or the Persian ک, ی and ۀ (Arabic writes ك and ي). Urdu has them too.
     * Persian typed on an Arabic keyboard may have none of them, so also the
     * words "را" (object marker) and "است" (is), which are not Arabic words.
     */
    public static function is_persian( string $text ): bool {
        return 1 === preg_match( "/[\\x{067E}\\x{0686}\\x{0698}\\x{06AF}\\x{06A9}\\x{06CC}\\x{06C0}]|(?<![\\p{L}\\p{M}])(?:را|است)(?![\\p{L}\\p{M}])/u", $text );
    }

    /**
     * Persian typed on an Arabic keyboard, in Persian letters: ي as ی, ك as ک,
     * and the ezafe ۀ (or ه with a hamza above) as ه. "كتاب" and "کتاب" are the
     * same word to a reader; a Persian post written with Arabic letters shared
     * no word with the same text in Persian letters (QA 29 Oct 2026). Arabic
     * text is folded the same way on both sides, so it still matches itself.
     * ى (alef maqsura) and ة (teh marbuta) are left alone: in Arabic they are
     * other letters ("على" on, "علي" Ali).
     */
    public static function fold_persian_letters( string $text ): string {
        // Every one of these letters starts with byte 0xD9 or 0xDB.
        if ( false === strpbrk( $text, "\xD9\xDB" ) ) {
            return $text;
        }
        return strtr( $text, [ "ي" => "ی", "ك" => "ک", "ۀ" => "ه", "ه\u{0654}" => "ه" ] );
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
        $stems  = null;
        foreach ( $runs as $chars ) {
            $n      = count( $chars );
            $i      = 0;
            $hangul = 1 === preg_match( "/^\\p{Hangul}/u", (string) ( $chars[0] ?? "" ) );
            while ( $i < $n - 1 ) {
                if ( ! isset( $shared[ $chars[ $i ] . $chars[ $i + 1 ] ] ) ) {
                    $i++;
                    continue;
                }
                $j = $i + 1;
                while ( $j < $n - 1 && isset( $shared[ $chars[ $j ] . $chars[ $j + 1 ] ] ) ) {
                    $j++;
                }
                if ( $hangul ) {
                    // A Korean word is spaced, with its particle glued on: show the
                    // word without the particle ("기준금리의" -> "기준금리"), and only
                    // when the shared stretch is that word. A stretch from the middle
                    // of a word ("레이" in 인플레이션, "면증" in 불면증) or one that
                    // stops inside it ("고혈" of 고혈압) names nothing to a reader.
                    $stems = $stems ?? self::korean_stems( $runs );
                    $word  = self::korean_stem( implode( "", $chars ), $stems );
                    if ( 0 === $i && $j + 1 < mb_strlen( $word ) && in_array( implode( "", array_slice( $chars, $j + 1 ) ), self::KO_ENDINGS, true ) ) {
                        // The other post has the word with another particle
                        // ("불면증은" / "불면증이"): the shared stretch is the word.
                        $word = implode( "", array_slice( $chars, 0, $j + 1 ) );
                    }
                    if ( 0 === $i && $j + 1 >= mb_strlen( $word ) ) {
                        for ( $k = $i; $k < $j; $k++ ) {
                            $piece = $chars[ $k ] . $chars[ $k + 1 ];
                            if ( ! isset( $labels[ $piece ] ) || mb_strlen( $labels[ $piece ] ) < mb_strlen( $word ) ) {
                                $labels[ $piece ] = $word;
                            }
                        }
                    }
                    $i = $j;
                    continue;
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

    /** Particles and endings glued to the end of a Korean word, longest first. */
    private const KO_ENDINGS = [
        "에서는", "으로는", "에게서", "이라는", "이라고", "에서", "에게", "으로", "까지", "부터", "보다", "처럼",
        "이다", "이며", "이고", "이나", "하는", "하여", "하고", "하며", "했다", "한다", "하게", "하기", "되는",
        "되어", "된다", "들의", "들은", "들이", "들을", "의", "에", "은", "는", "이", "가", "을", "를", "와",
        "과", "도", "로", "만", "들",
    ];

    /**
     * Every way the words of $runs split into a stem and an ending, with how the
     * stem was seen: stem => [ ending => true ], "" for the bare word.
     *
     * @param array<int,string[]> $runs from cjk_runs()
     * @return array<string,array<string,bool>>
     */
    private static function korean_stems( array $runs ): array {
        $stems = [];
        foreach ( $runs as $chars ) {
            $word = implode( "", $chars );
            if ( 1 !== preg_match( "/^\\p{Hangul}+$/u", $word ) ) {
                continue;
            }
            $stems[ $word ][""] = true;
            foreach ( self::KO_ENDINGS as $ending ) {
                $stem_len = mb_strlen( $word ) - mb_strlen( $ending );
                if ( $stem_len >= 2 && str_ends_with( $word, $ending ) ) {
                    $stems[ mb_substr( $word, 0, $stem_len ) ][ $ending ] = true;
                }
            }
        }
        return $stems;
    }

    /**
     * $word without its particle or ending, when the text shows that stem on its
     * own or with another ending ("기준금리의" next to "기준금리는"). Otherwise the
     * word as written: "고양이" ends in 이 but no "고양" appears anywhere.
     *
     * @param array<string,array<string,bool>> $stems from korean_stems()
     */
    private static function korean_stem( string $word, array $stems ): string {
        foreach ( self::KO_ENDINGS as $ending ) {
            $stem_len = mb_strlen( $word ) - mb_strlen( $ending );
            if ( $stem_len < 2 || ! str_ends_with( $word, $ending ) ) {
                continue;
            }
            $seen = $stems[ mb_substr( $word, 0, $stem_len ) ] ?? [];
            if ( isset( $seen[""] ) || count( $seen ) >= 2 ) {
                return mb_substr( $word, 0, $stem_len );
            }
        }
        return $word;
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
        $has_sea    = self::is_sea( $text ) && self::can_segment_sea();
        $vi         = self::is_vietnamese( $text );
        $fa         = self::is_persian( $text );
        $out        = [];
        foreach ( $raw as $token ) {
            if ( $has_sea && self::is_sea( $token ) ) {
                // Latin letters glued to Thai ("iphoneรุ่นใหม่") are a word of their own.
                $parts = preg_split( "/([" . self::SEA_CLASS . "]+)/u", $token, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
                foreach ( (array) $parts as $part ) {
                    foreach ( self::is_sea( $part ) ? (array) self::sea_words( $part ) : [ $part ] as $word ) {
                        $stem = self::keep_word( (string) $word, $language, $ignored );
                        if ( "" !== $stem ) {
                            $out[] = $pairs ? [ $word, $stem ] : $stem;
                        }
                    }
                }
                continue;
            }
            if ( ! $has_cjk || ! self::is_cjk( $token ) ) {
                $stem = self::keep_word( $token, $language, $ignored, $vi, $fa );
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
    private static function keep_word( string $token, string $language, array $ignored, bool $vietnamese = false, bool $persian = false ): string {
        $len = function_exists( "mb_strlen" ) ? mb_strlen( $token, "UTF-8" ) : strlen( $token );
        // Two characters are enough in Indic scripts ("घर", "मैच"); their short
        // function words (के, है, से) are in the stopword list instead. Also in
        // Vietnamese, written one syllable per word: "Sa Pa", "Hạ Long", "bò"
        // (beef), "gà" (chicken) lost every syllable (QA 29 Oct 2026).
        $min = ( $len === 2 && ( $vietnamese ? preg_match( "/^[\\p{Latin}\\p{M}]+$/u", $token ) : preg_match( "/[" . self::INDIC_CLASS . "]/u", $token ) ) ) ? 2 : 3;
        if ( $vietnamese && Stopwords::is_vietnamese_function_word( $token ) ) {
            return "";
        }
        if ( $persian ? Stopwords::is_persian_function_word( $token ) : ( self::is_arabic_script_word( $token ) && Stopwords::is_arabic_script_function_word( $token ) ) ) {
            return "";
        }
        if ( $len < $min || $len > 40 ) {
            return "";
        }
        if ( Stopwords::is_stopword( $token, $language ) ) {
            return "";
        }
        $stem = self::stem( $token, $language, $persian );
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
        return self::lower( $text );
    }

    /**
     * Lowercase for matching. mb_strtolower() turns the Turkish capital "İ" into
     * "i" plus a combining dot, so "İstanbul" and "istanbul" were two different
     * words (QA 29 Oct 2026); the dot is dropped here.
     */
    public static function lower( string $text ): string {
        $text = function_exists( "mb_strtolower" ) ? mb_strtolower( $text, "UTF-8" ) : strtolower( $text );
        return false !== strpos( $text, "i\u{0307}" ) ? str_replace( "i\u{0307}", "i", $text ) : $text;
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
        return self::unify_spelling( $content );
    }

    /**
     * One spelling for letters that Unicode can write two ways (ज़ as one code
     * point or as ज plus a nukta), so both forms are the same word. Anything
     * compared with tokenized text (the owner's "Words to ignore") goes through
     * this too.
     */
    public static function unify_spelling( string $text ): string {
        // Any script, not only Indic: Vietnamese pasted from a Mac arrives
        // decomposed ("ở" as o + horn + hook), and never matched the same word
        // typed elsewhere (QA 29 Oct 2026).
        if ( class_exists( "Normalizer" ) && preg_match( "/[^\\x00-\\x7F]/", $text ) && ! \Normalizer::isNormalized( $text, \Normalizer::FORM_C ) ) {
            $normal = \Normalizer::normalize( $text, \Normalizer::FORM_C );
            if ( is_string( $normal ) ) {
                return $normal;
            }
        }
        return $text;
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

    /**
     * One word's stem, the way the index stores it. An Arabic-script word is
     * stemmed as Arabic unless it is Persian ($persian, or a Persian-only letter).
     */
    public static function stem_word( string $word, string $language = "en", ?bool $persian = null ): string {
        $word = self::lower( self::unify_spelling( trim( $word ) ) );
        return "" === $word ? "" : self::stem( $word, $language, $persian ?? self::is_persian( $word ) );
    }

    /**
     * Light Arabic stem (in the spirit of Larkey's light10, no root extraction):
     * the definite article "ال" and the article with a one-letter word glued
     * before it (وال, بال, كال, فال, لل) come off the front, then one common
     * ending (ات, ون, ين, ية, ها, ة, ه, ي) comes off the back when at least three
     * letters stay (four for ون and ين: "بحرين" is not "بحر" plus an ending).
     * Short vowel marks and the tatweel go, and أ إ آ ٱ are read as ا, since
     * writers use them inconsistently. "الشبكة", "شبكة" and "بالشبكات" are one
     * word; an Arabic post that wrote "الشبكة" shared nothing with one that wrote
     * "شبكة" (QA 29 Oct 2026). $token is already in folded letters (ی, ک).
     */
    private static function stem_arabic( string $token ): string {
        $token = (string) preg_replace( "/[\\x{064B}-\\x{065F}\\x{0670}\\x{0640}]/u", "", $token );
        $token = strtr( $token, [ "أ" => "ا", "إ" => "ا", "آ" => "ا", "ٱ" => "ا" ] );
        $len   = mb_strlen( $token, "UTF-8" );
        foreach ( [ "وال", "بال", "کال", "فال", "لل", "ال" ] as $prefix ) {
            $pl = mb_strlen( $prefix, "UTF-8" );
            if ( $len - $pl >= 2 && str_starts_with( $token, $prefix ) ) {
                $token = (string) mb_substr( $token, $pl, null, "UTF-8" );
                $len  -= $pl;
                break;
            }
        }
        foreach ( self::AR_SUFFIXES as $suffix => $keep ) {
            $sl = mb_strlen( (string) $suffix, "UTF-8" );
            if ( $len - $sl >= $keep && str_ends_with( $token, (string) $suffix ) ) {
                return (string) mb_substr( $token, 0, $len - $sl, "UTF-8" );
            }
        }
        return $token;
    }

    /** Arabic endings, longest first, with the letters the stem must keep (ي as ی). */
    private const AR_SUFFIXES = [ "ات" => 3, "ون" => 4, "ین" => 4, "یة" => 3, "ها" => 3, "ة" => 3, "ه" => 3, "ی" => 3 ];

    /** Does $token start with an Arabic-script letter (its first byte is 0xD8 to 0xDB)? */
    public static function is_arabic_script_word( string $token ): bool {
        return "" !== $token && $token[0] >= "\xD8" && $token[0] <= "\xDB";
    }

    /**
     * Light suffix-strip stemmer — collapses common English inflections without
     * the cost (or correctness) of a full Porter implementation.
     */
    private static function stem( string $token, string $language, bool $persian = false ): string {
        // Hindi is stemmed whatever the language setting says (it is "en" on
        // Hindi sites too): "खिलाड़ी" and "खिलाड़ियों" (player, players) are one word.
        // Devanagari letters are three bytes starting 0xE0: check that byte first
        // so English words skip the regex.
        if ( "\xE0" === substr( $token, 0, 1 ) && preg_match( "/^\\p{Devanagari}/u", $token ) ) {
            return self::stem_hindi( $token );
        }
        $token = self::fold_persian_letters( $token );
        // Arabic, whatever the language setting says; Persian text keeps its words.
        if ( ! $persian && self::is_arabic_script_word( $token ) ) {
            return self::stem_arabic( $token );
        }
        // Turkish dotless "ı": "ILIK" lowercases to "ilik" but is the word "ılık",
        // so the two letters are one in the stem (the word shown keeps its "ı").
        if ( false !== strpos( $token, "ı" ) ) {
            $token = str_replace( "ı", "i", $token );
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
