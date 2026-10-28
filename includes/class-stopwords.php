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
        return isset( self::get( $language )[ $term ] ) || isset( self::JA_GRAMMAR_PIECES[ $term ] ) || isset( self::HI_FUNCTION_WORDS[ $term ] );
    }

    /**
     * Hindi function words: postpositions, pronouns, forms of "to be" and "can",
     * conjunctions, question words. Hindi words used to be cut apart at their
     * vowel signs, so none of these ever reached the index; now that words stay
     * whole they would. Applies whatever the language setting is (sites set it
     * to "en"): Devanagari only occurs in Hindi, Marathi and Nepali text.
     */
    private const HI_FUNCTION_WORDS = [
        "है" => 1, "हैं" => 1, "हूँ" => 1, "हूं" => 1, "के" => 1, "की" => 1, "का" => 1, "में" => 1,
        "से" => 1, "और" => 1, "को" => 1, "पर" => 1, "भी" => 1, "यह" => 1, "वह" => 1, "ये" => 1, "वे" => 1,
        "था" => 1, "थे" => 1, "थी" => 1, "थीं" => 1, "हो" => 1, "कर" => 1, "नहीं" => 1, "लिए" => 1,
        "लिये" => 1, "एक" => 1, "तो" => 1, "ही" => 1, "ने" => 1, "कि" => 1, "जो" => 1, "इस" => 1, "उस" => 1,
        "इसे" => 1, "उसे" => 1, "इन" => 1, "उन" => 1, "इसके" => 1, "उसके" => 1, "इसकी" => 1, "उसकी" => 1,
        "इनके" => 1, "उनके" => 1, "हम" => 1, "आप" => 1, "मैं" => 1, "तुम" => 1, "अपने" => 1, "अपनी" => 1,
        "अपना" => 1, "साथ" => 1, "बाद" => 1, "तक" => 1, "कुछ" => 1, "सब" => 1, "या" => 1, "लेकिन" => 1,
        "क्या" => 1, "कैसे" => 1, "कब" => 1, "कहाँ" => 1, "कहां" => 1, "कौन" => 1, "किस" => 1, "किसी" => 1,
        "गया" => 1, "गई" => 1, "गए" => 1, "जा" => 1, "रहा" => 1, "रही" => 1, "रहे" => 1, "सकता" => 1,
        "सकती" => 1, "सकते" => 1, "होता" => 1, "होती" => 1, "होते" => 1, "जाता" => 1, "जाती" => 1,
        "जाते" => 1, "करें" => 1, "करते" => 1, "करना" => 1, "किया" => 1, "दिया" => 1, "दी" => 1, "दिए" => 1,
        "वाला" => 1, "वाली" => 1, "वाले" => 1, "बहुत" => 1, "अब" => 1, "फिर" => 1, "यहाँ" => 1, "वहाँ" => 1,
        "जब" => 1, "तब" => 1, "होने" => 1, "होगा" => 1, "होगी" => 1, "होंगे" => 1, "एवं" => 1, "तथा" => 1,
        "द्वारा" => 1, "जैसे" => 1, "जिस" => 1, "जिसे" => 1, "जिन" => 1, "जिनके" => 1, "कोई" => 1, "कई" => 1, "सबसे" => 1,
        "आपका" => 1, "आपकी" => 1, "आपके" => 1, "आपको" => 1, "उन्होंने" => 1, "उनका" => 1, "उनकी" => 1, "उनको" => 1, "इसमें" => 1, "उसमें" => 1, "मुझे" => 1, "हमें" => 1, "हमारा" => 1, "हमारी" => 1, "हमारे" => 1, "मेरा" => 1, "मेरी" => 1, "मेरे" => 1, "इसलिए" => 1,
        "ना" => 1, "न" => 1,
    ];

    /**
     * Two-kana pieces of Japanese grammar: verb endings, particles and helper
     * words written in Hiragana (ます, でし, ている, について, ください...). Text in
     * Chinese, Japanese and Korean is matched in two-character pieces, and on a
     * small Japanese site these made a cookery post look related to an SEO post
     * because both end their sentences the same way. Applies whatever the
     * language setting is: they only ever occur in Japanese.
     */
    private const JA_GRAMMAR_PIECES = [
        "ます" => 1, "まし" => 1, "ませ" => 1, "せん" => 1, "した" => 1, "して" => 1, "しま" => 1, "です" => 1,
        "でし" => 1, "でき" => 1, "きま" => 1, "きる" => 1, "する" => 1, "され" => 1, "れる" => 1, "れま" => 1,
        "れて" => 1, "られ" => 1, "ない" => 1, "なく" => 1, "なり" => 1, "なる" => 1, "なっ" => 1, "って" => 1,
        "った" => 1, "ある" => 1, "あり" => 1, "りま" => 1, "いる" => 1, "いま" => 1, "いて" => 1, "てい" => 1,
        "てく" => 1, "てお" => 1, "てみ" => 1, "ても" => 1, "みま" => 1, "くだ" => 1, "ださ" => 1, "さい" => 1,
        "くれ" => 1, "おり" => 1, "この" => 1, "その" => 1, "これ" => 1, "それ" => 1, "こと" => 1, "もの" => 1,
        "よう" => 1, "うに" => 1, "ため" => 1, "から" => 1, "まで" => 1, "など" => 1, "とい" => 1, "いう" => 1,
        "につ" => 1, "つい" => 1, "ので" => 1, "のは" => 1, "のが" => 1, "のを" => 1, "のに" => 1, "のも" => 1,
        "には" => 1, "とは" => 1, "では" => 1, "でも" => 1, "にも" => 1, "とな" => 1, "しょ" => 1, "ょう" => 1,
        "ろう" => 1, "だけ" => 1, "ほど" => 1, "より" => 1, "また" => 1, "しい" => 1, "ずつ" => 1, "ばか" => 1,
        "かり" => 1,
    ];

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
            // Same Unicode spelling as the tokenizer gives the text: a Hindi word
            // typed with ज़ as one character never matched text that has ज plus
            // a nukta (QA 28 Oct 2026). Lists saved before this are covered too.
            $entry = Tokenizer::unify_spelling( mb_strtolower( trim( (string) $entry ) ) );
            foreach ( (array) preg_split( "/\\s+/u", $entry, -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
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

    /** @var array<string,true>|null */
    private static ?array $hi_stems = null;

    /**
     * Stems of the Hindi function words. The stopword check runs on the word as
     * written, so "आपने" (you, with an ending) passed it and was then stemmed
     * to "आप", a function word, and shown as a shared word.
     *
     * @return array<string,true>
     */
    public static function hindi_function_stems(): array {
        if ( null === self::$hi_stems ) {
            self::$hi_stems = [];
            foreach ( array_keys( self::HI_FUNCTION_WORDS ) as $word ) {
                self::$hi_stems[ Tokenizer::stem_word( (string) $word ) ] = true;
            }
        }
        return self::$hi_stems;
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
