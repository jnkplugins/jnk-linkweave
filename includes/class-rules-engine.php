<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

class Rules_Engine {

    /** Elements whose text never gets a link: links, code, headings, forms, scripts. */
    public const PROTECTED_TAGS = "a|code|pre|h[1-6]|form|label|button|select|option|textarea|script|style";

    /**
     * Keyword edges for patterns with the u flag: no letter, mark or digit beside
     * it. Chinese, Japanese and Korean characters do not count: those languages
     * write words without spaces or with endings attached (내부 링크를,
     * 内部リンクを設置, WordPressのプラグイン), so a rule never fired there.
     */
    /*
     * Korean is the exception at the start: endings and particles go after a
     * word, so a Hangul syllable before a keyword means it is inside another
     * word ("수환율" is not "환율"; QA 31 Oct 2026). Arabic puts و ف ب ل ك
     * and ال (the) in front of a word, written as one with it: "بالشبكة" is
     * "with the network", so a keyword may follow them at a word's start.
     * After a one-letter prefix the keyword needs four letters or more: "ريد"
     * would otherwise link inside "بريد" (mail).
     */
    public const WORD_START = "(?:(?<!(?![\\p{Han}\\p{Hiragana}\\p{Katakana}\\x{3005}\\x{3006}\\x{30FC}" . Tokenizer::SEA_CLASS . "])[\\p{L}\\p{M}\\p{N}])"
        . "|(?<=(?<![\\p{L}\\p{M}\\p{N}])و|(?<![\\p{L}\\p{M}\\p{N}])ف|(?<![\\p{L}\\p{M}\\p{N}])ب|(?<![\\p{L}\\p{M}\\p{N}])ل|(?<![\\p{L}\\p{M}\\p{N}])ك)(?=\\p{Arabic}{4})"
        . "|(?<=(?<![\\p{L}\\p{M}\\p{N}])ال|(?<![\\p{L}\\p{M}\\p{N}])وال|(?<![\\p{L}\\p{M}\\p{N}])فال|(?<![\\p{L}\\p{M}\\p{N}])بال|(?<![\\p{L}\\p{M}\\p{N}])كال|(?<![\\p{L}\\p{M}\\p{N}])لل)(?=\\p{Arabic}))";
    public const WORD_END   = "(?!(?![" . Tokenizer::CJK_CLASS . Tokenizer::SEA_CLASS . "])[\\p{L}\\p{M}\\p{N}])";

    /**
     * Thai, Lao, Khmer and Myanmar letters do not end a word either (WORD_START,
     * WORD_END let them through), since those languages write no spaces: a Thai
     * rule only ever fired next to a space (QA 29 Oct 2026). Where a match meets
     * such a letter, ICU's word breaker must agree that a word ends there, so
     * "ตา" (eye) links in "ปวดตามาก" but not inside "ตาราง" (table). Without intl
     * the old rule stands: no link next to those letters.
     */
    public static function at_word_edges( string $subject, int $start, int $end ): bool {
        $sea    = Tokenizer::SEA_CLASS;
        $before = mb_strcut( $subject, max( 0, $start - 240 ), $start - max( 0, $start - 240 ), "UTF-8" );
        $after  = mb_strcut( $subject, $end, 240, "UTF-8" );
        $match  = substr( $subject, $start, $end - $start );
        $left   = preg_match( "/[{$sea}]+$/u", $before, $l ) ? $l[0] : "";
        $right  = preg_match( "/^[{$sea}]+/u", $after, $r ) ? $r[0] : "";
        // Only an edge with these letters on both sides needs the word breaker.
        $check_start = "" !== $left && 1 === preg_match( "/^[{$sea}]/u", $match );
        $check_end   = "" !== $right && 1 === preg_match( "/[{$sea}]$/u", $match );
        if ( ! $check_start && ! $check_end ) {
            return true;
        }
        static $breaker = null;
        if ( null === $breaker ) {
            $breaker = Tokenizer::can_segment_sea() ? ( \IntlBreakIterator::createWordInstance( "th" ) ?: false ) : false;
        }
        if ( ! $breaker || ! $breaker->setText( $left . $match . $right ) ) {
            return false;
        }
        return ( ! $check_start || $breaker->isBoundary( strlen( $left ) ) )
            && ( ! $check_end || $breaker->isBoundary( strlen( $left ) + strlen( $match ) ) );
    }

    /**
     * A whole protected element. The name must end at a space, ">" or "/":
     * with \b, custom elements such as <form-row> or <label-chip> counted as
     * <form> and <label> (QA 27 Oct 2026). The element may not contain another
     * opening of the same tag, so an unclosed <label> no longer swallows the
     * text up to a later </label>. Written as possessive runs: a lazy ".*?" with a
     * look-ahead per character ran out of PCRE JIT stack on a 600 KB script.
     */
    public const PROTECTED_RE = "~<(" . self::PROTECTED_TAGS . ")(?=[\\s>/])[^>]*>(?:[^<]++|<(?!\\1(?=[\\s>/])|/\\1\\s*>))*+</\\1\\s*>~i";

    private const CACHE_KEY = "jnk_linkweave_active_rules";
    private const CACHE_TTL = HOUR_IN_SECONDS;

    /**
     * Option holding the per-site cap allocation: rule id => [ post id => links ].
     * Only rules with a positive max_per_site appear. Written by
     * recompute_applied_counts(), read at render time.
     */
    public const SITE_ALLOCATION_OPTION = "jnk_linkweave_rule_site_allocation";

    /**
     * Option caching which post each rule links to: rule id => [ target url, post id ].
     * Resolving a permalink costs a few queries, so it is done once per rule (by the
     * recount, or the first report that needs it) and redone only when the URL changes.
     */
    public const TARGETS_OPTION = "jnk_linkweave_rule_targets";

