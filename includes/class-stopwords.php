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
        return isset( self::get( $language )[ $term ] ) || isset( self::JA_GRAMMAR_PIECES[ $term ] ) || isset( self::HI_FUNCTION_WORDS[ $term ] ) || isset( self::TH_FUNCTION_WORDS[ $term ] );
    }

    /** A Vietnamese function word (and, of, is, the plural marker, with...)? */
    public static function is_vietnamese_function_word( string $word ): bool {
        return isset( self::VI_FUNCTION_WORDS[ $word ] );
    }

    /**
     * Vietnamese function words. Two-letter syllables are words in Vietnamese
     * text now, so "và" (and) and "là" (is) would otherwise be shared words.
     * Only checked in Vietnamese text: "hay" or "cho" mean something else elsewhere.
     */
    private const VI_FUNCTION_WORDS = [
        "và" => 1, "của" => 1, "có" => 1, "là" => 1, "các" => 1, "những" => 1, "được" => 1, "với" => 1, "này" => 1,
        "một" => 1, "để" => 1, "không" => 1, "đã" => 1, "khi" => 1, "từ" => 1, "thì" => 1, "mà" => 1, "nhưng" => 1,
        "rằng" => 1, "cũng" => 1, "như" => 1, "sẽ" => 1, "đến" => 1, "theo" => 1, "tại" => 1, "về" => 1, "bị" => 1,
        "nên" => 1, "hoặc" => 1, "rất" => 1, "nhiều" => 1, "lại" => 1, "vào" => 1, "lên" => 1, "cho" => 1, "trong" => 1,
        "ra" => 1, "hay" => 1, "bạn" => 1, "tôi" => 1, "chúng" => 1, "họ" => 1, "nó" => 1, "đó" => 1, "nếu" => 1,
        "vì" => 1, "còn" => 1, "đang" => 1, "hơn" => 1, "sau" => 1, "mỗi" => 1, "cùng" => 1, "bằng" => 1, "qua" => 1,
        "hãy" => 1, "nào" => 1, "gì" => 1, "ai" => 1, "đây" => 1, "kia" => 1, "vậy" => 1, "thế" => 1, "làm" => 1,
        "thể" => 1, "ở" => 1, "do" => 1, "nhất" => 1,
    ];

    /**
     * A Persian function word (and, in, of, with, this, is, do, have...)? Only
     * checked in Persian text (Tokenizer::is_persian()): a few of these spell
     * a real word in Arabic. Arabic-keyboard spellings ("كنيد") count too.
     * A word with the present-tense prefix "می‌" or "نمی‌" counts as its verb
     * ("می‌شود" as "شود"); "آن‌ها" as "آنها".
     */
    public static function is_persian_function_word( string $word ): bool {
        $word = Tokenizer::fold_persian_letters( $word );
        if ( isset( self::FA_FUNCTION_WORDS[ $word ] ) ) {
            return true;
        }
        if ( false === strpos( $word, "\u{200C}" ) ) {
            return false;
        }
        $verb = (string) preg_replace( "/^ن?می\\x{200C}/u", "", $word );
        return isset( self::FA_FUNCTION_WORDS[ $verb ] ) || isset( self::FA_FUNCTION_WORDS[ str_replace( "\u{200C}", "", $word ) ] );
    }

    /**
     * The commonest Persian function words, checked in any Arabic-script text:
     * Persian typed on an Arabic keyboard may have no Persian-only letter, and
     * these are function words in Arabic too (و, من, ما, به, يا, هم, اين
     * "where") or not Arabic words at all (در, از, که, با, را, تا, است).
     * The link map made "سفر به" (trip to) from "راهنماي سفر به جزيره كيش".
     */
    public static function is_arabic_script_function_word( string $word ): bool {
        $folded = Tokenizer::fold_persian_letters( $word );
        if ( isset( self::FA_ANY_TEXT[ $folded ] ) ) {
            return true;
        }
        $lookup = self::arabic_function_words();
        $key    = self::arabic_key( $folded );
        if ( isset( $lookup[ $key ] ) ) {
            return true;
        }
        // "and" or "so" glued on: وعليه, فإنه, وكانت. Only before a word of three
        // letters or more: "فهم" (understanding) and "وهم" (illusion) are words.
        $first = mb_substr( $key, 0, 1, "UTF-8" );
        return ( "و" === $first || "ف" === $first ) && mb_strlen( $key, "UTF-8" ) >= 4 && isset( $lookup[ mb_substr( $key, 1, null, "UTF-8" ) ] );
    }

    /** An Arabic word as the function-word list compares it: no short vowels or tatweel, أ إ آ as ا. */
    private static function arabic_key( string $folded ): string {
        $key = (string) preg_replace( "/[\\x{064B}-\\x{065F}\\x{0670}\\x{0640}]/u", "", $folded );
        return strtr( $key, [ "أ" => "ا", "إ" => "ا", "آ" => "ا", "ٱ" => "ا" ] );
    }

    /** @var array<string,true>|null */
    private static ?array $ar_words = null;

    /** @return array<string,true> AR_FUNCTION_WORDS in the letters arabic_key() gives. */
    private static function arabic_function_words(): array {
        if ( null === self::$ar_words ) {
            self::$ar_words = [];
            foreach ( preg_split( "/\\s+/u", trim( self::AR_FUNCTION_WORDS ) ) as $word ) {
                self::$ar_words[ self::arabic_key( Tokenizer::fold_persian_letters( $word ) ) ] = true;
            }
        }
        return self::$ar_words;
    }

    /**
     * Arabic function words: prepositions and particles, conjunctions,
     * pronouns, demonstratives and relatives, question words, quantity words,
     * the prepositions with a pronoun attached (عليه, فيه, منها, لديهم...), and
     * the commonest helper verbs in their usual forms (كان, يكون, أصبح, قال,
     * يفعل, تم, يمكن, يجب). Suggestions for a post on al-Mutanabbi listed a
     * central bank and VAT because both "mention: عليه, يفعل" (QA 29 Oct 2026).
     * Checked in Arabic-script text that is not Persian.
     */
    private const AR_FUNCTION_WORDS = "
        في من إلى الى على عن مع حتى منذ عند لدى نحو حول دون بين خلال ضد عبر مثل غير سوى قبل بعد فوق تحت
        أمام خلف وراء ضمن عدة لكن ولكن بل ثم أو أم إذا اذا إذ لو لولا كي لكي حيث بينما عندما كلما لما لأن
        لان كأن بأن إن أن ان كما أيضا أيضًا أيضاً فقط جدا جداً قد لقد سوف ليس ليست لم لن لا نعم كل كلا بعض
        جميع معظم أي أية أيضا هناك هنالك هنا ذلك تلك هذا هذه هؤلاء أولئك هذان هاتان هذين هاتين ذاك الذي
        التي الذين اللذان اللتان اللذين اللتين اللواتي اللاتي اللائي ما ماذا متى أين كيف لماذا لم كم من هل
        هو هي هم هن هما أنت أنتِ أنتم أنتن أنا نحن إياه إياها إياهم
        وفي ومن وعن ومع وما ولا ولم ولن وقد وهو وهي وهم وأن وإن فإن فأن فقد فهو فهي فلا فلم وكل وبعد وقبل
        وبين وعلى وإلى والى وحتى وكان وكانت وكما وأيضا وذلك وهذا وهذه وتلك والتي والذي والذين ثم وثم
        عليه عليها عليهم عليهما علينا عليك عليكم فيه فيها فيهم فيهما فينا منه منها منهم منهما منا منك
        له لها لهم لهما لنا لك لكم لي به بها بهم بهما بنا بك بي عنه عنها عنهم عنهما إليه إليها إليهم
        اليه اليها اليهم معه معها معهم معهما لديه لديها لديهم لدينا عنده عندها عندهم بينه بينها بينهم
        بينهما نفسه نفسها نفسهم أنفسهم ذاته ذاتها كله كلها كلهم بعضها بعضهم أنه أنها أنهم إنه إنها إنهم
        لأنه لأنها لأنهم كونه كونها مما عما فيما بما كما لما ممن عمن إذن
        كان كانت كانوا كن كنت كنا يكون تكون يكونون تكونون يكن تكن أكون نكون أصبح أصبحت أصبحوا يصبح تصبح
        صار صارت يصير ظل ظلت يظل مازال لازال يزال تزال قال قالت قالوا يقول تقول يقولون يقال قيل
        فعل فعلت يفعل تفعل يفعلون تم تمت يتم تتم يمكن يمكنك يمكنه يمكنها يمكننا يمكنهم يجب ينبغي
        إحدى أحد حوالي نحو وفق وفقا وفقًا حسب بسبب رغم بالرغم إضافة بالإضافة كذلك لذلك لهذا بذلك هكذا
        حين حينما أثناء أكثر أقل جدا بشكل مثلما
    ";

    private const FA_ANY_TEXT = [
        "و" => 1, "در" => 1, "به" => 1, "از" => 1, "که" => 1, "با" => 1, "را" => 1, "تا" => 1, "یا" => 1,
        "هم" => 1, "من" => 1, "ما" => 1, "او" => 1, "این" => 1, "است" => 1, "برای" => 1,
    ];

    /**
     * Persian function words: conjunctions, prepositions, pronouns, forms of
     * "to be", "to become", "to do", "to have", "will" and "can", question
     * words, and the pieces a zero-width non-joiner splits off a word (the
     * plural "ها", "های", the comparative "تر", "ترین", "می"). Persian
     * suggestions said "Both mention: کنید, دارید" (you do, you have) and the
     * link map made "پخت زرشک پلو با" and "با مرغ" (QA 29 Oct 2026).
     */
    private const FA_FUNCTION_WORDS = [
        "و" => 1, "در" => 1, "به" => 1, "از" => 1, "که" => 1, "با" => 1, "را" => 1, "برای" => 1, "تا" => 1,
        "بر" => 1, "یا" => 1, "اما" => 1, "ولی" => 1, "هم" => 1, "نیز" => 1, "اگر" => 1, "چون" => 1, "چه" => 1,
        "پس" => 1, "زیرا" => 1, "بی" => 1, "بدون" => 1, "درباره" => 1, "روی" => 1, "زیر" => 1, "توی" => 1,
        "مثل" => 1, "مانند" => 1, "طی" => 1, "بین" => 1, "میان" => 1, "پیش" => 1, "بعد" => 1, "قبل" => 1,
        "همه" => 1, "هر" => 1, "هیچ" => 1, "چند" => 1, "نه" => 1, "حتی" => 1, "فقط" => 1, "خیلی" => 1,
        "بسیار" => 1, "دیگر" => 1, "دیگری" => 1, "یک" => 1, "یکی" => 1, "اینکه" => 1, "آنکه" => 1, "وقتی" => 1,
        "همچنین" => 1, "البته" => 1,
        "من" => 1, "تو" => 1, "او" => 1, "ما" => 1, "شما" => 1, "آنها" => 1, "ایشان" => 1, "آن" => 1, "این" => 1,
        "آنان" => 1, "اینها" => 1, "خود" => 1, "خودش" => 1, "خودم" => 1, "خودت" => 1, "خودمان" => 1, "خودتان" => 1,
        "خودشان" => 1, "وی" => 1, "همین" => 1, "همان" => 1, "چنین" => 1, "چنان" => 1, "کسی" => 1, "چیزی" => 1,
        "اینجا" => 1, "آنجا" => 1,
        "است" => 1, "هست" => 1, "هستند" => 1, "هستیم" => 1, "هستید" => 1, "هستم" => 1, "نیست" => 1, "نیستند" => 1,
        "بود" => 1, "بودند" => 1, "بودیم" => 1, "باشد" => 1, "باشند" => 1, "باشید" => 1, "باشیم" => 1,
        "شد" => 1, "شده" => 1, "شدن" => 1, "شدند" => 1, "شود" => 1, "شوند" => 1, "شوید" => 1, "شویم" => 1,
        "کرد" => 1, "کرده" => 1, "کردن" => 1, "کردند" => 1, "کردیم" => 1, "کند" => 1, "کنند" => 1, "کنید" => 1,
        "کنیم" => 1, "کنم" => 1, "دارد" => 1, "دارند" => 1, "دارید" => 1, "داریم" => 1, "دارم" => 1, "داشت" => 1,
        "داشته" => 1, "داشتن" => 1, "خواهد" => 1, "خواهند" => 1, "خواهید" => 1, "خواهیم" => 1, "باید" => 1,
        "نباید" => 1, "توان" => 1, "تواند" => 1, "توانند" => 1, "توانید" => 1,
        "چرا" => 1, "چگونه" => 1, "کجا" => 1, "کی" => 1, "کدام" => 1, "چطور" => 1, "آیا" => 1,
        "ها" => 1, "های" => 1, "هایی" => 1, "تر" => 1, "ترین" => 1, "می" => 1, "نمی" => 1, "ای" => 1,
    ];

    /**
     * Thai function words: conjunctions, particles, prepositions, pronouns and
     * helper verbs (and, of, in, is, can, will, do...). Thai text is cut into
     * words by ICU now, so these reach the index; they only occur in Thai.
     */
    private const TH_FUNCTION_WORDS = [
        "และ" => 1, "ที่" => 1, "ของ" => 1, "ใน" => 1, "เป็น" => 1, "การ" => 1, "ได้" => 1, "มี" => 1, "จะ" => 1,
        "ให้" => 1, "ไม่" => 1, "กับ" => 1, "แล้ว" => 1, "ก็" => 1, "ว่า" => 1, "นี้" => 1, "คือ" => 1, "ความ" => 1,
        "หรือ" => 1, "จาก" => 1, "โดย" => 1, "ซึ่ง" => 1, "อยู่" => 1, "ไป" => 1, "มา" => 1, "ด้วย" => 1, "แต่" => 1,
        "เพื่อ" => 1, "ถ้า" => 1, "อย่า" => 1, "ต้อง" => 1, "ควร" => 1, "เมื่อ" => 1, "ทุก" => 1, "กว่า" => 1, "ยัง" => 1,
        "อีก" => 1, "นั้น" => 1, "เรา" => 1, "คุณ" => 1, "เขา" => 1, "มาก" => 1, "ไว้" => 1, "ทำ" => 1, "แบบ" => 1,
        "เลย" => 1, "นะ" => 1, "ครับ" => 1, "ค่ะ" => 1, "คะ" => 1, "จึง" => 1, "เพราะ" => 1, "ถึง" => 1, "ทั้ง" => 1,
        "บาง" => 1, "แค่" => 1, "กัน" => 1, "ตาม" => 1, "สำหรับ" => 1, "อย่าง" => 1, "เช่น" => 1, "ได้รับ" => 1, "นี่" => 1,
    ];

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
            $entry = Tokenizer::lower( Tokenizer::unify_spelling( trim( (string) $entry ) ) );
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
            // An Arabic-letter word is stemmed in Arabic text and kept whole in
            // Persian text: ignore it in both ("خانه" typed without a Persian-only letter).
            if ( Tokenizer::is_arabic_script_word( $word ) ) {
                $stems[ Tokenizer::stem_word( $word, $language, true ) ]  = true;
                $stems[ Tokenizer::stem_word( $word, $language, false ) ] = true;
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
