<?php

declare(strict_types=1);

namespace App\RichText\Html;

use Dom\CharacterData;
use Dom\Comment;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * The Nokogiri HTML5 (Gumbo) trees Action Text, Loofah and rails-html-sanitizer work on, over
 * PHP's Dom\HTMLDocument (Lexbor, the same WHATWG tree construction algorithm as Gumbo).
 *
 * Parsing always goes through the fragment parsing algorithm with a context element, as Nokogiri
 * does: `Document#fragment` parses in a `body` context and `Node#fragment` (behind `inner_html=`
 * and `replace(html)`) in the node's own context. Serialization is our own and reproduces
 * Nokogiri's `html_standard_serialize` (`Nokogiri::HTML5::Node#write_to`), which every
 * `to_html` in the pipeline produces: `&`, NBSP and `"` escaped in attribute values (but not `<`
 * and `>`), `&`, NBSP, `<` and `>` in text, raw text elements unescaped, void elements without
 * an end tag.
 *
 * A fragment is a detached `<body>` element owned by its own document: its children are the
 * fragment's nodes, and markup parsed for it gets the `body` context Nokogiri gives a fragment.
 */
final class Html
{
    public const string HTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';
    public const string SVG_NAMESPACE = 'http://www.w3.org/2000/svg';
    public const string MATHML_NAMESPACE = 'http://www.w3.org/1998/Math/MathML';

    /** Nokogiri::Gumbo::DEFAULT_MAX_TREE_DEPTH */
    public const int MAX_TREE_DEPTH = 400;
    /** Nokogiri::Gumbo::DEFAULT_MAX_ATTRIBUTES */
    public const int MAX_ATTRIBUTES = 400;

    private const array VOID_ELEMENTS = [
        'area' => true, 'base' => true, 'basefont' => true, 'bgsound' => true, 'br' => true, 'col' => true, 'embed' => true,
        'frame' => true, 'hr' => true, 'img' => true, 'input' => true, 'keygen' => true, 'link' => true, 'meta' => true,
        'param' => true, 'source' => true, 'track' => true, 'wbr' => true,
    ];

    /** Start tags that close an open element of their own kind (or a sibling's), never nesting deep. */
    private const array SELF_CLOSING_KINDS = [
        'p' => true, 'li' => true, 'dd' => true, 'dt' => true, 'option' => true, 'optgroup' => true, 'tr' => true, 'td' => true,
        'th' => true, 'tbody' => true, 'thead' => true, 'tfoot' => true, 'caption' => true, 'colgroup' => true, 'rb' => true,
        'rt' => true, 'rp' => true, 'rtc' => true, 'a' => true, 'nobr' => true, 'button' => true, 'select' => true, 'form' => true,
        'h1' => true, 'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true, 'html' => true, 'head' => true,
        'body' => true, 'frameset' => true, 'table' => true,
    ];

    private const array FORMATTING_ELEMENTS = ['a', 'b', 'big', 'code', 'em', 'font', 'i', 'nobr', 's', 'small', 'strike', 'strong', 'tt', 'u'];

    private const array RAW_TEXT_ELEMENTS = [
        'style' => true, 'script' => true, 'xmp' => true, 'iframe' => true, 'noembed' => true, 'noframes' => true,
        'plaintext' => true, 'noscript' => true,
    ];

    /**
     * `Nokogiri::HTML5::Document#fragment(html)` (ActionText::HtmlConversion.fragment_for_html,
     * Loofah.html5_fragment): a new fragment, parsed in a `body` context.
     *
     * @throws ParseError past Gumbo's limits
     */
    public static function fragment(string $html): Element
    {
        $document = HTMLDocument::createEmpty();
        $root = $document->createElement('body');
        foreach (self::parseNodes($root, $html) as $node) {
            $root->appendChild($node);
        }

        return $root;
    }

    /** A new, empty fragment in the document of `$node`. */
    public static function emptyFragment(Node $node): Element
    {
        return self::document($node)->createElement('body');
    }