    /** Per-request copy of the allocation option. */
    private static ?array $allocation = null;

    /**
     * Ids of rules that use options only the Pro build can apply (other words,
     * post type or category limits, rel="sponsored"). The Pro build keeps this list
     * in step with its own data; the free build only reads it.
     */
    public const NEEDS_PRO_OPTION = "jnk_linkweave_rules_needing_pro";

    /**
     * Post meta: the owner asked for no auto-links on this post. The switch to set
     * it is a Pro screen, but the choice is honoured by every build, so a post that
     * was opted out stays free of auto-links after Pro is removed.
     */
    public const OPT_OUT_META = "_jnk_linkweave_no_autolink";

    /** Did the owner switch auto-links off for this post? */
    public static function post_opted_out( int $post_id ): bool {
        return $post_id > 0 && (bool) get_post_meta( $post_id, self::OPT_OUT_META, true );
    }

    /**
     * Can this install apply those options? True whenever the Pro code is loaded
     * and was ever licensed, including after a licence lapses, so a live site keeps
     * the links it had. False on the free build.
     */
    public static function rule_options_supported(): bool {
        return (bool) apply_filters( "jnk_linkweave_rule_options_supported", false );
    }

    /** @return array<int,true> rule id => true */
    public static function rules_needing_pro(): array {
        $ids = get_option( self::NEEDS_PRO_OPTION, [] );
        $out = [];
        foreach ( is_array( $ids ) ? $ids : [] as $id ) {
            if ( (int) $id > 0 ) {
                $out[ (int) $id ] = true;
            }
        }
        return $out;
    }

    /**
     * A rule that needs Pro options is paused when they cannot be applied. Running
     * it without them would link on every post instead of the chosen ones and drop
     * rel="sponsored" from paid links. Its data is kept, so it resumes with Pro.
     */
    public static function is_paused_without_pro( int $rule_id ): bool {
        return ! self::rule_options_supported() && isset( self::rules_needing_pro()[ $rule_id ] );
    }

    /**
     * @param object[] $rules
     * @return object[]
     */
    private static function drop_paused( array $rules ): array {
        if ( self::rule_options_supported() ) {
            return $rules;
        }
        $needs = self::rules_needing_pro();
        if ( ! $needs ) {
            return $rules;
        }
        return array_values( array_filter( $rules, static fn( $r ) => ! isset( $needs[ (int) ( $r->id ?? 0 ) ] ) ) );
    }

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . "jnk_linkweave_rules";
    }

    /**
     * Reduce a URL to a form two spellings of the same page share, so a rule
     * target can be compared against a permalink. Ignores scheme, a leading
     * "www.", host case, percent-encoding, the trailing slash, and any fragment. Query strings are
     * kept: "?p=2" and "?p=3" are different pages.
     *
     * Returns "" for anything unparseable, and callers must treat "" as
     * "no match" rather than comparing two empty strings as equal.
     */
    private static function normalize_url( string $url ): string {
        $url = trim( $url );
        if ( "" === $url ) {
            return "";
        }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || ( empty( $parts["host"] ) && empty( $parts["path"] ) ) ) {
            return "";
        }
        $host = isset( $parts["host"] ) ? strtolower( $parts["host"] ) : "";
        if ( str_starts_with( $host, "www." ) ) {
            $host = substr( $host, 4 );
        }
        // A page with a Korean or Thai address is written percent-encoded by
        // WordPress ("/%ec%9d%b8…/") and as plain letters when pasted from the
        // address bar ("/인플레이션/"); decode so both spellings are one page.
        $path  = isset( $parts["path"] ) ? rtrim( rawurldecode( $parts["path"] ), "/" ) : "";
        $query = isset( $parts["query"] ) && "" !== $parts["query"] ? "?" . rawurldecode( $parts["query"] ) : "";

        return $host . $path . $query;
    }

    /**
     * normalize_url() for comparing links inside a post: a relative href
     * ("/grinder-guide/") is the same page as the rule's absolute target, so it
     * borrows this site's host before normalising.
     */
    private static function link_key( string $url ): string {
        $url = trim( $url );
        if ( str_starts_with( $url, "/" ) && ! str_starts_with( $url, "//" ) ) {
            $url = untrailingslashit( home_url() ) . $url;
        }
        return self::normalize_url( $url );
    }

    public function apply_rules_to_content( string $content, ?int $post_id = null ): string {
        if ( is_admin() || is_feed() || empty( $content ) ) {
            return $content;
        }
        if ( null === $post_id ) {
            $post_id = (int) get_the_ID();
        }

        $rules = self::limit_rules( $this->get_active_rules() );
        if ( empty( $rules ) ) {
            return $content;
        }

        // "Max per site" is a site-wide budget. It used to be compared against the
        // rule's stored site-wide total on every render, so any cap below that total
        // switched the rule off on every page. The budget is now shared out across
        // posts (oldest first) by recompute_applied_counts(), and each post renders
        // only its own share.
        $site_caps  = [];
        $allocation = self::site_allocation();
        foreach ( $rules as $rule ) {
            if ( (int) $rule->max_per_site > 0 ) {
                $site_caps[ (int) $rule->id ] = (int) ( $allocation[ (int) $rule->id ][ $post_id ] ?? 0 );
            }
        }

        $result = self::link_content( $content, $rules, $post_id, $site_caps, true );
        return $result["content"];
    }

    /**
     * Apply the optional jnk_linkweave_max_active_rules cap. Default 0 means
     * UNLIMITED (the free tier ships with no cap); a host filter may set a
     * positive limit if desired.
     */
    private static function limit_rules( array $rules ): array {
        $max = (int) apply_filters( "jnk_linkweave_max_active_rules", 0 );
        if ( $max > 0 ) {
            $rules = array_slice( $rules, 0, $max );
        }
        return $rules;
    }

    /**
     * The one linking pass, shared by rendering and by the Applied count, so the
     * number on the Rules screen is the number of links a visitor actually gets.
     * (The count used to regex the raw content and ignored the self-link skip and
     * the protected regions below, so it overstated every rule.)
     *
     * @param object[]       $rules     Active rules, in application order.
     * @param array<int,int> $site_caps Rule id => links this post may receive under
     *                                  the rule's max_per_site budget. Rules absent
     *                                  from the map have no site budget.
     * @param bool           $fire      Fire jnk_linkweave_rule_applied (render only).
     * @param array<int,array{0:string[],1:string[]}>|null $phrases Rule id => [ phrases, case-folded
     *                                  phrases ], from rule_phrases(). The recount works them out
     *                                  once for the whole walk instead of once per post.
     * @return array{content:string,applied:array<int,int>}
     */
    public static function link_content( string $content, array $rules, int $post_id, array $site_caps = [], bool $fire = false, ?array $phrases = null ): array {
        $applied = [];
        if ( "" === $content || empty( $rules ) || self::post_opted_out( $post_id ) ) {
            return [ "content" => $content, "applied" => $applied ];
        }
        /**
         * The rules that may link inside this post. Runs for rendering and for the
         * Links added count alike, so both agree. Extensions can narrow the list
         * per post (a rule limited to some post types, a post that opts out).
         *
         * @param object[] $rules   Active rules, in application order.
         * @param int      $post_id Post being linked (0 when unknown).
         */
        $rules = array_values( (array) apply_filters( "jnk_linkweave_rules_for_post", self::drop_paused( $rules ), $post_id ) );
        if ( empty( $rules ) ) {
            return [ "content" => $content, "applied" => $applied ];
        }

        // Pages this post already links to, by hand or from an earlier rule. A rule
        // aimed at one of them would add a second link to the same page, which
        // reads as spam to visitors and to search engines.
        $linked = [];
        if ( preg_match_all( "~<a\\b[^>]*?\\bhref\\s*=\\s*([\"'])(.*?)\\1~is", $content, $hrefs ) ) {
            foreach ( $hrefs[2] as $href ) {
                $key = self::link_key( html_entity_decode( (string) $href, ENT_QUOTES ) );
                if ( "" !== $key ) {
                    $linked[ $key ] = true;
                }
            }
        }

        // Protect regions whose contents must not be touched.
        $placeholders = [];
        $protect      = static function ( $m ) use ( &$placeholders ) {
            $key                  = "%%AUTOLINK_PROTECT_" . count( $placeholders ) . "%%";
            $placeholders[ $key ] = $m[0];
            return $key;
        };
        // Forms too: a link inside a label or an option took a visitor who tapped
        // "Email" away from the form they were filling in (QA 25 Oct 2026).
        $protected = preg_replace_callback( self::PROTECTED_RE, $protect, $content );
        if ( null === $protected ) {
            // PCRE gave up (a huge or odd post): add no links rather than lose the post.
            return [ "content" => $content, "applied" => $applied ];
        }
        // HTML comments (block delimiters carry JSON that the shortcode pattern
        // below would otherwise half-match).
        $protected = preg_replace_callback( "~<!--.*?-->~s", $protect, (string) $protected );
        // Raw shortcodes that survived do_shortcode.
        $protected = preg_replace_callback( "~\\[[^\\]]+\\]~", $protect, (string) $protected );
        // Every remaining tag, so a keyword inside an attribute (alt="…", title="…")
        // can never be turned into a link that breaks the tag.
        $protected = preg_replace_callback( "~<[^>]+>~", $protect, (string) $protected );
        $protected = (string) $protected;

        // A rule whose target is the post we're rendering would link the post to
        // itself. Resolve the current permalink once and skip those rules.
        $self_url = $post_id > 0 ? self::normalize_url( (string) get_permalink( $post_id ) ) : "";

        // Case-folded copy of the text, to skip rules whose words are not in this
        // post at all before building their pattern. A link map has hundreds of
        // rules and a post mentions a few of them, so the Applied recount ran
        // hundreds of patterns per post and every post save cost 3 s of
        // background work on a 1,100-post site (QA 27 Oct 2026).
        $folded = self::fold( $protected );

        foreach ( $rules as $rule ) {
            $id       = (int) $rule->id;
            [ $keywords, $folded_keywords ] = $phrases[ $id ] ?? self::rule_phrases( [ $rule ] )[ $id ];
            if ( ! $keywords || ! self::mentions_any( $folded, $folded_keywords ) ) {
                continue;
            }
            if ( "" !== $self_url && self::normalize_url( (string) $rule->target_url ) === $self_url ) {
                continue;
            }
            // A shortlink target (/?p=123, /?page_id=123) never equals the pretty
            // permalink, so compare the id it names as well.
            if ( $post_id > 0 && self::shortlink_post_id( (string) $rule->target_url ) === $post_id ) {
                continue;
            }
            $cap = (int) $rule->max_per_post > 0 ? (int) $rule->max_per_post : 1;
            if ( array_key_exists( $id, $site_caps ) ) {
                $cap = min( $cap, max( 0, (int) $site_caps[ $id ] ) );
            }
            if ( $cap <= 0 || ( $applied[ $id ] ?? 0 ) >= $cap ) {
                continue;
            }
            $target_key = self::link_key( (string) $rule->target_url );
            if ( "" !== $target_key && isset( $linked[ $target_key ] ) ) {
                continue;
            }
            $protected = self::replace_matches( $protected, $rule, $keywords, $cap, $applied, $post_id, $placeholders, $fire );
            if ( "" !== $target_key && ( $applied[ $id ] ?? 0 ) > 0 ) {
                $linked[ $target_key ] = true;
            }
        }

        // Placeholders can nest (a protected tag inside a protected region), so
        // restore until nothing is left to restore.
        $out = $protected;
        for ( $i = 0; $i < 5 && false !== strpos( $out, "%%AUTOLINK_PROTECT_" ); $i++ ) {
            $out = strtr( $out, $placeholders );
        }

        return [ "content" => $out, "applied" => $applied ];
    }

    /**
     * Phrases a rule links, longest first.
     *
     * @return string[]
     */
    private static function rule_keywords( object $rule ): array {
        /**
         * Phrases a rule links. One per rule by default; an extension may add
         * other spellings or synonyms. The rule's caps cover all of them together.
         *
         * @param string[] $keywords Phrases, the rule's own keyword first.
         * @param object   $rule     The rule row.
         */
        return array_values( array_unique( array_filter( array_map(
            static fn( $k ) => trim( (string) $k ),
            (array) apply_filters( "jnk_linkweave_rule_keywords", [ (string) $rule->keyword ], $rule )
        ), static fn( $k ) => "" !== $k ) ) );
    }

    /**
     * Each rule's phrases and their case-folded forms, for link_content().
     *
     * @param object[] $rules
     * @return array<int,array{0:string[],1:string[]}>
     */
    public static function rule_phrases( array $rules ): array {
        $out = [];
        foreach ( $rules as $rule ) {
            $keywords                 = self::rule_keywords( $rule );
            $out[ (int) $rule->id ] = [ $keywords, array_map( [ self::class, "fold_keyword" ], $keywords ) ];
        }
        return $out;
    }

    /**
     * Case folding for the cheap "could this match" test, with the spelling
     * variants keyword_regex() accepts made equal: NFC, Turkish İ/ı/I as i,
     * Arabic ي/ك as Persian ی/ک.
     */
    private static function fold( string $text ): string {
        $text = Tokenizer::unify_spelling( $text );
        $text = function_exists( "mb_convert_case" ) ? mb_convert_case( $text, MB_CASE_FOLD_SIMPLE, "UTF-8" ) : strtolower( $text );
        $text = strtr( $text, [ "i\u{0307}" => "i", "İ" => "i", "ı" => "i", "ي" => "ی", "ك" => "ک", "ى" => "ی", "أ" => "ا", "إ" => "ا", "آ" => "ا", "ة" => "ه" ] );
        return (string) preg_replace( [ "/" . self::APOS_RE . "/u", "/" . self::DQUOTE_RE . "/u" ], [ "'", '"' ], $text );
    }

    /**
     * An apostrophe as visitors' HTML may hold it: typed (' ’ ‘ ʼ), turned
     * curly by wptexturize (&#8217;) or encoded (&#039;, &rsquo;). A rule
     * typed "Chef's Knife" never linked "Chef&#8217;s Knife", the way the page
     * prints it, though the Links added count said it did (QA 31 Oct 2026).
     */
    public const APOS_RE = "(?:['`\\x{2018}\\x{2019}\\x{02BC}\\x{2032}]|&#0*39;|&#0*821[67];|&#0*8242;|&#[xX]0*27;|&#[xX]0*201[89];|&[lr]squo;|&apos;)";

    /** A double quote the same ways: " “ ” ″, &#8220; &#8221; &quot;. */
    public const DQUOTE_RE = "(?:[\"\\x{201C}\\x{201D}\\x{2033}]|&#0*34;|&#0*822[01];|&#0*8243;|&#[xX]0*22;|&#[xX]0*201[cdCD];|&[lr]dquo;|&quot;)";

    /** A folded keyword for mentions_any(): its longest part between ZWNJs, which keyword_regex() lets be a space or nothing. */
    private static function fold_keyword( string $keyword ): string {
        $parts = explode( "\u{200C}", self::fold( $keyword ) );
        usort( $parts, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );
        return (string) $parts[0];
    }

    /**
     * The pattern for one keyword (no edges): its usual spellings match too.
     * A rule typed "istanbul" never linked "İstanbul", the way most Turkish text
     * writes it; one typed on a Mac (NFD) never linked text typed elsewhere;
     * "کتاب" missed "كتاب" typed on an Arabic keyboard; "قورمه‌سبزی" (with a
     * zero-width non-joiner) missed "قورمه سبزی" (QA 29 Oct 2026).
     */
    public static function keyword_regex( string $keyword, bool $case_insensitive = true ): string {
        // Quotes typed as entities match like the characters they stand for.
        $keyword = (string) preg_replace( [ "/" . self::APOS_RE . "/u", "/" . self::DQUOTE_RE . "/u" ], [ "'", '"' ], $keyword );
        $forms   = [ $keyword ];
        if ( class_exists( "Normalizer" ) && preg_match( "/[^\\x00-\\x7F]/", $keyword ) ) {
            foreach ( [ \Normalizer::FORM_C, \Normalizer::FORM_D ] as $form ) {
                $normal = \Normalizer::normalize( $keyword, $form );
                if ( is_string( $normal ) ) {
                    $forms[] = $normal;
                }
            }
        }
        // Arabic as people type it: hamza on alef or not (أحمد = احمد), teh
        // marbuta or heh at a word's end (مدرسة = مدرسه), alef maqsura or yeh
        // (على = علي). Writers mix them; a rule matched only the one typed.
        $arabic = [ "ا" => "[اأإآ]", "أ" => "[اأإآ]", "إ" => "[اأإآ]", "آ" => "[اأإآ]", "ة" => "[ةه]", "ه" => "[ةه]", "ى" => "[یيى]" ];
        $map = $arabic + [ "ی" => "[یيى]", "ي" => "[یيى]", "ک" => "[کك]", "ك" => "[کك]", "\u{200C}" => "[\\x{200C} ]?", "'" => self::APOS_RE, '"' => self::DQUOTE_RE ];
        if ( $case_insensitive ) {
            $map += [ "i" => "[iİı]", "I" => "[iİı]", "İ" => "[iİı]", "ı" => "[iİı]" ];
        }
        $out = [];
        foreach ( array_unique( $forms ) as $form ) {
            $out[] = strtr( preg_quote( $form, "/" ), $map );
        }
        return implode( "|", array_unique( $out ) );
    }

    /**
     * Could any of these phrases match in this text? A cheap substring test that
     * may say yes when the pattern later finds no whole-word match, but never says
     * no when the pattern would match.
     *
     * @param string[] $folded_keywords Case-folded phrases.
     */
    private static function mentions_any( string $folded, array $folded_keywords ): bool {
        foreach ( $folded_keywords as $keyword ) {
            if ( "" !== $keyword && false !== strpos( $folded, $keyword ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string[]             $keywords     The rule's phrases (rule_keywords()).
     * @param array<string,string> $placeholders Protected-fragment map (passed by
     *        reference). Each anchor this method inserts is itself stashed as a
     *        placeholder so that later rules in the same pass cannot match inside an
     *        anchor we just created.
     */
    private static function replace_matches( string $content, object $rule, array $keywords, int $cap, array &$applied, int $post_id, array &$placeholders, bool $fire ): string {
        $flags = ! empty( $rule->case_sensitive ) ? "u" : "iu";
        if ( ! $keywords ) {
            return $content;
        }
        // Longest first, so "page caching plugin" wins over "page caching".
        usort( $keywords, static fn( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) );
        // Whole words only. \b counts a combining mark (the vowel signs of
        // Devanagari and other Indic scripts) as a word edge, so a rule cut
        // "बिग बॉस 20 सीज़न" inside a word (QA 27 Oct 2026). Letters, marks and
        // digits on either side mean "inside a word".
        $ci        = "iu" === $flags;
        $pattern   = "/" . self::WORD_START . "(?:" . implode( "|", array_map( static fn( $k ) => self::keyword_regex( $k, $ci ), $keywords ) ) . ")" . self::WORD_END . "/" . $flags;
        $remaining = $cap - ( $applied[ (int) $rule->id ] ?? 0 );
        if ( $remaining <= 0 ) {
            return $content;
        }

        $done   = 0;
        $result = preg_replace_callback( $pattern, static function ( $m ) use ( $rule, &$applied, $post_id, &$placeholders, $fire, $content, $remaining, &$done ) {
            [ $text, $offset ] = $m[0];
            if ( $done >= $remaining || ! self::at_word_edges( $content, (int) $offset, (int) $offset + strlen( $text ) ) ) {
                return $text;
            }
            $done++;
            $applied[ (int) $rule->id ] = ( $applied[ (int) $rule->id ] ?? 0 ) + 1;
            if ( $fire ) {
                do_action( "jnk_linkweave_rule_applied", (int) $rule->id, $post_id );
            }

            $target_attr = ! empty( $rule->open_in_new_tab ) ? ' target="_blank"' : "";
            $rel_parts   = [];
            if ( ! empty( $rule->open_in_new_tab ) ) {
                $rel_parts[] = "noopener";
            }
            if ( ! empty( $rule->nofollow ) ) {
                $rel_parts[] = "nofollow";
            }
            /**
             * rel values for a link a rule adds (e.g. "sponsored" for paid links).
             *
             * @param string[] $rel_parts rel tokens so far.
             * @param object   $rule      The rule row.
             */
            $rel_parts = array_values( array_unique( array_map( "sanitize_html_class", (array) apply_filters( "jnk_linkweave_rule_rel", $rel_parts, $rule ) ) ) );
            $rel_attr = $rel_parts ? ' rel="' . esc_attr( implode( " ", $rel_parts ) ) . '"' : "";

            $anchor = sprintf(
                '<a href="%s"%s%s class="jnk-linkweave-auto">%s</a>',
                esc_url( $rule->target_url ),
                $target_attr,
                $rel_attr,
                esc_html( $text )
            );

            $key                  = "%%AUTOLINK_PROTECT_" . count( $placeholders ) . "%%";
            $placeholders[ $key ] = $anchor;
            return $key;
        }, $content, -1, $count, PREG_OFFSET_CAPTURE );

        return null === $result ? $content : $result;
    }

    public function get_active_rules(): array {
        $cached = wp_cache_get( self::CACHE_KEY, "jnk-linkweave" );
        if ( false !== $cached ) {
            return $cached;
        }
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM %i WHERE is_active = 1 ORDER BY id ASC", self::table() )
        );
        if ( ! is_array( $rows ) ) {
            $rows = [];
        }
        wp_cache_set( self::CACHE_KEY, $rows, "jnk-linkweave", self::CACHE_TTL );
        return $rows;
    }

    public static function flush_cache(): void {
        wp_cache_delete( self::CACHE_KEY, "jnk-linkweave" );
        self::$allocation = null;
    }

    /** @return array<int,array<int,int>> rule id => [ post id => links allowed ] */
    public static function site_allocation(): array {
        if ( null === self::$allocation ) {
            $stored           = get_option( self::SITE_ALLOCATION_OPTION, [] );
            self::$allocation = is_array( $stored ) ? $stored : [];
        }
        return self::$allocation;
    }

    /**
     * Recompute every rule's applied_count as the number of links it currently
     * contributes site-wide, by running the same linking pass the front end runs
     * over each published post of the configured post types. Inactive rules count 0.
     *
     * The same walk shares out each max_per_site budget, oldest post first, and
     * stores the per-post shares for rendering to read.
     */
    public static function recompute_applied_counts(): void {
        global $wpdb;

        $all = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i ORDER BY id ASC", self::table() ) );
        if ( empty( $all ) ) {
            delete_option( self::SITE_ALLOCATION_OPTION );
            self::flush_cache();
            return;
        }

        $active = self::limit_rules( array_values( array_filter( (array) $all, static fn( $r ) => ! empty( $r->is_active ) ) ) );

        $settings   = (array) get_option( "jnk_linkweave_settings", [] );
        $post_types = Index_Builder::base_post_types( $settings )
            ? array_map( "sanitize_key", Index_Builder::base_post_types( $settings ) )
            : [ "post", "page" ];
        $in = implode( ",", array_fill( 0, count( $post_types ), "%s" ) );

        $totals    = [];
        $remaining = [];
        $alloc     = [];
        foreach ( $active as $rule ) {
            $totals[ (int) $rule->id ] = 0;
            if ( (int) $rule->max_per_site > 0 ) {
                $remaining[ (int) $rule->id ] = (int) $rule->max_per_site;
                $alloc[ (int) $rule->id ]     = [];
            }
        }

        if ( ! empty( $active ) ) {
            $phrases = self::rule_phrases( $active );
            // Oldest first, so a new post never takes the budget from an old one that
            // already shows the link.
            $posts = $wpdb->get_results( $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN() placeholder list is built from %s at runtime; post types are the prepared args.
                "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in) ORDER BY post_date ASC, ID ASC",
                $post_types
            ) );

            // Count the text visitors get: the_content turns quotes curly
            // (wptexturize, priority 10) before the rules run at 99.
            $texturize = false !== has_filter( "the_content", "wptexturize" );
            foreach ( (array) $posts as $post ) {
                $content = (string) $post->post_content;
                if ( "" === $content ) {
                    continue;
                }
                if ( $texturize ) {
                    $content = wptexturize( $content );
                }
                $result = self::link_content( $content, $active, (int) $post->ID, $remaining, false, $phrases );
                foreach ( $result["applied"] as $rule_id => $n ) {
                    $totals[ $rule_id ] = ( $totals[ $rule_id ] ?? 0 ) + $n;
                    if ( isset( $remaining[ $rule_id ] ) ) {
                        $remaining[ $rule_id ]                = max( 0, $remaining[ $rule_id ] - $n );
                        $alloc[ $rule_id ][ (int) $post->ID ] = $n;
                    }
                }
            }
        }

        foreach ( (array) $all as $rule ) {
            $wpdb->update( self::table(), [ "applied_count" => (int) ( $totals[ (int) $rule->id ] ?? 0 ) ], [ "id" => (int) $rule->id ], [ "%d" ], [ "%d" ] );
        }

        update_option( self::SITE_ALLOCATION_OPTION, $alloc, false );
        self::flush_cache();
        // Resolve the targets now, in the background job, so the reports never do.
        self::rule_inbound_counts();
    }

    /**
     * Links each post receives from auto-link rules: post id => links.
     *
     * Reuses the Applied count the recount already worked out (the links a rule
     * really adds to published posts), so a rule that matches nothing gives
     * nothing, and a paused or switched-off rule gives nothing. Only rules whose
     * target is a post on this site count. One small query over the rules table
     * plus a cached URL lookup per rule, so it stays cheap on large sites.
     *
     * @return array<int,int>
     */
    public static function rule_inbound_counts(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, target_url, applied_count FROM %i WHERE is_active = 1 AND applied_count > 0 ORDER BY id ASC",
            self::table()
        ) );
        $rows = self::limit_rules( self::drop_paused( (array) $rows ) );
        if ( ! $rows ) {
            return [];
        }
        $stored  = get_option( self::TARGETS_OPTION, [] );
        $stored  = is_array( $stored ) ? $stored : [];
        $targets = [];
        $changed = false;
        $out     = [];
        foreach ( $rows as $rule ) {
            $id  = (int) $rule->id;
            $url = (string) $rule->target_url;
            if ( isset( $stored[ $id ] ) && is_array( $stored[ $id ] ) && ( $stored[ $id ][0] ?? null ) === $url ) {
                $post_id = (int) ( $stored[ $id ][1] ?? 0 );
            } else {
                $post_id = self::target_post_id( $url );
                $changed = true;
            }
            $targets[ $id ] = [ $url, $post_id ];
            if ( $post_id > 0 ) {
                $out[ $post_id ] = ( $out[ $post_id ] ?? 0 ) + (int) $rule->applied_count;
            }
        }
        if ( $changed || count( $targets ) !== count( $stored ) ) {
            update_option( self::TARGETS_OPTION, $targets, false );
        }
        return $out;
    }

    /** The post on this site a rule target names, or 0 (another site, an archive). */
    private static function target_post_id( string $url ): int {
        $id = self::shortlink_post_id( $url );
        if ( $id > 0 ) {
            return $id;
        }
        $url = trim( $url );
        if ( str_starts_with( $url, "/" ) && ! str_starts_with( $url, "//" ) ) {
            $url = untrailingslashit( home_url() ) . $url;
        }
        $id = (int) url_to_postid( $url );
        if ( $id <= 0 ) {
            // A Korean or Thai address pasted from the address bar is plain letters;
            // WordPress stores the slug percent-encoded.
            $parts = wp_parse_url( $url );
            if ( is_array( $parts ) && ! empty( $parts["path"] ) && rawurldecode( $parts["path"] ) === $parts["path"] && preg_match( "/[^\\x00-\\x7f]/", $parts["path"] ) ) {
                $enc = implode( "/", array_map( static fn( $s ) => strtolower( rawurlencode( $s ) ), explode( "/", $parts["path"] ) ) );
                $id  = (int) url_to_postid( str_replace( $parts["path"], $enc, $url ) );
            }
        }
        return $id;
    }

    /** Debounced full recount after published content changes. */
    const RECOUNT_HOOK = "jnk_linkweave_recount_rules";

    public static function register_recount_hooks(): void {
        add_action( self::RECOUNT_HOOK, [ self::class, "recompute_applied_counts" ], 10, 0 );
        add_action( "transition_post_status", [ self::class, "schedule_recount_on_transition" ], 10, 3 );
        add_action( "post_updated", [ self::class, "schedule_recount_on_update" ], 10, 3 );
        add_action( "set_object_terms", [ self::class, "schedule_recount_on_terms" ], 10, 6 );
        foreach ( [ "added_post_meta", "updated_post_meta", "deleted_post_meta" ] as $meta_hook ) {
            add_action( $meta_hook, [ self::class, "schedule_recount_on_meta" ], 10, 3 );
        }
    }

    /** Is this post one whose content the recount reads? */
    private static function counts_for_rules( $post ): bool {
        if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
            return false;
        }
        $settings   = (array) get_option( "jnk_linkweave_settings", [] );
        $post_types = Index_Builder::base_post_types( $settings )
            ? array_map( "sanitize_key", Index_Builder::base_post_types( $settings ) )
            : [ "post", "page" ];
        return in_array( $post->post_type, $post_types, true );
    }

    /**
     * Queue one recount a minute from now, unless one is already queued. Every
     * change in that minute shares the one walk.
     */
    public static function schedule_recount(): void {
        if ( empty( ( new self() )->get_active_rules() ) ) {
            return;
        }
        if ( ! wp_next_scheduled( self::RECOUNT_HOOK ) ) {
            wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::RECOUNT_HOOK );
        }
    }

    /**
     * A published post saved again: only worth a recount when something the
     * linking pass reads changed (its text, its date, which orders the "Max per
     * site" shares, or its address, which decides the self-link skip). Saving a
     * post unchanged, or changing only its title or excerpt, used to queue the
     * full walk too.
     *
     * @param int      $post_id     Post id.
     * @param \WP_Post $post_after  The post as saved.
     * @param \WP_Post $post_before The post before the save.
     */
    public static function schedule_recount_on_update( $post_id, $post_after, $post_before ): void {
        if ( ! $post_after instanceof \WP_Post || ! $post_before instanceof \WP_Post ) {
            return;
        }
        if ( "publish" !== $post_after->post_status || "publish" !== $post_before->post_status ) {
            return; // A status change: schedule_recount_on_transition() has it.
        }
        if ( ! self::counts_for_rules( $post_after ) && ! self::counts_for_rules( $post_before ) ) {
            return;
        }
        foreach ( [ "post_content", "post_date", "post_name", "post_type", "post_parent" ] as $field ) {
            if ( (string) $post_after->$field !== (string) $post_before->$field ) {
                self::schedule_recount();
                return;
            }
        }
    }

    /**
     * Categories of a published post changed: rules limited to some categories
     * may now link there, or stop.
     */
    public static function schedule_recount_on_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
        $new = array_map( "intval", (array) $tt_ids );
        $old = array_map( "intval", (array) $old_tt_ids );
        sort( $new );
        sort( $old );
        if ( $new === $old ) {
            return;
        }
        $post = get_post( (int) $object_id );
        if ( $post && "publish" === $post->post_status && self::counts_for_rules( $post ) ) {
            self::schedule_recount();
        }
    }

    /** The owner switched auto-links off or on for a published post. */
    public static function schedule_recount_on_meta( $meta_id, $object_id, $meta_key ): void {
        if ( self::OPT_OUT_META !== $meta_key ) {
            return;
        }
        $post = get_post( (int) $object_id );
        if ( $post && "publish" === $post->post_status && self::counts_for_rules( $post ) ) {
            self::schedule_recount();
        }
    }

    /**
     * The Applied count used to be recomputed only when a rule was saved, so a post
     * published afterwards that mentions a keyword never moved the number, and
     * unpublishing one never lowered it. Any change that touches published content
     * now queues one recount a minute later; a burst of saves shares that one walk.
     *
     * @param string   $new_status New post status.
     * @param string   $old_status Previous post status.
     * @param \WP_Post $post       The post.
     */
    public static function schedule_recount_on_transition( $new_status, $old_status, $post ): void {
        if ( "publish" !== $new_status && "publish" !== $old_status ) {
            return;
        }
        if ( "publish" === $new_status && "publish" === $old_status ) {
            return; // Saved again while live: schedule_recount_on_update() decides.
        }
        if ( ! self::counts_for_rules( $post ) ) {
            return;
        }
        self::schedule_recount();
    }

    /**
     * After a single post is reindexed, a site budget may have room for it. Only
     * worth the full walk when some active rule actually has a budget.
     *
     * No longer hooked to every reindex: with a link map every rule has a budget,
     * so each post save ran the full walk (3 s on a 1,100-post site) and "Remove
     * all" queued hundreds of them. The queued recount gives a new post its share
     * a minute later instead.
     */
    public static function maybe_recompute_for_site_caps(): void {
        foreach ( ( new self() )->get_active_rules() as $rule ) {
            if ( (int) $rule->max_per_site > 0 ) {
                self::recompute_applied_counts();
                return;
            }
        }
    }

    /* -------------------- CRUD -------------------- */

    /**
     * @param bool $recount Recount every rule afterwards. Bulk callers pass false
     *                      and recount once at the end.
     */
    public function create( array $data, bool $recount = true ): int {
        global $wpdb;
        $now    = current_time( "mysql", true );
        $insert = $this->sanitize( $data );
        $insert["created_at"] = $now;
        $insert["updated_at"] = $now;
        $wpdb->insert( self::table(), $insert );
        $id = (int) $wpdb->insert_id;
        /**
         * A rule was created or updated. $data is the raw request body, so an
         * extension can store its own fields for the rule.
         */
        do_action( "jnk_linkweave_rule_saved", $id, $data );
        self::flush_cache();
        if ( $recount ) {
            self::recompute_applied_counts();
        }
        return $id;
    }

    public function update( int $id, array $data, bool $recount = true ): bool {
        global $wpdb;
        $update = $this->sanitize( $data );
        $update["updated_at"] = current_time( "mysql", true );
        $ok = $wpdb->update( self::table(), $update, [ "id" => $id ] );
        do_action( "jnk_linkweave_rule_saved", $id, $data );
        self::flush_cache();
        if ( $recount ) {
            self::recompute_applied_counts();
        }
        return false !== $ok;
    }

    public function delete( int $id ): bool {
        global $wpdb;
        $ok = $wpdb->delete( self::table(), [ "id" => $id ], [ "%d" ] );
        $needs = self::rules_needing_pro();
        if ( isset( $needs[ $id ] ) ) {
            unset( $needs[ $id ] );
            update_option( self::NEEDS_PRO_OPTION, array_keys( $needs ), true );
        }
        do_action( "jnk_linkweave_rule_deleted", $id );
        self::flush_cache();
        self::recompute_applied_counts();
        return false !== $ok;
    }

    public function find( int $id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d", self::table(), $id ) );
        return $row ? self::typed( $row ) : null;
    }

    public function list_rules( int $page = 1, int $per_page = 20 ): array {
        global $wpdb;
        $offset = max( 0, ( $page - 1 ) * $per_page );
        $rows   = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d",
            self::table(),
            $per_page,
            $offset
        ) );
        $total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", self::table() ) );
        return [
            "rules"    => array_map( [ self::class, "typed" ], $rows ?: [] ),
            "total"    => $total,
            "page"     => $page,
            "per_page" => $per_page,
        ];
    }

    /**
     * Give a rule row real types for the REST API. $wpdb returns every column as a
     * string, and "0" is truthy in JavaScript, so the edit form pre-ticked every
     * flag that was off and saving quietly turned them on.
     */
    public static function typed( object $row ): object {
        $out = clone $row;
        foreach ( [ "id", "max_per_post", "max_per_site", "applied_count" ] as $int_key ) {
            if ( isset( $out->$int_key ) ) {
                $out->$int_key = (int) $out->$int_key;
            }
        }
        foreach ( [ "case_sensitive", "open_in_new_tab", "nofollow", "is_active", "is_generated" ] as $bool_key ) {
            if ( isset( $out->$bool_key ) ) {
                $out->$bool_key = (bool) (int) $out->$bool_key;
            }
        }
        $out->paused_needs_pro = isset( $out->id ) && self::is_paused_without_pro( (int) $out->id );
        /** Lets an extension add its own fields to a rule in REST responses. */
        return (object) apply_filters( "jnk_linkweave_rule_row", $out );
    }

    /**
     * A rule's "Links to" must be a web address or a path on this site. Typing a
     * product name there saved an active rule linking to "http://Heritage Seed"
     * on every page (final persona run, 30 Oct 2026).
     */
    public static function is_valid_target( string $url ): bool {
        $url = trim( $url );
        if ( "" === $url || preg_match( "/\\s/u", $url ) ) {
            return false;
        }
        if ( "/" === $url[0] ) {
            return 0 !== strpos( $url, "//" );
        }
        $parts = wp_parse_url( $url );
        return is_array( $parts )
            && in_array( strtolower( (string) ( $parts["scheme"] ?? "" ) ), [ "http", "https" ], true )
            && "" !== (string) ( $parts["host"] ?? "" );
    }

    private function sanitize( array $data ): array {
        $out = [];
        if ( isset( $data["keyword"] ) ) {
            $out["keyword"] = sanitize_text_field( (string) $data["keyword"] );
        }
        if ( isset( $data["target_url"] ) ) {
            $out["target_url"] = esc_url_raw( (string) $data["target_url"] );
        }
        foreach ( [ "case_sensitive", "open_in_new_tab", "nofollow", "is_active" ] as $bool_key ) {
            if ( array_key_exists( $bool_key, $data ) ) {
                // "0" and "false" arrive from forms and older clients; both mean off.
                $v = $data[ $bool_key ];
                $out[ $bool_key ] = ( empty( $v ) || "false" === $v ) ? 0 : 1;
            }
        }
        if ( isset( $data["max_per_post"] ) ) {
            $out["max_per_post"] = max( 1, (int) $data["max_per_post"] );
        }
        if ( isset( $data["max_per_site"] ) ) {
            $out["max_per_site"] = max( 0, (int) $data["max_per_site"] );
        }
        return $out;
    }

    public static function is_pro(): bool {
        $license = get_option( "jnk_linkweave_license", [] );
        $is_pro  = ! empty( $license["is_pro"] );
        return (bool) apply_filters( "jnk_linkweave_is_pro", $is_pro );
    }

    /**
     * The post id named by a same-site shortlink target ("?p=123" or
     * "?page_id=123"), or 0. Cheap string parsing, no database lookup.
     */
    public static function shortlink_post_id( string $url ): int {
        $query = (string) wp_parse_url( $url, PHP_URL_QUERY );
        if ( "" === $query ) {
            return 0;
        }
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        if ( "" !== $host && strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
            return 0;
        }
        parse_str( $query, $args );
        foreach ( [ "p", "page_id" ] as $key ) {
            if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) && ctype_digit( (string) $args[ $key ] ) ) {
                return (int) $args[ $key ];
            }
        }
        return 0;
    }
}