    /**
     * Parses `$html` in the context of `$context` (Nokogiri's `Node#fragment`) and returns the
     * resulting top-level nodes, detached, owned by the context's document.
     *
     * @return list<Node>
     *
     * @throws ParseError
     */
    public static function parseNodes(Element $context, string $html): array
    {
        // Gumbo drops a byte order mark at the start of the input only
        if (str_starts_with($html, "\u{FEFF}")) {
            $html = substr($html, 3);
        }
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_scrub($html, 'UTF-8');
        }
        // A shallow copy of the context element: the parser's names needn't be valid for
        // createElement. Lexbor parses a template's markup into its (unreachable) template
        // contents, so a template's markup is parsed as a body's.
        if (self::HTML_NAMESPACE === $context->namespaceURI && 'template' === $context->localName) {
            $holder = self::document($context)->createElement('body');
        } else {
            $holder = $context->cloneNode(false);
            \assert($holder instanceof Element);
        }
        self::checkAttributeTokens($html);
        self::parseInto($holder, $html);
        self::checkLimits($holder);

        $nodes = [];
        foreach (iterator_to_array($holder->childNodes) as $node) {
            $holder->removeChild($node);
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * Nokogiri's `node.inner_html = html` (via `children=`), parsed in the node's own context.
     *
     * @throws ParseError
     */
    public static function setInnerHtml(Element $element, string $html): void
    {
        $nodes = self::parseNodes($element, $html);
        while (null !== $element->firstChild) {
            $element->removeChild($element->firstChild);
        }
        foreach ($nodes as $node) {
            $element->appendChild($node);
        }
    }

    /**
     * Nokogiri's `node.replace(html)`: the markup is parsed in the context of the node's parent.
     *
     * @throws ParseError
     */
    public static function replaceWithHtml(Node $node, string $html): void
    {
        $parent = $node->parentNode;
        if (!$parent instanceof Element) {
            return;
        }
        self::replaceWithNodes($node, self::parseNodes($parent, $html));
    }

    /** @param list<Node> $replacements detached nodes */
    public static function replaceWithNodes(Node $node, array $replacements): void
    {
        $parent = $node->parentNode;
        if (null === $parent) {
            return;
        }
        foreach ($replacements as $replacement) {
            $parent->insertBefore($replacement, $node);
        }
        $parent->removeChild($node);
    }

    /** `node.to_html` (`fragment.to_html` serializes its children). */
    public static function toHtml(Node $node, bool $escapeAttributeBrackets = false): string
    {
        $out = '';
        if (self::isFragment($node)) {
            self::serializeChildren($node, $escapeAttributeBrackets, $out);
        } else {
            self::serializeNode($node, $escapeAttributeBrackets, $out);
        }

        return $out;
    }

    /** `node.inner_html` */
    public static function innerHtml(Node $node): string
    {
        $out = '';
        self::serializeChildren($node, false, $out);

        return $out;
    }

    /** Whether `$node` is the root of a fragment made by fragment() or emptyFragment(). */
    public static function isFragment(Node $node): bool
    {
        return $node instanceof Element && null === $node->parentNode && 'body' === $node->localName && self::HTML_NAMESPACE === $node->namespaceURI;
    }

    /**
     * Every descendant of `$node` in document order, `$node` excluded.
     *
     * @return list<Node>
     */
    public static function descendants(Node $node): array
    {
        $out = [];
        $stack = [];
        for ($child = $node->lastChild; null !== $child; $child = $child->previousSibling) {
            $stack[] = $child;
        }
        while ([] !== $stack) {
            $current = array_pop($stack);
            $out[] = $current;
            for ($child = $current->lastChild; null !== $child; $child = $child->previousSibling) {
                $stack[] = $child;
            }
        }

        return $out;
    }

    /**
     * The descendant elements named `$localName`, in document order (Nokogiri's `css(name)`).
     *
     * @return list<Element>
     */
    public static function elementsNamed(Node $node, string $localName): array
    {
        $out = [];
        foreach (self::descendants($node) as $descendant) {
            if ($descendant instanceof Element && $descendant->localName === $localName) {
                $out[] = $descendant;
            }
        }

        return $out;
    }

    /** @return list<Element> Nokogiri's `Node#elements` */
    public static function elementChildren(Node $node): array
    {
        $out = [];
        for ($child = $node->firstChild; null !== $child; $child = $child->nextSibling) {
            if ($child instanceof Element) {
                $out[] = $child;
            }
        }

        return $out;
    }

    /** @return list<Node> */
    public static function children(Node $node): array
    {
        $out = [];
        for ($child = $node->firstChild; null !== $child; $child = $child->nextSibling) {
            $out[] = $child;
        }

        return $out;
    }

    /** Nokogiri's `Node#name`: the element's local name, "text", "comment" and so on otherwise. */
    public static function name(Node $node): string
    {
        return match (true) {
            $node instanceof Element => self::isFragment($node) ? '#document-fragment' : $node->localName,
            $node instanceof Text => 'text',
            $node instanceof Comment => 'comment',
            default => strtolower($node->nodeName),
        };
    }

    public static function isHtmlElement(Node $node): bool
    {
        return $node instanceof Element && self::HTML_NAMESPACE === $node->namespaceURI;
    }

    /** The text content of a node, as libxml2's `xmlNodeGetContent` computes it (`node.text`). */
    public static function textContent(Node $node): string
    {
        if ($node instanceof CharacterData) {
            return $node->data;
        }
        $out = '';
        foreach (self::descendants($node) as $descendant) {
            if ($descendant instanceof Text) {
                $out .= $descendant->data;
            }
        }

        return $out;
    }

    /**
     * `document.create_element(name, attributes)`.
     *
     * @param array<string, string> $attributes
     */
    public static function createElement(Node $owner, string $name, array $attributes = []): Element
    {
        $element = self::document($owner)->createElement($name);
        foreach ($attributes as $attribute => $value) {
            $element->setAttribute($attribute, $value);
        }

        return $element;
    }

    /**
     * The element's attributes in order, by qualified name.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function attributes(Element $element): array
    {
        $out = [];
        foreach ($element->attributes as $attribute) {
            $out[] = [$attribute->name, $attribute->value];
        }

        return $out;
    }

    public static function document(Node $node): HTMLDocument
    {
        $document = $node instanceof HTMLDocument ? $node : $node->ownerDocument;
        \assert($document instanceof HTMLDocument);

        return $document;
    }

    private static function serializeChildren(Node $node, bool $escapeAttributeBrackets, string &$out): void
    {
        for ($child = $node->firstChild; null !== $child; $child = $child->nextSibling) {
            self::serializeNode($child, $escapeAttributeBrackets, $out);
        }
    }

    private static function serializeNode(Node $node, bool $escapeAttributeBrackets, string &$out): void
    {
        if ($node instanceof Element) {
            $tag = self::tagName($node);
            $out .= '<'.$tag;
            foreach ($node->attributes as $attribute) {
                $out .= ' '.$attribute->name.'="'.self::escapeAttribute($attribute->value, $escapeAttributeBrackets).'"';
            }
            $out .= '>';
            if (self::HTML_NAMESPACE === $node->namespaceURI && isset(self::VOID_ELEMENTS[$node->localName])) {
                return;
            }
            self::serializeChildren($node, $escapeAttributeBrackets, $out);
            $out .= '</'.$tag.'>';
        } elseif ($node instanceof Text) {
            $parent = $node->parentNode;
            if ($parent instanceof Element && self::HTML_NAMESPACE === $parent->namespaceURI && isset(self::RAW_TEXT_ELEMENTS[$parent->localName]) && !self::isFragment($parent)) {
                $out .= $node->data;
            } else {
                $out .= strtr($node->data, ['&' => '&amp;', "\u{A0}" => '&nbsp;', '<' => '&lt;', '>' => '&gt;']);
            }
        } elseif ($node instanceof Comment) {
            $out .= '<!--'.$node->data.'-->';
        } elseif ($node instanceof CharacterData) {
            $out .= $node->data;
        }
    }

    private static function tagName(Element $element): string
    {
        $namespace = $element->namespaceURI;
        if (self::HTML_NAMESPACE === $namespace || self::SVG_NAMESPACE === $namespace || self::MATHML_NAMESPACE === $namespace || null === $element->prefix) {
            return $element->localName;
        }

        return $element->prefix.':'.$element->localName;
    }

    private static function escapeAttribute(string $value, bool $escapeBrackets): string
    {
        $map = ['&' => '&amp;', "\u{A0}" => '&nbsp;', '"' => '&quot;'];
        if ($escapeBrackets) {
            $map += ['<' => '&lt;', '>' => '&gt;'];
        }

        return strtr($value, $map);
    }

    /**
     * Gumbo puts a template's contents among its children, and Nokogiri works on them there.
     * Lexbor keeps them in template contents the DOM API can't reach, so they are moved in.
     */
    private static function exposeTemplateContents(Element $holder): void
    {
        foreach (self::elementsNamed($holder, 'template') as $template) {
            if (self::HTML_NAMESPACE !== $template->namespaceURI || null !== $template->firstChild) {
                continue;
            }
            $markup = $template->innerHTML;
            if ('' === $markup) {
                continue;
            }
            $template->innerHTML = '';
            // The "in template" insertion mode switches on the first start tag; the contents parse
            // back the same in the context that mode stands for
            $first = preg_match('~<([a-zA-Z][^\s/>]*)~', $markup, $m) ? strtolower($m[1]) : '';
            $context = match ($first) {
                'caption', 'colgroup', 'tbody', 'tfoot', 'thead' => 'table',
                'col' => 'colgroup',
                'tr' => 'tbody',
                'td', 'th' => 'tr',
                default => 'body',
            };
            $body = self::document($template)->createElement($context);
            self::parseInto($body, $markup);
            while (null !== $body->firstChild) {
                $template->appendChild($body->firstChild);
            }
        }
    }

    /** Lexbor's fragment parsing into `$holder`, which is the context, then Gumbo's tree. */
    private static function parseInto(Element $holder, string $html): void
    {
        $holder->innerHTML = $html;
        self::exposeTemplateContents($holder);
        self::fixLexborQuirks($holder);
    }

    /** Where PHP 8.4's Lexbor builds a different tree than Gumbo (Nokogiri 1.19), the tree Gumbo builds. */
    private static function fixLexborQuirks(Element $holder): void
    {
        self::breakOutOfForeignContent($holder);
        self::keepTextOnlyElementsText($holder);
        self::parseSelectsAsGumbo($holder);
    }

    /**
     * Lexbor leaves `sup` out of the start tags that break out of foreign content: Gumbo closes
     * the open SVG or MathML elements and inserts an HTML `<sup>` after them, along with what
     * follows it.
     */
    private static function breakOutOfForeignContent(Element $holder): void
    {
        foreach (self::elementsNamed($holder, 'sup') as $sup) {
            if (self::HTML_NAMESPACE === $sup->namespaceURI || null === $sup->parentNode) {
                continue;
            }
            $top = null;
            for ($ancestor = $sup->parentElement; null !== $ancestor && $ancestor !== $holder; $ancestor = $ancestor->parentElement) {
                if (self::HTML_NAMESPACE === $ancestor->namespaceURI || self::isIntegrationPoint($ancestor)) {
                    break;
                }
                $top = $ancestor;
            }
            if (null === $top || null === $top->parentNode) {
                continue;
            }
            $html = self::document($sup)->createElement('sup');
            foreach (iterator_to_array($sup->attributes) as $attribute) {
                $html->setAttribute($attribute->name, $attribute->value);
            }
            while (null !== $sup->firstChild) {
                $html->appendChild($sup->firstChild);
            }
            $moved = [$html];
            for ($sibling = $sup->nextSibling; null !== $sibling; $sibling = $sibling->nextSibling) {
                $moved[] = $sibling;
            }
            for ($at = $sup->parentNode; $at !== $top && null !== $at; $at = $at->parentNode) {
                for ($sibling = $at->nextSibling; null !== $sibling; $sibling = $sibling->nextSibling) {
                    $moved[] = $sibling;
                }
            }
            $sup->parentNode->removeChild($sup);
            $after = $top->nextSibling;
            foreach ($moved as $node) {
                $top->parentNode->insertBefore($node, $after);
            }
        }
    }

    private static function isIntegrationPoint(Element $element): bool
    {
        return match ($element->namespaceURI) {
            self::SVG_NAMESPACE => \in_array($element->localName, ['foreignObject', 'desc', 'title'], true),
            self::MATHML_NAMESPACE => \in_array($element->localName, ['mi', 'mo', 'mn', 'ms', 'mtext', 'annotation-xml'], true),
            default => false,
        };
    }

    /**
     * RCDATA and RAWTEXT elements hold text only, but Lexbor reconstructs active formatting
     * elements inside a textarea: their text is all Gumbo keeps.
     */
    private static function keepTextOnlyElementsText(Element $holder): void
    {
        foreach (self::descendants($holder) as $node) {
            if (!$node instanceof Element || self::HTML_NAMESPACE !== $node->namespaceURI || !\in_array($node->localName, ['textarea', 'title', 'style', 'script', 'xmp', 'iframe', 'noembed', 'noframes', 'plaintext'], true)) {
                continue;
            }
            if ([] === self::elementChildren($node)) {
                continue;
            }
            $text = self::textContent($node);
            while (null !== $node->firstChild) {
                $node->removeChild($node->firstChild);
            }
            $node->appendChild(self::document($node)->createTextNode($text));
        }
    }

    /**
     * Lexbor implements the current "customizable select" parsing, where a `<select>` keeps the
     * elements inside it. Gumbo implements the earlier rules, where the "in select" insertion
     * mode ignores every start and end tag but option, optgroup, hr, script and template (their
     * text stays), and an input, keygen or textarea closes the select. The tree is rewritten to
     * what those rules build: what follows a closing tag moves out after the select, and the
     * other elements inside are unwrapped.
     */
    private static function parseSelectsAsGumbo(Element $holder): void
    {
        foreach (self::elementsNamed($holder, 'select') as $select) {
            if (self::HTML_NAMESPACE !== $select->namespaceURI || null === $select->parentNode) {
                continue;
            }
            // A raw text element's start tag is ignored too, and what it held parsed as markup
            foreach (self::descendantsOutsideTemplates($select) as $node) {
                if ($node instanceof Element && self::HTML_NAMESPACE === $node->namespaceURI && \in_array($node->localName, ['style', 'xmp', 'iframe', 'noembed', 'noframes', 'plaintext'], true) && null !== $node->parentNode) {
                    $markup = self::document($node)->createElement('body');
                    self::parseInto($markup, self::textContent($node));
                    self::replaceWithNodes($node, self::children($markup));
                }
            }
            $descendants = self::descendantsOutsideTemplates($select);
            foreach ($descendants as $node) {
                if ($node instanceof Element && self::HTML_NAMESPACE === $node->namespaceURI && \in_array($node->localName, ['input', 'keygen', 'textarea'], true)) {
                    $moved = [];
                    for ($at = $node; $at !== $select && null !== $at; $at = $at->parentNode) {
                        for ($sibling = $at === $node ? $at : $at->nextSibling; null !== $sibling; $sibling = $sibling->nextSibling) {
                            $moved[] = $sibling;
                        }
                    }
                    $after = $select->nextSibling;
                    foreach ($moved as $movedNode) {
                        $select->parentNode->insertBefore($movedNode, $after);
                    }
                    $descendants = self::descendantsOutsideTemplates($select);
                    break;
                }
            }
            $formatting = [];
            foreach ($descendants as $node) {
                if (!$node instanceof Element || (self::HTML_NAMESPACE === $node->namespaceURI && \in_array($node->localName, ['option', 'optgroup', 'hr', 'script', 'template'], true))) {
                    continue;
                }
                if (self::HTML_NAMESPACE === $node->namespaceURI && \in_array($node->localName, self::FORMATTING_ELEMENTS, true)) {
                    $formatting[] = self::signature($node);
                }
                $parent = $node->parentNode;
                if (null === $parent) {
                    continue;
                }
                while (null !== $node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
            }
            // Gumbo appends the text around an ignored tag to the same text node
            $select->normalize();
            // Lexbor reconstructs the formatting elements it opened inside the select right after
            // it, where Gumbo never opened them
            while (($next = $select->nextSibling) instanceof Element && \in_array(self::signature($next), $formatting, true)) {
                while (null !== $next->firstChild) {
                    $select->parentNode?->insertBefore($next->firstChild, $next);
                }
                $next->parentNode?->removeChild($next);
            }
        }
    }

    /**
     * A template's contents parse in their own insertion mode, wherever the template is.
     *
     * @return list<Node>
     */
    private static function descendantsOutsideTemplates(Element $root): array
    {
        $out = [];
        $stack = array_reverse(self::children($root));
        while ([] !== $stack) {
            $node = array_pop($stack);
            $out[] = $node;
            if ($node instanceof Element && 'template' === $node->localName && self::HTML_NAMESPACE === $node->namespaceURI) {
                continue;
            }
            foreach (array_reverse(self::children($node)) as $child) {
                $stack[] = $child;
            }
        }

        return $out;
    }

    private static function signature(Element $element): string
    {
        return $element->localName.json_encode(self::attributes($element));
    }

    /**
     * Gumbo enforces its limits as its tokenizer and tree builder go: attributes are counted
     * before duplicates are dropped, on end tags and on a tag the input ends inside, and the
     * depth is that of the stack of open elements at any point, which the adoption agency can
     * reduce again before the tree is finished. The markup is scanned for both; for depth, tags
     * that close an open element of their own kind (p, li, a, headings, …) are not counted.
     *
     * @throws ParseError
     */
    private static function checkAttributeTokens(string $html): void
    {
        $manyTags = substr_count($html, '<') > self::MAX_TREE_DEPTH;
        // A tag with more than 400 attributes has at least 400 separators
        $manySeparators = preg_match_all('~[\s/]~', $html) >= self::MAX_ATTRIBUTES;
        if (!$manyTags && !$manySeparators) {
            return;
        }
        $stack = [];
        $offset = 0;
        $length = \strlen($html);
        while ($offset < $length && preg_match('~<(?:!--.*?(?:-->|\z)|[!?][^>]*(?:>|\z)|(/?)([a-zA-Z][^\s/>]*)((?:[^>"\']|"[^"]*(?:"|\z)|\'[^\']*(?:\'|\z))*)(?:>|\z))~s', $html, $m, \PREG_OFFSET_CAPTURE, $offset)) {
            $offset = $m[0][1] + max(1, \strlen($m[0][0]));
            if (!isset($m[2]) || -1 === $m[2][1]) {
                continue;
            }
            if ($manySeparators && preg_match_all('~(?:^|[\s/])+(?:[^\s/>=]|=)[^\s/>=]*(?:\s*=\s*(?:"[^"]*"?|\'[^\']*\'?|[^\s>]*))?~', $m[3][0]) > self::MAX_ATTRIBUTES) {
                throw ParseError::tooManyAttributes();
            }
            $name = strtolower($m[2][0]);
            if ('/' === $m[1][0]) {
                $positions = array_keys($stack, $name, true);
                if ([] !== $positions) {
                    $stack = \array_slice($stack, 0, (int) end($positions));
                }
                continue;
            }
            // Raw text and RCDATA elements hold no tags
            if (\in_array($name, ['textarea', 'title', 'style', 'xmp', 'iframe', 'noembed', 'noframes', 'script', 'plaintext'], true)) {
                if ('plaintext' === $name) {
                    return;
                }
                $end = stripos($html, '</'.$name, $offset);
                $offset = false === $end ? $length : $end;
                continue;
            }
            if ($manyTags && !isset(self::VOID_ELEMENTS[$name]) && !isset(self::SELF_CLOSING_KINDS[$name])) {
                $stack[] = $name;
                // The fragment's html element is on the stack too
                if (\count($stack) + 1 > self::MAX_TREE_DEPTH + 1) {
                    throw ParseError::treeDepthExceeded();
                }
            }
        }
    }

    /**
     * Gumbo's limits: more than 400 open elements, or more than 400 attributes on a tag, raise.
     * Checked on the finished tree, which Gumbo does as it parses.
     *
     * @throws ParseError
     */
    private static function checkLimits(Element $holder): void
    {
        $stack = [];
        for ($child = $holder->lastChild; null !== $child; $child = $child->previousSibling) {
            if ($child instanceof Element) {
                $stack[] = [$child, 1];
            }
        }
        while ([] !== $stack) {
            [$element, $depth] = array_pop($stack);
            if ($element->attributes->length > self::MAX_ATTRIBUTES) {
                throw ParseError::tooManyAttributes();
            }
            $void = self::HTML_NAMESPACE === $element->namespaceURI && isset(self::VOID_ELEMENTS[$element->localName]);
            if ($depth > self::MAX_TREE_DEPTH && !$void) {
                throw ParseError::treeDepthExceeded();
            }
            for ($child = $element->lastChild; null !== $child; $child = $child->previousSibling) {
                if ($child instanceof Element) {
                    $stack[] = [$child, $depth + 1];
                }
            }
        }
    }
}
